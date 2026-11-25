<?php
/**
 * Activity Logger
 * Automatically logs all admin and customer actions
 */

class ActivityLogger {
    private $conn;
    private $user_id;
    private $user_type;
    
    public function __construct($db_connection, $user_id = null, $user_type = null) {
        $this->conn = $db_connection;
        $this->user_id = $user_id ?? $_SESSION['super_admin_id'] ?? $_SESSION['user_id'] ?? null;
        
        // Determine user type if not provided
        if (!$user_type && $this->user_id) {
            $stmt = $this->conn->prepare("SELECT role FROM infos WHERE id = ?");
            $stmt->bind_param("i", $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $this->user_type = $row['role'];
            }
        } else {
            $this->user_type = $user_type;
        }
    }
    
    /**
     * Log an action to the appropriate log table
     * @param string $action Action performed (e.g., 'create_book', 'edit_customer')
     * @param string $description Detailed description of the action
     * @param array $data Additional data to store (optional)
     */
    public function log($action, $description, $data = []) {
        if (!$this->user_id) {
            return false;
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $session_id = session_id();
        
        // Determine which log table to use based on user type
        if (in_array($this->user_type, ['super_admin', 'admin'])) {
            // Log to admin_activity_log
            $this->logToAdminLog($action, $description, $ip_address, $user_agent, $session_id);
        } else {
            // Log to user_activity_logs (customer)
            $this->logToCustomerLog($action, $description, $ip_address, $user_agent, $session_id);
        }
        
        return true;
    }
    
    /**
     * Log to admin_activity_log table
     */
    private function logToAdminLog($action, $description, $ip_address, $user_agent, $session_id) {
        $stmt = $this->conn->prepare("
            INSERT INTO admin_activity_log 
            (user_id, user_type, session_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param("issssss", 
            $this->user_id,
            $this->user_type,
            $session_id,
            $action,
            $description,
            $ip_address,
            $user_agent
        );
        
        return $stmt->execute();
    }
    
    /**
     * Log to user_activity_logs table (customer logs)
     */
    private function logToCustomerLog($action, $description, $ip_address, $user_agent, $session_id) {
        // Map action to activity_type
        $activity_type = $this->mapActionToActivityType($action);
        
        $stmt = $this->conn->prepare("
            INSERT INTO user_activity_logs 
            (user_id, session_id, activity_type, activity_details, ip_address, user_agent, timestamp) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            error_log("ActivityLogger: Failed to prepare statement: " . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("isssss",
            $this->user_id,
            $session_id,
            $activity_type,
            $description,
            $ip_address,
            $user_agent
        );
        
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("ActivityLogger: Failed to execute: " . $stmt->error);
            error_log("ActivityLogger: user_id=$this->user_id, activity_type=$activity_type, details=$description");
        }
        
        return $result;
    }
    
    /**
     * Map action names to activity_type enum values
     */
    private function mapActionToActivityType($action) {
        $mapping = [
            'login' => 'login',
            'logout' => 'logout',
            'view_page' => 'page_view',
            'view_book' => 'book_view',
            'search' => 'search',
            'add_to_cart' => 'add_to_cart',
            'remove_from_cart' => 'remove_from_cart',
            'checkout' => 'checkout',
            'place_order' => 'checkout',
            'create_review' => 'review',
            'edit_review' => 'review',
            'add_to_wishlist' => 'wishlist_add',
            'remove_from_wishlist' => 'wishlist_remove'
        ];
        
        return $mapping[$action] ?? 'page_view';
    }
    
    /**
     * Quick log methods for common actions
     */
    
    public function logLogin() {
        return $this->log('login', 'User logged in');
    }
    
    public function logLogout() {
        return $this->log('logout', 'User logged out');
    }
    
    public function logPageView($page_name) {
        // Page view logging has been disabled
        return true;
    }
    
    public function logBookAction($action, $book_id, $book_title) {
        $descriptions = [
            'create' => "Created book: $book_title (ID: $book_id)",
            'edit' => "Edited book: $book_title (ID: $book_id)",
            'delete' => "Deleted book: $book_title (ID: $book_id)",
            'view' => "Viewed book: $book_title (ID: $book_id)"
        ];
        
        return $this->log("{$action}_book", $descriptions[$action] ?? "Book action: $action");
    }
    
    public function logCustomerAction($action, $customer_id, $customer_name) {
        $descriptions = [
            'create' => "Created customer: $customer_name (ID: $customer_id)",
            'edit' => "Edited customer: $customer_name (ID: $customer_id)",
            'delete' => "Deleted customer: $customer_name (ID: $customer_id)",
            'block' => "Blocked customer: $customer_name (ID: $customer_id)",
            'unblock' => "Unblocked customer: $customer_name (ID: $customer_id)",
            'view' => "Viewed customer: $customer_name (ID: $customer_id)"
        ];
        
        return $this->log("{$action}_customer", $descriptions[$action] ?? "Customer action: $action");
    }
    
    public function logAdminAction($action, $admin_id, $admin_name) {
        $descriptions = [
            'create' => "Created admin: $admin_name (ID: $admin_id)",
            'edit' => "Edited admin: $admin_name (ID: $admin_id)",
            'delete' => "Deleted admin: $admin_name (ID: $admin_id)",
            'view' => "Viewed admin: $admin_name (ID: $admin_id)"
        ];
        
        return $this->log("{$action}_admin", $descriptions[$action] ?? "Admin action: $action");
    }
    
    public function logOrderAction($action, $order_id, $order_number) {
        $descriptions = [
            'create' => "Created order: $order_number (ID: $order_id)",
            'edit' => "Edited order: $order_number (ID: $order_id)",
            'cancel' => "Cancelled order: $order_number (ID: $order_id)",
            'view' => "Viewed order: $order_number (ID: $order_id)"
        ];
        
        return $this->log("{$action}_order", $descriptions[$action] ?? "Order action: $action");
    }
    
    public function logReviewAction($action, $review_id, $book_title) {
        $descriptions = [
            'create' => "Created review for: $book_title (Review ID: $review_id)",
            'edit' => "Edited review for: $book_title (Review ID: $review_id)",
            'delete' => "Deleted review for: $book_title (Review ID: $review_id)",
            'approve' => "Approved review for: $book_title (Review ID: $review_id)",
            'reject' => "Rejected review for: $book_title (Review ID: $review_id)"
        ];
        
        return $this->log("{$action}_review", $descriptions[$action] ?? "Review action: $action");
    }
    
    public function logCartAction($action, $book_title) {
        if ($action === 'add') {
            return $this->log('add_to_cart', "Added to cart: $book_title");
        } else {
            return $this->log('remove_from_cart', "Removed from cart: $book_title");
        }
    }
    
    public function logWishlistAction($action, $book_title) {
        if ($action === 'add') {
            return $this->log('add_to_wishlist', "Added to wishlist: $book_title");
        } else {
            return $this->log('remove_from_wishlist', "Removed from wishlist: $book_title");
        }
    }
    
    public function logSearch($search_query) {
        return $this->log('search', "Searched for: $search_query");
    }
    
    public function logSettingsChange($setting_name, $old_value, $new_value) {
        return $this->log('change_settings', "Changed setting '$setting_name' from '$old_value' to '$new_value'");
    }
    
    public function logPermissionDenied($attempted_action, $required_permission) {
        return $this->log('permission_denied', "Access denied: Attempted '$attempted_action' (requires '$required_permission')");
    }
}
?>
