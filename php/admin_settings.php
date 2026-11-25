<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once '../DB/conn.php';

if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin || strtolower(trim($admin['role'] ?? '')) !== 'super_admin') {
    header('Location: unified_admin_dashboard.php');
    exit();
}

$message = '';
$message_class = 'success';
$_SESSION['admin_settings_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $system_title = account_input('system_title');
    $support_email = account_input('support_email');
    $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;

    if (!hash_equals($_SESSION['admin_settings_token'], account_input('settings_token', false))) {
        $message = 'This settings form expired. Reload the page and try again.';
        $message_class = 'danger';
    } elseif ($system_title === '' || !account_text_is_valid($system_title, 150) || !filter_var($support_email, FILTER_VALIDATE_EMAIL) || !account_text_is_valid($support_email, 250)) {
        $message = 'Enter a system title up to 150 bytes and a valid support email address.';
        $message_class = 'danger';
    } else {
        $_SESSION['admin_settings'] = [
            'system_title' => $system_title,
            'support_email' => $support_email,
            'maintenance_mode' => $maintenance_mode,
        ];

        $message = 'System settings saved successfully.';
    }
}

$settings = $_SESSION['admin_settings'] ?? [
    'system_title' => 'Caraga State University Lost & Found',
    'support_email' => 'support@csucc.edu.ph',
    'maintenance_mode' => 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <style>
        body {
            background: #f3f6fb;
        }
        .content-wrapper {
            margin-left: 260px;
            padding: 30px 24px;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }
        .page-title {
            font-size: 2.2rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .form-label {
            font-weight: 600;
            color: #334155;
        }
        .save-btn {
            background: #0f172a;
            border: none;
        }
        .save-btn:hover {
            background: #1e293b;
        }
        @media (max-width: 991px) {
            .content-wrapper {
                margin-left: 0;
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/professional_sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <div class="text-muted small text-uppercase fw-bold">
                    <i class="fas fa-cog me-2"></i>System Configuration
                </div>
                <h1 class="page-title">Admin Settings</h1>
            </div>
            <div class="text-muted">
                <i class="fas fa-shield-alt me-2"></i>Super Admin Access
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_class ?> border-0 shadow-sm">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="card p-4 p-md-5">
            <form method="POST">
                <input type="hidden" name="settings_token" value="<?= htmlspecialchars($_SESSION['admin_settings_token']) ?>">
                <div class="row g-4">
                    <div class="col-md-12">
                        <label for="system_title" class="form-label">System Title</label>
                        <input type="text" class="form-control form-control-lg" id="system_title" name="system_title" value="<?php echo htmlspecialchars($settings['system_title']); ?>" required>
                    </div>

                    <div class="col-md-12">
                        <label for="support_email" class="form-label">Support Email</label>
                        <input type="email" class="form-control form-control-lg" id="support_email" name="support_email" value="<?php echo htmlspecialchars($settings['support_email']); ?>" required>
                    </div>

                    <div class="col-md-12">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="maintenance_mode" name="maintenance_mode" <?php echo (int)($settings['maintenance_mode'] ?? 0) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="maintenance_mode">Enable maintenance mode</label>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" name="save_settings" class="btn btn-primary btn-lg save-btn px-4">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
