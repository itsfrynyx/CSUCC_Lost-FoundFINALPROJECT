<?php
// Script to set a specific customer account to pending approval for testing
require_once 'conn.php';

echo "=== Set Customer to Pending Approval ===\n\n";

// Get all customer accounts
$customers = $conn->query("SELECT id, username, full_name, email, approved FROM infos WHERE role = 'customer' ORDER BY created_at DESC");

if ($customers->num_rows === 0) {
    echo "No customer accounts found.\n";
    exit;
}

echo "Current Customer Accounts:\n";
echo "----------------------------\n";
while ($customer = $customers->fetch_assoc()) {
    $status = $customer['approved'] == 1 ? 'APPROVED' : ($customer['approved'] == 0 ? 'PENDING' : 'REJECTED');
    echo "ID: {$customer['id']} | Username: {$customer['username']} | Status: $status\n";
}

echo "\n----------------------------\n";
echo "Enter the customer ID to set as PENDING (or 'all' to set all customers as pending): ";
$handle = fopen("php://stdin", "r");
$input = trim(fgets($handle));

if (strtolower($input) === 'all') {
    // Set all customers to pending
    $conn->query("UPDATE infos SET approved = 0 WHERE role = 'customer'");
    echo "\n✓ All customer accounts set to PENDING approval.\n";
    
    // Also update pending_approvals table
    $conn->query("
        INSERT INTO pending_approvals (user_id, username, full_name, email, status)
        SELECT id, username, full_name, email, 'pending'
        FROM infos 
        WHERE role = 'customer' AND approved = 0
        ON DUPLICATE KEY UPDATE status = 'pending'
    ");
} elseif (is_numeric($input)) {
    $customer_id = (int)$input;
    
    // Set specific customer to pending
    $stmt = $conn->prepare("UPDATE infos SET approved = 0 WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("i", $customer_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo "\n✓ Customer ID $customer_id set to PENDING approval.\n";
        
        // Update pending_approvals table
        $customer_data = $conn->query("SELECT username, full_name, email FROM infos WHERE id = $customer_id")->fetch_assoc();
        $pending_stmt = $conn->prepare("
            INSERT INTO pending_approvals (user_id, username, full_name, email, status)
            VALUES (?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending'
        ");
        $pending_stmt->bind_param("isss", $customer_id, $customer_data['username'], $customer_data['full_name'], $customer_data['email']);
        $pending_stmt->execute();
    } else {
        echo "\n✗ Customer ID $customer_id not found or not a customer.\n";
    }
} else {
    echo "\n✗ Invalid input.\n";
}

echo "\nDone!\n";
$conn->close();
?>
