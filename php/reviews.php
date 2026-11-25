<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: Sign_in.php"); exit(); }
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/logger_helper.php';

$user_id = $_SESSION['user_id'];

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

// Get all reviews for this user with book details
$reviews_query = "
    SELECT 
        br.*,
        b.title as book_title,
        b.author as book_author,
        b.cover_image,
        b.isbn
    FROM book_reviews br
    JOIN books b ON br.book_id = b.id
    WHERE br.user_id = ?
    ORDER BY br.created_at DESC
";
$stmt = $conn->prepare($reviews_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle review deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    $review_id = $_POST['review_id'];
    
    // Get review details before deletion for logging
    $stmt = $conn->prepare("SELECT br.*, b.title FROM book_reviews br JOIN books b ON br.book_id = b.id WHERE br.id = ? AND br.user_id = ?");
    $stmt->bind_param("ii", $review_id, $user_id);
    $stmt->execute();
    $review_data = $stmt->get_result()->fetch_assoc();
    
    if ($review_data) {
        $stmt = $conn->prepare("DELETE FROM book_reviews WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $review_id, $user_id);
        if ($stmt->execute()) {
            // Log review deletion
            log_review_action('delete', $review_id, $review_data['title']);
            
            $_SESSION['success_message'] = "Review deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to delete review.";
        }
    } else {
        $_SESSION['error_message'] = "Review not found.";
    }
    
    header("Location: reviews.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            font-family: 'Inter', 'Roboto', sans-serif;
            background: var(--bg-page);
            min-height: 100vh;
            color: var(--text-primary);
         padding-top: 70px !important;}

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 40px;
            min-height: 100vh;
        }

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

        .reviews-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(233, 236, 239, 0.8);
        }

        .review-card {
            background: var(--white);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            display: flex;
            gap: 20px;
        }

        .review-card:hover {
            border-color: var(--color-brand-primary);
            box-shadow: 0 4px 12px rgba(136, 43, 42, 0.08);
        }

        .book-cover-section {
            flex-shrink: 0;
        }

        .review-book-cover {
            width: 120px;
            height: 160px;
            border-radius: 8px;
            object-fit: cover;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: #f0f0f0;
        }

        .review-content {
            flex: 1;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .book-info h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .book-author {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 10px;
        }

        .rating-stars {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
        }

        .star {
            color: #ffc107;
            font-size: 1.2rem;
        }

        .star.empty {
            color: #ddd;
        }

        .review-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .review-text {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .review-meta {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }

        .review-date {
            color: var(--text-secondary);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .review-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved {
            background: rgba(141, 194, 99, 0.15);
            color: var(--color-semantic-success);
            border: 1px solid rgba(141, 194, 99, 0.3);
        }

        .status-pending {
            background: rgba(243, 156, 18, 0.15);
            color: var(--color-semantic-warning);
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: var(--color-semantic-error);
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .verified-badge {
            background: rgba(23, 162, 184, 0.15);
            color: var(--info-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .review-actions {
            display: flex;
            gap: 10px;
        }

        .btn-edit,
        .btn-delete {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-edit {
            background: var(--info-color);
            color: white;
        }

        .btn-edit:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: var(--color-semantic-error);
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 30px;
        }

        .btn-browse {
            padding: 12px 30px;
            background: var(--color-brand-primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }

        .btn-browse:hover {
            background: var(--color-brand-secondary);
            transform: translateY(-2px);
            color: white;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .review-card {
                flex-direction: column;
            }

            .review-book-cover {
                width: 100%;
                height: 200px;
            }
        }
    </style>
</head>
<body><?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/customer_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <nav class="breadcrumb-nav">
                <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
                <span class="separator">/</span>
                <span class="current"><i class="fas fa-star"></i> My Reviews</span>
            </nav>

            <div class="page-header">
                <h1><i class="fas fa-star"></i> My Reviews</h1>
                <p>View and manage your book reviews</p>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="reviews-container">
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <div class="book-cover-section">
                                <?php if (!empty($review['cover_image']) && $review['cover_image'] !== '0'): ?>
                                    <img src="../uploads/books/<?= htmlspecialchars($review['cover_image']) ?>" alt="<?= htmlspecialchars($review['book_title']) ?>" class="review-book-cover">
                                <?php else: ?>
                                    <div class="review-book-cover" style="display: flex; align-items: center; justify-content: center; color: #ddd;">
                                        <i class="fas fa-book" style="font-size: 3rem;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="review-content">
                                <div class="review-header">
                                    <div class="book-info">
                                        <h3><?= htmlspecialchars($review['book_title']) ?></h3>
                                        <div class="book-author">
                                            <i class="fas fa-user"></i>
                                            <?= htmlspecialchars($review['book_author']) ?>
                                        </div>
                                    </div>
                                    <div class="review-actions">
                                        <button class="btn-edit" onclick="editReview(<?= $review['id'] ?>, <?= $review['book_id'] ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn-delete" onclick="confirmDelete(<?= $review['id'] ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>

                                <div class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= $review['rating'] ? 'star' : 'star empty' ?>"></i>
                                    <?php endfor; ?>
                                </div>

                                <?php if (!empty($review['review_title'])): ?>
                                    <div class="review-title"><?= htmlspecialchars($review['review_title']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($review['review_text'])): ?>
                                    <div class="review-text"><?= nl2br(htmlspecialchars($review['review_text'])) ?></div>
                                <?php endif; ?>

                                <div class="review-meta">
                                    <div class="review-date">
                                        <i class="fas fa-calendar"></i>
                                        <?= date('F j, Y', strtotime($review['created_at'])) ?>
                                    </div>

                                    <div class="review-status status-<?= $review['status'] ?>">
                                        <?= ucfirst($review['status']) ?>
                                    </div>

                                    <?php if ($review['is_verified_purchase']): ?>
                                        <div class="verified-badge">
                                            <i class="fas fa-check-circle"></i>
                                            Verified Purchase
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($review['helpful_count'] > 0): ?>
                                        <div class="review-date">
                                            <i class="fas fa-thumbs-up"></i>
                                            <?= $review['helpful_count'] ?> found helpful
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <h3>No Reviews Yet</h3>
                        <p>You haven't written any reviews yet. Share your thoughts on books you've read!</p>
                        <a href="browse_books.php" class="btn-browse">
                            <i class="fas fa-book"></i> Browse Books
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--gradient-hero); color: white;">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle"></i>
                        Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this review? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="review_id" id="deleteReviewId">
                        <button type="submit" name="delete_review" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Review
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.2/mdb.min.js"></script>
    <script>
        function confirmDelete(reviewId) {
            document.getElementById('deleteReviewId').value = reviewId;
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        function editReview(reviewId, bookId) {
            // Redirect to book page with edit mode
            window.location.href = 'book_details.php?id=' + bookId + '&edit_review=' + reviewId;
        }
    </script>
</body>
</html>

