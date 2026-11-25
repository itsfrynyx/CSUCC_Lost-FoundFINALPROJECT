<?php 
// Start a PHP session
session_start();

// Capture session ID before any operations - use stored session_id if available
$current_session_id = $_SESSION['session_id'] ?? session_id();

// If still empty, something is wrong - log it
if (empty($current_session_id)) {
    error_log("WARNING: Session ID is empty during logout. User: " . ($_SESSION['super_admin_id'] ?? $_SESSION['user_id'] ?? 'unknown'));
    $current_session_id = 'unknown_' . time(); // Fallback to prevent empty inserts
}

// Establish a database connection
require_once __DIR__ . "/../DB/conn.php";

// Debug session
file_put_contents(__DIR__ . '/../scratch/session_debug.txt', "Logout Start: " . date('H:i:s') . " | SID: " . session_id() . " | SESSION: " . json_encode($_SESSION) . "\n", FILE_APPEND);

// Get client information
$ip_address = $_SERVER["REMOTE_ADDR"] ?? "Unknown";
$user_agent = $_SERVER["HTTP_USER_AGENT"] ?? "Unknown";
$logout_time = date('Y-m-d H:i:s');

// Check if admin or customer logout
if (isset($_SESSION["super_admin_id"]) && isset($conn)) {
    // Admin logout (super_admin or admin)
    $user_id = $_SESSION["super_admin_id"];
    $user_role = $_SESSION["admin_role"] ?? 'admin';
    
    // Determine user type and description
    $user_type = ($user_role === 'super_admin') ? 'super_admin' : 'admin';
    $description = ($user_role === 'super_admin') ? 'Super Admin logged out' : 'Admin logged out';
    
    // Update user status to offline
    $update_status_stmt = $conn->prepare("UPDATE infos SET status = 'offline', logout_time = ? WHERE id = ?");
    $update_status_stmt->bind_param("si", $logout_time, $user_id);
    $update_status_stmt->execute();
    
    // Log admin logout activity using centralized logger
    require_once __DIR__ . "/activity_logger.php";
    logLogout($user_id, $user_type, $current_session_id);
    
} elseif (isset($_SESSION["user_id"]) && isset($conn)) {
    // Customer logout
    $user_id = $_SESSION["user_id"];
    
    // Update user status to offline
    $update_status_stmt = $conn->prepare("UPDATE infos SET status = 'offline', logout_time = ? WHERE id = ?");
    $update_status_stmt->bind_param("si", $logout_time, $user_id);
    $update_status_stmt->execute();
    
    // Log customer logout activity using centralized logger
    require_once __DIR__ . "/activity_logger.php";
    logLogout($user_id, 'customer', $current_session_id);
}

// Unset and destroy the session
session_unset();

// Clear the session cookie if it exists
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

session_destroy();

// Redirect to the sign-in page
header("location: Sign_in.php");
?>