<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../DB/conn.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

try {
    // Get order details
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }

    // Get customer details
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(full_name, CONCAT(firstname, ' ', COALESCE(middlename, ''), ' ', lastname)) as name,
            email,
            username
        FROM infos 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $order['user_id']);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();

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

    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items,
        'customer' => $customer
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
