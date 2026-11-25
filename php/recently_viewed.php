<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get recently viewed books from session
$recently_viewed = [];
if (isset($_SESSION['recently_viewed']) && !empty($_SESSION['recently_viewed'])) {
    $ids = $_SESSION['recently_viewed'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM books WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $books_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Reorder books to match the session order (most recent first)
    $books_by_id = [];
    foreach ($books_result as $book) {
        $books_by_id[$book['id']] = $book;
    }
    foreach ($ids as $id) {
        if (isset($books_by_id[$id])) {
            $recently_viewed[] = $books_by_id[$id];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recently Viewed - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #F9FAFB; padding-top: 70px; }
        .main-content { margin-left: 260px; padding: 30px 40px; width: calc(100% - 260px); }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { font-size: 2rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .page-header p { color: #6B7280; }
        .book-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 24px; }
        .book-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 16px; text-decoration: none; color: #111827; transition: all 0.2s; }
        .book-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); border-color: #6366F1; }
        .book-card img { width: 100%; height: 260px; object-fit: cover; border-radius: 8px; margin-bottom: 12px; background: #F3F4F6; }
        .book-card h4 { font-size: 1rem; font-weight: 600; margin-bottom: 6px; }
        .book-card .author { font-size: 0.85rem; color: #6B7280; margin-bottom: 8px; }
        .book-card .price { font-size: 1.1rem; font-weight: 700; color: #6366F1; }
        .empty-state { text-align: center; padding: 80px 20px; }
        .empty-state i { font-size: 5rem; color: #E5E7EB; margin-bottom: 20px; }
        .empty-state h3 { font-size: 1.5rem; margin-bottom: 12px; color: #111827; }
        .empty-state p { color: #6B7280; margin-bottom: 24px; }
        .btn-primary { background: #6366F1; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; display: inline-block; font-weight: 600; }
        .btn-primary:hover { background: #4F46E5; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-history"></i> Recently Viewed</h1>
        <p>Books you've recently browsed</p>
    </div>

    <?php if (empty($recently_viewed)): ?>
        <div class="empty-state">
            <i class="fas fa-history"></i>
            <h3>No Recently Viewed Books</h3>
            <p>Start browsing to see your viewing history here</p>
            <a href="browse_books.php" class="btn-primary">Browse Books</a>
        </div>
    <?php else: ?>
        <div class="book-grid">
            <?php foreach ($recently_viewed as $book): ?>
                <?php 
                $cover = empty($book['cover_image']) || $book['cover_image'] === '0' 
                    ? '../PICTURES/default-book.jpg' 
                    : '../uploads/books/' . $book['cover_image'];
                ?>
                <a href="book_details.php?id=<?= $book['id'] ?>" class="book-card">
                    <img src="<?= htmlspecialchars($cover) ?>" 
                         alt="<?= htmlspecialchars($book['title']) ?>"
                         onerror="this.src='../PICTURES/default-book.jpg'">
                    <h4><?= htmlspecialchars($book['title']) ?></h4>
                    <div class="author"><?= htmlspecialchars($book['author']) ?></div>
                    <div class="price">â‚±<?= number_format($book['price'], 2) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>

