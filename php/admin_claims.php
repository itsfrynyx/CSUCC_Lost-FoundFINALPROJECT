<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/includes/RBAC.php';

if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$rbac = new RBAC($conn, $_SESSION['super_admin_id']);
if (!$rbac->hasAnyPermission(['claims.review'])) {
    http_response_code(403);
    echo 'Access denied. You do not have claim review permission.';
    exit();
}

$requiredTables = ['found_items', 'claims'];
foreach ($requiredTables as $tableName) {
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if ($tableCheck && $tableCheck->num_rows === 0) {
        $createSql = "
            CREATE TABLE IF NOT EXISTS $tableName (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                item_name VARCHAR(150) NOT NULL,
                category VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                color VARCHAR(80) DEFAULT NULL,
                brand VARCHAR(100) DEFAULT NULL,
                model VARCHAR(100) DEFAULT NULL,
                date_found DATE DEFAULT NULL,
                time_found TIME DEFAULT NULL,
                location_found VARCHAR(255) NOT NULL,
                distinguishing_features TEXT DEFAULT NULL,
                storage_location VARCHAR(255) DEFAULT NULL,
                image_path VARCHAR(255) DEFAULT NULL,
                status ENUM('pending','verified','claimed','returned','closed') NOT NULL DEFAULT 'pending',
                reviewed_by INT DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_id (user_id),
                KEY idx_status (status),
                KEY idx_category (category),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        if ($tableName === 'claims') {
            $createSql = "
                CREATE TABLE IF NOT EXISTS claims (
                    id INT NOT NULL AUTO_INCREMENT,
                    found_item_id INT NOT NULL,
                    claimant_id INT NOT NULL,
                    item_description TEXT NOT NULL,
                    unique_identifying_features TEXT DEFAULT NULL,
                    proof_image_path VARCHAR(255) DEFAULT NULL,
                    status ENUM('pending','under_review','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending',
                    reviewed_by INT DEFAULT NULL,
                    reviewed_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_found_item_id (found_item_id),
                    KEY idx_claimant_id (claimant_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
        }

        if (!$conn->query($createSql)) {
            http_response_code(500);
            echo 'Failed to initialize the required claims schema: ' . htmlspecialchars($conn->error);
            exit();
        }
    }
}

$message = '';
$messageType = '';

$_SESSION['review_form_token'] ??= bin2hex(random_bytes(32));
$invalidReviewToken = $_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['review_form_token'], account_input('review_token', false));
if ($invalidReviewToken) {
    $message = 'This review form expired. Reload the page and try again.';
    $messageType = 'danger';
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_claim'])) {
    $claimId = account_positive_integer($_POST['claim_id'] ?? null);
    $status = account_input('status');
    $allowed = ['pending', 'under_review', 'approved', 'rejected', 'returned', 'cancelled'];

    if ($claimId <= 0 || !in_array($status, $allowed, true)) {
        $message = 'Invalid claim review request.';
        $messageType = 'danger';
    } else {
        $stmt = $conn->prepare(
            "UPDATE claims SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('sii', $status, $_SESSION['super_admin_id'], $claimId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = 'Claim status updated successfully.';
            $messageType = 'success';
        } else {
            $message = 'No matching record was updated.';
            $messageType = 'danger';
        }
        $stmt->close();
    }
}

$claimsStmt = $conn->prepare(
    "SELECT c.*, f.item_name AS found_item_name, claimant.full_name AS claimant_name
     FROM claims c
     JOIN found_items f ON f.id = c.found_item_id
     JOIN infos claimant ON claimant.id = c.claimant_id
     ORDER BY c.created_at DESC"
);
$claimsStmt->execute();
$claims = $claimsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$claimsStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Review - Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
                    <h4 class="mb-0">Claim Review</h4>
                    <small class="text-muted">Review and update found item claims</small>
                </div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-clipboard-check text-primary me-2"></i>
                    All Claim Requests
                </h5>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Claimant</th>
                                <th>Found Item</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($claims)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No claims found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($claims as $claim): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($claim['claimant_name'] ?? 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($claim['found_item_name']) ?></td>
                                        <td><?= htmlspecialchars($claim['item_description']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($claim['status']) ?></span>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="review_token" value="<?= htmlspecialchars($_SESSION['review_form_token']) ?>">
                                                <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <option value="pending" <?= $claim['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="under_review" <?= $claim['status'] === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                                                    <option value="approved" <?= $claim['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                                    <option value="rejected" <?= $claim['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                    <option value="returned" <?= $claim['status'] === 'returned' ? 'selected' : '' ?>>Returned</option>
                                                    <option value="cancelled" <?= $claim['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                </select>
                                                <button type="submit" name="review_claim" class="btn btn-sm btn-primary">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
