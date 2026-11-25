<?php
/**
 * Migration Script: Move all customer logs into admin_activity_log table
 * This will consolidate all activity logs into a single table
 */

require_once __DIR__ . '/conn.php';

echo "<h2>Migrating Customer Logs to Admin Activity Log</h2>";
echo "<pre>";

// Start transaction
$conn->begin_transaction();

try {
    // Step 1: Check current counts
    $admin_count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log")->fetch_assoc()['count'];
    $customer_count = $conn->query("SELECT COUNT(*) as count FROM customer_activity_log")->fetch_assoc()['count'];
    
    echo "Current Status:\n";
    echo "- Admin Activity Log: {$admin_count} records\n";
    echo "- Customer Activity Log: {$customer_count} records\n\n";
    
    if ($customer_count == 0) {
        echo "✓ No customer logs to migrate. All done!\n";
        $conn->commit();
        exit;
    }
    
    // Step 2: Insert customer logs into admin_activity_log
    echo "Step 1: Migrating {$customer_count} customer logs...\n";
    
    $migrate_query = "
        INSERT INTO admin_activity_log 
            (user_id, user_type, session_id, action, description, ip_address, user_agent, created_at)
        SELECT 
            cal.customer_id as user_id,
            'customer' as user_type,
            cal.session_id,
            cal.activity_type as action,
            cal.activity_details as description,
            cal.ip_address,
            cal.user_agent,
            cal.timestamp as created_at
        FROM customer_activity_log cal
        ORDER BY cal.timestamp ASC
    ";
    
    $result = $conn->query($migrate_query);
    
    if ($result) {
        $migrated_count = $conn->affected_rows;
        echo "✓ Successfully migrated {$migrated_count} customer logs to admin_activity_log\n\n";
    } else {
        throw new Exception("Failed to migrate customer logs: " . $conn->error);
    }
    
    // Step 3: Verify migration
    echo "Step 2: Verifying migration...\n";
    $new_admin_count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log")->fetch_assoc()['count'];
    $expected_count = $admin_count + $customer_count;
    
    echo "- Expected total: {$expected_count}\n";
    echo "- Actual total: {$new_admin_count}\n";
    
    if ($new_admin_count == $expected_count) {
        echo "✓ Verification successful!\n\n";
    } else {
        throw new Exception("Verification failed! Count mismatch.");
    }
    
    // Step 4: Check for customer logs in admin_activity_log
    $customer_in_admin = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE user_type = 'customer'")->fetch_assoc()['count'];
    echo "- Customer logs in admin_activity_log: {$customer_in_admin}\n\n";
    
    // Step 5: Backup customer_activity_log table (rename it)
    echo "Step 3: Backing up customer_activity_log table...\n";
    $backup_table_name = "customer_activity_log_backup_" . date('Ymd_His');
    $conn->query("RENAME TABLE customer_activity_log TO {$backup_table_name}");
    echo "✓ Table renamed to: {$backup_table_name}\n";
    echo "  (You can drop this table later if everything works fine)\n\n";
    
    // Commit transaction
    $conn->commit();
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "✓ MIGRATION COMPLETED SUCCESSFULLY!\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    echo "Summary:\n";
    echo "- Total records migrated: {$migrated_count}\n";
    echo "- New total in admin_activity_log: {$new_admin_count}\n";
    echo "- Customer logs backed up to: {$backup_table_name}\n\n";
    
    echo "Next Steps:\n";
    echo "1. Update admin_logs.php to use only admin_activity_log table\n";
    echo "2. Test the Activity Logs page thoroughly\n";
    echo "3. If everything works, you can drop the backup table:\n";
    echo "   DROP TABLE {$backup_table_name};\n\n";
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Transaction rolled back. No changes were made.\n";
}

echo "</pre>";
?>
