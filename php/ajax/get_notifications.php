<?php
/**
 * Notifications API Endpoint
 * Returns user notifications for the dropdown
 */

session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $query = "
        SELECT n.id, n.title, n.message, n.type, n.is_read, n.created_at,
               b.cover_image as book_image
        FROM notifications n
        LEFT JOIN books b ON n.book_id = b.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Format time ago
    foreach ($notifications as &$notif) {
        $time = strtotime($notif['created_at']);
        $diff = time() - $time;
        
        if ($diff < 60) {
            $notif['time_ago'] = 'Just now';
        } elseif ($diff < 3600) {
            $notif['time_ago'] = floor($diff / 60) . ' min ago';
        } elseif ($diff < 86400) {
            $notif['time_ago'] = floor($diff / 3600) . ' hours ago';
        } elseif ($diff < 604800) {
            $notif['time_ago'] = floor($diff / 86400) . ' days ago';
        } else {
            $notif['time_ago'] = date('M d, Y', $time);
        }
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);

} catch (Exception $e) {
    error_log("Notifications error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to load notifications']);
}
?>
