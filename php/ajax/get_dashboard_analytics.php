<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    $analytics = [];
    
    // 1. REVENUE ANALYTICS
    $revenue_query = $conn->query("
        SELECT 
            COALESCE(SUM(final_amount), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN final_amount ELSE 0 END), 0) as today_revenue,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN final_amount ELSE 0 END), 0) as monthly_revenue,
            COUNT(*) as total_orders,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_orders,
            COUNT(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 END) as monthly_orders
        FROM orders 
        WHERE payment_status = 'paid'
    ");
    $revenue_data = $revenue_query->fetch_assoc();
    
    $analytics['revenue'] = [
        'total' => floatval($revenue_data['total_revenue']),
        'today' => floatval($revenue_data['today_revenue']),
        'monthly' => floatval($revenue_data['monthly_revenue']),
        'total_orders' => intval($revenue_data['total_orders']),
        'today_orders' => intval($revenue_data['today_orders']),
        'monthly_orders' => intval($revenue_data['monthly_orders'])
    ];
    
    // 2. INVENTORY ANALYTICS
    $inventory_query = $conn->query("
        SELECT 
            COUNT(*) as total_books,
            COUNT(CASE WHEN stock_quantity = 0 THEN 1 END) as out_of_stock,
            COUNT(CASE WHEN stock_quantity > 0 AND stock_quantity <= 5 THEN 1 END) as low_stock,
            COALESCE(SUM(stock_quantity), 0) as total_stock_units
        FROM books 
        WHERE is_active = 1
    ");
    $inventory_data = $inventory_query->fetch_assoc();
    
    $analytics['inventory'] = [
        'total_books' => intval($inventory_data['total_books']),
        'out_of_stock' => intval($inventory_data['out_of_stock']),
        'low_stock' => intval($inventory_data['low_stock']),
        'total_units' => intval($inventory_data['total_stock_units'])
    ];
    
    // 3. TOP SELLING BOOKS
    $top_books_query = $conn->query("
        SELECT 
            b.id,
            b.title,
            b.author,
            b.cover_image,
            b.price,
            b.stock_quantity,
            COUNT(oi.id) as order_count,
            SUM(oi.quantity) as total_sold,
            SUM(oi.total_price) as total_revenue
        FROM books b
        INNER JOIN order_items oi ON b.id = oi.book_id
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.payment_status = 'paid'
        GROUP BY b.id
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    
    $top_books = [];
    while ($book = $top_books_query->fetch_assoc()) {
        $top_books[] = [
            'id' => intval($book['id']),
            'title' => $book['title'],
            'author' => $book['author'],
            'cover_image' => $book['cover_image'],
            'price' => floatval($book['price']),
            'stock' => intval($book['stock_quantity']),
            'orders' => intval($book['order_count']),
            'sold' => intval($book['total_sold']),
            'revenue' => floatval($book['total_revenue'])
        ];
    }
    $analytics['top_books'] = $top_books;
    
    // 4. LOW STOCK ALERTS
    $low_stock_query = $conn->query("
        SELECT 
            id, title, author, stock_quantity, price, cover_image
        FROM books 
        WHERE stock_quantity > 0 AND stock_quantity <= 5 AND is_active = 1
        ORDER BY stock_quantity ASC
        LIMIT 10
    ");
    
    $low_stock_books = [];
    while ($book = $low_stock_query->fetch_assoc()) {
        $low_stock_books[] = [
            'id' => intval($book['id']),
            'title' => $book['title'],
            'author' => $book['author'],
            'stock' => intval($book['stock_quantity']),
            'price' => floatval($book['price']),
            'cover_image' => $book['cover_image']
        ];
    }
    $analytics['low_stock_books'] = $low_stock_books;
    
    // 5. SALES PER MONTH (Last 12 months)
    $monthly_sales_query = $conn->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            DATE_FORMAT(created_at, '%b %Y') as month_label,
            COUNT(*) as order_count,
            SUM(final_amount) as revenue
        FROM orders
        WHERE payment_status = 'paid'
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    
    $monthly_sales = [];
    while ($month = $monthly_sales_query->fetch_assoc()) {
        $monthly_sales[] = [
            'month' => $month['month'],
            'label' => $month['month_label'],
            'orders' => intval($month['order_count']),
            'revenue' => floatval($month['revenue'])
        ];
    }
    $analytics['monthly_sales'] = $monthly_sales;
    
    // 6. ORDERS PER DAY (Last 30 days)
    $daily_orders_query = $conn->query("
        SELECT 
            DATE(created_at) as order_date,
            DATE_FORMAT(created_at, '%b %d') as date_label,
            COUNT(*) as order_count,
            SUM(final_amount) as revenue
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY order_date ASC
    ");
    
    $daily_orders = [];
    while ($day = $daily_orders_query->fetch_assoc()) {
        $daily_orders[] = [
            'date' => $day['order_date'],
            'label' => $day['date_label'],
            'orders' => intval($day['order_count']),
            'revenue' => floatval($day['revenue'])
        ];
    }
    $analytics['daily_orders'] = $daily_orders;
    
    // 7. CATEGORY DISTRIBUTION
    $category_query = $conn->query("
        SELECT 
            COALESCE(genre, 'Uncategorized') as category,
            COUNT(*) as book_count,
            SUM(stock_quantity) as total_stock
        FROM books
        WHERE is_active = 1
        GROUP BY genre
        ORDER BY book_count DESC
        LIMIT 10
    ");
    
    $categories = [];
    while ($cat = $category_query->fetch_assoc()) {
        $categories[] = [
            'name' => $cat['category'],
            'count' => intval($cat['book_count']),
            'stock' => intval($cat['total_stock'])
        ];
    }
    $analytics['categories'] = $categories;
    
    // 8. NOTIFICATIONS
    $notifications = [];
    
    // New orders today
    $new_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
    if ($new_orders > 0) {
        $notifications[] = [
            'type' => 'order',
            'icon' => 'fa-shopping-cart',
            'color' => 'success',
            'title' => 'New Orders',
            'message' => "$new_orders new order(s) today",
            'time' => 'Today'
        ];
    }
    
    // Pending approvals
    $pending = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 0")->fetch_assoc()['count'];
    if ($pending > 0) {
        $notifications[] = [
            'type' => 'approval',
            'icon' => 'fa-user-check',
            'color' => 'warning',
            'title' => 'Pending Approvals',
            'message' => "$pending customer(s) awaiting approval",
            'time' => 'Pending'
        ];
    }
    
    // Low stock alert
    if ($inventory_data['low_stock'] > 0) {
        $notifications[] = [
            'type' => 'stock',
            'icon' => 'fa-exclamation-triangle',
            'color' => 'danger',
            'title' => 'Low Stock Alert',
            'message' => "{$inventory_data['low_stock']} book(s) running low",
            'time' => 'Alert'
        ];
    }
    
    // New customers today
    $new_customers = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
    if ($new_customers > 0) {
        $notifications[] = [
            'type' => 'customer',
            'icon' => 'fa-user-plus',
            'color' => 'info',
            'title' => 'New Customers',
            'message' => "$new_customers new customer(s) registered",
            'time' => 'Today'
        ];
    }
    
    $analytics['notifications'] = $notifications;
    
    // 9. SECURITY METRICS
    $security_query = $conn->query("
        SELECT 
            COUNT(CASE WHEN action = 'login_failed' THEN 1 END) as failed_logins,
            COUNT(CASE WHEN action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as failed_logins_24h,
            COUNT(DISTINCT ip_address) as unique_ips,
            COUNT(CASE WHEN action = 'password_changed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as password_changes_week
        FROM admin_activity_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $security_data = $security_query->fetch_assoc();
    
    $analytics['security'] = [
        'failed_logins_total' => intval($security_data['failed_logins']),
        'failed_logins_24h' => intval($security_data['failed_logins_24h']),
        'unique_ips' => intval($security_data['unique_ips']),
        'password_changes_week' => intval($security_data['password_changes_week'])
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $analytics
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
