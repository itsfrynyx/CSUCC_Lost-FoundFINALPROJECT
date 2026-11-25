<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$current_role = $result['role'];

// SUPER ADMIN ONLY
$normalized_role = strtolower(trim($current_role));
$is_super_admin = ($normalized_role === 'super_admin');

if (!$is_super_admin) {
    die('Access denied. Only Super Administrator can export logs.');
}

// Get filter parameters
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';
$ip_filter = $_GET['ip'] ?? '';
$format = $_GET['format'] ?? 'csv';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

$where_conditions[] = "al.action NOT IN ('page_view', 'failed_login', 'login_failed', 'account_locked')";

if ($action_filter) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action_filter;
    $param_types .= 's';
}

if ($date_from) {
    $where_conditions[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if ($search_query) {
    $where_conditions[] = "(i.username LIKE ? OR i.full_name LIKE ? OR al.description LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($ip_filter) {
    $where_conditions[] = "al.ip_address LIKE ?";
    $params[] = "%{$ip_filter}%";
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get logs grouped by session
$query = "
    SELECT al.session_id, al.user_id, al.user_type, al.ip_address, al.user_agent,
           MIN(al.created_at) as first_activity,
           MAX(al.created_at) as last_activity_time,
           GROUP_CONCAT(DISTINCT al.action SEPARATOR ', ') as actions,
           GROUP_CONCAT(al.description SEPARATOR ' | ') as descriptions,
           i.username, i.full_name, i.role,
           usa.login_time, usa.logout_time, usa.duration_seconds
    FROM admin_activity_log al 
    LEFT JOIN infos i ON al.user_id = i.id 
    LEFT JOIN user_session_activity usa ON al.session_id = usa.session_id
    $where_clause
    GROUP BY al.session_id, al.user_id
    ORDER BY last_activity_time DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $logs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
}

// Export based on format
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="admin_logs_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'Session ID', 'Username', 'Full Name', 'Role', 'Actions', 'Activities', 
        'IP Address', 'User Agent', 'Session Start', 'Session End', 
        'Login Time', 'Logout Time', 'Duration (seconds)'
    ]);
    
    // Data
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['session_id'] ?? '',
            $log['username'] ?? 'Unknown',
            $log['full_name'] ?? 'Unknown',
            $log['role'] ?? 'Unknown',
            $log['actions'],
            $log['descriptions'] ?? '',
            $log['ip_address'] ?? '',
            $log['user_agent'] ?? '',
            $log['first_activity'],
            $log['last_activity_time'],
            $log['login_time'] ?? '',
            $log['logout_time'] ?? '',
            $log['duration_seconds'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="admin_logs_' . date('Y-m-d_His') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>Session ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Actions</th>';
    echo '<th>Activities</th><th>IP Address</th><th>User Agent</th>';
    echo '<th>Session Start</th><th>Session End</th><th>Login Time</th><th>Logout Time</th><th>Duration (seconds)</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($log['session_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['username'] ?? 'Unknown') . '</td>';
        echo '<td>' . htmlspecialchars($log['full_name'] ?? 'Unknown') . '</td>';
        echo '<td>' . htmlspecialchars($log['role'] ?? 'Unknown') . '</td>';
        echo '<td>' . htmlspecialchars($log['actions']) . '</td>';
        echo '<td>' . htmlspecialchars($log['descriptions'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['ip_address'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['user_agent'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['first_activity']) . '</td>';
        echo '<td>' . htmlspecialchars($log['last_activity_time']) . '</td>';
        echo '<td>' . htmlspecialchars($log['login_time'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['logout_time'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['duration_seconds'] ?? '') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</body></html>';
    exit();
}

if ($format === 'pdf') {
    // Simple HTML to PDF conversion
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="admin_logs_' . date('Y-m-d_His') . '.pdf"');
    
    // For a simple implementation, we'll output HTML that can be printed to PDF
    // In production, you'd use a library like TCPDF or mPDF
    echo '<!DOCTYPE html>';
    echo '<html><head><meta charset="UTF-8">';
    echo '<style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        h1 { text-align: center; }
    </style>';
    echo '</head><body>';
    echo '<h1>Admin Activity Logs - ' . date('Y-m-d H:i:s') . '</h1>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Username</th><th>Actions</th><th>Activities</th><th>IP</th><th>Session Start</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($log['username'] ?? 'Unknown') . '</td>';
        echo '<td>' . htmlspecialchars($log['actions']) . '</td>';
        echo '<td>' . htmlspecialchars(substr($log['descriptions'] ?? '', 0, 100)) . '</td>';
        echo '<td>' . htmlspecialchars($log['ip_address'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['first_activity']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '<script>window.print();</script>';
    echo '</body></html>';
    exit();
}
