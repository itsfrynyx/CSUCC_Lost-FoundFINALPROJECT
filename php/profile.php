<?php
require_once __DIR__ . '/includes/account_input_validation.php';
require_once __DIR__ . '/includes/upload_validation.php';
session_start();
if (!isset($_SESSION['user_id'])) { 
    header("Location: Sign_in.php"); 
    exit(); 
}

require_once __DIR__ . '/../DB/conn.php';

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

$has_user_profiles = false;
$user_profiles_check = $conn->query("SHOW TABLES LIKE 'user_profiles'");
if (!$user_profiles_check || $user_profiles_check->num_rows === 0) {
    $create_user_profiles_sql = "
        CREATE TABLE IF NOT EXISTS user_profiles (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            profile_picture VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_profile (user_id),
            KEY idx_user_id (user_id),
            CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES infos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $conn->query($create_user_profiles_sql);
    $user_profiles_check = $conn->query("SHOW TABLES LIKE 'user_profiles'");
}
$has_user_profiles = $user_profiles_check && $user_profiles_check->num_rows > 0;

$profile_question_options = [
    ['What was your first pet\'s name?', 'What is your mother\'s maiden name?', 'What city were you born in?', 'What is your favorite food?', 'What elementary school did you attend?'],
    ['What was your first car?', 'What is your best friend\'s name?', 'What is your favorite movie?', 'What is your dream job?', 'What is your favorite color?'],
    ['What is your favorite book?', 'What is your favorite sport?', 'What is your favorite holiday?', 'What is your first teacher\'s name?', 'What is your favorite song?'],
];
$_SESSION['profile_form_token'] ??= bin2hex(random_bytes(32));
$success_message = $_SESSION['profile_form_success'] ?? '';
unset($_SESSION['profile_form_success']);
$profile_fields = ['firstname' => 250, 'lastname' => 250, 'email' => 250, 'phone' => 50, 'address' => 1000, 'city' => 100, 'country' => 100, 'postal_code' => 20];
$profile_input = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($profile_fields as $field => $limit) {
        $profile_input[$field] = account_input($field);
        $value = $field === 'address' ? str_replace(["\r", "\n", "\t"], ' ', $profile_input[$field]) : $profile_input[$field];
        if (!account_text_is_valid($value, $limit) || (isset($_POST[$field]) && !is_string($_POST[$field]))) {
            $error_message = 'Check the length and format of your profile information.';
        }
    }
    ['firstname' => $firstname, 'lastname' => $lastname, 'email' => $email, 'phone' => $phone, 'address' => $address, 'city' => $city, 'country' => $country, 'postal_code' => $postal_code] = $profile_input;

    if (!hash_equals($_SESSION['profile_form_token'], account_input('profile_token', false))) {
        $error_message = 'This form expired. Reload the page and try again.';
    } elseif (!account_name_is_valid($firstname) || !account_name_is_valid($lastname)) {
        $error_message = 'Enter your first and last names using valid name characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Enter a valid email address.';
    } elseif ($phone !== '' && !account_phone_is_valid($phone)) {
        $error_message = 'Enter a phone number with 7 to 15 digits.';
    } elseif ($postal_code !== '' && !account_postal_is_valid($postal_code, $country)) {
        $error_message = 'Enter a valid postal code. Philippine postal codes have four digits.';
    }
    $questions = [account_input('security_q1'), account_input('security_q2'), account_input('security_q3')];
    $answers = [account_input('security_a1'), account_input('security_a2'), account_input('security_a3')];
    $update_security = $answers !== ['', '', ''];
    if ($update_security && $error_message === '') {
        foreach ($questions as $index => $question) {
            if (!in_array($question, $profile_question_options[$index], true) || $answers[$index] === ''
                || !account_text_is_valid($answers[$index], 72)) {
                $error_message = 'Choose three listed security questions and enter answers up to 72 bytes each.';
                break;
            }
        }
        if (count(array_unique($questions)) !== 3) {
            $error_message = 'Choose three different security questions.';
        }
    }

    $file = $_FILES['avatar'] ?? null;
    $extension = null;
    if ($file !== null && ($file['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
        $extension = validated_upload_extension($file, ['jpg', 'png', 'gif'], 5 * 1024 * 1024);
        if ($extension === null && $error_message === '') {
            $error_message = 'Choose a valid JPG, PNG, or GIF image up to 5 MB.';
        }
    }

    if ($error_message === '') {
        $newPath = null;
        $profilePicture = null;
        $oldPicture = null;
        $conn->begin_transaction();
        try {
            if ($extension !== null) {
                $directory = __DIR__ . '/../uploads/avatars/';
                $filename = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
                if (!$has_user_profiles || (!is_dir($directory) && !mkdir($directory, 0775, true))
                    || !move_uploaded_file($file['tmp_name'], $directory . $filename)) {
                    throw new RuntimeException('Unable to save the uploaded image.');
                }
                $newPath = $directory . $filename;
                $profilePicture = 'uploads/avatars/' . $filename;
                $old = $conn->prepare('SELECT profile_picture FROM user_profiles WHERE user_id = ? FOR UPDATE');
                $old->bind_param('i', $user_id);
                $old->execute();
                $oldPicture = $old->get_result()->fetch_assoc()['profile_picture'] ?? null;
            }
            if ($update_security) {
                [$hash1, $hash2, $hash3] = array_map(static fn($answer) => password_hash($answer, PASSWORD_DEFAULT), $answers);
                $stmt = $conn->prepare('UPDATE infos SET firstname = ?, lastname = ?, email = ?, security_q1 = ?, security_a1 = ?, security_q2 = ?, security_a2 = ?, security_q3 = ?, security_a3 = ? WHERE id = ?');
                $stmt->bind_param('sssssssssi', $firstname, $lastname, $email, $questions[0], $hash1, $questions[1], $hash2, $questions[2], $hash3, $user_id);
            } else {
                $stmt = $conn->prepare('UPDATE infos SET firstname = ?, lastname = ?, email = ? WHERE id = ?');
                $stmt->bind_param('sssi', $firstname, $lastname, $email, $user_id);
            }
            $stmt->execute();
            if ($has_user_profiles) {
                $stmt = $conn->prepare('INSERT INTO user_profiles (user_id, phone, address, city, country, postal_code, profile_picture)
                    VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address),
                    city = VALUES(city), country = VALUES(country), postal_code = VALUES(postal_code),
                    profile_picture = COALESCE(VALUES(profile_picture), profile_picture)');
                $stmt->bind_param('issssss', $user_id, $phone, $address, $city, $country, $postal_code, $profilePicture);
                $stmt->execute();
            }
            $conn->commit();
            if (is_string($oldPicture) && preg_match('~^uploads/avatars/[A-Za-z0-9_-]+[.](jpg|jpeg|png|gif)$~iD', $oldPicture)
                && is_file(__DIR__ . '/../' . $oldPicture)) {
                unlink(__DIR__ . '/../' . $oldPicture);
            }
            $_SESSION['profile_form_token'] = bin2hex(random_bytes(32));
            $_SESSION['profile_form_success'] = 'Profile updated successfully.';
            header('Location: profile.php', true, 303);
            exit;
        } catch (Throwable $error) {
            $conn->rollback();
            if ($newPath !== null && is_file($newPath)) {
                unlink($newPath);
            }
            error_log('Profile save failed: ' . $error->getMessage());
            $error_message = $error instanceof mysqli_sql_exception && $error->getCode() === 1062
                ? 'That email address is already used by another account.'
                : 'Unable to update your profile. Please try again.';
        }
    }
}

// Get user data including security questions
if ($has_user_profiles) {
    $stmt = $conn->prepare("SELECT i.*, up.phone, up.address, up.city, up.country, up.postal_code, up.profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
} else {
    $stmt = $conn->prepare("SELECT i.* FROM infos i WHERE i.id = ?");
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Check if user has security questions set
$has_security_questions = !empty($user['security_q1']) && !empty($user['security_q2']) && !empty($user['security_q3']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif; 
            background: #F8F9FA; 
            padding-top: 70px;
            color: #2D3748;
            line-height: 1.4;
        }
        
        .main-content { 
            margin-left: 260px; 
            padding: 16px 24px 32px; 
            max-width: 1200px;
            width: calc(100% - 260px);
            min-height: calc(100vh - 70px);
        }
        
        /* Breadcrumb - Compact */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
            font-size: 0.75rem;
            color: #718096;
        }
        .breadcrumb-nav a {
            color: #718096;
            text-decoration: none;
            transition: color 0.15s;
        }
        .breadcrumb-nav a:hover { color: #2D3748; }
        .breadcrumb-nav .current { color: #2D3748; font-weight: 500; }
        
        /* Page Header - Compact */
        .page-header {
            margin-bottom: 16px;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1A202C;
            margin-bottom: 2px;
        }
        .page-header p {
            color: #718096;
            font-size: 0.813rem;
        }
        
        /* Alert Messages - Compact */
        .alert {
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.813rem;
            border-left: 3px solid;
        }
        .alert-success {
            background: #F0FDF4;
            color: #166534;
            border-color: #22C55E;
        }
        .alert-error {
            background: #FEF2F2;
            color: #991B1B;
            border-color: #EF4444;
        }
        .alert-warning {
            background: #FFFBEB;
            color: #92400E;
            border-color: #F59E0B;
        }
        
        /* Profile Layout - Compact */
        .profile-layout {
            max-width: 100%;
        }
        
        /* Profile Card - Compact */
        .profile-card {
            background: white;
            border-radius: 6px;
            padding: 16px 20px;
            margin-bottom: 12px;
            border: 1px solid #E2E8F0;
        }
        
        .avatar-section {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #E2E8F0;
        }
        .avatar-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #1E3A5F;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
        .avatar-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar-wrapper i {
            font-size: 1.5rem;
            color: white;
        }
        .avatar-upload-btn {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 22px;
            height: 22px;
            background: #1E3A5F;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            border: 2px solid white;
            transition: background 0.15s;
            font-size: 0.7rem;
        }
        .avatar-upload-btn:hover { background: #2C5282; }
        .avatar-upload-btn input { display: none; }
        
        .profile-info {
            flex: 1;
        }
        .profile-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1A202C;
            margin-bottom: 2px;
        }
        .profile-email {
            font-size: 0.813rem;
            color: #718096;
        }
        
        .profile-stats {
            display: flex;
            gap: 20px;
            padding-top: 12px;
        }
        .stat-item {
            text-align: left;
        }
        .stat-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1A202C;
            display: block;
            line-height: 1;
        }
        .stat-label {
            font-size: 0.688rem;
            color: #718096;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        // Check if orders table exists for showing order stats
        $orders_check = $conn->query("SHOW TABLES LIKE 'orders'");
        $has_orders_table = $orders_check && $orders_check->num_rows > 0;
        
        $orders_count = 0;
        if ($has_orders_table) {
            $orders_result = $conn->query("SELECT COUNT(*) as c FROM orders WHERE user_id = $user_id");
            $orders_count = $orders_result ? ($orders_result->fetch_assoc()['c'] ?? 0) : 0;
        }
        
        /* Edit Form - Compact */
        .edit-form-card {
            background: white;
            border-radius: 6px;
            padding: 20px;
            border: 1px solid #E2E8F0;
        }
        
        .form-section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E2E8F0;
        }
        .form-section:last-of-type {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        .section-title {
            font-size: 0.813rem;
            font-weight: 600;
            color: #1A202C;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 0;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 0;
        }
        .form-group {
            margin-bottom: 12px;
        }
        .form-group:last-child {
            margin-bottom: 0;
        }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            color: #4A5568;
            margin-bottom: 4px;
        }
        .form-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #E2E8F0;
            border-radius: 4px;
            font-size: 0.813rem;
            color: #2D3748;
            transition: border-color 0.15s, box-shadow 0.15s;
            background: white;
        }
        .form-input:focus {
            outline: none;
            border-color: #1E3A5F;
            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.08);
        }
        textarea.form-input {
            resize: vertical;
            min-height: 60px;
        }
        .form-hint {
            font-size: 0.688rem;
            color: #718096;
            margin-top: 2px;
            display: block;
        }
        
        .security-info {
            background: #F7FAFC;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #4A5568;
            margin-bottom: 12px;
            border-left: 3px solid #CBD5E0;
        }
        
        .form-actions {
            display: flex;
            gap: 8px;
            padding-top: 16px;
        }
        .btn {
            padding: 8px 20px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.813rem;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: #1E3A5F;
            color: white;
        }
        .btn-primary:hover {
            background: #2C5282;
        }
        .btn-secondary {
            background: white;
            color: #4A5568;
            border: 1px solid #E2E8F0;
        }
        .btn-secondary:hover {
            background: #F7FAFC;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 12px;
                width: 100%;
            }
            .profile-card, .edit-form-card {
                padding: 16px;
            }
            .avatar-section {
                flex-direction: column;
                text-align: center;
            }
            .profile-stats {
                justify-content: center;
            }
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <!-- Breadcrumb -->
    <nav class="breadcrumb-nav">
        <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
        <span>/</span>
        <span class="current"><i class="fas fa-user"></i> My Profile</span>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <h1>My Profile</h1>
        <p>Manage your personal information and security settings</p>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-times-circle"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- Profile Layout -->
    <div class="profile-layout">
        <!-- Profile Card -->
        <div class="profile-card">
            <div class="avatar-section">
                <div class="avatar-wrapper" id="avatarPreview">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <?php 
                        // Handle both path formats
                        $avatar_path = $user['profile_picture'];
                        if (strpos($avatar_path, '../') !== 0 && strpos($avatar_path, 'uploads/') === 0) {
                            $avatar_path = '../' . $avatar_path;
                        }
                        ?>
                        <img src="<?= htmlspecialchars($avatar_path) ?>" alt="Avatar" onerror="this.hidden=true">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                    <label class="avatar-upload-btn" for="avatarInput">
                        <i class="fas fa-camera"></i>
                        <input type="file" id="avatarInput" accept=".jpg,.jpeg,.png,.gif" aria-describedby="avatarValidation">
                    </label>
                </div>
                <small id="avatarValidation" role="status"></small>
                <div class="profile-info">
                    <div class="profile-name"><?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?></div>
                    <div class="profile-email"><?= htmlspecialchars($profile_input['email'] ?? $user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
            </div>
            
            <div class="profile-stats">
                <?php
                // Check if orders table exists before querying
                $orders_count = 0;
                $orders_check = $conn->query("SHOW TABLES LIKE 'orders'");
                if ($orders_check && $orders_check->num_rows > 0) {
                    $orders_result = $conn->query("SELECT COUNT(*) as c FROM orders WHERE user_id = $user_id");
                    $orders_count = $orders_result ? ($orders_result->fetch_assoc()['c'] ?? 0) : 0;
                }
                $profit_count = 0; // Placeholder
                $return_count = 0; // Placeholder
                
                // Check if wishlist table exists before querying
                $wishlist_count = 0;
                $check_wishlist = $conn->query("SHOW TABLES LIKE 'wishlist'");
                if ($check_wishlist && $check_wishlist->num_rows > 0) {
                    $wishlist_result = $conn->query("SELECT COUNT(*) as c FROM wishlist WHERE user_id = $user_id");
                    $wishlist_count = $wishlist_result ? ($wishlist_result->fetch_assoc()['c'] ?? 0) : 0;
                }
                ?>
                <div class="stat-item">
                    <span class="stat-value"><?= $orders_count ?></span>
                    <span class="stat-label">Orders</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= $wishlist_count ?></span>
                    <span class="stat-label">Wishlist</span>
                </div>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="edit-form-card">
            <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                <input type="hidden" name="profile_token" value="<?= htmlspecialchars($_SESSION['profile_form_token']) ?>">
                <input type="file" name="avatar" id="avatarFileInput" style="display: none;">
                
                <!-- Personal Information -->
                <div class="form-section">
                    <h3 class="section-title">Personal Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="firstname" class="form-input" value="<?= htmlspecialchars($profile_input['firstname'] ?? $user['firstname'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="lastname" class="form-input" value="<?= htmlspecialchars($profile_input['lastname'] ?? $user['lastname'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($profile_input['email'] ?? $user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($profile_input['phone'] ?? $user['phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="form-section">
                    <h3 class="section-title">Address</h3>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-input" value="<?= htmlspecialchars($profile_input['city'] ?? $user['city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" class="form-input" value="<?= htmlspecialchars($profile_input['postal_code'] ?? $user['postal_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" class="form-input" value="<?= htmlspecialchars($user['country'] ?? 'Philippines') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Street Address</label>
                        <textarea name="address" class="form-input"><?= htmlspecialchars($profile_input['address'] ?? $user['address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <!-- Security Questions Section -->
                <div class="form-section">
                    <h3 class="section-title">Security Questions</h3>
                    
                    <?php if (!$has_security_questions): ?>
                        <div class="alert alert-warning" style="margin-bottom: 12px;">
                            <i class="fas fa-shield-alt"></i>
                            Set up security questions for account recovery
                        </div>
                    <?php endif; ?>
                    
                    <div class="security-info">
                        <i class="fas fa-info-circle"></i> Choose questions only you know the answer to
                    </div>
                    
                    <div class="form-row">
                        <!-- Question 1 -->
                        <div class="form-group">
                            <label class="form-label">Question 1</label>
                            <select name="security_q1" id="security_q1" class="form-input">
                                <option value="">Select a question</option>
                                <?php foreach ($profile_question_options[0] as $question): ?>
                                    <option value="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? account_input('security_q1') : ($user['security_q1'] ?? '')) === $question ? 'selected' : '' ?>><?= htmlspecialchars($question) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Answer 1</label>
                            <input type="text" name="security_a1" id="security_a1" class="form-input" maxlength="50" placeholder="Your answer">
                            <?php if ($has_security_questions): ?>
                                <span class="form-hint">Leave blank to keep current</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <!-- Question 2 -->
                        <div class="form-group">
                            <label class="form-label">Question 2</label>
                            <select name="security_q2" id="security_q2" class="form-input">
                                <option value="">Select a question</option>
                                <?php foreach ($profile_question_options[1] as $question): ?>
                                    <option value="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? account_input('security_q2') : ($user['security_q2'] ?? '')) === $question ? 'selected' : '' ?>><?= htmlspecialchars($question) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Answer 2</label>
                            <input type="text" name="security_a2" id="security_a2" class="form-input" maxlength="50" placeholder="Your answer">
                            <?php if ($has_security_questions): ?>
                                <span class="form-hint">Leave blank to keep current</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <!-- Question 3 -->
                        <div class="form-group">
                            <label class="form-label">Question 3</label>
                            <select name="security_q3" id="security_q3" class="form-input">
                                <option value="">Select a question</option>
                                <?php foreach ($profile_question_options[2] as $question): ?>
                                    <option value="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? account_input('security_q3') : ($user['security_q3'] ?? '')) === $question ? 'selected' : '' ?>><?= htmlspecialchars($question) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Answer 3</label>
                            <input type="text" name="security_a3" id="security_a3" class="form-input" maxlength="50" placeholder="Your answer">
                            <?php if ($has_security_questions): ?>
                                <span class="form-hint">Leave blank to keep current</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save Changes
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.reload()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const avatarInput = document.getElementById('avatarInput');
    const avatarFile = document.getElementById('avatarFileInput');
    const avatarValidation = document.getElementById('avatarValidation');
    let previewUrl;
    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files[0];
        avatarValidation.textContent = '';
        avatarFile.value = '';
        if (!file) return;
        const allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (file.size === 0 || file.size > 5 * 1024 * 1024 || !allowed.includes(file.type) || !/\.(jpe?g|png|gif)$/i.test(file.name)) {
            avatarValidation.textContent = 'Choose a JPG, PNG, or GIF image up to 5 MB.';
            avatarInput.value = '';
            return;
        }

        const wrapper = document.getElementById('avatarPreview');
        let image = wrapper.querySelector('img');
        if (!image) {
            image = document.createElement('img');
            image.alt = 'Profile preview';
            const icon = wrapper.querySelector(':scope > i');
            if (icon) icon.remove();
            wrapper.prepend(image);
        }
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = URL.createObjectURL(file);
        image.src = previewUrl;
        image.hidden = false;
        avatarFile.files = avatarInput.files;
    });

    const profileForm = document.getElementById('profileForm');
    const questions = [1, 2, 3].map(index => profileForm.elements['security_q' + index]);
    const answers = [1, 2, 3].map(index => profileForm.elements['security_a' + index]);
    const validateProfile = () => {
        const changingQuestions = answers.some(answer => answer.value.trim() !== '');
        const selected = questions.map(question => question.value);
        questions.forEach(question => {
            const duplicate = question.value && selected.filter(value => value === question.value).length > 1;
            question.setCustomValidity(changingQuestions && (!question.value || duplicate) ? 'Choose three different questions.' : '');
        });
        answers.forEach(answer => {
            answer.setCustomValidity(changingQuestions && !answer.value.trim() ? 'Enter an answer for this question.' : '');
        });
    };
    profileForm.addEventListener('input', validateProfile);
    profileForm.addEventListener('submit', event => {
        validateProfile();
        if (!profileForm.reportValidity()) {
            event.preventDefault();
        }
    });
</script>
</body>
</html>

