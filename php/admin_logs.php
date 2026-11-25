<?php
session_start();
require_once '../DB/conn.php';
require_once 'includes/pagination_helper.php';

// Increase GROUP_CONCAT limit
$conn->query("SET SESSION group_concat_max_len = 10000");

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Verify the current database role on every request.
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$current_role = strtolower(trim($result['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    header('Location: Sign_in.php');
    exit();
}

$is_super_admin = $current_role === 'super_admin';
$dashboard_url = $is_super_admin ? 'admin_dashboard.php' : 'unified_admin_dashboard.php';

$message = '';
$message_type = '';

// Pagination - dynamic per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
// Validate per_page (5-500)
if ($per_page < 5) $per_page = 5;
if ($per_page > 500) $per_page = 500;
$offset = ($page - 1) * $per_page;

// Enhanced filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$month_filter = $_GET['month'] ?? '';
$search_query = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';

// Build WHERE clause based on filters
$where_conditions = [];
$params = [];
$param_types = '';

// Filter out orphaned logs (users that don't exist in infos table)
$where_conditions[] = "username IS NOT NULL";
$where_conditions[] = "role IS NOT NULL";

// Filter out sessions with no login time (invalid sessions)
$where_conditions[] = "login_time IS NOT NULL";

$where_conditions[] = "activity_action NOT IN ('page_view', 'failed_login', 'login_failed', 'account_locked')";

if (!$is_super_admin) {
    $where_conditions[] = "role IN ('admin', 'customer')";
}

if ($date_from) {
    $where_conditions[] = "DATE(activity_time) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if (preg_match('/^\d{4}-\d{2}$/', $month_filter)) {
    $where_conditions[] = "DATE_FORMAT(activity_time, '%Y-%m') = ?";
    $params[] = $month_filter;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(activity_time) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if ($search_query) {
    $where_conditions[] = "(username LIKE ? OR full_name LIKE ? OR idnumber LIKE ? OR activity_description LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ssss';
}

if (in_array($role_filter, ['super_admin', 'admin', 'customer'], true)
    && ($is_super_admin || $role_filter !== 'super_admin')) {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$combined_logs_query = "
    SELECT al.session_id, al.user_id, al.user_type, al.ip_address, al.user_agent,
           al.created_at AS activity_time, al.action AS activity_action,
           al.description AS activity_description, i.username, i.idnumber,
           COALESCE(NULLIF(i.full_name, ''), CONCAT_WS(' ', i.firstname, i.middlename, i.lastname, i.suffix)) AS full_name, i.role,
           usa.login_time, usa.logout_time, usa.duration_seconds
    FROM admin_activity_log al
    INNER JOIN infos i ON al.user_id = i.id
    LEFT JOIN user_session_activity usa ON al.session_id = usa.session_id
    UNION ALL
    SELECT ual.session_id, ual.user_id, 'customer' AS user_type,
           ual.ip_address, ual.user_agent, ual.timestamp AS activity_time,
           ual.activity_type AS activity_action, ual.activity_details AS activity_description,
           i.username, i.idnumber,
           COALESCE(NULLIF(i.full_name, ''), CONCAT_WS(' ', i.firstname, i.middlename, i.lastname, i.suffix)) AS full_name,
           i.role, usa.login_time, usa.logout_time, usa.duration_seconds
    FROM user_activity_logs ual
    INNER JOIN infos i ON ual.user_id = i.id
    LEFT JOIN user_session_activity usa ON ual.session_id = usa.session_id
";

$count_query = "SELECT COUNT(*) AS count FROM (
    SELECT user_id,
           CASE WHEN session_id != '' THEN session_id ELSE CONCAT('no-sid-', user_id) END AS session_key
    FROM ($combined_logs_query) AS combined_logs
    $where_clause
    GROUP BY session_key, user_id
) AS filtered_sessions";
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_logs = (int) $count_stmt->get_result()->fetch_assoc()['count'];
$total_pages = (int) ceil($total_logs / $per_page);

$query = "
    SELECT session_id, user_id, user_type, ip_address, user_agent,
           COUNT(*) as activity_count,
           MIN(activity_time) as first_activity,
           MAX(activity_time) as last_activity_time,
           GROUP_CONCAT(DISTINCT activity_action SEPARATOR ', ') as actions_list,
           GROUP_CONCAT(DISTINCT CONCAT_WS('::', activity_time, activity_action, activity_description) ORDER BY activity_time ASC SEPARATOR ' || ') as descriptions,
           username, idnumber, full_name, role,
           login_time, logout_time, duration_seconds
    FROM ($combined_logs_query) AS combined_logs
    $where_clause
    GROUP BY (CASE WHEN session_id != '' THEN session_id ELSE CONCAT('no-sid-', user_id) END), user_id
    ORDER BY last_activity_time DESC 
    LIMIT ? OFFSET ?
";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $all_params = array_merge($params, [$per_page, $offset]);
    $all_types = $param_types . 'ii';
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $per_page, $offset);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get unique action types for filter dropdown
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_super_admin ? 'All Activity Logs' : 'Admin and User Logs'; ?> - Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <link rel="stylesheet" href="../css/enhanced_modal.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        /* Page-specific styles only - main styles come from professional_styles.css */
        .log-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-right: 15px;
        }
        
        /* Enhanced Filter Styles */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .filter-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
        }
        
        .export-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .export-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 8px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 180px;
            z-index: 1000;
        }
        
        .export-menu.show {
            display: block;
        }
        
        .export-menu a {
            display: block;
            padding: 12px 16px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .export-menu a:hover {
            background: #f5f5f5;
        }
        
        .export-menu a:first-child {
            border-radius: 8px 8px 0 0;
        }
        
        .export-menu a:last-child {
            border-radius: 0 0 8px 8px;
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .filter-badge .remove {
            cursor: pointer;
            font-weight: bold;
            margin-left: 4px;
        }
        
        .active-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box input {
            padding-left: 40px;
        }
        
        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-actions button,
            .filter-actions a {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    
    <?php include 'includes/professional_sidebar.php'; ?>
            
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="d-flex align-items-center">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h4 class="mb-0 ms-3">Activity Logs</h4>
            </div>
            
            <div class="text-muted">
                <i class="fas fa-clock me-2"></i>
                Total: <?php echo $total_logs; ?> sessions
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Enhanced Filter Form -->
        <div class="filter-section">
            <div class="filter-header">
                <h5>
                    <i class="fas fa-sliders-h"></i>
                    Advanced Filters
                </h5>
                <?php if ($is_super_admin): ?>
                <div class="export-dropdown">
                    <button class="btn btn-success btn-sm" onclick="toggleExportMenu(event)">
                        <i class="fas fa-download me-2"></i>
                        Export
                        <i class="fas fa-chevron-down ms-2"></i>
                    </button>
                    <div class="export-menu" id="exportMenu">
                        <a href="export_admin_logs.php?format=csv&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-csv me-2"></i>
                            Export as CSV
                        </a>
                        <a href="export_admin_logs.php?format=excel&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-excel me-2"></i>
                            Export as Excel
                        </a>
                        <a href="export_admin_logs.php?format=pdf&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-pdf me-2"></i>
                            Export as PDF
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <form method="GET" id="filterForm">
                
                <div class="row g-2 align-items-end">
                    <!-- Search Box -->
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Search Keywords</label>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   class="form-control form-control-sm" 
                                   name="search" 
                                   placeholder="Search user, name..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    

                    <!-- Role Filter -->
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Role</label>
                        <select class="form-select form-select-sm" name="role" id="roleFilter">
                            <option value="">All Roles</option>
                            <?php if ($is_super_admin): ?>
                            <option value="super_admin" <?php echo $role_filter === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                            <?php endif; ?>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="customer" <?php echo $role_filter === 'customer' ? 'selected' : ''; ?>>Customer</option>
                        </select>
                    </div>
                    
                    <!-- From Date -->
                    <div class="col-md-2">
                        <label for="dateFrom" class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" 
                               class="form-control form-control-sm" 
                               name="date_from" 
                               id="dateFrom" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <!-- To Date -->
                    <div class="col-md-2">
                        <label for="dateTo" class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" 
                               class="form-control form-control-sm" 
                               name="date_to" 
                               id="dateTo" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="monthFilter" class="form-label small text-muted mb-1">Month</label>
                        <input type="month" class="form-control form-control-sm" name="month" id="monthFilter"
                               value="<?php echo htmlspecialchars($month_filter, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                
                <!-- Active Filters Display -->
                <?php if ($date_from || $date_to || $month_filter || $search_query || $role_filter): ?>
                <div class="active-filters">
                    <small class="text-muted me-2">Active Filters:</small>
                    <?php if ($search_query): ?>
                        <span class="filter-badge">
                            Search: "<?php echo htmlspecialchars($search_query); ?>"
                            <span class="remove" onclick="removeFilter('search')">Ã—</span>
                        </span>
                    <?php endif; ?>
                    <?php if ($role_filter): ?>
                        <span class="filter-badge">
                            Role: <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($role_filter))); ?>
                            <span class="remove" onclick="removeFilter('role')">Ã—</span>
                        </span>
                    <?php endif; ?>
                    <?php if ($date_from): ?>
                        <span class="filter-badge">
                            From: <?php echo htmlspecialchars($date_from); ?>
                            <span class="remove" onclick="removeFilter('date_from')">Ã—</span>
                        </span>
                    <?php endif; ?>
                    <?php if ($date_to): ?>
                        <span class="filter-badge">
                            To: <?php echo htmlspecialchars($date_to); ?>
                            <span class="remove" onclick="removeFilter('date_to')">Ã—</span>
                        </span>
                    <?php endif; ?>
                    <?php if ($month_filter): ?>
                        <span class="filter-badge">
                            Month: <?php echo htmlspecialchars($month_filter, ENT_QUOTES, 'UTF-8'); ?>
                            <span class="remove" onclick="removeFilter('month')">×</span>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>
                        Apply Filters
                    </button>
                    <a href="admin_logs.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-2"></i>
                        Reset All
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-history text-primary me-2"></i>
                    Recent Activities
                </h5>
                
                <?php if (empty($logs)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No logs found</h5>
                        <p class="text-muted">There are no activity logs matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>User</th>
                                     <th>Role</th>
                                     <th>Activities</th>
                                     <th>IP Address</th>
                                     <th>Device / Browser</th>
                                    <th>Login Time</th>
                                    <th>Logout Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="log-icon bg-primary text-white">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['full_name'] ?: $log['username'] ?: '-'); ?></small>
                                                <small class="text-muted d-block">ID: <?php echo htmlspecialchars($log['idnumber'] ?? '-'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $role_badge = match(strtolower($log['role'] ?? '')) {
                                            'super_admin' => 'danger',
                                            'admin' => 'primary',
                                            'customer' => 'info',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?php echo $role_badge; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($log['role'] ?? 'N/A'))); ?>
                                        </span>
                                    </td>
                                     <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary view-details-btn" 
                                                data-session-id="<?php echo htmlspecialchars($log['session_id']); ?>"
                                                data-username="<?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?>"
                                                data-login-time="<?php echo htmlspecialchars($log['login_time'] ?? ''); ?>"
                                                data-logout-time="<?php echo htmlspecialchars($log['logout_time'] ?? ''); ?>"
                                                data-actions="<?php echo htmlspecialchars($log['actions_list'] ?? ''); ?>"
                                                data-details="<?php echo htmlspecialchars($log['descriptions']); ?>">
                                            <i class="fas fa-list-ul me-1"></i> View Details
                                        </button>
                                     </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            if (!empty($log['user_agent'])) {
                                                $user_agent = $log['user_agent'];
                                                $browser = 'Unknown';
                                                $os = 'Unknown';
                                                
                                                // Detect browser
                                                if (preg_match('/Edg\//i', $user_agent)) {
                                                    $browser = '<i class="fab fa-edge"></i> Edge';
                                                } elseif (preg_match('/Chrome/i', $user_agent) && !preg_match('/Edg/i', $user_agent)) {
                                                    $browser = '<i class="fab fa-chrome"></i> Chrome';
                                                } elseif (preg_match('/Firefox/i', $user_agent)) {
                                                    $browser = '<i class="fab fa-firefox"></i> Firefox';
                                                } elseif (preg_match('/Safari/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
                                                    $browser = '<i class="fab fa-safari"></i> Safari';
                                                } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
                                                    $browser = '<i class="fab fa-opera"></i> Opera';
                                                }
                                                
                                                // Detect OS
                                                if (preg_match('/Windows NT 10/i', $user_agent)) {
                                                    $os = '<i class="fab fa-windows"></i> Win 10/11';
                                                } elseif (preg_match('/Windows/i', $user_agent)) {
                                                    $os = '<i class="fab fa-windows"></i> Windows';
                                                } elseif (preg_match('/Mac OS X/i', $user_agent)) {
                                                    $os = '<i class="fab fa-apple"></i> macOS';
                                                } elseif (preg_match('/Linux/i', $user_agent)) {
                                                    $os = '<i class="fab fa-linux"></i> Linux';
                                                } elseif (preg_match('/Android/i', $user_agent)) {
                                                    $os = '<i class="fab fa-android"></i> Android';
                                                } elseif (preg_match('/iPhone|iPad/i', $user_agent)) {
                                                    $os = '<i class="fab fa-apple"></i> iOS';
                                                }
                                                
                                                echo $browser . ' <span class="text-muted">â€¢</span> ' . $os;
                                            } else {
                                                echo 'Unknown';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                     <td>
                                          <?php if (!empty($log['login_time'])): ?>
                                              <div class="text-success small">
                                                  <i class="fas fa-sign-in-alt me-1"></i>
                                                  <?php echo date('M d, Y h:i A', strtotime($log['login_time'])); ?>
                                              </div>
                                          <?php else: ?>
                                              <span class="text-muted">-</span>
                                          <?php endif; ?>
                                      </td>
                                     <td>
                                         <?php if (!empty($log['logout_time'])): ?>
                                             <div class="text-danger small">
                                                 <i class="fas fa-sign-out-alt me-1"></i>
                                                 <?php echo date('M d, Y h:i A', strtotime($log['logout_time'])); ?>
                                             </div>
                                         <?php else: ?>
                                             <span class="text-success small fw-bold"><i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i> Currently Active</span>
                                         <?php endif; ?>
                                     </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination and Limit Controls -->
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-3">
                        <!-- Left side: Show entries info -->
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small">
                                Showing <?php echo min(($page - 1) * $per_page + 1, $total_logs); ?> 
                                to <?php echo min($page * $per_page, $total_logs); ?> 
                                of <?php echo $total_logs; ?> entries
                            </span>
                        </div>
                        
                        <!-- Right side: Items per page selector + Pagination -->
                        <div class="d-flex align-items-center gap-3">
                            <!-- Items per page selector -->
                            <div class="d-flex align-items-center gap-2">
                                <label class="text-muted small mb-0">Show:</label>
                                <select class="form-select form-select-sm" id="limitSelector" style="width: auto;" onchange="changeLimit(this.value)">
                                    <?php 
                                    // Dynamic limit options based on total logs
                                    $all_limits = [5, 10, 15, 20, 25, 30, 50, 75, 100, 150, 200, 300, 500];
                                    $dynamic_limits = [];
                                    
                                    // Always include small options (up to 30)
                                    foreach($all_limits as $limit) {
                                        if ($limit <= 30) {
                                            $dynamic_limits[] = $limit;
                                        }
                                    }
                                    
                                    // Add larger options only if they're less than total logs
                                    foreach($all_limits as $limit) {
                                        if ($limit > 30 && $limit < $total_logs) {
                                            $dynamic_limits[] = $limit;
                                        }
                                    }
                                    
                                    // Ensure current per_page is included
                                    if (!in_array($per_page, $dynamic_limits)) {
                                        $dynamic_limits[] = $per_page;
                                    }
                                    
                                    // Add "All" option if total is reasonable (â‰¤ 1000)
                                    if ($total_logs > 0 && $total_logs <= 1000) {
                                        $dynamic_limits[] = $total_logs;
                                    }
                                    
                                    // Remove duplicates and sort
                                    $dynamic_limits = array_unique($dynamic_limits);
                                    sort($dynamic_limits);
                                    
                                    foreach($dynamic_limits as $limit_option): 
                                    ?>
                                        <option value="<?php echo $limit_option; ?>" <?php echo $per_page == $limit_option ? 'selected' : ''; ?>>
                                            <?php 
                                            if ($limit_option == $total_logs) {
                                                echo "All ($limit_option)";
                                            } else {
                                                echo $limit_option . " per page";
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Pagination -->
                            <div>
                                <?php 
                                $base_url = build_pagination_url(['page']);
                                echo render_pagination($page, $total_pages, $total_logs, $per_page, $base_url, 'logs'); 
                                ?>
                            </div>
                        </div>
                    </div>
                    
                <?php endif; ?>
            </div>
    </div>

    <!-- Enhanced Session Details Modal -->
    <div class="modal fade enhanced-modal" id="sessionDetailsModal" tabindex="-1" aria-labelledby="sessionDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sessionDetailsModalLabel">
                        <i class="fas fa-user-clock"></i>
                        <span>Session Details: <span id="modalUsername" class="username-highlight"></span></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Session Summary -->
                    <div class="session-summary">
                        <div class="session-info-grid" id="modalSessionSummary">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>

                    <!-- Section Divider -->
                    <div class="section-divider">
                        <h6><i class="fas fa-tasks me-2"></i>Activities Summary</h6>
                        <div class="divider-line"></div>
                    </div>

                    <!-- Actions Summary -->
                    <div class="actions-summary" id="actionsSummaryContainer">
                        <!-- Will be populated by JavaScript -->
                    </div>

                    <!-- Section Divider -->
                    <div class="section-divider">
                        <h6><i class="fas fa-stream me-2"></i>Activity Timeline</h6>
                        <div class="divider-line"></div>
                    </div>

                    <!-- Timeline -->
                    <div class="timeline-container">
                        <div class="timeline" id="modalActivitiesList">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
        
        // Remove individual filter
        function removeFilter(filterName) {
            const form = document.getElementById('filterForm');
            const input = form.querySelector(`[name="${filterName}"]`);
            if (input) {
                input.value = '';
            }
            form.submit();
        }

        function viewSessionDetails(sessionId, username, detailsStr, loginTime, logoutTime, actionsList) {
            console.log('Opening session details:', { sessionId, username });
            
            // Set username in modal title
            const modalTitle = document.getElementById('modalUsername');
            if (modalTitle) modalTitle.textContent = username || 'Unknown User';
            
            // Populate session summary
            const summary = document.getElementById('modalSessionSummary');
            if (summary) {
                const isActive = !logoutTime;
                summary.innerHTML = `
                    <div class="session-info-item">
                        <span class="session-info-label">
                            <i class="fas fa-sign-in-alt me-1"></i>Login Time
                        </span>
                        <div class="session-info-value">
                            <i class="far fa-clock"></i>
                            ${loginTime ? formatDateTime(loginTime) : '<span class="text-muted">Not recorded</span>'}
                        </div>
                    </div>
                    <div class="session-info-item">
                        <span class="session-info-label">
                            <i class="fas fa-sign-out-alt me-1"></i>Logout Time
                        </span>
                        <div class="session-info-value">
                            ${isActive ? 
                                '<span class="status-active"><span class="pulse-dot"></span>Currently Active</span>' : 
                                `<i class="far fa-clock"></i>${formatDateTime(logoutTime)}`
                            }
                        </div>
                    </div>
                `;
            }

            // Populate actions summary
            const actionsContainer = document.getElementById('actionsSummaryContainer');
            if (actionsContainer) {
                const uniqueActions = [...new Set((actionsList || '').split(', ')
                    .map(a => a.trim().toLowerCase())
                    .filter(a => a))];
                
                if (uniqueActions.length === 0) {
                    actionsContainer.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No actions recorded</p></div>';
                } else {
                    let actionsHtml = '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
                    uniqueActions.forEach(action => {
                        const badgeClass = getActionBadgeClass(action);
                        const icon = getActionIcon(action);
                        actionsHtml += `
                            <span class="action-badge bg-${badgeClass} text-white">
                                <i class="${icon}"></i>
                                ${ucfirst(action.replace(/_/g, ' '))}
                            </span>
                        `;
                    });
                    actionsHtml += '</div>';
                    actionsContainer.innerHTML = actionsHtml;
                }
            }

            // Populate timeline
            const list = document.getElementById('modalActivitiesList');
            if (list) {
                if (!detailsStr || detailsStr.trim() === '') {
                    list.innerHTML = '<div class="empty-state"><i class="fas fa-history"></i><p>No detailed activity logs found</p></div>';
                } else {
                    list.innerHTML = '';
                    const actions = detailsStr.split(' || ');
                    
                    actions.forEach(actionStr => {
                        if (!actionStr.includes('::')) return;
                        
                        const [timestamp, type, desc] = actionStr.split('::');
                        
                        let timeStr = 'N/A';
                        let dateStr = 'N/A';
                        
                        if (timestamp) {
                            try {
                                const dateObj = new Date(timestamp.replace(' ', 'T'));
                                if (!isNaN(dateObj.getTime())) {
                                    timeStr = dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                    dateStr = dateObj.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
                                }
                            } catch (e) { 
                                console.error('Date parsing error:', e); 
                            }
                        }
                        
                        const item = document.createElement('div');
                        item.className = 'timeline-item';
                        
                        const badgeClass = getActionBadgeClass(type || 'default');
                        const icon = getActionIcon(type || 'default');
                        const displayType = ucfirst((type || 'action').replace(/_/g, ' '));
                        
                        item.innerHTML = `
                            <div class="timeline-marker" style="border-color: var(--bs-${badgeClass})"></div>
                            <div class="timeline-content">
                                <div class="timeline-time">
                                    <i class="far fa-calendar-alt"></i>
                                    ${dateStr} <span style="opacity: 0.5;">â€¢</span> ${timeStr}
                                </div>
                                <div class="timeline-header">
                                    <div class="timeline-title">
                                        <i class="${icon}"></i>
                                        ${displayType}
                                    </div>
                                    <span class="timeline-badge bg-${badgeClass} text-white">
                                        ${type || 'info'}
                                    </span>
                                </div>
                                <div class="timeline-description">${desc || 'Action recorded'}</div>
                            </div>
                        `;
                        list.appendChild(item);
                    });
                }
            }
            
            // Show modal
            try {
                const modalEl = document.getElementById('sessionDetailsModal');
                if (modalEl) {
                    let modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (!modalInstance) {
                        modalInstance = new bootstrap.Modal(modalEl);
                    }
                    modalInstance.show();
                } else {
                    console.error('Modal element not found: sessionDetailsModal');
                }
            } catch (err) {
                console.error('Bootstrap Modal Error:', err);
                alert('Could not open details modal. Please refresh the page.');
            }
        }

        function formatDateTime(dateStr) {
            try {
                if (!dateStr) return 'Not recorded';
                const dateObj = new Date(dateStr.replace(' ', 'T'));
                if (isNaN(dateObj.getTime())) return dateStr;
                
                const date = dateObj.toLocaleDateString([], { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
                const time = dateObj.toLocaleTimeString([], { 
                    hour: '2-digit', 
                    minute: '2-digit'
                });
                
                return `${date} at ${time}`;
            } catch (e) {
                return dateStr;
            }
        }

        function getActionIcon(type) {
            const icons = {
                'login': 'fas fa-sign-in-alt',
                'logout': 'fas fa-sign-out-alt',
                'add_admin': 'fas fa-user-plus',
                'create_admin': 'fas fa-user-plus',
                'edit_admin': 'fas fa-user-edit',
                'delete_admin': 'fas fa-user-times',
                'view_admin': 'fas fa-user-shield',
                'add_customer': 'fas fa-user-plus',
                'create_customer': 'fas fa-user-plus',
                'edit_customer': 'fas fa-user-edit',
                'delete_customer': 'fas fa-user-slash',
                'block_customer': 'fas fa-user-lock',
                'unblock_customer': 'fas fa-user-check',
                'view_customer': 'fas fa-user-tag',
                'add_book': 'fas fa-plus-circle',
                'create_book': 'fas fa-plus-circle',
                'edit_book': 'fas fa-edit',
                'delete_book': 'fas fa-trash-alt',
                'view_book': 'fas fa-book',
                'book_view': 'fas fa-book-open',
                'create_order': 'fas fa-file-invoice',
                'edit_order': 'fas fa-file-signature',
                'cancel_order': 'fas fa-file-excel',
                'view_order': 'fas fa-file-alt',
                'approve_review': 'fas fa-check-circle',
                'reject_review': 'fas fa-times-circle',
                'delete_review': 'fas fa-comment-slash',
                'change_settings': 'fas fa-cog',
                'update_settings': 'fas fa-cog',
                'reset_password': 'fas fa-key',
                'permission_denied': 'fas fa-exclamation-triangle',
                'page_view': 'fas fa-eye',
                'search': 'fas fa-search',
                'add_to_cart': 'fas fa-shopping-cart',
                'remove_from_cart': 'fas fa-cart-arrow-down',
                'checkout': 'fas fa-cash-register',
                'purchase': 'fas fa-shopping-bag',
                'review': 'fas fa-star',
                'wishlist': 'fas fa-heart',
                'wishlist_add': 'fas fa-heart',
                'wishlist_remove': 'fas fa-heart-broken',
                'profile_update': 'fas fa-user-edit'
            };
            return icons[type] || 'fas fa-dot-circle';
        }

        function getActionBadgeClass(type) {
            const classes = {
                'login': 'success',
                'logout': 'info',
                'add_admin': 'primary',
                'create_admin': 'primary',
                'edit_admin': 'warning',
                'delete_admin': 'danger',
                'add_customer': 'primary',
                'create_customer': 'primary',
                'edit_customer': 'warning',
                'delete_customer': 'danger',
                'block_customer': 'dark',
                'unblock_customer': 'success',
                'add_book': 'primary',
                'create_book': 'primary',
                'edit_book': 'warning',
                'delete_book': 'danger',
                'cancel_order': 'danger',
                'reject_review': 'danger',
                'approve_review': 'success',
                'permission_denied': 'danger',
                'change_settings': 'dark',
                'update_settings': 'dark',
                'reset_password': 'secondary',
                'page_view': 'primary',
                'book_view': 'warning',
                'search': 'secondary',
                'add_to_cart': 'dark',
                'remove_from_cart': 'danger',
                'checkout': 'success',
                'purchase': 'success',
                'review': 'info',
                'wishlist': 'danger',
                'wishlist_add': 'danger',
                'wishlist_remove': 'secondary',
                'profile_update': 'primary'
            };
            return classes[type.toLowerCase()] || 'secondary';
        }

        function ucfirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        // Use standard event handling for the buttons
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.view-details-btn');
            if (btn) {
                e.preventDefault();
                const sessionId = btn.getAttribute('data-session-id');
                const username = btn.getAttribute('data-username');
                const details = btn.getAttribute('data-details');
                const loginTime = btn.getAttribute('data-login-time');
                const logoutTime = btn.getAttribute('data-logout-time');
                const actionsList = btn.getAttribute('data-actions');
                viewSessionDetails(sessionId, username, details, loginTime, logoutTime, actionsList);
            }
        });

        // Toggle export menu
        function toggleExportMenu(event) {
            event.stopPropagation();
            const menu = document.getElementById('exportMenu');
            menu.classList.toggle('show');
        }
        
        // Close export menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('exportMenu');
            if (menu && menu.classList.contains('show')) {
                menu.classList.remove('show');
            }
        });
        
        // Real-time log updates
        let lastLogId = 0;
        let isFirstLoad = true;
        
        // Get the current highest log ID on page load
        function initializeLastLogId() {
            const rows = document.querySelectorAll('tbody tr');
            if (rows.length > 0) {
                // Assuming logs are ordered by ID DESC, first row has highest ID
                const firstRow = rows[0];
                // Store the timestamp of the first log as reference
                const timestampCell = firstRow.querySelector('td[data-timestamp]');
                if (timestampCell) {
                    lastLogId = timestampCell.getAttribute('data-timestamp');
                }
            }
            isFirstLoad = false;
        }
        
        // Check for new logs
        function checkForNewLogs() {
            fetch('get_new_admin_logs.php?last_check=' + encodeURIComponent(lastLogId))
                .then(response => response.json())
                .then(data => {
                    if (data.has_new_logs && data.new_logs.length > 0) {
                        // Check if any of the new logs have logout times
                        const hasLogouts = data.new_logs.some(log => log.logout_time !== null);
                        
                        // Show notification
                        if (hasLogouts) {
                            showNewLogNotification(data.new_logs.length, 'Logout times updated');
                        } else {
                            showNewLogNotification(data.new_logs.length, 'New logs detected');
                        }
                        
                        // Auto-reload to show new logs
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    }
                })
                .catch(error => {
                    console.error('Error checking for new logs:', error);
                });
        }
        
        // Show notification for new logs
        function showNewLogNotification(count, message) {
            message = message || 'New logs detected';
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
            notification.innerHTML = `
                <i class="fas fa-bell me-2"></i>
                <strong>${message}!</strong> Refreshing...
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeLastLogId();
        });
        
        // Dynamic limit change function
        function changeLimit(newLimit) {
            const url = new URL(window.location.href);
            url.searchParams.set('limit', newLimit);
            url.searchParams.set('page', '1'); // Reset to first page when changing limit
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
