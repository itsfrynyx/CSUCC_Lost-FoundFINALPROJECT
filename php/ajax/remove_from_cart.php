<?php
require_once __DIR__ . '/../includes/account_input_validation.php';
session_start();
require_once __DIR__ . '/../../DB/conn.php';
require_once '../includes/ActivityLogger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST for this action.']);
    exit;
}


if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$cart_id = account_positive_integer($data['cart_id'] ?? null);

if ($cart_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize activity logger
$logger = new ActivityLogger($conn, $user_id, 'customer');

// Get cart item details including book title for logging
$stmt = $conn->prepare("
    SELECT sc.id, sc.quantity, b.title 
    FROM shopping_cart sc 
    JOIN books b ON sc.book_id = b.id 
    WHERE sc.id = ? AND sc.user_id = ?
");
$stmt->bind_param("ii", $cart_id, $user_id);
$stmt->execute();
$cart_item = $stmt->get_result()->fetch_assoc();

if (!$cart_item) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit();
}

$book_title = $cart_item['title'];
$quantity = $cart_item['quantity'];

// Delete cart item
$stmt = $conn->prepare("DELETE FROM shopping_cart WHERE id = ?");
$stmt->bind_param("i", $cart_id);

if ($stmt->execute()) {
    // Log the activity
    $logger->logCartAction('remove', "$book_title (Quantity: $quantity)");
    
    echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to remove item']);
}
?>
