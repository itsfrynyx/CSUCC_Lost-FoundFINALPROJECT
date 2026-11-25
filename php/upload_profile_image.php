<?php
require_once __DIR__ . '/includes/upload_validation.php';
// Start output buffering
ob_start();

session_start();
require_once '../DB/conn.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead

// Set response header
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST to upload a photo.']);
    exit;
}

header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

$response = ['success' => false, 'message' => ''];

try {
    // Check if user is logged in
    if (!isset($_SESSION['super_admin_id'])) {
        throw new Exception('User not logged in');
    }
    
    // Get admin ID
    $admin_id = $_SESSION['super_admin_id'];
    
    // Check if file was uploaded
    if (!isset($_FILES['profile_image'])) {
        throw new Exception('No file uploaded');
    }
    
    $file = $_FILES['profile_image'];
    
    $file_extension = validated_upload_extension($file, ['jpg', 'png', 'gif'], 5 * 1024 * 1024);
    if ($file_extension === null) {
        throw new Exception('Choose a valid JPG, PNG, or GIF image up to 5 MB.');
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/profiles/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }
    
    // Generate unique filename
    $filename = 'profile_' . $admin_id . '_' . bin2hex(random_bytes(12)) . '.' . $file_extension;
    $upload_path = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception('Failed to move uploaded file.');
    }
    
    // Get old profile image to delete
    $get_old_sql = "SELECT profile_image FROM infos WHERE id = ?";
    $old_stmt = $conn->prepare($get_old_sql);
    $old_stmt->bind_param("i", $admin_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    
    // Update database
    $update_sql = "UPDATE infos SET profile_image = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    if (!$stmt) {
        throw new Exception('Unable to update the profile photo.');
    }
    $stmt->bind_param("si", $filename, $admin_id);
    
    if (!$stmt->execute()) {
        // Remove uploaded file if database update fails
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }
        throw new Exception('Unable to update the profile photo.');
    }
    
    // Delete old profile image if exists and different
    if ($old_data && !empty($old_data['profile_image']) && preg_match('/^[A-Za-z0-9_-]+[.](jpg|jpeg|png|gif)$/iD', $old_data['profile_image']) && $old_data['profile_image'] !== $filename) {
        $old_image_path = $upload_dir . $old_data['profile_image'];
        if (file_exists($old_image_path)) {
            unlink($old_image_path);
        }
    }
    
    $response['success'] = true;
    $response['message'] = 'Profile photo updated successfully!';
    $response['filename'] = $filename;
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    $response['message'] = $e instanceof mysqli_sql_exception ? 'Unable to update the profile photo.' : $e->getMessage();
}

// Clear any output buffers and send JSON response
ob_clean();
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit();
?>
