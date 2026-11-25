<?php
/**
 * Super Admin Notifier Helper
 * Simplified functions to trigger Super Admin notifications from anywhere in the system
 * 
 * CRITICAL ACTIONS ONLY:
 * - Admin: Delete books, bulk updates, cancel orders, role changes
 * - Customer: Large orders, refund requests, suspicious activity
 */

require_once __DIR__ . '/SuperAdminNotifications.php';

/**
 * Initialize notifier (call this before using any notify functions)
 */
function getSuperAdminNotifier($conn) {
    static $notifier = null;
    if ($notifier === null) {
        $notifier = new SuperAdminNotifications($conn);
    }
    return $notifier;
}

// ========== ADMIN CRITICAL ACTIONS ==========

/**
 * Notify when admin deletes a book
 */
function notifySuperAdmin_BookDeleted($conn, $admin_name, $book_title, $book_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyAdminActivity(
        'delete_book',
        $admin_name,
        "deleted book: \"$book_title\"",
        $book_id
    );
}

/**
 * Notify when admin performs bulk book updates
 */
function notifySuperAdmin_BulkBookUpdate($conn, $admin_name, $count, $action) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyAdminActivity(
        'bulk_action',
        $admin_name,
        "performed bulk $action on $count books",
        null
    );
}

/**
 * Notify when admin edits book (only for significant changes: price or stock)
 */
function notifySuperAdmin_BookEdited($conn, $admin_name, $book_title, $changes, $book_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyAdminActivity(
        'edit_book',
        $admin_name,
        "updated \"$book_title\" ($changes)",
        $book_id
    );
}

/**
 * Notify when admin cancels an order
 */
function notifySuperAdmin_OrderCancelled($conn, $admin_name, $order_id, $customer_name, $amount) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyAdminActivity(
        'cancel_order',
        $admin_name,
        "cancelled order #$order_id for $customer_name (₱" . number_format($amount, 2) . ")",
        $order_id
    );
}

/**
 * Notify when admin role is changed
 */
function notifySuperAdmin_RoleChanged($conn, $admin_name, $target_user, $old_role, $new_role, $user_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifySecurityAlert(
        'permissions_modified',
        "$admin_name changed role",
        "$target_user: $old_role → $new_role",
        $user_id
    );
}

// ========== CUSTOMER CRITICAL ALERTS ==========

/**
 * Notify about large order (above threshold)
 */
function notifySuperAdmin_LargeOrder($conn, $customer_name, $order_id, $amount, $threshold = 5000) {
    if ($amount < $threshold) {
        return false; // Not a large order
    }
    
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyCustomerAlert(
        'large_order',
        $customer_name,
        "placed large order #$order_id (₱" . number_format($amount, 2) . ")",
        $order_id
    );
}

/**
 * Notify about refund request
 */
function notifySuperAdmin_RefundRequest($conn, $customer_name, $order_id, $amount, $reason) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyCustomerAlert(
        'refund_request',
        $customer_name,
        "requested refund for order #$order_id (₱" . number_format($amount, 2) . ") - Reason: $reason",
        $order_id
    );
}

/**
 * Notify about suspicious login attempts
 */
function notifySuperAdmin_SuspiciousLogin($conn, $customer_name, $failed_attempts, $ip_address, $user_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyCustomerAlert(
        'suspicious_behavior',
        $customer_name,
        "$failed_attempts failed login attempts from IP: $ip_address",
        $user_id
    );
}

/**
 * Notify about flagged customer account
 */
function notifySuperAdmin_AccountFlagged($conn, $customer_name, $reason, $user_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyCustomerAlert(
        'suspicious_behavior',
        $customer_name,
        "account flagged: $reason",
        $user_id
    );
}

/**
 * Notify about multiple failed checkout attempts
 */
function notifySuperAdmin_FailedCheckouts($conn, $customer_name, $attempt_count, $user_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyCustomerAlert(
        'failed_checkout',
        $customer_name,
        "$attempt_count failed checkout attempts",
        $user_id
    );
}

// ========== SECURITY ALERTS ==========

/**
 * Notify about new admin account created
 */
function notifySuperAdmin_NewAdminCreated($conn, $creator_name, $new_admin_name, $role, $user_id) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifySecurityAlert(
        'new_admin_created',
        "$creator_name created new admin account",
        "$new_admin_name with role: $role",
        $user_id
    );
}

/**
 * Notify about multiple failed admin login attempts
 */
function notifySuperAdmin_FailedAdminLogins($conn, $username, $attempt_count, $ip_address) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifySecurityAlert(
        'failed_logins',
        "Multiple failed admin login attempts",
        "$attempt_count attempts for user: $username from IP: $ip_address",
        null
    );
}

// ========== INVENTORY ALERTS ==========

/**
 * Notify about critical stock level
 */
function notifySuperAdmin_CriticalStock($conn, $book_title, $stock_quantity, $book_id) {
    if ($stock_quantity > 5) {
        return false; // Not critical yet
    }
    
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyInventoryAlert(
        'critical_stock',
        $book_title,
        "stock critically low: $stock_quantity units remaining",
        $book_id
    );
}

/**
 * Notify about bulk book deletion
 */
function notifySuperAdmin_BulkDeletion($conn, $admin_name, $count) {
    $notifier = getSuperAdminNotifier($conn);
    return $notifier->notifyInventoryAlert(
        'bulk_deletion',
        "Bulk Deletion",
        "$admin_name deleted $count books",
        null
    );
}

// ========== HELPER FUNCTIONS ==========

/**
 * Check if current user is NOT super admin (to avoid self-notifications)
 */
function shouldNotifySuperAdmin($admin_role) {
    return strtolower($admin_role) !== 'super_admin';
}

/**
 * Get admin name from session or database
 */
function getAdminName($conn, $admin_id) {
    $stmt = $conn->prepare("SELECT name, full_name, firstname, lastname FROM infos WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['name'] ?? 
           $result['full_name'] ?? 
           ($result['firstname'] . ' ' . $result['lastname']) ?? 
           'Admin';
}

/**
 * Get customer name from database
 */
function getCustomerName($conn, $user_id) {
    $stmt = $conn->prepare("SELECT full_name, firstname, lastname, username FROM infos WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['full_name'] ?? 
           ($result['firstname'] . ' ' . $result['lastname']) ?? 
           $result['username'] ?? 
           'Customer';
}
