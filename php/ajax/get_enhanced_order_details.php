<?php
session_start();
require_once '../DB/conn.php';
require_once 'OrderStatusManager.php';

header('Content-Type: application/json');

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit();
}

// Initialize Order Status Manager
$orderManager = new OrderStatusManager($conn, $admin_id);

try {
    // Get order details
    $stmt = $conn->prepare("
        SELECT o.*, 
               i.email as customer_email,
               COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as customer_name
        FROM orders o
        LEFT JOIN infos i ON o.user_id = i.id
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    // Get order items with book details
    $stmt = $conn->prepare("
        SELECT 
            oi.*,
            b.title as book_title,
            b.author as book_author,
            b.isbn as book_isbn,
            b.cover_image
        FROM order_items oi
        LEFT JOIN books b ON oi.book_id = b.id
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get customer details
    $customer = [
        'name' => $order['customer_name'],
        'email' => $order['customer_email']
    ];
    
    // Get status history
    $history = $orderManager->getStatusHistory($order_id);
    
    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items,
        'customer' => $customer,
        'history' => $history
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching order details: ' . $e->getMessage()
    ]);
}
