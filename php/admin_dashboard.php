<?php
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/includes/account_setup_helper.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

require_account_setup_complete($conn, (int) $_SESSION['super_admin_id']);

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
logPageView('Super Admin Dashboard');

$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if (!$admin_data) {
    session_destroy();
    header('Location: Sign_in.php');
    exit();
}

// Check user role - MUST be super admin
$is_super_admin = ($admin_data['role'] === 'super_admin');
if (!$is_super_admin) {
    header('Location: unified_admin_dashboard.php');
    exit();
}

// Update last_activity
$session_id = session_id();
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];

$update_activity = $conn->prepare("UPDATE infos SET last_activity = NOW(), status = 'active' WHERE id = ?");
$update_activity->bind_param("i", $admin_id);
$update_activity->execute();

require_once __DIR__ . '/activity_logger.php';
ensureUserSessionActivityTable($conn);

$upsert_session = $conn->prepare("
    INSERT INTO user_session_activity (user_id, session_id, ip_address, user_agent, login_time, last_activity, status) 
    VALUES (?, ?, ?, ?, NOW(), NOW(), 'active')
    ON DUPLICATE KEY UPDATE
        user_id = VALUES(user_id),
        ip_address = VALUES(ip_address),
        user_agent = VALUES(user_agent),
        last_activity = NOW(),
        status = 'active'
");
$upsert_session->bind_param("isss", $admin_id, $session_id, $ip_address, $user_agent);
$upsert_session->execute();

// ============ DASHBOARD STATISTICS ============

// Admin statistics
$total_admins = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role IN ('admin', 'super_admin')")->fetch_assoc()['count'] ?? 0;
$total_super_admins = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'super_admin'")->fetch_assoc()['count'] ?? 0;
$total_regular_admins = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'admin'")->fetch_assoc()['count'] ?? 0;
$active_admins = $conn->query("SELECT COUNT(*) as count FROM infos WHERE status = 'active' AND role IN ('admin', 'super_admin')")->fetch_assoc()['count'] ?? 0;

// Customer statistics
$total_customers = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 1")->fetch_assoc()['count'] ?? 0;
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 0")->fetch_assoc()['count'] ?? 0;
$recent_registrations = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['count'] ?? 0;

// Campus lost & found overview
$total_users = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role IN ('customer', 'admin', 'super_admin')")->fetch_assoc()['count'] ?? 0;
$blocked_accounts = $conn->query("SELECT COUNT(*) as count FROM infos WHERE status = 'blocked'")->fetch_assoc()['count'] ?? 0;

$lost_items_total = 0;
$found_items_total = 0;
$pending_claims = 0;
$returned_items = 0;

$lost_table = $conn->query("SHOW TABLES LIKE 'lost_items'");
if ($lost_table && $lost_table->num_rows > 0) {
    $lost_items_total = $conn->query("SELECT COUNT(*) as count FROM lost_items")->fetch_assoc()['count'] ?? 0;
    $returned_items = $conn->query("SELECT COUNT(*) as count FROM lost_items WHERE status = 'recovered' OR status = 'returned'")->fetch_assoc()['count'] ?? 0;
}

$found_table = $conn->query("SHOW TABLES LIKE 'found_items'");
if ($found_table && $found_table->num_rows > 0) {
    $found_items_total = $conn->query("SELECT COUNT(*) as count FROM found_items")->fetch_assoc()['count'] ?? 0;
}

$claims_table = $conn->query("SHOW TABLES LIKE 'claims'");
if ($claims_table && $claims_table->num_rows > 0) {
    $pending_claims = $conn->query("SELECT COUNT(*) as count FROM claims WHERE status IN ('pending', 'under_review')")->fetch_assoc()['count'] ?? 0;
    $returned_items += $conn->query("SELECT COUNT(*) as count FROM claims WHERE status = 'returned'")->fetch_assoc()['count'] ?? 0;
}

// Orders statistics
$total_orders = 0;
$pending_orders = 0;
$completed_orders = 0;
$today_orders = 0;
$yesterday_orders = 0;
$total_revenue = 0;
$today_revenue = 0;
$yesterday_revenue = 0;
$month_revenue = 0;
$avg_order_value = 0;

$orders_check = $conn->query("SHOW TABLES LIKE 'orders'");
if ($orders_check->num_rows > 0) {
    $total_orders = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'] ?? 0;
    $pending_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
    $completed_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('delivered', 'confirmed', 'completed')")->fetch_assoc()['count'] ?? 0;
    $today_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'] ?? 0;
    $yesterday_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetch_assoc()['count'] ?? 0;
    
    // Revenue calculations
    $revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE status IN ('delivered', 'confirmed', 'completed')");
    $total_revenue = $revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    $today_revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE DATE(created_at) = CURDATE() AND status IN ('delivered', 'confirmed', 'completed')");
    $today_revenue = $today_revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    $yesterday_revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status IN ('delivered', 'confirmed', 'completed')");
    $yesterday_revenue = $yesterday_revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    $month_revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status IN ('delivered', 'confirmed', 'completed')");
    $month_revenue = $month_revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    // Average order value
    if ($completed_orders > 0) {
        $avg_order_value = $total_revenue / $completed_orders;
    }
}

// Books statistics
$total_books = 0;
$low_stock_books = 0;
$out_of_stock = 0;
$slow_moving_items = 0;

$books_check = $conn->query("SHOW TABLES LIKE 'books'");
if ($books_check->num_rows > 0) {
    $total_books = $conn->query("SELECT COUNT(*) as count FROM books")->fetch_assoc()['count'] ?? 0;
    $low_stock_books = $conn->query("SELECT COUNT(*) as count FROM books WHERE stock_quantity < 10 AND stock_quantity > 0")->fetch_assoc()['count'] ?? 0;
    $out_of_stock = $conn->query("SELECT COUNT(*) as count FROM books WHERE stock_quantity = 0")->fetch_assoc()['count'] ?? 0;
    
    // Slow moving items (no sales in last 30 days)
    $slow_query = "
        SELECT COUNT(*) as count FROM books b
        WHERE NOT EXISTS (
            SELECT 1 FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE oi.book_id = b.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ) AND b.stock_quantity > 0
    ";
    $slow_moving_items = $conn->query($slow_query)->fetch_assoc()['count'] ?? 0;
}

// Top selling books (last 30 days)
$sales_chart_data = [];
$top_selling_books = [];
if ($books_check->num_rows > 0 && $orders_check->num_rows > 0) {
    $top_books_query = "
        SELECT b.id, b.title, b.author, b.cover_image, b.price,
        COUNT(oi.id) as sales_count,
        SUM(oi.quantity) as total_quantity,
        SUM(oi.quantity * b.price) as total_revenue
        FROM books b
        INNER JOIN order_items oi ON b.id = oi.book_id
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.status IN ('delivered', 'confirmed', 'completed')
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY b.id
        ORDER BY total_quantity DESC
        LIMIT 5
    ";
    $top_books_result = $conn->query($top_books_query);
    while ($row = $top_books_result->fetch_assoc()) {
        $top_selling_books[] = $row;
    }
}

// Top customers
$top_customers = [];
if ($orders_check->num_rows > 0) {
    $top_customers_query = "
        SELECT i.id, 
        COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name,
        i.email,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_spent
        FROM infos i
        INNER JOIN orders o ON i.id = o.user_id
        WHERE i.role = 'customer' AND o.status IN ('delivered', 'confirmed', 'completed')
        GROUP BY i.id
        ORDER BY total_spent DESC
        LIMIT 5
    ";
    $top_customers_result = $conn->query($top_customers_query);
    while ($row = $top_customers_result->fetch_assoc()) {
        $top_customers[] = $row;
    }
}

// Recent orders (last 10)
$recent_orders = [];
if ($orders_check->num_rows > 0) {
    $recent_orders_query = "
        SELECT o.*, 
        COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name
        FROM orders o
        INNER JOIN infos i ON o.user_id = i.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    $recent_orders_result = $conn->query($recent_orders_query);
    while ($row = $recent_orders_result->fetch_assoc()) {
        $recent_orders[] = $row;
    }
}

// Recent admin activities (last 10)
$recent_admin_activities = [];
$admin_activity_check = $conn->query("SHOW TABLES LIKE 'admin_activity_log'");
if ($admin_activity_check && $admin_activity_check->num_rows > 0) {
    $admin_activity_query = "
        SELECT aal.*, i.username, 
        COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as admin_name
        FROM admin_activity_log aal
        INNER JOIN infos i ON aal.user_id = i.id
        ORDER BY aal.created_at DESC
        LIMIT 10
    ";
    $admin_activity_result = $conn->query($admin_activity_query);
    while ($row = $admin_activity_result->fetch_assoc()) {
        $recent_admin_activities[] = $row;
    }
}

// System health metrics
$failed_logins = 0;
if ($admin_activity_check && $admin_activity_check->num_rows > 0) {
    $failed_logins = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE action = 'failed_login' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['count'] ?? 0;
}

$database_size_query = "
    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
";
$database_size = $conn->query($database_size_query)->fetch_assoc()['size_mb'] ?? 0;

// Notification count
$notification_count = 0;
$notification_count += $pending_orders;
$notification_count += $low_stock_books;
$notification_count += $pending_approvals;
$notification_count += ($failed_logins > 10 ? 1 : 0);

// Calculate percentage changes
$revenue_change = 0;
if ($yesterday_revenue > 0) {
    $revenue_change = (($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100;
}

$orders_change = 0;
if ($yesterday_orders > 0) {
    $orders_change = (($today_orders - $yesterday_orders) / $yesterday_orders) * 100;
}

// Handle logout
if (isset($_GET['logout'])) {
    $logout_time = date('Y-m-d H:i:s');
    
    $update_status_stmt = $conn->prepare("UPDATE infos SET status = 'offline', logout_time = ? WHERE id = ?");
    $update_status_stmt->bind_param("si", $logout_time, $admin_id);
    $update_status_stmt->execute();
    
    $update_session_stmt = $conn->prepare("UPDATE user_session_activity SET status = 'inactive', logout_time = ? WHERE user_id = ? AND session_id = ?");
    $update_session_stmt->bind_param("sis", $logout_time, $admin_id, $session_id);
    $update_session_stmt->execute();
    
    $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'super_admin', 'logout', 'Super Admin logged out', ?, ?)");
    $log_stmt->bind_param("iss", $admin_id, $ip_address, $user_agent);
    $log_stmt->execute();
    
    session_destroy();
    header('Location: Sign_in.php');
    exit();
}

// Helper functions
function getStatusColor($status) {
    $colors = [
        'pending' => 'warning',
        'confirmed' => 'info',
        'processing' => 'primary',
        'shipped' => 'info',
        'delivered' => 'success',
        'completed' => 'success',
        'cancelled' => 'danger',
        'refunded' => 'secondary'
    ];
    return $colors[$status] ?? 'secondary';
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $time);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI-Enhanced Campus Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <link rel="stylesheet" href="includes/superadmin_styles.css">
    <link rel="stylesheet" href="../css/book_loading_animation.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>

    <style>
        /* Super Admin Dashboard Styles */
        :root {
            --primary: #0d5c3c;
            --primary-dark: #094631;
            --success: #2a9d5a;
            --success-dark: #1f7a46;
            --warning: #d7b94b;
            --warning-dark: #b89c31;
            --danger: #b23a3a;
            --danger-dark: #8f2d2d;
            --dark: #1f2d25;
            --light: #f5f7f2;
        }
        
        body {
            background: #F8FAFC;
            font-family: 'Inter', sans-serif;
        }
        
        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        .stat-card-indigo::before { background: #0d5c3c; }
        .stat-card-green::before { background: #2a9d5a; }
        .stat-card-orange::before { background: #d7b94b; }
        .stat-card-purple::before { background: #1a6f5d; }
        
        .stat-icon {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
            background: transparent;
        }
        
        .stat-card-indigo .stat-icon {
            background: transparent;
            color: #0d5c3c;
        }
        
        .stat-card-green .stat-icon {
            background: transparent;
            color: #10B981;
        }
        
        .stat-card-orange .stat-icon {
            background: transparent;
            color: #F59E0B;
        }
        
        .stat-card-purple .stat-icon {
            background: transparent;
            color: #8B5CF6;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748B;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .stat-change {
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .stat-change.positive {
            color: #10B981;
        }
        
        .stat-change.negative {
            color: #EF4444;
        }
        
        .stat-change.neutral {
            color: #64748B;
        }
        
        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }
        
        .card-header-custom {
            padding: 20px 24px;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #F8FAFC;
        }
        
        .card-header-custom h5 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
        }
        
        .card-body-custom {
            padding: 24px;
        }
        
        /* Top Books List */
        .top-books-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .book-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
            transition: all 0.2s ease;
        }
        
        .book-item:last-child {
            border-bottom: none;
        }
        
        .book-item:hover {
            background: #F8FAFC;
        }
        
        .book-rank-badge {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: transparent;
            color: #0d5c3c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .book-cover-small {
            width: 48px;
            height: 64px;
            object-fit: cover;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            flex-shrink: 0;
        }
        
        .book-details {
            flex: 1;
            min-width: 0;
        }
        
        .book-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .book-author {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 6px;
        }
        
        .book-stats {
            display: flex;
            gap: 12px;
            font-size: 12px;
        }
        
        .book-sales {
            color: #10B981;
            font-weight: 600;
        }
        
        .book-price {
            color: #0d5c3c;
            font-weight: 600;
        }
        
        /* Activity Feed */
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            gap: 12px;
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: transparent;
        }
        
        .activity-admin {
            background: transparent;
            color: #0d5c3c;
        }
        
        .activity-customer {
            background: transparent;
            color: #10B981;
        }
        
        .activity-order {
            background: transparent;
            color: #F59E0B;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            font-size: 14px;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .activity-time {
            font-size: 12px;
            color: #94A3B8;
        }
        
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .quick-action-btn {
            padding: 16px;
            border-radius: 10px;
            border: none;
            background: transparent;
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .quick-action-btn:hover {
            border: none;
            background: transparent;
            transform: translateY(-2px);
            box-shadow: none;
        }
        
        .quick-action-btn i {
            font-size: 24px;
        }
        
        .quick-action-btn span {
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Admin Stats */
        .admin-stats {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .admin-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #F8FAFC;
            border-radius: 8px;
        }
        
        .admin-stat-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
        }
        
        .admin-stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        /* Health Items */
        .health-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #F1F5F9;
        }
        
        .health-item:last-child {
            border-bottom: none;
        }
        
        .health-label {
            font-size: 13px;
            color: #64748B;
            display: flex;
            align-items: center;
        }
        
        .health-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stat-value {
                font-size: 28px;
            }
        }
        
        @media (max-width: 768px) {
            /* Sidebar - Mobile */
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            /* Main Content - Mobile */
            .main-content {
                margin-left: 0 !important;
                padding: 16px !important;
            }
            
            /* Top Header - Mobile */
            .top-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 16px;
            }
            
            .top-header > div {
                width: 100%;
            }
            
            /* Stat Cards - Mobile */
            .stat-card {
                flex-direction: row;
                text-align: left;
                padding: 16px;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 22px;
            }
            
            .stat-value {
                font-size: 24px;
            }
            
            .stat-label {
                font-size: 13px;
            }
            
            /* Grid Layouts - Mobile */
            .row.g-4 > [class*='col-'] {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 16px;
            }
            
            /* Quick Actions - Mobile */
            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            
            .quick-action-btn {
                padding: 12px 8px;
                font-size: 12px;
            }
            
            /* Tables - Mobile */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table {
                min-width: 600px;
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 10px 8px;
                font-size: 12px;
            }
            
            /* Cards - Mobile */
            .content-card {
                margin-bottom: 16px;
            }
            
            .card-header-custom {
                padding: 16px;
            }
            
            .card-header-custom h5 {
                font-size: 15px;
            }
            
            .card-body-custom {
                padding: 16px;
            }
            
            /* Activity Feed - Mobile */
            .activity-item {
                padding: 12px;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .activity-icon {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            /* Book Items - Mobile */
            .book-item {
                padding: 12px;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .book-cover-small {
                width: 40px;
                height: 54px;
            }
            
            /* User Info - Mobile */
            .user-info {
                padding: 4px 8px;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }
            
            .user-info .text-end .fw-bold {
                font-size: 12px;
            }
            
            .user-info .text-end small {
                font-size: 10px;
            }
            
            /* Notification Dropdown - Mobile */
            .dropdown-menu.notification-dropdown {
                width: calc(100vw - 32px);
                max-width: 360px;
                left: 50% !important;
                right: auto !important;
                transform: translateX(-50%) !important;
            }
            
            /* Charts - Mobile */
            canvas {
                max-height: 250px !important;
            }
        }
        
        /* Extra Small Mobile */
        @media (max-width: 480px) {
            .main-content {
                padding: 12px !important;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 11px;
            }
            
            .table th,
            .table td {
                padding: 8px 6px;
            }
        }
        
        /* User Profile Dropdown */
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px;
            border-radius: 10px;
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .user-info:hover {
            border-color: #CBD5E1;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }
        
        .user-info .dropdown-toggle::after {
            display: none;
        }
        
        .user-info .text-end {
            text-align: right;
            line-height: 1.4;
        }
        
        .user-info .text-end .fw-bold {
            color: #1E293B;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 1px;
            letter-spacing: -0.01em;
        }
        
        .user-info .text-end small {
            font-size: 11px;
            font-weight: 500;
            color: #64748B;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
        }
        
        .user-info .text-end small i {
            font-size: 8px;
        }
        
        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #0d5c3c;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 15px;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid #F8FAFC;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .dropdown-menu {
            border: 1px solid #E2E8F0 !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12) !important;
            background: #FFFFFF !important;
            min-width: 200px;
            padding: 8px !important;
            margin-top: 8px !important;
        }
        
        .dropdown-item {
            padding: 10px 16px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
            transition: all 0.15s ease !important;
            color: #475569 !important;
            background: transparent !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }
        
        .dropdown-item:hover {
            background: #F1F5F9 !important;
            color: #1E293B !important;
        }
        
        .dropdown-item i {
            font-size: 14px;
            width: 18px;
            text-align: center;
            color: #64748B;
        }
        
        .dropdown-item:hover i {
            color: #1E293B;
        }
        
        .dropdown-item.text-danger {
            color: #DC2626 !important;
        }
        
        .dropdown-item.text-danger:hover {
            background: #FEE2E2 !important;
            color: #DC2626 !important;
        }
        
        .dropdown-item.text-danger i {
            color: #DC2626 !important;
        }
        
        .dropdown-item.text-danger:hover i {
            color: #DC2626 !important;
        }
        
        .dropdown-divider {
            margin: 6px 0;
            border-color: #E2E8F0;
            opacity: 1;
        }
        
        /* Notification Dropdown */
        .dropdown-header {
            padding: 12px 16px !important;
            font-weight: 600 !important;
            color: #1E293B !important;
            font-size: 14px !important;
            background: #F8FAFC !important;
            border-radius: 6px !important;
            margin-bottom: 4px !important;
        }
        
        /* ========================================
           NOTIFICATION ICONS - PROFESSIONAL COLORS v2.0
           ======================================== */
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 0 !important;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
            background: transparent !important;
            font-size: 24px;
        }
        
        /* Pending Orders - Amber/Orange for attention */
        .notification-icon.bg-warning,
        .notification-icon.bg-warning i {
            background: transparent !important;
            color: #F59E0B !important;
        }
        
        /* Low Stock Alert & Security - Red for urgency */
        .notification-icon.bg-danger,
        .notification-icon.bg-danger i {
            background: transparent !important;
            color: #DC2626 !important;
        }
        
        /* Pending Approvals - Blue for information */
        .notification-icon.bg-info,
        .notification-icon.bg-info i {
            background: transparent !important;
            color: #3B82F6 !important;
        }
        
        /* New Registrations - Green for positive action */
        .notification-icon.bg-success,
        .notification-icon.bg-success i {
            background: transparent !important;
            color: #10B981 !important;
        }
        
        /* Security Alerts - Dark Red for critical */
        .notification-icon.bg-security,
        .notification-icon.bg-security i {
            background: transparent !important;
            color: #991B1B !important;
        }
        }
        
        .notification-title {
            font-weight: 600 !important;
            font-size: 13px !important;
            color: #1E293B !important;
            margin-bottom: 2px !important;
        }
        
        .notification-text {
            font-size: 12px !important;
            color: #64748B !important;
        }
        
        .dropdown-item .d-flex {
            gap: 0;
        }
        
        .dropdown-item.text-center {
            justify-content: center !important;
        }
        
        .dropdown-item.text-primary {
            color: #0d5c3c !important;
        }
        
        .dropdown-item.text-primary:hover {
            background: #e7f5eb !important;
            color: #094631 !important;
        }
        
        /* Global Search Styles */
        .global-search {
            position: relative;
            width: 350px;
        }
        
        .search-input {
            width: 100%;
            padding: 8px 16px 8px 40px;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #0d5c3c;
            box-shadow: 0 0 0 3px rgba(13, 92, 60, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 14px;
        }
        
        .search-results {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            max-height: 400px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
        }
        
        .search-results.show {
            display: block;
        }
        
        .search-category {
            padding: 8px 16px;
            font-size: 11px;
            font-weight: 600;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
        }
        
        .search-item {
            padding: 12px 16px;
            border-bottom: 1px solid #F1F5F9;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        
        .search-item:last-child {
            border-bottom: none;
        }
        
        .search-item:hover {
            background: #F8FAFC;
        }
        
        .search-item-title {
            font-size: 13px;
            font-weight: 600;
            color: #1f2d25;
            margin-bottom: 2px;
        }
        
        .search-item-meta {
            font-size: 11px;
            color: #64748B;
        }
        
        .search-no-results {
            padding: 32px 16px;
            text-align: center;
            color: #94A3B8;
        }
        
        .search-no-results i {
            font-size: 32px;
            margin-bottom: 8px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .global-search {
                width: 100%;
            }
        }
        
        /* Notification Button Styling */
        .notification-btn {
            background: transparent;
            border: none;
            color: #F59E0B; /* Orange color for notification bell */
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .notification-btn:hover {
            background: #F8FAFC;
            color: #D97706; /* Darker orange on hover */
            transform: translateY(-1px);
        }
        
        .notification-btn:active {
            transform: translateY(0);
        }
        
        /* Notification bell icon specific color */
        .notification-btn i.fa-bell {
            color: #F59E0B !important;
        }
        
        .notification-btn:hover i.fa-bell {
            color: #D97706 !important;
        }
        
        /* Section Header Icon Colors */
        .card-header-custom h5 i.fa-chart-line {
            color: #4F46E5 !important; /* Indigo for Sales Overview */
        }
        
        .card-header-custom h5 i.fa-fire {
            color: #F59E0B !important; /* Orange for Top Selling Books */
        }
        
        .card-header-custom h5 i.fa-receipt {
            color: #10B981 !important; /* Green for Recent Orders */
        }
        
        .card-header-custom h5 i.fa-history {
            color: #3B82F6 !important; /* Blue for Admin Activity */
        }
        
        .card-header-custom h5 i.fa-users-cog {
            color: #8B5CF6 !important; /* Purple for Admin Management */
        }
        
        .card-header-custom h5 i.fa-heartbeat {
            color: #EF4444 !important; /* Red for System Health */
        }
        
        .card-header-custom h5 i.fa-bolt {
            color: #F59E0B !important; /* Orange for Quick Actions */
        }
        
        /* System Health Icons */
        .health-label i.fa-database {
            color: #3B82F6; /* Blue for Database */
        }
        
        .health-label i.fa-exclamation-triangle {
            color: #F59E0B; /* Orange for Failed Logins */
        }
        
        .health-label i.fa-box {
            color: #F59E0B; /* Orange for Low Stock */
        }
        
        .health-label i.fa-hourglass-half {
            color: #64748B; /* Gray for Slow Moving */
        }
        
        .health-label i.fa-user-clock {
            color: #8B5CF6; /* Purple for Pending Approvals */
        }
        
        /* Quick Actions Icons */
        .quick-action-btn i.fa-user-plus {
            color: #4F46E5 !important; /* Indigo for Add Admin */
        }
        
        .quick-action-btn i.fa-users {
            color: #10B981 !important; /* Green for Customers */
        }
        
        .quick-action-btn i.fa-book {
            color: #3B82F6 !important; /* Blue for Manage Books */
        }
        
        .quick-action-btn i.fa-shopping-cart {
            color: #F59E0B !important; /* Orange for Orders */
        }
        
        .quick-action-btn i.fa-clipboard-list {
            color: #8B5CF6 !important; /* Purple for Activity Logs */
        }
        
        .quick-action-btn i.fa-chart-bar {
            color: #EF4444 !important; /* Red for Reports */
        }
        
        /* Remove ALL backgrounds and borders from badges - just colored text */
        .badge {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #1E293B !important;
            font-weight: 600 !important;
            padding: 6px 12px !important;
        }
        
        /* Status-specific text colors only (NO backgrounds, NO borders) */
        .badge.bg-success {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #10B981 !important;
        }
        
        .badge.bg-warning {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #F59E0B !important;
        }
        
        .badge.bg-danger {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #EF4444 !important;
        }
        
        .badge.bg-info {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #3B82F6 !important;
        }
        
        .badge.bg-primary {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #4F46E5 !important;
        }
        
        .badge.bg-secondary {
            background: none !important;
            background-color: transparent !important;
            border: none !important;
            color: #64748B !important;
        }
        
        /* Keep super admin badge with gradient background (exception) */
        .badge[style*="gradient"] {
            background: #0d5c3c !important;
            border: none !important;
            color: white !important;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0">
                        AI-Enhanced Campus Lost & Found Management System
                    </h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin_data['full_name'] ?? $admin_data['username']); ?>!</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Global Search -->
                <div class="global-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="globalSearch" placeholder="Search users, items, claims, logs..." autocomplete="off">
                    <div class="search-results" id="searchResults"></div>
                </div>
                
                <!-- Notifications Dropdown -->
                <!-- Notifications -->
                <?php if ($notification_count > 0): ?>
                <div class="dropdown">
                    <button class="btn btn-sm position-relative notification-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell" style="font-size: 18px;"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill d-flex align-items-center justify-content-center" style="background: #FF5252 !important; color: white !important; font-size: 10px; padding: 0 !important; width: 18px !important; max-width: 18px !important; height: 16px !important;">
                            <?php echo $notification_count; ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 320px;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-bell me-2"></i>Notifications</span>
                            <span class="badge bg-danger"><?php echo $notification_count; ?></span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        
                        <?php if ($pending_orders > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_manage_orders.php?status=pending">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-warning">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Pending Orders</div>
                                        <div class="notification-text"><?php echo $pending_orders; ?> orders awaiting processing</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($low_stock_books > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_manage_books.php?filter=low_stock">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-danger">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Low Stock Alert</div>
                                        <div class="notification-text"><?php echo $low_stock_books; ?> books running low</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($pending_approvals > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_manage_customers.php?filter=pending">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-info">
                                        <i class="fas fa-user-clock"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Pending Approvals</div>
                                        <div class="notification-text"><?php echo $pending_approvals; ?> customers awaiting approval</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($failed_logins > 10): ?>
                        <li>
                            <a class="dropdown-item" href="admin_logs.php?filter=failed_login">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-danger">
                                        <i class="fas fa-shield-alt"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Security Alert</div>
                                        <div class="notification-text"><?php echo $failed_logins; ?> failed login attempts (24h)</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center text-primary" href="admin_logs.php">
                                <small><i class="fas fa-eye me-1"></i>View All Activity</small>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <div class="user-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="text-end">
                            <div class="fw-bold"><?php echo htmlspecialchars($admin_data['full_name'] ?? $admin_data['username']); ?></div>
                        </div>
                        <div class="user-avatar">
                            <?php if (!empty($admin_data['avatar'])): ?>
                                <img src="../uploads/avatars/<?php echo htmlspecialchars($admin_data['avatar']); ?>" 
                                     alt="Avatar" 
                                     onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo strtoupper(substr($admin_data['username'], 0, 1)); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($admin_data['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg">
                        <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                        <li><a class="dropdown-item" href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="Sign_out.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="container-fluid">
            <!-- Statistics Cards Row -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-indigo">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($total_users); ?></div>
                            <div class="stat-label">Total Users</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-user-check"></i>
                                <?php echo number_format($total_customers); ?> active users
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-green">
                        <div class="stat-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($total_admins); ?></div>
                            <div class="stat-label">Administrators</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-shield-alt"></i>
                                <?php echo number_format($total_super_admins); ?> superadmins
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-orange">
                        <div class="stat-icon">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($pending_approvals); ?></div>
                            <div class="stat-label">Pending Registrations</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-clipboard-list"></i>
                                Awaiting review
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-purple">
                        <div class="stat-icon">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($blocked_accounts); ?></div>
                            <div class="stat-label">Blocked Accounts</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-ban"></i>
                                Access restricted
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-indigo">
                        <div class="stat-icon">
                            <i class="fas fa-search-location"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($lost_items_total); ?></div>
                            <div class="stat-label">Lost Items</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-flag"></i>
                                Active reports
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-green">
                        <div class="stat-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($found_items_total); ?></div>
                            <div class="stat-label">Found Items</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-clipboard-check"></i>
                                Ready for review
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-orange">
                        <div class="stat-icon">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($pending_claims); ?></div>
                            <div class="stat-label">Pending Claims</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-hourglass-half"></i>
                                Awaiting review
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-card-purple">
                        <div class="stat-icon">
                            <i class="fas fa-undo-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($returned_items); ?></div>
                            <div class="stat-label">Returned Items</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-check-circle"></i>
                                Successfully restored
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders & Admin Activity Row -->
            <div class="row g-4 mb-4 align-items-stretch">
                <!-- Recent Orders -->
                <div class="col-xl-8">
                    <div class="content-card h-100">
                        <div class="card-header-custom">
                            <h5><i class="fas fa-box-open me-2" style="color: #10B981 !important;"></i>Recent Lost & Found Cases</h5>
                            <a href="admin_manage_orders.php" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="card-body-custom p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Customer</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_orders)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">No orders yet</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td><strong>#<?php echo $order['id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                            <td><span class="badge bg-<?php echo getStatusColor($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Admin Activity Feed -->
                <div class="col-xl-4">
                    <div class="content-card h-100">
                        <div class="card-header-custom">
                            <h5><i class="fas fa-history me-2" style="color: #8B5CF6 !important;"></i>Admin Activity</h5>
                        </div>
                        <div class="card-body-custom p-0">
                            <div class="activity-feed">
                                <?php if (empty($recent_admin_activities)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <p>No recent activity</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($recent_admin_activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-admin">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text">
                                            <strong><?php echo htmlspecialchars($activity['admin_name']); ?></strong>
                                            <?php echo htmlspecialchars($activity['description']); ?>
                                        </div>
                                        <div class="activity-time"><?php echo timeAgo($activity['created_at']); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Super Admin Specific Row -->
            <div class="row g-4 mb-4 align-items-stretch">
                <!-- Admin Management -->
                <div class="col-xl-5">
                    <div class="content-card h-100">
                        <div class="card-header-custom">
                            <h5><i class="fas fa-users-cog me-2" style="color: #0d5c3c !important;"></i>Admin Management</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="admin-stats">
                                <div class="admin-stat-item">
                                    <span class="admin-stat-label">Total Admins</span>
                                    <span class="admin-stat-value"><?php echo $total_admins; ?></span>
                                </div>
                                <div class="admin-stat-item">
                                    <span class="admin-stat-label">Super Admins</span>
                                    <span class="admin-stat-value"><?php echo $total_super_admins; ?></span>
                                </div>
                                <div class="admin-stat-item">
                                    <span class="admin-stat-label">Regular Admins</span>
                                    <span class="admin-stat-value"><?php echo $total_regular_admins; ?></span>
                                </div>
                                <div class="admin-stat-item">
                                    <span class="admin-stat-label">Active Now</span>
                                    <span class="admin-stat-value"><?php echo $active_admins; ?></span>
                                </div>
                            </div>
                            <a href="admin_manage.php" class="btn btn-primary w-100 mt-3">
                                <i class="fas fa-user-plus me-2"></i>Manage Admins
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- System Health -->
                <div class="col-xl-7">
                    <div class="content-card h-100">
                        <div class="card-header-custom">
                            <h5><i class="fas fa-heartbeat me-2" style="color: #10B981 !important;"></i>System Health</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="health-item">
                                <span class="health-label">
                                    <i class="fas fa-database me-2"></i>Database Size
                                </span>
                                <span class="health-value"><?php echo number_format($database_size, 2); ?> MB</span>
                            </div>
                            <div class="health-item">
                                <span class="health-label">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Failed Logins (24h)
                                </span>
                                <span class="health-value <?php echo $failed_logins > 10 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $failed_logins; ?>
                                </span>
                            </div>
                            <div class="health-item">
                                <span class="health-label">
                                    <i class="fas fa-box me-2"></i>Low Stock Items
                                </span>
                                <span class="health-value <?php echo $low_stock_books > 0 ? 'text-warning' : 'text-success'; ?>">
                                    <?php echo $low_stock_books; ?>
                                </span>
                            </div>
                            <div class="health-item">
                                <span class="health-label">
                                    <i class="fas fa-hourglass-half me-2"></i>Slow Moving Items
                                </span>
                                <span class="health-value"><?php echo $slow_moving_items; ?></span>
                            </div>
                            <div class="health-item">
                                <span class="health-label">
                                    <i class="fas fa-user-clock me-2"></i>Pending Approvals
                                </span>
                                <span class="health-value <?php echo $pending_approvals > 0 ? 'text-warning' : 'text-success'; ?>">
                                    <?php echo $pending_approvals; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Chart.js Implementation -->
    <script>
        // Sales Chart
        const salesCtx = document.getElementById('salesChart');
        if (salesCtx) {
            const salesData = <?php echo json_encode($sales_chart_data); ?>;
            
            new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: salesData.map(d => {
                        const date = new Date(d.date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Revenue',
                        data: salesData.map(d => parseFloat(d.revenue)),
                        borderColor: '#4F46E5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#4F46E5',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#0d5c3c',
                            borderWidth: 1,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return 'Revenue: ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                },
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: '#F1F5F9',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
        
        // Sidebar Toggle Function
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (!sidebar || !mainContent) return;
            
            // Check if mobile view
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                // Mobile: Toggle sidebar visibility with overlay
                sidebar.classList.toggle('show');
                if (overlay) {
                    overlay.classList.toggle('show');
                }
            } else {
                // Desktop: Toggle collapsed state
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            }
            
            // Close all dropdowns when collapsing/hiding sidebar
            if (sidebar.classList.contains('collapsed') || !sidebar.classList.contains('show')) {
                document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('active');
                });
            }
        }
        
        // Setup mobile sidebar handlers
        window.addEventListener('load', function() {
            const overlay = document.getElementById('sidebarOverlay');
            const sidebar = document.querySelector('.sidebar');
            
            // Close sidebar when clicking overlay (mobile)
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                });
            }
            
            // Close sidebar when clicking menu item on mobile
            const menuItems = document.querySelectorAll('.menu-item, .dropdown-item');
            menuItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('show');
                        if (overlay) {
                            overlay.classList.remove('show');
                        }
                    }
                });
            });
            
            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    const sidebar = document.querySelector('.sidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    const mainContent = document.getElementById('mainContent');
                    
                    if (window.innerWidth > 768) {
                        // Desktop: Remove mobile classes
                        if (sidebar) sidebar.classList.remove('show');
                        if (overlay) overlay.classList.remove('show');
                    } else {
                        // Mobile: Remove desktop collapsed state
                        if (sidebar) sidebar.classList.remove('collapsed');
                        if (mainContent) mainContent.classList.remove('expanded');
                    }
                }, 250);
            });
        });
        
        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Add ripple effect to buttons
        document.querySelectorAll('.btn, .quick-action-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.classList.add('ripple');
                this.appendChild(ripple);
                
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                
                setTimeout(() => ripple.remove(), 600);
            });
        });
        
        // Refresh data every 30 seconds
        setInterval(function() {
            // You can add AJAX calls here to refresh statistics without page reload
            console.log('Auto-refresh triggered');
        }, 30000);
        
        // Global Search Functionality
        const searchInput = document.getElementById('globalSearch');
        const searchResults = document.getElementById('searchResults');
        let searchTimeout;
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    searchResults.classList.remove('show');
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    performSearch(query);
                }, 300);
            });
            
            searchInput.addEventListener('focus', function() {
                if (this.value.trim().length >= 2) {
                    searchResults.classList.add('show');
                }
            });
            
            // Close search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.remove('show');
                }
            });
        }
        
        function performSearch(query) {
            fetch(`global_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    displaySearchResults(data);
                })
                .catch(error => {
                    console.error('Search error:', error);
                });
        }
        
        function displaySearchResults(data) {
            renderAdminSearchResults(searchResults, data);
        }
    </script>
    
    <!-- Book Loading Animation System -->
    <script src="../js/book_loading_animation.js"></script>

<script src="../js/admin_search_results.js"></script>
</body>
</html>
