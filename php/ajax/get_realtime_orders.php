<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../DB/conn.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['super_admin_id'];

// Verify admin role
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if (!$admin_data || !in_array($admin_data['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Get recent orders with customer information
    $query = "
        SELECT 
            o.*,
            i.username,
            i.email,
            COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as customer_name,
            COUNT(oi.id) as item_count
        FROM orders o
        LEFT JOIN infos i ON o.user_id = i.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    
    $result = $conn->query($query);
    $orders = [];
    
    while ($row = $result->fetch_assoc()) {
        $orders[] = [
            'id' => $row['id'],
            'order_number' => $row['order_number'],
            'customer_name' => $row['customer_name'] ?? 'Unknown',
            'customer_email' => $row['email'] ?? 'N/A',
            'total_amount' => $row['total_amount'],
            'status' => $row['status'],
            'payment_method' => $row['payment_method'] ?? 'N/A',
            'item_count' => $row['item_count'],
            'created_at' => $row['created_at'],
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'cancellation_reason' => $row['cancellation_reason'] ?? null,
            'time_ago' => timeAgo($row['created_at']),
            'is_new_cancellation' => ($row['status'] === 'cancelled' && $row['cancelled_at'] && (time() - strtotime($row['cancelled_at'])) < 300), // New if cancelled within last 5 minutes
            'is_new_completion' => (in_array($row['status'], ['delivered', 'completed']) && (time() - strtotime($row['created_at'])) < 300) // New if completed within last 5 minutes
        ];
    }
    
    // Get order statistics
    $stats = [
        'total_orders' => $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'] ?? 0,
        'pending_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0,
        'processing_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'processing'")->fetch_assoc()['count'] ?? 0,
        'shipped_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'shipped'")->fetch_assoc()['count'] ?? 0,
        'delivered_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'delivered'")->fetch_assoc()['count'] ?? 0,
        'cancelled_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'cancelled'")->fetch_assoc()['count'] ?? 0,
        'today_orders' => $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'] ?? 0,
        'today_revenue' => $conn->query("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM orders WHERE DATE(created_at) = CURDATE() AND status IN ('delivered', 'confirmed', 'completed')")->fetch_assoc()['revenue'] ?? 0
    ];
    
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}
?>
