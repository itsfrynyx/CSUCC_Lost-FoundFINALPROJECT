<?php
require_once __DIR__ . '/../includes/account_input_validation.php';
/**
 * Move Item from Wishlist to Cart
 */

session_start();
require_once __DIR__ . '/../../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = account_positive_integer($_POST['book_id'] ?? null);

if ($book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
    exit();
}

$conn->begin_transaction();
try {
    $bookStmt = $conn->prepare('SELECT stock_quantity FROM books WHERE id = ? AND is_active = 1 FOR UPDATE');
    $bookStmt->bind_param('i', $book_id);
    $bookStmt->execute();
    $book = $bookStmt->get_result()->fetch_assoc();
    if (!$book || (int) $book['stock_quantity'] <= 0) {
        throw new DomainException('This book is unavailable.');
    }
    $wishlist = $conn->prepare('SELECT id FROM wishlist WHERE user_id = ? AND book_id = ? FOR UPDATE');
    $wishlist->bind_param('ii', $user_id, $book_id);
    $wishlist->execute();
    if (!$wishlist->get_result()->fetch_assoc()) {
        throw new DomainException('This book is no longer in your wishlist.');
    }
    $cart = $conn->prepare('SELECT id, quantity FROM shopping_cart WHERE user_id = ? AND book_id = ? FOR UPDATE');
    $cart->bind_param('ii', $user_id, $book_id);
    $cart->execute();
    $item = $cart->get_result()->fetch_assoc();
    if ($item && (int) $item['quantity'] >= (int) $book['stock_quantity']) {
        throw new DomainException('Cannot add more than available stock.');
    }
    if ($item) {
        $write = $conn->prepare('UPDATE shopping_cart SET quantity = quantity + 1 WHERE id = ? AND user_id = ?');
        $write->bind_param('ii', $item['id'], $user_id);
    } else {
        $write = $conn->prepare('INSERT INTO shopping_cart (user_id, book_id, quantity, added_at) VALUES (?, ?, 1, NOW())');
        $write->bind_param('ii', $user_id, $book_id);
    }
    $write->execute();
    $remove = $conn->prepare('DELETE FROM wishlist WHERE user_id = ? AND book_id = ?');
    $remove->bind_param('ii', $user_id, $book_id);
    $remove->execute();
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Book moved to cart successfully!'
    ]);

} catch (DomainException $error) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Move to cart error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to move book to cart']);
}
?>
