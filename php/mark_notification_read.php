<?php
/**
 * Mark Notification as Read
 * This script marks a notification as read or marks all notifications as read
 */

session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/account_input_validation.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST to update notifications.']);
    exit;
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'Send the notification request as JSON.']);
    exit;
}


// Check if user is logged in
$user_id = null;
if (isset($_SESSION['super_admin_id'])) {
    $user_id = $_SESSION['super_admin_id'];
} elseif (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
}

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// The page can be opened before the optional notification migration is run.
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('order', 'delivery', 'promotion', 'new_book', 'price_drop', 'low_stock', 'system') NOT NULL,
    data JSON NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES infos(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created (created_at)
)");

// Get JSON input
$input = json_decode(file_get_contents('php://input', false, null, 0, 4097), true);
if (!is_array($input) || count($input) !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter one valid notification action.']);
    exit;
}

// Check if mark all
if (isset($input['mark_all']) && $input['mark_all'] === true) {
    // Mark all notifications as read for this user
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read', 'affected_rows' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error marking all notifications as read']);
    }
} else {
    // Mark single notification as read
    $notification_id = account_positive_integer($input['notification_id'] ?? null);
    
    if ($notification_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        exit();
    }
    
    // Mark notification as read
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error marking notification as read']);
    }
}

$conn->close();
?>
