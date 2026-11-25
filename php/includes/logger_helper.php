<?php
/**
 * Activity Logger Helper
 * Easy-to-use logging functions
 * Usage: require_once __DIR__ . '/includes/logger_helper.php';
 */

// Initialize logger if not already done
if (!isset($logger)) {
    require_once __DIR__ . '/ActivityLogger.php';
    
    // Make sure we have database connection
    if (!isset($conn)) {
        require_once __DIR__ . '/../DB/conn.php';
    }
    
    // Create logger instance
    $logger = new ActivityLogger($conn);
}

/**
 * Quick logging functions
 */

function log_action($action, $description, $data = []) {
    global $logger;
    return $logger->log($action, $description, $data);
}

function log_book_action($action, $book_id, $book_title) {
    global $logger;
    return $logger->logBookAction($action, $book_id, $book_title);
}

function log_customer_action($action, $customer_id, $customer_name) {
    global $logger;
    return $logger->logCustomerAction($action, $customer_id, $customer_name);
}

function log_admin_action($action, $admin_id, $admin_name) {
    global $logger;
    return $logger->logAdminAction($action, $admin_id, $admin_name);
}

function log_order_action($action, $order_id, $order_number) {
    global $logger;
    return $logger->logOrderAction($action, $order_id, $order_number);
}

function log_review_action($action, $review_id, $book_title) {
    global $logger;
    return $logger->logReviewAction($action, $review_id, $book_title);
}

function log_cart_action($action, $book_title) {
    global $logger;
    return $logger->logCartAction($action, $book_title);
}

function log_wishlist_action($action, $book_title) {
    global $logger;
    return $logger->logWishlistAction($action, $book_title);
}

function log_page_view($page_name) {
    // Page view logging has been disabled
    return true;
}

function log_search($search_query) {
    global $logger;
    return $logger->logSearch($search_query);
}

function log_permission_denied($attempted_action, $required_permission) {
    global $logger;
    return $logger->logPermissionDenied($attempted_action, $required_permission);
}
?>
