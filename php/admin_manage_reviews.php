<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as admin
if (!isset($_SESSION['super_admin_id'])) {
    if (isset($_POST['action'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    header('Location: Sign_in.php');
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $review_id = intval($_POST['review_id']);
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE book_reviews SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Review approved successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to approve review']);
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE book_reviews SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Review rejected successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reject review']);
        }
    }
    exit();
}

// Get admin data
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();
$is_super_admin = $admin_data['role'] === 'super_admin';

// Get all reviews
$reviews_query = "
    SELECT br.*, b.title as book_title, i.full_name as customer_name 
    FROM book_reviews br
    LEFT JOIN books b ON br.book_id = b.id
    LEFT JOIN infos i ON br.user_id = i.id
    ORDER BY br.created_at DESC
";
$reviews = $conn->query($reviews_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Reviews - CSUCC FIND</title>
    <link rel="icon" type="image/png" href="../PICTURES/Site-Logo.png">
    <link href="../Bootstrap/bootstrap-5.3.3-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-star me-2"></i>Community Reviews</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="unified_admin_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Review Center</li>
                    </ol>
                </nav>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Reporter</th>
                                <th>Rating</th>
                                <th>Feedback</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($reviews && $reviews->num_rows > 0): ?>
                                <?php while ($review = $reviews->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($review['book_title']); ?></td>
                                        <td><?php echo htmlspecialchars($review['customer_name']); ?></td>
                                        <td>
                                            <?php for($i = 0; $i < 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i < $review['rating'] ? 'text-warning' : 'text-muted'; ?>"></i>
                                            <?php endfor; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($review['review_text'], 0, 100)) . '...'; ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $review['status'] === 'approved' ? 'success' : 
                                                    ($review['status'] === 'rejected' ? 'danger' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($review['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                        <td>
                                            <?php if ($review['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-success" onclick="approveReview(<?php echo $review['id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="rejectReview(<?php echo $review['id']; ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No reviews found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../Bootstrap/bootstrap-5.3.3-dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function approveReview(reviewId) {
            if (confirm('Approve this review?')) {
                fetch('admin_manage_reviews.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=approve&review_id=' + reviewId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
        
        function rejectReview(reviewId) {
            if (confirm('Reject this review?')) {
                fetch('admin_manage_reviews.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=reject&review_id=' + reviewId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
    </script>
</body>
</html>
