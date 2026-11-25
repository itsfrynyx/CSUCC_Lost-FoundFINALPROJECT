<?php
session_start();
require_once '../../DB/conn.php';

header('Content-Type: application/json');

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Only a Super Admin may grant Admin access.
$admin_id = $_SESSION['super_admin_id'];
$role_check = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$role_check->bind_param("i", $admin_id);
$role_check->execute();
$admin_role = $role_check->get_result()->fetch_assoc()['role'] ?? '';

if (strtolower(trim($admin_role)) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Super Administrators can promote customers to Admin']);
    exit();
}

// Get customer ID
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
    exit();
}

if (!isset($_POST['customer_id']) || empty($_POST['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit();
}

$customer_id = intval($_POST['customer_id']);

// Start transaction
$conn->begin_transaction();

try {
    // Get customer data
    $customer_stmt = $conn->prepare("SELECT id, username, email, full_name, firstname, lastname, role, status FROM infos WHERE id = ? AND role = 'customer'");
    $customer_stmt->bind_param("i", $customer_id);
    $customer_stmt->execute();
    $customer = $customer_stmt->get_result()->fetch_assoc();
    
    if (!$customer) {
        throw new Exception('Customer not found or already an admin');
    }
    
    // Check if customer is offline
    if (isset($customer['status']) && strtolower(trim($customer['status'])) === 'offline') {
        throw new Exception('Cannot promote offline customers. Customer must be online to be promoted.');
    }
    
    // Promote customer to admin
    $promote_stmt = $conn->prepare("UPDATE infos SET role = 'admin', approved = 1, status = 'active' WHERE id = ?");
    $promote_stmt->bind_param("i", $customer_id);
    
    if (!$promote_stmt->execute()) {
        throw new Exception('Failed to promote customer');
    }
    
    // Log the activity for the admin
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $customer_name = $customer['full_name'] ?? ($customer['firstname'] . ' ' . $customer['lastname']);
    $description = "Promoted customer '{$customer['username']}' ({$customer_name}) to Administrator role";
    
    // Determine user type for logging
    $user_type = 'super_admin';
    
    $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, ?, 'promote_to_admin', ?, ?, ?)");
    $log_stmt->bind_param("issss", $admin_id, $user_type, $description, $ip_address, $user_agent);
    $log_stmt->execute();
    
    // Create notification for the newly promoted admin
    $notification_title = "You've been promoted to Administrator!";
    $notification_message = "Congratulations! You have been promoted to Administrator. You now have access to the admin dashboard and management features.";
    
    // Check if user_notifications table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
    if ($table_check && $table_check->num_rows > 0) {
        $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'promotion', ?, ?)");
        $notif_stmt->bind_param("iss", $customer_id, $notification_title, $notification_message);
        $notif_stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Customer successfully promoted to Administrator',
        'customer_id' => $customer_id,
        'customer_name' => $customer_name
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
