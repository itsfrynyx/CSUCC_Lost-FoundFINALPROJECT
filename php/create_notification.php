<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../DB/conn.php';

/**
 * Create notifications for users
 * 
 * @param mysqli $conn Database connection
 * @param array $user_ids Array of user IDs to notify (empty array = all users)
 * @param string $type Notification type: order, delivery, promotion, new_book, price_drop, low_stock, system
 * @param string $title Notification title
 * @param string $message Notification message
 * @param array $data Additional data (optional)
 * @return bool Success status
 */
function createNotification($conn, $user_ids, $type, $title, $message, $data = []) {
    // Ensure notifications table exists
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('order', 'delivery', 'promotion', 'new_book', 'price_drop', 'low_stock', 'system') NOT NULL,
        data JSON,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES infos(id) ON DELETE CASCADE,
        INDEX idx_user_read (user_id, is_read),
        INDEX idx_created (created_at)
    )");
    
    // If no specific users, notify all active customers (excluding admins)
    if (empty($user_ids)) {
        $result = $conn->query("SELECT id FROM infos WHERE (role IS NULL OR role = 'customer') AND status != 'blocked'");
        $user_ids = [];
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = $row['id'];
        }
    }
    
    // Insert notifications for each user
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, data) VALUES (?, ?, ?, ?, ?)");
    $data_json = json_encode($data);
    $success = true;
    
    foreach ($user_ids as $user_id) {
        $stmt->bind_param("issss", $user_id, $title, $message, $type, $data_json);
        if (!$stmt->execute()) {
            $success = false;
        }
    }
    
    return $success;
}

/**
 * Notify all customers about a new book
 */
function notifyNewBook($conn, $book_id, $book_title, $book_author, $cover_image = null) {
    $title = "📚 New Book Available!";
    $message = "Check out our new arrival: \"$book_title\" by $book_author";
    $data = [
        'book_id' => $book_id, 
        'book_title' => $book_title,
        'cover_image' => $cover_image
    ];
    
    return createNotification($conn, [], 'new_book', $title, $message, $data);
}

/**
 * Notify users about price drop on their wishlist items
 */
function notifyPriceDrop($conn, $book_id, $book_title, $old_price, $new_price, $cover_image = null) {
    // Get users who have this book in wishlist
    $result = $conn->query("SELECT DISTINCT user_id FROM wishlist WHERE book_id = $book_id");
    $user_ids = [];
    while ($row = $result->fetch_assoc()) {
        $user_ids[] = $row['user_id'];
    }
    
    if (!empty($user_ids)) {
        $discount = round((($old_price - $new_price) / $old_price) * 100);
        $title = "💰 Price Drop Alert!";
        $message = "\"$book_title\" is now ₱" . number_format($new_price, 2) . " (Save $discount%)";
        $data = [
            'book_id' => $book_id, 
            'old_price' => $old_price, 
            'new_price' => $new_price,
            'cover_image' => $cover_image
        ];
        
        return createNotification($conn, $user_ids, 'price_drop', $title, $message, $data);
    }
    
    return true;
}

/**
 * Notify users about low stock on their wishlist items
 */
function notifyLowStock($conn, $book_id, $book_title, $stock_quantity, $cover_image = null) {
    // Get users who have this book in wishlist
    $result = $conn->query("SELECT DISTINCT user_id FROM wishlist WHERE book_id = $book_id");
    $user_ids = [];
    while ($row = $result->fetch_assoc()) {
        $user_ids[] = $row['user_id'];
    }
    
    if (!empty($user_ids)) {
        $title = "⚠️ Low Stock Alert!";
        $message = "Only $stock_quantity copies left of \"$book_title\". Order now before it's gone!";
        $data = [
            'book_id' => $book_id, 
            'stock_quantity' => $stock_quantity,
            'cover_image' => $cover_image
        ];
        
        return createNotification($conn, $user_ids, 'low_stock', $title, $message, $data);
    }
    
    return true;
}

/**
 * Notify user about order status
 */
function notifyOrderStatus($conn, $user_id, $order_id, $status, $message) {
    $titles = [
        'confirmed' => '✅ Order Confirmed',
        'processing' => '📦 Order Processing',
        'shipped' => '🚚 Order Shipped',
        'delivered' => '🎉 Order Delivered',
        'cancelled' => '❌ Order Cancelled'
    ];
    
    $title = $titles[$status] ?? '📋 Order Update';
    $data = ['order_id' => $order_id, 'status' => $status];
    
    return createNotification($conn, [$user_id], 'order', $title, $message, $data);
}

/**
 * Send system-wide announcement
 */
function notifySystemAnnouncement($conn, $title, $message) {
    return createNotification($conn, [], 'system', $title, $message);
}

