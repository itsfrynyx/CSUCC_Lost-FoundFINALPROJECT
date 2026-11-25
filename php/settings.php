<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once __DIR__ . '/../DB/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$success_msg = '';
$error_msg = '';

$_SESSION['settings_form_token'] ??= bin2hex(random_bytes(32));
$submitted_email = $user['email'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['settings_form_token'], account_input('settings_token', false))) {
        $error_msg = 'This settings form expired. Reload the page and try again.';
    } elseif (isset($_POST['update_email'])) {
        $submitted_email = account_input('email');
        if (!account_text_is_valid($submitted_email, 250) || !filter_var($submitted_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Enter a valid email address.';
        } else {
            try {
                $stmt = $conn->prepare('UPDATE infos SET email = ? WHERE id = ?');
                $stmt->bind_param('si', $submitted_email, $user_id);
                $stmt->execute();
                $_SESSION['user_email'] = $submitted_email;
                $success_msg = 'Email updated successfully.';
            } catch (mysqli_sql_exception $error) {
                error_log('Settings email update failed: ' . $error->getMessage());
                $error_msg = $error->getCode() === 1062 ? 'That email address is already used.' : 'Unable to update email.';
            }
        }
    } elseif (isset($_POST['update_password'])) {
        $current = account_input('current_password', false);
        $password = account_input('new_password', false);
        if ($current === '' || strlen($current) > 72 || !password_verify($current, $user['passw'])) {
            $error_msg = 'Current password is incorrect.';
        } elseif (!account_password_is_valid($password)) {
            $error_msg = 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.';
        } elseif ($password !== account_input('confirm_password', false)) {
            $error_msg = 'New passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE infos SET passw = ? WHERE id = ? AND passw = ?');
            $stmt->bind_param('sis', $hash, $user_id, $user['passw']);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $success_msg = 'Password updated successfully.';
            } else {
                $error_msg = 'Unable to update the password. Reload the page and try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #F9FAFB; padding-top: 70px; }
        .main-content { margin-left: 260px; padding: 30px 40px; width: calc(100% - 260px); }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { font-size: 2rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .page-header p { color: #6B7280; }
        .settings-grid { display: grid; gap: 24px; max-width: 800px; }
        .settings-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 24px; }
        .settings-card h3 { font-size: 1.25rem; font-weight: 600; margin-bottom: 16px; color: #111827; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
        .form-group input { width: 100%; padding: 12px; border: 2px solid #E5E7EB; border-radius: 8px; font-size: 1rem; }
        .form-group input:focus { outline: none; border-color: #6366F1; }
        .btn-primary { background: #6366F1; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #4F46E5; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #10B981; }
        .alert-error { background: #FEE2E2; color: #991B1B; border: 1px solid #EF4444; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-cog"></i> Settings</h1>
        <p>Manage your account preferences</p>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error_msg ?></div>
    <?php endif; ?>

    <div class="settings-grid">
        <!-- Email Settings -->
        <div class="settings-card">
            <h3><i class="fas fa-envelope"></i> Email Address</h3>
            <form method="POST">
                <input type="hidden" name="settings_token" value="<?= htmlspecialchars($_SESSION['settings_form_token']) ?>">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($submitted_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                </div>
                <button type="submit" name="update_email" class="btn-primary">Update Email</button>
            </form>
        </div>

        <!-- Password Settings -->
        <div class="settings-card">
            <h3><i class="fas fa-lock"></i> Change Password</h3>
            <form method="POST">
                <input type="hidden" name="settings_token" value="<?= htmlspecialchars($_SESSION['settings_form_token']) ?>">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" name="update_password" class="btn-primary">Update Password</button>
            </form>
        </div>

        <!-- Notification Preferences -->
        <div class="settings-card">
            <h3><i class="fas fa-bell"></i> Notification Preferences</h3>
            <p style="color: #6B7280; margin-bottom: 16px;">Manage how you receive notifications</p>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" checked style="width: auto;">
                    <span>Email notifications for order updates</span>
                </label>
            </div>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" checked style="width: auto;">
                    <span>New arrivals and promotions</span>
                </label>
            </div>
            <button class="btn-primary">Save Preferences</button>
        </div>
    </div>
</div>

</body>
</html>

