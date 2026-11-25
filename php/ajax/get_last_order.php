<?php
/**
 * Get Last Order for Reorder Functionality
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
    $stmt = $conn->prepare("
        SELECT id, order_number, total_amount, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if ($order) {
        echo json_encode([
            'success' => true,
            'order_id' => $order['id'],
            'order_number' => $order['order_number'],
            'total_amount' => $order['total_amount']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No previous orders found'
        ]);
    }

} catch (Exception $e) {
    error_log("Get last order error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to retrieve order']);
}
?>
