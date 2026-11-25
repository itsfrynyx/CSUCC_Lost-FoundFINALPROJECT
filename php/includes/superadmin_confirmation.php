<?php

/**
 * Verifies the currently signed-in super administrator before a sensitive
 * account update. The password is never stored in the session or logged.
 */
function confirm_current_superadmin_password(mysqli $conn, string $password): bool
{
    $superadminId = $_SESSION['super_admin_id'] ?? 0;
    if ($superadminId <= 0 || $password === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT passw FROM infos WHERE id = ? AND role = 'super_admin' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the Super Administrator password.');
    }

    $stmt->bind_param('i', $superadminId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $account && password_verify($password, $account['passw']);
}
