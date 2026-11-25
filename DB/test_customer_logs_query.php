<?php
require_once __DIR__ . '/conn.php';

echo "<h2>Testing Customer Logs Query</h2>";
echo "<pre>";

// Test 1: Raw customer logs
echo "1. RAW CUSTOMER LOGS (latest 10):\n";
echo "═══════════════════════════════════════════════════════════\n";
$result = $conn->query("
    SELECT cal.*, i.username, i.full_name, i.role
    FROM customer_activity_log cal
    LEFT JOIN infos i ON cal.customer_id = i.id
    ORDER BY cal.timestamp DESC
    LIMIT 10
");

while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']} | Customer ID: {$row['customer_id']} | Username: " . ($row['username'] ?: 'NULL') . " | Role: " . ($row['role'] ?: 'NULL') . " | Action: {$row['activity_type']} | Time: {$row['timestamp']}\n";
}

// Test 2: UNION query (like Activity Logs page)
echo "\n2. UNION QUERY (admin + customer, latest 10):\n";
echo "═══════════════════════════════════════════════════════════\n";
$result = $conn->query("
    SELECT session_id, user_id, user_type, username, full_name, role, activity_action, activity_time
    FROM (
        SELECT al.session_id, al.user_id, al.user_type,
               i.username, i.full_name, i.role,
               al.action as activity_action,
               al.created_at as activity_time
        FROM admin_activity_log al 
        LEFT JOIN infos i ON al.user_id = i.id 
        
        UNION ALL
        
        SELECT cal.session_id, cal.customer_id as user_id, 'customer' as user_type,
               i.username, i.full_name, i.role,
               cal.activity_type as activity_action,
               cal.timestamp as activity_time
        FROM customer_activity_log cal 
        LEFT JOIN infos i ON cal.customer_id = i.id 
    ) AS combined_logs
    ORDER BY activity_time DESC
    LIMIT 10
");

while ($row = $result->fetch_assoc()) {
    echo "User: " . ($row['username'] ?: 'NULL') . " | Role: " . ($row['role'] ?: 'NULL') . " | Type: {$row['user_type']} | Action: {$row['activity_action']} | Time: {$row['activity_time']}\n";
}

// Test 3: Filter by customer role
echo "\n3. FILTERED BY CUSTOMER ROLE (latest 10):\n";
echo "═══════════════════════════════════════════════════════════\n";
$result = $conn->query("
    SELECT session_id, user_id, user_type, username, full_name, role, activity_action, activity_time
    FROM (
        SELECT al.session_id, al.user_id, al.user_type,
               i.username, i.full_name, i.role,
               al.action as activity_action,
               al.created_at as activity_time
        FROM admin_activity_log al 
        LEFT JOIN infos i ON al.user_id = i.id 
        
        UNION ALL
        
        SELECT cal.session_id, cal.customer_id as user_id, 'customer' as user_type,
               i.username, i.full_name, i.role,
               cal.activity_type as activity_action,
               cal.timestamp as activity_time
        FROM customer_activity_log cal 
        LEFT JOIN infos i ON cal.customer_id = i.id 
    ) AS combined_logs
    WHERE role = 'customer'
    ORDER BY activity_time DESC
    LIMIT 10
");

$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    echo "User: " . ($row['username'] ?: 'NULL') . " | Role: {$row['role']} | Type: {$row['user_type']} | Action: {$row['activity_action']} | Time: {$row['activity_time']}\n";
}

if ($count == 0) {
    echo "❌ NO RESULTS! This is the problem!\n";
}

// Test 4: Check customer IDs and their roles
echo "\n4. CUSTOMER IDs IN customer_activity_log vs infos table:\n";
echo "═══════════════════════════════════════════════════════════\n";
$result = $conn->query("
    SELECT DISTINCT cal.customer_id, i.username, i.role
    FROM customer_activity_log cal
    LEFT JOIN infos i ON cal.customer_id = i.id
    ORDER BY cal.customer_id
");

while ($row = $result->fetch_assoc()) {
    $username = $row['username'] ?: '❌ NOT FOUND IN INFOS';
    $role = $row['role'] ?: '❌ NO ROLE';
    echo "Customer ID: {$row['customer_id']} | Username: {$username} | Role: {$role}\n";
}

// Test 5: Check if there are orphaned customer logs
echo "\n5. ORPHANED CUSTOMER LOGS (customer_id not in infos table):\n";
echo "═══════════════════════════════════════════════════════════\n";
$result = $conn->query("
    SELECT cal.customer_id, COUNT(*) as count
    FROM customer_activity_log cal
    LEFT JOIN infos i ON cal.customer_id = i.id
    WHERE i.id IS NULL
    GROUP BY cal.customer_id
");

$orphaned = 0;
while ($row = $result->fetch_assoc()) {
    $orphaned++;
    echo "❌ Customer ID {$row['customer_id']}: {$row['count']} orphaned logs\n";
}

if ($orphaned == 0) {
    echo "✓ No orphaned logs found\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "DIAGNOSIS:\n";
echo "═══════════════════════════════════════════════════════════\n";

if ($count == 0) {
    echo "❌ PROBLEM: Customer logs are not showing when filtered by role!\n\n";
    echo "This could be because:\n";
    echo "1. Customer IDs in customer_activity_log don't exist in infos table\n";
    echo "2. Customers in infos table don't have role = 'customer'\n";
    echo "3. The JOIN is failing\n\n";
} else {
    echo "✓ Customer logs are working correctly!\n";
    echo "  Found {$count} customer logs when filtering by role.\n\n";
}

echo "</pre>";
?>
