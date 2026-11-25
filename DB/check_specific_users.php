<?php
require_once __DIR__ . '/conn.php';

echo "<h2>Checking Specific User IDs</h2>";
echo "<pre>";

$user_ids = [49, 50, 51, 52];

foreach ($user_ids as $id) {
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "USER ID: {$id}\n";
    echo "═══════════════════════════════════════════════════════════\n";
    
    // Check in infos table
    $result = $conn->query("SELECT * FROM infos WHERE id = {$id}");
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo "✓ FOUND IN INFOS TABLE:\n";
        echo "  Username: {$user['username']}\n";
        echo "  Full Name: " . ($user['full_name'] ?: 'NULL') . "\n";
        echo "  Role: {$user['role']}\n";
        echo "  Email: {$user['email']}\n";
        echo "  Approved: " . ($user['approved'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "❌ NOT FOUND IN INFOS TABLE\n";
    }
    
    // Check in customer_activity_log
    $result = $conn->query("SELECT COUNT(*) as count FROM customer_activity_log WHERE customer_id = {$id}");
    $count = $result->fetch_assoc()['count'];
    echo "\nCustomer Activity Logs: {$count} records\n";
    
    // Check in admin_activity_log
    $result = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE user_id = {$id}");
    $count = $result->fetch_assoc()['count'];
    echo "Admin Activity Logs: {$count} records\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "</pre>";
?>
