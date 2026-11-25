<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/RBAC.php';
require_once __DIR__ . '/includes/account_setup_helper.php';

// Security: User must be authenticated and have customer role
if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

require_account_setup_complete($conn, (int) $_SESSION['user_id']);

$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// RBAC check: ensure customer role (non-admin)
$rbac = new RBAC($conn, $user_id);
if (!$rbac->isCustomer()) {
    header("Location: admin_dashboard.php");
    exit();
}

// Time-based greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
    $greeting_icon = "☀️";
} elseif ($hour < 18) {
    $greeting = "Good afternoon";
    $greeting_icon = "🌤️";
} else {
    $greeting = "Good evening";
    $greeting_icon = "🌙";
}

// ===== SECURITY: Check table existence before querying =====

// Check for Lost & Found tables
$lost_items_check = $conn->query("SHOW TABLES LIKE 'lost_items'");
$has_lost_items = $lost_items_check && $lost_items_check->num_rows > 0;

$found_items_check = $conn->query("SHOW TABLES LIKE 'found_items'");
$has_found_items = $found_items_check && $found_items_check->num_rows > 0;

$claims_check = $conn->query("SHOW TABLES LIKE 'claims'");
$has_claims = $claims_check && $claims_check->num_rows > 0;

$ai_matches_check = $conn->query("SHOW TABLES LIKE 'ai_matches'");
$has_ai_matches = $ai_matches_check && $ai_matches_check->num_rows > 0;

// ===== CARD 1: My Lost Items Count =====
$my_lost_count = 0;
if ($has_lost_items) {
    $result = $conn->query("SELECT COUNT(*) as count FROM lost_items WHERE user_id = $user_id");
    $my_lost_count = $result ? ($result->fetch_assoc()['count'] ?? 0) : 0;
}

// ===== CARD 2: My Found Items Count =====
$my_found_count = 0;
if ($has_found_items) {
    $result = $conn->query("SELECT COUNT(*) as count FROM found_items WHERE user_id = $user_id");
    $my_found_count = $result ? ($result->fetch_assoc()['count'] ?? 0) : 0;
}

// ===== CARD 3: AI Possible Matches =====
$my_matches_count = 0;
if ($has_ai_matches && $has_lost_items) {
    // Count AI matches for user's lost items
    $result = $conn->query("
        SELECT COUNT(DISTINCT am.id) as count 
        FROM ai_matches am
        INNER JOIN lost_items li ON am.lost_item_id = li.id
        WHERE li.user_id = $user_id
    ");
    $my_matches_count = $result ? ($result->fetch_assoc()['count'] ?? 0) : 0;
}

// ===== CARD 4: My Active Claims =====
$my_claims_count = 0;
if ($has_claims) {
    $result = $conn->query("
        SELECT COUNT(*) as count 
        FROM claims 
        WHERE claimant_id = $user_id AND status IN ('pending', 'under_review', 'approved')
    ");
    $my_claims_count = $result ? ($result->fetch_assoc()['count'] ?? 0) : 0;
}

// ===== RECENT LOST ITEMS (max 3) =====
$recent_lost = [];
if ($has_lost_items) {
    $result = $conn->query("
        SELECT id, item_name, location_lost, date_lost, status
        FROM lost_items
        WHERE user_id = $user_id
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $recent_lost = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ===== RECENT FOUND ITEMS (max 3) =====
$recent_found = [];
if ($has_found_items) {
    $result = $conn->query("
        SELECT id, item_name, location_found, date_found, status
        FROM found_items
        WHERE user_id = $user_id
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $recent_found = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ===== RECENT AI MATCHES (max 3) =====
$recent_matches = [];
if ($has_ai_matches && $has_lost_items && $has_found_items) {
    $result = $conn->query("
        SELECT am.id, am.match_score, am.matching_factors,
               li.item_name as lost_item_name, li.id as lost_item_id,
               fi.item_name as found_item_name, fi.id as found_item_id
        FROM ai_matches am
        INNER JOIN lost_items li ON am.lost_item_id = li.id
        INNER JOIN found_items fi ON am.found_item_id = fi.id
        WHERE li.user_id = $user_id
        ORDER BY am.created_at DESC
        LIMIT 3
    ");
    $recent_matches = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ===== RECENT ACTIVITY (max 5) =====
$recent_activity = [];
if ($has_lost_items || $has_found_items || $has_claims) {
    // Combine all user activities
    $activity = [];
    
    if ($has_lost_items) {
        $result = $conn->query("
            SELECT 'lost_item' as type, id, item_name as title, status, created_at
            FROM lost_items
            WHERE user_id = $user_id
        ");
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
    }
    
    if ($has_found_items) {
        $result = $conn->query("
            SELECT 'found_item' as type, id, item_name as title, status, created_at
            FROM found_items
            WHERE user_id = $user_id
        ");
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
    }
    
    if ($has_claims) {
        $result = $conn->query("
            SELECT 'claim' as type, id, 'Claim submission' as title, status, created_at
            FROM claims
            WHERE claimant_id = $user_id
        ");
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
    }
    
    // Sort by created_at DESC and take first 5
    usort($activity, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $recent_activity = array_slice($activity, 0, 5);
}

// ===== ACCOUNT STATUS =====
$account_status = $user['status'] ?? 'unknown';
$approval_status = isset($user['approved']) ? ($user['approved'] == 1 ? 'Approved' : 'Pending') : 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CSUCC Lost & Found</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Existing CSUCC Styles -->
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #F5F8F5;
            color: #173B2C;
            padding-top: 70px;
        }

        .main-content {
            margin-left: 260px;
            padding: 32px 40px;
            min-height: calc(100vh - 70px);
        }

        /* Welcome Hero */
        .hero {
            background: linear-gradient(135deg, #0B5D3B 0%, #168354 100%);
            color: white;
            padding: 32px 40px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(11, 93, 59, 0.18);
        }

        .hero h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .hero p {
            font-size: 1.05rem;
            opacity: 0.95;
            margin-bottom: 16px;
        }

        .hero-meta {
            display: flex;
            gap: 24px;
            margin-top: 16px;
            font-size: 0.95rem;
            opacity: 0.9;
        }

        /* Summary Cards Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #DCE8E0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #0B5D3B;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(11, 93, 59, 0.14);
        }

        .summary-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 16px;
            background: #f0f0f0;
        }

        .summary-card-title {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .summary-card-value {
            font-size: 2rem;
            font-weight: 700;
            color: #0B5D3B;
            margin-bottom: 16px;
        }

        .summary-card-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #0B5D3B;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .summary-card-btn:hover {
            background: #07452D;
            transform: translateX(2px);
            color: white;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 32px;
            border: 1px solid #DCE8E0;
        }

        .quick-actions h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1a1a1a;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 20px;
            background: #F5F8F5;
            border: 2px solid #DCE8E0;
            border-radius: 10px;
            text-decoration: none;
            color: #173B2C;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .quick-action-btn:hover {
            background: #0B5D3B;
            color: white;
            border-color: #0B5D3B;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(11, 93, 59, 0.18);
        }

        .quick-action-btn i {
            font-size: 1.8rem;
        }

        /* Content Sections */
        .section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #DCE8E0;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1a1a1a;
            padding-bottom: 12px;
            border-bottom: 2px solid #E6F0E9;
        }

        /* Activity List */
        .activity-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #E6F0E9;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
        }

        .activity-icon.lost {
            background: #fef3f2;
            color: #b23a3a;
        }

        .activity-icon.found {
            background: #f0fdf4;
            color: #2a9d5a;
        }

        .activity-icon.claim {
            background: #fffbeb;
            color: #d7b94b;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #1a1a1a;
            margin-bottom: 2px;
        }

        .activity-meta {
            font-size: 0.85rem;
            color: #999;
        }

        .activity-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 12px;
        }

        .status-pending {
            background: #fef3f2;
            color: #b23a3a;
        }

        .status-verified {
            background: #f0fdf4;
            color: #2a9d5a;
        }

        .status-approved {
            background: #f0fdf4;
            color: #2a9d5a;
        }

        .status-under_review {
            background: #fffbeb;
            color: #d7b94b;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state-text {
            margin-bottom: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
            }

            .hero {
                padding: 20px;
                margin-bottom: 20px;
            }

            .hero h1 {
                font-size: 1.5rem;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .hero-meta {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>

<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>

<div class="main-content">
    <!-- Welcome Hero -->
    <div class="hero">
        <h1><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($user['firstname'] ?? 'User'); ?>! 👋</h1>
        <p>Keep track of your lost and found reports and discover possible matches with AI.</p>
        <div class="hero-meta">
            <span>📅 <?php echo date('F j, Y'); ?></span>
            <span>✓ Account Status: <strong><?php echo ucfirst($account_status); ?></strong></span>
        </div>
    </div>

    <!-- Recent Activity removed from the customer dashboard. -->
    <?php if (false && !empty($recent_activity)): ?>
    <div class="section">
        <div class="section-title">⏱️ Recent Activity</div>
        <?php foreach ($recent_activity as $activity): ?>
            <div class="activity-item">
                <div class="activity-icon <?php echo $activity['type']; ?>">
                    <?php if ($activity['type'] === 'lost_item'): ?>
                        <i class="fas fa-exclamation"></i>
                    <?php elseif ($activity['type'] === 'found_item'): ?>
                        <i class="fas fa-box"></i>
                    <?php else: ?>
                        <i class="fas fa-check"></i>
                    <?php endif; ?>
                </div>
                <div class="activity-content">
                    <div class="activity-title">
                        <?php 
                        if ($activity['type'] === 'lost_item') {
                            echo 'Reported lost item: ' . htmlspecialchars($activity['title']);
                        } elseif ($activity['type'] === 'found_item') {
                            echo 'Reported found item: ' . htmlspecialchars($activity['title']);
                        } else {
                            echo htmlspecialchars($activity['title']);
                        }
                        ?>
                        <span class="activity-status status-<?php echo $activity['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                        </span>
                    </div>
                    <div class="activity-meta">
                        <?php 
                        $diff = time() - strtotime($activity['created_at']);
                        if ($diff < 60) echo 'Just now';
                        elseif ($diff < 3600) echo floor($diff / 60) . ' minutes ago';
                        elseif ($diff < 86400) echo floor($diff / 3600) . ' hours ago';
                        else echo floor($diff / 86400) . ' days ago';
                        ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
