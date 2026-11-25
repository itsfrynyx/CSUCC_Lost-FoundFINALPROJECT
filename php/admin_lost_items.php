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
if (!$rbac->hasAnyPermission(['lost_items.review', 'lost_items.manage'])) {
    http_response_code(403);
    echo 'Access denied. You do not have lost item review permission.';
    exit();
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'lost_items'");
if ($tableCheck && $tableCheck->num_rows === 0) {
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS lost_items (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            color VARCHAR(80) DEFAULT NULL,
            brand VARCHAR(100) DEFAULT NULL,
            model VARCHAR(100) DEFAULT NULL,
            date_lost DATE DEFAULT NULL,
            time_lost TIME DEFAULT NULL,
            location_lost VARCHAR(255) NOT NULL,
            distinguishing_features TEXT DEFAULT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','verified','possible_match','recovered','closed') NOT NULL DEFAULT 'pending',
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

    if (!$conn->query($createTableSql)) {
        http_response_code(500);
        echo 'Failed to initialize the lost_items table: ' . htmlspecialchars($conn->error);
        exit();
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
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_lost_item'])) {
    $itemId = account_positive_integer($_POST['item_id'] ?? null);
    $status = account_input('status');
    $allowedStatuses = ['pending', 'verified', 'possible_match', 'recovered', 'closed'];

    if ($itemId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $message = 'Invalid review request.';
        $messageType = 'danger';
    } else {
        $stmt = $conn->prepare(
            "UPDATE lost_items SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('sii', $status, $_SESSION['super_admin_id'], $itemId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = 'Lost item status updated successfully.';
            $messageType = 'success';
        } else {
            $message = 'No matching record was updated.';
            $messageType = 'danger';
        }
        $stmt->close();
    }
}

$itemsStmt = $conn->prepare(
    "SELECT l.*, u.full_name AS reporter_name, reviewer.full_name AS reviewer_name
     FROM lost_items l
     LEFT JOIN infos u ON u.id = l.user_id
     LEFT JOIN infos reviewer ON reviewer.id = l.reviewed_by
     ORDER BY l.created_at DESC"
);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost Item Review - Lost & Found Management System</title>
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
                    <h4 class="mb-0">Lost Item Review</h4>
                    <small class="text-muted">Review and update reported lost items</small>
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
                    <i class="fas fa-search-location text-primary me-2"></i>
                    All Lost Item Reports
                </h5>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Reporter</th>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No lost item records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><strong><?= (int)$item['id'] ?></strong></td>
                                        <td><?= htmlspecialchars($item['reporter_name'] ?? 'Unknown') ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($item['item_name']) ?></div>
                                            <?php if (!empty($item['image_path'])): ?>
                                                <div class="mt-2">
                                                    <img src="../<?= htmlspecialchars($item['image_path']) ?>" alt="Lost item" class="img-thumbnail" style="max-width: 120px;">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($item['category']) ?></td>
                                        <td><?= htmlspecialchars($item['location_lost']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase">
                                                <?= htmlspecialchars($item['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="review_token" value="<?= htmlspecialchars($_SESSION['review_form_token']) ?>">
                                                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <option value="pending" <?= $item['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="verified" <?= $item['status'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                                                    <option value="possible_match" <?= $item['status'] === 'possible_match' ? 'selected' : '' ?>>Possible Match</option>
                                                    <option value="recovered" <?= $item['status'] === 'recovered' ? 'selected' : '' ?>>Recovered</option>
                                                    <option value="closed" <?= $item['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                                                </select>
                                                <button type="submit" name="review_lost_item" class="btn btn-sm btn-primary">Update</button>
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
