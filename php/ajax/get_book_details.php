<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Please login first']);
    exit();
}

$book_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($book_id <= 0) {
    echo json_encode(['error' => 'Invalid book ID']);
    exit();
}

// Get book details
$stmt = $conn->prepare("SELECT id, title, author, description, price, stock_quantity, cover_image, isbn, publisher, publication_year, genre, created_at FROM books WHERE id = ? AND is_active = 1");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    echo json_encode(['error' => 'Book not found']);
    exit();
}

echo json_encode($book);
?>
