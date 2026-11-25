<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: Sign_in.php"); exit(); }
// Legacy bookstore route: send users to the Lost & Found item list instead
// of querying the removed `books` table.
header('Location: user_found_items.php');
exit();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/activity_logger.php';

$user_id = $_SESSION['user_id'];

// Log page view
logPageView('Browse Books');

// Get user data
$stmt = $conn->prepare("SELECT firstname, lastname, profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get stats
$get_stats = function() use ($conn, $user_id) {
    $stats = ['cart_items' => 0, 'wishlist_items' => 0];
    try { $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shopping_cart WHERE user_id = ?"); $stmt->bind_param("i", $user_id); $stmt->execute(); $stats['cart_items'] = $stmt->get_result()->fetch_assoc()['count']; } catch (Exception $e) { $stats['cart_items'] = 0; }
    try { $stmt = $conn->prepare("SELECT COUNT(*) as count FROM wishlist WHERE user_id = ?"); $stmt->bind_param("i", $user_id); $stmt->execute(); $stats['wishlist_items'] = $stmt->get_result()->fetch_assoc()['count']; } catch (Exception $e) { $stats['wishlist_items'] = 0; }
    return $stats;
};
$stats = $get_stats();

// Get filter and search parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$genre = isset($_GET['genre']) ? $_GET['genre'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Log search activity
if ($search) {
    logSearch($search);
}

// Build query
$books_query = "SELECT * FROM books WHERE is_active = 1";
if ($search) {
    $books_query .= " AND (title LIKE '%" . $conn->real_escape_string($search) . "%' OR author LIKE '%" . $conn->real_escape_string($search) . "%' OR isbn LIKE '%" . $conn->real_escape_string($search) . "%' OR description LIKE '%" . $conn->real_escape_string($search) . "%')";
}
if ($genre) {
    $books_query .= " AND genre = '" . $conn->real_escape_string($genre) . "'";
}

// Sorting
switch($sort) {
    case 'price_low': $books_query .= " ORDER BY price ASC"; break;
    case 'price_high': $books_query .= " ORDER BY price DESC"; break;
    case 'title': $books_query .= " ORDER BY title ASC"; break;
    case 'newest':
    default: $books_query .= " ORDER BY created_at DESC"; break;
}

$books = $conn->query($books_query);
$total_books = $books->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Books - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Material Design Bootstrap -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.2/mdb.min.css" rel="stylesheet">
    <!-- Unified Customer Styles -->
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_unified_typography.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-page);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
            padding-top: 70px !important;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 40px;
            min-height: calc(100vh - 70px);
        }

        /* Breadcrumb */
        .breadcrumb-nav {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .breadcrumb-nav a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb-nav a:hover {
            color: var(--color-brand-primary);
        }

        .breadcrumb-nav .separator {
            color: var(--text-secondary);
        }

        .breadcrumb-nav .current {
            color: var(--color-brand-primary);
            font-weight: 600;
        }

        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 30px;
            border: 1px solid rgba(233, 236, 239, 0.8);
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-brand-primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Search and Filter */
        .search-filter-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(233, 236, 239, 0.8);
        }

        .search-box {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .search-box button {
            padding: 10px 20px;
            background: var(--color-brand-primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .search-box button:hover {
            background: var(--color-brand-secondary);
        }

        .sort-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }

        .book-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border: 1px solid rgba(233, 236, 239, 0.8);
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(136, 43, 42, 0.15);
        }

        .book-image-container {
            position: relative;
            height: 350px;
            background: #f8f9fa;
            overflow: hidden;
        }

        .book-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .book-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: #dee2e6;
        }

        .book-badges {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .badge-new {
            background: var(--color-semantic-success);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-stock {
            background: var(--color-semantic-warning);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .book-details {
            padding: 20px;
        }

        .book-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-author {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .book-description {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .book-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }

        .book-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-brand-primary);
        }

        .book-actions {
            display: flex;
            gap: 8px;
        }

        .btn-view, .btn-cart {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-view {
            background: #f8f9fa;
            color: var(--text-primary);
        }

        .btn-view:hover {
            background: #e9ecef;
            color: var(--text-primary);
        }

        .btn-cart {
            background: var(--color-brand-primary);
            color: white;
        }

        .btn-cart:hover {
            background: var(--color-brand-secondary);
        }

        .btn-cart:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* Override MDB Button Colors */
        .btn-primary {
            background-color: var(--color-brand-primary) !important;
            border-color: var(--color-brand-primary) !important;
        }

        .btn-primary:hover {
            background-color: var(--color-brand-secondary) !important;
            border-color: var(--color-brand-secondary) !important;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--color-brand-primary);
            margin-bottom: 20px;
            opacity: 0.7;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
        }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/customer_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Breadcrumb -->
            <nav class="breadcrumb-nav">
                <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
                <span class="separator">/</span>
                <span class="current"><i class="fas fa-book"></i> Browse Books</span>
            </nav>

            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-book-open"></i> Discover Your Next Great Read</h1>
                <p>Explore our collection of <?= $total_books ?> amazing books</p>
            </div>

            <!-- Search and Filter -->
            <div class="search-filter-section">
                <?php if ($search || $genre): ?>
                    <div style="background: rgba(136, 43, 42, 0.1); padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid var(--color-brand-primary);">
                        <div>
                            <?php if ($search): ?>
                                <i class="fas fa-search" style="color: var(--color-brand-primary); margin-right: 8px;"></i>
                                <strong style="color: var(--color-brand-primary);">Search Results for:</strong> 
                                <span style="color: var(--text-primary);">"<?= htmlspecialchars($search) ?>"</span>
                            <?php endif; ?>
                            <?php if ($genre): ?>
                                <i class="fas fa-tag" style="color: var(--color-brand-primary); margin-right: 8px; <?= $search ? 'margin-left: 15px;' : '' ?>"></i>
                                <strong style="color: var(--color-brand-primary);">Category:</strong> 
                                <span style="color: var(--text-primary);"><?= htmlspecialchars($genre) ?></span>
                            <?php endif; ?>
                            <span style="color: var(--text-secondary); margin-left: 10px;">(<?= $total_books ?> book<?= $total_books != 1 ? 's' : '' ?> found)</span>
                        </div>
                        <a href="browse_books.php" style="color: var(--color-brand-primary); text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 5px;">
                            <i class="fas fa-times-circle"></i> Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <form method="GET" action="" id="searchForm">
                            <div class="search-box">
                                <input type="text" name="search" id="searchInput" placeholder="Search by title, author, or ISBN..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                                <button type="submit"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form method="GET" action="">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="genre" value="<?= htmlspecialchars($genre) ?>">
                            <select name="sort" class="sort-select" onchange="this.form.submit()">
                                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                                <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
                                <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                                <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                            </select>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Books Grid -->
            <div class="books-grid">
                <?php if ($books && $books->num_rows > 0): ?>
                    <?php 
                    $books_data = [];
                    while ($book = $books->fetch_assoc()): 
                        $books_data[$book['id']] = $book;
                    ?>
                        <div class="book-card">
                            <div class="book-image-container">
                                <?php 
                                $cover_value = $book['cover_image'];
                                if (!empty($cover_value) && $cover_value !== '0' && $cover_value !== '#' && $cover_value !== 'NULL'):
                                    $img_path = "../uploads/books/" . htmlspecialchars($cover_value);
                                ?>
                                    <img src="<?= $img_path ?>?v=<?= time() ?>" 
                                         alt="<?= htmlspecialchars($book['title']) ?>" 
                                         class="book-image"
                                         style="display: block !important;"
                                         onload="console.log('Loaded: <?= $cover_value ?>');"
                                         onerror="console.error('Failed: <?= $img_path ?>'); this.onerror=null; this.parentElement.innerHTML='<div class=book-image-placeholder><i class=fas fa-book></i></div>';">
                                <?php else: ?>
                                    <div class="book-image-placeholder" title="No image: <?= var_export($cover_value, true) ?>">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="book-badges">
                                    <?php 
                                    $created_date = strtotime($book['created_at']);
                                    $days_old = (time() - $created_date) / (60 * 60 * 24);
                                    if ($days_old <= 7): 
                                    ?>
                                        <span class="badge-new">New!</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($book['stock_quantity'] < 5 && $book['stock_quantity'] > 0): ?>
                                        <span class="badge-stock">Only <?= $book['stock_quantity'] ?> left!</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="book-details">
                                <h3 class="book-title"><?= htmlspecialchars($book['title']) ?></h3>
                                <p class="book-author">by <?= htmlspecialchars($book['author']) ?></p>
                                
                                <?php if (!empty($book['description'])): ?>
                                    <p class="book-description">
                                        <?= htmlspecialchars(substr($book['description'], 0, 100)) ?>
                                        <?= strlen($book['description']) > 100 ? '...' : '' ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="book-footer">
                                    <div class="book-price">â‚±<?= number_format($book['price'], 2) ?></div>
                                    <div class="book-actions">
                                        <a href="book_details.php?id=<?= $book['id'] ?>" class="btn-view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($book['stock_quantity'] > 0): ?>
                                            <button class="btn-cart" onclick="addToCart(<?= $book['id'] ?>)" title="Add to Cart">
                                                <i class="fas fa-shopping-cart"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-cart" disabled title="Out of Stock">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h3>No Books Found</h3>
                        <p>Try adjusting your search or filters</p>
                        <a href="browse_books.php" class="btn btn-primary" data-mdb-ripple-init>View All Books</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Material Design Bootstrap JS -->
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.2/mdb.min.js"></script>
    <!-- Bootstrap Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Book Details Modal -->
    <div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 15px; overflow: hidden; border: none;">
                <div class="modal-body p-0" id="modalContent">
                    <!-- Content loaded dynamically -->
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Store books data
        const booksData = <?= json_encode($books_data) ?>;
        
        function addToCart(bookId) {
            // Convert to integer and validate
            bookId = parseInt(bookId, 10);
            
            if(!bookId || isNaN(bookId) || bookId <= 0){
                console.error('Invalid bookId:', bookId);
                alert('Error: Invalid book ID');
                return;
            }
            
            console.log('Adding to cart, bookId:', bookId);
            
            fetch('add_to_cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'book_id=' + bookId + '&quantity=1'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Response:', data);
                if (data.success) {
                    alert('Book added to cart!');
                    location.reload();
                } else {
                    alert(data.message || 'Failed to add to cart');
                    if(data.debug) console.log('Debug:', data.debug);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        function viewBook(bookId) {
            // Get book data from embedded data
            const book = booksData[bookId];
            
            if (!book) {
                alert('Book not found');
                return;
            }
            
            const modalContent = document.getElementById('modalContent');
            const imageUrl = book.cover_image && book.cover_image !== '0' && book.cover_image !== '#'
                ? '../uploads/books/' + book.cover_image 
                : '';
            
            modalContent.innerHTML = `
                <div class="row g-0">
                    <div class="col-md-5" style="background: linear-gradient(135deg, #1E3A5F 0%, #2C5282 100%); position: relative; overflow: hidden;">
                        <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: url('${imageUrl}') center/cover; opacity: 0.3;"></div>
                                <div style="position: relative; z-index: 1; padding: 40px; display: flex; flex-direction: column; justify-content: center; height: 100%; min-height: 500px;">
                                    ${imageUrl ? `
                                        <img src="${imageUrl}" alt="${book.title}" style="width: 100%; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); margin-bottom: 20px;">
                                    ` : `
                                        <div style="width: 100%; height: 300px; background: rgba(255,255,255,0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                                            <i class="fas fa-book" style="font-size: 5rem; color: rgba(255,255,255,0.5);"></i>
                                        </div>
                                    `}
                                    <div style="text-align: center; color: white;">
                                        <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 10px;">â‚±${parseFloat(book.price).toFixed(2)}</div>
                                        <div style="font-size: 0.9rem; opacity: 0.9;">
                                            ${book.stock_quantity > 0 ? `
                                                <span style="background: rgba(141, 194, 99, 0.3); padding: 5px 15px; border-radius: 20px; border: 1px solid rgba(141, 194, 99, 0.5);">
                                                    <i class="fas fa-check-circle"></i> ${book.stock_quantity} in stock
                                                </span>
                                            ` : `
                                                <span style="background: rgba(220, 53, 69, 0.3); padding: 5px 15px; border-radius: 20px; border: 1px solid rgba(220, 53, 69, 0.5);">
                                                    <i class="fas fa-times-circle"></i> Out of stock
                                                </span>
                                            `}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <div style="padding: 40px;">
                                    <button type="button" class="btn-close float-end" data-bs-dismiss="modal" aria-label="Close"></button>
                                    
                                    <h2 style="font-size: 1.8rem; font-weight: 700; color: #1E3A5F; margin-bottom: 10px; margin-top: 10px;">${book.title}</h2>
                                    <p style="color: #666; font-size: 1.1rem; margin-bottom: 20px;">
                                        <i class="fas fa-user-edit" style="color: #1E3A5F;"></i> by ${book.author}
                                    </p>
                                    
                                    <div style="border-top: 2px solid #f0f0f0; padding-top: 20px; margin-bottom: 20px;">
                                        <h5 style="font-weight: 600; color: #333; margin-bottom: 15px;">
                                            <i class="fas fa-info-circle" style="color: #1E3A5F;"></i> Book Details
                                        </h5>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                                            ${book.isbn ? `
                                                <div>
                                                    <small style="color: #999; display: block; margin-bottom: 5px;">ISBN</small>
                                                    <strong style="color: #333; font-family: 'Courier New', monospace;">${book.isbn}</strong>
                                                </div>
                                            ` : ''}
                                            ${book.publisher ? `
                                                <div>
                                                    <small style="color: #999; display: block; margin-bottom: 5px;">Publisher</small>
                                                    <strong style="color: #333;">${book.publisher}</strong>
                                                </div>
                                            ` : ''}
                                            ${book.publication_year ? `
                                                <div>
                                                    <small style="color: #999; display: block; margin-bottom: 5px;">Year</small>
                                                    <strong style="color: #333;">${book.publication_year}</strong>
                                                </div>
                                            ` : ''}
                                            ${book.genre ? `
                                                <div>
                                                    <small style="color: #999; display: block; margin-bottom: 5px;">Genre</small>
                                                    <strong style="color: #333;">${book.genre}</strong>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    
                                    ${book.description ? `
                                        <div style="margin-bottom: 25px;">
                                            <h5 style="font-weight: 600; color: #333; margin-bottom: 15px;">
                                                <i class="fas fa-align-left" style="color: #1E3A5F;"></i> Description
                                            </h5>
                                            <p style="color: #666; line-height: 1.8; text-align: justify;">${book.description}</p>
                                        </div>
                                    ` : ''}
                                    
                                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                                        ${book.stock_quantity > 0 ? `
                                            <button class="btn btn-primary" data-mdb-ripple-init style="flex: 1; padding: 12px; font-size: 1rem; font-weight: 600;" onclick="addToCartFromModal(${book.id})">
                                                <i class="fas fa-shopping-cart"></i> Add to Cart
                                            </button>
                                        ` : `
                                            <button class="btn btn-secondary" disabled style="flex: 1; padding: 12px; font-size: 1rem; font-weight: 600;">
                                                <i class="fas fa-times"></i> Out of Stock
                                            </button>
                                        `}
                                        <button class="btn btn-outline-primary" data-mdb-ripple-init style="padding: 12px 20px;" onclick="addToWishlist(${book.id})">
                                            <i class="far fa-heart"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('bookModal'));
            modal.show();
        }
        
        function addToCartFromModal(bookId) {
            addToCart(bookId);
            bootstrap.Modal.getInstance(document.getElementById('bookModal')).hide();
        }
        
        function addToWishlist(bookId) {
            fetch('toggle_wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'book_id=' + bookId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Failed to update wishlist');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        // Search enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const searchForm = document.getElementById('searchForm');
            
            if (searchInput && searchForm) {
                // Focus search input with Ctrl+K or Cmd+K
                document.addEventListener('keydown', function(e) {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                        e.preventDefault();
                        searchInput.focus();
                        searchInput.select();
                    }
                });
                
                // Trim search input on submit
                searchForm.addEventListener('submit', function(e) {
                    const trimmedValue = searchInput.value.trim();
                    if (!trimmedValue) {
                        e.preventDefault();
                        searchInput.focus();
                        return false;
                    }
                    searchInput.value = trimmedValue;
                });
                
                // Add search hint
                searchInput.title = 'Press Ctrl+K to focus search';
            }
        });
    </script>
</body>
</html>

