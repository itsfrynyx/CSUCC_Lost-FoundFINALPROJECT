<?php
require_once __DIR__ . '/includes/upload_validation.php';
session_start();
require_once '../DB/conn.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/includes/rbac_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/includes/superadmin_confirmation.php';
require_once __DIR__ . '/includes/account_setup_helper.php';
require_once __DIR__ . '/includes/super_admin_handoff.php';

// Check if logged in
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Log page view
logPageView('Manage Customers');

// RBAC: Require permission to view customers
require_permission('customers.view', 'admin_dashboard.php');

// Check if user has super admin role for certain actions
$current_admin = $conn->prepare("SELECT role FROM infos WHERE id = ?");
$current_admin->bind_param("i", $_SESSION['super_admin_id']);
$current_admin->execute();
$current_role_raw = $current_admin->get_result()->fetch_assoc()['role'] ?? '';

// Normalize role to lowercase for consistent comparison
$current_role = strtolower(trim($current_role_raw));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    header('Location: Sign_in.php');
    exit();
}

// Fetch admin data for display
$admin_stmt = $conn->prepare("SELECT id, username, email, firstname, lastname, middlename, full_name, profile_image, role FROM infos WHERE id = ?");
$admin_stmt->bind_param("i", $_SESSION['super_admin_id']);
$admin_stmt->execute();
$admin_data = $admin_stmt->get_result()->fetch_assoc();

// Determine which dashboard to return to based on role
$dashboard_url = ($current_role === 'super_admin') ? 'admin_dashboard.php' : 'unified_admin_dashboard.php';

$message = '';
$message_type = '';
$generated_idnumber = generate_registration_id($conn);

// Requests from regular admins are reviewed by a super admin before deletion.
$deletion_requests_table_ready = $conn->query("CREATE TABLE IF NOT EXISTS deletion_requests (
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
    INDEX idx_deletion_request_status (status),
    INDEX idx_deletion_request_target (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if ($deletion_requests_table_ready === false) {
    http_response_code(500);
    exit('Unable to prepare the deletion-request table. Please contact the system administrator.');
}
$deletion_details_column = $conn->query("SHOW COLUMNS FROM deletion_requests LIKE 'target_details'");
if ($deletion_details_column === false) {
    http_response_code(500);
    exit('Unable to inspect the deletion-request table. Please contact the system administrator.');
}
if ($deletion_details_column->num_rows === 0 && !$conn->query("ALTER TABLE deletion_requests ADD COLUMN target_details LONGTEXT NULL AFTER target_email")) {
    http_response_code(500);
    exit('Unable to update the deletion-request table. Please contact the system administrator.');
}

// Handle CRUD operations
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], account_input('csrf_token', false))) {
    $message = 'This form expired. Reload the page and try again.';
    $message_type = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = account_input('action');
    
    switch ($action) {
        case 'add':
            // Add new customer
            $idnumber = generate_registration_id($conn);
            $firstname = account_input('firstname');
            $middlename = account_input('middlename');
            $lastname = account_input('lastname');
            $suffix = account_input('suffix');
            $sex = account_input('sex');
            $birth = account_input('birth');
            $age = account_input('age');
            $street = account_input('street');
            $barangay = account_input('barangay');
            $municipality = account_input('municipality');
            $province = account_input('province');
            $country = account_input('country');
            $postal = account_input('postal');
            $email = account_input('email');
            $username = account_input('username');
            $role = 'customer';
            $status = account_input('status');
            
            $birth_date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth) : false;
            if (!$birth_date || $birth_date->format('Y-m-d') !== $birth || $birth_date > new DateTimeImmutable('today')) {
                $message = 'Enter a valid birthdate.';
                $message_type = 'danger';
                break;
            }
            $age = $birth_date->diff(new DateTimeImmutable('today'))->y;
            if ($age < 10 || $age > 120 || !in_array($sex, ['Male', 'Female', 'Prefer not to say'], true)
                || !in_array($status, ['active', 'inactive'], true) || !in_array($role, ['customer'], true)) {
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

            // Build full name
            $full_name = $firstname . ' ' . ($middlename ? $middlename . '. ' : '') . $lastname . ($suffix ? ' ' . $suffix : '');
            
            $temporary_password = is_string($_POST['temporary_password'] ?? null) ? $_POST['temporary_password'] : '';
            $hashed_password = password_hash($temporary_password, PASSWORD_DEFAULT);
            $must_complete_setup = 1;
            
            // Insert customer
            $stmt = $conn->prepare("INSERT INTO infos (idnumber, firstname, middlename, lastname, suffix, sex, birth, age, street, barangay, municipality, province, country, postal, email, username, passw, role, status, full_name, must_complete_setup, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("ssssssssssssssssssssi", $idnumber, $firstname, $middlename, $lastname, $suffix, $sex, $birth, $age, $street, $barangay, $municipality, $province, $country, $postal, $email, $username, $hashed_password, $role, $status, $full_name, $must_complete_setup);
            
            try {
                $customer_id = create_managed_account($conn, $stmt, $role, (int) $_SESSION['super_admin_id'], $email, $username, $temporary_password);
            } catch (RuntimeException $error) {
                $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Unable to create the account.';
                $message_type = 'danger';
                break;
            }

            $message = 'Customer created. The temporary password was emailed to the account holder.';
            $message_type = 'success';
            logCustomerManagement('add_customer', $username, $customer_id);
            break;
            
        case 'edit':
            $customer_id = account_positive_integer($_POST['customer_id'] ?? null);
            if ($customer_id === null) {
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
                $message = 'Enter your correct Super Administrator password to update this customer.';
                $message_type = 'danger';
                break;
            }
            
            // Allow both super_admin and admin to edit customers
            $stmt = $conn->prepare("UPDATE infos SET username = ?, email = ?, full_name = ?, status = ? WHERE id = ? AND role = 'customer'");
            $stmt->bind_param("ssssi", $username, $email, $full_name, $status, $customer_id);
            
            if ($stmt->execute()) {
                $customer_exists = $conn->prepare("SELECT 1 FROM infos WHERE id = ? AND role = 'customer' LIMIT 1");
                $customer_exists->bind_param("i", $customer_id);
                $customer_exists->execute();
                if ($customer_exists->get_result()->num_rows === 1) {
                    $message = 'Customer updated successfully';
                    $message_type = 'success';
                    logCustomerManagement('edit_customer', $username, $customer_id);
                } else {
                    $message = 'Customer not found.';
                    $message_type = 'danger';
                }
            } else {
                $message = 'Error updating customer: ' . $conn->error;
                $message_type = 'danger';
            }
            break;
            
        case 'delete':
            $customer_id = account_positive_integer($_POST['customer_id'] ?? null);
            if ($customer_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            if ($current_role !== 'super_admin') {
                $deletion_reason = trim($_POST['deletion_reason'] ?? '');
                if ($deletion_reason === '') {
                    $message = 'A reason is required before sending the deletion request.';
                    $message_type = 'danger';
                    break;
                }

                $customer_info = $conn->prepare("SELECT idnumber, username, full_name, firstname, middlename, lastname, suffix, email, sex, birth, age, street, barangay, municipality, province, postal, country, status FROM infos WHERE id = ? AND role = 'customer'");
                $customer_info->bind_param("i", $customer_id);
                $customer_info->execute();
                $requested_customer = $customer_info->get_result()->fetch_assoc();

                if (!$requested_customer) {
                    $message = 'Customer not found';
                    $message_type = 'danger';
                    break;
                }

                $duplicate_check = $conn->prepare("SELECT id FROM deletion_requests WHERE target_user_id = ? AND status = 'pending' LIMIT 1");
                $duplicate_check->bind_param("i", $customer_id);
                $duplicate_check->execute();
                if ($duplicate_check->get_result()->num_rows > 0) {
                    $message = 'A deletion request for this customer is already pending.';
                    $message_type = 'warning';
                    break;
                }

                $target_details = json_encode($requested_customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $request_stmt = $conn->prepare("INSERT INTO deletion_requests (target_user_id, target_idnumber, target_username, target_full_name, target_email, target_details, reason, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $request_stmt->bind_param("issssssi", $customer_id, $requested_customer['idnumber'], $requested_customer['username'], $requested_customer['full_name'], $requested_customer['email'], $target_details, $deletion_reason, $_SESSION['super_admin_id']);
                if ($request_stmt->execute()) {
                    $message = 'Deletion request sent to the Super Administrator for review.';
                    $message_type = 'success';
                    logCustomerManagement('request_customer_deletion', $requested_customer['username'], $customer_id);
                } else {
                    $message = 'Unable to send deletion request.';
                    $message_type = 'danger';
                }
            } elseif (!confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to delete this customer.';
                $message_type = 'danger';
            } else {
                // Validate proof image is uploaded
                if (!isset($_FILES['deletion_proof_image']) || $_FILES['deletion_proof_image']['error'] !== UPLOAD_ERR_OK) {
                    $message = 'Deletion proof image is required';
                    $message_type = 'danger';
                    break;
                }
                
                // Get customer info before deletion
                $customer_info = $conn->prepare("SELECT username, full_name FROM infos WHERE id = ? AND role = 'customer'");
                $customer_info->bind_param("i", $customer_id);
                $customer_info->execute();
                $result = $customer_info->get_result();
                $deleted_customer = $result->fetch_assoc();
                
                if (!$deleted_customer) {
                    $message = 'Customer not found';
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
                    $proof_filename = 'proof_customer_' . $customer_id . '_' . bin2hex(random_bytes(12)) . '.' . $file_extension;
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
                    $proof_stmt = $conn->prepare("INSERT INTO deletion_proofs (deleted_user_id, deleted_user_type, deleted_username, deleted_full_name, proof_image, deletion_reason, deleted_by, ip_address) VALUES (?, 'customer', ?, ?, ?, ?, ?, ?)");
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $proof_stmt->bind_param("issssis", $customer_id, $deleted_customer['username'], $deleted_customer['full_name'], $proof_filename, $deletion_reason, $_SESSION['super_admin_id'], $ip_address);
                    $proof_stmt->execute();
                    
                    // Proceed with deletion
                    $stmt = $conn->prepare("DELETE FROM infos WHERE id = ? AND role = 'customer'");
                    $stmt->bind_param("i", $customer_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Customer deleted successfully with proof recorded';
                        $message_type = 'success';
                        
                        // Log activity using ActivityLogger
                        $deleted_username = $deleted_customer['username'];
                        logCustomerManagement('delete_customer', $deleted_username . ' (Proof: ' . $proof_filename . ')', $customer_id);
                    } else {
                        $message = 'Error deleting customer: ' . $conn->error;
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
            $customer_id = account_positive_integer($_POST['customer_id'] ?? null);
            if ($customer_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            // Allow both super_admin and admin to block customers
            if ($current_role === 'super_admin' && !confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to block this customer.';
                $message_type = 'danger';
                break;
            }

            // Get customer info
            $customer_info = $conn->prepare("SELECT username FROM infos WHERE id = ? AND role = 'customer'");
            $customer_info->bind_param("i", $customer_id);
            $customer_info->execute();
            $blocked_customer = $customer_info->get_result()->fetch_assoc();
            
            if (!$blocked_customer) {
                $message = 'Customer not found';
                $message_type = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE infos SET is_blocked = 1 WHERE id = ? AND role = 'customer'");
                $stmt->bind_param("i", $customer_id);
                
                if ($stmt->execute()) {
                    $message = 'Customer blocked successfully';
                    $message_type = 'success';
                    
                    // Create notification for blocked user (if table exists)
                    $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
                    if ($table_check && $table_check->num_rows > 0) {
                        $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'blocked', 'Account Blocked', 'Your account has been blocked by the Administrator. You will not be able to login until your account is unblocked. Please contact support for assistance.')");
                        $notif_stmt->bind_param("i", $customer_id);
                        $notif_stmt->execute();
                    }
                    
                    // Log activity using ActivityLogger
                    logCustomerManagement('block_customer', $blocked_customer['username'], $customer_id);
                } else {
                    $message = 'Error blocking customer: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            break;
            
        case 'unblock':
            $customer_id = account_positive_integer($_POST['customer_id'] ?? null);
            if ($customer_id === null) {
                $message = 'Choose an existing account.';
                $message_type = 'danger';
                break;
            }
            
            if ($current_role === 'super_admin' && !confirm_current_superadmin_password($conn, $_POST['superadmin_password'] ?? '')) {
                $message = 'Enter your correct Super Administrator password to unblock this customer.';
                $message_type = 'danger';
                break;
            }

            // Get customer info
            $customer_info = $conn->prepare("SELECT username FROM infos WHERE id = ? AND role = 'customer'");
            $customer_info->bind_param("i", $customer_id);
            $customer_info->execute();
            $unblocked_customer = $customer_info->get_result()->fetch_assoc();
            
            if (!$unblocked_customer) {
                $message = 'Customer not found';
                $message_type = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE infos SET is_blocked = 0 WHERE id = ? AND role = 'customer'");
                $stmt->bind_param("i", $customer_id);
                
                if ($stmt->execute()) {
                    $message = 'Customer unblocked successfully';
                    $message_type = 'success';
                    
                    // Create notification for unblocked user (if table exists)
                    $table_check = $conn->query("SHOW TABLES LIKE 'user_notifications'");
                    if ($table_check && $table_check->num_rows > 0) {
                        $notif_stmt = $conn->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message) VALUES (?, 'unblocked', 'Account Unblocked', 'Good news! Your account has been unblocked by the Administrator. You can now login and access the system normally.')");
                        $notif_stmt->bind_param("i", $customer_id);
                        $notif_stmt->execute();
                    }
                    
                    // Log activity using ActivityLogger
                    logCustomerManagement('unblock_customer', $unblocked_customer['username'], $customer_id);
                } else {
                    $message = 'Error unblocking customer: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            break;
            
        case 'reset_password':
            $customer_id = account_positive_integer($_POST['customer_id'] ?? null);
            if ($customer_id === null) {
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
                $message = 'Enter your correct Super Administrator password to reset this customer password.';
                $message_type = 'danger';
                break;
            }
            
            // Allow both super_admin and admin to reset passwords
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE infos SET passw = ? WHERE id = ? AND role = 'customer'");
            $stmt->bind_param("si", $hashed_password, $customer_id);
            
            if ($stmt->execute()) {
                $message = 'Password reset successfully';
                $message_type = 'success';
                
                // Log activity
                $log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', 'reset_customer_password', ?, ?, ?)");
                $description = "Password reset for customer ID: $customer_id";
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $log_stmt->bind_param("isss", $_SESSION['super_admin_id'], $description, $ip_address, $user_agent);
                $log_stmt->execute();
            } else {
                $message = 'Error resetting password: ' . $conn->error;
                $message_type = 'danger';
            }
            break;
    }
}

// Pagination setup for customers - 10 per page
$customers_per_page = 10;
$id_search = is_string($_GET['id_search'] ?? null) ? trim($_GET['id_search']) : '';
if (!account_text_is_valid($id_search, 50)) {
    $id_search = '';
    $message = 'Enter an ID search of up to 50 characters.';
    $message_type = 'danger';
}
$requested_page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

// Search and paginate approved customers by ID number only.
$customer_filter = "WHERE role = 'customer' AND approved = 1";
$search_pattern = '%' . strtr($id_search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

if ($id_search !== '') {
    $customer_filter .= " AND idnumber LIKE ? ESCAPE '!'";
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM infos $customer_filter");
    $count_stmt->bind_param('s', $search_pattern);
} else {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM infos $customer_filter");
}

$count_stmt->execute();
$total_customers_count = (int) $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_customers_count / $customers_per_page));
$page = min($requested_page, $total_pages);
$offset = ($page - 1) * $customers_per_page;
$page_params = $id_search === '' ? [] : ['id_search' => $id_search];

if ($id_search !== '') {
    $customer_stmt = $conn->prepare("SELECT * FROM infos $customer_filter ORDER BY id DESC LIMIT ? OFFSET ?");
    $customer_stmt->bind_param('sii', $search_pattern, $customers_per_page, $offset);
} else {
    $customer_stmt = $conn->prepare("SELECT * FROM infos $customer_filter ORDER BY id DESC LIMIT ? OFFSET ?");
    $customer_stmt->bind_param('ii', $customers_per_page, $offset);
}

$customer_stmt->execute();
$customers = $customer_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$customer_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Customers - Lost & Found Management System</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <link rel="stylesheet" href="../css/delete_modal_compact.css">
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
                        Manage Customers
                    </h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin_data['full_name']); ?>!</small>
                </div>
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
                <strong>Limited Access:</strong> You are logged in as a Administrator. Only <strong>Super Administrator</strong> can delete customer accounts.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Customers Table -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-users text-primary me-2"></i>
                    All Customers
                </h5>

                <form method="get" class="customer-search-form mb-4" role="search">
                    <label for="customerIdSearch" class="visually-hidden">Search by ID number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="search" class="form-control" id="customerIdSearch" name="id_search" maxlength="50" value="<?php echo htmlspecialchars($id_search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by ID number" inputmode="numeric" autocomplete="off">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <?php if ($id_search !== ''): ?>
                            <a href="admin_manage_customers.php" class="btn btn-outline-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">Search customer records using ID number only.</small>
                </form>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID Number</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): 
                                // Build full name dynamically from name components
                                $full_name = trim($customer['firstname']);
                                if (!empty($customer['middlename'])) {
                                    $full_name .= ' ' . trim($customer['middlename']) . '.';
                                }
                                $full_name .= ' ' . trim($customer['lastname']);
                                if (!empty($customer['suffix'])) {
                                    $full_name .= ' ' . trim($customer['suffix']);
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($customer['idnumber']); ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($customer['username']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($full_name); ?></td>
                                <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td>
                                    <?php 
                                    if (isset($customer['is_blocked']) && $customer['is_blocked'] == 1) {
                                        echo '<span class="badge bg-danger">Blocked</span>';
                                    } else {
                                        echo '<span class="badge bg-' . (($customer['status'] ?? 'active') === 'active' ? 'success' : 'secondary') . '">' . ucfirst($customer['status'] ?? 'active') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo isset($customer['created_at']) && !empty($customer['created_at']) ? date('M d, Y', strtotime($customer['created_at'])) : 'N/A'; ?>
                                </td>
                                <td>
                                    <!-- Edit Button - Available to all admins -->
                                    <button class="btn-minimal btn-minimal-primary" onclick="editCustomer(<?php echo $customer['id']; ?>)" title="Edit Customer">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <!-- Block/Unblock Button - Available to all admins -->
                                    <?php if (isset($customer['is_blocked']) && $customer['is_blocked'] == 1): ?>
                                    <button class="btn-minimal btn-minimal-success" onclick="unblockCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['username']); ?>')" title="Unblock Customer">
                                        <i class="fas fa-unlock"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="btn-minimal btn-minimal-dark" onclick="blockCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['username']); ?>')" title="Block Customer">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Promote to Admin Button - Super Admin only -->
                                    <?php 
                                    $is_offline = (isset($customer['status']) && strtolower(trim($customer['status'])) === 'offline');
                                    if ($current_role === 'super_admin' && $is_offline): ?>
                                    <button class="btn-minimal btn-minimal-secondary" disabled title="Cannot promote offline customers">
                                        <i class="fas fa-user-shield"></i>
                                    </button>
                                    <?php elseif ($current_role === 'super_admin'): ?>
                                    <button class="btn-minimal btn-minimal-torch" onclick="promoteToAdmin(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['username']); ?>', '<?php echo htmlspecialchars($customer['full_name'] ?? $customer['firstname'] . ' ' . $customer['lastname']); ?>')" title="Promote to Admin">
                                        <i class="fas fa-user-shield"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Transfer the Crown Button - Only for Super Admin, disabled if offline -->
                                    <?php if ($current_role === 'super_admin'): ?>
                                        <?php if ($is_offline): ?>
                                        <button class="btn-minimal btn-minimal-secondary" disabled title="Cannot transfer the crown to offline customers">
                                            <i class="fas fa-crown"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn-minimal btn-minimal-torch" onclick="passTorchToCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['username']); ?>', '<?php echo htmlspecialchars($customer['full_name'] ?? $customer['firstname'] . ' ' . $customer['lastname']); ?>')" title="Transfer the Crown - Promote to Super Admin">
                                            <i class="fas fa-crown"></i>
                                        </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Delete Button -->
                                    <?php if ($current_role === 'super_admin'): ?>
                                    <!-- Super Admin - Active Delete Button -->
                                    <button class="btn-minimal btn-minimal-danger" onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['username']); ?>')" title="Delete Customer">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                    <?php else: ?>
                                    <!-- Regular Admin - Locked Delete Button -->
                                    <button class="btn-minimal btn-minimal-secondary" disabled title="Only Super Administrator can delete customers">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3" aria-label="Customer pagination">
                    <span class="small text-muted">
                        <?php if ($total_customers_count > 0): ?>
                            Showing <?= $offset + 1 ?>&ndash;<?= $offset + count($customers) ?> of <?= $total_customers_count ?> customers
                        <?php else: ?>
                            0 customers
                        <?php endif; ?>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($page_params + ['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>" rel="prev">Previous</a>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Previous</button>
                        <?php endif; ?>
                        <span class="small text-muted">Page <?= $page ?> of <?= $total_pages ?></span>
                        <?php if ($page < $total_pages): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($page_params + ['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>" rel="next">Next</a>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Next</button>
                        <?php endif; ?>
                    </div>
                </nav>
            </div>
        </div>
    </div>

    <!-- Add New Customer Modal - Aesthetic Design -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-aesthetic-large">
            <div class="modal-content aesthetic-modal">
                <!-- Elegant Close Button -->
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal" aria-label="Close">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="addCustomerForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body aesthetic-body-large">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="role" value="customer">
                        
                        <!-- Minimalist Icon -->
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon add-icon-customer">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="8.5" cy="7" r="4"></circle>
                                    <line x1="20" y1="8" x2="20" y2="14"></line>
                                    <line x1="23" y1="11" x2="17" y2="11"></line>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Clean Title -->
                        <h3 class="aesthetic-title">Add New Customer</h3>
                        <p class="aesthetic-subtitle">Create a new account with secure credentials</p>
                        
                        <!-- Progress Bar -->
                        <div class="form-progress-wrapper">
                            <div class="form-progress-bar">
                                <div class="form-progress-fill" id="customerProgressBar"></div>
                            </div>
                            <div class="form-progress-text">
                                <span id="customerProgressText">0% Complete</span>
                            </div>
                        </div>
                        
                        <!-- Minimalist Form -->
                        <div class="aesthetic-form aesthetic-form-large">
                        
                        <!-- Personal Information Section -->
                        <div class="form-section-aesthetic">
                            <div class="section-divider-aesthetic">
                                <span class="section-label-text">Personal Information</span>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Generated ID Number</label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="idnumber" id="add_customer_idnumber" value="<?php echo htmlspecialchars($generated_idnumber, ENT_QUOTES, 'UTF-8'); ?>" readonly aria-readonly="true">
                                        </div>
                                        <span id="add_customer_idnumber_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">First Name <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="firstname" id="add_customer_firstname" placeholder="Enter first name" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_firstname_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">M.I <span class="optional-mark">(optional)</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="middlename" id="add_customer_middlename" placeholder="M" maxlength="1">
                                        </div>
                                        <span id="add_customer_middlename_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Last Name <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="lastname" id="add_customer_lastname" placeholder="Enter last name" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_lastname_error" class="field-error"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Extension <span class="optional-mark">(optional)</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="suffix" id="add_customer_suffix" placeholder="Jr., Sr., III" maxlength="3">
                                        </div>
                                        <span id="add_customer_suffix_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Sex <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <select class="input-aesthetic" name="sex" id="add_customer_sex" required>
                                                <option value="">Select</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                        <span id="add_customer_sex_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Birthdate <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="date" class="input-aesthetic" name="birth" id="add_customer_birthdate" required>
                                        </div>
                                        <span id="add_customer_birthdate_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Age</label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="number" class="input-aesthetic" name="age" id="add_customer_age" placeholder="Auto-calculated" readonly required>
                                        </div>
                                        <span id="add_customer_age_error" class="field-error"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Address Section -->
                        <div class="form-section-aesthetic">
                            <div class="section-divider-aesthetic">
                                <span class="section-label-text">Personal Address</span>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Purok <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="street" id="add_customer_street" placeholder="Enter purok" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_street_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Barangay <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="barangay" id="add_customer_barangay" placeholder="Enter barangay" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_barangay_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Municipality/City <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="municipality" id="add_customer_municipality" placeholder="Enter municipality" maxlength="20" required>
                                        </div>
                                        <span id="add_customer_municipality_error" class="field-error"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Province <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="province" id="add_customer_province" placeholder="Enter province" maxlength="20" required>
                                        </div>
                                        <span id="add_customer_province_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Country <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="country" id="add_customer_country" placeholder="Enter country" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_country_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Zip Code <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="postal" id="add_customer_postal" placeholder="Enter zip code" maxlength="4" required>
                                        </div>
                                        <span id="add_customer_postal_error" class="field-error"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Account Credentials Section -->
                        <div class="form-section-aesthetic">
                            <div class="section-divider-aesthetic">
                                <span class="section-label-text">Account Credentials</span>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Email Address <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="email" class="input-aesthetic" name="email" id="add_customer_email" placeholder="customer@example.com" maxlength="30" required>
                                        </div>
                                        <span id="add_customer_email_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Username <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <input type="text" class="input-aesthetic" name="username" id="add_customer_username" placeholder="Enter username" maxlength="15" required>
                                        </div>
                                        <span id="add_customer_username_error" class="field-error"></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mt-3">
                                    <label for="new_customer_temporary_password" class="form-label">Temporary password <span aria-hidden="true">*</span></label>
                                    <div class="d-flex align-items-stretch gap-2">
                                        <input type="password" class="input-aesthetic" id="new_customer_temporary_password" name="temporary_password" minlength="8" maxlength="15"
                                            pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}" autocomplete="new-password" aria-describedby="new_customer_temporary_password_help" required>
                                        <button type="button" class="btn btn-outline-secondary flex-shrink-0" data-temporary-password-toggle aria-controls="new_customer_temporary_password" aria-pressed="false">Show</button>
                                    </div>
                                    <small id="new_customer_temporary_password_help" class="text-muted">Use 8 to 15 characters with uppercase and lowercase letters and a number. The password will be emailed to the new account holder.</small>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2 justify-content-center">
                                <div class="col-md-3">
                                    <div class="form-field-aesthetic">
                                        <label class="field-label">Status <span class="required-mark">*</span></label>
                                        <div class="input-wrapper-aesthetic">
                                            <select class="input-aesthetic" name="status" required>
                                                <option value="">Select</option>
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div><!-- Close aesthetic-form -->
                        
                        <!-- Elegant Buttons -->
                        <div class="aesthetic-buttons">
                            <button type="button" class="btn-aesthetic btn-secondary-aesthetic" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn-aesthetic btn-primary-aesthetic">
                                Create Customer
                            </button>
                        </div>
                    </div><!-- Close aesthetic-body-large -->
                </form>
            </div>
        </div>
    </div>

    <script>
    // Reset form and progress bar when modal opens
    document.getElementById('addCustomerModal')?.addEventListener('show.bs.modal', function() {
        document.getElementById('addCustomerForm')?.reset();
        updateCustomerProgress();
    });
    
    // Track form completion progress for Customer modal
    function updateCustomerProgress() {
        const form = document.getElementById('addCustomerForm');
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
        const progressBar = document.getElementById('customerProgressBar');
        const progressText = document.getElementById('customerProgressText');
        
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
        
        if (progressText) {
            progressText.textContent = progress + '% Complete';
            
            // Change color based on progress
            if (progress === 100) {
                progressText.style.color = '#10B981';
            } else if (progress >= 50) {
                progressText.style.color = '#F59E0B';
            } else {
                progressText.style.color = '#6B7280';
            }
        }
    }
    
    // Add event listeners to all form inputs in Customer modal
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('addCustomerForm');
        if (form) {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', updateCustomerProgress);
                input.addEventListener('change', updateCustomerProgress);
            });
        }
    });
    
    
    // Auto-calculate age from birthdate for customer
    document.getElementById('add_customer_birthdate')?.addEventListener('change', function() {
        const birthDate = new Date(this.value);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        document.getElementById('add_customer_age').value = age;
    });

    // Password toggle function for customer modal
    function togglePasswordCustomer(inputId) {
        const input = document.getElementById(inputId);
        const button = input.nextElementSibling;
        const icon = button.querySelector('.eye-icon');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        } else {
            input.type = 'password';
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        }
    }

    // Password confirmation validation for customer
    document.getElementById('add_customer_confirm_password')?.addEventListener('input', function() {
        const password = document.getElementById('add_customer_password').value;
        if (this.value !== password) {
            this.setCustomValidity('Passwords do not match');
        } else {
            this.setCustomValidity('');
        }
    });
    </script>
    
    <!-- Ultra Aesthetic Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1">
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
                        <input type="hidden" name="customer_id" id="edit_customer_id">
                        
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
                        <h3 class="aesthetic-title">Edit Customer</h3>
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
                        <input type="hidden" name="customer_id" id="reset_customer_id">
                        
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
                                    <button type="button" class="password-toggle-aesthetic" onclick="togglePasswordResetCustomer('new_password_reset')">
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
                                    <button type="button" class="password-toggle-aesthetic" onclick="togglePasswordResetCustomer('confirm_password_reset')">
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
                            
                            <div class="password-match-aesthetic" id="password_match_indicator_customer" style="display: none;">
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
    
    <!-- Compact Delete Confirmation Modal with Proof Upload -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header delete-modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
                        <?php echo $current_role === 'super_admin' ? 'Confirm Deletion with Proof' : 'Request Customer Deletion'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="deleteCustomerForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body delete-modal-body-compact">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="customer_id" id="delete_customer_id">
                        <?php if ($current_role === 'super_admin'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm Super Admin Password</label>
                            <input type="password" class="form-control" name="superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <!-- Left Column: Warning & Info -->
                            <div class="col-md-6">
                                <div class="delete-warning-box-compact">
                                    <div class="delete-icon-compact">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <h6 class="delete-title-compact">Delete Customer</h6>
                                    <div class="delete-user-info-compact">
                                        <i class="fas fa-user me-2"></i>
                                        <span id="delete_customer_name"></span>
                                    </div>
                                </div>
                                
                                <div class="delete-consequences-compact">
                                    <div class="consequence-item-compact">
                                        <i class="fas fa-times-circle text-danger"></i>
                                        <span>Revokes system access</span>
                                    </div>
                                    <div class="consequence-item-compact">
                                        <i class="fas fa-exclamation-circle text-warning"></i>
                                        <span>Cannot be undone</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column: Proof Upload or Request Reason -->
                            <div class="col-md-6">
                                <?php if ($current_role !== 'super_admin'): ?>
                                <label class="form-label fw-semibold" for="deletion_reason">Reason for deletion request</label>
                                <textarea class="form-control" id="deletion_reason" name="deletion_reason" rows="6" placeholder="Explain why this customer account should be deleted" required></textarea>
                                <?php else: ?>
                                <div class="proof-upload-compact">
                                    <label class="proof-label-compact">
                                        <i class="fas fa-shield-alt text-primary me-2"></i>
                                        <strong>Proof Required</strong>
                                    </label>
                                    
                                    <label for="deletion_proof_image" class="proof-dropzone-compact">
                                        <div id="proof_preview_container" class="proof-preview-compact" style="display: none;">
                                            <img id="proof_preview_content" src="" alt="Preview" style="max-width: 100%; max-height: 120px; border-radius: 6px;">
                                            <button type="button" class="proof-remove-compact" onclick="removeProofImage()">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <div id="proof_upload_placeholder" class="proof-placeholder-compact">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <span>Click to upload</span>
                                            <small>PNG, JPG, PDF (Max 5MB)</small>
                                        </div>
                                        <input type="file" 
                                               id="deletion_proof_image" 
                                               name="deletion_proof_image" 
                                               accept="image/png,image/jpeg,image/jpg,application/pdf" 
                                               style="display: none;"
                                               required>
                                    </label>
                                    
                                    <textarea class="form-control form-control-sm mt-2" 
                                              id="deletion_reason" 
                                              name="deletion_reason" 
                                              rows="2" 
                                              placeholder="Reason (optional)"
                                              style="font-size: 12px;"></textarea>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Confirmation Checkbox -->
                        <div class="delete-confirm-compact">
                            <label class="form-check-label">
                                <input type="checkbox" id="confirmDeleteCheckbox" class="form-check-input me-2">
                                <?php echo $current_role === 'super_admin' ? 'I have attached valid proof and confirm deletion' : 'I confirm that this deletion request and reason are accurate'; ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-footer delete-modal-footer-compact">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger btn-sm" id="confirmDeleteBtn" disabled>
                            <i class="fas fa-trash me-1"></i>
                            <?php echo $current_role === 'super_admin' ? 'Delete with Proof' : 'Send Deletion Request'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Block Customer Confirmation Modal -->
    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="blockCustomerForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="block">
                        <input type="hidden" name="customer_id" id="block_customer_id">
                        
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon block-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                                </svg>
                            </div>
                        </div>
                        
                        <h3 class="aesthetic-title">Block Customer</h3>
                        <p class="aesthetic-subtitle">This will prevent the user from logging in</p>
                        
                        <div class="aesthetic-form">
                            <div class="block-user-info">
                                <i class="fas fa-user"></i>
                                <span id="block_customer_name"></span>
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
    
    <!-- Unblock Customer Confirmation Modal -->
    <div class="modal fade" id="unblockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-aesthetic">
            <div class="modal-content aesthetic-modal">
                <button type="button" class="btn-close-aesthetic" data-bs-dismiss="modal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                
                <form method="POST" id="unblockCustomerForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body aesthetic-body">
                        <input type="hidden" name="action" value="unblock">
                        <input type="hidden" name="customer_id" id="unblock_customer_id">
                        
                        <div class="aesthetic-icon-wrapper">
                            <div class="aesthetic-icon unblock-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 9.9-1"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <h3 class="aesthetic-title">Unblock Customer</h3>
                        <p class="aesthetic-subtitle">Restore access for this user</p>
                        
                        <div class="aesthetic-form">
                            <div class="block-user-info">
                                <i class="fas fa-user"></i>
                                <span id="unblock_customer_name"></span>
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

                            <?php if ($current_role === 'super_admin'): ?>
                            <div class="mt-4">
                                <label class="field-label" for="unblock_superadmin_password">Confirm Super Administrator Password</label>
                                <input type="password" class="input-aesthetic" name="superadmin_password" id="unblock_superadmin_password" placeholder="Enter your password to continue" autocomplete="current-password" required>
                            </div>
                            <?php endif; ?>
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

    <!-- Promote to Admin Modal - Minimalist Version -->
    <div class="modal fade" id="promoteToAdminModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius: 12px; border: none;">
                <form id="promoteToAdminForm">
                    <div style="padding: 28px 24px;">
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="position: absolute; top: 16px; right: 16px; opacity: 0.5;"></button>
                        
                        <input type="hidden" id="promote_customer_id">
                        
                        <!-- Icon -->
                        <div style="width: 48px; height: 48px; margin: 0 auto 20px; background: #FEF3C7; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-shield" style="font-size: 20px; color: #F59E0B;"></i>
                        </div>
                        
                        <!-- Title -->
                        <h6 style="margin: 0 0 6px 0; color: #1E293B; font-weight: 600; font-size: 16px; text-align: center;">Promote to Admin?</h6>
                        
                        <!-- Customer Name -->
                        <p style="margin: 0 0 20px 0; color: #64748B; font-size: 14px; text-align: center; font-weight: 500;" id="promote_customer_name"></p>
                        
                        <!-- Warning -->
                        <div style="background: #FFFBEB; border-radius: 8px; padding: 14px; margin-bottom: 24px;">
                            <p style="margin: 0; font-size: 12px; color: #92400E; line-height: 1.6;">
                                This will grant full administrative privileges and dashboard access.
                            </p>
                        </div>
                        
                        <!-- Buttons -->
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="flex: 1; border-radius: 8px; padding: 10px; font-size: 13px; font-weight: 500; border: 1px solid #E2E8F0;">
                                Cancel
                            </button>
                            <button type="submit" class="btn" style="flex: 1; background: #F59E0B; color: white; border: none; border-radius: 8px; padding: 10px; font-size: 13px; font-weight: 600;">
                                Promote
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    
    <style>
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
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
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
        
        /* Professional Super Admin Button Styles - No backgrounds */
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
        
        /* Torch Button - Promote to Admin */
        .btn-minimal-torch {
            border: none !important;
            color: #F59E0B !important;
            background: transparent !important;
            position: relative;
        }
        
        .btn-minimal-torch:hover {
            background: transparent !important;
            color: #D97706 !important;
            border: none !important;
            animation: torchFlicker 0.3s ease-in-out;
        }
        
        @keyframes torchFlicker {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        /* Block/Unblock Modal Styles */
        .block-icon {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
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
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger-aesthetic:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }
        
        .btn-success-aesthetic {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success-aesthetic:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }
        
        /* ========================================
           ULTRA AESTHETIC MODAL STYLES
           Modern, Minimalist, Premium Design
           Super Admin Dashboard
        ======================================== */
        
        .modal-aesthetic {
            max-width: 1400px;
        }
        
        /* Custom Badge Colors - Remove backgrounds, just text */
        .badge.bg-brown {
            background: transparent !important;
            color: #D97706 !important;
            font-weight: 600;
            border: none !important;
        }
        
        .badge.bg-success {
            background: transparent !important;
            color: #10B981 !important;
            border: none !important;
        }
        
        .badge.bg-danger {
            background: transparent !important;
            color: #EF4444 !important;
            border: none !important;
        }
        
        .badge.bg-secondary {
            background: transparent !important;
            color: #64748B !important;
            border: none !important;
        }
        
        .badge {
            background: transparent !important;
            border: none !important;
            padding: 6px 12px;
            font-weight: 600;
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
            max-height: none;
            overflow-y: visible;
            padding: 32px 48px 40px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .aesthetic-form-large {
            max-width: 100%;
            margin: 0 auto;
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
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.25);
        }
        
        .aesthetic-icon svg {
            width: 32px;
            height: 32px;
        }
        
        .add-icon {
            background: #10B981;
            color: #ffffff;
        }
        
        .add-icon-customer {
            background: #10B981;
            color: #ffffff;
            box-shadow: none;
        }
        
        .aesthetic-icon svg {
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }
        
        .edit-icon {
            background: #EEF2FF;
            color: #4F46E5;
            box-shadow: none;
        }
        
        .reset-icon {
            background: #FEF3C7;
            color: #B45309;
            box-shadow: none;
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
            margin: 0 0 32px 0;
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
            background: #10B981;
            border-radius: 10px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: none;
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
            color: #10B981;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
            line-height: 1.3;
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
            overflow: visible !important;
        }
        
        .aesthetic-form-large {
            overflow: visible !important;
        }
        
        .section-divider-aesthetic {
            text-align: center;
            margin-bottom: 24px;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .section-label-text {
            font-size: 12px;
            font-weight: 700;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: inline-block;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
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
            z-index: 1;
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
            border-color: #10B981;
            background: #FAFAFA;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.05);
        }
        
        .input-aesthetic:focus {
            border-color: #10B981;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
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
            border-color: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
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
            background: #10B981 !important;
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
            justify-content: center;
            padding-top: 24px;
            border-top: 1px solid #F3F4F6;
        }
        
        .btn-aesthetic {
            min-width: 140px;
            height: 44px;
            padding: 0 32px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.01em;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .btn-secondary-aesthetic {
            background: #F9FAFB;
            color: #6B7280;
            border: 1.5px solid #E5E7EB;
        }
        
        .btn-secondary-aesthetic:hover {
            background: #F3F4F6;
            border-color: #D1D5DB;
            color: #374151;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .btn-primary-aesthetic {
            background: #10B981;
            color: white;
            box-shadow: none;
            border: none;
        }
        
        .btn-primary-aesthetic:hover {
            background: #059669;
            box-shadow: none;
            transform: none;
        }
        
        .btn-primary-aesthetic:active {
            transform: translateY(0);
        }
        
        .btn-danger-aesthetic {
            background: #EF4444;
            color: white;
            box-shadow: none;
            border: 2px solid transparent;
        }
        
        .btn-danger-aesthetic:hover {
            background: #DC2626;
            box-shadow: none;
            transform: none;
        }
        
        /* Desktop/Body Scrollbar Styling */
        body::-webkit-scrollbar {
            width: 8px;
        }
        
        body::-webkit-scrollbar-track {
            background: #F8FAFC;
        }
        
        body::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 4px;
        }
        
        body::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
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
        
        /* Enhanced Add Customer Modal Styles - Same as Add Admin */
        .add-admin-modal .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .add-admin-header {
            background: linear-gradient(135deg, #882b2a 0%, #a02c2b 100%);
            color: white;
            padding: 30px;
            border: none;
        }
        
        .add-admin-header .header-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .add-admin-header .icon-wrapper {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .add-admin-header .header-text h5 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }
        
        .add-admin-header .modal-subtitle {
            margin: 5px 0 0 0;
            font-size: 13px;
            opacity: 0.9;
        }
        
        .add-admin-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .add-admin-body {
            padding: 35px;
            background: #f8f9fa;
        }
        
        /* Progress Indicator */
        .form-progress {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 35px;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            border: 3px solid #e9ecef;
        }
        
        .progress-step.active .step-circle {
            background: linear-gradient(135deg, #882b2a, #a02c2b);
            color: white;
            border-color: #882b2a;
            box-shadow: 0 4px 15px rgba(136, 43, 42, 0.3);
        }
        
        .progress-step.completed .step-circle {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        
        .step-label {
            font-size: 12px;
            font-weight: 500;
            color: #6c757d;
            text-align: center;
        }
        
        .progress-step.active .step-label {
            color: #882b2a;
            font-weight: 600;
        }
        
        .progress-line {
            height: 3px;
            background: #e9ecef;
            flex: 1;
            margin: 0 10px;
            margin-bottom: 30px;
            border-radius: 2px;
        }
        
        /* Form Steps */
        .form-step {
            display: none;
            animation: fadeIn 0.4s ease;
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
            margin-bottom: 25px;
            padding: 15px 20px;
            background: white;
            border-radius: 12px;
            border-left: 4px solid #882b2a;
        }
        
        .step-header i {
            font-size: 24px;
            color: #882b2a;
        }
        
        .step-header h6 {
            margin: 0;
            font-size: 16px;
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
            
            .status-selector {
                gap: 10px;
            }
        }
        
        /* ========================================
           ULTRA AESTHETIC MODAL STYLES
           Modern, Minimalist, Premium Design
        ======================================== */
        
        .modal-aesthetic {
            max-width: 480px;
        }
        
        .aesthetic-modal {
            border: none;
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            position: relative;
        }
        
        .aesthetic-body {
            padding: 48px 40px;
            text-align: center;
        }
        
        /* Elegant Close Button */
        .btn-close-aesthetic {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: transparent;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
            color: #94a3b8;
        }
        
        .btn-close-aesthetic:hover {
            background: #f1f5f9;
            color: #64748b;
            transform: rotate(90deg);
        }
        
        /* Minimalist Icon */
        .aesthetic-icon-wrapper {
            margin-bottom: 32px;
            animation: fadeInScale 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .aesthetic-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .edit-icon {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            color: #0284c7;
        }
        
        .reset-icon {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #d97706;
        }
        
        /* Clean Typography */
        .aesthetic-title {
            font-size: 26px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 8px 0;
            letter-spacing: -0.02em;
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.1s both;
        }
        
        .aesthetic-subtitle {
            font-size: 15px;
            color: #64748b;
            margin: 0 0 36px 0;
            font-weight: 400;
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both;
        }
        
        /* Minimalist Form */
        .aesthetic-form {
            text-align: left;
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
        }
        
        .form-field-aesthetic {
            margin-bottom: 20px;
        }
        
        .form-row-aesthetic {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .field-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            margin-bottom: 8px;
            letter-spacing: 0.01em;
        }
        
        .input-wrapper-aesthetic {
            position: relative;
        }
        
        .input-aesthetic {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
        }
        
        .input-aesthetic:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }
        
        .input-aesthetic::placeholder {
            color: #cbd5e1;
        }
        
        .field-hint {
            display: block;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }
        
        /* Password Toggle */
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle-aesthetic {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: #94a3b8;
            transition: all 0.2s ease;
            border-radius: 6px;
        }
        
        .password-toggle-aesthetic:hover {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .password-wrapper .input-aesthetic {
            padding-right: 48px;
        }
        
        /* Password Match Indicator */
        .password-match-aesthetic {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 12px;
            margin-top: 16px;
            color: #16a34a;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Elegant Buttons */
        .aesthetic-buttons {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.4s both;
        }
        
        .btn-aesthetic {
            flex: 1;
            padding: 13px 24px;
            border-radius: 12px;
            border: none;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            letter-spacing: 0.01em;
        }
        
        .btn-secondary-aesthetic {
            background: #f8fafc;
            color: #64748b;
            border: 1.5px solid #e2e8f0;
        }
        
        .btn-secondary-aesthetic:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #475569;
        }
        
        .btn-primary-aesthetic {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2);
        }
        
        .btn-primary-aesthetic:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            box-shadow: 0 6px 16px rgba(2, 132, 199, 0.3);
            transform: translateY(-1px);
        }
        
        .btn-primary-aesthetic:active {
            transform: translateY(0);
        }
        
        /* Animations */
        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            .modal-aesthetic {
                max-width: 100%;
                margin: 16px;
            }
            
            .aesthetic-body {
                padding: 36px 24px;
            }
            
            .aesthetic-icon {
                width: 64px;
                height: 64px;
                border-radius: 16px;
            }
            
            .aesthetic-icon svg {
                width: 26px;
                height: 26px;
            }
            
            .aesthetic-title {
                font-size: 22px;
            }
            
            .aesthetic-subtitle {
                font-size: 14px;
                margin-bottom: 28px;
            }
            
            .form-row-aesthetic {
                grid-template-columns: 1fr;
            }
            
            .aesthetic-buttons {
                flex-direction: column;
            }
            
            .btn-aesthetic {
                width: 100%;
            }
        }
        
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
        
        /* Animations */
        @keyframes iconBounce {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        @keyframes iconShake {
            0%, 100% {
                transform: rotate(0deg);
            }
            25% {
                transform: rotate(-10deg);
            }
            75% {
                transform: rotate(10deg);
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 0.4;
            }
            100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive Design for Enhanced Modals */
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
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Customer Add Form Validation -->
    <script src="../js/customer_add_validation.js"></script>
    <script src="../js/delete_modal_compact.js"></script>
    
    <script>
        // Delete Customer Function (Compact Version)
        function deleteCustomer(customerId, customerName) {
            document.getElementById('deleteCustomerForm').reset();
            document.getElementById('confirmDeleteCheckbox').checked = false;
            document.getElementById('confirmDeleteBtn').disabled = true;
            <?php if ($current_role === 'super_admin'): ?>
            removeProofImage();
            <?php endif; ?>
            document.getElementById('delete_customer_id').value = customerId;
            document.getElementById('delete_customer_name').textContent = customerName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Block Customer Function
        function blockCustomer(customerId, customerName) {
            document.getElementById('block_customer_id').value = customerId;
            document.getElementById('block_customer_name').textContent = customerName;
            new bootstrap.Modal(document.getElementById('blockModal')).show();
        }
        
        // Unblock Customer Function
        function unblockCustomer(customerId, customerName) {
            document.getElementById('unblock_customer_id').value = customerId;
            document.getElementById('unblock_customer_name').textContent = customerName;
            new bootstrap.Modal(document.getElementById('unblockModal')).show();
        }
        
        // Promote to Admin Function
        function promoteToAdmin(customerId, customerUsername, customerFullName) {
            document.getElementById('promote_customer_id').value = customerId;
            document.getElementById('promote_customer_name').textContent = customerFullName;
            new bootstrap.Modal(document.getElementById('promoteToAdminModal')).show();
        }
        
        // Handle Promote to Admin Form Submission
        document.getElementById('promoteToAdminForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const customerId = document.getElementById('promote_customer_id').value;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Promoting...';
            
            // Send AJAX request
            fetch('ajax/promote_to_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    customer_id: customerId,
                    csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('promoteToAdminModal')).hide();
                    
                    // Show success message
                    alert('Customer successfully promoted to Administrator!');
                    
                    // Reload page to reflect changes
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to promote customer'));
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while promoting the customer');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
        
        // Transfer the Crown to Customer Function
        function passTorchToCustomer(customerId, customerUsername, customerFullName) {
            if (confirm('Are you sure you want to transfer the crown to ' + customerFullName + '?\n\nThis will:\nâ€¢ Promote them to Super Admin\nâ€¢ Demote your account to Admin\nâ€¢ Disable your account\nâ€¢ Log you out immediately\n\nThis action cannot be undone!')) {
                // Send AJAX request
                fetch('ajax/pass_the_torch.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'new_superadmin_id=' + customerId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Torch passed successfully! You will now be logged out.');
                        window.location.href = 'Sign_in.php';
                    } else {
                        alert('Error: ' + (data.message || 'Failed to transfer the crown'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while passing the torch');
                });
            }
        }
        
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
        
        function editCustomer(customerId) {
            // Fetch customer data
            fetch(`admin_get_data.php?id=${customerId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_customer_id').value = data.id;
                    document.getElementById('edit_username').value = data.username;
                    document.getElementById('edit_email').value = data.email;
                    document.getElementById('edit_full_name').value = data.full_name || '';
                    document.getElementById('edit_status').value = data.status || 'active';
                    
                    const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
                    modal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer data');
                });
        }
        
        function resetPassword(customerId) {
            document.getElementById('reset_customer_id').value = customerId;
            const modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
            modal.show();
        }
        
        // Password toggle for Reset Password modal
        function togglePasswordResetCustomer(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('.eye-icon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }
        
        // Password match validation for Reset Password modal
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordReset = document.getElementById('new_password_reset');
            const confirmPasswordReset = document.getElementById('confirm_password_reset');
            const matchIndicator = document.getElementById('password_match_indicator_customer');
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
    <?php if ($current_role === 'super_admin'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const actions = { edit: 'save these customer changes', reset_password: 'reset this customer password', block: 'block this customer', delete: 'permanently delete this customer' };
            document.querySelectorAll('#editForm, #resetPasswordForm, #blockCustomerForm, #deleteCustomerForm').forEach(function (form) {
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
