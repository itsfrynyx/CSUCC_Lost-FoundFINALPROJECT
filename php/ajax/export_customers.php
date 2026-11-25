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
header('Content-Disposition: attachment; filename=customers_report_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['Customer ID', 'Full Name', 'Email', 'Phone', 'Status', 'Approved', 'Registration Date']);

// Get customer data
$query = "
    SELECT id, 
    COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as customer_name,
    email, phone, status, approved, created_at
    FROM infos
    WHERE role = 'customer'
    ORDER BY created_at DESC
";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['customer_name'],
            $row['email'],
            $row['phone'] ?? 'N/A',
            ucfirst($row['status']),
            $row['approved'] ? 'Yes' : 'No',
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
}

fclose($output);
exit();
?>
