<?php
session_start();
require_once '../DB/conn.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/includes/account_input_validation.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
logPageView('My Profile');

$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if (!$admin_data) {
    session_destroy();
    header('Location: Sign_in.php');
    exit();
}

$is_super_admin = ($admin_data['role'] === 'super_admin');

// Handle profile update
$success_message = '';
$error_message = '';
$_SESSION['admin_profile_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['admin_profile_token'], account_input('profile_token', false))) {
    $error_message = 'This form expired. Reload the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = account_input('full_name');
        $email = account_input('email');
        $username = account_input('username');
        
        // Validate inputs
        if (!account_name_is_valid($full_name) || !account_text_is_valid($full_name, 250)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || !account_text_is_valid($email, 250)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._]{3,29}$/D', $username)) {
            $error_message = 'Enter a valid full name, email, and username.';
        } else {
            // Check if username or email already exists (excluding current user)
            $check_stmt = $conn->prepare("SELECT id FROM infos WHERE (username = ? OR email = ?) AND id != ?");
            $check_stmt->bind_param("ssi", $username, $email, $admin_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error_message = 'Username or email already exists.';
            } else {
                // Update profile
                $update_stmt = $conn->prepare("UPDATE infos SET full_name = ?, email = ?, username = ? WHERE id = ?");
                $update_stmt->bind_param("sssi", $full_name, $email, $username, $admin_id);
                
                if ($update_stmt->execute()) {
                    $success_message = 'Profile updated successfully!';
                    $admin_data['full_name'] = $full_name;
                    $admin_data['email'] = $email;
                    $admin_data['username'] = $username;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_email'] = $email;
                    $_SESSION['admin_full_name'] = $full_name;
                    
                    // Log activity
                    logActivity('profile_update', 'Updated profile information');
                } else {
                    $error_message = 'Failed to update profile.';
                }
            }
        }
    }
    
    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = account_input('current_password', false);
        $new_password = account_input('new_password', false);
        $confirm_password = account_input('confirm_password', false);
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error_message = 'New passwords do not match.';
        } elseif (!account_password_is_valid($new_password)) {
            $error_message = 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.';
        } else {
            // Verify current password
            if (password_verify($current_password, $admin_data['passw'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_pwd_stmt = $conn->prepare("UPDATE infos SET passw = ? WHERE id = ?");
                $update_pwd_stmt->bind_param("si", $hashed_password, $admin_id);
                
                if ($update_pwd_stmt->execute()) {
                    $success_message = 'Password changed successfully!';
                    logActivity('password_change', 'Changed account password');
                } else {
                    $error_message = 'Failed to change password.';
                }
            } else {
                $error_message = 'Current password is incorrect.';
            }
        }
    }
}

// Get recent activity
$activity_query = "SELECT * FROM admin_activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$activity_stmt = $conn->prepare($activity_query);
$activity_stmt->bind_param("i", $admin_id);
$activity_stmt->execute();
$recent_activities = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get account statistics
$login_count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE user_id = $admin_id AND action = 'login'")->fetch_assoc()['count'] ?? 0;
$last_login = $conn->query("SELECT created_at FROM admin_activity_log WHERE user_id = $admin_id AND action = 'login' ORDER BY created_at DESC LIMIT 1, 1")->fetch_assoc()['created_at'] ?? 'N/A';

// Get activity chart data (last 7 days)
$activity_chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE user_id = $admin_id AND DATE(created_at) = '$date'")->fetch_assoc()['count'] ?? 0;
    $activity_chart_data[] = ['date' => $date, 'count' => $count];
}

// Get active sessions
$active_sessions_query = "SELECT * FROM user_session_activity WHERE user_id = ? AND status = 'active' ORDER BY last_activity DESC";
$sessions_stmt = $conn->prepare($active_sessions_query);
$sessions_stmt->bind_param("i", $admin_id);
$sessions_stmt->execute();
$active_sessions = $sessions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get action breakdown
$action_breakdown = [];
$actions_query = "SELECT action, COUNT(*) as count FROM admin_activity_log WHERE user_id = ? GROUP BY action ORDER BY count DESC LIMIT 5";
$actions_stmt = $conn->prepare($actions_query);
$actions_stmt->bind_param("i", $admin_id);
$actions_stmt->execute();
$action_breakdown = $actions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?></title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        body {
            background: #F8FAFC;
            font-family: 'Inter', sans-serif;
        }
        
        .profile-header {
            background: linear-gradient(135deg, <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?> 0%, <?php echo $is_super_admin ? '#6366F1' : '#3B82F6'; ?> 100%);
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            border: 5px solid white;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            margin: 0 auto 20px;
            position: relative;
            overflow: hidden;
        }
        
        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 8px;
            text-align: center;
            font-size: 12px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .profile-avatar-large:hover .avatar-upload-btn {
            opacity: 1;
        }
        
        .profile-name {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .profile-role {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 500;
        }
        
        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #E2E8F0;
            margin-bottom: 24px;
        }
        
        .profile-card h5 {
            font-size: 18px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .profile-card h5 i {
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
        }
        
        .form-label {
            font-weight: 600;
            color: #475569;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .form-control {
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            border-color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            box-shadow: 0 0 0 3px <?php echo $is_super_admin ? 'rgba(79, 70, 229, 0.1)' : 'rgba(37, 99, 235, 0.1)'; ?>;
        }
        
        .btn-primary {
            background: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: <?php echo $is_super_admin ? '#4338CA' : '#1D4ED8'; ?>;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px <?php echo $is_super_admin ? 'rgba(79, 70, 229, 0.3)' : 'rgba(37, 99, 235, 0.3)'; ?>;
        }
        
        .stat-box {
            background: #F8FAFC;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            border: 1px solid #E2E8F0;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748B;
            font-weight: 500;
        }
        
        .activity-item {
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .activity-time {
            font-size: 12px;
            color: #94A3B8;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 14px 18px;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            color: white;
            opacity: 0.8;
        }
        
        /* Activity Chart */
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 20px;
        }
        
        /* Session Card */
        .session-item {
            padding: 14px;
            background: #F8FAFC;
            border-radius: 8px;
            margin-bottom: 12px;
            border: 1px solid #E2E8F0;
        }
        
        .session-item:last-child {
            margin-bottom: 0;
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .session-device {
            font-weight: 600;
            color: #1E293B;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .session-status {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            background: transparent !important;
            border: 1px solid #16A34A;
        }
        
        .session-status.active {
            color: #16A34A;
        }
        
        .session-info {
            font-size: 12px;
            color: #64748B;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        /* Action Breakdown */
        .action-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #F1F5F9;
        }
        
        .action-item:last-child {
            border-bottom: none;
        }
        
        .action-label {
            font-size: 13px;
            color: #475569;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .action-count {
            font-size: 16px;
            font-weight: 700;
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
        }
        
        /* Progress Bar */
        .progress-bar-custom {
            height: 6px;
            background: #E2E8F0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?> 0%, <?php echo $is_super_admin ? '#6366F1' : '#3B82F6'; ?> 100%);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        /* Badge */
        .badge-custom {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: transparent !important;
            border: 1px solid #E2E8F0;
        }
        
        .badge-primary {
            color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            border-color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
        }
        
        .badge-success {
            color: #16A34A;
            border-color: #16A34A;
        }
        
        .badge-warning {
            color: #F59E0B;
            border-color: #F59E0B;
        }
        
        /* Security Settings */
        .security-options {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .security-item {
            padding: 16px;
            background: #F8FAFC;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
        }
        
        .security-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .security-desc {
            font-size: 12px;
            color: #64748B;
        }
        
        .form-check-input {
            width: 48px;
            height: 24px;
            cursor: pointer;
        }
        
        .form-check-input:checked {
            background-color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
            border-color: <?php echo $is_super_admin ? '#4F46E5' : '#2563EB'; ?>;
        }
        
        /* Info List */
        .info-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .info-item {
            padding: 14px;
            background: #F8FAFC;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="container-fluid">
                <a href="<?php echo $is_super_admin ? 'admin_dashboard.php' : 'unified_admin_dashboard.php'; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                
                <div class="text-center mt-4">
                    <div class="profile-avatar-large">
                        <?php if (!empty($admin_data['avatar'])): ?>
                            <img src="../uploads/avatars/<?php echo htmlspecialchars($admin_data['avatar']); ?>" 
                                 alt="Avatar" 
                                 onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo strtoupper(substr($admin_data['username'], 0, 1)); ?>';">
                        <?php else: ?>
                            <?php echo strtoupper(substr($admin_data['username'], 0, 1)); ?>
                        <?php endif; ?>
                        <div class="avatar-upload-btn" onclick="document.getElementById('avatarUpload').click()">
                            <i class="fas fa-camera"></i> Change Photo
                        </div>
                    </div>
                    <input type="file" id="avatarUpload" accept="image/*" style="display: none;" onchange="uploadAvatar(this)">
                    
                    <h2 class="profile-name"><?php echo htmlspecialchars($admin_data['full_name']); ?></h2>
                    <p class="profile-role">
                        <i class="fas fa-<?php echo $is_super_admin ? 'crown' : 'shield-alt'; ?> me-2"></i>
                        <?php echo $is_super_admin ? 'Super Administrator' : 'Administrator'; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="container-fluid">
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Stats Overview Bar -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <i class="fas fa-sign-in-alt" style="font-size: 24px; color: #4F46E5;"></i>
                        </div>
                        <div class="stat-value"><?php echo $login_count; ?></div>
                        <div class="stat-label">Total Logins</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <i class="fas fa-history" style="font-size: 24px; color: #10B981;"></i>
                        </div>
                        <div class="stat-value"><?php echo count($recent_activities); ?></div>
                        <div class="stat-label">Recent Activities</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <i class="fas fa-desktop" style="font-size: 24px; color: #8B5CF6;"></i>
                        </div>
                        <div class="stat-value"><?php echo count($active_sessions); ?></div>
                        <div class="stat-label">Active Sessions</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <i class="fas fa-calendar-check" style="font-size: 24px; color: #F59E0B;"></i>
                        </div>
                        <div class="stat-value"><?php echo floor((time() - strtotime($admin_data['created_at'])) / 86400); ?></div>
                        <div class="stat-label">Days Active</div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Personal Information -->
                    <div class="profile-card">
                        <h5><i class="fas fa-user" style="color: #4F46E5;"></i> Personal Information</h5>
                        <form method="POST">
                <input type="hidden" name="profile_token" value="<?= htmlspecialchars($_SESSION['admin_profile_token']) ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($admin_data['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($admin_data['username']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin_data['email']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <input type="text" class="form-control" value="<?php echo $is_super_admin ? 'Super Administrator' : 'Administrator'; ?>" disabled>
                                </div>
                            </div>
                            <div class="mt-4">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Change Password -->
                    <div class="profile-card">
                        <h5><i class="fas fa-lock" style="color: #EF4444;"></i> Change Password</h5>
                        <form method="POST">
                <input type="hidden" name="profile_token" value="<?= htmlspecialchars($_SESSION['admin_profile_token']) ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password" minlength="6" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                                </div>
                            </div>
                            <div class="mt-4">
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Activity Chart -->
                    <div class="profile-card">
                        <h5><i class="fas fa-chart-area" style="color: #10B981;"></i> Activity Overview</h5>
                        <p class="text-muted" style="font-size: 13px; margin-bottom: 0;">Your activity in the last 7 days</p>
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Security Settings -->
                    <div class="profile-card">
                        <h5><i class="fas fa-shield-alt" style="color: #EF4444;"></i> Security Settings</h5>
                        <div class="security-options">
                            <div class="security-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="security-title">Login Notifications</div>
                                        <div class="security-desc">Get notified when someone logs into your account</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="loginNotif" checked>
                                    </div>
                                </div>
                            </div>
                            <div class="security-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="security-title">Email Alerts</div>
                                        <div class="security-desc">Receive email alerts for important activities</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="emailAlerts" checked>
                                    </div>
                                </div>
                            </div>
                            <div class="security-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="security-title">Session Timeout</div>
                                        <div class="security-desc">Auto logout after 30 minutes of inactivity</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="sessionTimeout" checked>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Recent Activity -->
                    <div class="profile-card">
                        <h5><i class="fas fa-history" style="color: #F59E0B;"></i> Recent Activity</h5>
                        <div class="activity-list">
                            <?php if (empty($recent_activities)): ?>
                            <p class="text-muted text-center py-3">No recent activity</p>
                            <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-<?php 
                                        echo match($activity['action']) {
                                            'login' => 'sign-in-alt',
                                            'logout' => 'sign-out-alt',
                                            'profile_update' => 'user-edit',
                                            'password_change' => 'key',
                                            default => 'circle'
                                        };
                                    ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                    <div class="activity-time"><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Active Sessions -->
                    <div class="profile-card">
                        <h5><i class="fas fa-desktop" style="color: #8B5CF6;"></i> Active Sessions</h5>
                        <?php if (empty($active_sessions)): ?>
                        <p class="text-muted text-center py-3">No active sessions</p>
                        <?php else: ?>
                        <?php foreach ($active_sessions as $session): ?>
                        <div class="session-item">
                            <div class="session-header">
                                <div class="session-device">
                                    <i class="fas fa-<?php echo (strpos(strtolower($session['user_agent']), 'mobile') !== false) ? 'mobile-alt' : 'desktop'; ?>"></i>
                                    <?php 
                                    $ua = $session['user_agent'];
                                    if (strpos($ua, 'Chrome') !== false) echo 'Chrome';
                                    elseif (strpos($ua, 'Firefox') !== false) echo 'Firefox';
                                    elseif (strpos($ua, 'Safari') !== false) echo 'Safari';
                                    elseif (strpos($ua, 'Edge') !== false) echo 'Edge';
                                    else echo 'Browser';
                                    ?>
                                </div>
                                <span class="session-status active">
                                    <i class="fas fa-circle" style="font-size: 6px;"></i> Active
                                </span>
                            </div>
                            <div class="session-info">
                                <span><i class="fas fa-map-marker-alt me-1"></i> IP: <?php 
                                    $ip = htmlspecialchars($session['ip_address']);
                                    echo ($ip === '::1') ? '127.0.0.1 (localhost)' : $ip;
                                ?></span>
                                <span><i class="fas fa-clock me-1"></i> Last active: <?php echo date('M d, Y h:i A', strtotime($session['last_activity'])); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Account Info -->
                    <div class="profile-card">
                        <h5><i class="fas fa-info-circle" style="color: #06B6D4;"></i> Account Information</h5>
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-user-shield" style="color: #4F46E5;"></i>
                                    Role
                                </div>
                                <div class="info-value"><?php echo $is_super_admin ? 'Super Administrator' : 'Administrator'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-check-circle" style="color: #10B981;"></i>
                                    Status
                                </div>
                                <div class="info-value">Active</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-calendar-alt" style="color: #F59E0B;"></i>
                                    Member Since
                                </div>
                                <div class="info-value"><?php echo date('M d, Y', strtotime($admin_data['created_at'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-clock" style="color: #8B5CF6;"></i>
                                    Last Login
                                </div>
                                <div class="info-value"><?php echo $last_login !== 'N/A' ? date('M d, Y h:i A', strtotime($last_login)) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebar && mainContent) {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            }
        }
        
        function uploadAvatar(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File too large. Maximum size is 5MB');
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed');
                    return;
                }
                
                const formData = new FormData();
                formData.append('avatar', file);
                
                // Show loading indicator
                const avatarDiv = document.querySelector('.profile-avatar-large');
                const originalContent = avatarDiv.innerHTML;
                avatarDiv.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 48px;"></i>';
                
                fetch('upload_avatar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        avatarDiv.innerHTML = originalContent;
                        alert('Failed to upload avatar: ' + data.message);
                    }
                })
                .catch(error => {
                    avatarDiv.innerHTML = originalContent;
                    alert('Error uploading avatar: ' + error.message);
                    console.error('Upload error:', error);
                });
            }
        }
        
        // Activity Chart
        const activityData = <?php echo json_encode($activity_chart_data); ?>;
        const ctx = document.getElementById('activityChart');
        
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: activityData.map(d => {
                        const date = new Date(d.date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Activities',
                        data: activityData.map(d => d.count),
                        borderColor: '<?php echo $is_super_admin ? "#4F46E5" : "#2563EB"; ?>',
                        backgroundColor: '<?php echo $is_super_admin ? "rgba(79, 70, 229, 0.1)" : "rgba(37, 99, 235, 0.1)"; ?>',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '<?php echo $is_super_admin ? "#4F46E5" : "#2563EB"; ?>',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '<?php echo $is_super_admin ? "#4F46E5" : "#2563EB"; ?>',
                            borderWidth: 1,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: '#F1F5F9',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
    </script>

</body>
</html>
