<?php
/**
 * RBAC Helper Functions
 * Include this file in any page that needs permission checks
 * Usage: require_once __DIR__ . '/includes/rbac_helper.php';
 */

// Initialize RBAC if not already done
if (!isset($rbac)) {
    require_once __DIR__ . '/RBAC.php';
    
    // Make sure we have database connection
    if (!isset($conn)) {
        require_once __DIR__ . '/../DB/conn.php';
    }
    
    // Create RBAC instance
    $rbac = new RBAC($conn);
}

/**
 * Quick permission check function
 * @param string $permission Permission to check
 * @return bool
 */
function can($permission) {
    global $rbac;
    return $rbac->hasPermission($permission);
}

/**
 * Check if user is super admin
 * @return bool
 */
function is_super_admin() {
    global $rbac;
    return $rbac->isSuperAdmin();
}

/**
 * Check if user is admin (any level)
 * @return bool
 */
function is_admin() {
    global $rbac;
    return $rbac->isAdmin();
}

/**
 * Check if user is customer
 * @return bool
 */
function is_customer() {
    global $rbac;
    return $rbac->isCustomer();
}

/**
 * Require permission or show error
 * @param string $permission Permission required
 * @param string $redirect Optional redirect URL
 */
function require_permission($permission, $redirect = null) {
    global $rbac;
    $rbac->requirePermission($permission, $redirect);
}

/**
 * Get user's role display name
 * @return string
 */
function get_role_name() {
    global $rbac;
    return $rbac->getRoleDisplayName();
}

/**
 * Check if user can access module
 * @param string $module Module name
 * @return bool
 */
function can_access_module($module) {
    global $rbac;
    return $rbac->canAccessModule($module);
}

/**
 * Conditional display helper - shows content only if user has permission
 * @param string $permission Permission required
 * @param callable $callback Function to execute if permission granted
 */
function if_can($permission, $callback) {
    if (can($permission)) {
        $callback();
    }
}

/**
 * Get current user's role
 * @return string
 */
function get_user_role() {
    global $rbac;
    return $rbac->getUserRole();
}
?>
