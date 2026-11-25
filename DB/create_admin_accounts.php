<?php
/**
 * Create Admin Accounts Script
 * Maghimo ug Super Admin ug System Admin accounts
 */

require_once 'conn.php';

// Set execution time
set_time_limit(60);

// Admin account configurations
$accounts = [
    [
        'type' => 'Super Admin',
        'idnumber' => 'SA-2026-001',
        'firstname' => 'Super',
        'lastname' => 'Administrator',
        'middlename' => 'System',
        'full_name' => 'Super Administrator',
        'sex' => 'Male',
        'age' => '30',
        'birth' => '1994-01-01',
        'email' => 'superadmin@curiosityquills.com',
        'username' => 'superadmin',
        'password' => 'SuperAdmin@2026',
        'role' => 'super_admin',
        'dashboard' => 'admin_dashboard.php'
    ]
];

// Security questions and answers (same for both)
$security = [
    'q1' => 'What is your favorite book?',
    'a1' => 'Lost & Found',
    'q2' => 'What is your first pet name?',
    'a2' => 'Admin',
    'q3' => 'What city were you born in?',
    'a3' => 'Cebu'
];

// Default address
$address = [
    'street' => 'Admin Office',
    'barangay' => 'Central',
    'municipality' => 'Cebu City',
    'province' => 'Cebu',
    'postal' => '6000',
    'country' => 'Philippines'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin Accounts</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        h1 {
            color: #882b2a;
            border-bottom: 3px solid #882b2a;
            padding-bottom: 10px;
        }
        .account-box {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #882b2a;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .warning {
            color: #ffc107;
            font-weight: bold;
        }
        .info {
            color: #17a2b8;
        }
        .credentials {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .credentials strong {
            color: #2e7d32;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #882b2a;
            color: white;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #882b2a;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #6d2221;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ðŸ” Create Admin Accounts</h1>
        <p>Maghimo ug Super Admin ug System Admin accounts para sa Campus Lost & Found</p>

        <?php
        $created_accounts = [];
        $errors = [];

        foreach ($accounts as $account) {
            echo "<div class='account-box'>";
            echo "<h3>Creating {$account['type']} Account...</h3>";
            
            // Check if username already exists
            $check_stmt = $conn->prepare("SELECT id, username, role FROM infos WHERE username = ?");
            $check_stmt->bind_param("s", $account['username']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                echo "<p class='warning'>âš  Username '{$account['username']}' already exists!</p>";
                echo "<p class='info'>Existing account details:</p>";
                echo "<ul>";
                echo "<li>ID: {$existing['id']}</li>";
                echo "<li>Username: {$existing['username']}</li>";
                echo "<li>Role: {$existing['role']}</li>";
                echo "</ul>";
                
                // Update role if different
                if ($existing['role'] !== $account['role']) {
                    $update_stmt = $conn->prepare("UPDATE infos SET role = ? WHERE username = ?");
                    $update_stmt->bind_param("ss", $account['role'], $account['username']);
                    if ($update_stmt->execute()) {
                        echo "<p class='success'>âœ“ Role updated to: {$account['role']}</p>";
                    }
                }
                
                $errors[] = "Username '{$account['username']}' already exists";
                echo "</div>";
                continue;
            }
            
            // Hash password and security answers
            $hashed_password = password_hash($account['password'], PASSWORD_BCRYPT);
            $hashed_a1 = password_hash($security['a1'], PASSWORD_BCRYPT);
            $hashed_a2 = password_hash($security['a2'], PASSWORD_BCRYPT);
            $hashed_a3 = password_hash($security['a3'], PASSWORD_BCRYPT);
            
            // Prepare insert statement
            $stmt = $conn->prepare("
                INSERT INTO infos (
                    idnumber, firstname, lastname, middlename, full_name,
                    sex, age, birth, email, username, passw, role, status,
                    street, barangay, municipality, province, postal, country,
                    security_q1, security_a1, security_q2, security_a2, security_q3, security_a3,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $status = 'active';
            
            $stmt->bind_param(
                "sssssssssssssssssssssssss",
                $account['idnumber'],
                $account['firstname'],
                $account['lastname'],
                $account['middlename'],
                $account['full_name'],
                $account['sex'],
                $account['age'],
                $account['birth'],
                $account['email'],
                $account['username'],
                $hashed_password,
                $account['role'],
                $status,
                $address['street'],
                $address['barangay'],
                $address['municipality'],
                $address['province'],
                $address['postal'],
                $address['country'],
                $security['q1'],
                $hashed_a1,
                $security['q2'],
                $hashed_a2,
                $security['q3'],
                $hashed_a3
            );
            
            if ($stmt->execute()) {
                $account_id = $conn->insert_id;
                echo "<p class='success'>âœ“ {$account['type']} account created successfully!</p>";
                echo "<p class='info'>Account ID: {$account_id}</p>";
                
                echo "<div class='credentials'>";
                echo "<strong>LOGIN CREDENTIALS:</strong><br>";
                echo "Username: <strong>{$account['username']}</strong><br>";
                echo "Password: <strong>{$account['password']}</strong><br>";
                echo "Role: <strong>{$account['role']}</strong><br>";
                echo "Dashboard: <strong>{$account['dashboard']}</strong><br>";
                echo "</div>";
                
                $created_accounts[] = [
                    'id' => $account_id,
                    'type' => $account['type'],
                    'username' => $account['username'],
                    'password' => $account['password'],
                    'role' => $account['role'],
                    'dashboard' => $account['dashboard']
                ];
            } else {
                echo "<p class='error'>âœ— Error creating account: " . $conn->error . "</p>";
                $errors[] = "Failed to create {$account['type']}: " . $conn->error;
            }
            
            echo "</div>";
        }
        ?>

        <?php if (!empty($created_accounts)): ?>
        <div style="background: #e8f5e9; padding: 20px; border-radius: 8px; margin-top: 20px;">
            <h2>âœ… Summary of Created Accounts</h2>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>Role</th>
                        <th>Dashboard</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($created_accounts as $acc): ?>
                    <tr>
                        <td><?= $acc['type'] ?></td>
                        <td><strong><?= $acc['username'] ?></strong></td>
                        <td><strong><?= $acc['password'] ?></strong></td>
                        <td><?= $acc['role'] ?></td>
                        <td><?= $acc['dashboard'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php
        // Verify accounts
        echo "<div style='margin-top: 30px;'>";
        echo "<h2>ðŸ” Verification - All Admin Accounts</h2>";
        
        $verify_result = $conn->query("
            SELECT id, username, email, role, full_name, status, created_at 
            FROM infos 
            WHERE role IN ('super_admin', 'admin')
            ORDER BY role DESC, created_at DESC
        ");
        
        if ($verify_result && $verify_result->num_rows > 0) {
            echo "<table>";
            echo "<thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Full Name</th><th>Status</th><th>Created</th></tr></thead>";
            echo "<tbody>";
            while ($row = $verify_result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td><strong>{$row['username']}</strong></td>";
                echo "<td>{$row['email']}</td>";
                echo "<td><span style='color: " . ($row['role'] === 'super_admin' ? '#dc3545' : '#ffc107') . ";'><strong>{$row['role']}</strong></span></td>";
                echo "<td>{$row['full_name']}</td>";
                echo "<td>{$row['status']}</td>";
                echo "<td>{$row['created_at']}</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p class='warning'>No admin accounts found in database.</p>";
        }
        echo "</div>";
        ?>

        <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-top: 20px;">
            <h3>âš ï¸ IMPORTANTE:</h3>
            <ul>
                <li><strong>Usba ang password</strong> human sa first login!</li>
                <li><strong>Luwasa ang credentials</strong> sa secure nga lugar</li>
                <li><strong>Dili ihatag</strong> ang admin credentials sa uban</li>
                <li><strong>Test ang login</strong> sa Sign_in.php</li>
            </ul>
        </div>

        <div style="margin-top: 20px;">
            <h3>ðŸ“ Next Steps:</h3>
            <ol>
                <li>Test Super Admin login: <code>superadmin / SuperAdmin@2026</code></li>
                <li>Test System Admin login: <code>systemadmin / SystemAdmin@2026</code></li>
                <li>Verify redirects:
                    <ul>
                        <li>Super Admin â†’ admin_dashboard.php</li>
                        <li>System Admin â†’ unified_admin_dashboard.php</li>
                    </ul>
                </li>
                <li>Change passwords after first login</li>
            </ol>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="../php/Sign_in.php" class="btn">Go to Login Page</a>
            <a href="index.html" class="btn" style="background: #17a2b8;">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>


