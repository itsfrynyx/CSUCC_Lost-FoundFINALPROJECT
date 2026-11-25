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

if ($book_id === null) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid book ID.']);
    exit;
}

// Initialize activity logger
$logger = new ActivityLogger($conn, $user_id, 'customer');

// Get book title for logging
$book_stmt = $conn->prepare("SELECT title FROM books WHERE id = ?");
$book_stmt->bind_param("i", $book_id);
$book_stmt->execute();
$book_result = $book_stmt->get_result();
$book = $book_result->fetch_assoc();
$book_title = $book['title'] ?? 'Unknown Book';

// Check if wishlist table exists, create if not
$check_table = $conn->query("SHOW TABLES LIKE 'wishlist'");
if (!$check_table || $check_table->num_rows == 0) {
    $create_sql = "CREATE TABLE `wishlist` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `book_id` int(11) NOT NULL,
      `added_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_user_book` (`user_id`, `book_id`),
      KEY `idx_user_id` (`user_id`),
      KEY `idx_book_id` (`book_id`),
      KEY `idx_added_at` (`added_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($create_sql)) {
        echo json_encode(['success' => false, 'message' => 'Could not create wishlist table']);
        exit();
    }
}

// Check if already in wishlist
$stmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND book_id = ?");
$stmt->bind_param("ii", $user_id, $book_id);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();

if ($exists) {
    // Remove from wishlist
    $stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND book_id = ?");
    $stmt->bind_param("ii", $user_id, $book_id);
    $stmt->execute();
    
    // Log the activity
    $logger->logWishlistAction('remove', $book_title);
    
    echo json_encode(['success' => true, 'action' => 'removed', 'message' => 'Removed from wishlist']);
} else {
    // Add to wishlist
    if (!$book) {
        echo json_encode(['success' => false, 'message' => 'Book not found']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO wishlist (user_id, book_id, added_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("ii", $user_id, $book_id);
    $stmt->execute();
    
    // Log the activity
    $logger->logWishlistAction('add', $book_title);
    
    echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Added to wishlist']);
}
?>
