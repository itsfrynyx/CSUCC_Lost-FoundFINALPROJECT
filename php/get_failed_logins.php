<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if ($admin_data['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Get time range (default: last 24 hours)
$hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;

// Get failed login attempts
$query = "
    SELECT 
        aal.*,
        i.username,
        i.full_name,
        i.role,
        i.email
    FROM admin_activity_log aal
    LEFT JOIN infos i ON aal.user_id = i.id
    WHERE aal.action IN ('failed_login', 'account_locked')
    AND aal.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ORDER BY aal.created_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $hours);
$stmt->execute();
$failed_logins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_failed,
        COUNT(DISTINCT aal.user_id) as unique_users,
        COUNT(DISTINCT aal.ip_address) as unique_ips,
        COUNT(CASE WHEN aal.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as last_hour,
        COUNT(CASE WHEN aal.action = 'account_locked' THEN 1 END) as locked_accounts
    FROM admin_activity_log aal
    WHERE aal.action IN ('failed_login', 'account_locked')
    AND aal.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $hours);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Format the data
$formatted_logins = array_map(function($login) {
    // Use full_name if available, otherwise use username
    $display_name = !empty($login['full_name']) ? $login['full_name'] : $login['username'];
    if (empty($display_name)) {
        $display_name = 'Unknown User';
    }
    
    return [
        'id' => $login['id'],
        'username' => $login['username'] ?? 'Unknown',
        'full_name' => $display_name,
        'role' => $login['role'] ?? 'unknown',
        'email' => $login['email'] ?? 'N/A',
        'ip_address' => $login['ip_address'] === '::1' ? '127.0.0.1 (localhost)' : $login['ip_address'],
        'user_agent' => $login['user_agent'],
        'description' => $login['description'],
        'action' => $login['action'],
        'created_at' => $login['created_at'],
        'time_ago' => timeAgo($login['created_at'])
    ];
}, $failed_logins);

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } else {
        return floor($diff / 86400) . ' days ago';
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'failed_logins' => $formatted_logins,
    'statistics' => $stats,
    'timestamp' => date('Y-m-d H:i:s')
]);
