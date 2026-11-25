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

if ($cart_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid cart ID']);
    exit();
}

// Delete cart item
$stmt = $conn->prepare("DELETE FROM shopping_cart WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $cart_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to remove item']);
}
?>
