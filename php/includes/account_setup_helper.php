<?php

function account_setup_id_from_session(): ?int
{
    if (isset($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    if (isset($_SESSION['super_admin_id'])) {
        return (int) $_SESSION['super_admin_id'];
    }

    return null;
}

function require_account_setup_complete(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare('SELECT must_complete_setup FROM infos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($account && (int) $account['must_complete_setup'] === 1) {
        header('Location: account_setup.php');
        exit();
    }
}

function generate_registration_id(mysqli $conn, bool $employee = false): string
{
    $year = date('Y');
    $prefix = $employee ? 'EMP-' : $year . '-';
    for ($number = 1; $number <= 9999; $number++) {
        $candidate = $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $check = $conn->prepare('SELECT 1 FROM infos WHERE idnumber = ? LIMIT 1');
        $check->bind_param('s', $candidate);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();

        if (!$exists) {
            return $candidate;
        }
    }

    throw new RuntimeException('No registration ID numbers are available for ' . $prefix . '.');
}
