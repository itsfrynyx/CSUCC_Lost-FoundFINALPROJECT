<?php
// Get counts for badges - check table existence first
if (!isset($user_id)) {
    $user_id = $_SESSION['user_id'] ?? 0;
}

$cart_count = 0;
$check_cart = $conn->query("SHOW TABLES LIKE 'shopping_cart'");
if ($check_cart && $check_cart->num_rows > 0) {
    $cart_result = $conn->query("SELECT COUNT(*) as c FROM shopping_cart WHERE user_id = $user_id");
    $cart_count = $cart_result ? ($cart_result->fetch_assoc()['c'] ?? 0) : 0;
}

// Check if wishlist table exists before querying
$wishlist_count = 0;
$check_wishlist = $conn->query("SHOW TABLES LIKE 'wishlist'");
if ($check_wishlist && $check_wishlist->num_rows > 0) {
    $wishlist_result = $conn->query("SELECT COUNT(*) as c FROM wishlist WHERE user_id = $user_id");
    $wishlist_count = $wishlist_result ? ($wishlist_result->fetch_assoc()['c'] ?? 0) : 0;
}

$orders_count = 0;
$check_orders = $conn->query("SHOW TABLES LIKE 'orders'");
if ($check_orders && $check_orders->num_rows > 0) {
    $orders_result = $conn->query("SELECT COUNT(*) as c FROM orders WHERE user_id = $user_id");
    $orders_count = $orders_result ? ($orders_result->fetch_assoc()['c'] ?? 0) : 0;
}

$unread_notif = 0;
$check_notif = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($check_notif && $check_notif->num_rows > 0) {
    $notif_result = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $unread_notif = $notif_result ? ($notif_result->fetch_assoc()['c'] ?? 0) : 0;
}

// Determine active page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Customer Unified Styles -->
<link rel="stylesheet" href="includes/curiosity_quills_colors.css">
<link rel="stylesheet" href="includes/customer_unified_typography.css">
<style>
body{font-family:'Inter','Roboto',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.sidebar{width:260px;height:calc(100vh - 70px);position:fixed;left:0;top:70px;background:#FFFFFF;border-right:1px solid #DCE8E0;z-index:1000;overflow-y:auto;transition:transform .3s;box-shadow:4px 0 24px rgba(11,93,59,.08);font-family:'Inter','Roboto',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.sidebar.collapsed{transform:translateX(-260px)}
.sidebar-header{display:none}
.sidebar-nav{padding:15px 0}
.nav-section-title{padding:20px 20px 10px;font-size:.75rem;font-weight:600;text-transform:uppercase;color:#6B8575;letter-spacing:.5px;font-family:'Inter','Roboto',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.nav-link{display:flex;align-items:center;padding:14px 20px;color:#294538;text-decoration:none;transition:all .2s;position:relative;font-weight:400;font-size:14px;border-left:3px solid transparent;font-family:'Inter','Roboto',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.nav-link:hover{background:#EEF6F0;color:#0B5D3B;border-left-color:#9CCAAE}
.nav-link.active{background:#E6F2E9;color:#0B5D3B;border-left-color:#0B5D3B;font-weight:600}
.nav-link i{width:18px;margin-right:12px;font-size:15px;opacity:.8}
.nav-link.active i{opacity:1}
.nav-link .badge{margin-left:auto;background:#dc2626;color:#FFFFFF;padding:3px 7px;border-radius:10px;font-size:10px;font-weight:600;min-width:20px;text-align:center}
.main-content{margin-left:260px;padding:20px;transition:margin-left .3s;min-height:100vh;background:#F5F8F5}
.main-content.expanded{margin-left:0}
@media(max-width:768px){
.sidebar{transform:translateX(-260px)}
.sidebar.show{transform:translateX(0)}
.main-content{margin-left:0}
}
</style>
<div class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="Dashboard.php" class="nav-link <?= $current_page == 'Dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i>Dashboard
        </a>
        <div class="nav-section-title">Account</div>
        <a href="profile.php" class="nav-link <?= $current_page == 'profile.php' ? 'active' : '' ?>">
            <i class="fas fa-user"></i>Profile
        </a>
        <a href="notifications.php" class="nav-link <?= $current_page == 'notifications.php' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i>Notifications
            <?php if($unread_notif > 0): ?>
            <span class="badge"><?= $unread_notif ?></span>
            <?php endif; ?>
        </a>
        <a href="my_logs.php" class="nav-link <?= $current_page == 'my_logs.php' ? 'active' : '' ?>">
            <i class="fas fa-history"></i>My Login Logs
        </a>
        
        <div class="nav-section-title"></div>
        <a href="Sign_out.php" class="nav-link text-danger">
            <i class="fas fa-sign-out-alt"></i>Logout
        </a>
    </nav>
</div>
<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}

// Mobile: close sidebar when clicking outside
document.addEventListener('click', function(e) {
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.toggle-sidebar');
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('show');
        }
    }
});
</script>
