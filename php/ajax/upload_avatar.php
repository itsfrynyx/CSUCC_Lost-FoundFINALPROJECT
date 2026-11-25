<?php
require_once __DIR__ . '/../includes/upload_validation.php';
session_start();
require_once __DIR__ . '/../../DB/conn.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST to upload a photo.']);
    exit;
}


try {
    // Check if logged in
    if (!isset($_SESSION['super_admin_id'])) {
        throw new Exception('Not authenticated');
    }

    $admin_id = $_SESSION['super_admin_id'];

    // Check if file was uploaded
    if (!isset($_FILES['avatar'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['avatar'];

    $extension = validated_upload_extension($file, ['jpg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
    if ($extension === null) {
        throw new Exception('Choose a valid JPG, PNG, GIF, or WebP image up to 5 MB.');
    }

    // Create upload directory
    $upload_dir = __DIR__ . '/../../uploads/avatars/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Cannot create upload directory');
        }
    }

    // Generate filename
    $filename = 'avatar_' . $admin_id . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }

    // Check if avatar column exists, if not create it
    $check_column = $conn->query("SHOW COLUMNS FROM infos LIKE 'avatar'");
    if ($check_column->num_rows == 0) {
        $conn->query("ALTER TABLE infos ADD COLUMN avatar VARCHAR(255) NULL");
    }

    // Get old avatar
    $stmt = $conn->prepare("SELECT avatar FROM infos WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $old_data = $result->fetch_assoc();

    // Update database
    $update_stmt = $conn->prepare("UPDATE infos SET avatar = ? WHERE id = ?");
    $update_stmt->bind_param("si", $filename, $admin_id);

    if (!$update_stmt->execute()) {
        @unlink($filepath);
        throw new Exception('Database update failed');
    }

    // Delete old avatar
    if (!empty($old_data['avatar']) && basename($old_data['avatar']) === $old_data['avatar'] && !str_contains($old_data['avatar'], '\\') && file_exists($upload_dir . $old_data['avatar'])) {
        @unlink($upload_dir . $old_data['avatar']);
    }

    // Log activity
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $log = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'avatar_update', 'Updated avatar', ?, ?)");
    $log->bind_param("iss", $admin_id, $ip, $ua);
    $log->execute();

    echo json_encode(['success' => true, 'message' => 'Avatar uploaded', 'filename' => $filename]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e instanceof mysqli_sql_exception ? 'Unable to update the avatar.' : $e->getMessage()]);
}
?>
