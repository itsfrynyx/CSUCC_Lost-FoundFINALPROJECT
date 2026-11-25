<?php
/**
 * Dashboard Data API Endpoint
 * Returns recommendations, trending books, and new releases for the dashboard carousels
 */

session_start();
require_once __DIR__ . '/../DB/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$response = [];

try {
    // Recommended For You - Based on user's purchase history and browsing
    $recommended_query = "
        SELECT DISTINCT b.id, b.title, b.author, b.price, b.cover_image, b.stock_quantity,
               COALESCE(AVG(r.rating), 4.0) as rating
        FROM books b
        LEFT JOIN reviews r ON b.id = r.book_id
        WHERE b.stock_quantity > 0
        AND b.id NOT IN (SELECT book_id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.user_id = ?)
        GROUP BY b.id
        ORDER BY rating DESC, b.created_at DESC
        LIMIT 12
    ";
    $stmt = $conn->prepare($recommended_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $response['recommendations'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Trending Now - Based on recent sales
    $trending_query = "
        SELECT b.id, b.title, b.author, b.price, b.cover_image, b.stock_quantity,
               COUNT(oi.id) as sales_count,
               COALESCE(AVG(r.rating), 4.0) as rating
        FROM books b
        LEFT JOIN order_items oi ON b.id = oi.book_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN reviews r ON b.id = r.book_id
        WHERE b.stock_quantity > 0
        GROUP BY b.id
        ORDER BY sales_count DESC, rating DESC
        LIMIT 12
    ";
    $response['trending'] = $conn->query($trending_query)->fetch_all(MYSQLI_ASSOC);

    // New Releases - Latest additions
    $new_releases_query = "
        SELECT b.id, b.title, b.author, b.price, b.cover_image, b.stock_quantity,
               COALESCE(AVG(r.rating), 4.0) as rating
        FROM books b
        LEFT JOIN reviews r ON b.id = r.book_id
        WHERE b.stock_quantity > 0
        GROUP BY b.id
        ORDER BY b.created_at DESC
        LIMIT 12
    ";
    $response['new_releases'] = $conn->query($new_releases_query)->fetch_all(MYSQLI_ASSOC);

    // Format ratings to 1 decimal place
    foreach (['recommendations', 'trending', 'new_releases'] as $key) {
        if (isset($response[$key])) {
            foreach ($response[$key] as &$book) {
                $book['rating'] = number_format((float)$book['rating'], 1);
            }
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Dashboard data error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to load dashboard data']);
}
?>
