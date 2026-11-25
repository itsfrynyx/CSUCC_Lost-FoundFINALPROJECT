<?php
session_start();
require_once '../DB/conn.php';
require_once 'includes/pagination_helper.php';

// Check that the signed-in account is still an authorized administrator.
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT role FROM infos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$current_role = strtolower(trim($admin['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    header('Location: Sign_in.php');
    exit();
}
$is_super_admin = $current_role === 'super_admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = '';

// Both administrator roles may review pending customer registrations.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Your session expired. Refresh the page and try again.';
        $message_type = 'danger';
    } else {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $action = $_POST['action'];
        $notes = trim($_POST['notes'] ?? '');

        if (!$user_id || !in_array($action, ['approve', 'reject'], true)) {
            $message = 'Invalid registration review request.';
            $message_type = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                $new_approval_status = $action === 'approve' ? 1 : 2;
                $update_stmt = $conn->prepare("UPDATE infos SET approved = ?, approval_date = NOW(), approved_by = ? WHERE id = ? AND role = 'customer' AND approved = 0");
                $update_stmt->bind_param("iii", $new_approval_status, $admin_id, $user_id);
                $update_stmt->execute();

                if ($update_stmt->affected_rows !== 1) {
                    $conn->rollback();
                    $message = 'This pending customer registration was not found or has already been reviewed.';
                    $message_type = 'warning';
                } else {
                    $status = $action === 'approve' ? 'approved' : 'rejected';
                    $approval_stmt = $conn->prepare("UPDATE pending_approvals SET status = ?, reviewed_by = ?, reviewed_date = NOW(), notes = ? WHERE user_id = ? AND status = 'pending'");
                    $approval_stmt->bind_param("sisi", $status, $admin_id, $notes, $user_id);
                    $approval_stmt->execute();
                    $conn->commit();
                    $message = $action === 'approve' ? 'Account approved successfully!' : 'Account rejected.';
                    $message_type = $action === 'approve' ? 'success' : 'warning';
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                throw $exception;
            }
        }
    }
}

// Pagination setup for pending approvals - 10 per page
$pending_per_page = 10;
$pending_page = isset($_GET['pending_page']) ? max(1, intval($_GET['pending_page'])) : 1;
$pending_offset = ($pending_page - 1) * $pending_per_page;
$pending_search = trim($_GET['search'] ?? '');
$pending_search_pattern = '%' . $pending_search . '%';

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM infos WHERE role = 'customer' AND approved = 0 AND (idnumber LIKE ? OR username LIKE ? OR full_name LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)");
$count_stmt->bind_param("ssssss", $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern);
$count_stmt->execute();
$total_pending_count = (int) $count_stmt->get_result()->fetch_assoc()['total'];
$total_pending_pages = ceil($total_pending_count / $pending_per_page);

// Search pending customer registrations and paginate the matching records.
$pending_stmt = $conn->prepare("
    SELECT i.*, 
           pa.registration_date, 
           pa.notes,
           COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as full_name,
           CONCAT(i.street, ', ', i.barangay, ', ', i.municipality) as address
    FROM infos i
    LEFT JOIN pending_approvals pa ON i.id = pa.user_id AND pa.status = 'pending'
    WHERE i.role = 'customer' AND i.approved = 0
      AND (i.idnumber LIKE ? OR i.username LIKE ? OR i.full_name LIKE ? OR i.firstname LIKE ? OR i.lastname LIKE ? OR i.email LIKE ?)
    ORDER BY i.created_at DESC
    LIMIT ? OFFSET ?
");
$pending_stmt->bind_param("ssssssii", $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_search_pattern, $pending_per_page, $pending_offset);
$pending_stmt->execute();
$pending_query = $pending_stmt->get_result();
$pending_pagination_query = http_build_query(array_diff_key($_GET, ['pending_page' => true]));
$pending_pagination_url = $pending_pagination_query === '' ? '' : '?' . $pending_pagination_query;

// Get approved/rejected history
$history_query = $conn->query("
    SELECT pa.*, 
           i.username, 
           i.email,
           COALESCE(i.full_name, CONCAT(i.firstname, ' ', COALESCE(i.middlename, ''), ' ', i.lastname)) as full_name,
           COALESCE(admin.full_name, CONCAT(admin.firstname, ' ', admin.lastname)) as reviewed_by_name
    FROM pending_approvals pa
    JOIN infos i ON pa.user_id = i.id
    LEFT JOIN infos admin ON pa.reviewed_by = admin.id
    WHERE pa.status IN ('approved', 'rejected')
    ORDER BY pa.reviewed_date DESC
    LIMIT 50
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        /* Page-specific styles only */
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: transparent !important;
        }
        
        /* Remove all background colors from stat icons */
        .stat-icon.bg-warning,
        .stat-icon.bg-success,
        .stat-icon.bg-danger,
        .bg-warning.bg-opacity-10,
        .bg-success.bg-opacity-10,
        .bg-danger.bg-opacity-10 {
            background: transparent !important;
            background-color: transparent !important;
        }
        
        .stat-icon.text-warning {
            color: #F59E0B !important;
        }
        
        .stat-icon.text-success {
            color: #10B981 !important;
        }
        
        .stat-icon.text-danger {
            color: #EF4444 !important;
        }
        
        /* Section Header Icons - Specific Colors */
        .card-header h5 i.fa-user-clock {
            color: #F59E0B !important; /* Orange for Awaiting Review */
        }
        
        .card-header h5 i.fa-history {
            color: #3B82F6 !important; /* Blue for Approval History */
        }
        
        /* Badge in header - remove background, just colored text */
        .card-header .badge {
            background: transparent !important;
            color: #F59E0B !important;
            border: none !important;
            font-weight: 600;
            padding: 6px 12px;
        }
        
        .card-header .badge.bg-warning {
            background: transparent !important;
            color: #F59E0B !important;
            border: none !important;
        }
        
        /* Top header clock icon - apply orange color */
        .top-header small i.fa-clock {
            color: #F59E0B !important;
        }
        
        /* Minimalist Table Styles */
        .table-minimal {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table-minimal thead th {
            background: #F8FAFC;
            border-bottom: 2px solid #E2E8F0;
            color: #64748B;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            white-space: nowrap;
        }
        
        .table-minimal tbody tr {
            border-bottom: 1px solid #F1F5F9;
            transition: background-color 0.2s;
        }
        
        .table-minimal tbody tr:hover {
            background-color: #F8FAFC;
        }
        
        .table-minimal tbody td {
            padding: 16px;
            vertical-align: middle;
            font-size: 13px;
            color: #1F2937;
        }
        
        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .user-info h6 {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        
        .user-info p {
            margin: 0;
            font-size: 12px;
            color: #64748B;
        }
        
        .badge-minimal {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .badge-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid;
            transition: all 0.2s;
            font-size: 13px;
        }
        
        .btn-icon.btn-approve {
            background: #ECFDF5;
            border-color: #D1FAE5;
            color: #065F46;
        }
        
        .btn-icon.btn-approve:hover {
            background: #D1FAE5;
            transform: translateY(-1px);
        }
        
        .btn-icon.btn-reject {
            background: #FEF2F2;
            border-color: #FECACA;
            color: #991B1B;
        }
        
        .btn-icon.btn-reject:hover {
            background: #FECACA;
            transform: translateY(-1px);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #CBD5E1;
            margin-bottom: 16px;
        }
        
        .empty-state h5 {
            color: #64748B;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .empty-state p {
            color: #94A3B8;
            font-size: 13px;
        }
        
        /* Professional Aesthetic Modal Styles */
        .aesthetic-modal .modal-content {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            background: #FFFFFF;
            overflow: visible;
        }
        
        .aesthetic-modal .modal-header {
            border: none;
            padding: 40px 48px 0;
            position: relative;
        }
        
        .aesthetic-modal .modal-body {
            padding: 24px 48px 40px;
        }
        
        .aesthetic-modal .modal-footer {
            border: none;
            padding: 0 48px 40px;
            gap: 12px;
        }
        
        .aesthetic-modal .btn-close-aesthetic {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #F1F5F9;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            z-index: 10;
        }
        
        .aesthetic-modal .btn-close-aesthetic:hover {
            background: #E2E8F0;
            transform: scale(1.05);
        }
        
        .aesthetic-modal .btn-close-aesthetic i {
            color: #64748B;
            font-size: 16px;
        }
        
        .aesthetic-modal .modal-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: white;
        }
        
        .aesthetic-modal .modal-icon.danger {
            background: #EF4444;
        }
        
        .aesthetic-modal .modal-icon.info {
            background: #3B82F6;
        }
        
        .aesthetic-modal .aesthetic-title {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            text-align: center;
            margin-bottom: 8px;
            font-family: 'Inter', sans-serif;
        }
        
        .aesthetic-modal .aesthetic-subtitle {
            font-size: 15px;
            color: #64748B;
            text-align: center;
            margin-bottom: 32px;
            font-family: 'Inter', sans-serif;
        }
        
        .aesthetic-modal .aesthetic-form label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            display: block;
            font-family: 'Inter', sans-serif;
        }
        
        .aesthetic-modal .aesthetic-form textarea {
            width: 100%;
            height: 120px;
            padding: 12px 16px;
            border: 1.5px solid #E2E8F0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #1F2937;
            background: #FFFFFF;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            resize: vertical;
        }
        
        .aesthetic-modal .aesthetic-form textarea:focus {
            outline: none;
            border-color: #EF4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        .aesthetic-modal .aesthetic-form textarea::placeholder {
            color: #94A3B8;
        }
        
        .aesthetic-modal .aesthetic-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .aesthetic-modal .aesthetic-buttons button {
            height: 48px;
            min-width: 160px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .aesthetic-modal .btn-cancel {
            background: #F1F5F9;
            color: #475569;
        }
        
        .aesthetic-modal .btn-cancel:hover {
            background: #E2E8F0;
            transform: translateY(-1px);
        }
        
        .aesthetic-modal .btn-reject {
            background: #EF4444;
            color: white;
        }
        
        .aesthetic-modal .btn-reject:hover {
            transform: none;
            box-shadow: none;
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
                <h4 class="mb-0 ms-3">Pending Approvals</h4>
            </div>
            <small class="text-muted">
                <i class="fas fa-clock me-2"></i>
                <?php echo $pending_query->num_rows; ?> pending
            </small>
        </div>

        <!-- Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?php echo $pending_query->num_rows; ?></h3>
                                <p class="text-muted mb-0 small">Pending Approval</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?php echo $conn->query("SELECT COUNT(*) as count FROM pending_approvals WHERE status = 'approved'")->fetch_assoc()['count']; ?></h3>
                                <p class="text-muted mb-0 small">Approved</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?php echo $conn->query("SELECT COUNT(*) as count FROM pending_approvals WHERE status = 'rejected'")->fetch_assoc()['count']; ?></h3>
                                <p class="text-muted mb-0 small">Rejected</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Approvals Card -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-user-clock me-2"></i>
                    Awaiting Review
                </h5>
                <span class="badge bg-warning"><?php echo $total_pending_count; ?></span>
            </div>
            <form method="GET" class="p-3 border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label for="pendingSearch" class="form-label">Filter registrations</label>
                        <input type="search" class="form-control" id="pendingSearch" name="search"
                               value="<?php echo htmlspecialchars($pending_search, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Search ID number, name, username, or email">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="pending_approvals.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>
            <div class="card-body p-0">
                <?php if ($pending_query->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-minimal mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Age</th>
                                <th>Location</th>
                                <th>Registered</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $pending_query->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-sm">
                                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="user-info">
                                            <h6><?php echo htmlspecialchars($user['full_name']); ?></h6>
                                            <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['age']); ?></td>
                                <td><?php echo htmlspecialchars($user['municipality']); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge-minimal badge-pending">Pending</span>
                                </td>
                                <td>
                                    <div class="action-buttons justify-content-end">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Approve this account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="notes" value="Approved by <?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?>">
                                            <button type="submit" class="btn-icon btn-approve" title="Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <button class="btn-icon btn-reject" 
                                                onclick="showRejectModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                                title="Reject">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="p-3 border-top">
                    <?php echo render_pagination($pending_page, $total_pending_pages, $total_pending_count, $pending_per_page, $pending_pagination_url, 'pending approvals'); ?>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h5>All Caught Up!</h5>
                        <p>No pending approvals at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- History Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>
                    Approval History
                </h5>
            </div>
            <div class="card-body">
                <?php if ($history_query->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Reviewed By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($history = $history_query->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history['username']); ?></td>
                                <td><?php echo htmlspecialchars($history['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($history['email']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $history['status'] === 'approved' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($history['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($history['reviewed_by_name'] ?? 'Unknown'); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y h:i A', strtotime($history['reviewed_date'])); ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3 opacity-25"></i>
                        <h5 class="text-muted">No History Yet</h5>
                        <p class="text-muted">Approval history will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade aesthetic-modal" id="rejectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content">
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                </button>
                <form method="POST">
                    <div class="modal-header">
                        <div class="w-100">
                            <div class="modal-icon danger">
                                <i class="fas fa-user-times"></i>
                            </div>
                            <h5 class="aesthetic-title">Reject Account</h5>
                            <p class="aesthetic-subtitle">Reject registration for <strong id="reject_username"></strong></p>
                        </div>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="reject_user_id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="reject">
                        <div class="aesthetic-form">
                            <label>Reason (Optional)</label>
                            <textarea name="notes" placeholder="Enter rejection reason..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-cancel" data-bs-dismiss="modal">
                                <i class="fas fa-times"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn-reject">
                                <i class="fas fa-ban"></i>
                                Reject Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showRejectModal(userId, username) {
        document.getElementById('reject_user_id').value = userId;
        document.getElementById('reject_username').textContent = username;
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    }
    
    // Auto-dismiss alerts
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
    </script>
</body>
</html>
