<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

$success = false;
$error = '';
$already_exists = false;

// Check if wishlist table exists
$check = $conn->query("SHOW TABLES LIKE 'wishlist'");

if ($check->num_rows == 0) {
    // Create wishlist table
    $sql = "CREATE TABLE `wishlist` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `book_id` int(11) NOT NULL,
      `added_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_user_book` (`user_id`, `book_id`),
      KEY `idx_user_id` (`user_id`),
      KEY `idx_book_id` (`book_id`),
      KEY `idx_added_at` (`added_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($sql)) {
        $success = true;
    } else {
        $error = $conn->error;
    }
} else {
    $already_exists = true;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Wishlist Table - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        .icon.success {
            color: #10B981;
        }
        .icon.error {
            color: #EF4444;
        }
        .icon.exists {
            color: #3B82F6;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 16px;
            color: #1F2937;
        }
        p {
            color: #6B7280;
            line-height: 1.6;
            margin-bottom: 24px;
            font-size: 1.1rem;
        }
        .details {
            background: #F3F4F6;
            border-radius: 8px;
            padding: 20px;
            margin: 24px 0;
            text-align: left;
        }
        .details h3 {
            color: #374151;
            margin-bottom: 12px;
            font-size: 1.1rem;
        }
        .details ul {
            list-style: none;
            padding: 0;
        }
        .details li {
            padding: 8px 0;
            color: #4B5563;
            display: flex;
            align-items: center;
        }
        .details li i {
            color: #10B981;
            margin-right: 10px;
            width: 20px;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s;
            margin: 8px;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn.secondary {
            background: #6B7280;
        }
        .btn.secondary:hover {
            background: #4B5563;
        }
        .error-box {
            background: #FEE2E2;
            border: 1px solid #EF4444;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
            color: #991B1B;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <i class="fas fa-check-circle icon success"></i>
            <h1>âœ… Success!</h1>
            <p>The wishlist table has been created successfully in your database.</p>
            
            <div class="details">
                <h3>What's Next?</h3>
                <ul>
                    <li><i class="fas fa-check"></i> Your wishlist feature is now active</li>
                    <li><i class="fas fa-check"></i> You can add books to your wishlist</li>
                    <li><i class="fas fa-check"></i> All wishlist pages will work</li>
                    <li><i class="fas fa-check"></i> No more error messages</li>
                </ul>
            </div>
            
            <a href="wishlist.php" class="btn">
                <i class="fas fa-heart me-2"></i> Go to Wishlist
            </a>
            <a href="Dashboard.php" class="btn secondary">
                <i class="fas fa-home me-2"></i> Dashboard
            </a>
            
        <?php elseif ($already_exists): ?>
            <i class="fas fa-info-circle icon exists"></i>
            <h1>Table Already Exists</h1>
            <p>The wishlist table is already in your database. Everything should be working fine!</p>
            
            <div class="details">
                <h3>Your Wishlist is Ready</h3>
                <ul>
                    <li><i class="fas fa-check"></i> Wishlist table exists</li>
                    <li><i class="fas fa-check"></i> Feature is fully functional</li>
                    <li><i class="fas fa-check"></i> You can start using it now</li>
                </ul>
            </div>
            
            <a href="wishlist.php" class="btn">
                <i class="fas fa-heart me-2"></i> Go to Wishlist
            </a>
            <a href="Dashboard.php" class="btn secondary">
                <i class="fas fa-home me-2"></i> Dashboard
            </a>
            
        <?php else: ?>
            <i class="fas fa-times-circle icon error"></i>
            <h1>âŒ Error Creating Table</h1>
            <p>There was an error creating the wishlist table.</p>
            
            <?php if ($error): ?>
                <div class="error-box">
                    <strong>Error Details:</strong><br>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <div class="details">
                <h3>Manual Solution</h3>
                <p style="text-align: left; color: #4B5563;">
                    1. Open phpMyAdmin: <code>http://localhost/phpmyadmin</code><br>
                    2. Select your database: <code>dajunos_security</code><br>
                    3. Click the "SQL" tab<br>
                    4. Run the SQL from: <code>DB/wishlist_table.sql</code>
                </p>
            </div>
            
            <a href="Dashboard.php" class="btn secondary">
                <i class="fas fa-home me-2"></i> Back to Dashboard
            </a>
        <?php endif; ?>
    </div>
</body>
</html>

