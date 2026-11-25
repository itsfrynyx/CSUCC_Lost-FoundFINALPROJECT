<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: browse_books.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = intval($_GET['id']);

// Track recently viewed
if ($conn->query("SHOW TABLES LIKE 'recently_viewed'")->num_rows > 0) {
    $conn->query("DELETE FROM recently_viewed WHERE user_id = $user_id AND book_id = $book_id");
    $conn->query("INSERT INTO recently_viewed (user_id, book_id, viewed_at) VALUES ($user_id, $book_id, NOW())");
}

// Get book details
$stmt = $conn->prepare("SELECT * FROM books WHERE id = ? AND is_active = 1");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();

if (!$book) {
    header("Location: browse_books.php");
    exit();
}

$stmt = $conn->prepare("SELECT i.*, up.profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Check if in wishlist (with table existence check)
$in_wishlist = false;
$check_wishlist = $conn->query("SHOW TABLES LIKE 'wishlist'");
if ($check_wishlist && $check_wishlist->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND book_id = ?");
    $stmt->bind_param("ii", $user_id, $book_id);
    $stmt->execute();
    $in_wishlist = $stmt->get_result()->num_rows > 0;
}

// Check if in cart
$stmt = $conn->prepare("SELECT quantity FROM shopping_cart WHERE user_id = ? AND book_id = ?");
$stmt->bind_param("ii", $user_id, $book_id);
$stmt->execute();
$cart_item = $stmt->get_result()->fetch_assoc();

// Get related books
$related = $conn->query("SELECT * FROM books WHERE genre = '{$book['genre']}' AND id != $book_id AND is_active = 1 LIMIT 4")->fetch_all(MYSQLI_ASSOC);

$cover = empty($book['cover_image']) || $book['cover_image'] === '0' 
    ? '../PICTURES/default-book.jpg' 
    : '../uploads/books/' . $book['cover_image'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($book['title']) ?> - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background: #FAF9F6; 
            padding-top: 70px;
            color: #1F2937;
            line-height: 1.6;
        }
        
        .main-content { 
            margin-left: 260px; 
            padding: 32px 48px 48px; 
            max-width: 1600px;
            width: calc(100% - 260px);
            min-height: calc(100vh - 70px);
        }
        
        /* Breadcrumb - Minimal */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: 0.875rem;
            color: #6B7280;
        }
        .breadcrumb-nav a {
            color: #6B7280;
            text-decoration: none;
            transition: color 0.2s;
        }
        .breadcrumb-nav a:hover {
            color: #1E3A5F;
        }
        .breadcrumb-nav .current {
            color: #1E3A5F;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 300px;
        }
        
        /* Book Detail Layout */
        .book-detail-container {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 32px;
            margin-bottom: 32px;
        }
        
        /* Book Image Section - Clean */
        .book-image-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: sticky;
            top: 100px;
            height: fit-content;
        }
        .book-image-wrapper {
            width: 100%;
            aspect-ratio: 2/3;
            border-radius: 8px;
            overflow: hidden;
            background: #F3F4F6;
            margin-bottom: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .book-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .quick-actions {
            display: flex;
            gap: 8px;
        }
        .quick-action-btn {
            flex: 1;
            padding: 10px;
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.875rem;
            color: #374151;
        }
        .quick-action-btn:hover {
            background: white;
            border-color: #1E3A5F;
            color: #1E3A5F;
        }
        .quick-action-btn.active {
            background: #1E3A5F;
            border-color: #1E3A5F;
            color: white;
        }
        
        /* Book Info Section - Clean */
        .book-info-section {
            background: white;
            padding: 32px;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .book-title {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .book-author {
            font-size: 1.125rem;
            color: #6B7280;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .book-author i {
            color: #1E3A5F;
        }
        
        .book-price {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1E3A5F;
            margin-bottom: 24px;
        }
        
        /* Book Meta - Clean Grid */
        .book-meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #F3F4F6;
        }
        .meta-item {
            background: #F9FAFB;
            padding: 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .meta-icon {
            width: 36px;
            height: 36px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1E3A5F;
            font-size: 0.95rem;
        }
        .meta-content {
            flex: 1;
        }
        .meta-label {
            font-size: 0.75rem;
            color: #6B7280;
            margin-bottom: 2px;
        }
        .meta-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
        }
        
        /* Stock Status */
        .stock-status {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .stock-status.in-stock {
            background: #D1FAE5;
            color: #065F46;
        }
        .stock-status.low-stock {
            background: #FEF3C7;
            color: #92400E;
        }
        .stock-status.out-of-stock {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        /* Description */
        .book-description {
            color: #374151;
            line-height: 1.8;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #F3F4F6;
        }
        .book-description h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
        }
        .btn {
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary {
            flex: 1;
            background: linear-gradient(135deg, #1E3A5F 0%, #2C5282 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(30, 58, 95, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 58, 95, 0.35);
        }
        .btn-primary:disabled {
            background: #E5E7EB;
            color: #9CA3AF;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-secondary {
            background: white;
            color: #1E3A5F;
            border: 2px solid #E5E7EB;
        }
        .btn-secondary:hover {
            background: #F9FAFB;
            border-color: #1E3A5F;
        }
        
        /* Related Books Section */
        .related-section {
            background: white;
            padding: 28px 32px;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .related-section h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #F3F4F6;
        }
        .related-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }
        .related-card {
            text-decoration: none;
            color: #111827;
            background: #FAFBFC;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.2s;
        }
        .related-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            border-color: #C19A6B;
        }
        .related-card img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            background: #F3F4F6;
        }
        .related-card-body {
            padding: 14px 16px 16px;
        }
        .related-card h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .related-card .author {
            font-size: 0.75rem;
            color: #6B7280;
            margin-bottom: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .related-card .price {
            font-size: 1rem;
            font-weight: 700;
            color: #1E3A5F;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .book-detail-container {
                grid-template-columns: 280px 1fr;
            }
            .related-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
                width: 100%;
            }
            .book-detail-container {
                grid-template-columns: 1fr;
            }
            .book-image-section {
                position: static;
            }
            .book-meta {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                flex-direction: column;
            }
            .related-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Smooth Fade In */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .main-content > * {
            animation: fadeIn 0.4s ease-out;
            animation-fill-mode: both;
        }
        .breadcrumb-nav { animation-delay: 0.05s; }
        .book-detail-container { animation-delay: 0.1s; }
        .related-section { animation-delay: 0.15s; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <!-- Breadcrumb -->
    <nav class="breadcrumb-nav">
        <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
        <span>/</span>
        <a href="browse_books.php"><i class="fas fa-book"></i> Books</a>
        <span>/</span>
        <span class="current"><?= htmlspecialchars($book['title']) ?></span>
    </nav>

    <!-- Book Detail -->
    <div class="book-detail-container">
        <!-- Book Image Section -->
        <div class="book-image-section">
            <div class="book-image-wrapper">
                <img src="<?= htmlspecialchars($cover) ?>" 
                     alt="<?= htmlspecialchars($book['title']) ?>"
                     onerror="this.src='../PICTURES/default-book.jpg'">
            </div>
            
            <div class="quick-actions">
                <button class="quick-action-btn <?= $in_wishlist ? 'active' : '' ?>" 
                        onclick="toggleWishlist(<?= $book_id ?>)" 
                        id="wishlist-btn">
                    <i class="fas fa-heart"></i>
                    <?= $in_wishlist ? 'Saved' : 'Save' ?>
                </button>
                <button class="quick-action-btn" onclick="shareBook()">
                    <i class="fas fa-share-alt"></i>
                    Share
                </button>
            </div>
        </div>

        <!-- Book Info Section -->
        <div class="book-info-section">
            <h1 class="book-title"><?= htmlspecialchars($book['title']) ?></h1>
            <div class="book-author">
                <i class="fas fa-user-edit"></i>
                by <?= htmlspecialchars($book['author']) ?>
            </div>
            
            <div class="book-price">â‚±<?= number_format($book['price'], 2) ?></div>
            
            <!-- Book Meta -->
            <div class="book-meta">
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div class="meta-content">
                        <div class="meta-label">Genre</div>
                        <div class="meta-value"><?= htmlspecialchars($book['genre']) ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <div class="meta-content">
                        <div class="meta-label">ISBN</div>
                        <div class="meta-value"><?= htmlspecialchars($book['isbn']) ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="meta-content">
                        <div class="meta-label">Stock</div>
                        <div class="meta-value"><?= $book['stock_quantity'] ?> available</div>
                    </div>
                </div>
            </div>
            
            <!-- Stock Status -->
            <?php if ($book['stock_quantity'] <= 0): ?>
                <div class="stock-status out-of-stock">
                    <i class="fas fa-times-circle"></i>
                    Out of Stock
                </div>
            <?php elseif ($book['stock_quantity'] <= 5): ?>
                <div class="stock-status low-stock">
                    <i class="fas fa-exclamation-circle"></i>
                    Only <?= $book['stock_quantity'] ?> left in stock - Order soon!
                </div>
            <?php else: ?>
                <div class="stock-status in-stock">
                    <i class="fas fa-check-circle"></i>
                    In Stock - Ready to ship
                </div>
            <?php endif; ?>
            
            <!-- Description -->
            <div class="book-description">
                <h3>About This Book</h3>
                <p><?= nl2br(htmlspecialchars($book['description'])) ?></p>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if ($book['stock_quantity'] > 0): ?>
                    <?php if ($cart_item): ?>
                        <button class="btn btn-primary" onclick="window.location.href='cart.php'">
                            <i class="fas fa-shopping-cart"></i>
                            View in Cart (<?= $cart_item['quantity'] ?>)
                        </button>
                    <?php else: ?>
                        <button class="btn btn-primary" onclick="addToCart(<?= $book_id ?>)">
                            <i class="fas fa-shopping-cart"></i>
                            Add to Cart
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-secondary" onclick="window.location.href='checkout.php'">
                        <i class="fas fa-bolt"></i>
                        Buy Now
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary" disabled>
                        <i class="fas fa-times-circle"></i>
                        Out of Stock
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Related Books -->
    <?php if (!empty($related)): ?>
    <div class="related-section">
        <h2>More Books in <?= htmlspecialchars($book['genre']) ?></h2>
        <div class="related-grid">
            <?php foreach ($related as $rel): ?>
                <a href="book_details.php?id=<?= $rel['id'] ?>" class="related-card">
                    <?php 
                    $rel_cover = empty($rel['cover_image']) || $rel['cover_image'] === '0' 
                        ? '../PICTURES/default-book.jpg' 
                        : '../uploads/books/' . $rel['cover_image'];
                    ?>
                    <img src="<?= htmlspecialchars($rel_cover) ?>" 
                         alt="<?= htmlspecialchars($rel['title']) ?>"
                         onerror="this.src='../PICTURES/default-book.jpg'">
                    <div class="related-card-body">
                        <h4><?= htmlspecialchars($rel['title']) ?></h4>
                        <div class="author"><?= htmlspecialchars($rel['author']) ?></div>
                        <div class="price">â‚±<?= number_format($rel['price'], 2) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    function addToCart(bookId) {
        fetch('cart_add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `book_id=${bookId}&quantity=1`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Book added to cart!');
                location.reload();
            } else {
                alert(data.message || 'Failed to add to cart');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function toggleWishlist(bookId) {
        const btn = document.getElementById('wishlist-btn');
        const isInWishlist = btn.classList.contains('active');
        
        fetch(isInWishlist ? 'wishlist_remove.php' : 'wishlist_add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `book_id=${bookId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to update wishlist');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function shareBook() {
        if (navigator.share) {
            navigator.share({
                title: '<?= htmlspecialchars($book['title']) ?>',
                text: 'Check out this book on Campus Lost & Found!',
                url: window.location.href
            });
        } else {
            navigator.clipboard.writeText(window.location.href);
            alert('Link copied to clipboard!');
        }
    }
</script>
</body>
</html>

