<?php
require_once __DIR__ . '/includes/upload_validation.php';
require_once __DIR__ . '/includes/super_admin_handoff.php';
session_start();
require_once '../DB/conn.php';
require_once 'includes/pagination_helper.php';
require_once __DIR__ . '/includes/superadmin_confirmation.php';
require_once __DIR__ . '/includes/account_setup_helper.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Check if user has super admin role for certain actions
$current_admin = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$current_admin->bind_param("i", $_SESSION['super_admin_id']);
$current_admin->execute();
$current_role_raw = $current_admin->get_result()->fetch_assoc()['role'];

// Normalize role to lowercase for consistent comparison
$current_role = strtolower($current_role_raw);

// Fetch admin data for display
$admin_stmt = $conn->prepare("SELECT id, username, email, firstname, lastname, middlename, full_name, profile_image, role FROM infos WHERE id = ?");
$admin_stmt->bind_param("i", $_SESSION['super_admin_id']);
$admin_stmt->execute();
$admin_data = $admin_stmt->get_result()->fetch_assoc();

// Access allowed for both admin and super_admin roles

$message = '';
$message_type = '';
$generated_idnumber = generate_registration_id($conn, true);
$generated_student_idnumber = generate_registration_id($conn);

// Handle CRUD operations
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], account_input('csrf_token', false))) {
    $message = 'This form expired. Reload the page and try again.';
    $message_type = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = account_input('action');
    
    switch ($action) {
        case 'add':
            // Personal Information
            $idnumber = generate_registration_id($conn, (account_input('role') ?? '') !== 'customer');
            $firstname = account_input('firstname');
            $middlename = account_input('middlename');
            $lastname = account_input('lastname');
            $suffix = account_input('suffix');
            $sex = account_input('sex');
            $birth = account_input('birth');
            $age = account_input('age');
            
            // Address
            $street = account_input('street');
            $barangay = account_input('barangay');
            $municipality = account_input('municipality');
            $province = account_input('province');
            $country = account_input('country');
            $postal = account_input('postal');
            
            // Account
            $email = account_input('email');
            $username = account_input('username');
            $role = account_input('role');
            $status = $role === 'super_admin' ? 'inactive' : account_input('status');
            
            $birth_date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth) : false;
            if (!$birth_date || $birth_date->format('Y-m-d') !== $birth || $birth_date > new DateTimeImmutable('today')) {
                $message = 'Enter a valid birthdate.';
                $message_type = 'danger';
                break;
            }
            $age = $birth_date->diff(new DateTimeImmutable('today'))->y;
            if ($age < 10 || $age > 120 || !in_array($sex, ['Male', 'Female', 'Prefer not to say'], true)
                || !in_array($status, ['active', 'inactive'], true) || !in_array($role, ['customer', 'admin', 'super_admin'], true)) {
                $message = 'Check the birthdate, account role, and status.';
                $message_type = 'danger';
                break;
            }
            if ($firstname === '' || $lastname === '' || $street === '' || $barangay === ''
                || $municipality === '' || $province === '' || $country === '' || $postal === ''
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._]{3,29}$/D', $username)) {
                $message = 'Complete the required name, address, and username fields.';
                $message_type = 'danger';
                break;
            }
            $name_fields = [$firstname, $lastname, $middlename, $suffix];
            foreach ($name_fields as $name) {
                if ($name !== '' && (!account_text_is_valid($name, 250) || !account_name_is_valid($name))) {
                    $message = 'Names must use letters, spaces, periods, apostrophes, or hyphens.';
                    $message_type = 'danger';
                    break 2;
                }
            }
            foreach ([$street, $barangay, $municipality, $province, $country] as $address_part) {
                if (!account_text_is_valid($address_part, 250)) {
                    $message = 'Use shorter address text without control characters.';
                    $message_type = 'danger';
                    break 2;
                }
            }
            if ($postal !== '' && !account_postal_is_valid($postal, $country)) {
                $message = 'Enter a valid postal code.';
                $message_type = 'danger';
                break;
            }

            // Create full_name
            $full_name = $firstname . ' ' . ($middlename ? $middlename . '. ' : '') . $lastname . ($suffix ? ' ' . $suffix : '');
            
            if ($current_role !== 'super_admin') {
                $message = 'Only super admin can add new admins';
                $message_type = 'danger';
            } elseif (empty($firstname) || empty($lastname) || empty($sex) || 
                      empty($birth) || empty($age) || empty($street) || empty($barangay) || 
                      empty($municipality) || empty($province) || empty($country) || empty($postal) ||
                      empty($username) || empty($email)) {
                $message = 'Please fill in all required fields';
                $message_type = 'danger';
            } else {
                // Check for duplicate ID number
                $check_id = $conn->prepare("SELECT id FROM infos WHERE idnumber = ?");
                $check_id->bind_param("s", $idnumber);
                $check_id->execute();
                if ($check_id->get_result()->num_rows > 0) {
                    $message = 'This ID number is already taken!';
                    $message_type = 'danger';
                    $check_id->close();
                } else {
                    $check_id->close();
                    
                    // Check for duplicate email
                    $check_email = $conn->prepare("SELECT id FROM infos WHERE email = ?");
                    $check_email->bind_param("s", $email);
                    $check_email->execute();
                    if ($check_email->get_result()->num_rows > 0) {
                        $message = 'This email is already taken!';
                        $message_type = 'danger';
                        $check_email->close();
                    } else {
                        $check_email->close();
                        
                        // Check for duplicate username
                        $check_username = $conn->prepare("SELECT id FROM infos WHERE username = ?");
                        $check_username->bind_param("s", $username);
                        $check_username->execute();
                        if ($check_username->get_result()->num_rows > 0) {
                            $message = 'This username is already taken!';
                            $message_type = 'danger';
                            $check_username->close();
                        } else {
                            $check_username->close();
                            
                            // All checks passed, proceed with insert
                            $temporary_password = is_string($_POST['temporary_password'] ?? null) ? $_POST['temporary_password'] : '';
                            $hashed_password = password_hash($temporary_password, PASSWORD_DEFAULT);
                            $must_complete_setup = 1;
                            $stmt = $conn->prepare("INSERT INTO infos (idnumber, firstname, middlename, lastname, suffix, full_name, sex, age, birth, email, username, passw, role, status, street, barangay, municipality, province, postal, country, must_complete_setup, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                            $stmt->bind_param("sssssssissssssssssssi", $idnumber, $firstname, $middlename, $lastname, $suffix, $full_name, $sex, $age, $birth, $email, $username, $hashed_password, $role, $status, $street, $barangay, $municipality, $province, $postal, $country, $must_complete_setup);
                            
                            try {
                                $created = create_managed_account($conn, $stmt, $role, (int) $_SESSION['super_admin_id'], $email, $username, $temporary_password);
                            } catch (RuntimeException $error) {
                                $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Unable to create the account.';
                                $message_type = 'danger';
                                break;
                            }

                            if ($created) {
                                $message = 'Account created. The temporary password was emailed to the account holder.';
                                $message_type = 'success';
                                
                                // Log activity
                                $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'add_admin', ?, ?, ?)");
                                $description = "Added new admin: $username ($full_name)";
                                $ip_address = $_SERVER['REMOTE_ADDR'];
                                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                                $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                                $log_stmt->execute();
                            } else {
                                $message = 'Error adding admin: ' . $conn->error;
                                $message_type = 'danger';
                            }
                        }
                    }
                }
            }
            break;
            
        case 'edit':
            $admin_id = account_positive_integer($_POST['admin_id'] ?? null);
            if ($admin_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            $username = account_input('username');
            $email = account_input('email');
            $full_name = account_input('full_name');
            $status = account_input('status');
            if (!in_array($status, ['active', 'inactive'], true)
                || !preg_match('/^[A-Za-z0-9._]{4,30}$/D', $username)
                || !account_text_is_valid($full_name, 250)
                || !account_text_is_valid($email, 250)
                || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Check the username, full name, email, and account status.';
                $message_type = 'danger';
                break;
            }


            if ($current_role === 'super_admin' && !confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to update this administrator.';
                $message_type = 'danger';
                break;
            }
            
            // Only super admin can edit other super admins
            if ($current_role !== 'super_admin' && $admin_id != $_SESSION['super_admin_id']) {
                $message = 'Insufficient permissions';
                $message_type = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE infos SET username = ?, email = ?, full_name = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $username, $email, $full_name, $status, $admin_id);
                
                if ($stmt->execute()) {
                    $message = 'Admin updated successfully';
                    $message_type = 'success';
                    
                    // Log activity
                    $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'edit_admin', ?, ?, ?)");
                    $description = "Updated admin: $username";
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];
                    $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                    $log_stmt->execute();
                } else {
                    $message = 'Error updating admin: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            break;
            
        case 'delete':
            $admin_id = account_positive_integer($_POST['admin_id'] ?? null);
            if ($admin_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            // Prevent self-deletion and only super admin can delete
            if ($admin_id == $_SESSION['super_admin_id']) {
                $message = 'You cannot delete your own account';
                $message_type = 'danger';
            } elseif ($current_role !== 'super_admin') {
                $message = 'Only super admin can delete admins';
                $message_type = 'danger';
            } elseif (!confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to delete this administrator.';
                $message_type = 'danger';
            } else {
                // Validate proof image is uploaded
                if (!isset($_FILES['deletion_proof_image']) || $_FILES['deletion_proof_image']['error'] !== UPLOAD_ERR_OK) {
                    $message = 'Deletion proof image is required';
                    $message_type = 'danger';
                    break;
                }
                
                // Get admin info before deletion
                $admin_info = $conn->prepare("SELECT username, full_name FROM infos WHERE id = ?");
                $admin_info->bind_param("i", $admin_id);
                $admin_info->execute();
                $deleted_admin = $admin_info->get_result()->fetch_assoc();
                
                if (!$deleted_admin) {
                    $message = 'Admin not found';
                    $message_type = 'danger';
                } else {
                    // Handle proof image upload
                    $upload_dir = '../uploads/deletion_proofs/';
                    $file = $_FILES['deletion_proof_image'];
                    $file_extension = validated_upload_extension($file, ['jpg', 'png', 'pdf'], 5 * 1024 * 1024);
                    if ($file_extension === null) {
                        $message = 'Choose a valid JPG, PNG, or PDF proof file up to 5 MB.';
                        $message_type = 'danger';
                        break;
                    }

                    // Generate unique filename
                    $proof_filename = 'proof_admin_' . $admin_id . '_' . bin2hex(random_bytes(12)) . '.' . $file_extension;
                    $proof_path = $upload_dir . $proof_filename;
                    
                    // Move uploaded file
                    if (!move_uploaded_file($file['tmp_name'], $proof_path)) {
                        $message = 'Failed to upload proof image';
                        $message_type = 'danger';
                        break;
                    }
                    
                    // Get deletion reason (optional)
                    $deletion_reason = isset($_POST['deletion_reason']) ? trim($_POST['deletion_reason']) : null;
                    
                    // Store deletion proof record
                    $proof_stmt = $conn->prepare("INSERT INTO deletion_proofs (deleted_user_id, deleted_user_type, deleted_username, deleted_full_name, proof_image, deletion_reason, deleted_by, ip_address) VALUES (?, 'admin', ?, ?, ?, ?, ?, ?)");
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $proof_stmt->bind_param("issssis", $admin_id, $deleted_admin['username'], $deleted_admin['full_name'], $proof_filename, $deletion_reason, $_SESSION['super_admin_id'], $ip_address);
                    $proof_stmt->execute();
                    
                    // Proceed with deletion
                    $stmt = $conn->prepare("DELETE FROM infos WHERE id = ?");
                    $stmt->bind_param("i", $admin_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Admin deleted successfully with proof recorded';
                        $message_type = 'success';
                        
                        // Log activity
                        $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'delete_admin', ?, ?, ?)");
                        $description = "Deleted admin: " . $deleted_admin['username'] . " (Proof: " . $proof_filename . ")";
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                        $log_stmt->execute();
                    } else {
                        $message = 'Error deleting admin: ' . $conn->error;
                        $message_type = 'danger';
                        
                        // Remove uploaded proof file if deletion failed
                        if (file_exists($proof_path)) {
                            unlink($proof_path);
                        }
                    }
                }
            }
            break;
            
        case 'block':
            $admin_id = account_positive_integer($_POST['admin_id'] ?? null);
            if ($admin_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            // Prevent self-blocking and only super admin can block
            if ($admin_id == $_SESSION['super_admin_id']) {
                $message = 'You cannot block your own account';
                $message_type = 'danger';
            } elseif ($current_role !== 'super_admin') {
                $message = 'Only super admin can block admins';
                $message_type = 'danger';
            } elseif (!confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to block this administrator.';
                $message_type = 'danger';
            } else {
                // Get admin info
                $admin_info = $conn->prepare("SELECT username FROM infos WHERE id = ?");
                $admin_info->bind_param("i", $admin_id);
                $admin_info->execute();
                $blocked_admin = $admin_info->get_result()->fetch_assoc();
                
                if (!$blocked_admin) {
                    $message = 'Admin not found';
                    $message_type = 'danger';
                } else {
                    $stmt = $conn->prepare("UPDATE infos SET is_blocked = 1 WHERE id = ?");
                    $stmt->bind_param("i", $admin_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Admin blocked successfully';
                        $message_type = 'success';
                        
                        // Create notification for blocked user (if table exists)
                        $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
                        if ($table_check && $table_check->num_rows > 0) {
                            $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'blocked', 'Account Blocked', 'Your account has been blocked by the Administrator. You will not be able to login until your account is unblocked. Please contact support for assistance.')");
                            $notif_stmt->bind_param("i", $admin_id);
                            $notif_stmt->execute();
                        }
                        
                        // Log activity
                        $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'block_admin', ?, ?, ?)");
                        $description = "Blocked admin: " . $blocked_admin['username'];
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                        $log_stmt->execute();
                    } else {
                        $message = 'Error blocking admin: ' . $conn->error;
                        $message_type = 'danger';
                    }
                }
            }
            break;
            
        case 'unblock':
            $admin_id = account_positive_integer($_POST['admin_id'] ?? null);
            if ($admin_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            if ($current_role !== 'super_admin') {
                $message = 'Only super admin can unblock admins';
                $message_type = 'danger';
            } else {
                // Get admin info
                $admin_info = $conn->prepare("SELECT username FROM infos WHERE id = ?");
                $admin_info->bind_param("i", $admin_id);
                $admin_info->execute();
                $unblocked_admin = $admin_info->get_result()->fetch_assoc();
                
                if (!$unblocked_admin) {
                    $message = 'Admin not found';
                    $message_type = 'danger';
                } else {
                    $stmt = $conn->prepare("UPDATE infos SET is_blocked = 0 WHERE id = ?");
                    $stmt->bind_param("i", $admin_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Admin unblocked successfully';
                        $message_type = 'success';
                        
                        // Create notification for unblocked user (if table exists)
                        $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
                        if ($table_check && $table_check->num_rows > 0) {
                            $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'unblocked', 'Account Unblocked', 'Good news! Your account has been unblocked by the Administrator. You can now login and access the system normally.')");
                            $notif_stmt->bind_param("i", $admin_id);
                            $notif_stmt->execute();
                        }
                        
                        // Log activity
                        $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'unblock_admin', ?, ?, ?)");
                        $description = "Unblocked admin: " . $unblocked_admin['username'];
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                        $log_stmt->execute();
                    } else {
                        $message = 'Error unblocking admin: ' . $conn->error;
                        $message_type = 'danger';
                    }
                }
            }
            break;
            
        case 'reset_password':
            $admin_id = account_positive_integer($_POST['admin_id'] ?? null);
            if ($admin_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            $new_password = account_input('new_password', false);
            if (!account_password_is_valid($new_password)) {
                $message = 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.';
                $message_type = 'danger';
                break;
            }

            if ($current_role === 'super_admin' && !confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to reset this administrator password.';
                $message_type = 'danger';
                break;
            }
            
            if ($current_role !== 'super_admin' && $admin_id != $_SESSION['super_admin_id']) {
                $message = 'Insufficient permissions';
                $message_type = 'danger';
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE infos SET passw = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $admin_id);
                
                if ($stmt->execute()) {
                    $message = 'Password reset successfully';
                    $message_type = 'success';
                    
                    // Log activity
                    $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'reset_password', ?, ?, ?)");
                    $description = "Password reset for admin ID: $admin_id";
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];
                    $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                    $log_stmt->execute();
                } else {
                    $message = 'Error resetting password: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            break;
    }
}

// Pagination setup for admins - 10 per page
$admins_per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $admins_per_page;

// Get total admins count
$total_admins_count = $conn->query("SELECT COUNT(*) as total FROM infos WHERE role IN ('admin', 'super_admin')")->fetch_assoc()['total'];
$total_pages = ceil($total_admins_count / $admins_per_page);

// Get all admins (exclude customers) with pagination
$admins = $conn->query("SELECT * FROM infos WHERE role IN ('admin', 'super_admin') ORDER BY id DESC LIMIT $admins_per_page OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <link rel="stylesheet" href="../css/bookshelf_loader.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
</head>
<body>
    
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0">
                        Manage Administrators
                    </h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin_data['full_name']); ?>!</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <?php if ($current_role === 'super_admin'): ?>
                <a class="btn btn-primary" href="unified_admin_users.php?add=1">
                    <i class="fas fa-plus-circle me-2"></i>Add New User
                </a>
                <a class="btn btn-outline-primary" href="unified_admin_users.php?add=staff">Add Administrator</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($current_role !== 'super_admin'): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Limited Access:</strong> You are logged in as a regular admin. Only <strong>Administrators</strong> can add, delete, or reset passwords for admin accounts.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Admins Table -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-users text-primary me-2"></i>
                    All Administrators
                </h5>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Employee Number</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($admin['idnumber']); ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                    <?php if ($admin['id'] == $_SESSION['super_admin_id']): ?>
                                        <span class="badge bg-info ms-2">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($admin['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $admin['role'] === 'super_admin' ? 'indigo' : 'blue'; ?>">
                                        <?php echo $admin['role'] === 'super_admin' ? 'Super Admin' : 'Admin'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if (isset($admin['is_blocked']) && $admin['is_blocked'] == 1) {
                                        echo '<span class="badge bg-danger">Blocked</span>';
                                    } else {
                                        echo '<span class="badge bg-' . ($admin['status'] === 'active' ? 'success' : 'secondary') . '">' . ucfirst(htmlspecialchars($admin['status'])) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo isset($admin['last_login']) && !empty($admin['last_login']) ? date('M d, Y h:i A', strtotime($admin['last_login'])) : 'Never'; ?>
                                </td>
                                <td>
                                    <!-- Edit Button - Available to all admins -->
                                    <button class="btn-minimal btn-minimal-primary" onclick="editAdmin(<?php echo $admin['id']; ?>)" title="Edit Admin">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if ($admin['id'] != $_SESSION['super_admin_id']): ?>
                                    <!-- Reset Password - Available to all admins -->
                                    <button class="btn-minimal btn-minimal-warning" onclick="resetPassword(<?php echo $admin['id']; ?>)" title="Reset Password">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    
                                    <!-- Transfer the Crown Button - Only for Super Admin to promote regular admins -->
                                    <?php if ($current_role === 'super_admin' && strtolower($admin['role']) === 'admin'): ?>
                                    <button class="btn-minimal btn-minimal-torch" onclick="passTorch(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>', '<?php echo htmlspecialchars($admin['full_name']); ?>')" title="Transfer the Crown - Promote to Super Admin">
                                        <i class="fas fa-crown"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Block/Unblock Button - Available to all admins -->
                                    <?php if (isset($admin['is_blocked']) && $admin['is_blocked'] == 1): ?>
                                    <button class="btn-minimal btn-minimal-success" onclick="unblockAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')" title="Unblock Admin">
                                        <i class="fas fa-unlock"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="btn-minimal btn-minimal-dark" onclick="blockAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')" title="Block Admin">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Delete Button - Available to all admins -->
                                    <button class="btn-minimal btn-minimal-danger" onclick="deleteAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')" title="Delete Admin">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <!-- Cannot modify yourself -->
                                    <button class="btn-minimal btn-minimal-secondary" disabled title="Cannot modify yourself">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Professional Pagination -->
                <?php echo render_pagination($page, $total_pages, $total_admins_count, $admins_per_page, '?', 'admins'); ?>
            </div>
        </div>
    </div>
    
    <!-- Add New User Modal - Enhanced Professional Design -->
    <div class="modal fade" id="addAdminModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content modern-modal-content">
                <!-- Modern Close Button -->
                <button type="button" class="modern-close-btn" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                
                <form method="POST" id="addAdminForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body p-0">
                        <input type="hidden" name="action" value="add">
                        
                        <!-- Modern Header Section -->
                        <div class="modern-modal-header">
                            <div class="header-icon-wrapper">
                                <div class="header-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                            </div>
                            <div class="header-content">
                                <h2 class="modal-main-title">Add New User</h2>
                                <p class="modal-subtitle">Create a new account with secure credentials and assign appropriate permissions</p>
                            </div>
                            
                            <!-- Progress Indicator -->
                            <div class="form-progress-container">
                                <div class="progress-bar-wrapper">
                                    <div class="progress-bar-fill" id="formProgress" style="width: 0%"></div>
                                </div>
                                <span class="progress-text" id="progressText">0% Complete</span>
                            </div>
                        </div>
                        
                        <!-- Modern Form Container -->
                        <div class="modern-form-container">
                        
                        <!-- Personal Information Section -->
                        <div class="form-section-modern">
                            <div class="section-header-modern">
                                <div class="section-icon-badge">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="section-title-group">
                                    <h4 class="section-title">Personal Information</h4>
                                    <p class="section-subtitle">Basic details and identification</p>
                                </div>
                                <span class="step-badge">Step 1 of 3</span>
                            </div>
                            
                            <div class="row g-4">
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            <span id="add_idnumber_label">Generated Employee Number</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="idnumber" id="add_idnumber" value="<?php echo htmlspecialchars($generated_idnumber, ENT_QUOTES, 'UTF-8'); ?>" readonly aria-readonly="true">
                                        </div>
                                        <span id="add_idnumber_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            First Name 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="firstname" id="add_firstname" placeholder="Enter first name" maxlength="15" required>
                                        </div>
                                        <span id="add_firstname_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            M.I 
                                            <span class="optional-text">(optional)</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input text-center" name="middlename" id="add_middlename" placeholder="M" maxlength="1">
                                        </div>
                                        <span id="add_middlename_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Last Name 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="lastname" id="add_lastname" placeholder="Enter last name" maxlength="15" required>
                                        </div>
                                        <span id="add_lastname_error" class="error-message"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-4 mt-1">
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Extension 
                                            <span class="optional-text">(optional)</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="suffix" id="add_suffix" placeholder="Jr., Sr., III" maxlength="3">
                                        </div>
                                        <span id="add_suffix_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Sex 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <select class="modern-input" name="sex" id="add_sex" required>
                                                <option value="">Select</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                        <span id="add_sex_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Birthdate 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="date" class="modern-input" name="birth" id="add_birthdate" required>
                                        </div>
                                        <span id="add_birthdate_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="modern-form-field">
                                        <label class="modern-label">Age</label>
                                        <div class="modern-input-wrapper">
                                            <input type="number" class="modern-input" name="age" id="add_age" placeholder="Auto-calculated" readonly required>
                                        </div>
                                        <span id="add_age_error" class="error-message"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Account Credentials Section -->
                        <div class="form-section-modern">
                            <div class="section-header-modern">
                                <div class="section-icon-badge">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="section-title-group">
                                    <h4 class="section-title">Account Credentials</h4>
                                    <p class="section-subtitle">Login information and security</p>
                                </div>
                                <span class="step-badge">Step 2 of 3</span>
                            </div>
                            
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Email Address 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="email" class="modern-input" name="email" id="add_email" placeholder="admin@example.com" maxlength="30" required>
                                        </div>
                                        <span id="add_email_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Username 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="username" id="add_username" placeholder="Enter username" maxlength="15" required>
                                        </div>
                                        <span id="add_username_error" class="error-message"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mt-3">
                                <label for="new_account_temporary_password" class="form-label">Temporary password <span aria-hidden="true">*</span></label>
                                <div class="d-flex align-items-stretch gap-2">
                                    <input type="password" class="modern-input" id="new_account_temporary_password" name="temporary_password" minlength="8" maxlength="15"
                                        pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}" autocomplete="new-password" aria-describedby="new_account_temporary_password_help" required>
                                    <button type="button" class="btn btn-outline-secondary flex-shrink-0" data-temporary-password-toggle aria-controls="new_account_temporary_password" aria-pressed="false">Show</button>
                                </div>
                                <small id="new_account_temporary_password_help" class="text-muted">Use 8 to 15 characters with uppercase and lowercase letters and a number. The password will be emailed to the new account holder.</small>
                            </div>
                        </div>
                        
                        <!-- Personal Address, Role & Status Section -->
                        <div class="form-section-modern">
                            <div class="section-header-modern">
                                <div class="section-icon-badge">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <div class="section-title-group">
                                    <h4 class="section-title">Personal Address, Role & Permissions</h4>
                                    <p class="section-subtitle">Location details and access control</p>
                                </div>
                                <span class="step-badge">Step 3 of 3</span>
                            </div>
                            
                            <div class="row g-4">
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Purok 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="street" id="add_street" placeholder="Enter purok" maxlength="15" required>
                                        </div>
                                        <span id="add_street_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Barangay 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="barangay" id="add_barangay" placeholder="Enter barangay" maxlength="15" required>
                                        </div>
                                        <span id="add_barangay_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Municipality/City 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="municipality" id="add_municipality" placeholder="Enter municipality" maxlength="20" required>
                                        </div>
                                        <span id="add_municipality_error" class="error-message"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-4 mt-1">
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Province 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="province" id="add_province" placeholder="Enter province" maxlength="20" required>
                                        </div>
                                        <span id="add_province_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Country 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="country" id="add_country" placeholder="Enter country" maxlength="15" required>
                                        </div>
                                        <span id="add_country_error" class="error-message"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="modern-form-field">
                                        <label class="modern-label">
                                            Zip Code 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <input type="text" class="modern-input" name="postal" id="add_postal" placeholder="Enter zip code" maxlength="4" required>
                                        </div>
                                        <span id="add_postal_error" class="error-message"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Role Selection - Modern Cards -->
                            <div class="row g-4 mt-4">
                                <div class="col-12">
                                    <label class="modern-label mb-3">
                                        Select Role & Permissions 
                                        <span class="required-star">*</span>
                                    </label>
                                    <p class="role-selection-subtitle">Choose the appropriate access level for this user</p>
                                    
                                    <div class="role-cards-grid">
                                        <!-- Claimant Role -->
                                        <div class="role-card-modern">
                                            <input type="radio" name="role" id="role_customer" value="customer" required hidden>
                                            <label for="role_customer" class="role-card-label-modern">
                                                <div class="role-card-header-modern">
                                                    <div class="role-icon-modern customer">
                                                        <i class="fas fa-user"></i>
                                                    </div>
                                                    <div class="role-info">
                                                        <h5 class="role-name">Claimant</h5>
                                                        <span class="role-badge customer-badge">Basic Access</span>
                                                    </div>
                                                </div>
                                                <p class="role-desc">User who reports lost items or claims found items</p>
                                                <ul class="role-perms">
                                                    <li><i class="fas fa-check"></i> Browse lost & found items</li>
                                                    <li><i class="fas fa-check"></i> File loss reports</li>
                                                    <li><i class="fas fa-check"></i> Submit item claims</li>
                                                    <li><i class="fas fa-check"></i> Track claim status</li>
                                                </ul>
                                            </label>
                                        </div>
                                        
                                        <!-- Items Manager Role -->
                                        <div class="role-card-modern">
                                            <input type="radio" name="role" id="role_admin" value="admin" checked required hidden>
                                            <label for="role_admin" class="role-card-label-modern">
                                                <div class="role-card-header-modern">
                                                    <div class="role-icon-modern admin">
                                                        <i class="fas fa-user-shield"></i>
                                                    </div>
                                                    <div class="role-info">
                                                        <h5 class="role-name">Items Manager</h5>
                                                        <span class="role-badge admin-badge">Management</span>
                                                    </div>
                                                </div>
                                                <p class="role-desc">Manage items and handle claims</p>
                                                <ul class="role-perms">
                                                    <li><i class="fas fa-check"></i> Add & catalog lost items</li>
                                                    <li><i class="fas fa-check"></i> Process item claims</li>
                                                    <li><i class="fas fa-check"></i> View claimant information</li>
                                                    <li><i class="fas fa-check"></i> Generate reports</li>
                                                </ul>
                                            </label>
                                        </div>
                                        
                                        <!-- System Administrator Role -->
                                        <div class="role-card-modern">
                                            <input type="radio" name="role" id="role_superadmin" value="super_admin" required hidden>
                                            <label for="role_superadmin" class="role-card-label-modern">
                                                <div class="role-card-header-modern">
                                                    <div class="role-icon-modern superadmin">
                                                        <i class="fas fa-crown"></i>
                                                    </div>
                                                    <div class="role-info">
                                                        <h5 class="role-name">Super Admin</h5>
                                                        <span class="role-badge superadmin-badge">Full Control</span>
                                                    </div>
                                                </div>
                                                <p class="role-desc">Complete system access and oversight</p>
                                                <ul class="role-perms">
                                                    <li><i class="fas fa-check"></i> All manager privileges</li>
                                                    <li><i class="fas fa-check"></i> Manage users & admins</li>
                                                    <li><i class="fas fa-check"></i> System configuration</li>
                                                    <li><i class="fas fa-check"></i> Security management</li>
                                                </ul>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Status Selection -->
                            <div class="row g-4 mt-3">
                                <div class="col-md-6 mx-auto">
                                    <div class="modern-form-field">
                                        <label class="modern-label text-center d-block">
                                            Account Status 
                                            <span class="required-star">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <select class="modern-input" name="status" required>
                                                <option value="">Select Status</option>
                                                <option value="active" selected>Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div><!-- Close modern-form-container -->
                        
                        <!-- Modern Action Buttons -->
                        <div class="modern-modal-footer">
                            <button type="button" class="btn-modern btn-cancel" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn-modern btn-submit">
                                <i class="fas fa-user-plus me-2"></i>
                                Create Administrator
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Auto-calculate age from birthdate
    document.getElementById('add_birthdate')?.addEventListener('change', function() {
        const birthDate = new Date(this.value);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        document.getElementById('add_age').value = age;
    });

    // Update privilege based on role selection
    document.getElementById('add_role')?.addEventListener('change', function() {
        const privilegeSelect = document.getElementById('add_privilege');
        const roleValue = this.value;
        
        switch(roleValue) {
            case 'admin':
                privilegeSelect.value = 'all';
                break;
            case 'super_admin':
                privilegeSelect.value = 'full_access';
                break;
            default:
                privilegeSelect.value = '';
        }
    });

    // Password toggle function for add modal
    function togglePasswordAdd(inputId) {
        const input = document.getElementById(inputId);
        const button = input.parentElement.querySelector('.password-toggle-btn');
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // Password confirmation validation
    document.getElementById('add_confirm_password')?.addEventListener('input', function() {
        const password = document.getElementById('add_password').value;
        if (this.value !== password) {
            this.setCustomValidity('Passwords do not match');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Form progress tracking
    document.addEventListener('DOMContentLoaded', function() {
        const idNumberLabel = document.getElementById('add_idnumber_label');
        const roleInputs = document.querySelectorAll('input[name="role"]');

        function updateIdNumberLabel() {
            const selectedRole = document.querySelector('input[name="role"]:checked')?.value;
            const idNumberInput = document.getElementById('add_idnumber');
            if (idNumberInput) {
                idNumberInput.value = selectedRole === 'customer'
                    ? <?= json_encode($generated_student_idnumber) ?>
                    : <?= json_encode($generated_idnumber) ?>;
            }
            if (idNumberLabel) {
                idNumberLabel.textContent = selectedRole === 'customer'
                    ? 'Generated ID Number'
                    : 'Generated Employee Number';
            }
        }

        roleInputs.forEach(input => input.addEventListener('change', updateIdNumberLabel));
        updateIdNumberLabel();

        const form = document.getElementById('addAdminForm');
        if (form) {
            const inputs = form.querySelectorAll('input[required], select[required]');
            const progressBar = document.getElementById('formProgress');
            const progressText = document.getElementById('progressText');
            
            function updateProgress() {
                let filled = 0;
                inputs.forEach(input => {
                    if (input.type === 'radio') {
                        const radioGroup = form.querySelectorAll(`input[name="${input.name}"]`);
                        if (Array.from(radioGroup).some(r => r.checked)) {
                            filled++;
                        }
                    } else if (input.value.trim() !== '') {
                        filled++;
                    }
                });
                
                const percentage = Math.round((filled / inputs.length) * 100);
                if (progressBar) progressBar.style.width = percentage + '%';
                if (progressText) progressText.textContent = percentage + '% Complete';
            }
            
            inputs.forEach(input => {
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });
        }
    });
    </script>
    
    <!-- Ultra Aesthetic Edit Admin Modal -->
    <div class="modal fade" id="editAdminModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <!-- Elegant Close Button -->
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal" aria-label="Close">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="editForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="admin_id" id="edit_admin_id">
                        
                        <!-- Minimalist Icon -->
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon edit-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Clean Title -->
                        <h3 class="aesthetic-title">Edit Administrator</h3>
                        <p class="aesthetic-subtitle">Update account details</p>
                        
                        <!-- Minimalist Form -->
                        <div class="aesthetic-form">
                            <div class="form-field-aesthetic">
                                <label class="field-label">Username</label>
                                <div class="input-wrapper-aesthetic">
                                    <input type="text" class="input-aesthetic" name="username" id="edit_username" placeholder="Enter username" required>
                                </div>
                            </div>
                            
                            <div class="form-field-aesthetic">
                                <label class="field-label">Email Address</label>
                                <div class="input-wrapper-aesthetic">
                                    <input type="email" class="input-aesthetic" name="email" id="edit_email" placeholder="Enter email" required>
                                </div>
                            </div>
                            
                            <div class="form-field-aesthetic">
                                <label class="field-label">Full Name</label>
                                <div class="input-wrapper-aesthetic">
                                    <input type="text" class="input-aesthetic" name="full_name" id="edit_full_name" placeholder="Enter full name" required>
                                </div>
                            </div>
                            
                            <div class="form-field-aesthetic">
                                <label class="field-label">Status</label>
                                <div class="input-wrapper-aesthetic">
                                    <select class="input-aesthetic" name="status" id="edit_status" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <?php if ($current_role === 'super_admin'): ?>
                            <div class="form-field-aesthetic">
                                <label class="field-label">Confirm Super Admin Password</label>
                                <div class="input-wrapper-aesthetic password-wrapper">
                                    <input type="password" class="input-aesthetic" name="superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Elegant Buttons -->
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-primary-aesthetic">
                                Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Ultra Aesthetic Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <!-- Elegant Close Button -->
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal" aria-label="Close">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="resetPasswordForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="admin_id" id="reset_admin_id">
                        
                        <!-- Minimalist Icon -->
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon reset-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Clean Title -->
                        <h3 class="aesthetic-title">Reset Password</h3>
                        <p class="aesthetic-subtitle">Create a new secure password</p>
                        
                        <!-- Minimalist Form -->
                        <div class="aesthetic-form">
                            <div class="form-field-aesthetic">
                                <label class="field-label">New Password</label>
                                <div class="input-wrapper-aesthetic password-wrapper">
                                    <input type="password" class="input-aesthetic" name="new_password" id="new_password_reset" placeholder="Enter new password" required minlength="8">
                                    <button type="button" class="password-toggle-aesthetic" onclick="togglePasswordReset('new_password_reset')">
                                        <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                                <span class="field-hint">Minimum 8 characters</span>
                            </div>
                            
                            <div class="form-field-aesthetic">
                                <label class="field-label">Confirm Password</label>
                                <div class="input-wrapper-aesthetic password-wrapper">
                                    <input type="password" class="input-aesthetic" id="confirm_password_reset" placeholder="Re-enter password" required>
                                    <button type="button" class="password-toggle-aesthetic" onclick="togglePasswordReset('confirm_password_reset')">
                                        <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <?php if ($current_role === 'super_admin'): ?>
                            <div class="form-field-aesthetic">
                                <label class="field-label">Confirm Super Admin Password</label>
                                <div class="input-wrapper-aesthetic password-wrapper">
                                    <input type="password" class="input-aesthetic" name="superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="password-match-aesthetic" id="password_match_indicator" style="display: none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                <span>Passwords match</span>
                            </div>
                        </div>
                        
                        <!-- Elegant Buttons -->
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-primary-aesthetic">
                                Update Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Professional Delete Confirmation Modal with Proof Upload -->
    <!-- Professional Delete Confirmation Modal with Proof Upload -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">

                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal" aria-label="Close">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>

                <form method="POST" id="deleteAdminForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="admin_id" id="delete_admin_id">
                        <?php if ($current_role === 'super_admin'): ?>
                        <div class="form-field-aesthetic">
                            <label class="field-label">Confirm Super Admin Password</label>
                            <div class="input-wrapper-aesthetic password-wrapper">
                                <input type="password" class="input-aesthetic" name="superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon delete-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                    <path d="M10 11v6"></path>
                                    <path d="M14 11v6"></path>
                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                                </svg>
                            </div>
                        </div>

                        <h3 class="aesthetic-title">Delete Administrator</h3>
                        <p class="aesthetic-subtitle">This action is permanent and cannot be undone</p>

                        <div class="aesthetic-form">
                            <div class="block-user-info">
                                <i class="fas fa-user"></i>
                                <span id="delete_admin_name" class="delete-admin-name"></span>
                            </div>

                            <div class="block-consequences">
                                <div class="consequence-item">
                                    <i class="fas fa-times-circle text-danger"></i>
                                    <span>All system access revoked immediately</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-database text-muted"></i>
                                    <span>Activity logs preserved for audit</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-exclamation-circle text-warning"></i>
                                    <span>Cannot be undone</span>
                                </div>
                            </div>

                            <!-- Proof Upload -->
                            <div class="delete-proof-compact">
                                <div class="delete-proof-label">
                                    <i class="fas fa-shield-alt"></i>
                                    <span>Deletion Proof Required</span>
                                </div>
                                <label for="deletion_proof_image" class="delete-proof-upload">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span id="proof_file_label">Click to upload proof (PNG, JPG, PDF Â· Max 5MB)</span>
                                    <input type="file" id="deletion_proof_image" name="deletion_proof_image"
                                           accept="image/png,image/jpeg,image/jpg,application/pdf"
                                           class="proof-file-input" required
                                           onchange="handleProofUpload(this)">
                                </label>
                                <div id="proof_preview_container" class="proof-preview-compact" style="display:none;">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span id="proof_file_name"></span>
                                    <button type="button" onclick="removeProofImage()" class="proof-remove-compact">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Reason -->
                            <textarea class="delete-reason-input" id="deletion_reason" name="deletion_reason"
                                      rows="2" placeholder="Deletion reason (optional)..."></textarea>

                            <!-- Checkbox -->
                            <label class="delete-check-compact">
                                <input type="checkbox" id="confirmDeleteCheckbox" class="delete-checkbox"
                                       onchange="document.getElementById('confirmDeleteBtn').disabled = !this.checked">
                                <span>I confirm this deletion with valid proof attached</span>
                            </label>
                        </div>

                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-danger-aesthetic" id="confirmDeleteBtn" disabled>
                                <i class="fas fa-trash me-2"></i>
                                Delete
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Block Admin Confirmation Modal -->
    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="blockAdminForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="block">
                        <input type="hidden" name="admin_id" id="block_admin_id">
                        
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon block-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                                </svg>
                            </div>
                        </div>
                        
                        <h3 class="aesthetic-title">Block Administrator</h3>
                        <p class="aesthetic-subtitle">This will prevent the user from logging in</p>
                        
                        <div class="aesthetic-form">
                            <div class="block-user-info">
                                <i class="fas fa-user"></i>
                                <span id="block_admin_name"></span>
                            </div>
                            
                            <div class="block-consequences">
                                <div class="consequence-item">
                                    <i class="fas fa-lock text-danger"></i>
                                    <span>User will not be able to login</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-bell text-warning"></i>
                                    <span>User will be notified about the block</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-undo text-info"></i>
                                    <span>Can be unblocked anytime</span>
                                </div>
                            </div>
                            <?php if ($current_role === 'super_admin'): ?>
                            <div class="form-field-aesthetic mt-3">
                                <label class="field-label">Confirm Super Admin Password</label>
                                <div class="input-wrapper-aesthetic password-wrapper">
                                    <input type="password" class="input-aesthetic" name="superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-danger-aesthetic">
                                <i class="fas fa-ban me-2"></i>
                                Block User
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Unblock Admin Confirmation Modal -->
    <div class="modal fade" id="unblockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="unblockAdminForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="unblock">
                        <input type="hidden" name="admin_id" id="unblock_admin_id">
                        
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon unblock-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 9.9-1"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <h3 class="aesthetic-title">Unblock Administrator</h3>
                        <p class="aesthetic-subtitle">Restore access for this user</p>
                        
                        <div class="aesthetic-form">
                            <div class="block-user-info">
                                <i class="fas fa-user"></i>
                                <span id="unblock_admin_name"></span>
                            </div>
                            
                            <div class="block-consequences">
                                <div class="consequence-item">
                                    <i class="fas fa-unlock text-success"></i>
                                    <span>User will be able to login again</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-bell text-info"></i>
                                    <span>User will be notified about the unblock</span>
                                </div>
                                <div class="consequence-item">
                                    <i class="fas fa-check text-success"></i>
                                    <span>Full access will be restored</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-success-aesthetic">
                                <i class="fas fa-unlock me-2"></i>
                                Unblock User
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Transfer the Crown Modal -->
    <div class="modal fade" id="passTorchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">

                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>

                <div class="modal-body aesthetic-body">

                    <div class="aesthetic-icon-wrapper">
                        <div class="aesthetic-icon torch-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2c0 0-4 4-4 8a4 4 0 0 0 8 0c0-4-4-8-4-8z"></path>
                                <path d="M12 14v8"></path>
                                <path d="M9 22h6"></path>
                            </svg>
                        </div>
                    </div>

                    <h3 class="aesthetic-title">Transfer the Crown</h3>
                    <p class="aesthetic-subtitle">Transfer Super Admin privileges to another admin</p>

                    <div class="aesthetic-form">
                        <div class="block-user-info">
                            <i class="fas fa-user-shield"></i>
                            <span id="torch_admin_name"></span>
                        </div>

                        <div class="block-consequences">
                            <div class="consequence-item">
                                <i class="fas fa-crown" style="color:#F59E0B;"></i>
                                <span><strong id="torch_admin_username"></strong> becomes the new Super Admin</span>
                            </div>
                            <div class="consequence-item">
                                <i class="fas fa-user-minus text-danger"></i>
                                <span>Your role is downgraded to Admin</span>
                            </div>
                            <div class="consequence-item">
                                <i class="fas fa-ban text-danger"></i>
                                <span>Your account will be disabled</span>
                            </div>
                            <div class="consequence-item">
                                <i class="fas fa-sign-out-alt text-warning"></i>
                                <span>You will be logged out immediately</span>
                            </div>
                        </div>
                    </div>

                    <div class="aesthetic-buttons">
                        <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="button" class="btn-aesthetic btn-torch-confirm" id="confirmTorchBtn" onclick="submitPassTorch()">
                            <i class="fas fa-crown me-2"></i>
                            Confirm Transfer
                        </button>
                    </div>

                </div>

                <input type="hidden" id="torch_admin_id">
            </div>
        </div>
    </div>

    <style>
        .torch-icon {
            background: #F59E0B;
            color: white;
            box-shadow: none;
        }
        .btn-torch-confirm {
            background: #F59E0B;
            color: white;
        }
        .btn-torch-confirm:hover {
            background: #D97706;
            transform: none;
            box-shadow: none;
        }
        .btn-torch-confirm:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .btn-minimal-torch {
            border: none !important;
            background: transparent !important;
            color: #F59E0B !important;
        }
        .btn-minimal-torch:hover {
            background: transparent !important;
            color: #D97706 !important;
            transform: scale(1.15);
        }
    </style>

    <style>
        /* Professional Super Admin Button Styles */
        .btn-professional {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .btn-professional:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn-professional:active {
            transform: translateY(0);
        }
        
        .btn-professional i {
            font-size: 16px;
        }
        
        .btn-professional-primary {
            background: #4F46E5;
            border-color: #4F46E5;
            color: white;
        }
        
        .btn-professional-primary:hover {
            background: #4338CA;
            border-color: #4338CA;
            box-shadow: none;
        }
        
        /* User Info Card - Minimalistic Professional Design */
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 8px 16px;
            border-radius: 12px;
            background: white;
            border: 1px solid #E2E8F0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .user-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #CBD5E1;
        }
        
        .user-info .dropdown-toggle::after {
            display: none;
        }
        
        .user-info .text-end {
            position: relative;
        }
        
        .user-info .text-end .fw-bold {
            color: #0F172A;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .user-info .text-end small {
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #4F46E5;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: none;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .dropdown-menu {
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            background: white;
            min-width: 220px;
            padding: 8px;
            margin-top: 8px;
        }
        
        .dropdown-item {
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
            color: #475569;
        }
        
        .dropdown-item:hover {
            background: #F1F5F9;
            color: #0F172A;
        }
        
        .dropdown-item i {
            width: 20px;
        }
        
        .dropdown-divider {
            margin: 8px 0;
            border-color: #E2E8F0;
        }
        
        .btn-professional-secondary {
            background: #F3F4F6;
            border-color: #E5E7EB;
            color: #6B7280;
        }
        
        .btn-professional-secondary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Minimal Button Styles - Remove backgrounds */
        .btn-minimal {
            border: none !important;
            background: transparent !important;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 13px;
            margin: 0 3px;
            position: relative;
        }
        
        .btn-minimal:hover {
            transform: translateY(-1px);
            box-shadow: none !important;
        }
        
        .btn-minimal:active {
            transform: translateY(0);
        }
        
        .btn-minimal:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .btn-minimal-primary {
            border: none !important;
            color: #4F46E5 !important;
            background: transparent !important;
        }
        
        .btn-minimal-primary:hover {
            background: transparent !important;
            color: #4338CA !important;
            border: none !important;
        }
        
        .btn-minimal-warning {
            border: none !important;
            color: #D97706 !important;
            background: transparent !important;
        }
        
        .btn-minimal-warning:hover {
            background: transparent !important;
            color: #B45309 !important;
            border: none !important;
        }
        
        .btn-minimal-danger {
            border: none !important;
            color: #DC2626 !important;
            background: transparent !important;
        }
        
        .btn-minimal-danger:hover {
            background: transparent !important;
            color: #B91C1C !important;
            border: none !important;
        }
        
        .btn-minimal-dark {
            border: none !important;
            color: #374151 !important;
            background: transparent !important;
        }
        
        .btn-minimal-dark:hover {
            background: transparent !important;
            color: #1F2937 !important;
            border: none !important;
        }
        
        .btn-minimal-success {
            border: none !important;
            color: #059669 !important;
            background: transparent !important;
        }
        
        .btn-minimal-success:hover {
            background: transparent !important;
            color: #047857 !important;
            border: none !important;
        }
        
        .btn-minimal-secondary {
            border: none !important;
            color: #6B7280 !important;
            background: transparent !important;
        }
        
        .btn-minimal-secondary:hover {
            background: transparent !important;
            color: #4B5563 !important;
            border: none !important;
        }
        
        /* Block/Unblock Modal Styles */
        .block-icon {
            background: #EF4444;
            color: white;
        }
        
        .unblock-icon {
            background: #10B981;
            color: white;
        }
        
        .block-user-info {
            background: #f8f9fa;
            padding: 16px 20px;
            border-radius: 12px;
            margin: 24px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .block-user-info i {
            color: #6c757d;
            font-size: 18px;
        }
        
        .block-consequences {
            text-align: left;
            margin: 20px 0;
        }
        
        .consequence-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            font-size: 14px;
            color: #64748b;
        }
        
        .consequence-item i {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }
        
        .btn-danger-aesthetic {
            background: #EF4444;
            color: white;
        }
        
        .btn-danger-aesthetic:hover {
            background: #DC2626;
            transform: none;
            box-shadow: none;
        }
        
        .btn-success-aesthetic {
            background: #10B981;
            color: white;
        }
        
        .btn-success-aesthetic:hover {
            background: #059669;
            transform: none;
            box-shadow: none;
        }
        
        /* Minimalist Delete Modal Styles */
        .modal-custom-wide {
            max-width: 600px;
        }
        
        .delete-modal-content {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
            overflow: hidden;
        }
        
        .delete-modal-header {
            background: #ffffff;
            color: #2c3e50;
            padding: 24px 32px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .delete-modal-header .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .delete-modal-body {
            padding: 32px;
            background: #ffffff;
        }
        
        /* Simple Warning Icon */
        .delete-icon-container {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .delete-warning-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto;
            background: #fee;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .delete-warning-icon i {
            font-size: 32px;
            color: #dc3545;
        }
        
        /* Clean Warning Box */
        .delete-warning-box {
            background: #f8f9fa;
            border-left: 4px solid #dc3545;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .delete-warning-title {
            color: #2c3e50;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .delete-warning-text {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        
        .delete-admin-info {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            font-size: 15px;
            font-weight: 600;
            color: #dc3545;
        }
        
        .delete-admin-name {
            font-weight: 600;
        }
        
        /* Simple Consequences Section */
        .delete-consequences {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .consequence-header {
            font-size: 14px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 12px;
        }
        
        .consequence-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .consequence-list li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            font-size: 13px;
            color: #6c757d;
        }
        
        .consequence-list li i {
            font-size: 14px;
            min-width: 20px;
            margin-right: 8px;
        }
        
        /* Clean Confirmation Checkbox */
        .delete-confirmation-check {
            background: #fff9e6;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 16px;
        }
        
        .delete-checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            margin: 0;
            user-select: none;
        }
        
        .delete-checkbox {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #dc3545;
        }
        
        .delete-checkbox-text {
            font-size: 14px;
            font-weight: 500;
            color: #856404;
            line-height: 1.4;
        }
        
        /* Modal Footer */
        .delete-modal-footer {
            padding: 20px 32px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            gap: 12px;
        }
        
        /* Minimalist Buttons */
        .delete-btn-cancel,
        .delete-btn-confirm {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            border: none;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 160px;
        }
        
        .delete-btn-cancel {
            background: #ffffff;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .delete-btn-cancel:hover {
            background: #f8f9fa;
            color: #495057;
            border-color: #adb5bd;
        }
        
        .delete-btn-confirm {
            background: #dc3545;
            color: white;
        }
        
        .delete-btn-confirm:not(:disabled):hover {
            background: #c82333;
            color: white;
        }
        
        .delete-btn-confirm:disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }
        
        .delete-btn-confirm.loading span {
            opacity: 0;
        }
        
        .delete-btn-confirm.loading .btn-loading-spinner {
            display: block !important;
            position: absolute;
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            .modal-custom-wide {
                max-width: 100%;
                margin: 10px;
            }
            
            .delete-modal-body {
                padding: 24px 20px;
            }
            
            .delete-modal-footer {
                flex-direction: column;
                padding: 16px 20px;
            }
            
            .delete-btn-cancel,
            .delete-btn-confirm {
                width: 100%;
                min-width: auto;
            }
        }
        
        /* Proof Upload Section Styles */
        .proof-upload-section {
            background: #f0f7ff;
            border: 2px dashed #3b82f6;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .proof-upload-header {
            font-size: 15px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .proof-upload-description {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        
        .proof-upload-container {
            margin-bottom: 16px;
        }
        
        .proof-upload-label {
            display: block;
            background: white;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .proof-upload-label:hover {
            border-color: #3b82f6;
            background: #f8fafc;
        }
        
        .proof-upload-icon {
            font-size: 48px;
            color: #3b82f6;
            margin-bottom: 12px;
        }
        
        .proof-upload-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .proof-upload-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .proof-upload-subtitle {
            font-size: 12px;
            color: #94a3b8;
        }
        
        .proof-file-input {
            display: none;
        }
        
        .proof-preview-container {
            background: white;
            border: 2px solid #10b981;
            border-radius: 10px;
            padding: 16px;
            margin-top: 16px;
        }
        
        .proof-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .proof-preview-title {
            font-size: 14px;
            font-weight: 600;
            color: #059669;
        }
        
        .proof-remove-btn {
            background: #fee2e2;
            border: none;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #dc2626;
        }
        
        .proof-remove-btn:hover {
            background: #fecaca;
        }
        
        .proof-preview-content {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .proof-file-name {
            font-size: 13px;
            color: #64748b;
            text-align: center;
            word-break: break-all;
        }
        
        /* Keep light theme always */
        
        /* ========================================
           ULTRA AESTHETIC MODAL STYLES
           Modern, Minimalist, Premium Design
        ======================================== */
        
        .modal-aesthetic {
            max-width: 1400px;
        }
        
        /* Compact Edit Modal Size */
        #editAdminModal .modal-dialog {
            max-width: 520px;
        }
        
        #editAdminModal .aesthetic-body {
            padding: 40px 48px;
        }
        
        #editAdminModal .aesthetic-form {
            max-width: 100%;
        }
        
        #editAdminModal .form-field-aesthetic {
            margin-bottom: 20px;
        }
        
        #editAdminModal .aesthetic-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
        }
        
        #editAdminModal .aesthetic-icon svg {
            width: 36px;
            height: 36px;
        }
        
        #editAdminModal .aesthetic-title {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        #editAdminModal .aesthetic-subtitle {
            font-size: 15px;
            margin-bottom: 36px;
        }
        
        #editAdminModal .field-label {
            font-size: 13px;
            margin-bottom: 8px;
            color: #6B7280;
        }
        
        #editAdminModal .input-aesthetic {
            height: 48px;
            font-size: 14px;
            padding: 12px 16px;
        }
        
        #editAdminModal .btn-aesthetic {
            height: 48px;
            font-size: 15px;
            min-width: 160px;
        }
        
        /* Compact Reset Password Modal Size */
        #resetPasswordModal .modal-dialog {
            max-width: 520px;
        }
        
        #resetPasswordModal .aesthetic-body {
            padding: 40px 48px;
        }
        
        #resetPasswordModal .aesthetic-form {
            max-width: 100%;
        }
        
        #resetPasswordModal .form-field-aesthetic {
            margin-bottom: 20px;
        }
        
        #resetPasswordModal .aesthetic-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
        }
        
        #resetPasswordModal .aesthetic-icon svg {
            width: 36px;
            height: 36px;
        }
        
        #resetPasswordModal .aesthetic-title {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        #resetPasswordModal .aesthetic-subtitle {
            font-size: 15px;
            margin-bottom: 36px;
        }
        
        #resetPasswordModal .field-label {
            font-size: 13px;
            margin-bottom: 8px;
            color: #6B7280;
        }
        
        #resetPasswordModal .input-aesthetic {
            height: 48px;
            font-size: 14px;
            padding: 12px 16px;
        }
        
        #resetPasswordModal .btn-aesthetic {
            height: 48px;
            font-size: 15px;
            min-width: 160px;
        }
        
        /* Compact Block Modal Size */
        #blockModal .modal-dialog,
        #unblockModal .modal-dialog {
            max-width: 520px;
        }
        
        #blockModal .aesthetic-body,
        #unblockModal .aesthetic-body {
            padding: 40px 48px;
        }
        
        #blockModal .aesthetic-icon,
        #unblockModal .aesthetic-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
        }
        
        #blockModal .aesthetic-icon svg,
        #unblockModal .aesthetic-icon svg {
            width: 36px;
            height: 36px;
        }
        
        #blockModal .aesthetic-title,
        #unblockModal .aesthetic-title {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        #blockModal .aesthetic-subtitle,
        #unblockModal .aesthetic-subtitle {
            font-size: 15px;
            margin-bottom: 36px;
        }
        
        #blockModal .btn-aesthetic,
        #unblockModal .btn-aesthetic {
            height: 48px;
            font-size: 15px;
            min-width: 160px;
        }
        
        #blockModal .consequence-item,
        #unblockModal .consequence-item {
            font-size: 14px;
            padding: 14px 0;
        }

        /* Compact Delete Modal */
        #deleteModal .modal-dialog,
        #passTorchModal .modal-dialog {
            max-width: 480px;
        }

        #deleteModal .aesthetic-body,
        #passTorchModal .aesthetic-body {
            padding: 36px 40px;
        }

        #deleteModal .aesthetic-icon,
        #passTorchModal .aesthetic-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
        }

        #deleteModal .aesthetic-icon svg,
        #passTorchModal .aesthetic-icon svg {
            width: 28px;
            height: 28px;
        }

        #deleteModal .aesthetic-title,
        #passTorchModal .aesthetic-title {
            font-size: 22px;
            margin-bottom: 6px;
        }

        #deleteModal .aesthetic-subtitle,
        #passTorchModal .aesthetic-subtitle {
            font-size: 13px;
            margin-bottom: 20px;
        }

        #deleteModal .btn-aesthetic,
        #passTorchModal .btn-aesthetic {
            height: 44px;
            font-size: 14px;
            min-width: 130px;
        }

        #deleteModal .consequence-item,
        #passTorchModal .consequence-item {
            font-size: 13px;
            padding: 10px 0;
        }

        /* Delete icon */
        .delete-icon {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
            box-shadow: 0 8px 24px rgba(239, 68, 68, 0.25);
        }

        /* Compact proof upload */
        .delete-proof-compact {
            background: #F0F7FF;
            border: 1.5px dashed #93C5FD;
            border-radius: 10px;
            padding: 14px 16px;
            margin: 16px 0 12px;
            text-align: left;
        }

        .delete-proof-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #1D4ED8;
            margin-bottom: 10px;
        }

        .delete-proof-upload {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #BFDBFE;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 12px;
            color: #6B7280;
            transition: border-color .2s;
        }

        .delete-proof-upload:hover { border-color: #3B82F6; color: #374151; }
        .delete-proof-upload i { color: #3B82F6; font-size: 16px; }
        .proof-file-input { display: none; }

        .proof-preview-compact {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #F0FDF4;
            border: 1px solid #86EFAC;
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 8px;
            font-size: 12px;
            color: #166534;
        }

        .proof-preview-compact span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .proof-remove-compact {
            background: none;
            border: none;
            color: #9CA3AF;
            cursor: pointer;
            padding: 0;
            font-size: 12px;
            flex-shrink: 0;
        }
        .proof-remove-compact:hover { color: #EF4444; }

        .delete-reason-input {
            width: 100%;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            color: #374151;
            resize: none;
            outline: none;
            margin-bottom: 14px;
            font-family: inherit;
        }
        .delete-reason-input:focus { border-color: #6366F1; }

        .delete-check-compact {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
            text-align: left;
        }

        .delete-check-compact input { margin-top: 2px; flex-shrink: 0; accent-color: #EF4444; }
        
        /* Custom Badge Colors - Remove backgrounds, just colored text */
        .badge.bg-blue {
            background: transparent !important;
            color: #3B82F6 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-indigo {
            background: transparent !important;
            color: #6366F1 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-info {
            background: transparent !important;
            color: #3B82F6 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-success {
            background: transparent !important;
            color: #10B981 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-danger {
            background: transparent !important;
            color: #EF4444 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-secondary {
            background: transparent !important;
            color: #64748B !important;
            font-weight: 600;
            border: none !important;
        }
        
        /* Modern Minimalist Modal - Ultra Compact */
        .aesthetic-modal {
            border: none;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
            overflow: visible !important;
            position: relative;
        }
        
        /* Professional Modal Header */
        .modal-header-enhanced {
            padding: 32px 40px 24px;
            background: linear-gradient(135deg, #F8FAFC 0%, #FFFFFF 100%);
            border-bottom: 1px solid #E2E8F0;
        }
        
        .modal-header-enhanced .header-content {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .aesthetic-icon-wrapper-enhanced {
            flex-shrink: 0;
        }
        
        .aesthetic-icon-enhanced {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
        }
        
        .modal-header-enhanced .header-text {
            flex: 1;
        }
        
        .modal-header-enhanced .aesthetic-title {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 6px 0;
            letter-spacing: -0.02em;
        }
        
        .modal-header-enhanced .aesthetic-subtitle {
            font-size: 13px;
            color: #64748B;
            margin: 0;
            font-weight: 400;
            line-height: 1.5;
        }
            overflow: visible !important;
            position: relative;
        }
        
        .modal-content {
            overflow: visible !important;
            border: none;
            border-radius: 16px;
        }
        
        .modal-body {
            overflow: visible !important;
        }
        
        /* Ensure modal dialog doesn't clip dropdowns */
        .modal-dialog {
            overflow: visible !important;
        }
        
        .modal.fade .modal-dialog {
            overflow: visible !important;
        }
        
        .aesthetic-body {
            padding: 32px 40px;
            text-align: center;
        }
        
        .aesthetic-body-large {
            max-height: calc(90vh - 100px);
            overflow-y: auto;
            padding: 0;
            text-align: left;
            display: flex;
            flex-direction: column;
        }
        
        .enhanced-modal-body {
            padding: 0 !important;
        }
        
        .enhanced-form-container {
            padding: 32px 40px 40px;
        }
        
        .aesthetic-form-large {
            max-width: 100%;
            margin: 0;
            text-align: left;
            width: 100%;
        }
        
        /* Modern Close Button - Ultra Compact */
        .btn-close-aesthetic {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
            color: #6B7280;
        }
        
        .btn-close-aesthetic:hover {
            background: #FEE2E2;
            border-color: #FCA5A5;
            color: #DC2626;
            transform: rotate(90deg) scale(1.05);
        }
        
        .btn-close-aesthetic svg {
            width: 18px;
            height: 18px;
        }
        
        /* Modern Icon - Ultra Small */
        .aesthetic-icon-wrapper {
            margin-bottom: 12px;
        }
        
        .aesthetic-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.25);
        }
        
        .aesthetic-icon svg {
            width: 32px;
            height: 32px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }
        
        .add-icon {
            background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
            color: #ffffff;
        }
        
        .edit-icon {
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.25);
        }
        
        .aesthetic-icon svg {
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }
        
        .reset-icon {
            background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
            color: #F59E0B;
            box-shadow: 0 3px 10px rgba(245, 158, 11, 0.12);
        }
        
        /* Modern Typography - Ultra Compact */
        .aesthetic-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 8px 0;
            letter-spacing: -0.02em;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .aesthetic-subtitle {
            font-size: 14px;
            color: #6B7280;
            margin: 0 0 16px 0;
            font-weight: 400;
            line-height: 1.5;
        }
        
        /* Professional Progress Bar */
        .form-progress-wrapper {
            width: 100%;
            max-width: 500px;
            margin: 0 auto 32px;
        }
        
        .form-progress-bar {
            width: 100%;
            height: 6px;
            background: #F3F4F6;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .form-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #6366F1 0%, #4F46E5 100%);
            border-radius: 10px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
        }
        
        .form-progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .form-progress-text {
            text-align: center;
            margin-top: 8px;
        }
        
        .form-progress-text span {
            font-size: 12px;
            font-weight: 600;
            color: #6366F1;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        /* Modern Form - Ultra Compact */
        .aesthetic-form {
            text-align: left;
        }
        
        .aesthetic-form-large {
            max-width: 100%;
        }
        
        .form-section-aesthetic {
            margin-bottom: 14px;
        }
        
        .section-divider-aesthetic {
            border-bottom: 1.5px solid #F3F4F6;
            margin-bottom: 10px;
            padding-bottom: 4px;
        }
        
        .section-label-text {
            font-size: 10px;
            font-weight: 700;
            color: #4B5563;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        
        .form-field-aesthetic {
            margin-bottom: 0;
        }
        
        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
            letter-spacing: 0.01em;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .required-mark {
            color: #EF4444;
            font-weight: 700;
        }
        
        .optional-mark {
            color: #9CA3AF;
            font-weight: 400;
            font-size: 10px;
        }
        
        .input-wrapper-aesthetic {
            position: relative;
        }
        
        .input-aesthetic {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #E5E7EB;
            border-radius: 8px;
            font-size: 13px;
            color: #1F2937;
            background: #FFFFFF;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            font-weight: 500;
            height: 40px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            letter-spacing: 0.01em;
        }
        
        .input-aesthetic:hover {
            border-color: #6366F1;
            background: #FAFAFA;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.05);
        }
        
        .input-aesthetic:focus {
            border-color: #6366F1;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }
        
        .input-aesthetic::placeholder {
            color: #9CA3AF;
            font-weight: 400;
            font-size: 12px;
        }
        
        select.input-aesthetic {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-color: #FFFFFF;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239CA3AF'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            padding-right: 40px;
            cursor: pointer;
            position: relative;
            z-index: 9999 !important;
            pointer-events: auto !important;
            color: #1F2937 !important;
            font-size: 13px;
            font-weight: 500;
            height: 40px;
        }
        
        /* Ensure dropdown options are visible */
        select.input-aesthetic:focus {
            outline: none;
            border-color: #6366F1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
            color: #1F2937 !important;
            background-color: #ffffff;
        }
        
        select.input-aesthetic option {
            background: #FFFFFF !important;
            color: #1F2937 !important;
            padding: 12px 10px;
            cursor: pointer;
            pointer-events: auto !important;
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Hover state for options */
        select.input-aesthetic option:hover {
            background: #F3F4F6 !important;
            color: #1F2937 !important;
        }
        
        /* Selected option */
        select.input-aesthetic option:checked {
            background: #6366F1 !important;
            color: #ffffff !important;
        }
        
        .field-error {
            display: block;
            font-size: 10px;
            color: #EF4444;
            margin-top: 3px;
            font-weight: 500;
        }
        
        .field-hint {
            display: block;
            font-size: 10px;
            color: #9CA3AF;
            margin-top: 3px;
        }
        
        /* Password Toggle */
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle-aesthetic {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 3px;
            cursor: pointer;
            color: #9CA3AF;
            transition: all 0.2s ease;
            border-radius: 4px;
        }
        
        .password-toggle-aesthetic:hover {
            background: #F3F4F6;
            color: #6B7280;
        }
        
        .password-toggle-aesthetic i {
            font-size: 12px;
        }
        
        .password-wrapper .input-aesthetic {
            padding-right: 36px;
        }
        
        /* Modern Buttons - Ultra Compact */
        .aesthetic-buttons {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #F3F4F6;
            justify-content: flex-end;
        }
        
        .btn-aesthetic {
            min-width: 110px;
            height: 40px;
            padding: 0 24px;
            border-radius: 8px;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: -0.01em;
        }
        
        .btn-secondary-aesthetic {
            background: #F9FAFB;
            color: #6B7280;
            border: 2px solid #E5E7EB;
        }
        
        .btn-secondary-aesthetic:hover {
            background: #F3F4F6;
            border-color: #D1D5DB;
            color: #374151;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .btn-primary-aesthetic {
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-primary-aesthetic:hover {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
        }
        
        .btn-primary-aesthetic:active {
            transform: translateY(0);
        }
        
        .btn-danger-aesthetic {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-danger-aesthetic:hover {
            background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            transform: translateY(-2px);
        }
        
        /* Responsive Design - Ultra Compact */
        @media (max-width: 576px) {
            .aesthetic-body {
                padding: 16px 20px;
            }
            
            .aesthetic-body-large {
                padding: 20px 24px 24px;
            }
            
            .aesthetic-icon {
                width: 40px;
                height: 40px;
            }
            
            .aesthetic-icon svg {
                width: 20px;
                height: 20px;
            }
            
            .aesthetic-title {
                font-size: 16px;
            }
            
            .aesthetic-subtitle {
                font-size: 11px;
            }
            
            .aesthetic-buttons {
                flex-direction: column;
            }
            
            .btn-aesthetic {
                width: 100%;
            }
        }
        
        /* Keep light theme always */
        
        /* Enhanced Edit & Reset Password Modal Styles */
        .enhanced-modal {
            border: none;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            position: relative;
        }
        
        .enhanced-modal .modal-body {
            padding: 50px 40px 40px;
        }
        
        /* Custom Close Button */
        .btn-close-custom {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.05);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .btn-close-custom:hover {
            background: rgba(220, 53, 69, 0.1);
            transform: rotate(90deg);
        }
        
        .btn-close-custom i {
            color: #6c757d;
            font-size: 16px;
        }
        
        .btn-close-custom:hover i {
            color: #dc3545;
        }
        
        /* Animated Icon */
        .modal-icon-animated {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
        }
        
        .icon-circle-edit,
        .icon-circle-reset {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
            animation: iconBounce 0.6s ease-out;
        }
        
        .icon-circle-edit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        
        .icon-circle-reset {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            box-shadow: 0 10px 30px rgba(240, 147, 251, 0.4);
        }
        
        .icon-circle-inner {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }
        
        .icon-circle-edit i,
        .icon-circle-reset i {
            font-size: 48px;
            color: white;
            animation: iconShake 0.5s ease-in-out 0.6s;
        }
        
        .icon-pulse {
            position: absolute;
            top: 0;
            left: 0;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            opacity: 0.4;
            animation: pulse 2s ease-out infinite;
        }
        
        .icon-circle-edit + .icon-pulse {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .icon-circle-reset + .icon-pulse {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        /* Modal Title */
        .modal-title-enhanced {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
            animation: fadeInUp 0.5s ease-out 0.3s both;
        }
        
        .modal-subtitle-enhanced {
            font-size: 15px;
            color: #6c757d;
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease-out 0.4s both;
        }
        
        /* Info Box */
        .info-box {
            background: rgba(23, 162, 184, 0.1);
            border: 2px solid rgba(23, 162, 184, 0.3);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeInUp 0.5s ease-out 0.5s both;
        }
        
        .info-box i {
            font-size: 20px;
            color: #17a2b8;
            flex-shrink: 0;
        }
        
        .info-box span {
            font-size: 13px;
            color: #0c5460;
            line-height: 1.5;
            text-align: left;
        }
        
        /* Form Container */
        .form-container {
            text-align: left;
            animation: fadeInUp 0.5s ease-out 0.6s both;
        }
        
        .form-group-enhanced {
            position: relative;
            margin-bottom: 20px;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 18px;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .form-control-enhanced {
            width: 100%;
            padding: 16px 16px 16px 50px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control-enhanced:focus {
            border-color: #882b2a;
            box-shadow: 0 0 0 4px rgba(136, 43, 42, 0.1);
            outline: none;
        }
        
        .form-control-enhanced:focus + .floating-label,
        .form-control-enhanced:not(:placeholder-shown) + .floating-label {
            top: -10px;
            font-size: 12px;
            color: #882b2a;
            background: white;
            padding: 0 8px;
        }
        
        .floating-label {
            position: absolute;
            left: 50px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 15px;
            pointer-events: none;
            transition: all 0.3s ease;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px 10px;
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .password-toggle-btn:hover {
            color: #882b2a;
        }
        
        .password-match-indicator {
            background: rgba(40, 167, 69, 0.1);
            border: 2px solid rgba(40, 167, 69, 0.3);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .password-match-indicator i {
            color: #28a745;
            font-size: 18px;
        }
        
        .password-match-indicator span {
            color: #155724;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Action Buttons */
        .action-buttons-enhanced {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            animation: fadeInUp 0.5s ease-out 0.7s both;
        }
        
        .btn-cancel-enhanced,
        .btn-submit-enhanced {
            flex: 1;
            padding: 16px 24px;
            border-radius: 12px;
            border: none;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-cancel-enhanced {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            color: #495057;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn-cancel-enhanced:hover {
            background: linear-gradient(135deg, #dee2e6 0%, #ced4da 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }
        
        .btn-submit-enhanced {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-submit-enhanced:hover {
            background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .btn-warning-enhanced {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%) !important;
            box-shadow: 0 4px 12px rgba(240, 147, 251, 0.4) !important;
        }
        
        .btn-warning-enhanced:hover {
            background: linear-gradient(135deg, #e67ff0 0%, #e6415a 100%) !important;
            box-shadow: 0 6px 20px rgba(240, 147, 251, 0.5) !important;
        }
        
        .btn-cancel-enhanced i,
        .btn-submit-enhanced i {
            font-size: 18px;
        }
        
        /* Ripple Effect */
        .btn-cancel-enhanced::before,
        .btn-submit-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn-cancel-enhanced:active::before,
        .btn-submit-enhanced:active::before {
            width: 300px;
            height: 300px;
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            .enhanced-modal .modal-body {
                padding: 40px 25px 30px;
            }
            
            .modal-icon-animated {
                width: 100px;
                height: 100px;
                margin-bottom: 25px;
            }
            
            .icon-circle-edit,
            .icon-circle-reset {
                width: 100px;
                height: 100px;
            }
            
            .icon-circle-inner {
                width: 80px;
                height: 80px;
            }
            
            .icon-circle-edit i,
            .icon-circle-reset i {
                font-size: 38px;
            }
            
            .icon-pulse {
                width: 100px;
                height: 100px;
            }
            
            .modal-title-enhanced {
                font-size: 24px;
            }
            
            .modal-subtitle-enhanced {
                font-size: 14px;
            }
            
            .action-buttons-enhanced {
                flex-direction: column;
            }
            
            .btn-cancel-enhanced,
            .btn-submit-enhanced {
                width: 100%;
            }
        }
        
        /* ========================================
           ADD ADMIN MODAL - AESTHETIC DESIGN
           Matching Edit & Reset Password Modals
        ======================================== */
        
        /* Large Modal for Add Admin */
        .modal-aesthetic-large {
            max-width: 1200px;
        }
        
        .aesthetic-body-large {
            max-height: calc(300vh - 100px);
            overflow-y: auto;
            padding: 48px 40px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        /* Center the form content */
        .aesthetic-form-large {
            max-width: 1100px;
            margin: 32px auto 0;
            text-align: left;
        }
        
        /* Form Sections */
        .form-section-aesthetic {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .form-section-aesthetic:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-divider-aesthetic {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-divider-aesthetic::before,
        .section-divider-aesthetic::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
        }
        
        .section-label-text {
            padding: 0 16px;
            font-size: 16px;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        /* Form Fields */
        
        .form-field-aesthetic {
            margin-bottom: 0;
        }
        
        .required-mark {
            color: #ef4444;
            margin-left: 2px;
            font-weight: 700;
        }
        
        .optional-mark {
            color: #94a3b8;
            font-weight: 400;
            font-size: 11px;
            margin-left: 4px;
        }
        
        /* Error Messages */
        .field-error {
            display: block;
            color: #ef4444;
            font-size: 12px;
            margin-top: 6px;
            font-weight: 500;
            min-height: 18px;
        }
        
        /* Add Icon Styling */
        .add-icon {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #16a34a;
        }
        
        /* Smooth Scrollbar for Modal Body */
        .aesthetic-body-large::-webkit-scrollbar {
            width: 6px;
        }
        
        .aesthetic-body-large::-webkit-scrollbar-track {
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .aesthetic-body-large::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        .aesthetic-body-large::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Responsive Design for Large Modal */
        @media (max-width: 768px) {
            .modal-aesthetic-large {
                max-width: 100%;
                margin: 10px;
            }
            
            .aesthetic-body-large {
                padding: 36px 24px;
            }
            
            .form-section-aesthetic {
                margin-bottom: 24px;
            }
            
            .section-label-text {
                font-size: 12px;
            }
        }
        
        /* Professional Super Admin Modal Styles */
        .add-admin-modal .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .add-admin-header {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            color: white;
            padding: 28px 32px;
            border: none;
        }
        
        .add-admin-header .header-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .add-admin-header .icon-wrapper {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
        .add-admin-header .header-text h5 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.3px;
        }
        
        .add-admin-header .modal-subtitle {
            margin: 4px 0 0 0;
            font-size: 13px;
            opacity: 0.85;
            font-weight: 400;
        }
        
        .add-admin-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .add-admin-body {
            padding: 32px;
            background: #F9FAFB;
        }
        
        /* Progress Indicator */
        .form-progress {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding: 20px 24px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #E5E7EB;
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            flex: 1;
            position: relative;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #F3F4F6;
            color: #9CA3AF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 2px solid #E5E7EB;
        }
        
        .progress-step.active .step-circle {
            background: linear-gradient(135deg, #4F46E5, #6366F1);
            color: white;
            border-color: #4F46E5;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        
        .progress-step.completed .step-circle {
            background: #10B981;
            color: white;
            border-color: #10B981;
        }
        
        .step-label {
            font-size: 11px;
            font-weight: 500;
            color: #6B7280;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .progress-step.active .step-label {
            color: #4F46E5;
            font-weight: 600;
        }
        
        .progress-line {
            height: 2px;
            background: #E5E7EB;
            flex: 1;
            margin: 0 10px;
            margin-bottom: 30px;
            border-radius: 2px;
        }
        
        /* Form Steps */
        .form-step {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .form-step.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: white;
            border-radius: 10px;
            border-left: 3px solid #4F46E5;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .step-header i {
            font-size: 20px;
            color: #4F46E5;
        }
        
        .step-header h6 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        /* Modern Form Inputs */
        .modern-input {
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .modern-input:focus {
            border-color: #882b2a;
            box-shadow: 0 0 0 4px rgba(136, 43, 42, 0.1);
            background: white;
        }
        
        .form-floating > .modern-input {
            padding-top: 1.625rem;
            padding-bottom: 0.625rem;
        }
        
        .form-floating > label {
            padding: 1rem 1rem;
            color: #6c757d;
            font-size: 14px;
        }
        
        .form-floating > label i {
            opacity: 0.7;
        }
        
        .input-hint {
            font-size: 11px;
            color: #6c757d;
            margin-top: 5px;
            padding-left: 5px;
        }
        
        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px 10px;
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: #882b2a;
        }
        
        /* Password Strength Indicator */
        .password-strength {
            padding: 0 5px;
        }
        
        .strength-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 10px;
        }
        
        .strength-text {
            font-size: 12px;
            font-weight: 500;
            text-align: center;
        }
        
        .strength-weak .strength-fill {
            width: 33%;
            background: #dc3545;
        }
        
        .strength-weak .strength-text {
            color: #dc3545;
        }
        
        .strength-medium .strength-fill {
            width: 66%;
            background: #ffc107;
        }
        
        .strength-medium .strength-text {
            color: #ffc107;
        }
        
        .strength-strong .strength-fill {
            width: 100%;
            background: #28a745;
        }
        
        .strength-strong .strength-text {
            color: #28a745;
        }
        
        /* Password Strength Message - Simple Text Display */
        .password-strength-message {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-top: 6px;
            margin-bottom: 4px;
        }
        
        .password-strength-message.weak {
            color: #dc3545;
        }
        
        .password-strength-message.medium {
            color: #ffc107;
        }
        
        .password-strength-message.strong {
            color: #28a745;
        }
        
        .password-strength-message.match {
            color: #28a745;
        }
        
        .password-strength-message.no-match {
            color: #dc3545;
        }
        
        /* Role Selector */
        .role-selector {
            display: flex;
            gap: 15px;
            flex-direction: column;
        }
        
        .role-option {
            cursor: pointer;
            margin: 0;
        }
        
        .role-option input[type="radio"] {
            display: none;
        }
        
        .role-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px;
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .role-option input[type="radio"]:checked + .role-card {
            border-color: #882b2a;
            background: rgba(136, 43, 42, 0.05);
            box-shadow: 0 4px 15px rgba(136, 43, 42, 0.1);
        }
        
        .role-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }
        
        .role-icon.super {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }
        
        .role-info h6 {
            margin: 0 0 5px 0;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .role-info p {
            margin: 0;
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Status Selector */
        .status-selector {
            display: flex;
            gap: 15px;
            flex-direction: column;
        }
        
        .status-option {
            cursor: pointer;
            margin: 0;
        }
        
        .status-option input[type="radio"] {
            display: none;
        }
        
        .status-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px;
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .status-option input[type="radio"]:checked + .status-card {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.1);
        }
        
        .status-option input[type="radio"]:checked + .status-card.inactive-status {
            border-color: #6c757d;
            background: rgba(108, 117, 125, 0.05);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.1);
        }
        
        .status-icon {
            width: 50px;
            height: 50px;
            background: #28a745;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }
        
        .inactive-status .status-icon {
            background: #6c757d;
        }
        
        .status-info h6 {
            margin: 0 0 5px 0;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .status-info p {
            margin: 0;
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Summary Card */
        .summary-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            border: 2px solid #e1e5e9;
        }
        
        .summary-header {
            background: linear-gradient(135deg, #882b2a, #a02c2b);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .summary-content {
            padding: 20px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-size: 13px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .summary-value {
            font-size: 13px;
            color: #2c3e50;
            font-weight: 600;
        }
        
        /* Form Labels */
        .form-label-modern {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 12px;
            display: block;
        }
        
        /* Modal Footer */
        .add-admin-footer {
            padding: 20px 35px;
            background: white;
            border-top: 1px solid #e9ecef;
            gap: 10px;
        }
        
        .add-admin-footer .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .add-admin-footer .btn-primary {
            background: linear-gradient(135deg, #882b2a, #a02c2b);
            border: none;
        }
        
        .add-admin-footer .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(136, 43, 42, 0.3);
        }
        
        .add-admin-footer .btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
        }
        
        .add-admin-footer .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .add-admin-header {
                padding: 20px;
            }
            
            .add-admin-header .icon-wrapper {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }
            
            .add-admin-body {
                padding: 20px;
            }
            
            .form-progress {
                padding: 15px 10px;
            }
            
            .step-circle {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
            
            .step-label {
                font-size: 10px;
            }
            
            .progress-line {
                margin: 0 5px;
                margin-bottom: 25px;
            }
            
            .role-selector,
            .status-selector {
                gap: 10px;
            }
        }

        /* ========================================
           ENHANCED ROLE SELECTION CARDS
           Premium Design with Advanced Animations
        ======================================== */
        
        .role-section-title {
            display: block;
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
        }
        
        .role-section-subtitle {
            display: block;
            font-size: 13px;
            color: #6B7280;
            font-weight: 400;
        }
        
        .role-selection-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 16px;
        }
        
        .role-card {
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .role-card-label {
            display: flex;
            flex-direction: column;
            cursor: pointer;
            border: 2px solid #E5E7EB;
            border-radius: 12px;
            padding: 0;
            background: #FFFFFF;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .role-card-label:hover {
            border-color: #CBD5E1;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        
        .role-card input[type="radio"]:checked + .role-card-label {
            border-color: #6366F1;
            background: #FAFAFF;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.15);
            transform: translateY(-2px);
        }
        
        /* Badge at top */
        .role-card-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid #F3F4F6;
            transition: all 0.3s ease;
        }
        
        .customer-badge {
            background: #FDF8F3;
            color: #78350F;
        }
        
        .admin-badge {
            background: #EFF6FF;
            color: #1E40AF;
        }
        
        .superadmin-badge {
            background: #EEF2FF;
            color: #3730A3;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .customer-badge {
            background: #92400E;
            color: white;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .admin-badge {
            background: #2563EB;
            color: white;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .superadmin-badge {
            background: #4F46E5;
            color: white;
        }
        
        .role-card-badge svg {
            width: 10px;
            height: 10px;
        }
        
        .role-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 18px 14px 18px;
        }
        
        .role-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .customer-icon {
            background: #FEF3C7;
            color: #78350F;
        }
        
        .admin-icon {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .superadmin-icon {
            background: #E0E7FF;
            color: #3730A3;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .customer-icon {
            background: #92400E;
            color: white;
            box-shadow: 0 4px 12px rgba(146, 64, 14, 0.3);
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .admin-icon {
            background: #2563EB;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .superadmin-icon {
            background: #4F46E5;
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        
        .role-header-text {
            flex: 1;
        }
        
        .role-title {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 4px 0;
            letter-spacing: -0.01em;
        }
        
        .role-level {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            color: #6B7280;
            background: #F3F4F6;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .role-level {
            background: #6366F1;
            color: white;
        }
        
        .role-card-body {
            padding: 0 18px 18px 18px;
            flex: 1;
        }
        
        .role-description {
            font-size: 12px;
            color: #6B7280;
            margin: 0 0 14px 0;
            line-height: 1.5;
        }
        
        .privileges-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .privileges-header svg {
            stroke: #9CA3AF;
            width: 14px;
            height: 14px;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .privileges-header {
            color: #6366F1;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .privileges-header svg {
            stroke: #6366F1;
        }
        
        .role-privileges {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .role-privileges li {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12px;
            color: #4B5563;
            margin-bottom: 8px;
            line-height: 1.4;
            transition: all 0.2s ease;
        }
        
        .role-privileges li:last-child {
            margin-bottom: 0;
        }
        
        .role-privileges li svg {
            flex-shrink: 0;
            margin-top: 2px;
            stroke: #10B981;
            width: 14px;
            height: 14px;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .role-privileges li {
            color: #1F2937;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .role-privileges li svg {
            stroke: #6366F1;
        }
        
        .role-privileges li span {
            flex: 1;
        }
        
        .role-card-footer {
            padding: 10px 18px;
            border-top: 1px solid #F3F4F6;
            background: #FAFAFA;
            transition: all 0.3s ease;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .role-card-footer {
            background: #F0F0FF;
            border-top-color: #E0E7FF;
        }
        
        .recommended-tag {
            display: inline-flex;
            align-items: center;
            font-size: 10px;
            font-weight: 600;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .recommended-tag::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #10B981;
            margin-right: 6px;
        }
        
        .recommended-tag.warning {
            color: #D97706;
        }
        
        .recommended-tag.warning::before {
            background: #F59E0B;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .recommended-tag {
            color: #6366F1;
        }
        
        .role-card input[type="radio"]:checked + .role-card-label .recommended-tag::before {
            background: #6366F1;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .role-selection-container {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .role-card-label {
                flex-direction: row;
                align-items: stretch;
            }
            
            .role-card-badge {
                writing-mode: vertical-rl;
                text-orientation: mixed;
                padding: 12px 8px;
                border-bottom: none;
                border-right: 1px solid #F3F4F6;
            }
            
            .role-card-header {
                padding: 16px 16px 12px 16px;
            }
            
            .role-card-body {
                padding: 0 16px 16px 16px;
            }
            
            .role-card-footer {
                padding: 12px 16px;
                border-top: none;
                border-left: 1px solid #F3F4F6;
                display: flex;
                align-items: center;
                writing-mode: vertical-rl;
                text-orientation: mixed;
            }
        }
        
        @media (max-width: 768px) {
            .role-card-label {
                flex-direction: column;
            }
            
            .role-card-badge {
                writing-mode: horizontal-tb;
                border-right: none;
                border-bottom: 1px solid #F3F4F6;
            }
            
            .role-card-footer {
                writing-mode: horizontal-tb;
                border-left: none;
                border-top: 1px solid #F3F4F6;
            }
            
            .role-icon {
                width: 44px;
                height: 44px;
            }
            
            .role-icon svg {
                width: 20px;
                height: 20px;
            }
        }
        
        /* ========================================
           MODERN ROLE CARDS STYLING
        ======================================== */
        
        .role-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        .role-card-modern {
            position: relative;
        }
        
        .role-card-modern input[type="radio"] {
            display: none;
        }
        
        .role-card-label-modern {
            display: block;
            cursor: pointer;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            background: white;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .role-card-label-modern:hover {
            border-color: #cbd5e1;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .role-card-modern input[type="radio"]:checked + .role-card-label-modern {
            border-color: #882b2a;
            background: rgba(136, 43, 42, 0.02);
            box-shadow: 0 4px 15px rgba(136, 43, 42, 0.15);
        }
        
        .role-card-header-modern {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .role-icon-modern {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            transition: all 0.3s ease;
        }
        
        /* Customer - Brown */
        .role-icon-modern.customer {
            background: #FEF3C7;
            color: #78350F;
        }
        
        .role-card-modern input[type="radio"]:checked + .role-card-label-modern .role-icon-modern.customer {
            background: #92400E;
            color: white;
            box-shadow: 0 4px 12px rgba(146, 64, 14, 0.3);
        }
        
        /* Admin - Blue */
        .role-icon-modern.admin {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .role-card-modern input[type="radio"]:checked + .role-card-label-modern .role-icon-modern.admin {
            background: #2563EB;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        /* Super Admin - Indigo */
        .role-icon-modern.superadmin {
            background: #E0E7FF;
            color: #3730A3;
        }
        
        .role-card-modern input[type="radio"]:checked + .role-card-label-modern .role-icon-modern.superadmin {
            background: #4F46E5;
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        
        .role-info {
            flex: 1;
        }
        
        .role-name {
            font-size: 16px;
            font-weight: 700;
            color: #2c3e50;
            margin: 0 0 5px 0;
        }
        
        .role-desc {
            font-size: 13px;
            color: #6c757d;
            margin: 0 0 15px 0;
            line-height: 1.5;
        }
        
        .role-perms {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .role-perms li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .role-perms li:last-child {
            margin-bottom: 0;
        }
        
        .role-perms li i {
            color: #28a745;
            font-size: 10px;
        }
        
        @media (max-width: 992px) {
            .role-cards-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
    </style>
    
    <script>
        // Enhanced Delete Modal Functionality with Proof Upload
        function deleteAdmin(adminId, adminName) {
            // Reset form
            document.getElementById('deleteAdminForm').reset();
            document.getElementById('confirmDeleteCheckbox').checked = false;
            document.getElementById('confirmDeleteBtn').disabled = true;
            
            // Reset proof upload
            removeProofImage();
            
            // Set values
            document.getElementById('delete_admin_id').value = adminId;
            document.getElementById('delete_admin_name').textContent = adminName;
            
            // Show modal with animation
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
        
        // Handle proof image upload and preview
        document.addEventListener('DOMContentLoaded', function() {
            const proofInput = document.getElementById('deletion_proof_image');
            const checkbox = document.getElementById('confirmDeleteCheckbox');
            const deleteBtn = document.getElementById('confirmDeleteBtn');
            const deleteForm = document.getElementById('deleteAdminForm');
            
            if (proofInput) {
                proofInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // Validate file size (5MB max)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File size must be less than 5MB');
                            proofInput.value = '';
                            return;
                        }
                        
                        // Validate file type
                        const validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf'];
                        if (!validTypes.includes(file.type)) {
                            alert('Only PNG, JPG, JPEG, and PDF files are allowed');
                            proofInput.value = '';
                            return;
                        }
                        
                        // Show preview
                        showProofPreview(file);
                    }
                });
            }
            
            // Enable/disable delete button based on checkbox and file upload
            if (checkbox && deleteBtn) {
                checkbox.addEventListener('change', function() {
                    const hasFile = proofInput && proofInput.files.length > 0;
                    deleteBtn.disabled = !(this.checked && hasFile);
                });
            }
            
            // Also check when file is selected
            if (proofInput) {
                proofInput.addEventListener('change', function() {
                    const hasFile = this.files.length > 0;
                    const isChecked = checkbox && checkbox.checked;
                    if (deleteBtn) {
                        deleteBtn.disabled = !(hasFile && isChecked);
                    }
                });
            }
            
            // Add loading state on form submit
            if (deleteForm && deleteBtn) {
                deleteForm.addEventListener('submit', function(e) {
                    // Validate proof image is attached
                    if (!proofInput || proofInput.files.length === 0) {
                        e.preventDefault();
                        alert('Please attach a proof image before proceeding with deletion');
                        return false;
                    }
                    
                    deleteBtn.classList.add('loading');
                    deleteBtn.disabled = true;
                });
            }
        });
        
        // Show proof image preview
        function handleProofUpload(input) {
            const file = input.files[0];
            if (!file) return;
            const preview = document.getElementById('proof_preview_container');
            const label  = document.getElementById('proof_file_label');
            const name   = document.getElementById('proof_file_name');
            if (name)   name.textContent = file.name;
            if (label)  label.textContent = file.name;
            if (preview) preview.style.display = 'flex';
        }

        function showProofPreview(file) {
            handleProofUpload({ files: [file] });
        }
        
        // Remove proof image
        function removeProofImage() {
            const proofInput = document.getElementById('deletion_proof_image');
            const preview    = document.getElementById('proof_preview_container');
            const label      = document.getElementById('proof_file_label');
            const deleteBtn  = document.getElementById('confirmDeleteBtn');
            const checkbox   = document.getElementById('confirmDeleteCheckbox');

            if (proofInput) proofInput.value = '';
            if (preview)    preview.style.display = 'none';
            if (label)      label.textContent = 'Click to upload proof (PNG, JPG, PDF Â· Max 5MB)';
            if (checkbox)   checkbox.checked = false;
            if (deleteBtn)  deleteBtn.disabled = true;
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Close all dropdowns when collapsing sidebar
            if (sidebar.classList.contains('collapsed')) {
                document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('active');
                });
            }
        }
        
        function toggleDropdown(element) {
            const sidebar = document.getElementById('sidebar');
            const dropdownContent = element.nextElementSibling;
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            // Close other dropdowns
            document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                if (dropdown !== dropdownContent) {
                    dropdown.classList.remove('show');
                }
            });
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                if (toggle !== element) {
                    toggle.classList.remove('active');
                }
            });
            
            // Toggle current dropdown
            dropdownContent.classList.toggle('show');
            element.classList.toggle('active');
            
            // For collapsed sidebar, close dropdown when clicking outside
            if (isCollapsed && dropdownContent.classList.contains('show')) {
                setTimeout(() => {
                    const closeDropdown = (e) => {
                        if (!element.contains(e.target) && !dropdownContent.contains(e.target)) {
                            dropdownContent.classList.remove('show');
                            element.classList.remove('active');
                            document.removeEventListener('click', closeDropdown);
                        }
                    };
                    document.addEventListener('click', closeDropdown);
                }, 100);
            }
        }
        
        // Auto-open dropdown if current page is in it
        document.addEventListener('DOMContentLoaded', function() {
            const activeDropdownItem = document.querySelector('.dropdown-item.active');
            if (activeDropdownItem) {
                const dropdownContent = activeDropdownItem.closest('.dropdown-content');
                const dropdownToggle = dropdownContent.previousElementSibling;
                dropdownContent.classList.add('show');
                dropdownToggle.classList.add('active');
            }
        });
        
        // Multi-step form functionality
        let currentStep = 1;
        const totalSteps = 3;
        
        function nextStep() {
            if (validateStep(currentStep)) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    updateStep();
                }
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateStep();
            }
        }
        
        function updateStep() {
            // Update form steps
            document.querySelectorAll('.form-step').forEach((step, index) => {
                step.classList.toggle('active', index + 1 === currentStep);
            });
            
            // Update progress indicators
            document.querySelectorAll('.progress-step').forEach((step, index) => {
                const stepNum = index + 1;
                step.classList.toggle('active', stepNum === currentStep);
                step.classList.toggle('completed', stepNum < currentStep);
            });
            
            // Update buttons
            document.querySelector('.btn-prev').style.display = currentStep === 1 ? 'none' : 'inline-block';
            document.querySelector('.btn-next').style.display = currentStep === totalSteps ? 'none' : 'inline-block';
            document.querySelector('.btn-submit').style.display = currentStep === totalSteps ? 'inline-block' : 'none';
            
            // Update summary on last step
            if (currentStep === 3) {
                updateSummary();
            }
        }
        
        function validateStep(step) {
            const currentStepElement = document.querySelector(`.form-step[data-step="${step}"]`);
            const inputs = currentStepElement.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                    
                    // Additional validation for specific fields
                    if (input.type === 'email' && !isValidEmail(input.value)) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    }
                    
                    if (input.id === 'add_confirm_password') {
                        const password = document.getElementById('add_password').value;
                        if (input.value !== password) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        }
                    }
                }
            });
            
            return isValid;
        }
        
        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
        
        function updateSummary() {
            document.getElementById('summary_username').textContent = document.getElementById('add_username').value || '-';
            document.getElementById('summary_email').textContent = document.getElementById('add_email').value || '-';
            document.getElementById('summary_fullname').textContent = document.getElementById('add_full_name').value || '-';
            
            const role = document.querySelector('input[name="role"]:checked').value;
            document.getElementById('summary_role').textContent = role === 'super_admin' ? 'Super Admin' : 'Admin';
            
            const status = document.querySelector('input[name="status"]:checked').value;
            document.getElementById('summary_status').textContent = status.charAt(0).toUpperCase() + status.slice(1);
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            return strength;
        }
        
        // Password toggle function
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Initialize Add Admin Modal
        document.addEventListener('DOMContentLoaded', function() {
            // Reset form when modal opens
            const addAdminModal = document.getElementById('addAdminModal');
            if (addAdminModal) {
                addAdminModal.addEventListener('show.bs.modal', function() {
                    currentStep = 1;
                    updateStep();
                    document.getElementById('addAdminForm').reset();
                    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
                });
            }
            
            // Password strength indicator
            const passwordInput = document.getElementById('add_password');
            if (passwordInput) {
                passwordInput.addEventListener('input', function() {
                    const strength = checkPasswordStrength(this.value);
                    const strengthText = document.getElementById('add_password_strength_text');
                    
                    // Remove all classes
                    strengthText.classList.remove('weak', 'medium', 'strong');
                    
                    if (this.value.length === 0) {
                        strengthText.textContent = '';
                    } else if (strength <= 2) {
                        strengthText.classList.add('weak');
                        strengthText.textContent = 'Password is Weak';
                    } else if (strength <= 3) {
                        strengthText.classList.add('medium');
                        strengthText.textContent = 'Password is Medium';
                    } else {
                        strengthText.classList.add('strong');
                        strengthText.textContent = 'Password is Strong';
                    }
                    
                    // Also check confirm password match when password changes
                    checkPasswordMatch();
                });
            }
            
            // Confirm password validation with match indicator
            const confirmPassword = document.getElementById('add_confirm_password');
            if (confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    checkPasswordMatch();
                });
            }
            
            // Function to check password match
            function checkPasswordMatch() {
                const password = document.getElementById('add_password').value;
                const confirmPassword = document.getElementById('add_confirm_password').value;
                const matchText = document.getElementById('add_confirm_password_match');
                
                if (confirmPassword.length === 0) {
                    matchText.textContent = '';
                    matchText.classList.remove('match', 'no-match');
                } else if (password === confirmPassword) {
                    matchText.textContent = 'Passwords match';
                    matchText.classList.remove('no-match');
                    matchText.classList.add('match');
                } else {
                    matchText.textContent = 'Passwords do not match';
                    matchText.classList.remove('match');
                    matchText.classList.add('no-match');
                }
            }
            
            // Real-time validation for inputs
            document.querySelectorAll('.modern-input').forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.hasAttribute('required') && !this.value.trim()) {
                        this.classList.add('is-invalid');
                    } else if (this.type === 'email' && this.value && !isValidEmail(this.value)) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.classList.contains('is-invalid') && this.value.trim()) {
                        this.classList.remove('is-invalid');
                    }
                });
            });
            
            // Form submission
            const addAdminForm = document.getElementById('addAdminForm');
            if (addAdminForm) {
                addAdminForm.addEventListener('submit', function(e) {
                    // Check AJAX validation flags first
                    if (typeof adminIdNumberExists !== 'undefined' && adminIdNumberExists) {
                        e.preventDefault();
                        alert('This ID number is already taken! Please use a different ID number.');
                        return false;
                    }
                    if (typeof adminEmailExists !== 'undefined' && adminEmailExists) {
                        e.preventDefault();
                        alert('This email is already taken! Please use a different email address.');
                        return false;
                    }
                    if (typeof adminUsernameExists !== 'undefined' && adminUsernameExists) {
                        e.preventDefault();
                        alert('This username is already taken! Please use a different username.');
                        return false;
                    }
                    
                    // Validate all required fields
                    const requiredInputs = this.querySelectorAll('input[required], select[required]');
                    let hasErrors = false;
                    
                    requiredInputs.forEach(input => {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            hasErrors = true;
                        } else {
                            input.classList.remove('is-invalid');
                        }
                    });
                    
                    // Check password match
                    const password = document.getElementById('add_password');
                    const confirmPassword = document.getElementById('add_confirm_password');
                    if (password && confirmPassword && password.value !== confirmPassword.value) {
                        confirmPassword.classList.add('is-invalid');
                        hasErrors = true;
                        alert('Passwords do not match!');
                        e.preventDefault();
                        return false;
                    }
                    
                    // Check age requirement
                    const age = document.getElementById('add_age');
                    if (age && parseInt(age.value) < 18) {
                        age.classList.add('is-invalid');
                        hasErrors = true;
                        alert('Age must be 18 or above!');
                        e.preventDefault();
                        return false;
                    }
                    
                    if (hasErrors) {
                        e.preventDefault();
                        alert('Please fill in all required fields correctly.');
                        return false;
                    }
                });
            }
        });
        
        function editAdmin(adminId) {
            // Fetch admin data
            fetch(`admin_get_data.php?id=${adminId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_admin_id').value = data.id;
                    document.getElementById('edit_username').value = data.username;
                    document.getElementById('edit_email').value = data.email;
                    document.getElementById('edit_full_name').value = data.full_name;
                    document.getElementById('edit_status').value = data.status;
                    
                    new bootstrap.Modal(document.getElementById('editAdminModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching admin data');
                });
        }
        
        function resetPassword(adminId) {
            document.getElementById('reset_admin_id').value = adminId;
            document.getElementById('new_password_reset').value = '';
            document.getElementById('confirm_password_reset').value = '';
            
            // Hide password match indicator
            const matchIndicator = document.getElementById('password_match_indicator');
            if (matchIndicator) {
                matchIndicator.style.display = 'none';
            }
            
            // Remove any validation classes
            document.getElementById('new_password_reset').classList.remove('is-invalid', 'is-valid');
            document.getElementById('confirm_password_reset').classList.remove('is-invalid', 'is-valid');
            
            new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
        }
        
        // Password toggle for Reset Password modal
        function togglePasswordReset(inputId) {
            const input = document.getElementById(inputId);
            const button = input.parentElement.querySelector('.password-toggle-btn');
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function deleteAdmin(adminId, adminName) {
            document.getElementById('delete_admin_id').value = adminId;
            document.getElementById('delete_admin_name').textContent = adminName;
            
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Block Admin Function
        function blockAdmin(adminId, adminName) {
            document.getElementById('block_admin_id').value = adminId;
            document.getElementById('block_admin_name').textContent = adminName;
            new bootstrap.Modal(document.getElementById('blockModal')).show();
        }
        
        // Unblock Admin Function
        function unblockAdmin(adminId, adminName) {
            document.getElementById('unblock_admin_id').value = adminId;
            document.getElementById('unblock_admin_name').textContent = adminName;
            new bootstrap.Modal(document.getElementById('unblockModal')).show();
        }

        // Transfer the Crown
        function passTorch(adminId, adminUsername, adminFullName) {
            document.getElementById('torch_admin_id').value = adminId;
            document.getElementById('torch_admin_name').textContent = adminFullName;
            document.getElementById('torch_admin_username').textContent = adminUsername;
            new bootstrap.Modal(document.getElementById('passTorchModal')).show();
        }

        function submitPassTorch() {
            const adminId = document.getElementById('torch_admin_id').value;
            const btn = document.getElementById('confirmTorchBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            const formData = new FormData();
            formData.append('new_superadmin_id', adminId);

            fetch('../php/ajax/pass_the_torch.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Torch passed successfully to ' + data.new_superadmin + '!\nYou will now be logged out.');
                        window.location.href = 'Sign_in.php';
                    } else {
                        alert('Error: ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-crown"></i> Transfer the Crown';
                    }
                })
                .catch(() => {
                    alert('An error occurred. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-crown"></i> Transfer the Crown';
                });
        }

        // Password match validation for Reset Password modal
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordReset = document.getElementById('new_password_reset');
            const confirmPasswordReset = document.getElementById('confirm_password_reset');
            const matchIndicator = document.getElementById('password_match_indicator');
            const resetForm = document.getElementById('resetPasswordForm');
            
            if (newPasswordReset && confirmPasswordReset && matchIndicator) {
                function checkPasswordMatch() {
                    const newPass = newPasswordReset.value;
                    const confirmPass = confirmPasswordReset.value;
                    
                    if (confirmPass.length > 0) {
                        if (newPass === confirmPass && newPass.length >= 8) {
                            matchIndicator.style.display = 'flex';
                            confirmPasswordReset.classList.remove('is-invalid');
                            confirmPasswordReset.classList.add('is-valid');
                        } else {
                            matchIndicator.style.display = 'none';
                            if (confirmPass.length > 0) {
                                confirmPasswordReset.classList.add('is-invalid');
                                confirmPasswordReset.classList.remove('is-valid');
                            }
                        }
                    } else {
                        matchIndicator.style.display = 'none';
                        confirmPasswordReset.classList.remove('is-invalid', 'is-valid');
                    }
                }
                
                newPasswordReset.addEventListener('input', checkPasswordMatch);
                confirmPasswordReset.addEventListener('input', checkPasswordMatch);
                
                // Validate on form submit
                if (resetForm) {
                    resetForm.addEventListener('submit', function(e) {
                        const newPass = newPasswordReset.value;
                        const confirmPass = confirmPasswordReset.value;
                        
                        if (newPass !== confirmPass) {
                            e.preventDefault();
                            alert('Passwords do not match!');
                            return false;
                        }
                        
                        if (newPass.length < 8) {
                            e.preventDefault();
                            alert('Password must be at least 8 characters long!');
                            return false;
                        }
                    });
                }
            }
        });
    </script>
    
    <!-- jQuery for AJAX validation -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Custom validation for Add Admin Modal -->
    <script>
    $(document).ready(function() {
        // ID Number validation
        $('#add_idnumber').on('keyup blur', function() {
            var idnumber = $(this).val();
            
            if (idnumber.length > 0) {
                $.ajax({
                    url: './check_id_number.php',
                    type: 'GET',
                    data: { idnumber: idnumber },
                    success: function(response) {
                        try {
                            var data = JSON.parse(response);
                            if (data.exists) {
                                $('#add_idnumber_error').text('This ID number is already taken!').css('color', '#dc3545');
                            } else {
                                $('#add_idnumber_error').text('');
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            } else {
                $('#add_idnumber_error').text('');
            }
        });
        
        // Email validation
        $('#add_email').on('keyup blur', function() {
            var email = $(this).val();
            console.log('Email field triggered, value:', email);
            
            if (email.length > 0) {
                console.log('Making AJAX request for email:', email);
                $.ajax({
                    url: './check_email.php',
                    type: 'GET',
                    data: { email: email },
                    success: function(response) {
                        console.log('Email check response:', response);
                        try {
                            var data = JSON.parse(response);
                            console.log('Parsed data:', data);
                            if (data.exists) {
                                console.log('Email exists! Showing error');
                                $('#add_email_error').text('This email is already taken!').css('color', '#dc3545');
                            } else {
                                console.log('Email available');
                                $('#add_email_error').text('');
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                            console.error("Response was:", response);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                        console.error("Status:", status);
                        console.error("Response text:", xhr.responseText);
                    }
                });
            } else {
                $('#add_email_error').text('');
            }
        });
        
        // Username validation
        $('#add_username').on('keyup blur', function() {
            var username = $(this).val();
            
            if (username.length > 0) {
                $.ajax({
                    url: './check_username.php',
                    type: 'GET',
                    data: { username: username },
                    success: function(response) {
                        try {
                            var data = JSON.parse(response);
                            if (data.exists) {
                                $('#add_username_error').text('This username is already taken!').css('color', '#dc3545');
                            } else {
                                $('#add_username_error').text('');
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            } else {
                $('#add_username_error').text('');
            }
        });
        
        // Prevent form submission if there are validation errors
        $('#addAdminForm').on('submit', function(e) {
            var hasErrors = false;
            
            if ($('#add_idnumber_error').text() !== '') {
                hasErrors = true;
            }
            if ($('#add_email_error').text() !== '') {
                hasErrors = true;
            }
            if ($('#add_username_error').text() !== '') {
                hasErrors = true;
            }
            
            if (hasErrors) {
                e.preventDefault();
                alert('Please fix the validation errors before submitting.');
                return false;
            }
        });
    });
    </script>
    
    <!-- Admin Add Form Validation -->
    <script src="../js/admin_add_validation.js"></script>
    <?php if ($current_role === 'super_admin'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const actions = { edit: 'save these administrator changes', reset_password: 'reset this administrator password', block: 'block this administrator', delete: 'permanently delete this administrator' };
            document.querySelectorAll('#editForm, #resetPasswordForm, #blockAdminForm, #deleteAdminForm').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (event.defaultPrevented) return;
                    const action = form.querySelector('input[name="action"]')?.value;
                    if (!window.confirm('Are you sure you want to ' + (actions[action] || 'continue with this action') + '?')) event.preventDefault();
                });
            });
        });
    </script>
    <?php endif; ?>
<script src="../js/temporary_password_toggle.js"></script>
</body>
</html>

    <script>
    // Track form completion progress for Admin modal
    function updateAdminProgress() {
        const form = document.getElementById('addAdminForm');
        if (!form) return;
        
        const requiredFields = form.querySelectorAll('[required]');
        let filledFields = 0;
        
        requiredFields.forEach(field => {
            if (field.type === 'checkbox' || field.type === 'radio') {
                if (field.checked) filledFields++;
            } else if (field.value.trim() !== '') {
                filledFields++;
            }
        });
        
        const progress = Math.round((filledFields / requiredFields.length) * 100);
        const progressBar = document.getElementById('adminProgressBar');
        const progressText = document.getElementById('adminProgressText');
        
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
        
        if (progressText) {
            progressText.textContent = progress + '% Complete';
            
            // Change color based on progress
            if (progress === 100) {
                progressText.style.color = '#6366F1';
            } else if (progress >= 50) {
                progressText.style.color = '#F59E0B';
            } else {
                progressText.style.color = '#6B7280';
            }
        }
    }
    
    // Add event listeners to all form inputs in Admin modal
    (function() {
        const form = document.getElementById('addAdminForm');
        if (form) {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', updateAdminProgress);
                input.addEventListener('change', updateAdminProgress);
            });
        }
        
        // Reset progress when modal opens
        const addAdminModal = document.getElementById('addAdminModal');
        if (addAdminModal) {
            addAdminModal.addEventListener('show.bs.modal', function() {
                setTimeout(updateAdminProgress, 100);
            });
        }
    })();
    </script>
