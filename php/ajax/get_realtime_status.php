<?php
session_start();
require_once '../DB/conn.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$type = $_GET['type'] ?? 'admin'; // 'admin' or 'customer'

// Clean up old sessions first
$conn->query("
    UPDATE user_session_activity 
    SET status = 'inactive' 
    WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
    AND status = 'active'
");

// Function to parse user agent
function parseUserAgent($user_agent) {
    $browser = 'Unknown';
    $os = 'Unknown';
    $device = 'Desktop';
    
    // Detect browser
    if (preg_match('/Edg\//i', $user_agent)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome/i', $user_agent) && !preg_match('/Edg/i', $user_agent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox/i', $user_agent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
        $browser = 'Opera';
    } elseif (preg_match('/MSIE|Trident/i', $user_agent)) {
        $browser = 'IE';
    }
    
    // Detect OS
    if (preg_match('/Windows NT 10/i', $user_agent)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6.3/i', $user_agent)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/Windows NT 6.2/i', $user_agent)) {
        $os = 'Windows 8';
    } elseif (preg_match('/Windows NT 6.1/i', $user_agent)) {
        $os = 'Windows 7';
    } elseif (preg_match('/Windows/i', $user_agent)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X 10[._](\d+)/i', $user_agent, $matches)) {
        $os = 'macOS ' . $matches[1];
    } elseif (preg_match('/Mac/i', $user_agent)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux/i', $user_agent)) {
        $os = 'Linux';
    } elseif (preg_match('/Android (\d+)/i', $user_agent, $matches)) {
        $os = 'Android ' . $matches[1];
        $device = 'Mobile';
    } elseif (preg_match('/Android/i', $user_agent)) {
        $os = 'Android';
        $device = 'Mobile';
    } elseif (preg_match('/iPhone/i', $user_agent)) {
        $os = 'iOS';
        $device = 'iPhone';
    } elseif (preg_match('/iPad/i', $user_agent)) {
        $os = 'iOS';
        $device = 'iPad';
    }
    
    // Detect device type
    if (preg_match('/Mobile|Android|iPhone/i', $user_agent)) {
        $device = 'Mobile';
    } elseif (preg_match('/Tablet|iPad/i', $user_agent)) {
        $device = 'Tablet';
    }
    
    return [
        'browser' => $browser,
        'os' => $os,
        'device' => $device,
        'full' => "$browser • $os • $device"
    ];
}

if ($type === 'admin') {
    // Get admin status - prioritize active sessions over most recent sessions
    $query = $conn->prepare("
        SELECT 
            i.id, 
            i.username, 
            i.full_name, 
            i.role, 
            i.status,
            i.last_login,
            i.last_activity as user_last_activity,
            i.created_at,
            i.profile_image,
            COALESCE(active_usa.ip_address, recent_usa.ip_address) as ip_address,
            COALESCE(active_usa.user_agent, recent_usa.user_agent) as user_agent,
            COALESCE(active_usa.last_activity, recent_usa.last_activity) as session_last_activity,
            COALESCE(active_usa.login_time, recent_usa.login_time) as login_time,
            COALESCE(active_usa.logout_time, recent_usa.logout_time) as logout_time,
            COALESCE(active_usa.status, recent_usa.status) as session_status,
            CASE 
                WHEN active_usa.status = 'active' AND active_usa.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                WHEN i.status = 'active' AND i.last_login > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'active'
                ELSE 'offline'
            END as online_status,
            TIMESTAMPDIFF(SECOND, COALESCE(active_usa.last_activity, recent_usa.last_activity), NOW()) as seconds_since_activity
        FROM infos i 
        LEFT JOIN user_session_activity active_usa ON i.id = active_usa.user_id 
            AND active_usa.status = 'active'
            AND active_usa.id = (
                SELECT MAX(id) FROM user_session_activity 
                WHERE user_id = i.id AND status = 'active'
            )
        LEFT JOIN user_session_activity recent_usa ON i.id = recent_usa.user_id 
            AND active_usa.id IS NULL
            AND recent_usa.id = (
                SELECT MAX(id) FROM user_session_activity 
                WHERE user_id = i.id
            )
        WHERE i.role IN ('admin', 'super_admin')
        ORDER BY 
            CASE 
                WHEN i.role = 'super_admin' THEN 1
                WHEN i.role = 'admin' THEN 2
                ELSE 3
            END,
            i.full_name ASC
    ");
} else {
    // Get customer status - prioritize active sessions over most recent sessions
    $query = $conn->prepare("
        SELECT 
            i.id, 
            i.username, 
            i.full_name,
            i.firstname,
            i.lastname,
            i.role, 
            i.status,
            i.last_login,
            i.last_activity as user_last_activity,
            i.created_at,
            i.profile_image,
            COALESCE(active_usa.ip_address, recent_usa.ip_address) as ip_address,
            COALESCE(active_usa.user_agent, recent_usa.user_agent) as user_agent,
            COALESCE(active_usa.last_activity, recent_usa.last_activity) as session_last_activity,
            COALESCE(active_usa.login_time, recent_usa.login_time) as login_time,
            COALESCE(active_usa.logout_time, recent_usa.logout_time) as logout_time,
            COALESCE(active_usa.status, recent_usa.status) as session_status,
            CASE 
                WHEN active_usa.status = 'active' AND active_usa.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                WHEN i.status = 'active' AND i.last_login > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'active'
                ELSE 'offline'
            END as online_status,
            TIMESTAMPDIFF(SECOND, COALESCE(active_usa.last_activity, recent_usa.last_activity), NOW()) as seconds_since_activity
        FROM infos i 
        LEFT JOIN user_session_activity active_usa ON i.id = active_usa.user_id 
            AND active_usa.status = 'active'
            AND active_usa.id = (
                SELECT MAX(id) FROM user_session_activity 
                WHERE user_id = i.id AND status = 'active'
            )
        LEFT JOIN user_session_activity recent_usa ON i.id = recent_usa.user_id 
            AND active_usa.id IS NULL
            AND recent_usa.id = (
                SELECT MAX(id) FROM user_session_activity 
                WHERE user_id = i.id
            )
        WHERE i.role = 'customer'
        ORDER BY i.created_at DESC
        LIMIT 50
    ");
}

$query->execute();
$result = $query->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $user_agent_info = parseUserAgent($row['user_agent'] ?? '');
    
    // Use the most recent activity time: session_last_activity (if active session) > user_last_activity > last_login
    $most_recent_activity = $row['session_last_activity'] ?: ($row['user_last_activity'] ?: $row['last_login']);
    
    $users[] = [
        'id' => $row['id'],
        'username' => $row['username'],
        'full_name' => $row['full_name'] ?: ($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''),
        'role' => $row['role'],
        'status' => $row['status'],
        'online_status' => $row['online_status'],
        'last_login' => $row['last_login'],
        'last_activity' => $most_recent_activity,
        'login_time' => $row['login_time'],
        // Only include logout_time if session is inactive
        'logout_time' => ($row['session_status'] ?? 'inactive') === 'inactive' ? $row['logout_time'] : null,
        'ip_address' => $row['ip_address'] ?? 'Unknown',
        'browser' => $user_agent_info['browser'],
        'os' => $user_agent_info['os'],
        'device' => $user_agent_info['device'],
        'device_info' => $user_agent_info['full'],
        'created_at' => $row['created_at'],
        'profile_image' => $row['profile_image'],
        'seconds_since_activity' => $row['seconds_since_activity']
    ];
}

echo json_encode([
    'success' => true,
    'users' => $users,
    'timestamp' => date('Y-m-d H:i:s'),
    'total' => count($users)
]);
?>
