<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=inventory_report_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['Book ID', 'Title', 'Author', 'ISBN', 'Genre', 'Price', 'Stock Quantity', 'Status', 'Added Date']);

// Get inventory data
$query = "
    SELECT id, title, author, isbn, genre, price, stock_quantity, created_at
    FROM books
    ORDER BY title ASC
";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = 'In Stock';
        if ($row['stock_quantity'] == 0) {
            $status = 'Out of Stock';
        } elseif ($row['stock_quantity'] < 10) {
            $status = 'Low Stock';
        }
        
        fputcsv($output, [
            $row['id'],
            $row['title'],
            $row['author'],
            $row['isbn'] ?? 'N/A',
            $row['genre'] ?? 'Uncategorized',
            number_format($row['price'], 2),
            $row['stock_quantity'],
            $status,
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
}

fclose($output);
exit();
?>
