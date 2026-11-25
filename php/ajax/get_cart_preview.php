<?php
/**
 * Cart Preview API Endpoint
 * Returns cart items for the dropdown preview
 */

session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $query = "
        SELECT sc.id, sc.quantity, sc.book_id,
               b.title, b.author, b.price, b.cover_image, b.stock_quantity
        FROM shopping_cart sc
        JOIN books b ON sc.book_id = b.id
        WHERE sc.user_id = ?
        ORDER BY sc.created_at DESC
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Calculate totals
    $subtotal = 0;
    foreach ($items as &$item) {
        $item['subtotal'] = $item['price'] * $item['quantity'];
        $subtotal += $item['subtotal'];
    }

    echo json_encode([
        'success' => true,
        'items' => $items,
        'subtotal' => $subtotal,
        'count' => count($items)
    ]);

} catch (Exception $e) {
    error_log("Cart preview error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to load cart']);
}
?>
