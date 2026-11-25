<?php
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/includes/superadmin_confirmation.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Check if user has super admin role
$current_admin = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$current_admin->bind_param("i", $_SESSION['super_admin_id']);
$current_admin->execute();
$current_role_raw = $current_admin->get_result()->fetch_assoc()['role'] ?? '';
$current_role = strtolower(trim($current_role_raw));

if ($current_role !== 'super_admin') {
    header('Location: unified_admin_dashboard.php');
    exit();
}

// Fetch admin data
$admin_stmt = $conn->prepare("SELECT id, username, email, full_name, profile_image FROM infos WHERE id = ?");
$admin_stmt->bind_param("i", $_SESSION['super_admin_id']);
$admin_stmt->execute();
$admin_data = $admin_stmt->get_result()->fetch_assoc();

// Pagination
$per_page = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Keep this page usable on existing installations that predate the deletion
// proof feature.  The same schema is used by the deletion handlers.
$create_deletion_proofs_table = "CREATE TABLE IF NOT EXISTS deletion_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deleted_user_id INT NOT NULL,
    deleted_user_type ENUM('admin', 'customer') NOT NULL,
    deleted_username VARCHAR(100) NOT NULL,
    deleted_full_name VARCHAR(255),
    proof_image VARCHAR(255) NOT NULL,
    deletion_reason TEXT,
    deleted_by INT NOT NULL,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    INDEX idx_deleted_user (deleted_user_id, deleted_user_type),
    INDEX idx_deleted_by (deleted_by),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($create_deletion_proofs_table)) {
    http_response_code(500);
    exit('Unable to prepare the deletion-proofs audit table. Please contact the system administrator.');
}

$create_deletion_requests_table = "CREATE TABLE IF NOT EXISTS deletion_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_user_id INT NOT NULL,
    target_idnumber VARCHAR(50),
    target_username VARCHAR(100) NOT NULL,
    target_full_name VARCHAR(255),
    target_email VARCHAR(255),
    target_details LONGTEXT,
    reason TEXT NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deletion_request_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$request_table_created = $conn->query($create_deletion_requests_table);
if ($request_table_created === false) {
    http_response_code(500);
    exit('Unable to prepare the deletion-request review table. Please contact the system administrator.');
}
$request_details_column = $conn->query("SHOW COLUMNS FROM deletion_requests LIKE 'target_details'");
if ($request_details_column === false) {
    http_response_code(500);
    exit('Unable to inspect the deletion-request review table. Please contact the system administrator.');
}
if ($request_details_column->num_rows === 0 && !$conn->query("ALTER TABLE deletion_requests ADD COLUMN target_details LONGTEXT NULL AFTER target_email")) {
    http_response_code(500);
    exit('Unable to update the deletion-request review table. Please contact the system administrator.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['deletion_review_message'] = 'Your session expired. Refresh the page and try again.';
        header('Location: deletion_proofs_audit.php');
        exit();
    }

    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $request_action = $_POST['request_action'];
    if (!$request_id || !in_array($request_action, ['approve', 'reject'], true)) {
        $_SESSION['deletion_review_message'] = 'Invalid deletion review request.';
    } elseif ($request_action === 'approve' && !confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
        $_SESSION['deletion_review_message'] = 'Enter your correct Super Administrator password to approve this deletion.';
    } else {
        $conn->begin_transaction();
        try {
            $request_stmt = $conn->prepare("SELECT * FROM deletion_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
            $request_stmt->bind_param('i', $request_id);
            $request_stmt->execute();
            $request = $request_stmt->get_result()->fetch_assoc();

            if ($request) {
                if ($request_action === 'approve') {
                    $delete_stmt = $conn->prepare("DELETE FROM infos WHERE id = ? AND role = 'customer'");
                    $delete_stmt->bind_param('i', $request['target_user_id']);
                    $delete_stmt->execute();
                    $status = $delete_stmt->affected_rows > 0 ? 'approved' : 'rejected';
                } else {
                    $status = 'rejected';
                }

                $review_stmt = $conn->prepare("UPDATE deletion_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
                $review_stmt->bind_param('sii', $status, $_SESSION['super_admin_id'], $request_id);
                $review_stmt->execute();
                if ($review_stmt->affected_rows !== 1) {
                    throw new RuntimeException('The deletion request could not be marked as reviewed.');
                }
                $conn->commit();
                $_SESSION['deletion_review_message'] = $status === 'approved' ? 'Deletion request approved.' : 'Deletion request rejected.';
            } else {
                $conn->rollback();
                $_SESSION['deletion_review_message'] = 'The pending deletion request was not found or was already reviewed.';
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Deletion request review failed: ' . $exception->getMessage());
            $_SESSION['deletion_review_message'] = 'Unable to process the deletion request. Please try again or contact the system administrator.';
        }
    }
    header('Location: deletion_proofs_audit.php');
    exit();
}

$deletion_review_message = $_SESSION['deletion_review_message'] ?? '';
unset($_SESSION['deletion_review_message']);
$pending_requests = $conn->query("SELECT dr.*, i.full_name AS requested_by_name FROM deletion_requests dr LEFT JOIN infos i ON dr.requested_by = i.id WHERE dr.status = 'pending' ORDER BY dr.created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Get total count
$total_count = $conn->query("SELECT COUNT(*) as total FROM deletion_proofs")->fetch_assoc()['total'];
$total_pages = ceil($total_count / $per_page);

// Fetch deletion proofs with admin info
$query = "SELECT 
    dp.*,
    i.username as deleted_by_username,
    i.full_name as deleted_by_fullname
FROM deletion_proofs dp
LEFT JOIN infos i ON dp.deleted_by = i.id
ORDER BY dp.deleted_at DESC
LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$proofs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-user-clock text-warning me-2"></i>
                    Pending Deletion Requests (<?php echo count($pending_requests); ?>)
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID Number</th>
                                <th>Complete User Information</th>
                                <th>Reason</th>
                                <th>Requested By</th>
                                <th>Date</th>
                                <th>Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_requests)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No pending deletion requests.</td></tr>
                            <?php else: foreach ($pending_requests as $request): ?>
                            <?php
                                $target_details = json_decode($request['target_details'] ?? '', true);
                                $detail_labels = [
                                    'sex' => 'Sex',
                                    'birth' => 'Birth Date',
                                    'age' => 'Age',
                                    'street' => 'Street',
                                    'barangay' => 'Barangay',
                                    'municipality' => 'Municipality',
                                    'province' => 'Province',
                                    'postal' => 'Postal Code',
                                    'country' => 'Country',
                                    'status' => 'Account Status',
                                ];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($request['target_idnumber'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['target_full_name'] ?? 'N/A'); ?></strong><br>
                                    <small>Username: <?php echo htmlspecialchars($request['target_username']); ?></small><br>
                                    <small>Email: <?php echo htmlspecialchars($request['target_email'] ?? 'N/A'); ?></small>
                                    <?php if (is_array($target_details)): ?>
                                    <details class="mt-2">
                                        <summary>View complete account information</summary>
                                        <div class="mt-2">
                                            <?php foreach ($detail_labels as $detail_key => $detail_label): ?>
                                                <?php if (!empty($target_details[$detail_key])): ?>
                                                <div><strong><?php echo htmlspecialchars($detail_label); ?>:</strong>
                                                    <?php echo htmlspecialchars((string) $target_details[$detail_key]); ?></div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($request['reason'])); ?></td>
                                <td><?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($request['created_at'])); ?></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                        <input type="password" name="superadmin_password" class="form-control form-control-sm mb-2" placeholder="Super Admin password required to approve" autocomplete="current-password">
                                        <button name="request_action" value="approve" class="btn btn-sm btn-danger" onclick="return confirm('Approve deletion of this customer account?');">Approve Delete</button>
                                        <button name="request_action" value="reject" class="btn btn-sm btn-outline-secondary">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deletion Proofs Audit - Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
</head>
<body>
    
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0">Deletion Proofs Audit</h4>
                    <small class="text-muted">Review all deletion records with proof documentation</small>
                </div>
            </div>
        </div>
        <?php if ($deletion_review_message !== ''): ?>
        <div class="alert alert-info" role="status"><?php echo htmlspecialchars($deletion_review_message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-shield-alt text-primary me-2"></i>
                    Deletion Records (<?php echo $total_count; ?> total)
                </h5>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Deleted User</th>
                                <th>Type</th>
                                <th>Proof Image</th>
                                <th>Reason</th>
                                <th>Deleted By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($proofs)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No deletion records found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($proofs as $proof): ?>
                            <tr>
                                <td><strong>#<?php echo $proof['id']; ?></strong></td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($proof['deleted_username']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($proof['deleted_full_name']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $proof['deleted_user_type'] === 'admin' ? 'primary' : 'info'; ?>">
                                        <?php echo ucfirst($proof['deleted_user_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $ext = pathinfo($proof['proof_image'], PATHINFO_EXTENSION);
                                    if (in_array($ext, ['jpg', 'jpeg', 'png'])): 
                                    ?>
                                    <img src="../uploads/deletion_proofs/<?php echo htmlspecialchars($proof['proof_image']); ?>" 
                                         alt="Proof" 
                                         style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px; cursor: pointer;"
                                         onclick="viewProof('<?php echo htmlspecialchars($proof['proof_image']); ?>', '<?php echo $ext; ?>')">
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            onclick="viewProof('<?php echo htmlspecialchars($proof['proof_image']); ?>', 'pdf')">
                                        <i class="fas fa-file-pdf"></i> View PDF
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($proof['deletion_reason']): ?>
                                    <small><?php echo htmlspecialchars(substr($proof['deletion_reason'], 0, 50)); ?><?php echo strlen($proof['deletion_reason']) > 50 ? '...' : ''; ?></small>
                                    <?php else: ?>
                                    <small class="text-muted">No reason provided</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        <?php echo htmlspecialchars($proof['deleted_by_fullname'] ?? 'Unknown'); ?>
                                        <br>
                                        <span class="text-muted"><?php echo htmlspecialchars($proof['ip_address']); ?></span>
                                    </small>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($proof['deleted_at'])); ?></small>
                                    <br>
                                    <small class="text-muted"><?php echo date('h:i A', strtotime($proof['deleted_at'])); ?></small>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            onclick="viewDetails(<?php echo htmlspecialchars(json_encode($proof)); ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Proof Viewer Modal -->
    <div class="modal fade" id="proofModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deletion Proof</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center" id="proofContent">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deletion Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
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
        
        function viewProof(filename, type) {
            const content = document.getElementById('proofContent');
            if (type === 'pdf') {
                content.innerHTML = `<iframe src="../uploads/deletion_proofs/${filename}" style="width: 100%; height: 600px; border: none;"></iframe>`;
            } else {
                content.innerHTML = `<img src="../uploads/deletion_proofs/${filename}" style="max-width: 100%; max-height: 600px; border-radius: 8px;">`;
            }
            new bootstrap.Modal(document.getElementById('proofModal')).show();
        }
        
        function viewDetails(proof) {
            const content = document.getElementById('detailsContent');
            content.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Deleted User ID:</strong><br>
                        ${proof.deleted_user_id}
                    </div>
                    <div class="col-md-6">
                        <strong>User Type:</strong><br>
                        <span class="badge bg-${proof.deleted_user_type === 'admin' ? 'primary' : 'info'}">${proof.deleted_user_type}</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Username:</strong><br>
                        ${proof.deleted_username}
                    </div>
                    <div class="col-md-6">
                        <strong>Full Name:</strong><br>
                        ${proof.deleted_full_name || 'N/A'}
                    </div>
                    <div class="col-12">
                        <strong>Deletion Reason:</strong><br>
                        ${proof.deletion_reason || '<em class="text-muted">No reason provided</em>'}
                    </div>
                    <div class="col-md-6">
                        <strong>Deleted By:</strong><br>
                        ${proof.deleted_by_fullname || 'Unknown'} (ID: ${proof.deleted_by})
                    </div>
                    <div class="col-md-6">
                        <strong>IP Address:</strong><br>
                        ${proof.ip_address}
                    </div>
                    <div class="col-md-6">
                        <strong>Date & Time:</strong><br>
                        ${new Date(proof.deleted_at).toLocaleString()}
                    </div>
                    <div class="col-md-6">
                        <strong>Proof File:</strong><br>
                        ${proof.proof_image}
                    </div>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }
    </script>
</body>
</html>
