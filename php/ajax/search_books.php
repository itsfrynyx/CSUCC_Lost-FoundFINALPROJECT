<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    echo json_encode(['success' => false, 'books' => []]);
    exit();
}

$query = '%' . trim($_GET['q']) . '%';

$stmt = $conn->prepare("
    SELECT id, title, author, price, cover_image 
    FROM books 
    WHERE is_active = 1 
    AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR genre LIKE ?)
    ORDER BY title ASC 
    LIMIT 10
");

$stmt->bind_param("ssss", $query, $query, $query, $query);
$stmt->execute();
$result = $stmt->get_result();

$books = [];
while ($row = $result->fetch_assoc()) {
    // Fix cover image path
    if (empty($row['cover_image']) || $row['cover_image'] === '0') {
        $row['cover_image'] = '../PICTURES/default-book.jpg';
    } else {
        $row['cover_image'] = '../uploads/books/' . $row['cover_image'];
    }
    $books[] = $row;
}

echo json_encode([
    'success' => true,
    'books' => $books
]);
?>
