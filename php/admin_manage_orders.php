<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once '../DB/conn.php';
require_once 'includes/pagination_helper.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$current_role = $stmt->get_result()->fetch_assoc()['role'];

// Both admin and super_admin can access
if (!in_array($current_role, ['admin', 'super_admin'])) {
    header('Location: unified_admin_dashboard.php');
    exit();
}

$message = '';
$message_type = '';
$_SESSION['order_review_token'] ??= bin2hex(random_bytes(32));

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['order_review_token'], account_input('order_token', false))) {
    $message = 'This order form expired. Reload the page and try again.';
    $message_type = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $order_id = account_positive_integer($_POST['order_id'] ?? null);
        $new_status = account_input('status');
        
        if ($order_id === null || !in_array($new_status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'], true)) {
            http_response_code(400);
            exit('Choose a valid order and status.');
        }

        // Get order and customer details before update
        $order_stmt = $conn->prepare("
            SELECT o.order_number, o.user_id, o.status as old_status,
                   i.email as customer_email, 
                   COALESCE(i.full_name, CONCAT(i.firstname, ' ', i.lastname)) as customer_name
            FROM orders o
            LEFT JOIN infos i ON o.user_id = i.id
            WHERE o.id = ?
        ");
        $order_stmt->bind_param("i", $order_id);
        $order_stmt->execute();
        $order_data = $order_stmt->get_result()->fetch_assoc();
        
        // Update order status
        $update_stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?");
        $old_status = $order_data['old_status'] ?? null;
        $update_stmt->bind_param("sis", $new_status, $order_id, $old_status);
        
        if ($order_data && $update_stmt->execute() && $update_stmt->affected_rows === 1) {
            $message = 'Order status updated successfully!';
            $message_type = 'success';
            
            // Send email notification
            if ($order_data && $order_data['customer_email']) {
                require_once __DIR__ . '/EmailNotificationService.php';
                $emailService = new EmailNotificationService($conn);
                
                $email_sent = $emailService->sendOrderStatusEmail(
                    $order_id,
                    $order_data['customer_email'],
                    $order_data['customer_name'],
                    $order_data['order_number'],
                    $order_data['old_status'],
                    $new_status,
                    null, // tracking number
                    null  // estimated delivery
                );
                
                if ($email_sent) {
                    $message .= ' Email notification sent to customer.';
                } else {
                    $message .= ' (Note: Email notification failed to send - check logs)';
                }
            }
        } else {
            $message = 'Error updating order status.';
            $message_type = 'danger';
        }
    }
}

// Pagination setup for orders - 5 per page
$orders_per_page = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $orders_per_page;

// Check if orders table exists
$orders_table_check = $conn->query("SHOW TABLES LIKE 'orders'");
$has_orders = $orders_table_check && $orders_table_check->num_rows > 0;

// Get total orders count
$total_orders_count = 0;
$total_pages = 0;
$orders = [];

if ($has_orders) {
    $total_orders_count = $conn->query("SELECT COUNT(DISTINCT o.id) as total FROM orders o")->fetch_assoc()['total'];
    $total_pages = ceil($total_orders_count / $orders_per_page);
    
    // Get all orders with customer information with pagination
    $orders_query = "
        SELECT 
            o.*,
            i.username,
            i.email as customer_email,
            COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as customer_name,
            COUNT(oi.id) as item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        INNER JOIN infos i ON o.user_id = i.id
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT ?, ?
    ";
    $orders_stmt = $conn->prepare($orders_query);
    $orders_stmt->bind_param("ii", $offset, $orders_per_page);
    $orders_stmt->execute();
    $orders = $orders_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get order statistics
$pending_orders = 0;
$processing_orders = 0;
$completed_orders = 0;
$cancelled_orders = 0;
$total_revenue = 0;
$today_orders = 0;
$today_revenue = 0;
$total_orders = 0;

if ($has_orders) {
    $total_orders = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'];
    $pending_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch_assoc()['count'];
    $processing_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'processing'")->fetch_assoc()['count'];
    $completed_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('delivered', 'completed')")->fetch_assoc()['count'];
    $cancelled_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'cancelled'")->fetch_assoc()['count'];
    $total_revenue = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE status IN ('delivered', 'completed')")->fetch_assoc()['total'];
    $today_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
    $today_revenue = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(created_at) = CURDATE() AND status IN ('delivered', 'completed')")->fetch_assoc()['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <style>
        /* Orders Page Styles - Matching Unified Dashboard */
        :root {
            --primary: #3B82F6;
            --primary-dark: #2563EB;
            --success: #10B981;
            --success-dark: #059669;
            --warning: #F59E0B;
            --warning-dark: #D97706;
            --danger: #EF4444;
            --danger-dark: #DC2626;
            --dark: #1E293B;
            --light: #F8FAFC;
        }
        
        /* Order Status Badges - Just colored text, no backgrounds */
        .order-status-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: transparent !important;
            border: none !important;
        }
        
        .status-pending { 
            background: transparent !important;
            color: #F59E0B !important;
            border: none !important;
        }
        .status-processing { 
            background: transparent !important;
            color: #3B82F6 !important;
            border: none !important;
        }
        .status-shipped { 
            background: transparent !important;
            color: #8B5CF6 !important;
            border: none !important;
        }
        .status-delivered { 
            background: transparent !important;
            color: #10B981 !important;
            border: none !important;
        }
        .status-cancelled { 
            background: transparent !important;
            color: #EF4444 !important;
            border: none !important;
        }
        
        /* Order Cards */
        .order-card {
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        
        .order-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-color: #CBD5E1;
            transform: translateY(-2px);
        }
        
        .order-card h6 {
            font-weight: 600;
            color: var(--dark);
            font-size: 15px;
        }
        
        .order-card strong {
            color: var(--dark);
        }
        
        /* Card Container */
        .card {
            background: white;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #E2E8F0;
            padding: 20px;
        }
        
        .card-header h5 {
            font-weight: 600;
            color: var(--dark);
            font-size: 16px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Buttons - Remove boxes, just colored icons */
        .btn-primary {
            background: transparent !important;
            color: #4338CA !important;
            border: none !important;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background: transparent !important;
            border: none !important;
            color: #3730A3 !important;
            transform: translateY(-2px);
            box-shadow: none !important;
        }
        
        .btn-warning {
            background: transparent !important;
            color: #D97706 !important;
            border: none !important;
            font-weight: 500;
        }
        
        .btn-warning:hover {
            background: transparent !important;
            border: none !important;
            color: #B45309 !important;
        }
        
        .btn-danger {
            background: transparent !important;
            color: #DC2626 !important;
            border: none !important;
            font-weight: 500;
        }
        
        .btn-danger:hover {
            background: transparent !important;
            border: none !important;
            color: #B91C1C !important;
        }
        
        .btn-success {
            background: transparent !important;
            color: #64748B !important;
            border: none !important;
            font-weight: 500;
        }
        
        .btn-success:hover {
            background: transparent !important;
            border: none !important;
            color: #475569 !important;
        }
        
        .btn-secondary {
            background: transparent !important;
            color: #64748B !important;
            border: none !important;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: transparent !important;
            border: none !important;
            color: #475569 !important;
        }
        
        .btn-outline-primary {
            border: none !important;
            color: #D97706 !important;
            background: transparent !important;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .btn-outline-primary:hover {
            background: transparent !important;
            color: #B45309 !important;
            border: none !important;
            transform: translateY(-2px);
            box-shadow: none !important;
        }
        
        /* Form Controls */
        .form-select, .form-control {
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        /* Empty State */
        .text-center.py-5 {
            padding: 60px 20px !important;
        }
        
        .text-center.py-5 i {
            color: #CBD5E1;
        }
        
        .text-center.py-5 h5 {
            color: #64748B;
            font-weight: 600;
            margin-top: 20px;
        }
        
        .text-center.py-5 p {
            color: #94A3B8;
        }
        
        /* Alert */
        .alert {
            border-radius: 10px;
            border: 1px solid;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
            color: var(--success-dark);
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: var(--danger-dark);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .order-card .row > div {
                margin-bottom: 12px;
            }
            
            .order-card .text-end {
                text-align: left !important;
            }
            
            .btn-sm {
                width: 100%;
                margin-bottom: 8px;
            }
        }
        
        /* Statistics Cards */
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #E2E8F0;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        /* Remove gradient backgrounds from icon boxes */
        .icon-box {
            background: transparent !important;
        }
        
        .icon-box[style*="background: linear-gradient(135deg, #3B82F6"] {
            background: transparent !important;
            color: #3B82F6 !important;
        }
        
        .icon-box[style*="background: linear-gradient(135deg, #F59E0B"] {
            background: transparent !important;
            color: #F59E0B !important;
        }
        
        .icon-box[style*="background: linear-gradient(135deg, #10B981"] {
            background: transparent !important;
            color: #10B981 !important;
        }
        
        .icon-box[style*="background: linear-gradient(135deg, #8B5CF6"] {
            background: transparent !important;
            color: #8B5CF6 !important;
        }
    </style>
</head>
<body>
    
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
                    <h4 class="mb-0">Manage Orders</h4>
                    <small class="text-muted">Order Management & Tracking</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="unified_admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <div class="container-fluid">
        <!-- Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="icon-box" style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; margin-right: 16px;">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #1E293B;"><?php echo $total_orders; ?></h3>
                            <p style="margin: 0; color: #64748B; font-size: 13px; font-weight: 500;">Total Orders</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="icon-box" style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; margin-right: 16px;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #1E293B;"><?php echo $pending_orders; ?></h3>
                            <p style="margin: 0; color: #64748B; font-size: 13px; font-weight: 500;">Pending Orders</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="icon-box" style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #10B981 0%, #059669 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; margin-right: 16px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #1E293B;"><?php echo $completed_orders; ?></h3>
                            <p style="margin: 0; color: #64748B; font-size: 13px; font-weight: 500;">Completed</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="icon-box" style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; margin-right: 16px;">
                            <i class="fas fa-peso-sign"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 24px; font-weight: 700; margin: 0; color: #1E293B;">â‚±<?php echo number_format($total_revenue, 0); ?></h3>
                            <p style="margin: 0; color: #64748B; font-size: 13px; font-weight: 500;">Total Revenue</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    All Orders
                </h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="statusFilter" style="width: 150px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="shipped">Shipped</option>
                        <option value="delivered">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search orders..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <div id="ordersList">
                    <?php if ($orders_result->num_rows > 0): ?>
                        <?php while ($order = $orders_result->fetch_assoc()): ?>
                        <div class="order-card" data-status="<?php echo $order['status']; ?>" data-search="<?php echo strtolower($order['order_number'] . ' ' . $order['customer_name'] . ' ' . $order['customer_email']); ?>">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($order['order_number']); ?></h6>
                                    <small class="text-muted">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-1"><strong><?php echo htmlspecialchars($order['customer_name']); ?></strong></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($order['customer_email'] ?? 'N/A'); ?></small>
                                </div>
                                <div class="col-md-2 text-center">
                                    <div class="mb-1"><strong>â‚±<?php echo number_format($order['total_amount'], 2); ?></strong></div>
                                    <small class="text-muted"><?php echo $order['item_count']; ?> item(s)</small>
                                </div>
                                <div class="col-md-2 text-center">
                                    <span class="order-status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 text-end">
                                    <button class="btn btn-sm btn-primary" onclick="viewOrderDetails(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary" onclick="updateOrderStatus(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-4x text-muted mb-3 opacity-25"></i>
                            <h5 class="text-muted">No Orders Yet</h5>
                            <p class="text-muted">Orders will appear here once customers start placing them.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Professional Pagination -->
            <?php echo render_pagination($page, $total_pages, $total_orders_count, $orders_per_page, '?', 'orders'); ?>
        </div>
        </div><!-- Close container-fluid -->
    </div><!-- Close main-content -->

    <!-- View Order Details Modal -->
    <div class="modal fade" id="viewOrderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid #E2E8F0; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
                <div class="modal-header" style="background: white; padding: 24px 28px; border-bottom: 1px solid #E2E8F0;">
                    <div>
                        <h5 class="modal-title mb-1" style="font-size: 18px; font-weight: 600; color: #0F172A; letter-spacing: -0.3px;">
                            Order Details
                        </h5>
                        <p class="mb-0" style="font-size: 13px; color: #64748B; font-weight: 400;">View complete order information</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size: 12px;"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent" style="padding: 28px; background: #FAFAFA;">
                    <div class="text-center py-5">
                        <div style="width: 48px; height: 48px; margin: 0 auto 12px; border: 3px solid #E2E8F0; border-top-color: #4F46E5; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        <p class="mb-0" style="color: #64748B; font-size: 14px; font-weight: 500;">Loading...</p>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #E2E8F0; padding: 20px 28px; background: white;">
                    <button type="button" class="btn" data-bs-dismiss="modal" style="padding: 10px 24px; border-radius: 8px; font-weight: 500; font-size: 14px; border: 1px solid #E2E8F0; background: white; color: #475569;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    </style>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid #E2E8F0; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
                <div class="modal-header" style="background: white; padding: 24px 28px; border-bottom: 1px solid #E2E8F0;">
                    <div>
                        <h5 class="modal-title mb-1" style="font-size: 18px; font-weight: 600; color: #0F172A; letter-spacing: -0.3px;">
                            Update Order Status
                        </h5>
                        <p class="mb-0" style="font-size: 13px; color: #64748B; font-weight: 400;">Change order <span id="statusOrderNumber" style="font-weight: 600;"></span></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size: 12px;"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="order_token" value="<?= htmlspecialchars($_SESSION['order_review_token']) ?>">
                    <div class="modal-body" style="padding: 28px; background: #FAFAFA;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" id="update_order_id">
                        
                        <div class="mb-4">
                            <label class="form-label" style="font-size: 12px; font-weight: 500; color: #64748B; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Current Status
                            </label>
                            <div id="currentStatusDisplay" style="padding: 14px 16px; background: white; border: 1px solid #E2E8F0; border-radius: 8px; font-weight: 600; font-size: 14px; color: #0F172A;"></div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label" style="font-size: 12px; font-weight: 500; color: #64748B; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                                New Status
                            </label>
                            <select name="status" id="update_status" class="form-select" required style="padding: 14px 16px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; font-weight: 500; color: #0F172A; background: white;">
                                <option value="">Select new status</option>
                                <option value="pending">â³ Pending</option>
                                <option value="processing">ðŸ”„ Processing</option>
                                <option value="shipped">ðŸšš Shipped</option>
                                <option value="delivered">âœ… Delivered</option>
                                <option value="cancelled">âŒ Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info" style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 14px 16px; margin: 0;">
                            <div style="display: flex; gap: 12px; align-items: start;">
                                <i class="fas fa-info-circle" style="color: #3B82F6; font-size: 16px; margin-top: 2px;"></i>
                                <small style="color: #1E40AF; font-size: 13px; line-height: 1.5; font-weight: 400;">Customer will be notified of this status change via email.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #E2E8F0; padding: 20px 28px; background: white; gap: 12px;">
                        <button type="button" class="btn" data-bs-dismiss="modal" style="flex: 1; padding: 12px; border-radius: 8px; font-weight: 500; font-size: 14px; border: 1px solid #E2E8F0; background: white; color: #475569;">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px; border-radius: 8px; font-weight: 500; font-size: 14px; background: #4F46E5; border: none; color: white;">
                            Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateOrderStatus(orderId, currentStatus) {
        document.getElementById('update_order_id').value = orderId;
        document.getElementById('update_status').value = currentStatus;
        
        // Display current status with styling
        const statusMap = {
            'pending': 'â³ Pending',
            'processing': 'ðŸ”„ Processing',
            'shipped': 'ðŸšš Shipped',
            'delivered': 'âœ… Delivered',
            'cancelled': 'âŒ Cancelled'
        };
        
        document.getElementById('currentStatusDisplay').textContent = statusMap[currentStatus] || currentStatus;
        document.getElementById('statusOrderNumber').textContent = '#' + orderId;
        
        new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
    }
    
    function viewOrderDetails(orderId) {
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
        modal.show();
        
        // Fetch order details
        fetch('get_admin_order_details.php?order_id=' + orderId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayOrderDetails(data.order, data.items, data.customer);
                } else {
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${data.message || 'Failed to load order details'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('orderDetailsContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading order details. Please try again.
                    </div>
                `;
            });
    }
    
    function displayOrderDetails(order, items, customer) {
        const statusColors = {
            'pending': 'warning',
            'processing': 'info',
            'shipped': 'primary',
            'delivered': 'success',
            'cancelled': 'danger'
        };
        
        let itemsHtml = '';
        let subtotal = 0;
        
        items.forEach(item => {
            const pricePerItem = parseFloat(item.price_per_item) || 0;
            const quantity = parseInt(item.quantity) || 0;
            const itemTotal = pricePerItem * quantity;
            subtotal += itemTotal;
            
            itemsHtml += `
                <div style="display: flex; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid #E2E8F0;">
                    ${item.cover_image ? 
                        `<img src="../uploads/books/${item.cover_image}" alt="${item.book_title}" style="width: 56px; height: 72px; object-fit: cover; border-radius: 6px; border: 1px solid #E2E8F0;">` :
                        `<div style="width: 56px; height: 72px; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #94A3B8;">
                            <i class="fas fa-book" style="font-size: 20px;"></i>
                        </div>`
                    }
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 14px; color: #0F172A; margin-bottom: 4px; line-height: 1.4;">${item.book_title}</div>
                        <div style="font-size: 13px; color: #64748B; font-weight: 400;">${item.book_author || 'Unknown Author'}</div>
                    </div>
                    <div style="text-align: center; min-width: 60px;">
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 500;">Qty</div>
                        <div style="font-size: 15px; font-weight: 600; color: #0F172A;">${quantity}</div>
                    </div>
                    <div style="text-align: center; min-width: 90px;">
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 500;">Price</div>
                        <div style="font-size: 14px; font-weight: 600; color: #475569;">â‚±${pricePerItem.toFixed(2)}</div>
                    </div>
                    <div style="text-align: right; min-width: 110px;">
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 500;">Total</div>
                        <div style="font-size: 16px; font-weight: 600; color: #0F172A;">â‚±${itemTotal.toFixed(2)}</div>
                    </div>
                </div>
            `;
        });
        
        const tax = subtotal * 0.12;
        const shipping = subtotal > 500 ? 0 : 50;
        const total = subtotal + tax + shipping;
        
        const content = `
            <!-- Order Information -->
            <div style="background: white; padding: 24px; border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Order Number</div>
                        <div style="font-size: 15px; font-weight: 600; color: #0F172A;">${order.order_number}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Date</div>
                        <div style="font-size: 15px; font-weight: 600; color: #0F172A;">${new Date(order.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Status</div>
                        <div><span class="badge bg-${statusColors[order.status]}" style="font-size: 12px; padding: 6px 12px; font-weight: 500;">${order.status.charAt(0).toUpperCase() + order.status.slice(1)}</span></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Customer</div>
                        <div style="font-size: 15px; font-weight: 600; color: #0F172A;">${customer.name}</div>
                    </div>
                    <div style="grid-column: span 2;">
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Email</div>
                        <div style="font-size: 15px; font-weight: 600; color: #0F172A;">${customer.email}</div>
                    </div>
                    ${order.shipping_address ? `
                    <div style="grid-column: 1 / -1; margin-top: 8px; padding-top: 20px; border-top: 1px solid #E2E8F0;">
                        <div style="font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; font-weight: 500;">Shipping Address</div>
                        <div style="font-size: 14px; font-weight: 500; color: #475569; line-height: 1.6;">${order.shipping_address}</div>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Order Items -->
            <div style="background: white; padding: 24px; border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 600; color: #0F172A; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                    Order Items
                </div>
                <div>
                    ${itemsHtml}
                </div>
            </div>

            <!-- Order Summary -->
            <div style="background: white; padding: 24px; border-radius: 12px; border: 1px solid #E2E8F0;">
                <div style="font-size: 13px; font-weight: 600; color: #0F172A; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                    Summary
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #F1F5F9;">
                    <span style="color: #64748B; font-size: 14px; font-weight: 400;">Subtotal</span>
                    <span style="font-weight: 600; color: #0F172A; font-size: 14px;">â‚±${subtotal.toFixed(2)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #F1F5F9;">
                    <span style="color: #64748B; font-size: 14px; font-weight: 400;">Tax (12%)</span>
                    <span style="font-weight: 600; color: #0F172A; font-size: 14px;">â‚±${tax.toFixed(2)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #F1F5F9;">
                    <span style="color: #64748B; font-size: 14px; font-weight: 400;">Shipping</span>
                    <span style="font-weight: 600; color: #0F172A; font-size: 14px;">${shipping > 0 ? 'â‚±' + shipping.toFixed(2) : 'FREE'}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 16px 0; margin-top: 8px;">
                    <span style="font-size: 16px; font-weight: 600; color: #0F172A;">Total</span>
                    <span style="font-size: 20px; font-weight: 700; color: #0F172A;">â‚±${total.toFixed(2)}</span>
                </div>
            </div>
        `;
        
        document.getElementById('orderDetailsContent').innerHTML = content;
    }
    
    // Filter functionality
    document.getElementById('statusFilter').addEventListener('change', filterOrders);
    document.getElementById('searchInput').addEventListener('input', filterOrders);
    
    function filterOrders() {
        const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const orders = document.querySelectorAll('.order-card');
        
        orders.forEach(order => {
            const status = order.dataset.status;
            const searchData = order.dataset.search;
            
            const statusMatch = !statusFilter || status === statusFilter;
            const searchMatch = !searchTerm || searchData.includes(searchTerm);
            
            order.style.display = (statusMatch && searchMatch) ? 'block' : 'none';
        });
    }
    </script>
</body>
</html>

