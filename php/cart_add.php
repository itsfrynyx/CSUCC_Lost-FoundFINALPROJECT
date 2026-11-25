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
require_once __DIR__ . '/includes/ActivityLogger.php';

$user_id = $_SESSION['user_id'];
$book_id = account_positive_integer($_POST['book_id'] ?? null);
$quantity = account_positive_integer($_POST['quantity'] ?? 1);

if ($book_id <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

// Initialize activity logger
$logger = new ActivityLogger($conn, $user_id, 'customer');

// Check book stock and get title
$stmt = $conn->prepare("SELECT title, stock_quantity FROM books WHERE id = ? AND is_active = 1");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    echo json_encode(['success' => false, 'message' => 'Book not found']);
    exit();
}

$book_title = $book['title'];

if ($book['stock_quantity'] < $quantity) {
    echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
    exit();
}

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
        exit();
    }
    $stmt = $conn->prepare("UPDATE shopping_cart SET quantity = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_quantity, $cart_item['id']);
} else {
    // Add new item
    $stmt = $conn->prepare("INSERT INTO shopping_cart (user_id, book_id, quantity, added_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iii", $user_id, $book_id, $quantity);
}

if ($stmt->execute()) {
    // Log the activity
    $logger->logCartAction('add', "$book_title (Quantity: $quantity)");
    
    echo json_encode(['success' => true, 'message' => 'Added to cart']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to add to cart']);
}
?>
