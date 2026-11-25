<?php
require_once __DIR__ . '/../DB/conn.php';

echo "Adding sample ISBN values to books...\n\n";

// Sample ISBN values for your books
$isbn_updates = [
    1 => '978-971-8845-12-3',  // Bakus ni Hudas
    32 => '978-971-8845-45-6', // Bading Dong
    33 => '978-971-8845-78-9'  // Bading AWRA!
];

foreach ($isbn_updates as $book_id => $isbn) {
    $stmt = $conn->prepare("UPDATE books SET isbn = ? WHERE id = ?");
    $stmt->bind_param("si", $isbn, $book_id);
    
    if ($stmt->execute()) {
        echo "✓ Updated book ID $book_id with ISBN: $isbn\n";
    } else {
        echo "✗ Failed to update book ID $book_id: " . $conn->error . "\n";
    }
}

echo "\nDone! Check your books page to see the ISBN values.\n";
?>
