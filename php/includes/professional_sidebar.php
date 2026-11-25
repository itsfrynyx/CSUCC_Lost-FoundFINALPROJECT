<?php
// Get pending approvals count for badge
$pending_approvals_count = 0;
if (isset($conn)) {
    $pending_result = $conn->query("SELECT COUNT(*) as count FROM infos WHERE role = 'customer' AND approved = 0");
    if ($pending_result) {
        $pending_approvals_count = $pending_result->fetch_assoc()['count'] ?? 0;
    }
}

// Ensure $is_super_admin is set
if (!isset($is_super_admin)) {
    $is_super_admin = false;
    if (isset($_SESSION['super_admin_id']) && isset($conn)) {
        $admin_id = $_SESSION['super_admin_id'];
        $stmt = $conn->prepare("SELECT role FROM infos WHERE id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $is_super_admin = (strtolower(trim($result['role'] ?? '')) === 'super_admin');
    }
}

// Determine active page
$current_page = basename($_SERVER['PHP_SELF']);

// Set dashboard URL based on role
$dashboard_url = $is_super_admin ? 'admin_dashboard.php' : 'unified_admin_dashboard.php';
?>

<?php if ($is_super_admin): ?>
<!-- Super Admin Indigo Theme -->
<link rel="stylesheet" href="includes/superadmin_styles.css">
<?php endif; ?>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo-section">
            <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="sidebar-logo">
            <div class="brand-info">
                <h3>CSU Lost & Found</h3>
                <p class="brand-subtitle">Campus Recovery System</p>
            </div>
        </div>
        <div class="toggle-sidebar-btn" onclick="toggleSidebar()">
            <i class="fas fa-chevron-left"></i>
        </div>
    </div>
    
    <div class="sidebar-menu">
        <a href="<?php echo isset($dashboard_url) ? $dashboard_url : 'admin_dashboard.php'; ?>" 
           class="menu-item <?php echo $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>

        <?php if ($is_super_admin): ?>
        <a href="admin_manage.php" 
           class="menu-item <?php echo $current_page == 'admin_manage.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-tie"></i>
            <span>Superadmin Management</span>
        </a>
        <?php endif; ?>

        <a href="unified_admin_users.php" 
           class="menu-item <?php echo $current_page == 'unified_admin_users.php' ? 'active' : ''; ?>">
            <i class="fas fa-shield-alt"></i>
            <span>Admin Management</span>
        </a>

        <a href="admin_manage_customers.php" 
           class="menu-item <?php echo $current_page == 'admin_manage_customers.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>User Management</span>
        </a>

        <a href="pending_approvals.php" 
           class="menu-item <?php echo $current_page == 'pending_approvals.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i>
            <span>Pending Registrations</span>
            <?php if ($pending_approvals_count > 0): ?>
                <span class="menu-badge"><?php echo $pending_approvals_count; ?></span>
            <?php endif; ?>
        </a>

        <a href="privileges.php" 
           class="menu-item <?php echo $current_page == 'privileges.php' ? 'active' : ''; ?>">
            <i class="fas fa-lock"></i>
            <span>Privileges and Access Control</span>
        </a>

        <?php if ($is_super_admin): ?>
        <a href="deletion_proofs_audit.php" 
           class="menu-item <?php echo $current_page == 'deletion_proofs_audit.php' ? 'active' : ''; ?>">
            <i class="fas fa-trash-alt"></i>
            <span>Delete Requests</span>
        </a>
        <?php endif; ?>

        <a href="admin_logs.php" 
           class="menu-item <?php echo $current_page == 'admin_logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            <span>Login Logs</span>
        </a>

        <a href="audit_policy.php" 
           class="menu-item <?php echo $current_page == 'audit_policy.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-shield"></i>
            <span>Audit Logs</span>
        </a>

        <?php if ($is_super_admin): ?>
        <a href="admin_settings.php" 
           class="menu-item <?php echo $current_page == 'admin_settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i>
            <span>System Settings</span>
        </a>
        <?php endif; ?>

        <a href="admin_profile.php" 
           class="menu-item <?php echo $current_page == 'admin_profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-circle"></i>
            <span>Profile</span>
        </a>

        <a href="Sign_out.php" class="menu-item menu-item-logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>


<!-- Mobile Handler Script -->
<script src="includes/mobile_handler.js"></script>


