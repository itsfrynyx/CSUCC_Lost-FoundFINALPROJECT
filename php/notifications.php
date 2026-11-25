<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: Sign_in.php"); exit(); }
require_once __DIR__ . '/../DB/conn.php';

$user_id = $_SESSION['user_id'];

// Keep the notifications page usable on installations that have not run the
// notification migration yet. This is safe to run repeatedly.
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

// Get user data
$stmt = $conn->prepare("SELECT firstname, lastname, profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get stats
$get_stats = function() use ($conn, $user_id) {
    $stats = ['cart_items' => 0, 'wishlist_items' => 0];
    try { $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shopping_cart WHERE user_id = ?"); $stmt->bind_param("i", $user_id); $stmt->execute(); $stats['cart_items'] = $stmt->get_result()->fetch_assoc()['count']; } catch (Exception $e) { $stats['cart_items'] = 0; }
    try { $stmt = $conn->prepare("SELECT COUNT(*) as count FROM wishlist WHERE user_id = ?"); $stmt->bind_param("i", $user_id); $stmt->execute(); $stats['wishlist_items'] = $stmt->get_result()->fetch_assoc()['count']; } catch (Exception $e) { $stats['wishlist_items'] = 0; }
    return $stats;
};
$stats = $get_stats();

// Get all notifications. An empty result is a valid first-run state.
$notifications = [];
$stmt = $conn->prepare("SELECT id, title, message, type, data, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get unread count
$unread_count = count(array_filter($notifications, fn($n) => !$n['is_read']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.2/mdb.min.css" rel="stylesheet">
    <!-- Unified Customer Styles -->
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_unified_typography.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Roboto', sans-serif;
            background: var(--bg-page);
            min-height: 100vh;
            color: var(--text-primary);
         padding-top: 70px !important;}

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 40px;
            min-height: 100vh;
        }

        .breadcrumb-nav {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .breadcrumb-nav a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb-nav a:hover {
            color: var(--color-brand-primary);
        }

        .breadcrumb-nav .separator {
            color: var(--text-secondary);
        }

        .breadcrumb-nav .current {
            color: var(--color-brand-primary);
            font-weight: 600;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 30px;
            border: 1px solid rgba(233, 236, 239, 0.8);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-brand-primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn-mark-all {
            padding: 10px 20px;
            background: var(--color-brand-primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .btn-mark-all:hover {
            background: var(--color-brand-secondary);
            transform: translateY(-2px);
        }

        .notifications-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(233, 236, 239, 0.8);
        }

        .notification-item {
            display: flex;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: rgba(136, 43, 42, 0.03);
            border-left: 4px solid var(--color-brand-primary);
        }

        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }

        .notification-book-cover {
            width: 50px;
            height: 65px;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            position: relative;
        }

        .notification-book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notification-icon.order { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .notification-icon.delivery { background: linear-gradient(135deg, #8dc263 0%, #76b882 100%); }
        .notification-icon.promotion { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .notification-icon.new_book { background: linear-gradient(135deg, #882b2a 0%, #a14a22 100%); }
        .notification-icon.price_drop { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .notification-icon.low_stock { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .notification-icon.system { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .notification-message {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #999;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-secondary);
        }
    </style>
</head>
<body><?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/customer_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <nav class="breadcrumb-nav">
                <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
                <span class="separator">/</span>
                <span class="current"><i class="fas fa-bell"></i> Notifications</span>
            </nav>

            <div class="page-header">
                <div>
                    <h1><i class="fas fa-bell"></i> All Notifications</h1>
                    <p><?= count($notifications) ?> total notification<?= count($notifications) != 1 ? 's' : '' ?> 
                    <?php if ($unread_count > 0): ?>
                        (<?= $unread_count ?> unread)
                    <?php endif; ?>
                    </p>
                </div>
                <?php if ($unread_count > 0): ?>
                <div class="header-actions">
                    <button class="btn-mark-all" onclick="markAllAsRead()">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="notifications-container">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <?php 
                        $notif_data = !empty($notif['data']) ? json_decode($notif['data'], true) : [];
                        $has_cover = !empty($notif_data) && !empty($notif_data['cover_image']);
                        $icon_class = $notif['type'];
                        ?>
                        <div class="notification-item <?= $notif['is_read'] ? 'read' : 'unread' ?>" onclick="markAsRead(<?= $notif['id'] ?>)">
                            <?php if ($has_cover): ?>
                                <div class="notification-book-cover">
                                    <img src="../uploads/books/<?= htmlspecialchars($notif_data['cover_image']) ?>" 
                                         alt="Book Cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="notification-icon <?= $icon_class ?>" style="display: none;">
                                        <i class="fas fa-<?= $icon_class === 'new_book' ? 'book' : ($icon_class === 'price_drop' ? 'tag' : ($icon_class === 'low_stock' ? 'exclamation-triangle' : 'bell')) ?>"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="notification-icon <?= $icon_class ?>">
                                    <i class="fas fa-<?= $icon_class === 'new_book' ? 'book' : ($icon_class === 'price_drop' ? 'tag' : ($icon_class === 'low_stock' ? 'exclamation-triangle' : 'bell')) ?>"></i>
                                </div>
                            <?php endif; ?>
                            <div class="notification-content">
                                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i>
                                    <?php
                                    $time_diff = time() - strtotime($notif['created_at']);
                                    if ($time_diff < 60) echo 'Just now';
                                    elseif ($time_diff < 3600) echo floor($time_diff / 60) . ' minutes ago';
                                    elseif ($time_diff < 86400) echo floor($time_diff / 3600) . ' hours ago';
                                    else echo floor($time_diff / 86400) . ' days ago';
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No Notifications Yet</h3>
                        <p>We'll notify you about orders, new books, and special offers</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.2/mdb.min.js"></script>
    <script>
        function markAsRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function markAllAsRead() {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mark_all: true })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
    </script>
</body>
</html>

