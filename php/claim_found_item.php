<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/includes/RBAC.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$rbac = new RBAC($conn, $_SESSION['user_id']);
if (!$rbac->hasPermission('lost_items.create')) {
    http_response_code(403);
    echo 'Access denied. You do not have permission to claim an item.';
    exit();
}

$_SESSION['claim_form_token'] ??= bin2hex(random_bytes(32));
$message = $_SESSION['claim_form_success'] ?? '';
$messageType = $message === '' ? '' : 'success';
unset($_SESSION['claim_form_success']);
$foundItemId = null;
$itemDescription = $uniqueFeatures = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foundItemId = account_positive_integer($_POST['found_item_id'] ?? null);
    $itemDescription = account_input('item_description');
    $uniqueFeatures = account_input('unique_identifying_features');
    $descriptionText = str_replace(["\r", "\n", "\t"], ' ', $itemDescription);
    $featureText = str_replace(["\r", "\n", "\t"], ' ', $uniqueFeatures);

    if (!hash_equals($_SESSION['claim_form_token'], account_input('claim_token', false))) {
        $message = 'This claim form expired or was already submitted. Please try again.';
    } elseif ($foundItemId === null || $itemDescription === '') {
        $message = 'Select a found item and enter its description.';
    } elseif (!account_text_is_valid($descriptionText, 5000) || !account_text_is_valid($featureText, 5000)) {
        $message = 'Use up to 5,000 bytes per text field and remove control characters.';
    } else {
        $claimantId = (int) $_SESSION['user_id'];
        $conn->begin_transaction();
        try {
            $item = $conn->prepare("SELECT id FROM found_items WHERE id = ? AND status = 'verified' FOR UPDATE");
            $item->bind_param('i', $foundItemId);
            $item->execute();
            if (!$item->get_result()->fetch_assoc()) {
                $message = 'That item is no longer available to claim.';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO claims (found_item_id, claimant_id, item_description, unique_identifying_features, status)
                     SELECT ?, ?, ?, ?, 'pending'
                     WHERE NOT EXISTS (
                         SELECT 1 FROM claims WHERE found_item_id = ? AND claimant_id = ?
                         AND status IN ('pending', 'under_review', 'approved')
                     )"
                );
                $stmt->bind_param('iissii', $foundItemId, $claimantId, $itemDescription, $uniqueFeatures, $foundItemId, $claimantId);
                $stmt->execute();
                if ($stmt->affected_rows === 1) {
                    $conn->commit();
                    $_SESSION['claim_form_token'] = bin2hex(random_bytes(32));
                    $_SESSION['claim_form_success'] = 'Claim submitted successfully for review.';
                    header('Location: claim_found_item.php', true, 303);
                    exit;
                }
                $message = 'You already have an active claim for this item.';
            }
            $conn->rollback();
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            error_log('Claim save failed: ' . $error->getMessage());
            $message = 'Unable to save the claim. Please try again.';
        }
    }
    $messageType = 'danger';
}

$foundItemsStmt = $conn->prepare(
    "SELECT * FROM found_items WHERE status = 'verified' ORDER BY created_at DESC"
);
$foundItemsStmt->execute();
$foundItems = $foundItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$foundItemsStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Found Item</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: #f7f4ef;
            color: #1f2937;
            padding-top: 70px;
        }
        .main-content {
            margin-left: 260px;
            padding: 24px;
            min-height: calc(100vh - 70px);
            width: calc(100% - 260px);
        }
        .page-header {
            margin-bottom: 20px;
        }
        .breadcrumb-nav {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .breadcrumb-nav a {
            color: #64748b;
            text-decoration: none;
        }
        .page-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
        }
        .page-header p {
            margin: 8px 0 0;
            color: #64748b;
        }
        .panel {
            background: #ffffff;
            border: 1px solid #e7e3dc;
            border-radius: 18px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .panel-header {
            padding: 18px 22px;
            border-bottom: 1px solid #f1efe9;
            background: linear-gradient(135deg, #f8fafc 0%, #f3f4f6 100%);
            font-weight: 700;
            color: #1f2937;
        }
        .panel-body {
            padding: 22px;
        }
        .alert {
            margin-bottom: 20px;
            border-radius: 12px;
            border: 1px solid transparent;
            padding: 12px 16px;
            font-weight: 500;
        }
        .alert-success { background: #ecfdf5; color: #166534; border-color: #bbf7d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        .form-control, .form-select, textarea {
            width: 100%;
            border: 1px solid #dfe6eb;
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
            color: #1f2937;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus, textarea:focus {
            border-color: #0d5c3c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 92, 60, 0.08);
        }
        textarea { min-height: 120px; resize: vertical; }
        .btn-primary {
            background: linear-gradient(135deg, #0d5c3c, #0f7a4f);
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-weight: 600;
            color: white;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0b4b31, #0d6b45);
            color: white;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div class="breadcrumb-nav">
            <a href="Dashboard.php">Dashboard</a> / <span>Claim Found Item</span>
        </div>
        <h1>Claim Found Item</h1>
        <p>Submit your claim for a reported found item and provide identifying details.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">Claim Submission</div>
        <div class="panel-body">
            <form method="POST" autocomplete="off">
                    <input type="hidden" name="claim_token" value="<?= htmlspecialchars($_SESSION['claim_form_token']) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Found Item <span class="text-danger">*</span></label>
                        <select name="found_item_id" class="form-select" required>
                            <option value="">-- Select item --</option>
                            <?php foreach ($foundItems as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $foundItemId === (int) $item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['item_name']) ?> (<?= htmlspecialchars($item['location_found']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Item Description <span class="text-danger">*</span></label>
                        <textarea name="item_description" required maxlength="5000"><?= htmlspecialchars($itemDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Unique Identifying Features</label>
                        <textarea name="unique_identifying_features" maxlength="5000"><?= htmlspecialchars($uniqueFeatures, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <div style="margin-top: 20px; display:flex; justify-content:flex-end;">
                    <button type="submit" name="submit_claim" class="btn btn-primary">Submit Claim</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
