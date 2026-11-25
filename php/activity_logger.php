<?php
/**
 * Centralized Activity Logger
 * Records all customer and admin actions to their respective activity logs
 */

require_once __DIR__ . '/../DB/conn.php';

function ensureUserSessionActivityTable($conn) {
    $check = $conn->query("SHOW TABLES LIKE 'user_session_activity'");
    if ($check && $check->num_rows === 0) {
        $createSql = "
            CREATE TABLE user_session_activity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                session_id VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                logout_time DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                duration_seconds INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_session (user_id, session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        if (!$conn->query($createSql)) {
            throw new Exception("Failed to create user_session_activity table: " . $conn->error);
        }
    }
}

/**
 * Log customer activity
 * 
 * @param int $customer_id - Customer user ID
 * @param string $activity_type - Type of activity (login, logout, page_view, book_view, add_to_cart, purchase, etc.)
 * @param string $activity_details - Detailed description of the activity
 * @param string|null $session_id - Optional session ID
 * @return bool - Success status
 */
function logCustomerActivity($customer_id, $activity_type, $activity_details, $session_id = null) {
    global $conn;
    
    // Validate that customer exists in infos table before logging
    $check_stmt = $conn->prepare("SELECT id FROM infos WHERE id = ?");
    $check_stmt->bind_param("i", $customer_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        // User doesn't exist, skip logging to avoid foreign key constraint error
        error_log("Activity Logger: Skipped logging for non-existent user ID: $customer_id");
        return false;
    }
    
    if (!$session_id) {
        $session_id = session_id();
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    try {
        // Log to user_activity_logs (legacy table - keep for backward compatibility)
        $stmt1 = $conn->prepare("
            INSERT INTO user_activity_logs 
            (user_id, session_id, activity_type, activity_details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt1->bind_param("isssss", $customer_id, $session_id, $activity_type, $activity_details, $ip_address, $user_agent);
        $result1 = $stmt1->execute();
        
        // Log to admin_activity_log (unified table - now includes customers)
        $stmt2 = $conn->prepare("
            INSERT INTO admin_activity_log 
            (user_id, user_type, session_id, action, description, ip_address, user_agent) 
            VALUES (?, 'customer', ?, ?, ?, ?, ?)
        ");
        $stmt2->bind_param("isssss", $customer_id, $session_id, $activity_type, $activity_details, $ip_address, $user_agent);
        $result2 = $stmt2->execute();
        
        // Ensure session exists in user_session_activity (skip for logout)
        if ($activity_type !== 'logout') {
            ensureSessionActivity($customer_id, $session_id, $ip_address, $user_agent);
        }
        
        return $result1 && $result2;
    } catch (mysqli_sql_exception $e) {
        // Handle foreign key constraint errors gracefully
        error_log("Activity Logger Error (Customer): " . $e->getMessage());
        error_log("Context: user_id=$customer_id, type=$activity_type, session=$session_id");
        return false;
    }
}

/**
 * Ensure a record exists in user_session_activity for the current session
 * 
 * @param int $user_id
 * @param string $session_id
 * @param string $ip_address
 * @param string $user_agent
 * @return bool
 */
function ensureSessionActivity($user_id, $session_id, $ip_address, $user_agent) {
    global $conn;

    try {
        ensureUserSessionActivityTable($conn);

        $stmt = $conn->prepare("
            INSERT INTO user_session_activity 
            (user_id, session_id, ip_address, user_agent, login_time, last_activity, status) 
            VALUES (?, ?, ?, ?, NOW(), NOW(), 'active')
            ON DUPLICATE KEY UPDATE
            last_activity = NOW(),
            status = 'active',
            login_time = IF(status = 'inactive', NOW(), login_time),
            logout_time = IF(status = 'inactive', NULL, logout_time),
            duration_seconds = IF(status = 'inactive', NULL, duration_seconds)
        ");
        $stmt->bind_param("isss", $user_id, $session_id, $ip_address, $user_agent);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Session Activity Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log admin activity
 * 
 * @param int $admin_id - Admin user ID
 * @param string $user_type - Type of user (super_admin or admin)
 * @param string $action - Action performed (login, logout, add_book, edit_book, delete_book, etc.)
 * @param string $description - Detailed description of the action
 * @param string|null $session_id - Optional session ID
 * @return bool - Success status
 */
function logAdminActivity($admin_id, $user_type, $action, $description, $session_id = null) {
    global $conn;
    
    // Validate that admin exists in infos table before logging
    $check_stmt = $conn->prepare("SELECT id FROM infos WHERE id = ? AND role IN ('admin', 'super_admin')");
    $check_stmt->bind_param("i", $admin_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Admin doesn't exist, skip logging
        error_log("Activity Logger: Skipped logging for non-existent admin ID: $admin_id");
        return false;
    }
    
    if (!$session_id) {
        $session_id = session_id();
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO admin_activity_log 
            (user_id, user_type, session_id, action, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssss", $admin_id, $user_type, $session_id, $action, $description, $ip_address, $user_agent);
        
        // Ensure session exists in user_session_activity (skip for logout)
        if ($action !== 'logout') {
            ensureSessionActivity($admin_id, $session_id, $ip_address, $user_agent);
        }
        
        return $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // Handle errors gracefully
        error_log("Activity Logger Error (Admin): " . $e->getMessage());
        error_log("Context: admin_id=$admin_id, type=$user_type, action=$action, session=$session_id");
        return false;
    }
}

/**
 * Log a login event and initialize session tracking
 * 
 * @param int $user_id
 * @param string $user_type (customer, admin, super_admin)
 * @return bool
 */
function logLogin($user_id, $user_type) {
    global $conn;
    
    // Safety check: close any orphaned active sessions for this user before starting a new one
    try {
        $close_orphans = $conn->prepare("
            UPDATE user_session_activity 
            SET status = 'inactive', logout_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, login_time, NOW())
            WHERE user_id = ? AND status = 'active'
        ");
        $close_orphans->bind_param("i", $user_id);
        $close_orphans->execute();
    } catch (Exception $e) {
        error_log("Login orphan cleanup error: " . $e->getMessage());
    }

    $session_id = session_id();
    $details = ucfirst($user_type) . " logged in";
    
    if ($user_type === 'customer') {
        return logCustomerActivity($user_id, 'login', $details, $session_id);
    } else {
        return logAdminActivity($user_id, $user_type, 'login', $details, $session_id);
    }
}

/**
 * Log a logout event and close session tracking
 * 
 * @param int $user_id
 * @param string $user_type
 * @param string $session_id
 * @return bool
 */
function logLogout($user_id, $user_type, $session_id = null) {
    global $conn;
    
    // Ensure we have a session ID
    if (empty($session_id)) {
        $session_id = session_id();
    }
    
    // Robust fallback: if session_id is empty, try to find the most recent active session
    if (empty($session_id) || $session_id === 'unknown') {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        error_log("Activity Logger Debug: Falling back for user_id=" . ($user_id ?? 'NULL') . " (IP=$ip_address)");
        
        try {
            if (!empty($user_id)) {
                // Primary fallback: by user_id
                $find_sid = $conn->prepare("
                    SELECT session_id FROM user_session_activity 
                    WHERE user_id = ? AND status = 'active' 
                    ORDER BY login_time DESC LIMIT 1
                ");
                $find_sid->bind_param("i", $user_id);
            } else {
                // Secondary fallback: by IP and User Agent (if session is lost)
                $find_sid = $conn->prepare("
                    SELECT session_id, user_id FROM user_session_activity 
                    WHERE ip_address = ? AND user_agent = ? AND status = 'active' 
                    ORDER BY login_time DESC LIMIT 1
                ");
                $find_sid->bind_param("ss", $ip_address, $user_agent);
            }
            
            $find_sid->execute();
            $sid_res = $find_sid->get_result()->fetch_assoc();
            if ($sid_res) {
                $session_id = $sid_res['session_id'];
                if (empty($user_id)) {
                    $user_id = $sid_res['user_id'];
                }
                error_log("Activity Logger Debug: Found fallback session_id=$session_id for user_id=$user_id");
            } else {
                error_log("Activity Logger Debug: No active session found during fallback");
            }
        } catch (Exception $e) {
            error_log("Session ID fallback error: " . $e->getMessage());
        }
    }
    
    // If it's still empty, we have a problem, but let's at least log it
    if (empty($session_id)) {
        error_log("Activity Logger Warning: Empty session_id in logLogout for user_id=$user_id");
        $session_id = 'unknown_' . time();
    }

    $details = ucfirst($user_type) . " logged out";
    $logout_time = date('Y-m-d H:i:s');
    
    // Update user_session_activity with accurate duration
    try {
        $session_update = $conn->prepare("
            UPDATE user_session_activity 
            SET logout_time = ?, status = 'inactive', last_activity = ?,
                duration_seconds = TIMESTAMPDIFF(SECOND, login_time, ?)
            WHERE user_id = ? AND session_id = ? AND status = 'active'
        ");
        $session_update->bind_param("sssis", $logout_time, $logout_time, $logout_time, $user_id, $session_id);
        $session_update->execute();
        
        // Fallback: If no active session was updated, try updating the most recent session for this user/id
        if ($session_update->affected_rows === 0) {
            $session_update_fallback = $conn->prepare("
                UPDATE user_session_activity 
                SET logout_time = ?, status = 'inactive', last_activity = ?,
                    duration_seconds = TIMESTAMPDIFF(SECOND, login_time, ?)
                WHERE user_id = ? AND session_id = ?
                ORDER BY login_time DESC LIMIT 1
            ");
            $session_update_fallback->bind_param("sssis", $logout_time, $logout_time, $logout_time, $user_id, $session_id);
            $session_update_fallback->execute();
        }
    } catch (Exception $e) {
        error_log("Logout Session Update Error: " . $e->getMessage());
    }
    
    // Record in activity log tables
    if ($user_type === 'customer') {
        return logCustomerActivity($user_id, 'logout', $details, $session_id);
    } else {
        return logAdminActivity($user_id, $user_type, 'logout', $details, $session_id);
    }
}

/**
 * Auto-detect and log activity based on current session
 * 
 * @param string $action - Action type
 * @param string $details - Action details
 * @return bool - Success status
 */
function logActivity($action, $details) {
    // Check if admin is logged in
    if (isset($_SESSION['super_admin_id'])) {
        $admin_id = $_SESSION['super_admin_id'];
        $user_type = $_SESSION['admin_role'] ?? 'admin';
        return logAdminActivity($admin_id, $user_type, $action, $details);
    }
    // Check if customer is logged in
    elseif (isset($_SESSION['user_id'])) {
        $customer_id = $_SESSION['user_id'];
        return logCustomerActivity($customer_id, $action, $details);
    }
    
    return false;
}

/**
 * Log page view activity
 * 
 * @param string $page_name - Name of the page viewed
 * @return bool - Success status
 */
function logPageView($page_name) {
    // Page view logging has been disabled
    return true;
}

/**
 * Log book view activity
 * 
 * @param int $book_id - ID of the book viewed
 * @param string $book_title - Title of the book
 * @return bool - Success status
 */
function logBookView($book_id, $book_title) {
    return logActivity('book_view', "Viewed book: $book_title (ID: $book_id)");
}

/**
 * Log search activity
 * 
 * @param string $search_query - Search query
 * @return bool - Success status
 */
function logSearch($search_query) {
    return logActivity('search', "Searched for: $search_query");
}

/**
 * Log add to cart activity
 * 
 * @param int $book_id - ID of the book added to cart
 * @param string $book_title - Title of the book
 * @return bool - Success status
 */
function logAddToCart($book_id, $book_title) {
    return logActivity('add_to_cart', "Added to cart: $book_title (ID: $book_id)");
}

/**
 * Log purchase activity
 * 
 * @param int $order_id - Order ID
 * @param float $total_amount - Total purchase amount
 * @return bool - Success status
 */
function logPurchase($order_id, $total_amount) {
    return logActivity('purchase', "Completed purchase - Order #$order_id, Amount: $$total_amount");
}

/**
 * Log book management activity (admin)
 * 
 * @param string $action - Action type (add_book, edit_book, delete_book)
 * @param string $book_title - Title of the book
 * @param int|null $book_id - Book ID
 * @return bool - Success status
 */
function logBookManagement($action, $book_title, $book_id = null) {
    $details = match($action) {
        'add_book' => "Added new book: $book_title",
        'edit_book' => "Edited book: $book_title (ID: $book_id)",
        'delete_book' => "Deleted book: $book_title (ID: $book_id)",
        default => "Book action: $action - $book_title"
    };
    
    return logActivity($action, $details);
}

/**
 * Log customer management activity (admin)
 * 
 * @param string $action - Action type (approve_customer, block_customer, unblock_customer, delete_customer)
 * @param string $customer_username - Username of the customer
 * @param int $customer_id - Customer ID
 * @return bool - Success status
 */
function logCustomerManagement($action, $customer_username, $customer_id) {
    $details = match($action) {
        'approve_customer' => "Approved customer: $customer_username (ID: $customer_id)",
        'reject_customer' => "Rejected customer: $customer_username (ID: $customer_id)",
        'block_customer' => "Blocked customer: $customer_username (ID: $customer_id)",
        'unblock_customer' => "Unblocked customer: $customer_username (ID: $customer_id)",
        'delete_customer' => "Deleted customer: $customer_username (ID: $customer_id)",
        default => "Customer action: $action - $customer_username"
    };
    
    return logActivity($action, $details);
}

/**
 * Log admin management activity (super admin only)
 * 
 * @param string $action - Action type (add_admin, edit_admin, delete_admin)
 * @param string $admin_username - Username of the admin
 * @param int|null $admin_id - Admin ID
 * @return bool - Success status
 */
function logAdminManagement($action, $admin_username, $admin_id = null) {
    $details = match($action) {
        'add_admin' => "Added new admin: $admin_username",
        'edit_admin' => "Edited admin: $admin_username (ID: $admin_id)",
        'delete_admin' => "Deleted admin: $admin_username (ID: $admin_id)",
        'reset_password' => "Reset password for admin: $admin_username (ID: $admin_id)",
        default => "Admin action: $action - $admin_username"
    };
    
    return logActivity($action, $details);
}

/**
 * Log order management activity
 * 
 * @param string $action - Action type (update_order_status, cancel_order)
 * @param int $order_id - Order ID
 * @param string $status - Order status
 * @return bool - Success status
 */
function logOrderManagement($action, $order_id, $status) {
    $details = match($action) {
        'update_order_status' => "Updated order #$order_id status to: $status",
        'cancel_order' => "Cancelled order #$order_id",
        default => "Order action: $action - Order #$order_id"
    };
    
    return logActivity($action, $details);
}

/**
 * Log settings update activity
 * 
 * @param string $setting_name - Name of the setting updated
 * @param string $old_value - Old value
 * @param string $new_value - New value
 * @return bool - Success status
 */
function logSettingsUpdate($setting_name, $old_value, $new_value) {
    $details = "Updated setting '$setting_name' from '$old_value' to '$new_value'";
    return logActivity('update_settings', $details);
}

/**
 * Log profile update activity
 * 
 * @param string $field_updated - Field that was updated
 * @return bool - Success status
 */
function logProfileUpdate($field_updated) {
    $details = "Updated profile: $field_updated";
    return logActivity('profile_update', $details);
}
?>
