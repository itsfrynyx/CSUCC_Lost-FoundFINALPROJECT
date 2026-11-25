<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
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

require_once __DIR__ . '/../DB/conn.php';

$user_id = $_SESSION['user_id'];
$cart_id = account_positive_integer($_POST['cart_id'] ?? null);
$quantity = account_positive_integer($_POST['quantity'] ?? null);

if ($cart_id <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

// Get cart item and check stock
$stmt = $conn->prepare("SELECT sc.*, b.stock_quantity FROM shopping_cart sc JOIN books b ON sc.book_id = b.id WHERE sc.id = ? AND sc.user_id = ?");
$stmt->bind_param("ii", $cart_id, $user_id);
$stmt->execute();
$cart_item = $stmt->get_result()->fetch_assoc();

if (!$cart_item) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit();
}

if ($quantity > $cart_item['stock_quantity']) {
    echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
    exit();
}

// Update quantity
$stmt = $conn->prepare("UPDATE shopping_cart SET quantity = ? WHERE id = ? AND user_id = ?");
$stmt->bind_param("iii", $quantity, $cart_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Cart updated']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update cart']);
}
?>
