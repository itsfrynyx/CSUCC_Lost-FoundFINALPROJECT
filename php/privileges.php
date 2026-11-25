<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in and is admin or super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("Location: Sign_in.php");
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

$current_role = strtolower(trim($admin_data['role'] ?? ''));
if (!in_array($current_role, ['admin', 'super_admin'], true)) {
    header("Location: Sign_in.php");
    exit();
}
$is_super_admin = ($current_role === 'super_admin');
$permission_message = '';
$permission_message_type = '';

$account_permissions_table_ready = (bool) $conn->query("CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    granted_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    INDEX idx_user_permissions_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Load RBAC data when the permission tables are installed.
$permission_roles = [];
$available_permissions = [];
$role_permission_ids = [];
$rbac_tables_ready = $conn->query("SHOW TABLES LIKE 'roles'")->num_rows > 0
    && $conn->query("SHOW TABLES LIKE 'permissions'")->num_rows > 0
    && $conn->query("SHOW TABLES LIKE 'role_permissions'")->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_permissions') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $permission_message = 'Your session expired. Refresh the page and try again.';
        $permission_message_type = 'danger';
    } elseif (!$is_super_admin) {
        $permission_message = 'Only Super Administrators can modify role permissions.';
        $permission_message_type = 'danger';
    } elseif (!$rbac_tables_ready) {
        $permission_message = 'Permission tables are not installed.';
        $permission_message_type = 'danger';
    } else {
        $role_name = $_POST['role_name'] ?? '';
        $posted_permission_ids = $_POST['permission_ids'] ?? [];
        if (!is_array($posted_permission_ids)) {
            $posted_permission_ids = [];
        }
        $selected_permission_ids = array_values(array_unique(array_filter(
            array_map('intval', $posted_permission_ids),
            static fn($permission_id) => $permission_id > 0
        )));
        $role_stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? AND is_active = 1 LIMIT 1");
        $role_stmt->bind_param('s', $role_name);
        $role_stmt->execute();
        $role_row = $role_stmt->get_result()->fetch_assoc();

        if (!$role_row || !in_array($role_name, ['customer', 'admin', 'super_admin'], true)) {
            $permission_message = 'Invalid role selected.';
            $permission_message_type = 'danger';
        } else {
            $role_id = (int) $role_row['id'];
            $conn->begin_transaction();
            try {
                $delete_stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $delete_stmt->bind_param('i', $role_id);
                $delete_stmt->execute();
                $insert_stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE id = ?");
                foreach ($selected_permission_ids as $permission_id) {
                    $insert_stmt->bind_param('ii', $role_id, $permission_id);
                    $insert_stmt->execute();
                    if ($insert_stmt->affected_rows !== 1) {
                        throw new RuntimeException('An invalid permission was selected.');
                    }
                }
                $conn->commit();
                $permission_message = 'Permissions updated successfully.';
                $permission_message_type = 'success';
            } catch (Throwable $exception) {
                $conn->rollback();
                $permission_message = 'Unable to update permissions.';
                $permission_message_type = 'danger';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account_permissions') {
    $account_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $posted_permissions = $_POST['permission_ids'] ?? [];
    if (!is_array($posted_permissions)) {
        $posted_permissions = [];
    }
    $selected_permission_ids = array_values(array_unique(array_filter(
        array_map('intval', $posted_permissions),
        static fn($permission_id) => $permission_id > 0
    )));

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $permission_message = 'Your session expired. Refresh the page and try again.';
        $permission_message_type = 'danger';
    } elseif (!$is_super_admin) {
        $permission_message = 'Only Super Administrators can modify account permissions.';
        $permission_message_type = 'danger';
    } elseif (!$account_permissions_table_ready || !$rbac_tables_ready) {
        $permission_message = 'Permission tables are not available.';
        $permission_message_type = 'danger';
    } elseif (!$account_id) {
        $permission_message = 'Select a valid account.';
        $permission_message_type = 'danger';
    } else {
        $account_stmt = $conn->prepare("SELECT id FROM infos WHERE id = ? AND role IN ('admin', 'customer') LIMIT 1");
        $account_stmt->bind_param('i', $account_id);
        $account_stmt->execute();

        if (!$account_stmt->get_result()->fetch_assoc()) {
            $permission_message = 'Only Admin and User accounts can receive account-specific permissions.';
            $permission_message_type = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                $delete_account_permissions = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $delete_account_permissions->bind_param('i', $account_id);
                $delete_account_permissions->execute();

                $insert_account_permission = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id, granted_by) SELECT ?, id, ? FROM permissions WHERE id = ?");
                foreach ($selected_permission_ids as $permission_id) {
                    $insert_account_permission->bind_param('iii', $account_id, $admin_id, $permission_id);
                    $insert_account_permission->execute();
                    if ($insert_account_permission->affected_rows !== 1) {
                        throw new RuntimeException('An invalid permission was selected.');
                    }
                }

                $conn->commit();
                $permission_message = 'Account permissions updated successfully.';
                $permission_message_type = 'success';
            } catch (Throwable $exception) {
                $conn->rollback();
                $permission_message = 'Unable to update account permissions.';
                $permission_message_type = 'danger';
            }
        }
    }
}

if ($rbac_tables_ready) {
    $permission_roles_result = $conn->query("SELECT id, role_name, role_display_name FROM roles WHERE is_active = 1 ORDER BY level DESC");
    while ($role_row = $permission_roles_result->fetch_assoc()) {
        $permission_roles[$role_row['role_name']] = $role_row;
    }

    $permissions_result = $conn->query("SELECT id, permission_name, permission_display_name, module, action FROM permissions ORDER BY module, action");
    while ($permission_row = $permissions_result->fetch_assoc()) {
        $available_permissions[] = $permission_row;
    }

    $assigned_result = $conn->query("SELECT role_id, permission_id FROM role_permissions");
    while ($assigned_row = $assigned_result->fetch_assoc()) {
        $role_permission_ids[(int) $assigned_row['role_id']][] = (int) $assigned_row['permission_id'];
    }
}

$account_id = (int) ($_POST['user_id'] ?? $_GET['account_id'] ?? 0);
$assignable_accounts = [];
$account_permission_ids = [];
if ($is_super_admin && $rbac_tables_ready && $account_permissions_table_ready) {
    $account_result = $conn->query("SELECT id, idnumber, username, full_name, role FROM infos WHERE role IN ('admin', 'customer') ORDER BY role, full_name, username");
    while ($account_row = $account_result->fetch_assoc()) {
        $assignable_accounts[] = $account_row;
    }

    if ($account_id === 0 && !empty($assignable_accounts)) {
        $account_id = (int) $assignable_accounts[0]['id'];
    }

    if ($account_id > 0) {
        $selected_account_stmt = $conn->prepare("SELECT id FROM infos WHERE id = ? AND role IN ('admin', 'customer') LIMIT 1");
        $selected_account_stmt->bind_param('i', $account_id);
        $selected_account_stmt->execute();
        if (!$selected_account_stmt->get_result()->fetch_assoc()) {
            $account_id = 0;
        } else {
            $account_permission_stmt = $conn->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ?");
            $account_permission_stmt->bind_param('i', $account_id);
            $account_permission_stmt->execute();
            $account_permission_result = $account_permission_stmt->get_result();
            while ($assigned_account_permission = $account_permission_result->fetch_assoc()) {
                $account_permission_ids[] = (int) $assigned_account_permission['permission_id'];
            }
        }
    }
}

// Define privileges for each role
$role_privileges = [
    'customer' => [
        'role_name' => 'Student / User',
        'description' => 'Campus member who can report, track, and claim lost or found items',
        'color' => '#0d5c3c',
        'icon' => 'fa-user',
        'categories' => [
            'Account Management' => [
                'Register account',
                'Login / Logout',
                'Edit profile',
                'Change password',
                'Upload profile picture',
                'Manage address details'
            ],
            'Item Reporting' => [
                'Report lost items',
                'Report found items',
                'Upload item photos',
                'View item history',
                'Track item status',
                'Submit claims'
            ],
            'Case Updates' => [
                'Receive claim notifications',
                'View case progress',
                'Check return status',
                'Resubmit missing details'
            ],
            'Community Access' => [
                'Browse found items',
                'Search reported items',
                'View campus notices',
                'Receive recovery updates'
            ]
        ],
        'restrictions' => [
            'Access Denied' => [
                'Cannot access superadmin dashboard',
                'Cannot approve registrations',
                'Cannot manage system users',
                'Cannot view all audit logs',
                'Cannot change role permissions',
                'Cannot delete any account records',
                'Cannot unlock blocked accounts',
                'Cannot modify system settings'
            ]
        ]
    ],
    'admin' => [
        'role_name' => 'Administrator',
        'description' => 'Handles campus item review, verification, account oversight, and incident processing',
        'color' => '#2563EB',
        'icon' => 'fa-user-tie',
        'categories' => [
            'Item Management' => [
                'Review lost item reports',
                'Review found item reports',
                'Update item case status',
                'Verify possible matches',
                'Upload item evidence images'
            ],
            'Claims & Verification' => [
                'View all claims',
                'Process claim submissions',
                'Approve or reject claims',
                'Update return status',
                'Review ownership evidence'
            ],
            'Community Oversight' => [
                'View user list',
                'View account details',
                'Approve pending registrations',
                'Block or unblock accounts',
                'View security activity logs'
            ],
            'AI Match Review' => [
                'View AI-generated matches',
                'Review match confidence',
                'Flag suspicious matches',
                'Escalate unresolved cases'
            ],
            'Reporting' => [
                'View item reports',
                'View claim summaries',
                'Monitor lost and found trends',
                'Export operational reports'
            ]
        ],
        'restrictions' => [
            'Access Denied' => [
                'Cannot delete accounts permanently',
                'Cannot create or modify superadmins',
                'Cannot access system settings',
                'Cannot modify security configurations',
                'Cannot perform database-level changes',
                'Cannot view all superadmin-only logs',
                'Cannot change role permissions',
                'Cannot approve deletion requests without superadmin review'
            ]
        ]
    ],
    'super_admin' => [
        'role_name' => 'Super Administrator',
        'description' => 'Full system authority over campus security, accounts, lost and found operations, and policy control',
        'color' => '#6366F1',
        'icon' => 'fa-user-shield',
        'categories' => [
            'Full Administration' => [
                'All administrator capabilities',
                'Plus superadmin management rights'
            ],
            'Account Administration' => [
                'Create superadmins',
                'Create administrators',
                'Edit account details',
                'Delete accounts with approval flow',
                'Assign privileges and access levels'
            ],
            'Campus Case Control' => [
                'Manage lost item records',
                'Manage found item records',
                'Review all claims',
                'Approve returned items',
                'Handle deletion requests'
            ],
            'Security & Logs' => [
                'Access system settings',
                'Audit all login and activity logs',
                'Review blocked accounts',
                'Monitor failed logins',
                'Manage access control policies'
            ],
            'AI & Analytics' => [
                'Review AI possible matches',
                'View analytics dashboards',
                'Monitor system performance',
                'Generate campus recovery reports'
            ]
        ],
        'restrictions' => [
            'No Restrictions' => [
                'Super Administrator has full CSUCC Find system access',
                'No limitations on authorized administrative functions',
                'Complete oversight of all lost-and-found workflows',
                'Full control over security, roles, and audit records'
            ]
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSUCC FIND - Access Control & Privileges</title>
    <link rel="icon" href="../PICTURES/Site-Logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Super Admin Indigo Color Palette */
            --sidebar-dark: #0F172A;
            --sidebar-darker: #020617;
            --sidebar-hover: #1E293B;
            --active-menu: #4F46E5;
            --primary-button: #4F46E5;
            --button-hover: #4338CA;
            
            /* Content Colors */
            --content-bg: #f5f6fa;
            --card-bg: #ffffff;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --text-muted: #a0aec0;
            --border-color: #e2e8f0;
            --hover-bg: #f7fafc;
            
            /* Super Admin KPI Semantic Colors */
            --stat-indigo: #6366F1;
            --stat-indigo-light: #E0E7FF;
            --stat-blue: #2563EB;
            --stat-blue-light: #DBEAFE;
            --stat-red: #B91C1C;
            --stat-red-light: #FEE2E2;
            --stat-amber: #D97706;
            --stat-amber-light: #FEF3C7;
            
            /* Table Colors */
            --table-header-bg: #F1F5F9;
            --table-row-hover: #F8FAFC;
            --table-selected: #E0F2FE;
            --action-edit: #2563EB;
            --action-delete: #DC2626;
            
            /* Legacy accent colors for compatibility */
            --accent-blue: #2563EB;
            --accent-green: #10b981;
            --accent-yellow: #D97706;
            --accent-red: #B91C1C;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--content-bg);
            color: var(--text-primary);
            font-weight: 400;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: var(--sidebar-dark);
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
            overflow-y: auto;
        }
        
        .sidebar.collapsed {
            width: 70px;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: var(--sidebar-darker);
            position: relative;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .sidebar-logo {
            width: 36px;
            height: 36px;
            border-radius: 8px;
        }
        
        .brand-info h3 {
            color: #ffffff;
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -0.3px;
        }
        
        .brand-subtitle {
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            margin: 0;
            font-weight: 400;
        }
        
        .toggle-sidebar-btn {
            position: absolute;
            top: 50%;
            right: -15px;
            width: 30px;
            height: 30px;
            background: #ffffff;
            border: 2px solid var(--sidebar-dark);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1001;
            transform: translateY(-50%);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .toggle-sidebar-btn i {
            color: var(--sidebar-dark);
            font-size: 14px;
            font-weight: 700;
            transition: transform 0.3s ease;
        }
        
        .toggle-sidebar-btn:hover {
            background: #f8f9fa;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transform: translateY(-50%) scale(1.1);
        }
        
        .toggle-sidebar-btn:hover i {
            color: var(--sidebar-dark);
        }
        
        .sidebar.collapsed .toggle-sidebar-btn i {
            transform: rotate(180deg);
        }
        
        .sidebar-menu {
            padding: 16px 0;
        }
        
        .menu-section-title {
            padding: 20px 20px 8px 20px;
            font-size: 11px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.4);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .menu-section-title:first-child {
            padding-top: 8px;
        }
        
        .sidebar.collapsed .menu-section-title {
            display: none;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 11px 20px;
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 2px solid transparent;
            font-size: 14px;
            font-weight: 400;
            position: relative;
        }
        
        .menu-item:hover {
            background: var(--sidebar-hover);
            color: rgba(255, 255, 255, 0.95);
            border-left-color: rgba(255, 255, 255, 0.3);
        }
        
        .menu-item.active {
            background: var(--active-menu);
            color: #ffffff;
            border-left-color: #ffffff;
            font-weight: 500;
        }
        
        .menu-item i {
            width: 18px;
            margin-right: 12px;
            font-size: 15px;
            opacity: 0.8;
        }
        
        .menu-item.active i {
            opacity: 1;
        }
        
        .menu-badge {
            margin-left: auto;
            background: var(--accent-red);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 7px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }
        
        /* Dropdown Menu Styles */
        .menu-dropdown {
            position: relative;
        }
        
        .dropdown-toggle {
            cursor: pointer;
            position: relative;
        }
        
        .dropdown-arrow {
            margin-left: auto;
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        
        .dropdown-toggle.active .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .dropdown-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        
        .dropdown-content.show {
            max-height: 200px;
        }
        
        
        
        .sidebar.collapsed .dropdown-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        
        .dropdown-content.show {
            max-height: 200px;
        }
        
        
        
        .sidebar.collapsed .dropdown-content {
            display: none;
        }
        
        .sidebar.collapsed .dropdown-arrow {
            display: none;
        }
        
        .menu-item-logout {
            margin-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 19px;
        }
        
        .sidebar.collapsed .menu-item span {
            display: none;
        }
        
        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 14px 5px;
        }
        
        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }
        
        .sidebar.collapsed .menu-badge {
            display: none;
        }
        
        .sidebar.collapsed .menu-item-logout {
            border-top: none;
            padding-top: 14px;
            margin-top: 0;
        }
        
        .sidebar.collapsed .brand-info {
            display: none;
        }
        
        .sidebar.collapsed .logo-section {
            justify-content: center;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 32px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded {
            margin-left: 70px;
        }
        
        .top-header {
            background: var(--card-bg);
            padding: 20px 28px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-header h4 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
            letter-spacing: -0.4px;
        }
        
        .top-header small {
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        .toggle-sidebar {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toggle-sidebar:hover {
            background: var(--hover-bg);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }
        
        
        .d-flex { display: flex; }
        .align-items-center { align-items: center; }
        .gap-3 { gap: 1rem; }
        .mb-0 { margin-bottom: 0; }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 35px;
        }
        
        .stat-item {
            background: #FFFFFF;
            padding: 25px;
            border-radius: 4px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .privileges-container {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .permission-editor {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 4px;
            padding: 25px 30px;
            margin-bottom: 30px;
        }

        .permission-editor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        .permission-editor-header h5 {
            margin: 0;
            color: #1E293B;
            font-weight: 700;
        }

        .permission-editor-header p {
            margin: 5px 0 0;
            color: #64748B;
            font-size: 0.9rem;
        }

        .permission-role-form {
            border-top: 1px solid #E2E8F0;
            padding: 20px 0 5px;
        }

        .permission-role-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 15px;
            color: #334155;
            font-weight: 600;
        }

        .permission-checkboxes {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px 20px;
        }

        .permission-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 0.9rem;
        }

        .permission-checkbox input {
            width: 17px;
            height: 17px;
            accent-color: #4F46E5;
        }

        .permission-checkbox input:disabled {
            opacity: 0.8;
        }

        .permission-editor .btn-save-permissions {
            margin-top: 18px;
            border: 0;
            border-radius: 4px;
            background: #4F46E5;
            color: #FFFFFF;
            padding: 9px 16px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .permission-checkboxes {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        
        .role-section {
            background: #FFFFFF;
            border-radius: 4px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .role-header {
            padding: 25px 30px;
            background: #F8FAFC;
            border-bottom: 2px solid #E2E8F0;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .role-icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #FFFFFF;
        }
        
        .role-info { flex: 1; }
        
        .role-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 5px;
        }
        
        .role-description {
            font-size: 0.9rem;
            color: #64748B;
        }
        
        .role-badge {
            display: none !important;
        }
        
        .privilege-categories {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
        }
        
        .privilege-category {
            padding: 30px;
            border-right: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
            min-height: 280px;
        }
        
        .privilege-category:nth-child(3n) {
            border-right: none;
        }
        
        .category-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #F1F5F9;
        }
        
        .category-icon {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: #FFFFFF;
        }
        
        .category-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #334155;
            text-transform: uppercase;
        }
        
        .privilege-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .privilege-item {
            padding: 12px 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: #475569;
            font-size: 0.9rem;
        }
        
        .privilege-check {
            width: 18px;
            height: 18px;
            background: #10B981;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.65rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .privilege-deny {
            width: 18px;
            height: 18px;
            background: #DC2626;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.65rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .restriction-category {
            background: #FEF2F2;
        }
        
        .restriction-item {
            color: #991B1B;
        }
        
        .security-notice {
            background: #FEF3C7;
            border-left: 4px solid #F59E0B;
            padding: 20px 25px;
            margin-bottom: 30px;
            border-radius: 4px;
            display: flex;
            gap: 15px;
        }
        
        .security-notice-icon {
            width: 40px;
            height: 40px;
            background: #F59E0B;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .security-notice-content h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #92400E;
            margin-bottom: 5px;
        }
        
        .security-notice-content p {
            font-size: 0.9rem;
            color: #78350F;
            margin: 0;
        }
        
        @media (max-width: 1200px) {
            .privilege-categories {
                grid-template-columns: repeat(2, 1fr);
            }
            .privilege-category:nth-child(3n) {
                border-right: 1px solid #E2E8F0;
            }
            .privilege-category:nth-child(2n) {
                border-right: none;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            .privilege-categories {
                grid-template-columns: 1fr;
            }
            .privilege-category {
                border-right: none;
            }
        }
    </style>
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
</head>
<body>
    
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0">Privileges and Access Control</h4>
                    <small class="text-muted">The super admin can assign privileges and access control to a specific account.</small>
                </div>
            </div>
        </div>
        
        <div class="security-notice">
            <div class="security-notice-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="security-notice-content">
                <h4>Security Notice</h4>
                <p>This page displays sensitive information about system access controls. Privilege modifications should only be performed by authorized Super Administrators. All changes are logged and audited.</p>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value">3</div>
                <div class="stat-label">User Roles</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?php
                    $customer_count = $conn->query("SELECT COUNT(*) as c FROM infos WHERE role = 'customer'")->fetch_assoc()['c'];
                    echo $customer_count;
                    ?>
                </div>
                <div class="stat-label">Customers</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?php
                    $admin_count = $conn->query("SELECT COUNT(*) as c FROM infos WHERE role = 'admin'")->fetch_assoc()['c'];
                    echo $admin_count;
                    ?>
                </div>
                <div class="stat-label">Administrators</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?php
                    $super_admin_count = $conn->query("SELECT COUNT(*) as c FROM infos WHERE role = 'super_admin'")->fetch_assoc()['c'];
                    echo $super_admin_count;
                    ?>
                </div>
                <div class="stat-label">Super Admins</div>
            </div>
        </div>

        <?php if ($permission_message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($permission_message_type, ENT_QUOTES, 'UTF-8') ?>" role="alert">
            <?= htmlspecialchars($permission_message, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <section class="permission-editor" aria-labelledby="permission-editor-title">
            <div class="permission-editor-header">
                <div>
                    <h5 id="permission-editor-title">Role Permission Assignments</h5>
                    <p><?= $is_super_admin ? 'Select the permissions each role can use.' : 'Permission assignments are view-only for administrators.' ?></p>
                </div>
                <?php if ($is_super_admin): ?>
                <span class="badge text-bg-success">Editable</span>
                <?php else: ?>
                <span class="badge text-bg-secondary">Read-only</span>
                <?php endif; ?>
            </div>

            <?php if (!$rbac_tables_ready || empty($available_permissions)): ?>
            <p class="text-muted mb-0">No RBAC permissions are available yet.</p>
            <?php else: ?>
                <?php foreach ($permission_roles as $role_name => $permission_role): ?>
                <form method="POST" class="permission-role-form">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="role_name" value="<?= htmlspecialchars($role_name, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="permission-role-title">
                        <span><?= htmlspecialchars($permission_role['role_display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($is_super_admin): ?>
                        <button type="submit" class="btn-save-permissions"><i class="fas fa-save"></i> Save</button>
                        <?php endif; ?>
                    </div>
                    <div class="permission-checkboxes">
                        <?php foreach ($available_permissions as $permission): ?>
                        <?php $assigned = in_array((int) $permission['id'], $role_permission_ids[(int) $permission_role['id']] ?? [], true); ?>
                        <label class="permission-checkbox">
                            <input type="checkbox" name="permission_ids[]" value="<?= (int) $permission['id'] ?>" <?= $assigned ? 'checked' : '' ?> <?= $is_super_admin ? '' : 'disabled' ?>>
                            <span><?= htmlspecialchars($permission['permission_display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php if ($is_super_admin): ?>
        <section class="permission-editor mt-4" aria-labelledby="account-permission-title">
            <div class="permission-editor-header">
                <div>
                    <h5 id="account-permission-title">Account-Specific Permission Assignments</h5>
                    <p>These additional permissions apply only to the selected Admin or User account.</p>
                </div>
            </div>
            <?php if (!$account_permissions_table_ready || !$rbac_tables_ready || empty($assignable_accounts)): ?>
                <p class="text-muted mb-0">Account-specific permissions are unavailable until accounts and RBAC permissions are installed.</p>
            <?php else: ?>
                <form method="GET" class="mb-3">
                    <label class="form-label" for="permissionAccount">Account</label>
                    <div class="d-flex gap-2">
                        <select class="form-select" id="permissionAccount" name="account_id">
                            <?php foreach ($assignable_accounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>" <?= $account_id === (int) $account['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($account['idnumber'] ?? '') . ' - ' . ($account['full_name'] ?: $account['username']) . ' (' . ucfirst($account['role']) . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-primary">Load</button>
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="update_account_permissions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" value="<?= $account_id ?>">
                    <div class="permission-checkboxes">
                        <?php foreach ($available_permissions as $permission): ?>
                        <?php $account_assigned = in_array((int) $permission['id'], $account_permission_ids, true); ?>
                        <label class="permission-checkbox">
                            <input type="checkbox" name="permission_ids[]" value="<?= (int) $permission['id'] ?>" <?= $account_assigned ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($permission['permission_display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn-save-permissions"><i class="fas fa-save"></i> Save Account Permissions</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        
        <div class="privileges-container">
            <?php foreach ($role_privileges as $role => $data): ?>
            <div class="role-section">
                <div class="role-header">
                    <div class="role-icon-wrapper" style="background: <?= $data['color'] ?>;">
                        <i class="fas <?= $data['icon'] ?>"></i>
                    </div>
                    <div class="role-info">
                        <div class="role-name"><?= $data['role_name'] ?></div>
                        <div class="role-description"><?= $data['description'] ?></div>
                    </div>
                    <div class="role-badge" style="background: <?= $data['color'] ?>;">
                        <?= strtoupper($role) ?>
                    </div>
                </div>
                
                <div class="role-body">
                    <div class="privilege-categories">
                        <?php foreach ($data['categories'] as $category => $privileges): ?>
                        <div class="privilege-category">
                            <div class="category-header">
                                <div class="category-icon" style="background: <?= $data['color'] ?>;">
                                    <i class="fas fa-key"></i>
                                </div>
                                <div class="category-title"><?= $category ?></div>
                            </div>
                            <ul class="privilege-list">
                                <?php foreach ($privileges as $privilege): ?>
                                <li class="privilege-item">
                                    <span class="privilege-check">
                                        <i class="fas fa-check"></i>
                                    </span>
                                    <?= $privilege ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (isset($data['restrictions'])): ?>
                        <?php foreach ($data['restrictions'] as $category => $restrictions): ?>
                        <div class="privilege-category restriction-category">
                            <div class="category-header">
                                <div class="category-icon" style="background: #DC2626;">
                                    <i class="fas fa-ban"></i>
                                </div>
                                <div class="category-title"><?= $category ?></div>
                            </div>
                            <ul class="privilege-list">
                                <?php foreach ($restrictions as $restriction): ?>
                                <li class="privilege-item restriction-item">
                                    <span class="privilege-deny">
                                        <i class="fas fa-times"></i>
                                    </span>
                                    <?= $restriction ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
