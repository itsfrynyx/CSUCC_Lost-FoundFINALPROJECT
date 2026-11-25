<?php
/**
 * Diagnostic Script: Check Activity Logs Status
 * This will show you what's in your database tables
 */

require_once __DIR__ . '/conn.php';

echo "<h2>Activity Logs Database Status</h2>";
echo "<pre>";
echo "â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•\n\n";

// Check admin_activity_log table
echo "1. ADMIN_ACTIVITY_LOG TABLE:\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$admin_count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log")->fetch_assoc()['count'];
echo "Total records: {$admin_count}\n\n";

if ($admin_count > 0) {
    // Count by user_type
    $type_counts = $conn->query("
        SELECT user_type, COUNT(*) as count 
        FROM admin_activity_log 
        GROUP BY user_type
    ");
    
    echo "Breakdown by user_type:\n";
    while ($row = $type_counts->fetch_assoc()) {
        $type = $row['user_type'] ?: '(empty/null)';
        echo "  - {$type}: {$row['count']} records\n";
    }
    echo "\n";
    
    // Count by action (excluding page_view, logout, failed_login)
    $action_counts = $conn->query("
        SELECT action, COUNT(*) as count 
        FROM admin_activity_log 
        WHERE action NOT IN ('page_view', 'logout', 'failed_login')
        GROUP BY action 
        ORDER BY count DESC 
        LIMIT 10
    ");
    
    echo "Top 10 actions (excluding page_view, logout, failed_login):\n";
    while ($row = $action_counts->fetch_assoc()) {
        echo "  - {$row['action']}: {$row['count']} records\n";
    }
    echo "\n";
    
    // Sample records
    echo "Sample records (latest 5):\n";
    $samples = $conn->query("
        SELECT al.id, al.user_id, al.user_type, al.action, al.created_at, i.username, i.role
        FROM admin_activity_log al
        LEFT JOIN infos i ON al.user_id = i.id
        ORDER BY al.created_at DESC
        LIMIT 5
    ");
    
    while ($row = $samples->fetch_assoc()) {
        $username = $row['username'] ?: 'Unknown';
        $role = $row['role'] ?: 'N/A';
        echo "  ID: {$row['id']} | User: {$username} ({$role}) | Type: {$row['user_type']} | Action: {$row['action']} | Time: {$row['created_at']}\n";
    }
    echo "\n";
} else {
    echo "âš ï¸  WARNING: No records found in admin_activity_log!\n\n";
}

// Check customer_activity_log table (if exists)
echo "2. CUSTOMER_ACTIVITY_LOG TABLE:\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$table_exists = $conn->query("SHOW TABLES LIKE 'customer_activity_log'")->num_rows > 0;

if ($table_exists) {
    $customer_count = $conn->query("SELECT COUNT(*) as count FROM customer_activity_log")->fetch_assoc()['count'];
    echo "Total records: {$customer_count}\n";
    
    if ($customer_count > 0) {
        echo "âš ï¸  WARNING: Customer logs have NOT been migrated yet!\n";
        echo "   Run the migration script to move these logs to admin_activity_log.\n\n";
        
        // Sample customer records
        echo "Sample customer records (latest 5):\n";
        $samples = $conn->query("
            SELECT cal.id, cal.customer_id, cal.activity_type, cal.timestamp, i.username, i.role
            FROM customer_activity_log cal
            LEFT JOIN infos i ON cal.customer_id = i.id
            ORDER BY cal.timestamp DESC
            LIMIT 5
        ");
        
        while ($row = $samples->fetch_assoc()) {
            $username = $row['username'] ?: 'Unknown';
            $role = $row['role'] ?: 'N/A';
            echo "  ID: {$row['id']} | User: {$username} ({$role}) | Type: {$row['activity_type']} | Time: {$row['timestamp']}\n";
        }
        echo "\n";
    } else {
        echo "âœ“ Table is empty (already migrated or no customer logs)\n\n";
    }
} else {
    echo "âœ“ Table does not exist (already migrated and backed up)\n\n";
}

// Check for backup tables
echo "3. BACKUP TABLES:\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$backup_tables = $conn->query("SHOW TABLES LIKE 'customer_activity_log_backup%'");
if ($backup_tables->num_rows > 0) {
    echo "Found backup tables:\n";
    while ($row = $backup_tables->fetch_array()) {
        $table_name = $row[0];
        $count = $conn->query("SELECT COUNT(*) as count FROM `{$table_name}`")->fetch_assoc()['count'];
        echo "  - {$table_name}: {$count} records\n";
    }
    echo "\n";
} else {
    echo "No backup tables found\n\n";
}

// Check infos table for users
echo "4. USERS IN INFOS TABLE:\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$user_counts = $conn->query("
    SELECT role, COUNT(*) as count 
    FROM infos 
    GROUP BY role
");

echo "Users by role:\n";
while ($row = $user_counts->fetch_assoc()) {
    $role = $row['role'] ?: '(empty/null)';
    echo "  - {$role}: {$row['count']} users\n";
}
echo "\n";

// Check user_session_activity
echo "5. USER_SESSION_ACTIVITY TABLE:\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$session_count = $conn->query("SELECT COUNT(*) as count FROM user_session_activity")->fetch_assoc()['count'];
echo "Total sessions: {$session_count}\n";

$active_sessions = $conn->query("SELECT COUNT(*) as count FROM user_session_activity WHERE status = 'active'")->fetch_assoc()['count'];
echo "Active sessions: {$active_sessions}\n\n";

// Final diagnosis
echo "â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•\n";
echo "DIAGNOSIS:\n";
echo "â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•\n\n";

if ($admin_count == 0) {
    echo "âŒ PROBLEM: No logs in admin_activity_log table!\n\n";
    echo "POSSIBLE CAUSES:\n";
    echo "1. No one has logged in or performed actions yet\n";
    echo "2. Activity logging is not working\n";
    echo "3. Database connection issues\n\n";
    echo "SOLUTION:\n";
    echo "- Try logging in as admin or customer\n";
    echo "- Perform some actions (view pages, manage books, etc.)\n";
    echo "- Check if logs appear\n\n";
} elseif ($table_exists && $customer_count > 0) {
    echo "âš ï¸  MIGRATION NEEDED: Customer logs exist but not migrated!\n\n";
    echo "SOLUTION:\n";
    echo "Run the migration script:\n";
    echo "http://localhost/Online%20Lost & Found%20System/DB/migrate_customer_logs_to_admin_logs.php\n\n";
} else {
    echo "âœ“ Database structure looks good!\n\n";
    
    // Check if logs are being filtered out
    $filtered_count = $conn->query("
        SELECT COUNT(*) as count 
        FROM admin_activity_log 
        WHERE action NOT IN ('page_view', 'logout', 'failed_login')
    ")->fetch_assoc()['count'];
    
    echo "Records after filtering (excluding page_view, logout, failed_login): {$filtered_count}\n\n";
    
    if ($filtered_count == 0) {
        echo "âš ï¸  WARNING: All logs are being filtered out!\n";
        echo "All your logs are page_view, logout, or failed_login actions.\n";
        echo "Try performing other actions like:\n";
        echo "- Managing books\n";
        echo "- Managing customers\n";
        echo "- Updating settings\n\n";
    } else {
        echo "âœ“ You have {$filtered_count} displayable logs!\n\n";
        echo "If Activity Logs page shows 'No logs found', check:\n";
        echo "1. Applied filters (date range, role, search)\n";
        echo "2. PHP error log for SQL errors\n";
        echo "3. Browser console for JavaScript errors\n\n";
    }
}

echo "â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•\n";
echo "</pre>";
?>

