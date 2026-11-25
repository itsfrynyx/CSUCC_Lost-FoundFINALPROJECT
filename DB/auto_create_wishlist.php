<?php
/**
 * Auto-Create Wishlist Table
 * This script automatically creates the wishlist table if it doesn't exist
 */

require_once 'conn.php';

// Check if wishlist table exists
$check = $conn->query("SHOW TABLES LIKE 'wishlist'");

if ($check->num_rows == 0) {
    echo "<h2>Creating Wishlist Table...</h2>";
    
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
        echo "<p style='color: green; font-size: 18px;'>✅ SUCCESS! Wishlist table created!</p>";
        echo "<p>The wishlist table has been successfully created in your database.</p>";
        echo "<p><strong>What's next?</strong></p>";
        echo "<ul>";
        echo "<li>Your Dashboard should now work without errors</li>";
        echo "<li>Users can now add books to their wishlist</li>";
        echo "<li>The wishlist feature is fully functional</li>";
        echo "</ul>";
        echo "<br><a href='../php/Dashboard.php' style='display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Go to Dashboard</a>";
    } else {
        echo "<p style='color: red;'>❌ Error creating table: " . $conn->error . "</p>";
        echo "<p>Please try creating it manually in phpMyAdmin.</p>";
    }
} else {
    echo "<h2>Wishlist Table Already Exists</h2>";
    echo "<p style='color: green;'>✅ The wishlist table is already in your database.</p>";
    echo "<p>No action needed. Your Dashboard should work fine.</p>";
    echo "<br><a href='../php/Dashboard.php' style='display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Go to Dashboard</a>";
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Wishlist Table Setup</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        h2 {
            color: white;
            margin-bottom: 20px;
        }
        p, ul {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 10px 0;
            line-height: 1.6;
        }
        ul {
            padding-left: 40px;
        }
        li {
            margin: 8px 0;
        }
        a {
            margin-top: 20px;
        }
        a:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
</body>
</html>
