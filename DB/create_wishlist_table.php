<?php
/**
 * Wishlist Table Creation Script
 * 
 * This script creates the wishlist table for storing user's favorite books
 * Run this file once to create the table in your database
 */

require_once 'conn.php';

echo "<h2>Creating Wishlist Table...</h2>";

// Create wishlist table
$create_wishlist_table = "
CREATE TABLE IF NOT EXISTS `wishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_book` (`user_id`, `book_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_book_id` (`book_id`),
  KEY `idx_added_at` (`added_at`),
  CONSTRAINT `fk_wishlist_user` FOREIGN KEY (`user_id`) REFERENCES `infos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    if ($conn->query($create_wishlist_table)) {
        echo "<p style='color: green;'>✓ Wishlist table created successfully!</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Wishlist table already exists or error: " . $conn->error . "</p>";
    }
    
    // Verify table was created
    $check_table = $conn->query("SHOW TABLES LIKE 'wishlist'");
    if ($check_table->num_rows > 0) {
        echo "<p style='color: green;'>✓ Wishlist table verified in database</p>";
        
        // Show table structure
        echo "<h3>Table Structure:</h3>";
        $structure = $conn->query("DESCRIBE wishlist");
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $structure->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "<td>{$row['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Show indexes
        echo "<h3>Indexes:</h3>";
        $indexes = $conn->query("SHOW INDEX FROM wishlist");
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Key Name</th><th>Column</th><th>Unique</th></tr>";
        while ($row = $indexes->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Key_name']}</td>";
            echo "<td>{$row['Column_name']}</td>";
            echo "<td>" . ($row['Non_unique'] == 0 ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<br><h3 style='color: green;'>✓ Setup Complete!</h3>";
        echo "<p>The wishlist table has been created successfully. You can now use the wishlist feature.</p>";
        echo "<p><a href='../php/Dashboard.php'>Go to Dashboard</a></p>";
    } else {
        echo "<p style='color: red;'>✗ Error: Table was not created</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Wishlist Table Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h2, h3 {
            color: #333;
        }
        table {
            background: white;
            margin: 10px 0;
        }
        th {
            background: #667eea;
            color: white;
            padding: 8px;
        }
        td {
            padding: 8px;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        a:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
</body>
</html>
