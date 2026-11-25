<?php
session_start();
require_once '../DB/conn.php';

header('Content-Type: application/json');

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['error' => 'Unauthorized', 'success' => false]);
    exit();
}

// Get admin data and verify super admin role
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if ($admin_data['role'] !== 'super_admin') {
    echo json_encode(['error' => 'Access denied', 'success' => false]);
    exit();
}

// Check if notification table exists
$table_check = $conn->query("SHOW TABLES LIKE 'super_admin_notifications'");
if ($table_check->num_rows == 0) {
    // Table doesn't exist - return empty state
    echo json_encode([
        'success' => true,
        'notifications' => [],
        'unread_count' => 0,
        'table_missing' => true,
        'message' => 'Notification system not installed. Please run the installation script.'
    ]);
    exit();
}

// Include the class only after verifying table exists
require_once __DIR__ . '/includes/SuperAdminNotifications.php';

try {
    $notifier = new SuperAdminNotifications($conn);
    $action = $_GET['action'] ?? 'get_recent';

    switch ($action) {
        case 'get_recent':
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $notifications = $notifier->getRecent($limit);
            
            // Format notifications
            foreach ($notifications as &$notif) {
                $notif['time_ago'] = SuperAdminNotifications::timeAgo($notif['created_at']);
                $notif['color_hex'] = SuperAdminNotifications::getColorClass($notif['color']);
            }
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $notifier->getUnreadCount()
            ]);
            break;
            
        case 'get_count':
            echo json_encode([
                'success' => true,
                'unread_count' => $notifier->getUnreadCount()
            ]);
            break;
            
        case 'mark_read':
            $notification_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($notification_id > 0) {
                $success = $notifier->markAsRead($notification_id);
                echo json_encode([
                    'success' => $success,
                    'unread_count' => $notifier->getUnreadCount()
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            }
            break;
            
        case 'mark_all_read':
            $success = $notifier->markAllAsRead();
            echo json_encode([
                'success' => $success,
                'unread_count' => 0
            ]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action', 'success' => false]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'System error',
        'message' => $e->getMessage()
    ]);
}
