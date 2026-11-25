<?php
session_start();
require_once '../../DB/conn.php';

header('Content-Type: application/json');

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Only a Super Admin may transfer the role.
$current_admin_stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$current_admin_stmt->bind_param("i", $_SESSION['super_admin_id']);
$current_admin_stmt->execute();
$current_role_result = $current_admin_stmt->get_result()->fetch_assoc();

if (!$current_role_result || strtolower(trim($current_role_result['role'])) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Super Administrators can transfer the crown']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
        exit();
    }

    $new_superadmin_id = isset($_POST['new_superadmin_id']) ? intval($_POST['new_superadmin_id']) : 0;
    $current_superadmin_id = $_SESSION['super_admin_id'];
    
    // Validate new superadmin ID
    if ($new_superadmin_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid admin ID']);
        exit();
    }
    
    // Prevent passing torch to yourself
    if ($new_superadmin_id === $current_superadmin_id) {
        echo json_encode(['success' => false, 'message' => 'You cannot transfer the crown to yourself']);
        exit();
    }
    
    $new_admin_stmt = $conn->prepare("SELECT id, username, full_name, role, status FROM infos WHERE id = ? AND role IN ('admin', 'customer')");
    $new_admin_stmt->bind_param("i", $new_superadmin_id);
    $new_admin_stmt->execute();
    $new_admin = $new_admin_stmt->get_result()->fetch_assoc();
    
    if (!$new_admin) {
        echo json_encode(['success' => false, 'message' => 'Target user not found or not eligible']);
        exit();
    }
    
    // Check if target user is offline
    if (isset($new_admin['status']) && strtolower(trim($new_admin['status'])) === 'offline') {
        echo json_encode(['success' => false, 'message' => 'Cannot transfer the crown to an offline user. The user must be online to receive it.']);
        exit();
    }
    
    // Get current superadmin info
    $current_admin_stmt = $conn->prepare("SELECT username, full_name FROM infos WHERE id = ?");
    $current_admin_stmt->bind_param("i", $current_superadmin_id);
    $current_admin_stmt->execute();
    $current_admin = $current_admin_stmt->get_result()->fetch_assoc();
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // 1. Promote the selected account to Super Admin.
        $promote_stmt = $conn->prepare("UPDATE infos SET role = 'super_admin', approved = 1 WHERE id = ? AND role IN ('admin', 'customer')");
        $promote_stmt->bind_param("i", $new_superadmin_id);
        if (!$promote_stmt->execute()) {
            throw new Exception('Failed to promote new super admin');
        }
        
        // 2. Demote current super admin to admin and set status to inactive
        $demote_stmt = $conn->prepare("UPDATE infos SET role = 'admin', status = 'inactive' WHERE id = ?");
        $demote_stmt->bind_param("i", $current_superadmin_id);
        if (!$demote_stmt->execute()) {
            throw new Exception('Failed to demote current super admin');
        }
        
        // 3. Log the torch passing activity for the old super admin
        $current_user_type = (strtolower($current_role_result['role']) === 'super_admin') ? 'super_admin' : 'admin';
        $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, ?, 'pass_torch', ?, ?, ?)");
        $description = "Passed the torch to " . $new_admin['username'] . " (" . $new_admin['full_name'] . "). Account demoted to admin and disabled.";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $log_stmt->bind_param("issss", $current_superadmin_id, $current_user_type, $description, $ip_address, $user_agent);
        $log_stmt->execute();
        
        // 4. Log the torch receiving activity for the new super admin
        $log_stmt2 = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'super_admin', 'receive_torch', ?, ?, ?)");
        $description2 = "Received the torch from " . $current_admin['username'] . " (" . $current_admin['full_name'] . "). Promoted to Super Admin.";
        $log_stmt2->bind_param("isss", $new_superadmin_id, $description2, $ip_address, $user_agent);
        $log_stmt2->execute();
        
        // 5. Create notification for new super admin (if notifications table exists)
        $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
        if ($table_check && $table_check->num_rows > 0) {
            $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'promotion', 'You are now Super Admin!', 'Congratulations! You have been promoted to Super Admin. You now have full administrative control over the system.')");
            $notif_stmt->bind_param("i", $new_superadmin_id);
            $notif_stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Destroy current session (logout)
        session_destroy();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Torch passed successfully! You have been logged out.',
            'new_superadmin' => $new_admin['full_name']
        ]);
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
