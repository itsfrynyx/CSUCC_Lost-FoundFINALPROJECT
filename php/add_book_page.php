<?php
require_once __DIR__ . '/includes/book_input_validation.php';
require_once __DIR__ . '/includes/upload_validation.php';
session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/rbac_helper.php';
require_once __DIR__ . '/includes/logger_helper.php';

if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

require_permission('books.create', 'admin_dashboard.php');

// Get admin data for sidebar
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

// Determine admin role for loading screen
$is_super_admin = ($admin_data['role'] === 'super_admin');
$admin_title = $is_super_admin ? 'Super Administrator' : 'Administrator';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("=== ADD BOOK PAGE FORM SUBMISSION ===");
    
    try {
        [$title, $author, $isbn, $description, $price, $stock_quantity, $is_active] = book_form_input();
    } catch (InvalidArgumentException $error) {
        $_SESSION['message'] = $error->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: add_book_page.php', true, 303);
        exit;
    }
    
    // Handle image upload with detailed logging
    $cover_image = null;
    $upload_success = false;
    $error_details = '';
    
    error_log("=== IMAGE UPLOAD DEBUG ===");
    
    if (!isset($_FILES['cover_image'])) {
        $error_details = "No file input found";
        error_log("ERROR: " . $error_details);
    } elseif ($_FILES['cover_image']['error'] !== UPLOAD_ERR_OK) {
        $error_details = "Upload error code: " . $_FILES['cover_image']['error'];
        error_log("ERROR: " . $error_details);
    } else {
        $upload_dir = __DIR__ . '/../uploads/books/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
            error_log("Created directory: " . $upload_dir);
        }
        
        $file_extension = validated_upload_extension($_FILES['cover_image'], ['jpg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
        $file_size = $_FILES['cover_image']['size'];
        
        error_log("File size: " . $file_size);
        
        if ($file_extension === null) {
            $error_details = 'Choose a valid JPG, PNG, GIF, or WebP image up to 5 MB.';
            error_log("ERROR: " . $error_details);
        } elseif ($file_size > 5242880) {
            $error_details = "File too large: " . $file_size . " bytes";
            error_log("ERROR: " . $error_details);
        } elseif ($file_size == 0) {
            $error_details = "File is empty (0 bytes)";
            error_log("ERROR: " . $error_details);
        } else {
            $cover_image = 'book_' . bin2hex(random_bytes(12)) . '.' . strtolower($file_extension);
            $target_path = $upload_dir . $cover_image;
            
            error_log("Target path: " . $target_path);
            
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $target_path)) {
                if (file_exists($target_path) && filesize($target_path) > 0) {
                    $upload_success = true;
                    error_log("SUCCESS! Image uploaded: " . $cover_image);
                } else {
                    $error_details = "File moved but is empty or missing";
                    error_log("ERROR: " . $error_details);
                    if (file_exists($target_path)) {
                        unlink($target_path);
                    }
                    $cover_image = null;
                }
            } else {
                $error_details = "Failed to move uploaded file";
                error_log("ERROR: " . $error_details);
                $cover_image = null;
            }
        }
    }
    
    // Insert into database only if upload succeeded
    if ($upload_success && !empty($cover_image)) {
        error_log("Inserting book with image: " . $cover_image);
        
        $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, description, price, stock_quantity, cover_image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssdisi", $title, $author, $isbn, $description, $price, $stock_quantity, $cover_image, $is_active);
        
        if ($stmt->execute()) {
            $book_id = $conn->insert_id;
            error_log("Book inserted! ID: " . $book_id);
            
            log_book_action('create', $book_id, $title);
            
            require_once __DIR__ . '/create_notification.php';
            notifyNewBook($conn, $book_id, $title, $author, $cover_image);
            
            $_SESSION['message'] = 'Book added successfully with cover image!';
            $_SESSION['message_type'] = 'success';
            header('Location: admin_manage_books.php?refresh=' . time());
            exit();
        } else {
            error_log("Database error: " . $conn->error);
            if (file_exists($upload_dir . $cover_image)) {
                unlink($upload_dir . $cover_image);
            }
            $_SESSION['message'] = 'Database error: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
            header('Location: add_book_page.php');
            exit();
        }
    } else {
        error_log("Upload failed: " . $error_details);
        $_SESSION['message'] = 'Image upload failed: ' . $error_details . '. Please try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: add_book_page.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Book - Campus Lost & Found Admin</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <style>
        /* Add Book Page Styles - Matching Unified Dashboard */
        :root {
            --primary: #3B82F6;
            --primary-dark: #2563EB;
            --success: #10B981;
            --success-dark: #059669;
            --warning: #F59E0B;
            --warning-dark: #D97706;
            --danger: #EF4444;
            --danger-dark: #DC2626;
            --dark: #1E293B;
            --light: #F8FAFC;
        }
        
        /* Remove extra spacing */
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .main-content {
            padding: 24px 30px;
            min-height: 100vh;
        }
        
        .top-header {
            margin-bottom: 24px;
        }
        
        /* Form Container */
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
        }
        
        .form-header {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #E2E8F0;
        }
        
        .form-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .form-header h2 i {
            color: var(--primary);
        }
        
        .form-header p {
            color: #64748B;
            font-size: 14px;
            margin: 0;
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-label i {
            color: var(--primary);
            font-size: 16px;
        }
        
        .form-label .required {
            color: var(--danger);
            margin-left: 4px;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
            background: white;
        }
        
        .form-control:hover, .form-select:hover {
            border-color: #CBD5E1;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        /* File Upload */
        .file-upload-wrapper {
            position: relative;
            border: 3px dashed #CBD5E1;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%);
            cursor: pointer;
            overflow: hidden;
        }
        
        .file-upload-wrapper::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .file-upload-wrapper:hover::before {
            opacity: 1;
        }
        
        .file-upload-wrapper:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(59, 130, 246, 0.15);
        }
        
        .file-upload-icon {
            font-size: 64px;
            color: var(--primary);
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
            position: relative;
            z-index: 1;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }
        
        .file-upload-content {
            position: relative;
            z-index: 1;
        }
        
        .file-upload-text {
            color: var(--dark);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .file-upload-hint {
            color: #64748B;
            font-size: 14px;
            margin-bottom: 16px;
        }
        
        .file-upload-specs {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        
        .upload-spec {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748B;
            font-size: 13px;
        }
        
        .upload-spec i {
            color: var(--primary);
            font-size: 16px;
        }
        
        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        
        .image-preview {
            margin-top: 24px;
            padding: 24px;
            background: white;
            border-radius: 16px;
            border: 2px solid var(--success);
            display: none;
            position: relative;
            animation: slideUp 0.4s ease;
            overflow: hidden;
        }
        
        .image-preview::-webkit-scrollbar {
            display: none;
        }
        
        .image-preview {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        
        .preview-image-container {
            overflow: hidden;
        }
        
        .preview-image-container::-webkit-scrollbar {
            display: none;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .preview-success {
            color: var(--success);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .preview-success i {
            font-size: 20px;
        }
        
        .btn-remove-image {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-remove-image:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
        }
        
        .preview-image-container {
            text-align: center;
            margin-bottom: 16px;
            overflow: hidden;
        }
        
        .preview-image-container::-webkit-scrollbar {
            display: none;
        }
        
        .image-preview img {
            max-width: 100%;
            max-height: 350px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border: 3px solid #F1F5F9;
            display: block;
            margin: 0 auto;
        }
        
        .preview-info {
            display: flex;
            justify-content: space-around;
            padding-top: 16px;
            border-top: 2px solid #F1F5F9;
        }
        
        .preview-info-item {
            text-align: center;
        }
        
        .preview-info-label {
            font-size: 12px;
            color: #94A3B8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .preview-info-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 8px;
            padding: 14px 32px;
            font-weight: 600;
            font-size: 14px;
            color: white;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: white;
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            padding: 14px 32px;
            font-weight: 600;
            font-size: 14px;
            color: #64748B;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary:hover {
            border-color: #CBD5E1;
            background: #F8FAFC;
            color: #64748B;
            transform: translateY(-2px);
        }
        
        /* Alert Messages */
        .alert {
            border-radius: 10px;
            border: 1px solid;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert i {
            font-size: 20px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
            color: var(--success-dark);
        }
        
        .alert-success i {
            color: var(--success);
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: var(--danger-dark);
        }
        
        .alert-danger i {
            color: var(--danger);
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #E2E8F0;
        }
        
        /* Tips Card */
        .tips-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            margin-bottom: 20px;
        }
        
        .tips-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #E2E8F0;
        }
        
        .tips-header i {
            font-size: 24px;
            color: #F59E0B;
        }
        
        .tips-header h5 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .tip-item {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #F1F5F9;
        }
        
        .tip-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .tip-item > i {
            color: var(--success);
            font-size: 18px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .tip-item strong {
            display: block;
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .tip-item p {
            margin: 0;
            color: #64748B;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #BFDBFE;
        }
        
        .info-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .info-header i {
            color: var(--primary);
            font-size: 18px;
        }
        
        .info-header h6 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .info-content p {
            color: #475569;
            font-size: 13px;
            margin-bottom: 12px;
        }
        
        .required-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .required-list li {
            color: #475569;
            font-size: 13px;
            padding: 6px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .required-list li:before {
            content: "â€¢";
            color: var(--primary);
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        
        /* Form Text Helper */
        .form-text {
            display: flex;
            align-items: center;
            font-size: 12px;
            margin-top: 6px;
        }
        
        /* Input Icons */
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon .form-control,
        .input-with-icon .form-select {
            padding-left: 44px;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 16px;
            pointer-events: none;
        }
        
        .input-with-icon .form-control:focus ~ i,
        .input-with-icon .form-select:focus ~ i {
            color: var(--primary);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .form-container {
                padding: 24px;
            }
            
            .form-header h2 {
                font-size: 20px;
            }
            
            .form-actions {
                flex-direction: column-reverse;
            }
            
            .form-actions button,
            .form-actions a {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<!-- Sidebar -->
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
                    <h4 class="mb-0">Add New Book</h4>
                    <small class="text-muted">Create New Inventory Item</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="admin_manage_books.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Books
                </a>
            </div>
        </div>
        
        <div class="container-fluid">
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-<?php echo $_SESSION['message_type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php 
                echo $_SESSION['message'];
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-8">
                <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-plus-circle"></i>Book Information</h2>
                <p>Fill in the details below to add a new book to the inventory</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-book"></i>
                                <span>Book Title<span class="required">*</span></span>
                            </label>
                            <input type="text" class="form-control" name="title" placeholder="Enter book title" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user"></i>
                                <span>Author<span class="required">*</span></span>
                            </label>
                            <input type="text" class="form-control" name="author" placeholder="Enter author name" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-peso-sign"></i>
                                <span>Price<span class="required">*</span></span>
                            </label>
                            <input type="number" class="form-control" name="price" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-boxes"></i>
                                <span>Stock Quantity<span class="required">*</span></span>
                            </label>
                            <input type="number" class="form-control" name="stock_quantity" placeholder="0" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-toggle-on"></i>
                                <span>Status<span class="required">*</span></span>
                            </label>
                            <select class="form-select" name="is_active" required>
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-barcode"></i>
                        <span>ISBN (Optional)</span>
                    </label>
                    <input type="text" class="form-control" name="isbn" placeholder="e.g., 978-3-16-148410-0" maxlength="17">
                    <small class="form-text text-muted">
                        <i class="fas fa-info-circle me-1"></i>Enter ISBN-10 or ISBN-13 format
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-align-left"></i>
                        <span>Description</span>
                    </label>
                    <textarea class="form-control" name="description" id="description" rows="4" placeholder="Enter a compelling book description..." maxlength="1000"></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>Provide a detailed description to attract readers
                        </small>
                        <small class="text-muted">
                            <span id="charCount">0</span>/1000 characters
                        </small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-image"></i>
                        <span>Book Cover<span class="required">*</span></span>
                    </label>
                    <div class="file-upload-wrapper" id="uploadWrapper">
                        <div class="file-upload-content">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">Click or drag to upload cover image</div>
                            <div class="file-upload-hint">Upload a high-quality book cover for better presentation</div>
                            <div class="file-upload-specs">
                                <div class="upload-spec">
                                    <i class="fas fa-file-image"></i>
                                    <span>JPG, PNG, GIF, WEBP</span>
                                </div>
                                <div class="upload-spec">
                                    <i class="fas fa-weight"></i>
                                    <span>Max 5MB</span>
                                </div>
                                <div class="upload-spec">
                                    <i class="fas fa-expand-arrows-alt"></i>
                                    <span>Recommended: 600x900px</span>
                                </div>
                            </div>
                        </div>
                        <input type="file" id="coverImage" name="cover_image" class="file-input" accept="image/*" required onchange="previewImage(this)">
                    </div>
                    <div class="image-preview" id="imagePreview">
                        <div class="preview-header">
                            <div class="preview-success">
                                <i class="fas fa-check-circle"></i>
                                <span>Image uploaded successfully!</span>
                            </div>
                            <button type="button" class="btn-remove-image" onclick="removeImage(event)">
                                <i class="fas fa-times me-1"></i>Remove
                            </button>
                        </div>
                        <div class="preview-image-container">
                            <img id="preview" alt="Book Cover Preview">
                        </div>
                        <div class="preview-info">
                            <div class="preview-info-item">
                                <div class="preview-info-label">File Name</div>
                                <div class="preview-info-value" id="fileName">-</div>
                            </div>
                            <div class="preview-info-item">
                                <div class="preview-info-label">File Size</div>
                                <div class="preview-info-value" id="fileSize">-</div>
                            </div>
                            <div class="preview-info-item">
                                <div class="preview-info-label">Dimensions</div>
                                <div class="preview-info-value" id="fileDimensions">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="admin_manage_books.php" class="btn-secondary">
                        <i class="fas fa-times"></i>
                        <span>Cancel</span>
                    </a>
                    <button type="submit" class="btn-primary" id="submitBtn">
                        <i class="fas fa-plus-circle"></i>
                        <span>Add Book</span>
                    </button>
                </div>
            </form>
        </div>
        </div><!-- End col-lg-8 -->
        
        <div class="col-lg-4">
            <!-- Quick Tips Card -->
            <div class="tips-card">
                <div class="tips-header">
                    <i class="fas fa-lightbulb"></i>
                    <h5>Quick Tips</h5>
                </div>
                <div class="tips-content">
                    <div class="tip-item">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>High-Quality Images</strong>
                            <p>Use clear, professional book cover images for better customer engagement</p>
                        </div>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Accurate Pricing</strong>
                            <p>Double-check the price before adding to avoid customer confusion</p>
                        </div>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Detailed Description</strong>
                            <p>Include genre, themes, and key highlights to help customers decide</p>
                        </div>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Stock Management</strong>
                            <p>Set realistic stock quantities and update regularly</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Required Fields Info -->
            <div class="info-card mt-3">
                <div class="info-header">
                    <i class="fas fa-info-circle"></i>
                    <h6>Required Fields</h6>
                </div>
                <div class="info-content">
                    <p>Fields marked with <span class="text-danger">*</span> are mandatory and must be filled before submitting.</p>
                    <ul class="required-list">
                        <li>Book Title</li>
                        <li>Author Name</li>
                        <li>Price</li>
                        <li>Stock Quantity</li>
                        <li>Book Cover Image</li>
                        <li>Status (Active/Inactive)</li>
                    </ul>
                </div>
            </div>
        </div><!-- End col-lg-4 -->
        </div><!-- End row -->
        
        </div><!-- End container-fluid -->
    </div><!-- End main-content -->
    
    <script>
        // Hide loading overlay
        window.addEventListener('load', function() {
            setTimeout(function() {
                document.getElementById('loadingOverlay').classList.add('hide');
            }, 1000);
        });
        
        // Character counter for description
        const descriptionField = document.getElementById('description');
        const charCount = document.getElementById('charCount');
        
        if (descriptionField && charCount) {
            descriptionField.addEventListener('input', function() {
                charCount.textContent = this.value.length;
                
                // Change color based on character count
                if (this.value.length > 900) {
                    charCount.style.color = '#EF4444';
                } else if (this.value.length > 700) {
                    charCount.style.color = '#F59E0B';
                } else {
                    charCount.style.color = '#64748B';
                }
            });
        }
        
        // Form validation before submit
        const form = document.querySelector('form');
        const submitBtn = document.getElementById('submitBtn');
        
        form.addEventListener('submit', function(e) {
            // Disable submit button to prevent double submission
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Adding Book...</span>';
            
            // Check if image is uploaded
            const coverImage = document.getElementById('coverImage');
            if (!coverImage.files || coverImage.files.length === 0) {
                e.preventDefault();
                alert('Please upload a book cover image');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i><span>Add Book</span>';
                return false;
            }
        });
        
        // Image preview with file info
        function previewImage(input) {
            const preview = document.getElementById('preview');
            const previewContainer = document.getElementById('imagePreview');
            const uploadWrapper = document.getElementById('uploadWrapper');
            const fileNameEl = document.getElementById('fileName');
            const fileSizeEl = document.getElementById('fileSize');
            const fileDimensionsEl = document.getElementById('fileDimensions');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Display file name
                fileNameEl.textContent = file.name;
                
                // Display file size
                const sizeInKB = (file.size / 1024).toFixed(2);
                const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
                fileSizeEl.textContent = sizeInMB > 1 ? `${sizeInMB} MB` : `${sizeInKB} KB`;
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    
                    // Get image dimensions
                    const img = new Image();
                    img.onload = function() {
                        fileDimensionsEl.textContent = `${this.width} Ã— ${this.height}px`;
                    };
                    img.src = e.target.result;
                    
                    // Show preview and hide upload area
                    previewContainer.style.display = 'block';
                    uploadWrapper.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Remove image
        function removeImage(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            const input = document.getElementById('coverImage');
            const previewContainer = document.getElementById('imagePreview');
            const uploadWrapper = document.getElementById('uploadWrapper');
            
            input.value = '';
            previewContainer.style.display = 'none';
            uploadWrapper.style.display = 'block';
            
            // Reset preview info
            document.getElementById('fileName').textContent = '-';
            document.getElementById('fileSize').textContent = '-';
            document.getElementById('fileDimensions').textContent = '-';
        }
        
        // Upload wrapper click handler
        const uploadWrapper = document.getElementById('uploadWrapper');
        uploadWrapper.addEventListener('click', function(e) {
            // Only trigger file input if clicking on the wrapper itself, not the file input
            if (e.target !== document.getElementById('coverImage')) {
                document.getElementById('coverImage').click();
            }
        });
        
        // Drag and drop functionality
        uploadWrapper.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = 'var(--primary)';
            this.style.background = 'linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%)';
        });
        
        uploadWrapper.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#CBD5E1';
            this.style.background = 'linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%)';
        });
        
        uploadWrapper.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#CBD5E1';
            this.style.background = 'linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%)';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('coverImage').files = files;
                previewImage(document.getElementById('coverImage'));
            }
        });
    </script>
</body>
</html>

