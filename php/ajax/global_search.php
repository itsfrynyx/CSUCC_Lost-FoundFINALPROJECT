<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit();
}

$results = [
    'orders' => [],
    'customers' => [],
    'books' => []
];

// Search Orders
$search_term = '%' . $query . '%';
$orders_query = "
    SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at,
    COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name
    FROM orders o
    LEFT JOIN infos i ON o.user_id = i.id
    WHERE o.order_number LIKE ? OR o.id LIKE ?
    LIMIT 5
";
$stmt = $conn->prepare($orders_query);
$stmt->bind_param("ss", $search_term, $search_term);
$stmt->execute();
$orders_result = $stmt->get_result();

while ($row = $orders_result->fetch_assoc()) {
    $results['orders'][] = [
        'id' => $row['id'],
        'title' => 'Order #' . str_pad($row['id'], 6, '0', STR_PAD_LEFT),
        'meta' => $row['customer_name'] . ' • ₱' . number_format($row['total_amount'], 2) . ' • ' . ucfirst($row['status']),
        'url' => 'admin_manage_orders.php?id=' . $row['id']
    ];
}

// Search Customers
$customers_query = "
    SELECT id, full_name, email, firstname, lastname
    FROM infos
    WHERE role = 'customer' 
    AND (full_name LIKE ? OR email LIKE ? OR firstname LIKE ? OR lastname LIKE ?)
    LIMIT 5
";
$stmt = $conn->prepare($customers_query);
$stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
$stmt->execute();
$customers_result = $stmt->get_result();

while ($row = $customers_result->fetch_assoc()) {
    $name = !empty($row['full_name']) ? $row['full_name'] : $row['firstname'] . ' ' . $row['lastname'];
    $results['customers'][] = [
        'id' => $row['id'],
        'title' => $name,
        'meta' => $row['email'],
        'url' => 'admin_manage_customers.php?id=' . $row['id']
    ];
}

// Search Books
$books_query = "
    SELECT id, title, author, isbn, price, stock_quantity
    FROM books
    WHERE title LIKE ? OR author LIKE ? OR isbn LIKE ?
    LIMIT 5
";
$stmt = $conn->prepare($books_query);
$stmt->bind_param("sss", $search_term, $search_term, $search_term);
$stmt->execute();
$books_result = $stmt->get_result();

while ($row = $books_result->fetch_assoc()) {
    $results['books'][] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'meta' => $row['author'] . ' • ₱' . number_format($row['price'], 2) . ' • Stock: ' . $row['stock_quantity'],
        'url' => 'admin_manage_books.php?id=' . $row['id']
    ];
}

header('Content-Type: application/json');
echo json_encode($results);
