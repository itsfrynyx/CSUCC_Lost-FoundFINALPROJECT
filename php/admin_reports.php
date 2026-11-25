<?php
session_start();
require_once '../DB/conn.php';
require_once 'includes/pagination_helper.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();
$is_super_admin = $admin_data['role'] === 'super_admin';

// Get date range from filters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$report_type = isset($_GET['type']) ? $_GET['type'] : 'orders';

// Pagination setup - 5 per page for all reports
$reports_per_page = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $reports_per_page;

// Check which legacy bookstore tables actually exist before using them.
$orders_table_exists = $conn->query("SHOW TABLES LIKE 'orders'")->num_rows > 0;
$books_table_exists = $conn->query("SHOW TABLES LIKE 'books'")->num_rows > 0;
$order_items_table_exists = $conn->query("SHOW TABLES LIKE 'order_items'")->num_rows > 0;

// Orders Report Data
$orders_data = [];
$total_orders_pages = 0;
$total_orders_count = 0;
if ($report_type == 'orders' && $orders_table_exists) {
    // Get total count
    $count_query = "
        SELECT COUNT(DISTINCT DATE(created_at)) as total
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
    ";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("ss", $date_from, $date_to);
    $count_stmt->execute();
    $total_orders_count = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $total_orders_pages = ceil($total_orders_count / $reports_per_page);
    
    $orders_query = "
        SELECT 
            DATE(created_at) as order_date,
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            SUM(total_amount) as total_revenue
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY order_date DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($orders_query);
    $stmt->bind_param("ssii", $date_from, $date_to, $reports_per_page, $offset);
    $stmt->execute();
    $orders_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Inventory Report Data
$inventory_data = [];
$total_inventory_pages = 0;
$total_inventory_count = 0;
if ($report_type == 'inventory' && $books_table_exists) {
    // Get total count
    $total_inventory_count = $conn->query("SELECT COUNT(*) as total FROM books")->fetch_assoc()['total'] ?? 0;
    $total_inventory_pages = ceil($total_inventory_count / $reports_per_page);
    
    $inventory_query = "
        SELECT 
            id,
            title,
            author,
            price,
            stock_quantity,
            is_active,
            created_at
        FROM books
        ORDER BY stock_quantity ASC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($inventory_query);
    $stmt->bind_param("ii", $reports_per_page, $offset);
    $stmt->execute();
    $inventory_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Sales Report Data
$sales_data = [];
$total_sales_pages = 0;
$total_sales_count = 0;
if ($report_type == 'sales' && $books_table_exists && $order_items_table_exists && $orders_table_exists) {
    // Get total count
    $count_query = "
        SELECT COUNT(DISTINCT b.id) as total
        FROM books b
        LEFT JOIN order_items oi ON b.id = oi.book_id
        LEFT JOIN orders o ON oi.order_id = o.id
        WHERE o.status = 'completed' AND DATE(o.created_at) BETWEEN ? AND ?
    ";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("ss", $date_from, $date_to);
    $count_stmt->execute();
    $total_sales_count = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $total_sales_pages = ceil($total_sales_count / $reports_per_page);
    
    $sales_query = "
        SELECT 
            b.title,
            b.author,
            b.price,
            COUNT(oi.id) as times_sold,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.quantity * b.price) as total_revenue
        FROM books b
        LEFT JOIN order_items oi ON b.id = oi.book_id
        LEFT JOIN orders o ON oi.order_id = o.id
        WHERE o.status = 'completed' AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY b.id
        ORDER BY total_revenue DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sales_query);
    $stmt->bind_param("ssii", $date_from, $date_to, $reports_per_page, $offset);
    $stmt->execute();
    $sales_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Customer Approval Reports Data
$pending_customers = [];
$approved_customers = [];
$rejected_customers = [];
$total_pending_pages = 0;
$total_approved_pages = 0;
$total_rejected_pages = 0;

if ($report_type == 'pending_customers') {
    // Check if approved column exists
    $check_column = $conn->query("SHOW COLUMNS FROM infos LIKE 'approved'");
    if ($check_column->num_rows > 0) {
        // Get total count
        $total_pending_count = $conn->query("SELECT COUNT(*) as total FROM infos WHERE role = 'customer' AND approved = 0")->fetch_assoc()['total'];
        $total_pending_pages = ceil($total_pending_count / $reports_per_page);
        
        $pending_query = "
            SELECT 
                id,
                idnumber,
                username,
                COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as full_name,
                email,
                CONCAT_WS(', ', street, barangay, municipality, province, postal, country) as address,
                created_at
            FROM infos
            WHERE role = 'customer' AND approved = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($pending_query);
        $stmt->bind_param("ii", $reports_per_page, $offset);
        $stmt->execute();
        $pending_customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // If approved column doesn't exist, show all customers
        $total_pending_count = $conn->query("SELECT COUNT(*) as total FROM infos WHERE role = 'customer'")->fetch_assoc()['total'];
        $total_pending_pages = ceil($total_pending_count / $reports_per_page);
        
        $pending_query = "
            SELECT 
                id,
                idnumber,
                username,
                COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as full_name,
                email,
                CONCAT_WS(', ', street, barangay, municipality, province, postal, country) as address,
                created_at
            FROM infos
            WHERE role = 'customer'
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($pending_query);
        $stmt->bind_param("ii", $reports_per_page, $offset);
        $stmt->execute();
        $pending_customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if ($report_type == 'approved_customers') {
    // Check if approved column exists
    $check_column = $conn->query("SHOW COLUMNS FROM infos LIKE 'approved'");
    if ($check_column->num_rows > 0) {
        // Get total count
        $count_query = "
            SELECT COUNT(*) as total
            FROM infos
            WHERE role = 'customer' AND approved = 1
            AND DATE(COALESCE(approval_date, created_at)) BETWEEN ? AND ?
        ";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("ss", $date_from, $date_to);
        $count_stmt->execute();
        $total_approved_count = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_approved_pages = ceil($total_approved_count / $reports_per_page);
        
        $approved_query = "
            SELECT 
                id,
                username,
                COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as full_name,
                email,
                CONCAT_WS(', ', street, barangay, municipality, province, postal, country) as address,
                created_at,
                approval_date as approved_at
            FROM infos
            WHERE role = 'customer' AND approved = 1
            AND DATE(COALESCE(approval_date, created_at)) BETWEEN ? AND ?
            ORDER BY approval_date DESC, created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($approved_query);
        $stmt->bind_param("ssii", $date_from, $date_to, $reports_per_page, $offset);
        $stmt->execute();
        $approved_customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // If approved column doesn't exist, show all customers in date range
        $count_query = "
            SELECT COUNT(*) as total
            FROM infos
            WHERE role = 'customer'
            AND DATE(created_at) BETWEEN ? AND ?
        ";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("ss", $date_from, $date_to);
        $count_stmt->execute();
        $total_approved_count = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_approved_pages = ceil($total_approved_count / $reports_per_page);
        
        $approved_query = "
            SELECT 
                id,
                username,
                COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as full_name,
                email,
                CONCAT_WS(', ', street, barangay, municipality, province, postal, country) as address,
                created_at,
                NULL as approved_at
            FROM infos
            WHERE role = 'customer'
            AND DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($approved_query);
        $stmt->bind_param("ssii", $date_from, $date_to, $reports_per_page, $offset);
        $stmt->execute();
        $approved_customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if ($report_type == 'rejected_customers') {
    // Check if approved column exists
    $check_column = $conn->query("SHOW COLUMNS FROM infos LIKE 'approved'");
    if ($check_column->num_rows > 0) {
        // Get total count
        $count_query = "
            SELECT COUNT(*) as total
            FROM infos
            WHERE role = 'customer' AND approved = 2
            AND DATE(COALESCE(approval_date, created_at)) BETWEEN ? AND ?
        ";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("ss", $date_from, $date_to);
        $count_stmt->execute();
        $total_rejected_count = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_rejected_pages = ceil($total_rejected_count / $reports_per_page);
        
        $rejected_query = "
            SELECT 
                id,
                username,
                COALESCE(full_name, CONCAT(firstname, ' ', lastname)) as full_name,
                email,
                CONCAT_WS(', ', street, barangay, municipality, province, postal, country) as address,
                created_at,
                approval_date as rejected_at,
                '' as rejection_reason
            FROM infos
            WHERE role = 'customer' AND approved = 2
            AND DATE(COALESCE(approval_date, created_at)) BETWEEN ? AND ?
            ORDER BY approval_date DESC, created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($rejected_query);
        $stmt->bind_param("ssii", $date_from, $date_to, $reports_per_page, $offset);
        $stmt->execute();
        $rejected_customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // If approved column doesn't exist, return empty
        $rejected_customers = [];
        $total_rejected_pages = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSUCC FIND Reports</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        :root {
            --primary-blue: #3B82F6;
            --primary-blue-dark: #2563EB;
            --success-green: #10B981;
            --success-green-dark: #059669;
            --warning-orange: #F59E0B;
            --warning-orange-dark: #D97706;
            --danger-red: #EF4444;
            --danger-red-dark: #DC2626;
            --content-bg: #f5f6fa;
            --card-bg: #ffffff;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --border-color: #e2e8f0;
        }
        
        /* Page-specific styles only - sidebar handled by professional_styles.css */
        
        .report-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .report-tab {
            padding: 12px 24px;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .report-tab:hover {
            background: #f9fafb;
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .report-tab.active {
            background: white;
            color: var(--text-primary);
            border-color: var(--border-color);
            box-shadow: none;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }
        
        .filter-section .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .filter-section .form-control {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .filter-section .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3);
        }
        
        .export-btn {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--success-green) 0%, var(--success-green-dark) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }
        
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .report-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 18px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .report-header small {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 400;
        }
        
        .table-card thead {
            background: #f9fafb;
        }
        
        .table-card thead th {
            padding: 16px;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .table-card tbody td {
            padding: 16px;
            vertical-align: middle;
            color: var(--text-primary);
            font-size: 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .table-card tbody tr:hover {
            background: #f9fafb;
        }
        
        .table-card tbody tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background: transparent !important;
            border: none !important;
        }
        
        .badge.bg-warning {
            background: transparent !important;
            color: var(--warning-orange) !important;
            border: none !important;
        }
        
        .badge.bg-success {
            background: transparent !important;
            color: var(--success-green) !important;
            border: none !important;
        }
        
        .badge.bg-danger {
            background: transparent !important;
            color: var(--danger-red) !important;
            border: none !important;
        }
        
        .badge.bg-secondary {
            background: transparent !important;
            color: #6B7280 !important;
            border: none !important;
        }
        
        .btn-outline-secondary {
            border: 2px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .btn-outline-secondary:hover {
            background: #f9fafb;
            border-color: var(--text-secondary);
            color: var(--text-primary);
            transform: translateY(-2px);
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
                    <h4 class="mb-0">CSUCC FIND Reports</h4>
                    <small class="text-muted">Campus Lost & Found Analytics</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
            </div>
        </div>
        
        <div class="container-fluid py-4">
            
            <!-- Report Tabs -->
            <div class="report-tabs">
                <a href="?type=orders&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'orders' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list me-2" style="color: #3B82F6;"></i>Case Overview
                </a>
                <a href="?type=inventory&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'inventory' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes me-2" style="color: #8B5CF6;"></i>Item Registry
                </a>
                <a href="?type=sales&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'sales' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line me-2" style="color: #10B981;"></i>Recovery Insights
                </a>
                <a href="?type=pending_customers&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'pending_customers' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock me-2" style="color: #F59E0B;"></i>Pending Registrations
                </a>
                <a href="?type=approved_customers&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'approved_customers' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check me-2" style="color: #10B981;"></i>Approved Accounts
                </a>
                <a href="?type=rejected_customers&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="report-tab <?php echo $report_type == 'rejected_customers' ? 'active' : ''; ?>">
                    <i class="fas fa-user-times me-2" style="color: #EF4444;"></i>Rejected Accounts
                </a>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Apply Filter
                        </button>
                    </div>
                    <div class="col-md-3 text-end">
                        <button type="button" class="export-btn" onclick="exportReport()">
                            <i class="fas fa-file-excel me-2" style="color: white;"></i>Export to Excel
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Orders Report -->
            <?php if ($report_type == 'orders'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-clipboard-list me-2" style="color: #3B82F6;"></i>Case Overview</h5>
                    <small class="text-muted">From <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Orders</th>
                                <th>Pending</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($orders_data) > 0): ?>
                                <?php foreach ($orders_data as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                    <td><strong><?php echo $row['total_orders']; ?></strong></td>
                                    <td><span class="badge bg-warning"><?php echo $row['pending_orders']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $row['completed_orders']; ?></span></td>
                                    <td><span class="badge bg-danger"><?php echo $row['cancelled_orders']; ?></span></td>
                                    <td><strong>₱<?php echo number_format($row['total_revenue'], 2); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No orders found for the selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_orders_pages, count($orders_data) > 0 ? $total_orders_count : 0, $reports_per_page, $base_url, 'order records'); 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Inventory Report -->
            <?php if ($report_type == 'inventory'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-boxes me-2" style="color: #8B5CF6;"></i>Item Registry</h5>
                    <small class="text-muted">Current recorded item levels</small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Added Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inventory_data) > 0): ?>
                                <?php foreach ($inventory_data as $book): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td>₱<?php echo number_format($book['price'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $book['stock_quantity'] == 0 ? 'danger' : ($book['stock_quantity'] < 10 ? 'warning' : 'success'); ?>">
                                            <?php echo $book['stock_quantity']; ?> units
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $book['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $book['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($book['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No books found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_inventory_pages, count($inventory_data) > 0 ? $total_inventory_count : 0, $reports_per_page, $base_url, 'books'); 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Sales Report -->
            <?php if ($report_type == 'sales'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-chart-line me-2" style="color: #10B981;"></i>Recovery Insights</h5>
                    <small class="text-muted">From <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Author</th>
                                <th>Price</th>
                                <th>Times Sold</th>
                                <th>Total Quantity</th>
                                <th>Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sales_data) > 0): ?>
                                <?php foreach ($sales_data as $sale): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($sale['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($sale['author']); ?></td>
                                    <td>₱<?php echo number_format($sale['price'], 2); ?></td>
                                    <td><?php echo $sale['times_sold']; ?></td>
                                    <td><strong><?php echo $sale['total_quantity']; ?></strong></td>
                                    <td><strong class="text-success">₱<?php echo number_format($sale['total_revenue'], 2); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No sales found for the selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_sales_pages, count($sales_data) > 0 ? $total_sales_count : 0, $reports_per_page, $base_url, 'sales records'); 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Pending Customers Report -->
            <?php if ($report_type == 'pending_customers'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-user-clock me-2" style="color: #F59E0B;"></i>Pending Approval</h5>
                    <small class="text-muted">Customers awaiting approval</small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>ID Number</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Address</th>
                                <th>Registration Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($pending_customers) > 0): ?>
                                <?php foreach ($pending_customers as $customer): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($customer['idnumber']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($customer['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($customer['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No pending customers</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_pending_pages, count($pending_customers) > 0 ? $total_pending_count : 0, $reports_per_page, $base_url, 'pending customers'); 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Approved Customers Report -->
            <?php if ($report_type == 'approved_customers'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-user-check me-2" style="color: #10B981;"></i>Approved Customers</h5>
                    <small class="text-muted">From <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>ID Number</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Address</th>
                                <th>Registration Date</th>
                                <th>Approved Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($approved_customers) > 0): ?>
                                <?php foreach ($approved_customers as $customer): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($customer['idnumber']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($customer['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($customer['created_at'])); ?></td>
                                    <td>
                                        <?php if ($customer['approved_at']): ?>
                                            <span class="badge bg-success"><?php echo date('M d, Y h:i A', strtotime($customer['approved_at'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No approved customers found for the selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_approved_pages, count($approved_customers) > 0 ? $total_approved_count : 0, $reports_per_page, $base_url, 'approved customers'); 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Rejected Customers Report -->
            <?php if ($report_type == 'rejected_customers'): ?>
            <div class="table-card">
                <div class="report-header px-4 pt-4">
                    <h5><i class="fas fa-user-times me-2" style="color: #EF4444;"></i>Rejected Customers</h5>
                    <small class="text-muted">From <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>ID Number</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Address</th>
                                <th>Registration Date</th>
                                <th>Rejected Date</th>
                                <th>Rejection Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rejected_customers) > 0): ?>
                                <?php foreach ($rejected_customers as $customer): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($customer['idnumber']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($customer['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($customer['created_at'])); ?></td>
                                    <td>
                                        <?php if ($customer['rejected_at']): ?>
                                            <span class="badge bg-danger"><?php echo date('M d, Y h:i A', strtotime($customer['rejected_at'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($customer['rejection_reason'] ?? 'No reason provided'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No rejected customers found for the selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php 
                $base_url = build_pagination_url(['page']);
                echo render_pagination($page, $total_rejected_pages, count($rejected_customers) > 0 ? $total_rejected_count : 0, $reports_per_page, $base_url, 'rejected customers'); 
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportReport() {
            const table = document.getElementById('reportTable');
            if (!table) return;
            
            let csv = '';
            
            // Get headers
            const headers = table.querySelectorAll('thead th');
            headers.forEach((header, index) => {
                csv += '"' + header.textContent.trim() + '"';
                if (index < headers.length - 1) csv += ',';
            });
            csv += '\n';
            
            // Get rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 1) {
                    cells.forEach((cell, index) => {
                        csv += '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                        if (index < cells.length - 1) csv += ',';
                    });
                    csv += '\n';
                }
            });
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `report_<?php echo $report_type; ?>_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
