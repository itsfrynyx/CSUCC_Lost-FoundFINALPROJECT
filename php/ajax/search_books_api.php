<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($search)) {
    echo json_encode(['results' => []]);
    exit();
}

// Search books
$stmt = $conn->prepare("SELECT id, title, author, price, stock_quantity, cover_image, isbn 
                        FROM books 
                        WHERE is_active = 1 
                        AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?) 
                        LIMIT 8");
$searchTerm = '%' . $search . '%';
$stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
$stmt->execute();
$result = $stmt->get_result();

$books = [];
while ($row = $result->fetch_assoc()) {
    $books[] = $row;
}

echo json_encode(['results' => $books, 'query' => $search]);
?>
