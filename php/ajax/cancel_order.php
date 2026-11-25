<?php
require_once __DIR__ . '/../includes/account_input_validation.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once __DIR__ . '/../../DB/conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST to cancel the order.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$order_id = account_positive_integer($_POST['order_id'] ?? null);
$reason = isset($_POST['reason']) ? account_input('reason') : 'Customer requested cancellation';
if ($order_id === null || $reason === '' || !account_text_is_valid($reason, 255)) {
    echo json_encode(['success' => false, 'message' => 'Choose a valid order and enter a short reason.']);
    exit;
}

$conn->begin_transaction();
try {
    $orderStmt = $conn->prepare("SELECT id, order_number FROM orders WHERE id = ? AND user_id = ? AND status IN ('pending', 'processing') FOR UPDATE");
    $orderStmt->bind_param('ii', $order_id, $user_id);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    if (!$order) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Order not found or cannot be cancelled.']);
        exit;
    }

    // Get order items to restore stock
    $stmt = $conn->prepare("SELECT book_id, quantity FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Restore stock for each item
    foreach ($items as $item) {
        $stmt = $conn->prepare("UPDATE books SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $stmt->bind_param("ii", $item['quantity'], $item['book_id']);
        $stmt->execute();
    }
    
    // Update order status
    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $reason, $order_id, $user_id);
    $stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order cancelled successfully. Stock has been restored.',
        'order_number' => $order['order_number']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log('Order cancellation failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to cancel the order.']);
}
?>
