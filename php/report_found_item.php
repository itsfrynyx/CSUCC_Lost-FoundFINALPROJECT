<?php
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/includes/RBAC.php';
require_once __DIR__ . '/includes/ai_match_service.php';
require_once __DIR__ . '/includes/account_input_validation.php';
require_once __DIR__ . '/includes/upload_validation.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$rbac = new RBAC($conn, $_SESSION['user_id']);
if (!$rbac->hasPermission('lost_items.create')) {
    http_response_code(403);
    echo 'Access denied. You do not have permission to report found items.';
    exit();
}

$tokenKey = 'report_found_token';
$flashKey = 'report_found_message';
$_SESSION[$tokenKey] ??= bin2hex(random_bytes(32));
$message = $_SESSION[$flashKey] ?? '';
$messageType = $message === '' ? '' : 'success';
unset($_SESSION[$flashKey]);
$fields = [];
$errors = [];
$limits = ['item_name' => 150, 'category' => 100, 'description' => 5000, 'color' => 80, 'brand' => 100, 'model' => 100, 'location_found' => 255, 'distinguishing_features' => 5000, 'storage_location' => 255];
$required = ['item_name', 'category', 'description', 'location_found'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_merge(array_keys($limits), ['date_found', 'time_found']) as $field) {
        $fields[$field] = account_input($field);
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            $errors[$field] = 'Enter a single value.';
        }
    }
    foreach ($limits as $field => $limit) {
        if ($fields[$field] === '' && in_array($field, $required, true)) {
            $errors[$field] = 'This field is required.';
        }
        $value = in_array($field, ['description', 'distinguishing_features'], true)
            ? str_replace(["\r", "\n", "\t"], ' ', $fields[$field]) : $fields[$field];
        if (!account_text_is_valid($value, $limit)) {
            $errors[$field] = 'Use shorter text without control characters.';
        }
    }

    $date = $fields['date_found'];
    $time = $fields['time_found'];
    if ($date !== '') {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d') || $date < '1000-01-01') {
            $errors['date_found'] = 'Enter a valid date up to today.';
        }
    }
    if ($time !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
        $errors['time_found'] = 'Enter a valid time.';
    }

    $file = $_FILES['item_image'] ?? null;
    $extension = null;
    if ($file !== null && ($file['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
        $extension = validated_upload_extension($file, ['jpg', 'png', 'webp'], 2 * 1024 * 1024);
        if ($extension === null) {
            $errors['item_image'] = 'Choose a valid JPG, PNG, or WebP image up to 2 MB.';
        }
    }

    if (!hash_equals($_SESSION[$tokenKey], account_input('report_token', false))) {
        $message = 'This report form expired or was already submitted. Please try again.';
        $messageType = 'danger';
    } elseif ($errors) {
        $message = 'Review the highlighted report fields.';
        $messageType = 'danger';
    } else {
        $imagePath = null;
        $targetPath = null;
        if ($extension !== null) {
            $safeName = 'found_' . bin2hex(random_bytes(12)) . '.' . $extension;
            $uploadsDir = __DIR__ . '/../uploads/found_items';
            $targetPath = $uploadsDir . '/' . $safeName;
            if ((is_dir($uploadsDir) || mkdir($uploadsDir, 0775, true)) && move_uploaded_file($file['tmp_name'], $targetPath)) {
                $imagePath = 'uploads/found_items/' . $safeName;
            } else {
                $errors['item_image'] = 'Unable to save the uploaded image.';
            }
        }

        if ($errors) {
            $message = 'Review the highlighted report fields.';
            $messageType = 'danger';
        } else {
            $date = $date === '' ? null : $date;
            $time = $time === '' ? null : $time;
            $stmt = $conn->prepare(
                "INSERT INTO found_items (user_id, item_name, category, description, color, brand, model, date_found, time_found, location_found, distinguishing_features, storage_location, image_path, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );

            $stmt->bind_param(
                'issssssssssss',
                $_SESSION['user_id'],
                $fields['item_name'],
                $fields['category'],
                $fields['description'],
                $fields['color'],
                $fields['brand'],
                $fields['model'],
                $date,
                $time,
                $fields['location_found'],
                $fields['distinguishing_features'],
                $fields['storage_location'],
                $imagePath
            );

            try {
                $stmt->execute();
            } catch (mysqli_sql_exception $error) {
                if ($imagePath !== null) {
                    unlink($targetPath);
                }
                error_log('Item report save failed: ' . $error->getMessage());
                $message = 'Unable to save the report. Please try again.';
                $messageType = 'danger';
            }

            if ($messageType !== 'danger') {
                $_SESSION[$tokenKey] = bin2hex(random_bytes(32));
                $_SESSION[$flashKey] = 'Found item report submitted successfully and is pending review.';
                try {
                    $matches = ai_generate_matches_for_found_item($conn, $stmt->insert_id);
                    if ($matches > 0) {
                        $_SESSION[$flashKey] .= ' ' . $matches . ' AI match(es) were generated.';
                    }
                } catch (Throwable $error) {
                    error_log('Item matching failed: ' . $error->getMessage());
                }
                header('Location: report_found_item.php', true, 303);
                exit();
            }
            $stmt->close();
        }
    }
}

function report_field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<small class="text-danger" id="' . $field . '-error">' . htmlspecialchars($errors[$field]) . '</small>' : '';
}

$mineStmt = $conn->prepare(
    "SELECT * FROM found_items WHERE user_id = ? ORDER BY created_at DESC"
);
$mineStmt->bind_param('i', $_SESSION['user_id']);
$mineStmt->execute();
$myReports = $mineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mineStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Found Item</title>
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
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .form-group.full {
            grid-column: 1 / -1;
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
        .button-row {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .helper-text {
            color: #64748b;
            font-size: 0.84rem;
        }
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
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .reports-table th, .reports-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #edf1f5;
            text-align: left;
        }
        .reports-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .status-badge {
            display: inline-block;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            background: #e7f8ee;
            color: #166534;
            text-transform: capitalize;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
                width: 100%;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .button-row {
                align-items: flex-start;
                flex-direction: column;
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
            <a href="Dashboard.php">Dashboard</a> / <span>Report Found Item</span>
        </div>
        <h1>Report Found Item</h1>
        <p>Submit a found item report and help re-unite it with its owner.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">Found Item Details</div>
        <div class="panel-body">
            <form method="POST" enctype="multipart/form-data" autocomplete="off" data-item-report>
                <input type="hidden" name="report_token" value="<?= htmlspecialchars($_SESSION[$tokenKey]) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" class="form-control" required maxlength="150" <?= isset($errors['item_name']) ? 'aria-invalid="true" aria-describedby="item_name-error"' : '' ?> value="<?= htmlspecialchars($fields['item_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'item_name') ?>
                    </div>
                    <div class="form-group">
                        <label>Category <span class="text-danger">*</span></label>
                        <input type="text" name="category" class="form-control" required maxlength="100" <?= isset($errors['category']) ? 'aria-invalid="true" aria-describedby="category-error"' : '' ?> value="<?= htmlspecialchars($fields['category'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'category') ?>
                    </div>
                    <div class="form-group full">
                        <label>Description <span class="text-danger">*</span></label>
                        <textarea name="description" required maxlength="5000" <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="description-error"' : '' ?>><?= htmlspecialchars($fields['description'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                        <?= report_field_error($errors, 'description') ?>
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="text" name="color" class="form-control" maxlength="80" <?= isset($errors['color']) ? 'aria-invalid="true" aria-describedby="color-error"' : '' ?> value="<?= htmlspecialchars($fields['color'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'color') ?>
                    </div>
                    <div class="form-group">
                        <label>Brand</label>
                        <input type="text" name="brand" class="form-control" maxlength="100" <?= isset($errors['brand']) ? 'aria-invalid="true" aria-describedby="brand-error"' : '' ?> value="<?= htmlspecialchars($fields['brand'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'brand') ?>
                    </div>
                    <div class="form-group">
                        <label>Model</label>
                        <input type="text" name="model" class="form-control" maxlength="100" <?= isset($errors['model']) ? 'aria-invalid="true" aria-describedby="model-error"' : '' ?> value="<?= htmlspecialchars($fields['model'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'model') ?>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <input type="file" name="item_image" class="form-control" accept="image/jpeg,image/png,image/webp" aria-describedby="item-image-hint<?= isset($errors['item_image']) ? ' item_image-error' : '' ?>" <?= isset($errors['item_image']) ? 'aria-invalid="true"' : '' ?>>
                        <small id="item-image-hint" class="text-muted">JPG, PNG, or WebP, up to 2 MB.</small>
                        <?= report_field_error($errors, 'item_image') ?>
                    </div>
                    <div class="form-group">
                        <label>Date Found</label>
                        <input type="date" name="date_found" class="form-control" max="<?= date('Y-m-d') ?>" <?= isset($errors['date_found']) ? 'aria-invalid="true" aria-describedby="date_found-error"' : '' ?> value="<?= htmlspecialchars($fields['date_found'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'date_found') ?>
                    </div>
                    <div class="form-group">
                        <label>Time Found</label>
                        <input type="time" name="time_found" class="form-control" <?= isset($errors['time_found']) ? 'aria-invalid="true" aria-describedby="time_found-error"' : '' ?> value="<?= htmlspecialchars($fields['time_found'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'time_found') ?>
                    </div>
                    <div class="form-group full">
                        <label>Location Found <span class="text-danger">*</span></label>
                        <input type="text" name="location_found" class="form-control" required maxlength="255" <?= isset($errors['location_found']) ? 'aria-invalid="true" aria-describedby="location_found-error"' : '' ?> value="<?= htmlspecialchars($fields['location_found'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'location_found') ?>
                    </div>
                    <div class="form-group full">
                        <label>Storage / Current Location</label>
                        <input type="text" name="storage_location" class="form-control" maxlength="255" <?= isset($errors['storage_location']) ? 'aria-invalid="true" aria-describedby="storage_location-error"' : '' ?> value="<?= htmlspecialchars($fields['storage_location'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= report_field_error($errors, 'storage_location') ?>
                    </div>
                    <div class="form-group full">
                        <label>Distinguishing Features</label>
                        <textarea name="distinguishing_features" maxlength="5000" <?= isset($errors['distinguishing_features']) ? 'aria-invalid="true" aria-describedby="distinguishing_features-error"' : '' ?>><?= htmlspecialchars($fields['distinguishing_features'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                        <?= report_field_error($errors, 'distinguishing_features') ?>
                    </div>
                </div>

                <div class="button-row">
                    <span class="helper-text">AI-assisted matches are suggestions only and require admin review.</span>
                    <button type="submit" name="submit_found_item" class="btn btn-primary">Submit Found Report</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel" style="margin-top: 24px;">
        <div class="panel-header">My Found Reports</div>
        <div class="panel-body">
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myReports)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:#64748b;">No found reports yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($myReports as $report): ?>
                            <tr>
                                <td><?= htmlspecialchars($report['item_name']) ?></td>
                                <td><?= htmlspecialchars($report['category']) ?></td>
                                <td><?= htmlspecialchars($report['location_found']) ?></td>
                                <td><?= !empty($report['date_found']) ? htmlspecialchars(date('M d, Y', strtotime($report['date_found']))) : 'N/A' ?></td>
                                <td><span class="status-badge"><?= htmlspecialchars(str_replace('_', ' ', $report['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="../js/item_report_validation.js" defer></script>
</body>
</html>
