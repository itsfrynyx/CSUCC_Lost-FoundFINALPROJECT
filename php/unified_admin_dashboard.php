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

// Log page view
logPageView('Admin Dashboard');
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

ensureUserSessionActivityTable($conn);

// Check user role
$is_super_admin = $admin_data['role'] === 'super_admin';
if (($admin_data['role'] ?? '') !== 'admin') {
    header('Location: ' . ($is_super_admin ? 'admin_dashboard.php' : 'Sign_in.php'));
    exit();
}

// Get dashboard statistics
$total_admins = $conn->query("SELECT COUNT(*) as count FROM infos")->fetch_assoc()['count'];
$active_admins = $conn->query("SELECT COUNT(*) as count FROM infos WHERE status = 'active'")->fetch_assoc()['count'];

// Orders statistics
$total_orders = 0;
$pending_orders = 0;
$completed_orders = 0;
$today_orders = 0;
$total_revenue = 0;
$today_revenue = 0;
$avg_order_value = 0;

$orders_check = $conn->query("SHOW TABLES LIKE 'orders'");
if ($orders_check->num_rows > 0) {
    $total_orders = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'] ?? 0;
    $pending_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
    $completed_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('delivered', 'confirmed', 'completed')")->fetch_assoc()['count'] ?? 0;
    $today_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'] ?? 0;
    
    // Revenue calculations
    $revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE status IN ('delivered', 'confirmed', 'completed')");
    $total_revenue = $revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    $today_revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE DATE(created_at) = CURDATE() AND status IN ('delivered', 'confirmed', 'completed')");
    $today_revenue = $today_revenue_result->fetch_assoc()['revenue'] ?? 0;
    
    // Average order value
    if ($completed_orders > 0) {
        $avg_order_value = $total_revenue / $completed_orders;
    }
}

// Lost Items statistics (replacing books statistics)
$total_lost_items = 0;
$pending_claims = 0;
$resolved_claims = 0;

$lost_items_check = $conn->query("SHOW TABLES LIKE 'lost_items'");
if ($lost_items_check->num_rows > 0) {
    $total_lost_items = $conn->query("SELECT COUNT(*) as count FROM lost_items")->fetch_assoc()['count'] ?? 0;
    $pending_claims = $conn->query("SELECT COUNT(*) as count FROM lost_items WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
    $resolved_claims = $conn->query("SELECT COUNT(*) as count FROM lost_items WHERE status = 'resolved'")->fetch_assoc()['count'] ?? 0;
}

// Customers statistics
$total_customers = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 1")->fetch_assoc()['count'] ?? 0;

// Pending approvals (available to all admins)
$pending_approvals = 0;
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 0")->fetch_assoc()['count'] ?? 0;

// ============ NEW ANALYTICS DATA ============

// Yesterday's data for comparison
$yesterday_orders = 0;
$yesterday_revenue = 0;
if ($orders_check->num_rows > 0) {
    $yesterday_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetch_assoc()['count'] ?? 0;
    $yesterday_revenue_result = $conn->query("SELECT SUM(total_amount) as revenue FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status IN ('delivered', 'confirmed', 'completed')");
    $yesterday_revenue = $yesterday_revenue_result->fetch_assoc()['revenue'] ?? 0;
}

// Sales chart data (last 7 days)
$sales_chart_data = [];
if ($orders_check->num_rows > 0) {
    $chart_query = "
        SELECT DATE(created_at) as date, 
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as revenue
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    $chart_result = $conn->query($chart_query);
    while ($row = $chart_result->fetch_assoc()) {
        $sales_chart_data[] = $row;
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

// Popular categories - disabled for lost & found system
$popular_categories = [];
/*
if ($books_check->num_rows > 0 && $orders_check->num_rows > 0) {
    $categories_query = "
        SELECT b.genre,
        COUNT(DISTINCT b.id) as book_count,
        COALESCE(SUM(b.stock_quantity), 0) as total_stock,
        COALESCE(SUM(oi.quantity), 0) as total_sold,
        COALESCE(SUM(oi.quantity * b.price), 0) as revenue
        FROM books b
        LEFT JOIN order_items oi ON b.id = oi.book_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('delivered', 'confirmed', 'completed')
        WHERE b.genre IS NOT NULL AND b.genre != ''
        GROUP BY b.genre
        ORDER BY revenue DESC
        LIMIT 6
    ";
    $categories_result = $conn->query($categories_query);
    while ($row = $categories_result->fetch_assoc()) {
        $popular_categories[] = $row;
    }
}
*/

// Recent activity feed
$recent_activities = [];
$activity_query = "
    SELECT 'claimant' as type, CONCAT(firstname, ' ', lastname) as name, created_at as activity_time, 'registered' as action
    FROM infos WHERE role = 'customer' ORDER BY created_at DESC LIMIT 5
";
$activity_result = $conn->query($activity_query);
while ($row = $activity_result->fetch_assoc()) {
    $recent_activities[] = $row;
}

// Notifications count
$notification_count = 0;
$notification_count += $pending_orders; // Pending orders
$notification_count += $pending_claims; // Pending claims
$notification_count += $pending_approvals; // Pending approvals
if ($orders_check->num_rows > 0) {
    $new_orders_today = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE() AND status = 'pending'")->fetch_assoc()['count'] ?? 0;
    $notification_count += $new_orders_today;
}

// Recent registrations (last 24 hours)
$recent_registrations = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['count'] ?? 0;

// Conversion metrics
$cart_abandonment_rate = 0;
$browse_to_purchase = 0;
// Note: These would need cart tracking tables for accurate data

// Inventory health - disabled for lost & found system
$slow_moving_items = 0;
/*
if ($books_check->num_rows > 0) {
    // Books with no sales in last 30 days
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
*/

// Handle logout
if (isset($_GET['logout'])) {
    // Get IP and user agent
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $logout_time = date('Y-m-d H:i:s');
    
    // Determine user type based on role
    $user_type = $is_super_admin ? 'super_admin' : 'admin';
    $logout_description = $is_super_admin ? 'Super Admin logged out' : 'Admin logged out';
    
    // Update user status to offline and set logout_time
    $update_status_stmt = $conn->prepare("UPDATE infos SET status = 'offline', logout_time = ? WHERE id = ?");
    $update_status_stmt->bind_param("si", $logout_time, $admin_id);
    $update_status_stmt->execute();
    
    // Update session activity status
    $session_id = session_id();
    $update_session_stmt = $conn->prepare("UPDATE user_session_activity SET status = 'inactive', logout_time = ? WHERE user_id = ? AND session_id = ?");
    $update_session_stmt->bind_param("sis", $logout_time, $admin_id, $session_id);
    $update_session_stmt->execute();
    
    // Log logout activity
    $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, ?, 'logout', ?, ?, ?)");
    $log_stmt->bind_param("issss", $admin_id, $user_type, $logout_description, $ip_address, $user_agent);
    $log_stmt->execute();
    
    // Destroy session
    session_destroy();
    header('Location: Sign_in.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?> Dashboard - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    <link href="../Bootstrap/bootstrap-5.3.3-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/book_loading_animation.css">
    <link rel="stylesheet" href="../css/bookshelf_loader.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        :root {
            /* Caraga State University Brand Palette */
            --sidebar-dark: #0d5c3c;
            --sidebar-darker: #094631;
            --sidebar-hover: #166d4d;
            --active-menu: #d7b94b;
            --primary-button: #0d5c3c;
            --button-hover: #0a4732;
            
            /* Content Colors */
            --content-bg: #f5f6fa;
            --card-bg: #ffffff;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --text-muted: #a0aec0;
            --border-color: #e2e8f0;
            --hover-bg: #f7fafc;
            
            /* KPI Semantic Colors */
            --stat-blue: #3B82F6;
            --stat-blue-light: #DBEAFE;
            --stat-green: #16A34A;
            --stat-green-light: #DCFCE7;
            --stat-orange: #F59E0B;
            --stat-orange-light: #FEF3C7;
            --stat-red: #DC2626;
            --stat-red-light: #FEE2E2;
            
            /* Legacy accent colors for compatibility */
            --accent-blue: #3B82F6;
            --accent-green: #16A34A;
            --accent-yellow: #F59E0B;
            --accent-red: #DC2626;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--content-bg);
            color: var(--text-primary);
            font-weight: 400;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            box-sizing: border-box;
            width: 100%;
            height: 100%;
            min-height: 100vh;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html {
            overflow-x: hidden;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: var(--sidebar-dark);
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
            overflow-y: auto;
        }
        
        .sidebar.collapsed {
            width: 70px;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: var(--sidebar-darker);
            position: relative;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .sidebar-logo {
            width: 36px;
            height: 36px;
            border-radius: 8px;
        }
        
        .brand-info h3 {
            color: #ffffff;
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -0.3px;
        }
        
        .brand-subtitle {
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            margin: 0;
            font-weight: 400;
        }
        
        .brand-info {
            text-align: left;
        }
        
        .brand-info h3 {
            color: white;
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            line-height: 1.2;
        }
        
        .brand-subtitle {
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            font-size: 11px;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            letter-spacing: 0.5px;
        }
        
        .sidebar-header .role-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 10px;
        }
        
        .sidebar-menu {
            padding: 16px 0;
        }
        
        .menu-section-title {
            padding: 20px 20px 8px 20px;
            font-size: 11px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.4);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .menu-section-title:first-child {
            padding-top: 8px;
        }
        
        .sidebar.collapsed .menu-section-title {
            display: none;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 11px 20px;
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 2px solid transparent;
            font-size: 14px;
            font-weight: 400;
            position: relative;
        }
        
        .menu-item:hover {
            background: var(--sidebar-hover);
            color: rgba(255, 255, 255, 0.95);
            border-left-color: rgba(255, 255, 255, 0.3);
        }
        
        .menu-item.active {
            background: var(--active-menu);
            color: #ffffff;
            border-left-color: #ffffff;
            font-weight: 500;
        }
        
        .menu-item i {
            width: 18px;
            margin-right: 12px;
            font-size: 15px;
            opacity: 0.8;
        }
        
        .menu-item.active i {
            opacity: 1;
        }
        
        .menu-badge {
            margin-left: auto;
            background: var(--accent-red);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 7px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }
        
        .toggle-sidebar-btn {
            position: absolute;
            top: 50%;
            right: -15px;
            width: 30px;
            height: 30px;
            background: #ffffff;
            border: 2px solid var(--sidebar-dark);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1001;
            transform: translateY(-50%);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .toggle-sidebar-btn i {
            color: var(--sidebar-dark);
            font-size: 14px;
            font-weight: 700;
            transition: transform 0.3s ease;
        }
        
        .toggle-sidebar-btn:hover {
            background: #f8f9fa;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transform: translateY(-50%) scale(1.1);
        }
        
        .toggle-sidebar-btn:hover i {
            color: var(--sidebar-dark);
        }
        
        .sidebar.collapsed .toggle-sidebar-btn i {
            transform: rotate(180deg);
        }
        
        .menu-item-logout {
            margin-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 19px;
        }
        
        .sidebar.collapsed .menu-item span {
            display: none;
        }
        
        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 14px 5px;
        }
        
        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }
        
        .sidebar.collapsed .menu-badge {
            display: none;
        }
        
        .sidebar.collapsed .menu-item-logout {
            border-top: none;
            padding-top: 14px;
            margin-top: 0;
        }
        
        .sidebar.collapsed .brand-info {
            display: none;
        }
        
        .sidebar.collapsed .logo-section {
            justify-content: center;
        }
        
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(102, 126, 234, 0.3);
            transform: translate(-50%, -50%) scale(0);
            animation: ripple-animation 0.6s ease-out;
            pointer-events: none;
        }
        
        @keyframes ripple-animation {
            to {
                transform: translate(-50%, -50%) scale(4);
                opacity: 0;
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100%);
            }
        }
        
        .main-content {
            position: relative;
            margin-left: 280px;
            padding: 16px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            background: var(--content-bg);
            min-height: 100vh;
            width: calc(100% - 280px);
            max-width: calc(100% - 280px);
            overflow-x: hidden;
            overflow-y: auto;
            box-sizing: border-box;
        }
        
        .main-content.expanded {
            margin-left: 70px;
            width: calc(100% - 70px);
            max-width: calc(100% - 70px);
        }
        
        /* Container for all content */
        .main-content > * {
            max-width: 100%;
        }
        
        /* Smooth transition for all cards when sidebar toggles */
        .main-content .content-card,
        .main-content .metric-card {
            transition: all 0.3s ease;
            width: 100%;
            max-width: 100%;
        }
        
        .main-content .row {
            transition: all 0.3s ease;
        }
        
        .top-header {
            background: var(--card-bg);
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 100;
            box-sizing: border-box;
            width: 100%;
        }
        
        .top-header h4 {
            font-size: 24px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
            letter-spacing: -0.4px;
        }
        
        .top-header small {
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        .top-header .navbar-brand {
            color: var(--text-primary);
            font-weight: 600;
        }
        
        .top-header .navbar-brand:hover {
            color: var(--text-secondary);
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            min-height: 130px;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 16px;
            color: white;
            background: var(--text-primary);
        }
        
        .stat-card.info .stat-icon { background: var(--accent-blue); }
        .stat-card.success .stat-icon { background: var(--accent-green); }
        .stat-card.warning .stat-icon { background: var(--accent-yellow); }
        .stat-card.danger .stat-icon { background: var(--accent-red); }
        .stat-card.primary .stat-icon { background: var(--text-primary); }
        .stat-card.secondary .stat-icon { background: var(--text-secondary); }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
            line-height: 1;
            letter-spacing: -0.5px;
        }
            line-height: 1;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
        }
        
        .activity-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.2s ease;
        }
        
        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item:hover {
            background: #f8f9fa;
            margin: 0 -25px;
            padding: 15px 25px;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-right: 15px;
        }
        
        .toggle-sidebar {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toggle-sidebar:hover {
            background: var(--hover-bg);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }
        
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
        
        .user-info .text-end small i.fa-circle {
            color: #10B981 !important;
        }
        
        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
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
        
        .quick-action-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #2c3e50;
            display: block;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }
        
        .quick-action-btn i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        
        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100%);
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #1E293B;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            box-shadow: none;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
                padding: 12px;
            }
            
            .main-content.expanded {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }
            
            .top-header {
                flex-direction: column;
                gap: 12px;
                padding: 12px;
            }
            
            .global-search {
                width: 100%;
            }
            
            .main-content .row {
                margin-left: -6px;
                margin-right: -6px;
                width: calc(100% + 12px);
            }
            
            .main-content .row > * {
                padding-left: 6px;
                padding-right: 6px;
            }
            
            /* Stack columns on mobile */
            .col-xl-8, .col-xl-6, .col-xl-4, .col-md-6 {
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Reduce metric card size on mobile */
            .metric-card {
                padding: 12px;
            }
            
            .metric-icon {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
            
            /* Reduce chart height on mobile */
            .card-body-custom > div {
                height: 200px !important;
            }
        }
        
        /* Tablet breakpoint */
        @media (min-width: 769px) and (max-width: 1024px) {
            .main-content {
                padding: 14px;
            }
            
            .metric-card {
                padding: 14px;
            }
            
            .card-body-custom {
                padding: 14px;
            }
        }
        
        /* Large desktop breakpoint */
        @media (min-width: 1400px) {
            .main-content {
                padding: 20px;
            }
            
            .main-content .row {
                margin-left: -10px;
                margin-right: -10px;
                width: calc(100% + 20px);
            }
            
            .main-content .row > * {
                padding-left: 10px;
                padding-right: 10px;
            }
        }
        
        /* Extra large screens */
        @media (min-width: 1920px) {
            .main-content {
                padding: 24px;
            }
            
            .card-body-custom > div {
                height: 300px !important;
            }
        }
        
        .fa-crown:before {
            content: "\f521";
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            border-color: #0d5c3c;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #0d5c3c;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
            color: white;
        }
        
        .stat-card.primary .stat-icon { background: #0d5c3c; }
        .stat-card.success .stat-icon { background: #2a9d5a; }
        .stat-card.info .stat-icon { background: #1a6f5d; }
        .stat-card.warning .stat-icon { background: #f59e0b; }
        .stat-card.danger .stat-icon { background: #ef4444; }
        .stat-card.secondary .stat-icon { background: #6b7280; }
        
        .stat-number {
            font-size: 32px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 4px;
            line-height: 1;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        
x 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .mini-stat i {
            font-size: 18px;
        }
        
        /* Enhanced Stat Cards with Gradients */
        .stat-card {
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(136, 43, 42, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .stat-card:hover::after {
            transform: translate(30%, -30%) scale(1.5);
            transition: transform 0.5s ease;
        }
        
        .stat-number {
            position: relative;
            z-index: 2;
            animation: countUp 1s ease-out;
        }
        
        @keyframes countUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Quick Actions Bar */
        .quick-actions-bar {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        
        .quick-action-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f5f6fa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .quick-action-item:hover {
            background: #0d5c3c;
            color: white;
            border-color: #0d5c3c;
            transform: translateY(-2px);
        }
        
        .quick-action-item i {
            font-size: 16px;
        }
        
        /* Progress Bar Styles */
        .progress-bar-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0d5c3c, #d7b94b);
            border-radius: 10px;
            transition: width 1s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        /* Activity Timeline */
        .activity-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(180deg, #0d5c3c, #d7b94b);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -26px;
            top: 5px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: white;
            border: 3px solid #0d5c3c;
            box-shadow: 0 0 0 4px rgba(13, 92, 60, 0.08);
            z-index: 2;
        }
        
        .timeline-item:hover .timeline-dot {
            transform: scale(1.3);
            transition: transform 0.3s ease;
        }
        
        /* Enhanced Card Hover Effects */
        .card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12) !important;
        }
        
        /* Pulse Animation for Important Items */
        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(136, 43, 42, 0.4);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(136, 43, 42, 0);
            }
        }
        
        .pulse-badge {
            animation: pulse 2s infinite;
        }
        
        /* Skeleton Loading Animation */
        @keyframes skeleton-loading {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }
        
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: skeleton-loading 1.5s ease-in-out infinite;
        }
        
        /* Tooltip Styles */
        .tooltip-custom {
            position: relative;
            cursor: help;
        }
        
        .tooltip-custom:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 12px;
            background: #2c3e50;
            color: white;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
        }
        
        /* Badge Animations */
        .badge {
            transition: all 0.3s ease;
        }
        
        .badge:hover {
            transform: scale(1.1);
        }
        
        /* Improved Table Styles */
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(136, 43, 42, 0.05), transparent);
            transform: translateX(5px);
        }
        
        /* Quick Action Enhanced */
        .quick-action-btn {
            position: relative;
            overflow: hidden;
        }
        
        .quick-action-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(136, 43, 42, 0.1);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .quick-action-btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .quick-action-btn i {
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover i {
            transform: scale(1.2) rotate(5deg);
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .welcome-banner {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .welcome-title {
                font-size: 22px;
            }
        }
        

        
        /* ========================================
           MINIMALISTIC DASHBOARD REDESIGN
           Professional & Clean Design
        ======================================== */
        
        /* Metric Cards */
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 16px;
            border: 1px solid #E2E8F0;
            transition: all 0.3s ease;
            height: 100%;
            opacity: 0;
        }
        
        .metric-card:hover {
            border-color: #CBD5E1;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        /* Fade-in animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .metric-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: transparent !important;
            color: #2563EB !important;
        }
        
        /* Override inline gradient backgrounds */
        .metric-icon[style*="background"] {
            background: transparent !important;
        }
        
        .metric-trend {
            font-size: 11px;
            font-weight: 600;
        }
        
        .trend-up {
            color: #10B981;
        }
        
        .metric-alert {
            color: #F59E0B;
            font-size: 16px;
            animation: pulse-alert 2s infinite;
        }
        
        @keyframes pulse-alert {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .metric-body {
            margin-top: 12px;
        }
        
        .metric-value {
            font-size: 32px;
            font-weight: 700;
            color: #1E293B;
            line-height: 1;
            margin-bottom: 8px;
        }
        
        .metric-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
        }
        
        .metric-footer {
            margin-top: 12px;
            font-size: 12px;
            color: #64748B;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .metric-footer i {
            font-size: 10px;
        }
        
        .alert-text {
            color: #F59E0B;
        }
        
        .warning-text {
            color: #F59E0B;
        }
        
        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            width: 100%;
            max-width: 100%;
            height: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        
        .card-header-custom {
            padding: 12px 16px;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            flex-shrink: 0;
            box-sizing: border-box;
        }
        
        .card-title-custom {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
        }
        
        .card-body-custom {
            padding: 12px;
            overflow-x: hidden;
            overflow-y: auto;
            flex: 0 1 auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        
        .card-body-custom > * {
            max-width: 100%;
        }
        
        /* Live Badge */
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #ECFDF5;
            border: 1px solid #10B981;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            color: #10B981;
        }
        
        .live-dot {
            width: 6px;
            height: 6px;
            background: #10B981;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        
        @keyframes pulse-dot {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.5;
                transform: scale(1.2);
            }
        }
        
        /* Keyboard Hint */
        .kbd-hint {
            display: inline-flex;
            align-items: center;
            opacity: 0.6;
            transition: opacity 0.2s ease;
        }
        
        .kbd-hint:hover {
            opacity: 1;
        }
        
        .kbd-hint kbd {
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 600;
            background: #F1F5F9;
            border: 1px solid #CBD5E1;
            border-radius: 4px;
            color: #64748B;
            font-family: monospace;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        /* Button Styles */
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #64748B;
            font-size: 14px;
        }
        
        .btn-icon:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
            color: #1E293B;
        }
        
        .btn-icon:active {
            transform: scale(0.95);
        }
        
        .btn-icon.refreshing {
            pointer-events: none;
        }
        
        .btn-icon.refreshing i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .btn-text {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
            background: white;
            font-size: 13px;
            font-weight: 500;
            color: #64748B;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-block;
        }
        
        .btn-text:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
            color: #1E293B;
        }
        
        /* Orders List */
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 0;
            padding: 0;
            flex: 0 1 auto;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            margin-bottom: 8px;
            background: white;
            min-height: 60px;
        }
        
        .order-item:last-child {
            margin-bottom: 0;
        }
        
        .order-item:hover {
            border-color: #CBD5E1;
            background: #F8FAFC;
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        .order-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        
        .order-number {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            line-height: 1.2;
        }
        
        .order-customer {
            font-size: 12px;
            color: #64748B;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .order-meta {
            font-size: 11px;
            color: #94A3B8;
            display: flex;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
        }
        
        .separator {
            color: #CBD5E1;
        }
        
        .order-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            flex-shrink: 0;
            margin-left: 12px;
        }
        
        .order-amount {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            line-height: 1.2;
        }
        
        .order-status {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
            line-height: 1;
        }
        
        .order-status i {
            font-size: 9px;
        }
        
        .status-success {
            background: #ECFDF5;
            color: #10B981;
            border: 1px solid #10B981;
        }
        
        .status-warning {
            background: #FEF3C7;
            color: #F59E0B;
            border: 1px solid #F59E0B;
        }
        
        .status-info {
            background: #DBEAFE;
            color: #3B82F6;
            border: 1px solid #3B82F6;
        }
        
        .status-primary {
            background: #EDE9FE;
            color: #8B5CF6;
            border: 1px solid #8B5CF6;
        }
        
        .status-danger {
            background: #FEE2E2;
            color: #EF4444;
            border: 1px solid #EF4444;
        }
        
        .status-secondary {
            background: #F1F5F9;
            color: #64748B;
            border: 1px solid #CBD5E1;
        }
        
        /* Stock Alerts */
        .alert-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .alert-item {
            padding: 14px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-item.critical {
            background: #FEE2E2;
            border: 1px solid #EF4444;
        }
        
        .alert-item.warning {
            background: #FEF3C7;
            border: 1px solid #F59E0B;
        }
        
        .alert-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .alert-item.critical .alert-icon {
            background: white;
            color: #EF4444;
        }
        
        .alert-item.warning .alert-icon {
            background: white;
            color: #F59E0B;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-value {
            font-size: 20px;
            font-weight: 700;
            color: #1E293B;
            line-height: 1;
            margin-bottom: 4px;
        }
        
        .alert-label {
            font-size: 11px;
            color: #64748B;
            font-weight: 500;
        }
        
        /* Stock List */
        .stock-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 6px;
            background: #F8FAFC;
        }
        
        .stock-info {
            flex: 1;
        }
        
        .stock-title {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 2px;
        }
        
        .stock-author {
            font-size: 11px;
            color: #64748B;
        }
        
        .stock-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .stock-badge.critical {
            background: #EF4444;
            color: white;
        }
        
        .stock-badge.warning {
            background: #F59E0B;
            color: white;
        }
        
        /* Status Summary */
        .status-summary {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .status-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .status-bar {
            height: 8px;
            background: #F1F5F9;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .status-progress {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        .status-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-name {
            font-size: 12px;
            color: #64748B;
            font-weight: 500;
        }
        
        .status-count {
            font-size: 13px;
            font-weight: 700;
            color: #1E293B;
        }
        
        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
        }
        
        .book-card {
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .book-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .book-card:hover::before {
            left: 100%;
        }
        
        .book-card:hover {
            border-color: #CBD5E1;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-6px);
        }
        
        .book-rank {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            z-index: 1;
        }
        
        .book-cover {
            width: 100%;
            height: 200px;
            background: #F8FAFC;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .book-placeholder {
            font-size: 48px;
            color: #CBD5E1;
        }
        
        .book-info {
            padding: 12px;
        }
        
        .book-title {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .book-author {
            font-size: 11px;
            color: #64748B;
            margin-bottom: 8px;
        }
        
        .book-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid #E2E8F0;
        }
        
        .book-sold {
            font-size: 11px;
            color: #10B981;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .book-sold i {
            font-size: 10px;
        }
        
        .book-price {
            font-size: 13px;
            font-weight: 700;
            color: #1E293B;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #94A3B8;
            margin: 0;
            flex: 0 1 auto;
        }
        
        .empty-state i {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.2;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .empty-state p {
            font-size: 13px;
            margin: 0;
            font-weight: 500;
        }
        
        .empty-state-small {
            text-align: center;
            padding: 20px;
            color: #94A3B8;
        }
        
        .empty-state-small i {
            font-size: 32px;
            margin-bottom: 8px;
            color: #10B981;
        }
        
        .empty-state-small p {
            font-size: 12px;
            margin: 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 12px;
            }
            
            .alert-summary {
                grid-template-columns: 1fr;
            }
            
            .metric-value {
                font-size: 28px;
            }
            
            .kbd-hint {
                display: none;
            }
        }
        
        /* Scroll to Top Button */
        .scroll-top-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 48px;
            height: 48px;
            background: #1E293B;
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            box-shadow: none;
            z-index: 1000;
        }
        
        .scroll-top-btn.show {
            opacity: 1;
            visibility: visible;
        }
        
        .scroll-top-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .scroll-top-btn:active {
            transform: translateY(-2px);
        }
        
        /* Quick Actions Floating Menu */
        .dashboard-removed { display: none !important; }

        .quick-actions-fab {
            position: fixed;
            bottom: 90px;
            right: 30px;
            z-index: 999;
        }
        
        .fab-main {
            width: 56px;
            height: 56px;
            background: #1E293B;
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: none;
            transition: all 0.3s ease;
            font-size: 20px;
        }
        
        .fab-main:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 24px rgba(102, 126, 234, 0.5);
        }
        
        .fab-main.active {
            transform: rotate(45deg);
        }
        
        .fab-menu {
            position: absolute;
            bottom: 70px;
            right: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }
        
        .fab-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .fab-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 12px 16px;
            border-radius: 28px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: #1E293B;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            transition: all 0.2s ease;
            border: 1px solid #E2E8F0;
        }
        
        .fab-item:hover {
            transform: translateX(-4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            border-color: #CBD5E1;
        }
        
        .fab-item i {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 14px;
        }
        
        .fab-item.add-book i {
            background: #ECFDF5;
            color: #10B981;
        }
        
        .fab-item.manage-orders i {
            background: #FEF3C7;
            color: #F59E0B;
        }
        
        .fab-item.view-customers i {
            background: #DBEAFE;
            color: #3B82F6;
        }
        
        .fab-item.reports i {
            background: #EDE9FE;
            color: #8B5CF6;
        }
        
        .fab-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #EF4444;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
        }
        
        .fab-item {
            position: relative;
        }
        
        /* Welcome Toast */
        .welcome-toast {
            position: fixed;
            top: 80px;
            right: 20px;
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            z-index: 9998;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 3.5s forwards;
            min-width: 280px;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(400px);
            }
        }
        
        .welcome-icon {
            width: 40px;
            height: 40px;
            background: #1E293B;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .welcome-content {
            flex: 1;
        }
        
        .welcome-title {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 2px;
        }
        
        .welcome-subtitle {
            font-size: 12px;
            color: #64748B;
        }
        
        /* Global Search */
        .global-search {
            position: relative;
            width: 350px;
        }
        
        .search-input {
            width: 100%;
            padding: 8px 16px 8px 40px;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.2s ease;
            background: #F8FAFC;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667EEA;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 14px;
            pointer-events: none;
        }
        
        .search-results {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
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
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
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
            color: #1E293B;
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
            opacity: 0.3;
        }
        
        /* Quick Filters */
        .quick-filters {
            display: flex;
            gap: 4px;
            background: #F8FAFC;
            padding: 4px;
            border-radius: 8px;
        }
        
        .filter-btn {
            padding: 6px 12px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            color: #64748B;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-btn:hover {
            background: white;
            color: #1E293B;
        }
        
        .filter-btn.active {
            background: white;
            color: #667EEA;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        /* Theme Toggle */
        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #64748B;
        }
        
        .theme-toggle:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
            color: #1E293B;
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
        
        /* ========================================
           NOTIFICATION ICONS - PROFESSIONAL COLORS
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
            color: #4F46E5 !important;
        }
        
        .dropdown-item.text-primary:hover {
            background: #EEF2FF !important;
            color: #4338CA !important;
        }
        
        /* Notification Dropdown Header */
        .dropdown-header {
            padding: 12px 16px !important;
            font-weight: 600 !important;
            color: #1E293B !important;
            font-size: 14px !important;
            background: #F8FAFC !important;
            border-radius: 6px !important;
            margin-bottom: 4px !important;
        }
        
        /* Responsive Notification Dropdown */
        @media (max-width: 768px) {
            .notification-dropdown {
                width: 340px;
                right: -20px;
            }
            
            .notification-item {
                padding: 14px 16px;
            }
            
            .notification-icon {
                width: 42px;
                height: 42px;
                font-size: 18px;
            }
            
            .notification-title {
                font-size: 14px;
            }
            
            .notification-text {
                font-size: 13px;
            }
        }
        
        @media (max-width: 480px) {
            .notification-dropdown {
                width: calc(100vw - 40px);
                right: -10px;
                max-height: 70vh;
            }
        }
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.3;
        }
        
        /* Dark Mode Styles */
        body.dark-mode {
            background: #0F172A;
            color: #E2E8F0;
        }
        
        body.dark-mode .main-content {
            background: #0F172A;
        }
        
        body.dark-mode .top-header {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .top-header h4 {
            color: #F1F5F9 !important;
        }
        
        body.dark-mode .top-header * {
            color: #E2E8F0 !important;
        }
        
        body.dark-mode .top-header small {
            color: #94A3B8 !important;
        }
        
        body.dark-mode .metric-card,
        body.dark-mode .content-card {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .metric-label,
        body.dark-mode .metric-footer,
        body.dark-mode .card-title-custom {
            color: #94A3B8;
        }
        
        body.dark-mode .metric-value {
            color: #F1F5F9;
        }
        
        body.dark-mode .order-item,
        body.dark-mode .book-card,
        body.dark-mode .stock-item {
            background: #1E293B !important;
            border-color: #334155 !important;
        }
        
        body.dark-mode .order-item:hover,
        body.dark-mode .book-card:hover {
            background: #334155 !important;
        }
        
        /* Force remove white backgrounds from book cards */
        body.dark-mode .book-card,
        body.dark-mode .book-card * {
            background-color: transparent !important;
        }
        
        body.dark-mode .book-card {
            background-color: #1E293B !important;
            background: #1E293B !important;
        }
        
        body.dark-mode .book-card::before {
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent) !important;
        }
        
        body.dark-mode .order-number,
        body.dark-mode .order-amount,
        body.dark-mode .book-title,
        body.dark-mode .stock-title {
            color: #F1F5F9;
        }
        
        body.dark-mode .order-customer,
        body.dark-mode .order-meta,
        body.dark-mode .book-author,
        body.dark-mode .stock-author {
            color: #94A3B8;
        }
        
        body.dark-mode .search-input {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #E2E8F0 !important;
        }
        
        body.dark-mode .search-input:focus {
            background: #475569 !important;
        }
        
        body.dark-mode .search-input::placeholder {
            color: #94A3B8 !important;
        }
        
        body.dark-mode .search-icon {
            color: #94A3B8 !important;
        }
        
        body.dark-mode .search-results {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .search-category {
            background: #0F172A;
            color: #94A3B8;
        }
        
        body.dark-mode .search-item {
            border-color: #334155;
        }
        
        body.dark-mode .search-item:hover {
            background: #334155;
        }
        
        body.dark-mode .search-item-title {
            color: #F1F5F9;
        }
        
        body.dark-mode .theme-toggle,
        body.dark-mode .btn-icon,
        body.dark-mode .btn-text,
        body.dark-mode .notification-bell {
            background: #334155;
            border-color: #475569;
            color: #94A3B8;
        }
        
        body.dark-mode .theme-toggle:hover,
        body.dark-mode .btn-icon:hover,
        body.dark-mode .btn-text:hover,
        body.dark-mode .notification-bell:hover {
            background: #475569;
            color: #F1F5F9;
        }
        
        body.dark-mode .notification-dropdown {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .notification-header {
            border-color: #334155;
        }
        
        body.dark-mode .notification-header h6 {
            color: #F1F5F9;
        }
        
        body.dark-mode .notification-item {
            border-color: #334155;
        }
        
        body.dark-mode .notification-item:hover {
            background: #334155;
        }
        
        body.dark-mode .notification-item.unread {
            background: #1E3A5F;
        }
        
        body.dark-mode .notification-title {
            color: #F1F5F9;
        }
        
        body.dark-mode .notification-text {
            color: #94A3B8;
        }
        
        body.dark-mode .notification-footer {
            border-color: #334155;
        }
        
        body.dark-mode .quick-filters {
            background: #0F172A;
        }
        
        body.dark-mode .filter-btn {
            color: #94A3B8;
        }
        
        body.dark-mode .filter-btn:hover {
            background: #334155;
            color: #F1F5F9;
        }
        
        body.dark-mode .filter-btn.active {
            background: #334155;
            color: #667EEA;
        }
        
        body.dark-mode .user-info {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .dropdown-menu {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .dropdown-item {
            color: #94A3B8;
        }
        
        body.dark-mode .dropdown-item:hover {
            background: #334155;
            color: #F1F5F9;
        }
        
        /* Reviews List */
        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .review-item {
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }
        
        .review-item:last-child {
            margin-bottom: 0;
        }
        
        .review-item:hover {
            border-color: #CBD5E1;
            background: #F8FAFC;
        }
        
        .reviews-list {
            display: flex;
            flex-direction: column;
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 12px;
        }
        
        .review-info {
            flex: 1;
            min-width: 0;
        }
        
        .review-book {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .review-customer {
            font-size: 12px;
            color: #64748B;
        }
        
        .review-rating {
            display: flex;
            gap: 2px;
            color: #F59E0B;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .review-rating .far {
            color: #CBD5E1;
        }
        
        .review-comment {
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 8px;
            word-wrap: break-word;
        }
        
        .review-date {
            font-size: 11px;
            color: #94A3B8;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Quick Actions List */
        .quick-actions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .quick-action-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .quick-action-item:hover {
            border-color: #CBD5E1;
            background: #F8FAFC;
            transform: translateX(4px);
        }
        
        .quick-action-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .quick-action-content {
            flex: 1;
        }
        
        .quick-action-title {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 2px;
        }
        
        .quick-action-desc {
            font-size: 11px;
            color: #64748B;
        }
        
        .quick-action-arrow {
            color: #CBD5E1;
            font-size: 12px;
            transition: all 0.2s ease;
        }
        
        .quick-action-item:hover .quick-action-arrow {
            color: #667EEA;
            transform: translateX(4px);
        }
        
        .quick-action-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #EF4444;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }
        
        /* Dark Mode - Reviews & Quick Actions */
        body.dark-mode .review-item,
        body.dark-mode .quick-action-item {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .review-item:hover,
        body.dark-mode .quick-action-item:hover {
            background: #334155;
        }
        
        body.dark-mode .review-book,
        body.dark-mode .quick-action-title {
            color: #F1F5F9;
        }
        
        body.dark-mode .review-customer,
        body.dark-mode .review-comment,
        body.dark-mode .review-date,
        body.dark-mode .quick-action-desc {
            color: #94A3B8;
        }
        
        /* Quick Actions Grid (for grid layout) */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }
        
        .quick-action-item-grid {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 20px 16px;
            border-radius: 10px;
            border: 1px solid #E2E8F0;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            position: relative;
            text-align: center;
        }
        
        .quick-action-item-grid:hover {
            border-color: #CBD5E1;
            background: #F8FAFC;
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .quick-action-item-grid .quick-action-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .quick-action-item-grid .quick-action-title {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
        }
        
        /* Dark Mode - Grid Quick Actions */
        body.dark-mode .quick-action-item-grid {
            background: #1E293B;
            border-color: #334155;
        }
        
        body.dark-mode .quick-action-item-grid:hover {
            background: #334155;
        }
        
        body.dark-mode .quick-action-item-grid .quick-action-title {
            color: #F1F5F9;
        }
        
        /* Top Customers List */
        .top-customers-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .top-customer-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #F8FAFC;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .top-customer-item:hover {
            background: #F1F5F9;
            transform: translateX(4px);
        }
        
        .customer-rank {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 13px;
            flex-shrink: 0;
        }
        
        .customer-info {
            flex: 1;
            min-width: 0;
        }
        
        .customer-name {
            font-size: 13px;
            font-weight: 500;
            color: #1E293B;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .customer-email {
            font-size: 11px;
            color: #64748B;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .customer-stats {
            text-align: right;
        }
        
        .customer-spent {
            font-size: 13px;
            font-weight: 600;
            color: #10B981;
        }
        
        .customer-orders {
            font-size: 11px;
            color: #64748B;
        }
        
        /* Top Books List (Compact Sidebar Version) */
        .top-books-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .top-book-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #F8FAFC;
            border-radius: 8px;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .top-book-item:hover {
            background: #F1F5F9;
            transform: translateX(4px);
        }
        
        .book-rank-badge {
            position: absolute;
            top: 6px;
            left: 6px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
            z-index: 1;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .book-cover-small {
            width: 50px;
            height: 70px;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
            background: #E2E8F0;
        }
        
        .book-cover-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .book-placeholder-small {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #E2E8F0 0%, #CBD5E1 100%);
            color: #94A3B8;
            font-size: 20px;
        }
        
        .book-details {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .book-title-small {
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .book-author-small {
            font-size: 11px;
            color: #64748B;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .book-stats-small {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            margin-top: 2px;
        }
        
        .book-sold-small {
            color: #10B981;
            font-weight: 500;
        }
        
        .book-sold-small i {
            font-size: 10px;
            margin-right: 3px;
        }
        
        .book-price-small {
            color: #3B82F6;
            font-weight: 600;
        }
        
        /* Categories List */
        .categories-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .category-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #F8FAFC;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .category-item:hover {
            background: #F1F5F9;
            transform: translateX(4px);
        }
        
        .category-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .category-info {
            flex: 1;
            min-width: 0;
        }
        
        .category-name {
            font-size: 13px;
            font-weight: 500;
            color: #1E293B;
            margin-bottom: 2px;
        }
        
        .category-meta {
            font-size: 11px;
            color: #64748B;
        }
        
        .category-stats {
            text-align: right;
        }
        
        .category-revenue {
            font-size: 13px;
            font-weight: 600;
            color: #3B82F6;
        }
        
        .category-sold {
            font-size: 11px;
            color: #64748B;
        }
        
        /* Activity Feed */
        .activity-feed {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px;
            background: #F8FAFC;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .activity-item:hover {
            background: #F1F5F9;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            font-size: 13px;
            color: #1E293B;
            margin-bottom: 4px;
        }
        
        .activity-time {
            font-size: 11px;
            color: #94A3B8;
        }
        
        /* Today's Snapshot */
        .snapshot-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .snapshot-item {
            padding: 12px;
            background: #F8FAFC;
            border-radius: 8px;
            border-left: 3px solid #3B82F6;
        }
        
        .snapshot-label {
            font-size: 11px;
            color: #64748B;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .snapshot-value {
            font-size: 20px;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 6px;
        }
        
        .snapshot-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .snapshot-change.positive {
            color: #10B981;
        }
        
        .snapshot-change.negative {
            color: #EF4444;
        }
        
        /* Inventory Health */
        .health-score {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .health-circle {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 10px;
        }
        
        .health-circle svg {
            width: 100%;
            height: 100%;
        }
        
        .health-percentage {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 20px;
            font-weight: 700;
            color: #10B981;
        }
        
        .health-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
        }
        
        .health-stats {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .health-stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #F8FAFC;
            border-radius: 6px;
        }
        
        .health-stat-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .health-stat-label {
            flex: 1;
            font-size: 13px;
            color: #64748B;
        }
        
        .health-stat-value {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
        }
        
        /* Export List */
        .export-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .export-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }
        
        .export-btn:hover {
            background: white;
            border-color: #CBD5E1;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .export-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        
        .export-content {
            flex: 1;
            text-align: left;
        }
        
        .export-title {
            font-size: 14px;
            font-weight: 500;
            color: #1E293B;
            margin-bottom: 2px;
        }
        
        .export-desc {
            font-size: 12px;
            color: #64748B;
        }
        
        .export-btn i.fa-download {
            color: #64748B;
            font-size: 16px;
        }
        
        /* Dark Mode - New Sections */
        body.dark-mode .top-customer-item,
        body.dark-mode .category-item,
        body.dark-mode .activity-item,
        body.dark-mode .snapshot-item,
        body.dark-mode .health-stat-item,
        body.dark-mode .export-btn {
            background: #0F172A;
            border-color: #334155;
        }
        
        body.dark-mode .top-customer-item:hover,
        body.dark-mode .category-item:hover,
        body.dark-mode .activity-item:hover {
            background: #1E293B;
        }
        
        body.dark-mode .export-btn:hover {
            background: #1E293B;
        }
        
        body.dark-mode .customer-name,
        body.dark-mode .category-name,
        body.dark-mode .activity-text,
        body.dark-mode .snapshot-value,
        body.dark-mode .health-stat-value,
        body.dark-mode .export-title {
            color: #F1F5F9;
        }
        
        body.dark-mode .customer-email,
        body.dark-mode .customer-orders,
        body.dark-mode .category-meta,
        body.dark-mode .category-sold,
        body.dark-mode .activity-time,
        body.dark-mode .snapshot-label,
        body.dark-mode .health-label,
        body.dark-mode .health-stat-label,
        body.dark-mode .export-desc {
            color: #94A3B8;
        }
        
        /* Keyboard Shortcuts Help Button */
        .shortcuts-help-btn {
            position: fixed;
            bottom: 90px;
            right: 24px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.3s ease;
            z-index: 999;
        }
        
        .shortcuts-help-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
        }
        
        /* Keyboard Shortcuts Panel */
        .shortcuts-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            width: 90%;
            max-width: 500px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 10000;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .shortcuts-panel.active {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }
        
        .shortcuts-header {
            padding: 20px 24px;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
            color: white;
        }
        
        .shortcuts-header h6 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .shortcuts-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .shortcuts-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .shortcuts-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        
        .shortcut-group {
            margin-bottom: 24px;
        }
        
        .shortcut-group:last-child {
            margin-bottom: 0;
        }
        
        .shortcut-group-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        
        .shortcut-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #F8FAFC;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .shortcut-keys {
            display: flex;
            gap: 4px;
        }
        
        .shortcut-keys kbd {
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            background: white;
            border: 2px solid #E2E8F0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 0 #E2E8F0;
        }
        
        .shortcut-desc {
            font-size: 14px;
            color: #475569;
        }
        
        /* Shortcuts Panel Overlay */
        .shortcuts-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 9999;
        }
        
        .shortcuts-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Dark Mode - Shortcuts */
        body.dark-mode .shortcuts-panel {
            background: #1E293B;
        }
        
        body.dark-mode .shortcuts-header {
            border-color: #334155;
        }
        
        body.dark-mode .shortcut-item {
            background: #0F172A;
        }
        
        body.dark-mode .shortcut-keys kbd {
            background: #334155;
            border-color: #475569;
            color: #F1F5F9;
            box-shadow: 0 2px 0 #475569;
        }
        
        body.dark-mode .shortcut-desc {
            color: #94A3B8;
        }
        
        body.dark-mode .shortcut-group-title {
            color: #94A3B8;
        }
        
        /* Ensure all rows and columns are properly contained */
        .main-content .row {
            margin-left: -8px;
            margin-right: -8px;
            width: auto;
            max-width: none;
            display: flex;
            flex-wrap: wrap;
            box-sizing: border-box;
        }
        
        .main-content .row > * {
            padding-left: 8px;
            padding-right: 8px;
            box-sizing: border-box;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        
        .main-content .row > * > .content-card,
        .main-content .row > * > .metric-card {
            flex: 0 1 auto;
            width: 100%;
            box-sizing: border-box;
            height: auto;
        }
        
        /* Specific fix for g-3 gap */
        .main-content .g-3 {
            margin-left: -8px !important;
            margin-right: -8px !important;
            width: calc(100% + 16px) !important;
        }
        
        .main-content .g-3 > * {
            padding-left: 8px !important;
            padding-right: 8px !important;
            min-width: 0 !important;
        }
        
        /* Reduce mb-4 spacing */
        .main-content .mb-4 {
            margin-bottom: 16px !important;
        }
        
        .main-content .mb-3 {
            margin-bottom: 12px !important;
        }
        
        /* Ensure proper column widths */
        .col-xl-8 {
            flex: 0 0 auto;
            width: 66.66666667%;
            max-width: 66.66666667%;
        }
        
        .col-xl-6 {
            flex: 0 0 auto;
            width: 50%;
            max-width: 50%;
        }
        
        .col-xl-4 {
            flex: 0 0 auto;
            width: 33.33333333%;
            max-width: 33.33333333%;
        }
        
        .col-xl-3 {
            flex: 0 0 auto;
            width: 25%;
            max-width: 25%;
        }
        
        .col-12 {
            flex: 0 0 auto;
            width: 100%;
            max-width: 100%;
        }
        
        @media (max-width: 1199px) {
            .col-lg-4 {
                flex: 0 0 auto;
                width: 33.33333333%;
                max-width: 33.33333333%;
            }
        }
        
        @media (max-width: 991px) {
            .col-md-6 {
                flex: 0 0 auto;
                width: 50%;
                max-width: 50%;
            }
        }
        
        /* Fix for chart responsiveness */
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Ensure lists don't overflow */
        .top-customers-list,
        .top-books-list,
        .categories-list,
        .activity-feed,
        .snapshot-grid,
        .health-stats,
        .export-list {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        /* Fix for orders list container */
        .orders-list {
            min-height: 0;
            flex-shrink: 1;
            flex-grow: 0;
            flex: 0 1 auto;
        }
        
        /* Remove height constraints from Recent Orders card */
        .col-xl-8 > .content-card {
            height: auto;
        }
        
        .col-xl-8 > .content-card .card-body-custom {
            padding: 12px;
            flex: 0 1 auto;
            height: auto;
            overflow-y: visible !important;
            min-height: auto !important;
        }
        
        /* Fix Bootstrap container */
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
            max-width: 100%;
            width: 100%;
            margin: 0;
            box-sizing: border-box;
        }
        
        /* Ensure main content wrapper */
        .main-content > .container-fluid {
            padding: 0;
            margin: 0;
        }
        
        /* Ensure content cards fit properly */
        .col-xl-8, .col-xl-6, .col-xl-4, .col-xl-3, .col-md-6, .col-12 {
            box-sizing: border-box;
            max-width: 100%;
            min-width: 0;
        }
        
        /* Force content cards to respect container width */
        .content-card {
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        /* Ensure chart container doesn't overflow */
        .card-body-custom canvas {
            display: block;
            box-sizing: border-box;
            max-width: 100%;
        }
        
        /* Chart container specific fix */
        .card-body-custom > div {
            width: 100% !important;
            max-width: 100% !important;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        #salesChart {
            width: 100% !important;
            max-width: 100% !important;
        }
        
        /* Prevent text overflow in cards */
        .customer-name,
        .category-name,
        .activity-text,
        .export-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-section">
                <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="sidebar-logo">
                <div class="brand-info">
                    <h3>CSU Lost & Found</h3>
                    <p class="brand-subtitle">Campus Recovery System</p>
                </div>
            </div>
            <div class="toggle-sidebar-btn" onclick="toggleSidebar()">
                <i class="fas fa-chevron-left"></i>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="unified_admin_dashboard.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-section-title">
                <span>Customer Management</span>
            </div>
            
            <a href="admin_manage_customers.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            
            <a href="pending_approvals.php" class="menu-item">
                <i class="fas fa-user-check"></i>
                <span>Pending Approvals</span>
                <?php if ($pending_approvals > 0): ?>
                    <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                <?php endif; ?>
            </a>
            
            <?php if ($is_super_admin): ?>
            <div class="menu-section-title">
                <span>Administrator Management</span>
            </div>

            <a href="unified_admin_users.php" class="menu-item">
                <i class="fas fa-user-shield"></i>
                <span>Admin Users</span>
            </a>
            <?php endif; ?>
            
            <div class="menu-section-title">
                <span>Logs & Audit</span>
            </div>
            
            <?php if ($is_super_admin): ?>
            <a href="privileges.php" class="menu-item">
                <i class="fas fa-shield-alt"></i>
                <span>Privileges</span>
            </a>
            <?php endif; ?>
            <a href="admin_logs.php" class="menu-item">
                <i class="fas fa-user-shield"></i>
                <span>Login Logs</span>
            </a>
            
            <a href="customer_logs.php" class="menu-item">
                <i class="fas fa-clipboard-list"></i>
                <span>Customer Logs</span>
            </a>
            
            <a href="audit_policy.php" class="menu-item">
                <i class="fas fa-file-shield"></i>
                <span>Audit Policy</span>
            </a>
            
            <?php if ($is_super_admin): ?>
            <div class="menu-section-title">
                <span>System Settings</span>
            </div>
            <a href="admin_settings.php" class="menu-item">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
            <?php endif; ?>
            
            <a href="admin_profile.php" class="menu-item">
                <i class="fas fa-user-circle"></i>
                <span>My Profile</span>
            </a>
            
            <a href="Sign_out.php" class="menu-item menu-item-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
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
                        <?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?> Dashboard
                    </h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin_data['full_name']); ?>!</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Global Search -->
                <div class="global-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="globalSearch" placeholder="Search cases, claimants, items..." autocomplete="off">
                    <div class="search-results" id="searchResults"></div>
                </div>
                
                <!-- Notifications -->
                <?php if ($notification_count > 0): ?>
                <div class="dropdown">
                    <button class="btn btn-sm position-relative notification-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell" style="font-size: 18px;"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" style="background: #FF5252 !important; color: white !important; font-size: 10px; padding: 3px 6px; min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center;">
                            <?php echo $notification_count; ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 320px;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-bell me-2"></i>Notifications</span>
                            <span style="background: transparent !important; color: #EF4444 !important; font-size: 16px; font-weight: 700; padding: 0;"><?php echo $notification_count; ?></span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        
                        <?php if ($pending_orders > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_manage_orders.php?status=pending">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-warning">
                                        <i class="fas fa-hourglass-half"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Pending Claims</div>
                                        <div class="notification-text"><?php echo $pending_claims; ?> claims awaiting verification</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($pending_claims > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_lost_items.php?filter=pending">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-danger">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">Active Cases</div>
                                        <div class="notification-text"><?php echo $pending_claims; ?> items under review</div>
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
                        
                        <?php if ($recent_registrations > 0): ?>
                        <li>
                            <a class="dropdown-item" href="admin_manage_customers.php">
                                <div class="d-flex align-items-center">
                                    <div class="notification-icon bg-success">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="notification-title">New Registrations</div>
                                        <div class="notification-text"><?php echo $recent_registrations; ?> new customers (24h)</div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <?php if ($is_super_admin): ?>
                            <a class="dropdown-item text-center text-primary" href="admin_logs.php">
                                <small><i class="fas fa-eye me-1"></i>View All Activity</small>
                            </a>
                            <?php else: ?>
                            <div class="dropdown-item text-center text-muted" style="cursor: not-allowed;">
                                <small><i class="fas fa-lock me-1"></i>Access Denied: Only Super Administrator can view Admin Logs.</small>
                            </div>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Dark Mode Toggle -->
                <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>
                
                <div class="dropdown">
                    <div class="user-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="text-end">
                            <div class="fw-bold"><?php echo htmlspecialchars($admin_data['full_name']); ?></div>
                        </div>
                        <div class="user-avatar">
                            <?php 
                            if (!empty($admin_data['profile_image']) && file_exists('../uploads/profiles/' . $admin_data['profile_image'])) {
                                echo '<img src="../uploads/profiles/' . htmlspecialchars($admin_data['profile_image']) . '" alt="Profile" onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'' . strtoupper(substr($admin_data['full_name'], 0, 1)) . '\';">';
                            } else {
                                echo strtoupper(substr($admin_data['full_name'], 0, 1));
                            }
                            ?>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg">
                        <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                        <li><a class="dropdown-item" href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="Sign_out.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                    <input type="file" id="profileImageUpload" accept="image/*" style="display: none;" onchange="uploadProfileImage(this)">
                </div>
            </div>
        </div>        
        <!-- Access Denied Alert (if any) -->
        <?php if (isset($_SESSION['access_denied'])): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert" style="border-radius: 8px;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Access Denied:</strong> <?php echo htmlspecialchars($_SESSION['access_denied']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php 
            unset($_SESSION['access_denied']); 
        endif; 
        ?>
        
        <!-- Welcome Toast -->
        <div class="welcome-toast" id="welcomeToast">
            <div class="welcome-icon">
                <i class="fas fa-hand-sparkles"></i>
            </div>
            <div class="welcome-content">
                <div class="welcome-title">Welcome back, <?php echo htmlspecialchars(explode(' ', $admin_data['full_name'])[0]); ?>!</div>
                <div class="welcome-subtitle">Ready to manage campus recoveries</div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <!-- Total Orders -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-shopping-cart" style="color: #3B82F6 !important;"></i>
                        </div>
                        <div class="metric-trend">
                            <?php if ($today_orders > 0): ?>
                                <span class="trend-up">+<?php echo $today_orders; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="totalOrders"><?php echo $total_orders; ?></div>
                        <div class="metric-label">Total Orders</div>
                        <?php if ($today_orders > 0): ?>
                            <div class="metric-footer">
                                <i class="fas fa-arrow-up"></i> <?php echo $today_orders; ?> orders today
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Total Users -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.2s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-users" style="color: #10B981 !important;"></i>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="totalUsers"><?php echo $total_customers; ?></div>
                        <div class="metric-label">Total Users</div>
                        <?php if ($pending_approvals > 0): ?>
                            <div class="metric-footer">
                                <i class="fas fa-user-clock"></i> <?php echo $pending_approvals; ?> pending approval
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Resolved Cases -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.3s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-handshake" style="color: #F59E0B !important;"></i>
                        </div>
                        <div class="metric-trend">
                            <?php if ($resolved_claims > 0): ?>
                                <span class="trend-up">+<?php echo $resolved_claims; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="resolvedCases"><?php echo $resolved_claims; ?></div>
                        <div class="metric-label">Resolved Cases</div>
                        <?php if ($total_lost_items > 0): ?>
                            <div class="metric-footer">
                                <i class="fas fa-check-circle"></i> <?php echo round(($resolved_claims / $total_lost_items) * 100); ?>% success rate
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Pending Claims -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.4s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-clock" style="color: #8B5CF6 !important;"></i>
                        </div>
                        <?php if ($pending_claims > 5): ?>
                            <div class="metric-alert">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="pendingClaims"><?php echo $pending_claims; ?></div>
                        <div class="metric-label">Pending Claims</div>
                        <?php if ($pending_claims > 5): ?>
                            <div class="metric-footer alert-text">
                                <i class="fas fa-bell"></i> Needs attention
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Successfully Matched Cases -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.5s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-check-circle" style="color: #10B981 !important;"></i>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="matchedCases"><?php echo $resolved_claims; ?></div>
                        <div class="metric-label">Matched Cases</div>
                        <?php if ($total_lost_items > 0): ?>
                            <div class="metric-footer">
                                <?php echo round(($resolved_claims / $total_lost_items) * 100); ?>% match rate
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Total Lost Items -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.6s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-search" style="color: #6366F1 !important;"></i>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="totalLostItems"><?php echo $total_lost_items; ?></div>
                        <div class="metric-label">Lost Items</div>
                        <?php if ($pending_claims > 0): ?>
                            <div class="metric-footer warning-text">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $pending_claims; ?> pending
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Resolution Rate -->
            <div class="col-xl-3 col-lg-4 col-md-6 dashboard-removed">
                <div class="metric-card animate-fade-in" style="animation-delay: 0.7s;">
                    <div class="metric-header">
                        <div class="metric-icon" style="background: transparent !important;">
                            <i class="fas fa-chart-pie" style="color: #EC4899 !important;"></i>
                        </div>
                    </div>
                    <div class="metric-body">
                        <div class="metric-value" id="resolutionRate"><?php echo ($total_lost_items > 0) ? round(($resolved_claims / $total_lost_items) * 100) : 0; ?>%</div>
                        <div class="metric-label">Resolution Rate</div>
                        <div class="metric-footer">
                            <i class="fas fa-check-circle"></i> Cases resolved
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sales Chart & Top Customers -->
        <div class="row g-3 mb-4 dashboard-removed">
            <!-- Sales Chart -->
            <div class="col-xl-8">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h6 class="card-title-custom mb-0"><i class="fas fa-chart-line" style="color: #3B82F6; margin-right: 8px;"></i>Lost & Found Overview</h6>
                        <div class="d-flex gap-2">
                            <button class="btn-text active" onclick="changeSalesView('week')">7 Days</button>
                            <button class="btn-text" onclick="changeSalesView('month')">30 Days</button>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div style="position: relative; height: 200px; width: 100%; max-width: 100%;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Resolved Cases Summary -->
            <div class="col-xl-4">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h6 class="card-title-custom mb-0"><i class="fas fa-check-double" style="color: #10B981; margin-right: 8px;"></i>Resolved Cases</h6>
                        <a href="admin_lost_items.php" class="btn-text">View All</a>
                    </div>
                    <div class="card-body-custom">
                        <div class="top-books-list">
                            <?php if ($resolved_claims > 0): ?>
                            <div class="top-book-item">
                                <div class="book-rank-badge" style="background: #10B981;">✓</div>
                                <div class="book-cover-small" style="background: #E0F2FE; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-check-circle" style="font-size: 24px; color: #10B981;"></i>
                                </div>
                                <div class="book-details">
                                    <div class="book-title-small">Successfully Resolved</div>
                                    <div class="book-author-small">Lost & Found Matches</div>
                                    <div class="book-stats-small">
                                        <span class="book-sold-small">
                                            <i class="fas fa-clipboard-check"></i>
                                            <?php echo $resolved_claims; ?> cases
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php if ($pending_claims > 0): ?>
                            <div class="top-book-item">
                                <div class="book-rank-badge" style="background: #F59E0B;">!</div>
                                <div class="book-cover-small" style="background: #FEF3C7; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-hourglass-half" style="font-size: 24px; color: #F59E0B;"></i>
                                </div>
                                <div class="book-details">
                                    <div class="book-title-small">Under Review</div>
                                    <div class="book-author-small">Pending Verification</div>
                                    <div class="book-stats-small">
                                        <span class="book-sold-small">
                                            <i class="fas fa-search"></i>
                                            <?php echo $pending_claims; ?> cases
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No resolved cases yet</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Real-Time Orders & Quick Stats -->
        <div class="row g-3 mb-4 dashboard-removed">
            <!-- Recent Orders -->
            <div class="col-xl-8">
                <div class="content-card">
                    <div class="card-header-custom">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="card-title-custom mb-0"><i class="fas fa-box-open" style="color: #10B981; margin-right: 8px;"></i>Recent Lost & Found Cases</h6>
                            <span class="live-badge">
                                <span class="live-dot"></span>
                                Live
                            </span>
                            <span class="kbd-hint" title="Press R to refresh">
                                <kbd>R</kbd>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <!-- Quick Filters -->
                            <div class="quick-filters">
                                <button class="filter-btn active" data-status="all" onclick="filterOrders('all')">All</button>
                                <button class="filter-btn" data-status="pending" onclick="filterOrders('pending')">New</button>
                                <button class="filter-btn" data-status="processing" onclick="filterOrders('processing')">Reviewing</button>
                                <button class="filter-btn" data-status="shipped" onclick="filterOrders('shipped')">Matched</button>
                                <button class="filter-btn" data-status="delivered" onclick="filterOrders('delivered')">Resolved</button>
                            </div>
                            <button class="btn-icon" onclick="refreshOrdersManual()" id="refreshBtn" title="Refresh (Press R)">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <a href="admin_manage_orders.php" class="btn-text">View All Cases</a>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div class="orders-list" id="ordersContainer">
                            <?php
                            $recent_orders = null;
                            $table_check = $conn->query("SHOW TABLES LIKE 'orders'");
                            if ($table_check && $table_check->num_rows > 0) {
                                $recent_orders_query = "
                                    SELECT o.*, 
                                    COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as customer_name,
                                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                                    FROM orders o
                                    LEFT JOIN infos i ON o.user_id = i.id
                                    ORDER BY o.created_at DESC
                                    LIMIT 6
                                ";
                                $recent_orders = $conn->query($recent_orders_query);
                            }
                            
                            if ($recent_orders && $recent_orders->num_rows > 0):
                                while ($order = $recent_orders->fetch_assoc()):
                                    $status_class = '';
                                    $status_icon = '';
                                    switch($order['status']) {
                                        case 'delivered': 
                                            $status_class = 'status-success'; 
                                            $status_icon = 'fa-check-circle';
                                            break;
                                        case 'pending': 
                                            $status_class = 'status-warning'; 
                                            $status_icon = 'fa-clock';
                                            break;
                                        case 'processing': 
                                            $status_class = 'status-info'; 
                                            $status_icon = 'fa-spinner';
                                            break;
                                        case 'shipped': 
                                            $status_class = 'status-primary'; 
                                            $status_icon = 'fa-shipping-fast';
                                            break;
                                        case 'cancelled': 
                                            $status_class = 'status-danger'; 
                                            $status_icon = 'fa-times-circle';
                                            break;
                                        default: 
                                            $status_class = 'status-secondary';
                                            $status_icon = 'fa-circle';
                                    }
                                    
                                    $time_diff = time() - strtotime($order['created_at']);
                                    if ($time_diff < 60) $time_ago = 'Just now';
                                    elseif ($time_diff < 3600) $time_ago = floor($time_diff / 60) . 'm ago';
                                    elseif ($time_diff < 86400) $time_ago = floor($time_diff / 3600) . 'h ago';
                                    else $time_ago = date('M d', strtotime($order['created_at']));
                            ?>
                            <div class="order-item" data-order-id="<?php echo $order['id']; ?>">
                                <div class="order-info">
                                    <div class="order-number">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></div>
                                    <div class="order-customer"><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></div>
                                    <div class="order-meta">
                                        <span><?php echo $order['item_count']; ?> record<?php echo $order['item_count'] > 1 ? 's' : ''; ?></span>
                                        <span class="separator">•</span>
                                        <span><?php echo $time_ago; ?></span>
                                    </div>
                                </div>
                                <div class="order-details">
                                    <div class="order-amount">Case #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></div>
                                    <span class="order-status <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No cases yet</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Recent Customer Reviews (inside Recent Orders) -->
                        <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title-custom mb-0"><i class="fas fa-star" style="color: #F59E0B; margin-right: 8px;"></i>Recent Match Reviews</h6>
                                <a href="admin_manage_reviews.php" class="btn-text">View All</a>
                            </div>
                            <div class="reviews-list">
                                <?php
                                $reviews = null;
                                $table_check = $conn->query("SHOW TABLES LIKE 'reviews'");
                                if ($table_check && $table_check->num_rows > 0) {
                                    $reviews_query = "
                                        SELECT r.*, b.title as book_title, 
                                        COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name
                                        FROM reviews r
                                        LEFT JOIN books b ON r.id = b.id
                                        LEFT JOIN infos i ON r.user_id = i.id
                                        ORDER BY r.created_at DESC
                                        LIMIT 3
                                    ";
                                    $reviews = $conn->query($reviews_query);
                                }
                                
                                if ($reviews && $reviews->num_rows > 0):
                                    while ($review = $reviews->fetch_assoc()):
                                ?>
                                <div class="review-item">
                                    <div class="review-header">
                                        <div class="review-info">
                                            <div class="review-book"><?php echo htmlspecialchars($review['book_title'] ?? 'Unknown Book'); ?></div>
                                            <div class="review-customer">by <?php echo htmlspecialchars($review['customer_name'] ?? 'Anonymous'); ?></div>
                                        </div>
                                        <div class="review-rating">
                                            <?php 
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= $review['rating']) {
                                                    echo '<i class="fas fa-star"></i>';
                                                } else {
                                                    echo '<i class="far fa-star"></i>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="review-comment"><?php echo htmlspecialchars(substr($review['comment'] ?? '', 0, 120)); ?><?php echo strlen($review['comment'] ?? '') > 120 ? '...' : ''; ?></div>
                                    <div class="review-date">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                    </div>
                                </div>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <div class="empty-state-small">
                                    <i class="fas fa-comments"></i>
                                    <p>No reviews yet</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Today's Snapshot (inside Recent Orders card) -->
                        <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title-custom mb-0"><i class="fas fa-calendar-day" style="color: #3B82F6; margin-right: 8px;"></i>Today's Snapshot</h6>
                            </div>
                            <div class="snapshot-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                                <div class="snapshot-item" style="padding: 12px; background: #F8FAFC; border-radius: 8px; border: 1px solid #E2E8F0;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 36px; height: 36px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                            <i class="fas fa-shopping-cart" style="color: #3B82F6 !important;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-size: 20px; font-weight: 700; color: #1E293B; line-height: 1;"><?php echo $today_orders; ?></div>
                                            <div style="font-size: 11px; color: #64748B; margin-top: 2px;">Cases Today</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="snapshot-item" style="padding: 12px; background: #F8FAFC; border-radius: 8px; border: 1px solid #E2E8F0;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 36px; height: 36px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                            <i class="fas fa-peso-sign" style="color: #10B981 !important;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-size: 20px; font-weight: 700; color: #1E293B; line-height: 1;"><?php echo $today_revenue; ?></div>
                                            <div style="font-size: 11px; color: #64748B; margin-top: 2px;">Recovered Today</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="snapshot-item" style="padding: 12px; background: #F8FAFC; border-radius: 8px; border: 1px solid #E2E8F0;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 36px; height: 36px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                            <i class="fas fa-clock" style="color: #F59E0B !important;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-size: 20px; font-weight: 700; color: #1E293B; line-height: 1;"><?php echo $pending_orders; ?></div>
                                            <div style="font-size: 11px; color: #64748B; margin-top: 2px;">Pending</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="snapshot-item" style="padding: 12px; background: #F8FAFC; border-radius: 8px; border: 1px solid #E2E8F0;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 36px; height: 36px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                            <i class="fas fa-user-plus" style="color: #8B5CF6 !important;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-size: 20px; font-weight: 700; color: #1E293B; line-height: 1;"><?php echo $recent_registrations; ?></div>
                                            <div style="font-size: 11px; color: #64748B; margin-top: 2px;">New Reports</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Case Status Health -->
                        <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title-custom mb-0"><i class="fas fa-heartbeat" style="color: #10B981; margin-right: 8px;"></i>Case Status Health</h6>
                                <a href="admin_lost_items.php" class="btn-text">View All</a>
                            </div>
                            <div style="display: flex; gap: 16px; align-items: center;">
                                <!-- Health Score Circle -->
                                <div style="position: relative; width: 100px; height: 100px; flex-shrink: 0;">
                                    <svg width="100" height="100" style="transform: rotate(-90deg);">
                                        <circle cx="50" cy="50" r="40" fill="none" stroke="#E2E8F0" stroke-width="8"></circle>
                                        <circle cx="50" cy="50" r="40" fill="none" stroke="#10B981" stroke-width="8" 
                                                stroke-dasharray="<?php 
                                                    $health_percentage = $total_lost_items > 0 ? (($resolved_claims / $total_lost_items) * 100) : 0;
                                                    $circumference = 2 * 3.14159 * 40;
                                                    echo ($health_percentage / 100 * $circumference) . ' ' . $circumference;
                                                ?>" 
                                                stroke-linecap="round"></circle>
                                    </svg>
                                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                        <div style="font-size: 24px; font-weight: 700; color: #1E293B; line-height: 1;"><?php echo round($health_percentage); ?>%</div>
                                        <div style="font-size: 10px; color: #64748B; margin-top: 2px;">Health</div>
                                    </div>
                                </div>
                                
                                <!-- Health Stats -->
                                <div style="flex: 1;">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #F8FAFC; border-radius: 6px;">
                                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #10B981; flex-shrink: 0;"></div>
                                            <div style="flex: 1; font-size: 12px; color: #64748B;">Resolved</div>
                                            <div style="font-size: 13px; font-weight: 600; color: #1E293B;"><?php echo $resolved_claims; ?></div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #F8FAFC; border-radius: 6px;">
                                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #F59E0B; flex-shrink: 0;"></div>
                                            <div style="flex: 1; font-size: 12px; color: #64748B;">Pending</div>
                                            <div style="font-size: 13px; font-weight: 600; color: #1E293B;"><?php echo $pending_claims; ?></div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #F8FAFC; border-radius: 6px;">
                                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #EF4444; flex-shrink: 0;"></div>
                                            <div style="flex: 1; font-size: 12px; color: #64748B;">Total Items</div>
                                            <div style="font-size: 13px; font-weight: 600; color: #1E293B;"><?php echo $total_lost_items; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Export Reports -->
                        <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title-custom mb-0"><i class="fas fa-file-export" style="color: #3B82F6; margin-right: 8px;"></i>Export Reports</h6>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <button onclick="exportReport('sales')" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; width: 100%;" onmouseover="this.style.background='white'; this.style.borderColor='#CBD5E1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.08)';" onmouseout="this.style.background='#F8FAFC'; this.style.borderColor='#E2E8F0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <div style="width: 40px; height: 40px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 20px;">
                                        <i class="fas fa-chart-line" style="color: #3B82F6 !important;"></i>
                                    </div>
                                    <div style="flex: 1; text-align: left;">
                                        <div style="font-size: 13px; font-weight: 500; color: #1E293B; margin-bottom: 2px;">Sales Report</div>
                                        <div style="font-size: 11px; color: #64748B;">Export sales data</div>
                                    </div>
                                    <i class="fas fa-download" style="color: #64748B; font-size: 14px;"></i>
                                </button>
                                
                                <button onclick="exportReport('customers')" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; width: 100%;" onmouseover="this.style.background='white'; this.style.borderColor='#CBD5E1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.08)';" onmouseout="this.style.background='#F8FAFC'; this.style.borderColor='#E2E8F0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <div style="width: 40px; height: 40px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 20px;">
                                        <i class="fas fa-users" style="color: #10B981 !important;"></i>
                                    </div>
                                    <div style="flex: 1; text-align: left;">
                                        <div style="font-size: 13px; font-weight: 500; color: #1E293B; margin-bottom: 2px;">Customers Report</div>
                                        <div style="font-size: 11px; color: #64748B;">Export customer list</div>
                                    </div>
                                    <i class="fas fa-download" style="color: #64748B; font-size: 14px;"></i>
                                </button>
                                
                                <button onclick="exportReport('inventory')" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; width: 100%;" onmouseover="this.style.background='white'; this.style.borderColor='#CBD5E1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.08)';" onmouseout="this.style.background='#F8FAFC'; this.style.borderColor='#E2E8F0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <div style="width: 40px; height: 40px; border-radius: 8px; background: transparent !important; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 20px;">
                                        <i class="fas fa-boxes" style="color: #F59E0B !important;"></i>
                                    </div>
                                    <div style="flex: 1; text-align: left;">
                                        <div style="font-size: 13px; font-weight: 500; color: #1E293B; margin-bottom: 2px;">Inventory Report</div>
                                        <div style="font-size: 11px; color: #64748B;">Export stock data</div>
                                    </div>
                                    <i class="fas fa-download" style="color: #64748B; font-size: 14px;"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title-custom mb-0"><i class="fas fa-bolt" style="color: #F59E0B; margin-right: 8px;"></i>Quick Actions</h6>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                                <a href="admin_lost_items.php?action=add" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 12px; background: linear-gradient(135deg, #10B981 0%, #059669 100%); border-radius: 8px; text-decoration: none; color: white; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(16, 185, 129, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <i class="fas fa-plus" style="font-size: 24px;"></i>
                                    <span style="font-size: 12px; font-weight: 600; text-align: center;">Report Item</span>
                                </a>
                                
                                <a href="admin_lost_items.php?status=pending" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 12px; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); border-radius: 8px; text-decoration: none; color: white; transition: all 0.2s ease; position: relative;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(245, 158, 11, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <?php if ($pending_claims > 0): ?>
                                        <span style="position: absolute; top: 8px; right: 8px; background: #EF4444; color: white; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center;"><?php echo $pending_claims; ?></span>
                                    <?php endif; ?>
                                    <i class="fas fa-tasks" style="font-size: 24px;"></i>
                                    <span style="font-size: 12px; font-weight: 600; text-align: center;">Review Claims</span>
                                </a>
                                
                                <a href="admin_manage_customers.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 12px; background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%); border-radius: 8px; text-decoration: none; color: white; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <i class="fas fa-users" style="font-size: 24px;"></i>
                                    <span style="font-size: 12px; font-weight: 600; text-align: center;">Customers</span>
                                </a>
                                
                                <a href="admin_reports.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 12px; background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%); border-radius: 8px; text-decoration: none; color: white; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(139, 92, 246, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <i class="fas fa-chart-line" style="font-size: 24px;"></i>
                                    <span style="font-size: 12px; font-weight: 600; text-align: center;">Reports</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats & Alerts -->
            <div class="col-xl-4">
                <div class="content-card">
                    <!-- Popular Categories -->
                    <div class="card-header-custom">
                        <h6 class="card-title-custom mb-0"><i class="fas fa-list-check" style="color: #8B5CF6; margin-right: 8px;"></i>Case Summary</h6>
                        <a href="admin_lost_items.php" class="btn-text">Manage</a>
                    </div>
                    <div class="card-body-custom">
                        <div class="categories-list">
                            <div class="category-item">
                                <div class="category-icon" style="background: #3B82F620; color: #3B82F6;">
                                    <i class="fas fa-search"></i>
                                </div>
                                <div class="category-info">
                                    <div class="category-name">Total Items</div>
                                    <div class="category-meta">Currently tracked</div>
                                </div>
                                <div class="category-stats">
                                    <div class="category-revenue" style="color: #3B82F6;"><?php echo $total_lost_items; ?></div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <div class="category-icon" style="background: #10B98120; color: #10B981;">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="category-info">
                                    <div class="category-name">Resolved</div>
                                    <div class="category-meta">Successfully matched</div>
                                </div>
                                <div class="category-stats">
                                    <div class="category-revenue" style="color: #10B981;"><?php echo $resolved_claims; ?></div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <div class="category-icon" style="background: #F59E0B20; color: #F59E0B;">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="category-info">
                                    <div class="category-name">Pending</div>
                                    <div class="category-meta">Under review</div>
                                </div>
                                <div class="category-stats">
                                    <div class="category-revenue" style="color: #F59E0B;"><?php echo $pending_claims; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Status -->
                    <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                        <div class="card-header-custom" style="padding-top: 0;">
                            <h6 class="card-title-custom mb-0"><i class="fas fa-heartbeat" style="color: #EF4444; margin-right: 8px;"></i>System Status</h6>
                            <a href="admin_dashboard.php" class="btn-text">Details</a>
                        </div>
                        <div class="card-body-custom" style="padding-top: 12px;">
                        <div class="alert-summary">
                            <div class="alert-item critical">
                                <div class="alert-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="alert-content">
                                    <div class="alert-value">Active</div>
                                    <div class="alert-label">System Operational</div>
                                </div>
                            </div>
                            <div class="alert-item warning">
                                <div class="alert-icon">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="alert-content">
                                    <div class="alert-value"><?php echo $pending_claims; ?></div>
                                    <div class="alert-label">Pending Claims</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php
                        // Recent claims awaiting action
                        $recent_claims = [];
                        $lost_items_check = $conn->query("SHOW TABLES LIKE 'lost_items'");
                        if ($lost_items_check && $lost_items_check->num_rows > 0) {
                            $recent_claims_query = "SELECT * FROM lost_items WHERE status = 'pending' ORDER BY created_at DESC LIMIT 3";
                            $recent_claims_result = $conn->query($recent_claims_query);
                            if ($recent_claims_result) {
                                while ($row = $recent_claims_result->fetch_assoc()) {
                                    $recent_claims[] = $row;
                                }
                            }
                        }
                        
                        if (!empty($recent_claims)):
                        ?>
                        <div class="stock-list">
                            <?php foreach ($recent_claims as $claim): ?>
                            <div class="stock-item">
                                <div class="stock-info">
                                    <div class="stock-title"><?php echo htmlspecialchars(substr($claim['item_description'] ?? 'Unknown Item', 0, 30)); ?><?php echo strlen($claim['item_description'] ?? '') > 30 ? '...' : ''; ?></div>
                                    <div class="stock-author">Reported: <?php echo date('M d, Y', strtotime($claim['created_at'] ?? 'now')); ?></div>
                                </div>
                                <div class="stock-badge warning">
                                    Pending
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state-small">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending claims</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Claim Status Summary -->
                <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                    <div class="card-header-custom" style="padding-top: 0;">
                        <h6 class="card-title-custom mb-0"><i class="fas fa-tasks" style="color: #8B5CF6; margin-right: 8px;"></i>Claim Status</h6>
                    </div>
                    <div class="card-body-custom" style="padding-top: 12px;">
                        <div class="status-summary">
                            <?php
                            $claim_status_counts = [
                                'pending' => $pending_claims,
                                'resolved' => $resolved_claims,
                                'rejected' => 0
                            ];
                            
                            $lost_items_check = $conn->query("SHOW TABLES LIKE 'lost_items'");
                            if ($lost_items_check && $lost_items_check->num_rows > 0) {
                                $rejected_result = $conn->query("SELECT COUNT(*) as count FROM lost_items WHERE status = 'rejected'");
                                if ($rejected_result) {
                                    $claim_status_counts['rejected'] = $rejected_result->fetch_assoc()['count'];
                                }
                            }
                            ?>
                            <div class="status-item">
                                <div class="status-bar">
                                    <div class="status-progress" style="width: <?php echo ($claim_status_counts['pending'] > 0) ? '40%' : '0%'; ?> background: #F59E0B;"></div>
                                </div>
                                <div class="status-info">
                                    <span class="status-name">Pending</span>
                                    <span class="status-count"><?php echo $claim_status_counts['pending']; ?></span>
                                </div>
                            </div>
                            <div class="status-item">
                                <div class="status-bar">
                                    <div class="status-progress" style="width: <?php echo ($total_lost_items > 0 && $claim_status_counts['resolved'] > 0) ? (($claim_status_counts['resolved'] / $total_lost_items) * 100) : 0; ?>%; background: #10B981; "></div>
                                </div>
                                <div class="status-info">
                                    <span class="status-name">Resolved</span>
                                    <span class="status-count"><?php echo $claim_status_counts['resolved']; ?></span>
                                </div>
                            </div>
                            <div class="status-item">
                                <div class="status-bar">
                                    <div class="status-progress" style="width: <?php echo ($total_lost_items > 0 && $claim_status_counts['rejected'] > 0) ? (($claim_status_counts['rejected'] / $total_lost_items) * 100) : 0; ?>%; background: #EF4444; "></div>
                                </div>
                                <div class="status-info">
                                    <span class="status-name">Rejected</span>
                                    <span class="status-count"><?php echo $claim_status_counts['rejected']; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="mt-4 pt-3" style="border-top: 1px solid #E2E8F0;">
                    <div class="card-header-custom" style="padding-top: 0;">
                        <h6 class="card-title-custom mb-0"><i class="fas fa-history" style="color: #8B5CF6; margin-right: 8px;"></i>Recent Activity</h6>
                        <?php if ($is_super_admin): ?>
                        <a href="admin_logs.php" class="btn-text">View All</a>
                        <?php else: ?>
                        <span class="btn-text" style="cursor: not-allowed; opacity: 0.5;" title="Access Denied: Only Super Administrator can view Admin Logs.">
                            <i class="fas fa-lock me-1"></i>Restricted
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body-custom" style="padding-top: 12px;">
                        <div class="activity-feed">
                            <?php if (!empty($recent_activities)): ?>
                                <?php 
                                $activity_limit = 5; // Show only 5 activities in sidebar
                                foreach (array_slice($recent_activities, 0, $activity_limit) as $activity): 
                                    $icon = '';
                                    $color = '';
                                    switch($activity['type']) {
                                        case 'customer':
                                            $icon = 'fa-user-plus';
                                            $color = '#10B981';
                                            break;
                                        case 'book':
                                            $icon = 'fa-book';
                                            $color = '#3B82F6';
                                            break;
                                        case 'order':
                                            $icon = 'fa-shopping-cart';
                                            $color = '#F59E0B';
                                            break;
                                        default:
                                            $icon = 'fa-circle';
                                            $color = '#94A3B8';
                                    }
                                    
                                    $time_diff = time() - strtotime($activity['activity_time']);
                                    if ($time_diff < 60) $time_ago = 'Just now';
                                    elseif ($time_diff < 3600) $time_ago = floor($time_diff / 60) . 'm ago';
                                    elseif ($time_diff < 86400) $time_ago = floor($time_diff / 3600) . 'h ago';
                                    else $time_ago = date('M d', strtotime($activity['activity_time']));
                                ?>
                                <div class="activity-item">
                                    <div class="activity-icon" style="background: transparent !important; color: <?php echo $color; ?>;">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text">
                                            <strong><?php echo htmlspecialchars(substr($activity['name'], 0, 20)); ?><?php echo strlen($activity['name']) > 20 ? '...' : ''; ?></strong> 
                                            <?php echo $activity['action']; ?>
                                        </div>
                                        <div class="activity-time"><?php echo $time_ago; ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state-small">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scroll to Top Button -->
    <button class="scroll-top-btn" id="scrollTopBtn" onclick="scrollToTop()" title="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>
    
    <!-- Quick Actions Floating Menu -->
    <div class="quick-actions-fab dashboard-removed">
        <div class="fab-menu" id="fabMenu">
            <a href="admin_lost_items.php?action=add" class="fab-item add-book">
                <i class="fas fa-plus"></i>
                <span>Report Item</span>
            </a>
            <a href="admin_manage_orders.php?status=pending" class="fab-item manage-orders">
                <i class="fas fa-tasks"></i>
                <span>Process Orders</span>
                <?php if ($pending_orders > 0): ?>
                    <span class="fab-badge"><?php echo $pending_orders; ?></span>
                <?php endif; ?>
            </a>
            <a href="admin_manage_customers.php" class="fab-item view-customers">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="admin_reports.php" class="fab-item reports">
                <i class="fas fa-chart-line"></i>
                <span>Reports</span>
            </a>
        </div>
        <button class="fab-main" id="fabMain" onclick="toggleFabMenu()" title="Quick Actions">
            <i class="fas fa-plus"></i>
        </button>
    </div>
    
    <!-- Keyboard Shortcuts Help Button -->
    <button class="shortcuts-help-btn" onclick="toggleShortcutsPanel()" title="Keyboard Shortcuts (Press ?)">
        <i class="fas fa-keyboard"></i>
    </button>
    
    <!-- Keyboard Shortcuts Panel -->
    <div class="shortcuts-panel" id="shortcutsPanel">
        <div class="shortcuts-header">
            <h6>Keyboard Shortcuts</h6>
            <button class="shortcuts-close" onclick="toggleShortcutsPanel()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="shortcuts-body">
            <div class="shortcut-group">
                <div class="shortcut-group-title">Navigation</div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>R</kbd>
                    </div>
                    <div class="shortcut-desc">Refresh orders</div>
                </div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>N</kbd>
                    </div>
                    <div class="shortcut-desc">Go to orders</div>
                </div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>B</kbd>
                    </div>
                    <div class="shortcut-desc">Go to books</div>
                </div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>C</kbd>
                    </div>
                    <div class="shortcut-desc">Go to customers</div>
                </div>
            </div>
            
            <div class="shortcut-group">
                <div class="shortcut-group-title">Actions</div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>Esc</kbd>
                    </div>
                    <div class="shortcut-desc">Close notifications</div>
                </div>
                <div class="shortcut-item">
                    <div class="shortcut-keys">
                        <kbd>?</kbd>
                    </div>
                    <div class="shortcut-desc">Show this help</div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../Bootstrap/bootstrap-5.3.3-dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function uploadProfileImage(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validate file size (max 5MB)
                const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                if (file.size > maxSize) {
                    showNotification('File too large. Maximum size is 5MB.', 'error');
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    showNotification('Invalid file type. Only JPG, PNG, and GIF are allowed.', 'error');
                    return;
                }
                
                const formData = new FormData();
                formData.append('profile_image', file);
                formData.append('admin_id', '<?php echo $admin_id; ?>');
                
                // Show loading state
                const avatar = document.querySelector('.user-avatar');
                avatar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                // Use XMLHttpRequest to upload
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_profile_image.php', true);
                xhr.timeout = 30000; // 30 second timeout
                
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        try {
                            // Check if we got a response
                            if (!xhr.responseText) {
                                throw new Error('No response from server');
                            }
                            
                            // Try to parse JSON
                            let data;
                            try {
                                data = JSON.parse(xhr.responseText);
                            } catch (parseError) {
                                console.error('Parse error:', parseError);
                                console.error('Response text:', xhr.responseText);
                                throw new Error('Invalid server response');
                            }
                            
                            if (data.success) {
                                // Update avatar with new image
                                avatar.innerHTML = '<img src="../uploads/profiles/' + data.filename + '?t=' + Date.now() + '" alt="Profile">';
                                showNotification('Profile photo updated successfully!', 'success');
                            } else {
                                showNotification(data.message || 'Error uploading photo', 'error');
                                // Only reload if it's a serious error
                                if (data.message && !data.message.includes('File too large') && !data.message.includes('Invalid file type')) {
                                    setTimeout(() => location.reload(), 3000);
                                }
                            }
                        } catch (e) {
                            console.error('Error:', e);
                            console.error('Response:', xhr.responseText);
                            showNotification('Error processing response: ' + e.message, 'error');
                            setTimeout(() => location.reload(), 3000);
                        }
                    }
                };
                
                xhr.onerror = function() {
                    console.error('Upload failed');
                    showNotification('Network error. Please try again.', 'error');
                    setTimeout(() => location.reload(), 3000);
                };
                
                xhr.ontimeout = function() {
                    console.error('Upload timeout');
                    showNotification('Upload timeout. Please try again.', 'error');
                    setTimeout(() => location.reload(), 3000);
                };
                
                try {
                    xhr.send(formData);
                } catch (e) {
                    console.error('Send error:', e);
                    showNotification('Error sending request', 'error');
                    setTimeout(() => location.reload(), 3000);
                }
            }
        }
        
        // Notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(notification);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }
        
        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function(dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
            
            // Load theme preference
            const savedTheme = localStorage.getItem('adminTheme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                updateThemeIcon();
            }
            
            // Remove welcome toast after animation
            const welcomeToast = document.getElementById('welcomeToast');
            if (welcomeToast) {
                setTimeout(() => {
                    welcomeToast.remove();
                }, 4000);
            }
            
            // Scroll to top button functionality
            const scrollTopBtn = document.getElementById('scrollTopBtn');
            const mainContent = document.getElementById('mainContent');
            
            if (mainContent && scrollTopBtn) {
                mainContent.addEventListener('scroll', function() {
                    if (mainContent.scrollTop > 300) {
                        scrollTopBtn.classList.add('show');
                    } else {
                        scrollTopBtn.classList.remove('show');
                    }
                });
            }
            
            // Keyboard shortcut: Press 'R' to refresh orders
            document.addEventListener('keydown', function(e) {
                if (e.key === 'r' || e.key === 'R') {
                    if (!e.ctrlKey && !e.metaKey && !e.altKey) {
                        const activeElement = document.activeElement;
                        if (activeElement.tagName !== 'INPUT' && activeElement.tagName !== 'TEXTAREA') {
                            e.preventDefault();
                            refreshOrdersManual();
                        }
                    }
                }
            });
        });
        
        // Scroll to top function
        function scrollToTop() {
            const mainContent = document.getElementById('mainContent');
            if (mainContent) {
                mainContent.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        }
        
        // Toggle FAB menu
        function toggleFabMenu() {
            const fabMenu = document.getElementById('fabMenu');
            const fabMain = document.getElementById('fabMain');
            
            fabMenu.classList.toggle('show');
            fabMain.classList.toggle('active');
        }
        
        // Close FAB menu when clicking outside
        document.addEventListener('click', function(e) {
            const fabContainer = document.querySelector('.quick-actions-fab');
            const fabMenu = document.getElementById('fabMenu');
            const fabMain = document.getElementById('fabMain');
            
            if (fabContainer && !fabContainer.contains(e.target)) {
                fabMenu.classList.remove('show');
                fabMain.classList.remove('active');
            }
        });
        
        // Make order items clickable
        document.addEventListener('DOMContentLoaded', function() {
            const orderItems = document.querySelectorAll('.order-item');
            orderItems.forEach(item => {
                item.style.cursor = 'pointer';
                item.addEventListener('click', function() {
                    const orderId = this.getAttribute('data-order-id');
                    if (orderId) {
                        window.location.href = 'admin_manage_orders.php?id=' + orderId;
                    }
                });
            });
            
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
        });
        
        // Filter Orders Function
        function filterOrders(status) {
            const orderItems = document.querySelectorAll('.order-item');
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterBtns.forEach(btn => {
                if (btn.getAttribute('data-status') === status) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // Filter orders
            orderItems.forEach(item => {
                const orderStatus = item.querySelector('.order-status');
                if (!orderStatus) return;
                
                const statusText = orderStatus.textContent.trim().toLowerCase();
                
                if (status === 'all' || statusText === status) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // Toggle Dark Mode
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('adminTheme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        }
        
        function updateThemeIcon() {
            const themeToggle = document.getElementById('themeToggle');
            if (!themeToggle) return;
            const icon = themeToggle.querySelector('i');
            const isDark = document.body.classList.contains('dark-mode');
            
            if (isDark) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
                themeToggle.title = 'Toggle Light Mode';
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
                themeToggle.title = 'Toggle Dark Mode';
            }
        }
        
        // Manual refresh function for the button
        function refreshOrdersManual() {
            const refreshBtn = document.getElementById('refreshBtn');
            if (!refreshBtn) return;
            
            // Add refreshing class for animation
            refreshBtn.classList.add('refreshing');
            
            // Simulate refresh (in real implementation, this would fetch new data)
            setTimeout(() => {
                refreshBtn.classList.remove('refreshing');
                
                // Show success notification
                const notification = document.createElement('div');
                notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 80px; right: 20px; z-index: 9999; min-width: 250px;';
                notification.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i>
                    Orders refreshed
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 2000);
                
                // If refreshOrders function exists from the real-time system, call it
                if (typeof refreshOrders === 'function') {
                    refreshOrders();
                }
            }, 800);
        }
            
        // Toggle sidebar function - works with both buttons
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggleBtn = document.querySelector('.toggle-sidebar-btn');
            const headerToggleBtn = document.querySelector('.toggle-sidebar');
            
            const isCollapsed = sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            if (sidebarToggleBtn) {
                const sidebarIcon = sidebarToggleBtn.querySelector('i');
                sidebarToggleBtn.style.transform = isCollapsed ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
                
                if (sidebarIcon) {
                    sidebarIcon.style.opacity = '0';
                    setTimeout(() => {
                        if (isCollapsed) {
                            sidebarIcon.classList.remove('fa-chevron-left');
                            sidebarIcon.classList.add('fa-chevron-right');
                        } else {
                            sidebarIcon.classList.remove('fa-chevron-right');
                            sidebarIcon.classList.add('fa-chevron-left');
                        }
                        sidebarIcon.style.opacity = '1';
                    }, 200);
                }
                createProfessionalRipple(sidebarToggleBtn);
            }
            
            if (headerToggleBtn) {
                createProfessionalRipple(headerToggleBtn);
            }
            
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }
        
        function createProfessionalRipple(button) {
            const ripple = document.createElement('span');
            ripple.classList.add('professional-ripple');
            button.appendChild(ripple);
            
            const rect = button.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = '50%';
            ripple.style.top = '50%';
            ripple.style.transform = 'translate(-50%, -50%)';
            
            setTimeout(() => ripple.remove(), 800);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarCollapsed = localStorage.getItem('sidebarCollapsed');
            if (sidebarCollapsed === 'true') {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                const sidebarToggleBtn = document.querySelector('.toggle-sidebar-btn');
                
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                
                if (sidebarToggleBtn) {
                    const sidebarIcon = sidebarToggleBtn.querySelector('i');
                    sidebarToggleBtn.style.transform = 'translateY(-50%) rotate(180deg)';
                    if (sidebarIcon) {
                        sidebarIcon.classList.remove('fa-chevron-left');
                        sidebarIcon.classList.add('fa-chevron-right');
                    }
                }
            }
        });

        // Toggle sidebar function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggleBtn = document.querySelector('.toggle-sidebar-btn');
            const headerToggleBtn = document.querySelector('.toggle-sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
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
                const isCollapsed = sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                
                if (sidebarToggleBtn) {
                    const icon = sidebarToggleBtn.querySelector('i');
                    sidebarToggleBtn.style.transform = isCollapsed ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
                    if (icon) {
                        icon.style.opacity = '0';
                        setTimeout(() => {
                            icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
                            icon.style.opacity = '1';
                        }, 200);
                    }
                }
                localStorage.setItem('sidebarCollapsed', isCollapsed);
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
        
        document.addEventListener('DOMContentLoaded', function() {
            // Restore sidebar state on desktop only
            if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === 'true') {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                const btn = document.querySelector('.toggle-sidebar-btn');
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (btn) {
                    btn.style.transform = 'translateY(-50%) rotate(180deg)';
                    const icon = btn.querySelector('i');
                    if (icon) icon.className = 'fas fa-chevron-right';
                }
            }
            
            // Setup mobile sidebar handlers
            const overlay = document.getElementById('sidebarOverlay');
            const sidebar = document.getElementById('sidebar');
            
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
                    const sidebar = document.getElementById('sidebar');
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
        
        // Real-Time Orders Monitoring
        let ordersRefreshInterval;
        let lastOrdersUpdate = Date.now();
        
        function refreshOrders() {
            const refreshBtn = document.getElementById('refreshBtn');
            const indicator = document.getElementById('realtimeIndicator');
            const tbody = document.getElementById('ordersTableBody');
            
            // Show loading state
            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Refreshing...';
            }
            
            fetch('get_realtime_orders.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.orders) {
                        updateOrdersTable(data.orders);
                        lastOrdersUpdate = Date.now();
                        updateLastUpdateTime();
                        
                        // Update indicator
                        if (indicator) {
                            indicator.className = 'badge bg-success';
                            indicator.innerHTML = '<i class="fas fa-circle" style="font-size: 8px;"></i> Live';
                        }
                    } else {
                        console.error('Failed to fetch orders:', data.message);
                        if (indicator) {
                            indicator.className = 'badge bg-danger';
                            indicator.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching orders:', error);
                    if (indicator) {
                        indicator.className = 'badge bg-warning';
                        indicator.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Offline';
                    }
                })
                .finally(() => {
                    if (refreshBtn) {
                        refreshBtn.disabled = false;
                        refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                    }
                });
        }
        
        function updateOrdersTable(orders) {
            const tbody = document.getElementById('ordersTableBody');
            if (!tbody) return;
            
            if (orders.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                            <p class="mb-0">No orders yet</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = orders.map(order => {
                const statusClass = getStatusClass(order.status);
                const isNewCancellation = order.is_new_cancellation;
                const isNewCompletion = order.is_new_completion;
                
                let rowClass = '';
                let newBadge = '';
                
                if (isNewCancellation) {
                    rowClass = 'table-danger';
                    newBadge = '<span class="badge bg-danger ms-2 pulse-animation">NEW CANCELLATION</span>';
                } else if (isNewCompletion) {
                    rowClass = 'table-success';
                    newBadge = '<span class="badge bg-success ms-2 pulse-animation">COMPLETED</span>';
                }
                
                return `
                    <tr data-order-id="${order.id}" class="${rowClass}" style="animation: fadeIn 0.3s;">
                        <td><strong>#${escapeHtml(order.order_number)}</strong>${newBadge}</td>
                        <td>
                            <div class="fw-semibold">${escapeHtml(order.customer_name)}</div>
                            <small class="text-muted">${escapeHtml(order.customer_email)}</small>
                        </td>
                        <td><span class="badge bg-secondary">${order.item_count} item${order.item_count > 1 ? 's' : ''}</span></td>
                        <td><strong>₱${parseFloat(order.total_amount).toFixed(2)}</strong></td>
                        <td>
                            <span class="badge bg-${statusClass}">${order.status.charAt(0).toUpperCase() + order.status.slice(1)}</span>
                            ${order.status === 'cancelled' && order.cancellation_reason ? 
                                `<br><small class="text-muted" title="${escapeHtml(order.cancellation_reason)}">
                                    <i class="fas fa-info-circle"></i> ${escapeHtml(order.cancellation_reason.substring(0, 30))}${order.cancellation_reason.length > 30 ? '...' : ''}
                                </small>` : ''}
                        </td>
                        <td>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i>
                                ${order.time_ago}
                            </small>
                        </td>
                        <td>
                            <a href="admin_manage_orders.php?id=${order.id}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                `;
            }).join('');
            
            // Play notification sound for new cancellations or completions
            const hasNewCancellation = orders.some(order => order.is_new_cancellation);
            const hasNewCompletion = orders.some(order => order.is_new_completion);
            
            if (hasNewCancellation) {
                playNotificationSound('error'); // Different tone for cancellation
            } else if (hasNewCompletion) {
                playNotificationSound('success'); // Different tone for completion
            }
        }
        
        function getStatusClass(status) {
            const statusMap = {
                'delivered': 'success',
                'pending': 'warning',
                'processing': 'info',
                'shipped': 'primary',
                'cancelled': 'danger'
            };
            return statusMap[status] || 'secondary';
        }
        
        function updateLastUpdateTime() {
            const timeElement = document.getElementById('lastUpdateTime');
            if (!timeElement) return;
            
            const diff = Math.floor((Date.now() - lastOrdersUpdate) / 1000);
            let timeText;
            
            if (diff < 10) {
                timeText = 'Updated just now';
            } else if (diff < 60) {
                timeText = `Updated ${diff}s ago`;
            } else {
                const mins = Math.floor(diff / 60);
                timeText = `Updated ${mins}m ago`;
            }
            
            timeElement.textContent = timeText;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Play notification sound for new cancellations or completions
        function playNotificationSound(type = 'success') {
            // Create a simple beep sound using Web Audio API
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                // Different frequencies for different notification types
                if (type === 'error') {
                    // Lower frequency for cancellations (warning tone)
                    oscillator.frequency.value = 400;
                    oscillator.type = 'square';
                } else {
                    // Higher frequency for completions (success tone)
                    oscillator.frequency.value = 800;
                    oscillator.type = 'sine';
                }
                
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.5);
                
                // For success, play a second higher note
                if (type === 'success') {
                    setTimeout(() => {
                        const oscillator2 = audioContext.createOscillator();
                        const gainNode2 = audioContext.createGain();
                        
                        oscillator2.connect(gainNode2);
                        gainNode2.connect(audioContext.destination);
                        
                        oscillator2.frequency.value = 1000;
                        oscillator2.type = 'sine';
                        
                        gainNode2.gain.setValueAtTime(0.3, audioContext.currentTime);
                        gainNode2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
                        
                        oscillator2.start(audioContext.currentTime);
                        oscillator2.stop(audioContext.currentTime + 0.3);
                    }, 200);
                }
            } catch (e) {
                console.log('Audio notification not supported');
            }
        }
        
        // Start real-time monitoring
        if (document.getElementById('ordersTableBody')) {
            // Refresh every 30 seconds
            ordersRefreshInterval = setInterval(refreshOrders, 30000);
            
            // Update time display every 10 seconds
            setInterval(updateLastUpdateTime, 10000);
            
            // Initial refresh after 5 seconds
            setTimeout(refreshOrders, 5000);
        }
        
        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            if (ordersRefreshInterval) {
                clearInterval(ordersRefreshInterval);
            }
        });
        
        // Add pulse animation for live indicator
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .pulse-animation {
                animation: pulse 1.5s ease-in-out infinite;
            }
            .table-danger {
                background-color: rgba(220, 53, 69, 0.1) !important;
                border-left: 3px solid #dc3545 !important;
            }
            .table-success {
                background-color: rgba(16, 185, 129, 0.1) !important;
                border-left: 3px solid #10b981 !important;
            }
        `;
        document.head.appendChild(style);
        
        // Sales Chart
        const salesChartCanvas = document.getElementById('salesChart');
        if (salesChartCanvas) {
            const salesData = <?php echo json_encode($sales_chart_data); ?>;
            
            // Prepare data for chart
            const labels = [];
            const orderData = [];
            const revenueData = [];
            
            // Fill in missing days
            for (let i = 6; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                const dateStr = date.toISOString().split('T')[0];
                labels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                
                const dayData = salesData.find(d => d.date === dateStr);
                orderData.push(dayData ? parseInt(dayData.order_count) : 0);
                revenueData.push(dayData ? parseFloat(dayData.revenue) : 0);
            }
            
            const ctx = salesChartCanvas.getContext('2d');
            window.salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Orders',
                            data: orderData,
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Revenue (₱)',
                            data: revenueData,
                            borderColor: '#10B981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#E2E8F0',
                            borderWidth: 1
                        }
                    },
                    layout: {
                        padding: {
                            left: 10,
                            right: 10,
                            top: 10,
                            bottom: 10
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Orders'
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Revenue (₱)'
                            },
                            grid: {
                                drawOnChartArea: false,
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });
            
            // Update chart theme on dark mode toggle
            const originalToggleTheme = window.toggleTheme;
            window.toggleTheme = function() {
                originalToggleTheme();
                const isDark = document.body.classList.contains('dark-mode');
                if (window.salesChart) {
                    window.salesChart.options.scales.y.grid.color = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
                    window.salesChart.options.scales.y1.grid.color = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
                    window.salesChart.options.scales.x.grid.color = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
                    window.salesChart.options.scales.y.ticks.color = isDark ? '#94A3B8' : '#64748B';
                    window.salesChart.options.scales.y1.ticks.color = isDark ? '#94A3B8' : '#64748B';
                    window.salesChart.options.scales.x.ticks.color = isDark ? '#94A3B8' : '#64748B';
                    window.salesChart.options.scales.y.title.color = isDark ? '#94A3B8' : '#64748B';
                    window.salesChart.options.scales.y1.title.color = isDark ? '#94A3B8' : '#64748B';
                    window.salesChart.options.plugins.legend.labels.color = isDark ? '#F1F5F9' : '#1E293B';
                    window.salesChart.update();
                }
            };
        }
        
        // Handle window resize for responsive charts
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (window.salesChart) {
                    window.salesChart.resize();
                }
            }, 250);
        });
        
        // Also resize chart when sidebar is toggled
        const originalToggleSidebar = window.toggleSidebar;
        if (originalToggleSidebar) {
            window.toggleSidebar = function() {
                originalToggleSidebar();
                setTimeout(() => {
                    if (window.salesChart) {
                        window.salesChart.resize();
                    }
                }, 350);
            };
        }
        
        // Export Report Functions
        function exportReport(type) {
            let url = '';
            switch(type) {
                case 'sales':
                    url = 'export_sales_report.php';
                    break;
                case 'customers':
                    url = 'export_customers.php';
                    break;
                case 'inventory':
                    url = 'export_inventory.php';
                    break;
            }
            
            if (url) {
                // Show loading notification
                showNotification('Generating report...', 'info');
                
                // Create temporary link and trigger download
                const link = document.createElement('a');
                link.href = url;
                link.download = `${type}_report_${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                setTimeout(() => {
                    showNotification('Report downloaded successfully!', 'success');
                }, 1000);
            }
        }
        
        // Keyboard Shortcuts
        document.addEventListener('keydown', function(e) {
            // ? key - Show keyboard shortcuts
            if (e.key === '?' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                toggleShortcutsPanel();
            }
            
            // R key - Refresh orders
            if (e.key === 'r' || e.key === 'R') {
                if (!e.target.matches('input, textarea')) {
                    e.preventDefault();
                    refreshOrdersManual();
                }
            }
            
            // N key - New order (navigate to orders page)
            if (e.key === 'n' || e.key === 'N') {
                if (!e.target.matches('input, textarea')) {
                    e.preventDefault();
                    window.location.href = 'admin_manage_orders.php';
                }
            }
            
            // L key - Lost Items management
            if (e.key === 'l' || e.key === 'L') {
                if (!e.target.matches('input, textarea')) {
                    e.preventDefault();
                    window.location.href = 'admin_lost_items.php';
                }
            }
            
            // C key - Customers
            if (e.key === 'c' || e.key === 'C') {
                if (!e.target.matches('input, textarea')) {
                    e.preventDefault();
                    window.location.href = 'admin_manage_customers.php';
                }
            }
            
            // Escape key - Close notifications and shortcuts panel
            if (e.key === 'Escape') {
                const bell = document.getElementById('notificationBell');
                if (bell && bell.classList.contains('active')) {
                    bell.classList.remove('active');
                }
                
                const panel = document.getElementById('shortcutsPanel');
                if (panel && panel.classList.contains('active')) {
                    toggleShortcutsPanel();
                }
            }
        });
        
        // Toggle Shortcuts Panel
        function toggleShortcutsPanel() {
            const panel = document.getElementById('shortcutsPanel');
            panel.classList.toggle('active');
            
            // Add/remove overlay
            let overlay = document.querySelector('.shortcuts-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'shortcuts-overlay';
                overlay.onclick = toggleShortcutsPanel;
                document.body.appendChild(overlay);
            }
            overlay.classList.toggle('active');
        }
        
        // Show keyboard shortcuts hint on first load
        if (!localStorage.getItem('keyboardHintsShown')) {
            setTimeout(() => {
                showNotification('💡 Tip: Press R to refresh, N for orders, B for books, C for customers', 'info', 5000);
                localStorage.setItem('keyboardHintsShown', 'true');
            }, 3000);
        }
    </script>
    
    <!-- Book Loading Animation System -->
    <script src="../js/book_loading_animation.js"></script>
    
    <?php include 'block_notification_checker.php'; ?>
<script src="../js/admin_search_results.js"></script>
</body>
</html>

