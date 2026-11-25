<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['count' => 0]);
    exit();
}

// Get notification counts
$pending_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$low_stock_books = $conn->query("SELECT COUNT(*) as count FROM books WHERE stock_quantity < 10")->fetch_assoc()['count'] ?? 0;
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 0")->fetch_assoc()['count'] ?? 0;
$out_of_stock = $conn->query("SELECT COUNT(*) as count FROM books WHERE stock_quantity = 0")->fetch_assoc()['count'] ?? 0;
$recent_failed_logins = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE action = 'failed_login' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['count'] ?? 0;
$new_customers_today = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'] ?? 0;

$total_notifications = $pending_orders + $low_stock_books + $pending_approvals + $out_of_stock + $recent_failed_logins + $new_customers_today;

echo json_encode([
    'count' => $total_notifications,
    'pending_orders' => $pending_orders,
    'low_stock_books' => $low_stock_books,
    'pending_approvals' => $pending_approvals,
    'out_of_stock' => $out_of_stock,
    'recent_failed_logins' => $recent_failed_logins,
    'new_customers_today' => $new_customers_today
]);
?>
