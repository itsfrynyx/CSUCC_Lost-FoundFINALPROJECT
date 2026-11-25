<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$current_role_stmt = $conn->prepare("SELECT role FROM infos WHERE id = ? LIMIT 1");
$current_role_stmt->bind_param("i", $_SESSION['super_admin_id']);
$current_role_stmt->execute();
$current_role = strtolower(trim($current_role_stmt->get_result()->fetch_assoc()['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (isset($_GET['id'])) {
    $admin_id = $_GET['id'];
    $target_stmt = $conn->prepare("SELECT id, username, email, full_name, role, status FROM infos WHERE id = ? AND role " . ($current_role === 'super_admin' ? "IN ('admin', 'super_admin')" : "= 'admin'"));
    $target_stmt->bind_param("i", $admin_id);
    $target_stmt->execute();
    $result = $target_stmt->get_result();
    
    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($admin);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Admin not found']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ID not provided']);
}
?>
