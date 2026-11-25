<?php
require_once __DIR__ . '/../includes/book_input_validation.php';
require_once __DIR__ . '/../includes/upload_validation.php';
session_start();
require_once __DIR__ . '/../../DB/conn.php';
require_once __DIR__ . '/../includes/rbac_helper.php';
require_once __DIR__ . '/../includes/logger_helper.php';

header('Content-Type: application/json');

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// RBAC: Check permission to create books
if (!can('books.create')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to add books']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // DEBUG: Log what we received
    
    // Get form data
    try {
        [$title, $author, $isbn, $description, $price, $stock_quantity, $is_active] = book_form_input();
    } catch (InvalidArgumentException $error) {
        echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        exit;
    }
    
    // Validate required fields
    if (empty($title) || empty($author) || $price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit();
    }
    
    // Handle image upload
    $cover_image = '';
    $upload_error = '';
    
    if (!isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'message' => 'Book cover image is required']);
        exit();
    }
    
    if ($_FILES['cover_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../uploads/books/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = validated_upload_extension($_FILES['cover_image'], ['jpg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
        
        if ($file_extension === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed']);
            exit();
        }
        
        if ($_FILES['cover_image']['size'] > 5242880) {
            echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
            exit();
        }
        
        if ($_FILES['cover_image']['size'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Uploaded file is empty']);
            exit();
        }
        
        $cover_image = 'book_' . bin2hex(random_bytes(12)) . '.' . strtolower($file_extension);
        
        if (!move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_dir . $cover_image)) {
            echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
            exit();
        }
        
        // Verify file was created
        if (!file_exists($upload_dir . $cover_image) || filesize($upload_dir . $cover_image) == 0) {
            echo json_encode(['success' => false, 'message' => 'File upload verification failed']);
            exit();
        }
    } else {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
        ];
        $error_msg = $error_messages[$_FILES['cover_image']['error']] ?? 'Unknown upload error';
        echo json_encode(['success' => false, 'message' => $error_msg]);
        exit();
    }
    
    // Insert into database
    $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, description, price, stock_quantity, cover_image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssdiis", $title, $author, $isbn, $description, $price, $stock_quantity, $cover_image, $is_active);
    
    if ($stmt->execute()) {
        $book_id = $conn->insert_id;
        
        // Log book creation
        log_book_action('create', $book_id, $title);
        
        // Notify customers
        require_once __DIR__ . '/create_notification.php';
        notifyNewBook($conn, $book_id, $title, $author, $cover_image);
        
        echo json_encode([
            'success' => true,
            'message' => 'Book added successfully!',
            'book_id' => $book_id,
            'cover_image' => $cover_image
        ]);
    } else {
        // Delete uploaded file if database insert fails
        if (file_exists($upload_dir . $cover_image)) {
            unlink($upload_dir . $cover_image);
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
