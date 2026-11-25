<?php
require_once __DIR__ . '/includes/book_input_validation.php';
require_once __DIR__ . '/includes/upload_validation.php';
// VERSION CHECK: 2026-02-21-12:01 - NEW CODE WITH LOGGING
session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/rbac_helper.php';
require_once __DIR__ . '/includes/logger_helper.php';
require_once __DIR__ . '/includes/pagination_helper.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// RBAC: Require permission to view books
require_permission('books.view', 'admin_dashboard.php');

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();
$is_super_admin = $admin_data['role'] === 'super_admin';

// Set dashboard URL
$dashboard_url = 'unified_admin_dashboard.php';

// Handle AJAX requests 
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_book' && isset($_GET['id'])) {
        $book_id = intval($_GET['id']);
        $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $book = $stmt->get_result()->fetch_assoc();
        echo json_encode($book);
        exit();
    }
    
    if ($_GET['ajax'] === 'get_stats') {
        $stats = [];
        $stats['total'] = $conn->query("SELECT COUNT(*) as count FROM books")->fetch_assoc()['count'];
        $stats['active'] = $conn->query("SELECT COUNT(*) as count FROM books WHERE is_active = 1")->fetch_assoc()['count'];
        $stats['low_stock'] = $conn->query("SELECT COUNT(*) as count FROM books WHERE stock_quantity < 10")->fetch_assoc()['count'];
        echo json_encode($stats);
        exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add') {
        // RBAC: Check permission to create books
        if (!can('books.create')) {
            $_SESSION['message'] = 'You do not have permission to add books.';
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php');
            exit();
        }
        
        // Add new book
        try {
            [$title, $author, $isbn, $description, $price, $stock_quantity, $is_active] = book_form_input();
        } catch (InvalidArgumentException $error) {
            $_SESSION['message'] = $error->getMessage();
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php', true, 303);
            exit;
        }
        
        // Handle image upload with detailed logging
        $cover_image = null;
        $upload_success = false;
        $error_details = '';
        
        error_log("=== ADD BOOK IMAGE UPLOAD ===");
        
        if (!isset($_FILES['cover_image'])) {
            $error_details = "No file input";
        } elseif ($_FILES['cover_image']['error'] !== UPLOAD_ERR_OK) {
            $error_details = "Upload error: " . $_FILES['cover_image']['error'];
        } else {
            $upload_dir = __DIR__ . '/../uploads/books/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = validated_upload_extension($_FILES['cover_image'], ['jpg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
            $file_size = $_FILES['cover_image']['size'];
            
            if ($file_extension === null) {
                $error_details = 'Choose a valid JPG, PNG, GIF, or WebP image up to 5 MB.';
            } elseif ($file_size > 5242880) {
                $error_details = "File too large";
            } elseif ($file_size == 0) {
                $error_details = "Empty file";
            } else {
                $cover_image = 'book_' . bin2hex(random_bytes(12)) . '.' . strtolower($file_extension);
                
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_dir . $cover_image)) {
                    if (file_exists($upload_dir . $cover_image) && filesize($upload_dir . $cover_image) > 0) {
                        $upload_success = true;
                        error_log("SUCCESS: " . $cover_image);
                    } else {
                        $error_details = "File empty after move";
                        if (file_exists($upload_dir . $cover_image)) {
                            unlink($upload_dir . $cover_image);
                        }
                        $cover_image = null;
                    }
                } else {
                    $error_details = "Move failed";
                }
            }
        }
        
        // Only insert if image uploaded successfully
        if (!$upload_success || empty($cover_image)) {
            error_log("Upload failed: " . $error_details);
            $_SESSION['message'] = 'Image upload failed: ' . $error_details;
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php');
            exit();
        }
        
        $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, description, price, stock_quantity, cover_image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssdisi", $title, $author, $isbn, $description, $price, $stock_quantity, $cover_image, $is_active);
        
        if ($stmt->execute()) {
            $book_id = $conn->insert_id;
            
            // VERIFICATION: Immediately check what was saved
            $verify_stmt = $conn->prepare("SELECT id, title, cover_image FROM books WHERE id = ?");
            $verify_stmt->bind_param("i", $book_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result()->fetch_assoc();
            error_log("VERIFICATION: Book ID {$book_id} saved with cover_image = " . var_export($verify_result['cover_image'], true));
            
            // Log book creation
            log_book_action('create', $book_id, $title);
            
            // Automatically notify all customers about the new book
            require_once __DIR__ . '/create_notification.php';
            notifyNewBook($conn, $book_id, $title, $author, $cover_image);
            
            // NO Super Admin notification for adding books (not critical)
            
            $_SESSION['message'] = 'Book added successfully with cover image! All customers have been notified.';
            $_SESSION['message_type'] = 'success';
            $_SESSION['new_book_added'] = true; // Flag to scroll to new book
        } else {
            $_SESSION['message'] = 'Error adding book: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
        
        // Force page reload with cache busting to show new image
        header('Location: admin_manage_books.php?refresh=' . time());
        exit();
    }
    
    elseif ($action == 'edit') {
        // RBAC: Check permission to edit books
        if (!can('books.edit')) {
            $_SESSION['message'] = 'You do not have permission to edit books.';
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php');
            exit();
        }
        
        // Edit existing book
        $book_id = account_positive_integer($_POST['book_id'] ?? null);
        try {
            [$title, $author, $isbn, $description, $price, $stock_quantity, $is_active] = book_form_input();
        } catch (InvalidArgumentException $error) {
            $_SESSION['message'] = $error->getMessage();
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php', true, 303);
            exit;
        }
        
        // Get old book data for comparison
        $old_book_stmt = $conn->prepare("SELECT price, stock_quantity, cover_image FROM books WHERE id = ?");
        $old_book_stmt->bind_param("i", $book_id);
        $old_book_stmt->execute();
        $old_book = $old_book_stmt->get_result()->fetch_assoc();
        if (!$old_book) {
            $_SESSION['message'] = 'Choose an existing book.';
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php', true, 303);
            exit;
        }
        $old_price = $old_book['price'];
        $old_stock = $old_book['stock_quantity'];
        $current_cover_image = $old_book['cover_image'];
        
        $update_image = false;
        $upload = $_FILES['cover_image'] ?? null;
        if ($upload !== null && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
            $extension = validated_upload_extension($upload, ['jpg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
            $directory = __DIR__ . '/../uploads/books/';
            $cover_image = $extension === null ? '' : 'book_' . bin2hex(random_bytes(12)) . '.' . $extension;
            if ($extension === null || (!is_dir($directory) && !mkdir($directory, 0775, true)) ||
                !move_uploaded_file($upload['tmp_name'], $directory . $cover_image)) {
                $_SESSION['message'] = 'Choose a valid JPG, PNG, GIF, or WebP cover up to 5 MB.';
                $_SESSION['message_type'] = 'danger';
                header('Location: admin_manage_books.php', true, 303);
                exit;
            }
            $update_image = true;
        }

        if ($update_image) {
            $stmt = $conn->prepare("UPDATE books SET title=?, author=?, isbn=?, description=?, price=?, stock_quantity=?, cover_image=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssssdisii", $title, $author, $isbn, $description, $price, $stock_quantity, $cover_image, $is_active, $book_id);
        } else {
            $stmt = $conn->prepare("UPDATE books SET title=?, author=?, isbn=?, description=?, price=?, stock_quantity=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssssdiii", $title, $author, $isbn, $description, $price, $stock_quantity, $is_active, $book_id);
        }
        
        if ($stmt->execute()) {
            // Log book edit
            log_book_action('edit', $book_id, $title);
            
            // Send notifications based on changes
            require_once __DIR__ . '/create_notification.php';
            
            // Determine which cover image to use (new one if uploaded, otherwise current)
            $notification_cover = $update_image ? $cover_image : $current_cover_image;
            
            // Notify about price drop (only if price decreased by at least 5%)
            if ($price < $old_price && (($old_price - $price) / $old_price) >= 0.05) {
                notifyPriceDrop($conn, $book_id, $title, $old_price, $price, $notification_cover);
            }
            
            // Notify about low stock (if stock is now 5 or less and was higher before)
            if ($stock_quantity <= 5 && $stock_quantity > 0 && $old_stock > 5) {
                notifyLowStock($conn, $book_id, $title, $stock_quantity, $notification_cover);
            }
            
            // Notify Super Admin about significant book edits (CRITICAL ONLY)
            if ($admin_data['role'] !== 'super_admin') {
                require_once __DIR__ . '/includes/superadmin_notifier_helper.php';
                $admin_name = $admin_data['name'] ?? 'Admin';
                
                // Only notify for significant changes (price or stock)
                $changes = [];
                if ($price != $old_price) {
                    $changes[] = "price: ₱" . number_format($old_price, 2) . " → ₱" . number_format($price, 2);
                }
                if ($stock_quantity != $old_stock) {
                    $changes[] = "stock: {$old_stock} → {$stock_quantity}";
                }
                
                if (!empty($changes)) {
                    $change_details = implode(', ', $changes);
                    notifySuperAdmin_BookEdited($conn, $admin_name, $title, $change_details, $book_id);
                }
            }
            
            $_SESSION['message'] = 'Book updated successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Error updating book: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
        
        // Force page reload with cache busting to show updated image
        header('Location: admin_manage_books.php?refresh=' . time());
        exit();
    }
    
    elseif ($action == 'delete') {
        // Only Super Admin can delete books
        if (!$is_super_admin) {
            $_SESSION['message'] = 'Access Denied! Only Administrators can delete books.';
            $_SESSION['message_type'] = 'danger';
            header('Location: admin_manage_books.php');
            exit();
        }
        
        $book_id = account_positive_integer($_POST['book_id'] ?? null);
        
        // Get the book details before deleting
        $stmt = $conn->prepare("SELECT title, cover_image FROM books WHERE id = ?");
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $book_title = $result['title'] ?? 'Unknown';
        
        // Delete the book
        $stmt = $conn->prepare("DELETE FROM books WHERE id=?");
        $stmt->bind_param("i", $book_id);
        
        if ($stmt->execute()) {
            // Log book deletion
            log_book_action('delete', $book_id, $book_title);
            
            // Delete the image file if it exists
            if (!empty($result['cover_image'])) {
                $image_path = __DIR__ . '/../uploads/books/' . $result['cover_image'];
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            // Notify Super Admin about book deletion (CRITICAL ACTION)
            require_once __DIR__ . '/includes/superadmin_notifier_helper.php';
            $admin_name = $admin_data['name'] ?? 'Super Admin';
            notifySuperAdmin_BookDeleted($conn, $admin_name, $book_title, $book_id);
            
            $_SESSION['message'] = 'Book deleted successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Error deleting book: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
        
        header('Location: admin_manage_books.php');
        exit();
    }
}

// Get messages from session
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);

// Pagination setup for books - 5 per page
$books_per_page = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $books_per_page;

// Get total books count
$total_books_result = $conn->query("SELECT COUNT(*) as total FROM books");
$total_books = $total_books_result->fetch_assoc()['total'];
$total_pages = ceil($total_books / $books_per_page);

// Get all books - Force fresh data with explicit column selection with pagination
$books_query = "SELECT id, title, author, isbn, description, price, stock_quantity, cover_image, is_active, created_at, updated_at FROM books ORDER BY created_at DESC LIMIT $books_per_page OFFSET $offset";
$books = $conn->query($books_query);

// Debug: Log the query result count
error_log("Books query returned: " . ($books ? $books->num_rows : 0) . " rows");

// Check for books without images
$books_without_images = 0;
if ($books && $books->num_rows > 0) {
    $books_array = [];
    while ($book = $books->fetch_assoc()) {
        $books_array[] = $book;
        if (empty($book['cover_image']) || $book['cover_image'] === '0' || $book['cover_image'] === '#') {
            $books_without_images++;
        }
    }
    // Reset the result pointer
    $books = $books_array;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost & Found Catalog - CSUCC FIND</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <link rel="stylesheet" href="../css/book_loading_animation.css">
    <link rel="stylesheet" href="../css/bookshelf_loader.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        /* Books Page Styles - Matching Unified Dashboard */
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
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
        }
        
        /* Statistics Cards */
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            border: 1px solid #E2E8F0;
        }
        
        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        .stats-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
            background: transparent !important;
            background-color: transparent !important;
        }
        
        .stats-card.total .icon {
            background: transparent !important;
            background-color: transparent !important;
            color: #3B82F6;
        }
        
        .stats-card.active .icon {
            background: transparent !important;
            background-color: transparent !important;
            color: #10B981;
        }
        
        .stats-card.low-stock .icon {
            background: transparent !important;
            background-color: transparent !important;
            color: #EF4444;
        }
        
        .stats-card h3 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px 0;
            color: var(--dark);
        }
        
        .stats-card p {
            margin: 0;
            color: var(--gray-500);
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Quick Actions Bar */
        .quick-actions-bar {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border: 1px solid #E2E8F0;
        }
        
        .quick-action-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            color: var(--gray-700);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .quick-action-item:hover {
            background: var(--dark);
            color: white;
            border-color: var(--dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .quick-action-item i {
            font-size: 14px;
        }
        
        /* Quick Action Icon Colors */
        .quick-action-item i.fa-plus-circle {
            color: #4F46E5 !important; /* Indigo for Add New Book */
        }
        
        .quick-action-item i.fa-sync-alt {
            color: #3B82F6 !important; /* Blue for Refresh */
        }
        
        .quick-action-item i.fa-file-export {
            color: #10B981 !important; /* Green for Export */
        }
        
        .quick-action-item i.fa-file-import {
            color: #F59E0B !important; /* Orange for Import */
        }
        
        .quick-action-item i.fa-print {
            color: #64748B !important; /* Gray for Print */
        }
        
        .quick-action-item:hover i {
            color: white !important; /* White on hover */
        }
        
        /* Search and Filter Section */
        .search-filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            border: 1px solid #E2E8F0;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 16px;
        }
        
        .search-box input {
            padding-left: 48px;
            height: 48px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s ease;
            width: 100%;
        }
        
        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .filter-btn {
            padding: 10px 18px;
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            color: var(--gray-700);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-btn:hover {
            background: var(--gray-100);
            border-color: var(--gray-300);
        }
        
        .filter-btn.active {
            background: transparent !important;
            color: var(--text-primary) !important;
            border-color: var(--gray-300) !important;
        }
        
        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #E2E8F0;
        }
        
        .table-card .table {
            margin: 0;
        }
        
        .table-card thead {
            background: var(--gray-50);
        }
        
        .table-card thead th {
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .table-card tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            color: var(--gray-700);
            font-size: 14px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .table-card tbody tr {
            transition: all 0.2s ease;
        }
        
        .table-card tbody tr:hover {
            background: var(--gray-50);
        }
        
        /* Action Buttons */
        .btn-action {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s ease;
            border: none;
            background: transparent;
            font-weight: 500;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: none;
        }
        
        .btn-action.btn-primary {
            color: #4338CA;
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-primary:hover {
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-warning {
            color: #D97706;
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-warning:hover {
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-danger {
            color: #DC2626;
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-danger:hover {
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-secondary {
            color: #64748B;
            border: none;
            background: transparent;
        }
        
        .btn-action.btn-secondary:hover {
            border: none;
            background: transparent;
        }
        
        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            color: #94A3B8 !important;
            border: none !important;
            background: transparent !important;
        }
        
        .btn-action:disabled:hover {
            transform: none;
            box-shadow: none;
            background: transparent !important;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-400);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h4 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 8px;
        }
        
        .empty-state p {
            font-size: 14px;
            color: var(--gray-400);
            margin-bottom: 24px;
        }
        
        /* Badges - Transparent Background with Text Only */
        .badge {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
            background: transparent !important;
        }
        
        /* Stock Badges - Black text, transparent background */
        .badge.bg-primary,
        .badge.bg-warning,
        .badge.bg-danger {
            color: #1F2937 !important;
            background: transparent !important;
        }
        
        /* Status Badges - Colored text, transparent background */
        .badge.bg-success {
            color: #16A34A !important;
            background: transparent !important;
        }
        
        .badge.bg-secondary {
            color: #6B7280 !important;
            background: transparent !important;
        }
        
        .badge i {
            font-size: 10px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-card h3 {
                font-size: 24px;
            }
            
            .quick-actions-bar {
                flex-direction: column;
            }
            
            .quick-action-item {
                width: 100%;
                justify-content: center;
            }
            
            .filter-btn {
                font-size: 12px;
                padding: 8px 14px;
            }
        }
        
        @media print {
            .sidebar, .top-header, .quick-actions-bar, .search-filter-section, .btn-action {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
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
                    <h4 class="mb-0">Lost & Found Catalog</h4>
                    <small class="text-muted">Item Inventory & Case Registry</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="<?php echo $dashboard_url; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
        
        <div class="container-fluid">
        <!-- Success/Error Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show fade-in" role="alert">
                <i data-feather="<?php echo $message_type == 'success' ? 'check-circle' : 'alert-circle'; ?>" class="me-2"></i>
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Warning for books without images -->
        <?php if ($books_without_images > 0): ?>
            <div class="alert alert-warning alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Attention!</strong> You have <?php echo $books_without_images; ?> book(s) without cover images. 
                <a href="fix_book_images.php" class="alert-link">Click here to view and fix them</a>.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Actions Bar -->
        <div class="quick-actions-bar mb-4">
            <?php if (can('books.create')): ?>
            <a href="add_book_page.php" class="quick-action-item" style="text-decoration: none;">
                <i class="fas fa-plus-circle"></i>
                <span>Add New Item</span>
            </a>
            <?php endif; ?>
            <button class="quick-action-item" onclick="refreshStats()" title="Refresh Statistics">
                <i class="fas fa-sync-alt"></i>
                <span>Refresh</span>
            </button>
            <button class="quick-action-item" onclick="exportBooks()" title="Export to CSV">
                <i class="fas fa-file-export"></i>
                <span>Export</span>
            </button>
            <button class="quick-action-item" data-bs-toggle="modal" data-bs-target="#importModal" title="Import Items">
                <i class="fas fa-file-import"></i>
                <span>Import</span>
            </button>
            <button class="quick-action-item" onclick="printBookList()" title="Print Item List">
                <i class="fas fa-print"></i>
                <span>Print</span>
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card total">
                    <div class="icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <h3 id="totalBooks">0</h3>
                    <p>Total Items</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card active">
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 id="activeBooks">0</h3>
                    <p>Verified Items</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card low-stock">
                    <div class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 id="lowStockBooks">0</h3>
                    <p>Pending Review</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="search-filter-section">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by title or author...">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-2 justify-content-end">
                        <button class="filter-btn active" data-filter="all">
                            <i class="fas fa-list" style="color: #4F46E5 !important;"></i> All
                        </button>
                        <button class="filter-btn" data-filter="active">
                            <i class="fas fa-check" style="color: #10B981 !important;"></i> Active
                        </button>
                        <button class="filter-btn" data-filter="inactive">
                            <i class="fas fa-pause" style="color: #6B7280 !important;"></i> Inactive
                        </button>
                        <button class="filter-btn" data-filter="low-stock">
                            <i class="fas fa-exclamation-circle" style="color: #F59E0B !important;"></i> Low Stock
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Books Table -->
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <div>
                    <h5 class="mb-0" style="font-weight: 600; color: #1a1d29;">
                        <i class="fas fa-book me-2" style="color: #3B82F6;"></i>Item Registry
                    </h5>
                    <small class="text-muted">Showing <span id="visibleRows">0</span> of <span id="totalRows">0</span> items</small>
                </div>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="rowsPerPage" style="width: auto;">
                        <option value="10">10 rows</option>
                        <option value="25" selected>25 rows</option>
                        <option value="50">50 rows</option>
                        <option value="100">100 rows</option>
                        <option value="all">All</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="booksTable">
                    <thead>
                        <tr>
                            <th>Cover</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($books && is_array($books) && count($books) > 0): ?>
                            <?php foreach ($books as $book): ?>
                                <tr class="book-loading" data-status="<?php echo $book['is_active'] ? 'active' : 'inactive'; ?>" 
                                    data-stock="<?php echo $book['stock_quantity']; ?>"
                                    data-title="<?php echo strtolower(htmlspecialchars($book['title'])); ?>"
                                    data-author="<?php echo strtolower(htmlspecialchars($book['author'])); ?>">
                                    <td style="width: 80px; text-align: center;">
                                        <?php 
                                        $cover_value = $book['cover_image'];
                                        
                                        // Debug logging
                                        error_log("Book ID {$book['id']}: cover_image = " . var_export($cover_value, true));
                                        
                                        // Check if we have a valid image filename
                                        if (!empty($cover_value) && $cover_value !== '0' && $cover_value !== '#' && $cover_value !== 'NULL'):
                                            $img_path = "../uploads/books/" . htmlspecialchars($cover_value);
                                            $full_path = __DIR__ . "/../uploads/books/" . $cover_value;
                                            error_log("Book ID {$book['id']}: Image path = {$img_path}, File exists = " . (file_exists($full_path) ? 'YES' : 'NO'));
                                        ?>
                                            <img src="<?= $img_path ?>?v=<?= time() ?>" 
                                                 alt="<?= htmlspecialchars($book['title']) ?>" 
                                                 style="width: 60px; height: 85px; object-fit: cover; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); display: block;"
                                                 onload="console.log('Image loaded: <?= $cover_value ?>');"
                                                 onerror="console.error('Failed to load: <?= $img_path ?>'); this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div style="display: none; width: 60px; height: 85px; border-radius: 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); align-items: center; justify-content: center; color: white; font-size: 1.8rem;">
                                                <i class="fas fa-book"></i>
                                            </div>
                                        <?php else: 
                                            error_log("Book ID {$book['id']}: No valid image - showing placeholder");
                                        ?>
                                            <div style="width: 60px; height: 85px; border-radius: 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.8rem;" title="No image: <?= var_export($cover_value, true) ?>">
                                                <i class="fas fa-book"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><strong>₱<?php echo number_format($book['price'], 2); ?></strong></td>
                                    <td>
                                        <?php 
                                        $stock_class = $book['stock_quantity'] < 5 ? 'danger' : ($book['stock_quantity'] < 10 ? 'warning' : 'primary');
                                        $stock_icon = $book['stock_quantity'] < 5 ? 'fa-exclamation-circle' : ($book['stock_quantity'] < 10 ? 'fa-exclamation-triangle' : 'fa-check-circle');
                                        ?>
                                        <span class="badge bg-<?php echo $stock_class; ?>">
                                            <i class="fas <?php echo $stock_icon; ?>"></i>
                                            <?php echo $book['stock_quantity']; ?> units
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $book['is_active'] ? 'success' : 'secondary'; ?>">
                                            <i class="fas <?php echo $book['is_active'] ? 'fa-check-circle' : 'fa-pause-circle'; ?>"></i>
                                            <?php echo $book['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 justify-content-center">
                                            <button class="btn btn-sm btn-primary btn-action" 
                                                    onclick="viewBook(<?php echo $book['id']; ?>)" 
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (can('books.edit')): ?>
                                            <button class="btn btn-sm btn-warning btn-action" 
                                                    onclick="editBook(<?php echo $book['id']; ?>)" 
                                                    title="Edit Book">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($is_super_admin): ?>
                                                <button class="btn btn-sm btn-danger btn-action" 
                                                        onclick="deleteBook(<?php echo $book['id']; ?>, '<?php echo htmlspecialchars($book['title']); ?>')" 
                                                        title="Delete Book">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-secondary btn-action" 
                                                        disabled
                                                        title="Only Super Admin can delete books">
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="emptyRow">
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-book-open"></i>
                                        <h4>No Books Found</h4>
                                        <p>Your library is empty. Start building your collection today!</p>
                                        <a href="add_book_page.php" class="btn">
                                            <i class="fas fa-plus-circle me-2"></i>Add Your First Book
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Professional Pagination -->
            <?php echo render_pagination($page, $total_pages, $total_books, $books_per_page, '?', 'books'); ?>
        </div>
        </div>
    </div>
    
    <?php include 'admin_manage_books_modals.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/admin_manage_books.js"></script>
    <script>
        // Enhanced functionality
        document.addEventListener('DOMContentLoaded', function() {
            updateRowCounts();
            initializeFilters();
            initializeSearch();
            initializeRowsPerPage();
            loadStats();
        });
        
        // Update row counts
        function updateRowCounts() {
            const table = document.getElementById('booksTable');
            if (!table) return;
            
            const allRows = table.querySelectorAll('tbody tr');
            const visibleRows = Array.from(allRows).filter(row => row.style.display !== 'none');
            
            document.getElementById('totalRows').textContent = allRows.length;
            document.getElementById('visibleRows').textContent = visibleRows.length;
        }
        
        // Initialize filter buttons
        function initializeFilters() {
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            filterBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Remove active class from all buttons
                    filterBtns.forEach(b => b.classList.remove('active'));
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    filterBooks(filter);
                });
            });
        }
        
        // Filter books
        function filterBooks(filter) {
            const rows = document.querySelectorAll('#booksTable tbody tr');
            
            rows.forEach(row => {
                const status = row.dataset.status;
                const stock = parseInt(row.dataset.stock);
                
                let show = false;
                
                switch(filter) {
                    case 'all':
                        show = true;
                        break;
                    case 'active':
                        show = status === 'active';
                        break;
                    case 'inactive':
                        show = status === 'inactive';
                        break;
                    case 'low-stock':
                        show = stock < 10;
                        break;
                }
                
                row.style.display = show ? '' : 'none';
            });
            
            updateRowCounts();
        }
        
        // Initialize search
        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            if (!searchInput) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('#booksTable tbody tr');
                
                rows.forEach(row => {
                    const title = row.dataset.title || '';
                    const author = row.dataset.author || '';
                    
                    if (title.includes(searchTerm) || author.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                updateRowCounts();
            });
        }
        
        // Initialize rows per page
        function initializeRowsPerPage() {
            const select = document.getElementById('rowsPerPage');
            if (!select) return;
            
            select.addEventListener('change', function() {
                const limit = this.value;
                const rows = document.querySelectorAll('#booksTable tbody tr');
                
                if (limit === 'all') {
                    rows.forEach(row => row.style.display = '');
                } else {
                    const limitNum = parseInt(limit);
                    rows.forEach((row, index) => {
                        row.style.display = index < limitNum ? '' : 'none';
                    });
                }
                
                updateRowCounts();
            });
        }
        
        // Load statistics
        function loadStats() {
            fetch('?ajax=get_stats')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('totalBooks').textContent = data.total || 0;
                    document.getElementById('activeBooks').textContent = data.active || 0;
                    document.getElementById('lowStockBooks').textContent = data.low_stock || 0;
                })
                .catch(error => console.error('Error loading stats:', error));
        }
        
        // Refresh statistics
        function refreshStats() {
            const icon = event.target.closest('.quick-action-item').querySelector('i');
            icon.classList.add('fa-spin');
            
            loadStats();
            
            setTimeout(() => {
                icon.classList.remove('fa-spin');
                showToast('Statistics refreshed successfully!', 'success');
            }, 500);
        }
        
        // Export books to CSV
        function exportBooks() {
            const rows = document.querySelectorAll('#booksTable tbody tr');
            if (rows.length === 0 || rows[0].cells.length === 1) {
                showToast('No books to export', 'warning');
                return;
            }
            
            let csv = 'Title,Author,Price,Stock,Status\n';
            
            rows.forEach(row => {
                if (row.style.display !== 'none' && row.cells.length > 1) {
                    const title = row.cells[1]?.textContent.trim() || '';
                    const author = row.cells[2]?.textContent.trim() || '';
                    const price = row.cells[3]?.textContent.trim() || '';
                    const stock = row.cells[4]?.textContent.trim() || '';
                    const status = row.cells[5]?.textContent.trim() || '';
                    
                    csv += `"${title}","${author}","${price}","${stock}","${status}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `books_export_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showToast('Books exported successfully!', 'success');
        }
        
        // Print book list
        function printBookList() {
            window.print();
        }
        
        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
            document.body.appendChild(container);
            return container;
        }
        
        // Force reload images after book is added
        <?php if (isset($_SESSION['new_book_added'])): ?>
        console.log('New book was added - forcing image reload');
        // Clear the flag
        <?php unset($_SESSION['new_book_added']); ?>
        
        // Force reload all book cover images
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('#booksTable img');
            images.forEach(img => {
                const src = img.src.split('?')[0]; // Remove existing query string
                img.src = src + '?v=' + new Date().getTime(); // Add new timestamp
            });
            
            // Scroll to top to show the newly added book
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        <?php endif; ?>
    </script>
    
    <!-- Book Loading Animation System -->
    <script src="../js/book_loading_animation.js"></script>
</body>
</html>

