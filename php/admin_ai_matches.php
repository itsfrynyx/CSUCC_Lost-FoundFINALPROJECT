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
    echo 'Access denied. You do not have AI review permission.';
    exit();
}

$message = '';
$messageType = '';

$requiredTables = ['lost_items', 'found_items', 'ai_matches'];
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

        if ($tableName === 'lost_items') {
            $createSql = "
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
        }

        if ($tableName === 'ai_matches') {
            $createSql = "
                CREATE TABLE IF NOT EXISTS ai_matches (
                    id INT NOT NULL AUTO_INCREMENT,
                    lost_item_id INT NOT NULL,
                    found_item_id INT NOT NULL,
                    match_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    matching_factors JSON DEFAULT NULL,
                    review_status ENUM('pending','reviewed','rejected') NOT NULL DEFAULT 'pending',
                    reviewed_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_lost_item_id (lost_item_id),
                    KEY idx_found_item_id (found_item_id),
                    KEY idx_review_status (review_status),
                    UNIQUE KEY uq_ai_match_pair (lost_item_id, found_item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
        }

        if (!$conn->query($createSql)) {
            http_response_code(500);
            echo 'Failed to initialize the required AI match schema: ' . htmlspecialchars($conn->error);
            exit();
        }
    }
}

$_SESSION['review_form_token'] ??= bin2hex(random_bytes(32));
$invalidReviewToken = $_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['review_form_token'], account_input('review_token', false));
if ($invalidReviewToken) {
    $message = 'This review form expired. Reload the page and try again.';
    $messageType = 'danger';
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_ai_matches'])) {
    require_once __DIR__ . '/includes/ai_match_service.php';
    $generated = ai_generate_all_matches($conn);
    $message = 'AI match table refreshed. ' . $generated . ' match(es) generated.';
    $messageType = 'success';
}

if (!$invalidReviewToken && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_ai_match'])) {
    $matchId = account_positive_integer($_POST['match_id'] ?? null);
    $reviewStatus = account_input('review_status');

    if ($matchId <= 0 || !in_array($reviewStatus, ['reviewed', 'rejected'], true)) {
        $message = 'Invalid AI match review request.';
        $messageType = 'danger';
    } else {
        $stmt = $conn->prepare(
            "UPDATE ai_matches SET review_status = ?, reviewed_by = ? WHERE id = ?"
        );
        $stmt->bind_param('sii', $reviewStatus, $_SESSION['super_admin_id'], $matchId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = 'AI match review recorded successfully.';
            $messageType = 'success';
        } else {
            $message = 'No matching record was updated.';
            $messageType = 'danger';
        }
        $stmt->close();
    }
}

$matchesStmt = $conn->prepare(
    "SELECT am.*, l.item_name AS lost_item_name, f.item_name AS found_item_name,
            l.location_lost, f.location_found, l.category AS lost_category, f.category AS found_category
     FROM ai_matches am
     JOIN lost_items l ON l.id = am.lost_item_id
     JOIN found_items f ON f.id = am.found_item_id
     ORDER BY am.created_at DESC"
);
$matchesStmt->execute();
$matches = $matchesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$matchesStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Match Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">AI Match Review</h2>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary">Back to Admin Dashboard</a>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end mb-3">
            <form method="POST">
                                                <input type="hidden" name="review_token" value="<?= htmlspecialchars($_SESSION['review_form_token']) ?>">
                <button type="submit" name="regenerate_ai_matches" class="btn btn-primary">Regenerate AI Matches</button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle bg-white">
                <thead class="table-light">
                    <tr>
                        <th>Lost Item</th>
                        <th>Found Item</th>
                        <th>Score</th>
                        <th>Factors</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matches)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No AI matches generated yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($match['lost_item_name']) ?>
                                    <div class="small text-muted"><?= htmlspecialchars($match['lost_category'] ?? 'N/A') ?> / <?= htmlspecialchars($match['location_lost'] ?? 'N/A') ?></div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($match['found_item_name']) ?>
                                    <div class="small text-muted"><?= htmlspecialchars($match['found_category'] ?? 'N/A') ?> / <?= htmlspecialchars($match['location_found'] ?? 'N/A') ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$match['match_score']) ?>%</strong>
                                </td>
                                <td>
                                    <pre class="small mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($match['matching_factors'] ?? '{}') ?></pre>
                                </td>
                                <td>
                                    <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($match['review_status']) ?></span>
                                </td>
                                <td>
                                    <form method="POST" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="review_token" value="<?= htmlspecialchars($_SESSION['review_form_token']) ?>">
                                        <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                        <select name="review_status" class="form-select form-select-sm">
                                            <option value="reviewed" <?= $match['review_status'] === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                            <option value="rejected" <?= $match['review_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                        <button type="submit" name="review_ai_match" class="btn btn-sm btn-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
