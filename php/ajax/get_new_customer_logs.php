<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$role_stmt = $conn->prepare("SELECT role FROM infos WHERE id = ? LIMIT 1");
$role_stmt->bind_param("i", $_SESSION['super_admin_id']);
$role_stmt->execute();
$current_role = strtolower(trim($role_stmt->get_result()->fetch_assoc()['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$last_check = $_GET['last_check'] ?? '';

// Get the latest log entry timestamp
$query = "SELECT MAX(cal.timestamp) AS timestamp FROM customer_activity_log cal INNER JOIN infos i ON cal.customer_id = i.id WHERE i.role = 'customer' AND cal.activity_type NOT IN ('failed_login', 'login_failed', 'account_locked')";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $latest = $result->fetch_assoc();
    $latest_timestamp = $latest['timestamp'];
    
    // If last_check is empty (first load), no new logs
    if (empty($last_check)) {
        echo json_encode([
            'has_new_logs' => false,
            'new_logs' => [],
            'latest_timestamp' => $latest_timestamp
        ]);
        exit();
    }
    
    // Check if there are new logs OR updated logout times since last check
    $check_query = "
        SELECT COUNT(*) as count 
        FROM customer_activity_log cal
        INNER JOIN infos i ON cal.customer_id = i.id AND i.role = 'customer'
        LEFT JOIN user_session_activity usa ON cal.session_id = usa.session_id
        WHERE (cal.timestamp > ? OR (usa.logout_time IS NOT NULL AND usa.logout_time > ?))
        AND cal.activity_type NOT IN ('failed_login', 'login_failed', 'account_locked')
    ";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ss", $last_check, $last_check);
    $stmt->execute();
    $count_result = $stmt->get_result()->fetch_assoc();
    
    $new_count = $count_result['count'];
    
    if ($new_count > 0) {
        // Get the new logs and updated logout times
        $new_logs_query = "
            SELECT cal.*, i.username, i.idnumber, i.full_name, i.firstname, i.lastname, i.email,
                   usa.login_time, usa.logout_time, usa.duration_seconds
            FROM customer_activity_log cal 
            INNER JOIN infos i ON cal.customer_id = i.id AND i.role = 'customer'
            LEFT JOIN user_session_activity usa ON cal.session_id = usa.session_id
            WHERE (cal.timestamp > ? OR (usa.logout_time IS NOT NULL AND usa.logout_time > ?))
            AND cal.activity_type NOT IN ('failed_login', 'login_failed', 'account_locked')
            ORDER BY cal.timestamp DESC 
            LIMIT 10
        ";
        $stmt = $conn->prepare($new_logs_query);
        $stmt->bind_param("ss", $last_check, $last_check);
        $stmt->execute();
        $new_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'has_new_logs' => true,
            'new_logs' => $new_logs,
            'count' => $new_count,
            'latest_timestamp' => $latest_timestamp
        ]);
    } else {
        echo json_encode([
            'has_new_logs' => false,
            'new_logs' => [],
            'latest_timestamp' => $latest_timestamp
        ]);
    }
} else {
    echo json_encode([
        'has_new_logs' => false,
        'new_logs' => [],
        'latest_timestamp' => null
    ]);
}
?>
