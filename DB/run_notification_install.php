<?php
require_once 'conn.php';

echo "Installing Super Admin Notification System...\n\n";

// Create super_admin_notifications table
$sql1 = "CREATE TABLE IF NOT EXISTS super_admin_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM(
        'pending_approval',
        'admin_activity',
        'customer_alert',
        'inventory_alert',
        'system_alert',
        'security_alert'
    ) NOT NULL,
    category VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-bell',
    color ENUM('amber', 'blue', 'orange', 'red', 'indigo') DEFAULT 'blue',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    related_id INT DEFAULT NULL,
    related_type VARCHAR(50) DEFAULT NULL,
    redirect_url VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_type (type),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql1)) {
    echo "✅ Created super_admin_notifications table\n";
} else {
    echo "❌ Error creating super_admin_notifications: " . $conn->error . "\n";
}

// Create notification_settings table
$sql2 = "CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql2)) {
    echo "✅ Created notification_settings table\n";
} else {
    echo "❌ Error creating notification_settings: " . $conn->error . "\n";
}

// Insert default settings
$sql3 = "INSERT INTO notification_settings (setting_key, setting_value, description) VALUES
('large_order_threshold', '5000', 'Order amount threshold for customer alerts (in currency)'),
('critical_stock_level', '5', 'Stock quantity threshold for inventory alerts'),
('failed_login_threshold', '3', 'Number of failed login attempts before security alert'),
('bulk_action_threshold', '10', 'Number of items in bulk action to trigger alert')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";

if ($conn->query($sql3)) {
    echo "✅ Inserted default notification settings\n";
} else {
    echo "❌ Error inserting settings: " . $conn->error . "\n";
}

echo "\n🎉 Installation Complete!\n";
echo "Go to your Super Admin Dashboard and click the notification bell 🔔\n";

$conn->close();
