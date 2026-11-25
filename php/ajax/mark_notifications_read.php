<?php
/**
 * Mark All Notifications as Read
 */

session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'updated' => $stmt->affected_rows
    ]);

} catch (Exception $e) {
    error_log("Mark notifications read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to update notifications']);
}
?>
