<?php
require_once __DIR__ . '/../includes/account_input_validation.php';
session_start();
require_once __DIR__ . '/../../DB/conn.php';

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

$user_id = $_SESSION['user_id'];
$book_id = account_positive_integer($_POST['book_id'] ?? null);
$rating = account_positive_integer($_POST['rating'] ?? null, 5);
$review_title = account_input('review_title');
$review_text = account_input('review_text');

if (!$book_id || !$rating || !$review_title || !$review_text) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
    exit();
}

if (!account_text_is_valid($review_title, 255) || !account_text_is_valid(str_replace(["\r", "\n", "\t"], ' ', $review_text), 5000)) {
    echo json_encode(['success' => false, 'message' => 'Shorten the review title or text.']);
    exit;
}
$book = $conn->prepare('SELECT id FROM books WHERE id = ? AND is_active = 1');
$book->bind_param('i', $book_id);
$book->execute();
if (!$book->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Choose an existing book.']);
    exit;
}

// Check if user purchased this book
$stmt = $conn->prepare("SELECT COUNT(*) as purchased FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.user_id = ? AND oi.book_id = ? AND o.status = 'delivered'");
$stmt->bind_param("ii", $user_id, $book_id);
$stmt->execute();
$has_purchased = $stmt->get_result()->fetch_assoc()['purchased'] > 0;

// Insert review
$stmt = $conn->prepare("INSERT INTO book_reviews (book_id, user_id, rating, review_title, review_text, is_verified_purchase) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = ?, review_title = ?, review_text = ?, updated_at = NOW()");
$stmt->bind_param("iiissiiss", $book_id, $user_id, $rating, $review_title, $review_text, $has_purchased, $rating, $review_title, $review_text);

if ($stmt->execute()) {
    // Update book average rating and count
    $ratingUpdate = $conn->prepare('UPDATE books SET average_rating = (SELECT AVG(rating) FROM book_reviews WHERE book_id = ?), review_count = (SELECT COUNT(*) FROM book_reviews WHERE book_id = ?) WHERE id = ?');
    $ratingUpdate->bind_param('iii', $book_id, $book_id, $book_id);
    $ratingUpdate->execute();
    
    echo json_encode(['success' => true, 'message' => 'Review submitted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to submit review']);
}
?>
