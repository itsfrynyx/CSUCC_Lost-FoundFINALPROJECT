<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/ActivityLogger.php';

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

if (!isset($_POST['book_id']) || empty($_POST['book_id'])) {
    echo json_encode(['success' => false, 'message' => 'Book ID is required']);
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = account_positive_integer($_POST['book_id'] ?? null);
$quantity = isset($_POST['quantity']) ? account_positive_integer($_POST['quantity'] ?? null) : 1;

if ($book_id === null || $quantity === null) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid book ID and quantity.']);
    exit;
}

// Initialize activity logger
$logger = new ActivityLogger($conn, $user_id, 'customer');

// Check if book exists and is active
$stmt = $conn->prepare("SELECT id, title, stock_quantity FROM books WHERE id = ? AND is_active = 1");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    echo json_encode(['success' => false, 'message' => 'Book not found']);
    exit();
}

if ($book['stock_quantity'] < $quantity) {
    echo json_encode(['success' => false, 'message' => 'Insufficient stock']);
    exit();
}

$book_title = $book['title'];

// Check if already in cart
$stmt = $conn->prepare("SELECT id, quantity FROM shopping_cart WHERE user_id = ? AND book_id = ?");
$stmt->bind_param("ii", $user_id, $book_id);
$stmt->execute();
$cart_item = $stmt->get_result()->fetch_assoc();

if ($cart_item) {
    // Update quantity
    $new_quantity = $cart_item['quantity'] + $quantity;
    if ($new_quantity > $book['stock_quantity']) {
        echo json_encode(['success' => false, 'message' => 'Cannot add more than available stock']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE shopping_cart SET quantity = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_quantity, $cart_item['id']);
    $stmt->execute();
} else {
    // Insert new item
    $stmt = $conn->prepare("INSERT INTO shopping_cart (user_id, book_id, quantity, added_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iii", $user_id, $book_id, $quantity);
    $stmt->execute();
}

// Log the activity (only once, after the operation)
$logger->logCartAction('add', "$book_title (Quantity: $quantity)");

echo json_encode(['success' => true, 'message' => 'Added to cart successfully']);
?>
