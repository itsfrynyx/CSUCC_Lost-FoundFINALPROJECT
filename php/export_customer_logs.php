<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get admin data and verify role
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$current_role = strtolower(trim($stmt->get_result()->fetch_assoc()['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

// Get filter parameters
$activity_filter = $_GET['activity'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$month_filter = $_GET['month'] ?? '';
$search_query = $_GET['search'] ?? '';
$ip_filter = $_GET['ip'] ?? '';
$format = $_GET['format'] ?? 'csv';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

$where_conditions[] = "ual.activity_type NOT IN ('page_view', 'failed_login', 'login_failed', 'account_locked')";

if ($activity_filter) {
    $where_conditions[] = "ual.activity_type = ?";
    $params[] = $activity_filter;
    $param_types .= 's';
}

if ($date_from) {
    $where_conditions[] = "DATE(ual.timestamp) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(ual.timestamp) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if (preg_match('/^\d{4}-\d{2}$/', $month_filter)) {
    $where_conditions[] = "DATE_FORMAT(ual.timestamp, '%Y-%m') = ?";
    $params[] = $month_filter;
    $param_types .= 's';
}

$where_conditions[] = "u.role = 'customer'";

if ($search_query) {
    $where_conditions[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR ual.activity_details LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ssssss';
}

if ($ip_filter) {
    $where_conditions[] = "ual.ip_address LIKE ?";
    $params[] = "%{$ip_filter}%";
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get logs grouped by session
$query = "
    SELECT ual.session_id, ual.user_id, ual.ip_address, ual.user_agent,
           MIN(ual.timestamp) as first_activity,
           MAX(ual.timestamp) as last_activity_time,
           GROUP_CONCAT(DISTINCT ual.activity_type SEPARATOR ', ') as activities,
           GROUP_CONCAT(ual.activity_details SEPARATOR ' | ') as details,
           u.username, u.full_name, u.firstname, u.lastname, u.email,
           usa.login_time, usa.logout_time, usa.duration_seconds
    FROM user_activity_logs ual 
    LEFT JOIN infos u ON ual.user_id = u.id 
    LEFT JOIN user_session_activity usa ON ual.session_id = usa.session_id
    $where_clause
    GROUP BY ual.session_id, ual.user_id
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
    header('Content-Disposition: attachment; filename="customer_logs_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'Session ID', 'Username', 'Full Name', 'Email', 'Activities', 'Activity Details', 
        'IP Address', 'User Agent', 'Session Start', 'Session End', 
        'Login Time', 'Logout Time', 'Duration (seconds)'
    ]);
    
    // Data
    foreach ($logs as $log) {
        $full_name = $log['full_name'] ?? ($log['firstname'] . ' ' . $log['lastname']);
        
        fputcsv($output, [
            $log['session_id'] ?? '',
            $log['username'] ?? 'Guest',
            $full_name,
            $log['email'] ?? '',
            $log['activities'],
            $log['details'] ?? '',
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
    header('Content-Disposition: attachment; filename="customer_logs_' . date('Y-m-d_His') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>Session ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Activities</th>';
    echo '<th>Activity Details</th><th>IP Address</th><th>User Agent</th>';
    echo '<th>Session Start</th><th>Session End</th><th>Login Time</th><th>Logout Time</th><th>Duration (seconds)</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($logs as $log) {
        $full_name = $log['full_name'] ?? ($log['firstname'] . ' ' . $log['lastname']);
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($log['session_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['username'] ?? 'Guest') . '</td>';
        echo '<td>' . htmlspecialchars($full_name) . '</td>';
        echo '<td>' . htmlspecialchars($log['email'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['activities']) . '</td>';
        echo '<td>' . htmlspecialchars($log['details'] ?? '') . '</td>';
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
    header('Content-Disposition: attachment; filename="customer_logs_' . date('Y-m-d_His') . '.pdf"');
    
    echo '<!DOCTYPE html>';
    echo '<html><head><meta charset="UTF-8">';
    echo '<style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #17a2b8; color: white; }
        h1 { text-align: center; }
    </style>';
    echo '</head><body>';
    echo '<h1>Customer Activity Logs - ' . date('Y-m-d H:i:s') . '</h1>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Username</th><th>Activities</th><th>Details</th><th>IP</th><th>Session Start</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($log['username'] ?? 'Guest') . '</td>';
        echo '<td>' . htmlspecialchars($log['activities']) . '</td>';
        echo '<td>' . htmlspecialchars(substr($log['details'] ?? '', 0, 100)) . '</td>';
        echo '<td>' . htmlspecialchars($log['ip_address'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['first_activity']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '<script>window.print();</script>';
    echo '</body></html>';
    exit();
}
