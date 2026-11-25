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
header('Content-Disposition: attachment; filename=sales_report_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['Order ID', 'Order Number', 'Customer Name', 'Email', 'Total Amount', 'Status', 'Order Date']);

// Get sales data
$query = "
    SELECT o.id, o.order_number, 
    COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name,
    i.email, o.total_amount, o.status, o.created_at
    FROM orders o
    LEFT JOIN infos i ON o.user_id = i.id
    ORDER BY o.created_at DESC
";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['order_number'] ?? str_pad($row['id'], 6, '0', STR_PAD_LEFT),
            $row['customer_name'] ?? 'Guest',
            $row['email'] ?? 'N/A',
            number_format($row['total_amount'], 2),
            ucfirst($row['status']),
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
}

fclose($output);
exit();
?>
