<?php
/**
 * Role-Based Access Control (RBAC) System
 * Handles all permission checks without affecting UI/design
 */

class RBAC {
    private $conn;
    private $user_id;
    private $user_role;
    private $permissions_cache = null;

    public function __construct($db_connection, $user_id = null) {
        $this->conn = $db_connection;
        // Check both session variables (customer uses user_id, admin uses super_admin_id)
        $this->user_id = $user_id ?? $_SESSION['super_admin_id'] ?? $_SESSION['user_id'] ?? null;
        
        if ($this->user_id) {
            $this->loadUserRole();
        }
    }

    /**
     * Load user role from database
     */
    private function loadUserRole() {
        $stmt = $this->conn->prepare("SELECT role FROM infos WHERE id = ?");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $this->user_role = $row['role'];
        }
    }

    /**
     * Get user's role
     */
    public function getUserRole() {
        return $this->user_role;
    }

    /**
     * Check if user is Super Admin
     */
    public function isSuperAdmin() {
        return $this->user_role === 'super_admin';
    }

    /**
     * Check if user is Admin (any admin level)
     */
    public function isAdmin() {
        return in_array($this->user_role, ['super_admin', 'admin']);
    }

    /**
     * Check if user is Customer
     */
    public function isCustomer() {
        return $this->user_role === 'customer';
    }

    /**
     * Default permissions are used only when the RBAC schema is unavailable.
     */
    private function getDefaultPermissionsForRole() {
        $defaults = [
            'super_admin' => [
                'lost_items.view', 'lost_items.create', 'lost_items.review', 'lost_items.manage',
                'found_items.view', 'found_items.create', 'found_items.review', 'found_items.manage',
                'claims.view', 'claims.review', 'profile.view_own', 'profile.edit_own'
            ],
            'admin' => [
                'lost_items.view', 'lost_items.review', 'lost_items.manage',
                'found_items.view', 'found_items.review', 'found_items.manage',
                'claims.view', 'claims.review', 'profile.view_own', 'profile.edit_own'
            ],
            'customer' => [
                'lost_items.view', 'lost_items.create',
                'found_items.view', 'found_items.create',
                'claims.view', 'profile.view_own', 'profile.edit_own'
            ],
        ];

        return $defaults[$this->user_role] ?? [];
    }

    /**
     * Load all permissions for current user's role
     */
    private function tableExists($tableName) {
        if (!$this->conn || !method_exists($this->conn, 'query')) {
            throw new RuntimeException('Unable to inspect the RBAC schema.');
        }

        $result = $this->conn->query("SHOW TABLES LIKE '" . str_replace("'", "''", $tableName) . "'");
        if ($result === false) {
            throw new RuntimeException('Unable to inspect the RBAC schema.');
        }
        return $result->num_rows > 0;
    }

    private function loadPermissions() {
        if ($this->permissions_cache !== null) {
            return;
        }

        $requiredTables = ['roles', 'permissions', 'role_permissions'];
        foreach ($requiredTables as $tableName) {
            if (!$this->tableExists($tableName)) {
                $this->permissions_cache = $this->getDefaultPermissionsForRole();
                return;
            }
        }

        $this->permissions_cache = [];

        $query = "
            SELECT p.permission_name, p.module, p.action
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            INNER JOIN roles r ON rp.role_id = r.id
            WHERE r.role_name = ?
        ";

        $stmt = $this->conn->prepare($query);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare role permissions.');
        }

        $stmt->bind_param("s", $this->user_role);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $this->permissions_cache[] = $row['permission_name'];
        }

        $stmt->close();

        if ($this->tableExists('user_permissions')) {
            $account_permissions_stmt = $this->conn->prepare("
                SELECT p.permission_name
                FROM user_permissions up
                INNER JOIN permissions p ON p.id = up.permission_id
                WHERE up.user_id = ?
            ");
            if ($account_permissions_stmt === false) {
                throw new RuntimeException('Unable to load account-specific permissions.');
            }
            $account_permissions_stmt->bind_param('i', $this->user_id);
            $account_permissions_stmt->execute();
            $account_permissions_result = $account_permissions_stmt->get_result();
            while ($row = $account_permissions_result->fetch_assoc()) {
                $this->permissions_cache[] = $row['permission_name'];
            }
            $account_permissions_stmt->close();
        }

        $this->permissions_cache = array_values(array_unique($this->permissions_cache));

    }

    /**
     * Check if user has specific permission
     * @param string $permission Permission name (e.g., 'books.create')
     * @return bool
     */
    public function hasPermission($permission) {
        // Super admin has all permissions
        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadPermissions();
        return in_array($permission, $this->permissions_cache, true);
    }

    /**
     * Check if user has any of the given permissions
     * @param array $permissions Array of permission names
     * @return bool
     */
    public function hasAnyPermission($permissions) {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions
     * @param array $permissions Array of permission names
     * @return bool
     */
    public function hasAllPermissions($permissions) {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Require permission or redirect/exit
     * @param string $permission Permission name
     * @param string $redirect_url URL to redirect if no permission
     */
    public function requirePermission($permission, $redirect_url = null) {
        if (!$this->hasPermission($permission)) {
            if ($redirect_url) {
                header("Location: $redirect_url");
                exit();
            } else {
                http_response_code(403);
                die("Access Denied: You don't have permission to access this resource.");
            }
        }
    }

    /**
     * Get all permissions for current user
     * @return array
     */
    public function getAllPermissions() {
        $this->loadPermissions();
        return $this->permissions_cache;
    }

    /**
     * Check if user can access a module
     * @param string $module Module name (e.g., 'books', 'orders')
     * @return bool
     */
    public function canAccessModule($module) {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadPermissions();
        
        foreach ($this->permissions_cache as $permission) {
            if (strpos($permission, $module . '.') === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get role display name
     * @return string
     */
    public function getRoleDisplayName() {
        $stmt = $this->conn->prepare("SELECT role_display_name FROM roles WHERE role_name = ?");
        $stmt->bind_param("s", $this->user_role);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['role_display_name'];
        }
        
        return ucfirst(str_replace('_', ' ', $this->user_role));
    }

    /**
     * Log access attempt (for audit trail)
     * @param string $resource Resource being accessed
     * @param bool $granted Whether access was granted
     */
    public function logAccess($resource, $granted = true) {
        $action = $granted ? 'access_granted' : 'access_denied';
        $description = ($granted ? 'Accessed: ' : 'Denied access to: ') . $resource;
        
        $stmt = $this->conn->prepare("
            INSERT INTO admin_activity_log 
            (user_id, user_type, action, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bind_param("isssss", 
            $this->user_id, 
            $this->user_role, 
            $action, 
            $description, 
            $ip, 
            $user_agent
        );
        
        $stmt->execute();
    }

    /**
     * Check if user can perform action on resource
     * @param string $module Module name
     * @param string $action Action name (view, create, edit, delete)
     * @return bool
     */
    public function can($module, $action) {
        return $this->hasPermission($module . '.' . $action);
    }

    /**
     * Get all available roles
     * @return array
     */
    public function getAllRoles() {
        $query = "SELECT * FROM roles WHERE is_active = 1 ORDER BY level DESC";
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get permissions for a specific role
     * @param string $role_name Role name
     * @return array
     */
    public function getRolePermissions($role_name) {
        $query = "
            SELECT p.*
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            INNER JOIN roles r ON rp.role_id = r.id
            WHERE r.role_name = ?
            ORDER BY p.module, p.action
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $role_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>
