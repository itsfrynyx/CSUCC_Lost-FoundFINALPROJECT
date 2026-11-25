<?php
/**
 * Super Admin Notification System
 * Handles all notification types for Super Admin oversight
 */

class SuperAdminNotifications {
    private $conn;
    
    // Notification type constants
    const TYPE_PENDING_APPROVAL = 'pending_approval';
    const TYPE_ADMIN_ACTIVITY = 'admin_activity';
    const TYPE_CUSTOMER_ALERT = 'customer_alert';
    const TYPE_INVENTORY_ALERT = 'inventory_alert';
    const TYPE_SYSTEM_ALERT = 'system_alert';
    const TYPE_SECURITY_ALERT = 'security_alert';
    
    // Color constants
    const COLOR_AMBER = 'amber';
    const COLOR_BLUE = 'blue';
    const COLOR_ORANGE = 'orange';
    const COLOR_RED = 'red';
    const COLOR_INDIGO = 'indigo';
    
    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Create a new notification
     */
    public function create($type, $category, $title, $message, $options = []) {
        $icon = $options['icon'] ?? 'fa-bell';
        $color = $options['color'] ?? self::COLOR_BLUE;
        $priority = $options['priority'] ?? self::PRIORITY_MEDIUM;
        $related_id = $options['related_id'] ?? null;
        $related_type = $options['related_type'] ?? null;
        $redirect_url = $options['redirect_url'] ?? null;
        
        $stmt = $this->conn->prepare("
            INSERT INTO super_admin_notifications 
            (type, category, title, message, icon, color, priority, related_id, related_type, redirect_url) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "ssssssssss",
            $type, $category, $title, $message, $icon, $color, $priority,
            $related_id, $related_type, $redirect_url
        );
        
        return $stmt->execute();
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount() {
        $result = $this->conn->query("
            SELECT COUNT(*) as count 
            FROM super_admin_notifications 
            WHERE is_read = 0
        ");
        return $result->fetch_assoc()['count'] ?? 0;
    }
    
    /**
     * Get recent notifications
     */
    public function getRecent($limit = 10, $unread_only = false) {
        $where = $unread_only ? "WHERE is_read = 0" : "";
        $stmt = $this->conn->prepare("
            SELECT * FROM super_admin_notifications 
            $where
            ORDER BY priority DESC, created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id) {
        $stmt = $this->conn->prepare("
            UPDATE super_admin_notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $notification_id);
        return $stmt->execute();
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead() {
        return $this->conn->query("
            UPDATE super_admin_notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE is_read = 0
        ");
    }
    
    /**
     * Delete old read notifications (cleanup)
     */
    public function cleanup($days = 30) {
        $stmt = $this->conn->prepare("
            DELETE FROM super_admin_notifications 
            WHERE is_read = 1 
            AND read_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("i", $days);
        return $stmt->execute();
    }
    
    // ========== SPECIFIC NOTIFICATION CREATORS ==========
    
    /**
     * 1. Pending Approval Notifications
     */
    public function notifyPendingApproval($approval_type, $user_id, $user_name) {
        $titles = [
            'admin_registration' => 'New Admin Registration Request',
            'role_upgrade' => 'Admin Role Upgrade Request',
            'account_reactivation' => 'Account Reactivation Request'
        ];
        
        $title = $titles[$approval_type] ?? 'New Approval Request';
        $message = "$user_name has requested $approval_type";
        
        return $this->create(
            self::TYPE_PENDING_APPROVAL,
            $approval_type,
            $title,
            $message,
            [
                'icon' => 'fa-user-check',
                'color' => self::COLOR_AMBER,
                'priority' => self::PRIORITY_HIGH,
                'related_id' => $user_id,
                'related_type' => 'user',
                'redirect_url' => 'pending_approvals.php'
            ]
        );
    }
    
    /**
     * 2. Admin Activity Alerts
     */
    public function notifyAdminActivity($action, $admin_name, $details, $related_id = null) {
        $icons = [
            'delete_book' => 'fa-trash',
            'edit_book' => 'fa-edit',
            'cancel_order' => 'fa-times-circle',
            'bulk_action' => 'fa-layer-group',
            'export_report' => 'fa-file-export'
        ];
        
        $icon = $icons[$action] ?? 'fa-user-shield';
        $title = "Admin Action: " . ucwords(str_replace('_', ' ', $action));
        $message = "$admin_name $details";
        
        return $this->create(
            self::TYPE_ADMIN_ACTIVITY,
            $action,
            $title,
            $message,
            [
                'icon' => $icon,
                'color' => self::COLOR_BLUE,
                'priority' => self::PRIORITY_MEDIUM,
                'related_id' => $related_id,
                'related_type' => 'admin_action',
                'redirect_url' => 'admin_logs.php'
            ]
        );
    }
    
    /**
     * 3. Customer Activity Alerts (Critical Only)
     */
    public function notifyCustomerAlert($alert_type, $customer_name, $details, $related_id = null) {
        $configs = [
            'large_order' => [
                'icon' => 'fa-shopping-cart',
                'priority' => self::PRIORITY_HIGH,
                'url' => 'admin_manage_orders.php'
            ],
            'failed_checkout' => [
                'icon' => 'fa-exclamation-triangle',
                'priority' => self::PRIORITY_MEDIUM,
                'url' => 'admin_manage_customers.php'
            ],
            'suspicious_behavior' => [
                'icon' => 'fa-shield-alt',
                'priority' => self::PRIORITY_HIGH,
                'url' => 'customer_logs.php'
            ],
            'refund_request' => [
                'icon' => 'fa-undo',
                'priority' => self::PRIORITY_HIGH,
                'url' => 'admin_manage_orders.php'
            ]
        ];
        
        $config = $configs[$alert_type] ?? ['icon' => 'fa-user', 'priority' => self::PRIORITY_MEDIUM, 'url' => '#'];
        $title = "Customer Alert: " . ucwords(str_replace('_', ' ', $alert_type));
        $message = "$customer_name - $details";
        
        return $this->create(
            self::TYPE_CUSTOMER_ALERT,
            $alert_type,
            $title,
            $message,
            [
                'icon' => $config['icon'],
                'color' => self::COLOR_ORANGE,
                'priority' => $config['priority'],
                'related_id' => $related_id,
                'related_type' => 'customer',
                'redirect_url' => $config['url']
            ]
        );
    }
    
    /**
     * 4. Inventory Alerts
     */
    public function notifyInventoryAlert($alert_type, $book_title, $details, $book_id = null) {
        $configs = [
            'critical_stock' => [
                'icon' => 'fa-exclamation-circle',
                'priority' => self::PRIORITY_HIGH
            ],
            'book_inactive' => [
                'icon' => 'fa-pause-circle',
                'priority' => self::PRIORITY_MEDIUM
            ],
            'bulk_deletion' => [
                'icon' => 'fa-trash-alt',
                'priority' => self::PRIORITY_HIGH
            ]
        ];
        
        $config = $configs[$alert_type] ?? ['icon' => 'fa-book', 'priority' => self::PRIORITY_MEDIUM];
        $title = "Inventory Alert: " . ucwords(str_replace('_', ' ', $alert_type));
        $message = "$book_title - $details";
        
        return $this->create(
            self::TYPE_INVENTORY_ALERT,
            $alert_type,
            $title,
            $message,
            [
                'icon' => $config['icon'],
                'color' => self::COLOR_ORANGE,
                'priority' => $config['priority'],
                'related_id' => $book_id,
                'related_type' => 'book',
                'redirect_url' => 'admin_manage_books.php'
            ]
        );
    }
    
    /**
     * 5. System Alerts
     */
    public function notifySystemAlert($alert_type, $message, $details = '') {
        $configs = [
            'backup_failed' => [
                'icon' => 'fa-database',
                'priority' => self::PRIORITY_CRITICAL
            ],
            'system_error' => [
                'icon' => 'fa-exclamation-triangle',
                'priority' => self::PRIORITY_HIGH
            ],
            'login_failures' => [
                'icon' => 'fa-lock',
                'priority' => self::PRIORITY_HIGH
            ],
            'maintenance_warning' => [
                'icon' => 'fa-tools',
                'priority' => self::PRIORITY_MEDIUM
            ]
        ];
        
        $config = $configs[$alert_type] ?? ['icon' => 'fa-info-circle', 'priority' => self::PRIORITY_MEDIUM];
        $title = "System Alert: " . ucwords(str_replace('_', ' ', $alert_type));
        $full_message = $details ? "$message - $details" : $message;
        
        return $this->create(
            self::TYPE_SYSTEM_ALERT,
            $alert_type,
            $title,
            $full_message,
            [
                'icon' => $config['icon'],
                'color' => self::COLOR_INDIGO,
                'priority' => $config['priority'],
                'redirect_url' => 'admin_dashboard.php'
            ]
        );
    }
    
    /**
     * 6. Security Alerts
     */
    public function notifySecurityAlert($alert_type, $message, $details = '', $user_id = null) {
        $configs = [
            'failed_logins' => [
                'icon' => 'fa-shield-alt',
                'priority' => self::PRIORITY_CRITICAL
            ],
            'password_change' => [
                'icon' => 'fa-key',
                'priority' => self::PRIORITY_MEDIUM
            ],
            'new_admin_created' => [
                'icon' => 'fa-user-plus',
                'priority' => self::PRIORITY_HIGH
            ],
            'permissions_modified' => [
                'icon' => 'fa-user-lock',
                'priority' => self::PRIORITY_HIGH
            ]
        ];
        
        $config = $configs[$alert_type] ?? ['icon' => 'fa-shield', 'priority' => self::PRIORITY_HIGH];
        $title = "Security Alert: " . ucwords(str_replace('_', ' ', $alert_type));
        $full_message = $details ? "$message - $details" : $message;
        
        return $this->create(
            self::TYPE_SECURITY_ALERT,
            $alert_type,
            $title,
            $full_message,
            [
                'icon' => $config['icon'],
                'color' => self::COLOR_RED,
                'priority' => $config['priority'],
                'related_id' => $user_id,
                'related_type' => 'security',
                'redirect_url' => 'admin_logs.php'
            ]
        );
    }
    
    /**
     * Get notification settings
     */
    public function getSetting($key, $default = null) {
        $stmt = $this->conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['setting_value'] ?? $default;
    }
    
    /**
     * Format time ago
     */
    public static function timeAgo($datetime) {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        if ($diff < 60) return $diff . ' seconds ago';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('M d, Y', $timestamp);
    }
    
    /**
     * Get color class for notification
     */
    public static function getColorClass($color) {
        $colors = [
            'amber' => '#D97706',
            'blue' => '#2563EB',
            'orange' => '#F59E0B',
            'red' => '#B91C1C',
            'indigo' => '#4F46E5'
        ];
        return $colors[$color] ?? $colors['blue'];
    }
}
