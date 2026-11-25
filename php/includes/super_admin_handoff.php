<?php

require_once __DIR__ . '/account_creation_mail.php';
require_once __DIR__ . '/account_input_validation.php';

function create_managed_account(
    mysqli $conn,
    mysqli_stmt $insert,
    string $role,
    int $creator,
    string $email,
    string $username,
    string $temporaryPassword
): int
{
    if (!account_text_is_valid($email, 250) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address for the new account.');
    }

    if ($temporaryPassword === '') {
        throw new RuntimeException('Enter a temporary password for the new account.');
    }

    if (!account_password_is_valid($temporaryPassword)) {
        throw new RuntimeException('Use 8?15 characters with uppercase, lowercase, and a number for the temporary password.');
    }

    if ($role === 'super_admin') {
        $conn->query("CREATE TABLE IF NOT EXISTS super_admin_handoffs (
        successor_id INT NOT NULL PRIMARY KEY,
        creator_id INT NOT NULL,
        INDEX (creator_id)
        ) ENGINE=InnoDB");
    }

    $conn->begin_transaction();
    try {
        if ($role === 'super_admin') {
            $lock = $conn->prepare("SELECT id FROM infos WHERE id = ? AND role = 'super_admin' AND COALESCE(is_blocked, 0) = 0 FOR UPDATE");
            $lock->bind_param('i', $creator);
            $lock->execute();
            if (!$lock->get_result()->fetch_assoc()) {
                throw new RuntimeException('Only an active Super Administrator can create a successor.');
            }

            $pending = $conn->prepare("SELECT h.successor_id FROM super_admin_handoffs h JOIN infos i ON i.id = h.successor_id WHERE h.creator_id = ? AND i.role = 'super_admin' LIMIT 1");
            $pending->bind_param('i', $creator);
            $pending->execute();
            if ($pending->get_result()->fetch_assoc()) {
                throw new RuntimeException('A successor account is already waiting for its first sign-in.');
            }
        }

        $insert->execute();
        $accountId = $conn->insert_id;
        if ($role === 'super_admin') {
            $handoff = $conn->prepare('INSERT INTO super_admin_handoffs (successor_id, creator_id) VALUES (?, ?)');
            $handoff->bind_param('ii', $accountId, $creator);
            $handoff->execute();
        }

        send_temporary_account_password($email, $username, $temporaryPassword);
        $conn->commit();
        return $accountId;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function complete_super_admin_handoff(mysqli $conn, int $successor): void
{
    if ($conn->query("SHOW TABLES LIKE 'super_admin_handoffs'")->num_rows === 0) {
        return;
    }

    $conn->begin_transaction();
    try {
        $handoff = $conn->prepare('SELECT creator_id FROM super_admin_handoffs WHERE successor_id = ? FOR UPDATE');
        $handoff->bind_param('i', $successor);
        $handoff->execute();
        $pending = $handoff->get_result()->fetch_assoc();
        if ($pending) {
            $creator = (int) $pending['creator_id'];
            $retire = $conn->prepare("UPDATE infos SET is_blocked = 1, status = 'inactive', logout_time = NOW() WHERE id = ? AND role = 'super_admin'");
            $retire->bind_param('i', $creator);
            $retire->execute();

            if ($retire->affected_rows > 0 && $conn->query("SHOW TABLES LIKE 'user_session_activity'")->num_rows > 0) {
                $close = $conn->prepare("UPDATE user_session_activity SET status = 'inactive', logout_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, login_time, NOW()) WHERE user_id = ? AND status = 'active'");
                $close->bind_param('i', $creator);
                $close->execute();
            }

            $finish = $conn->prepare('DELETE FROM super_admin_handoffs WHERE successor_id = ?');
            $finish->bind_param('i', $successor);
            $finish->execute();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
