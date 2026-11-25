<?php
/**
 * IDNUMBER Usage Examples
 * 
 * This file demonstrates how to use idnumber instead of id
 * throughout your application after migration.
 */

// Include helper functions
require_once __DIR__ . '/idnumber_helpers.php';
require_once __DIR__ . '/conn.php';

// ============================================================================
// EXAMPLE 1: Checking if User is Logged In
// ============================================================================

// OLD WAY (using id):
if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// NEW WAY (using idnumber):
if (!isset($_SESSION['user_idnumber'])) {
    header('Location: Sign_in.php');
    exit();
}

// OR using helper function:
if (!isLoggedInByIdNumber()) {
    header('Location: Sign_in.php');
    exit();
}

// ============================================================================
// EXAMPLE 2: Getting Current User Data
// ============================================================================

// OLD WAY (using id):
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// NEW WAY (using idnumber):
$user_idnumber = $_SESSION['user_idnumber'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
$stmt->bind_param("s", $user_idnumber);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// OR using helper function:
$user_idnumber = getCurrentUserIdNumber();
$user = getUserByIdNumber($conn, $user_idnumber);

// ============================================================================
// EXAMPLE 3: Admin Dashboard - Get Admin Data
// ============================================================================

// OLD WAY (using id):
$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

// NEW WAY (using idnumber):
$admin_idnumber = $_SESSION['super_admin_idnumber'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
$stmt->bind_param("s", $admin_idnumber);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

// OR using helper function:
$admin_idnumber = $_SESSION['super_admin_idnumber'];
$admin_data = getUserByIdNumber($conn, $admin_idnumber);

// ============================================================================
// EXAMPLE 4: Displaying User Information
// ============================================================================

// OLD WAY (showing database id):
echo "User ID: " . $user['id'];

// NEW WAY (showing idnumber):
echo "User ID: " . $user['idnumber'];  // Shows "2022-1432" or "SA-2026-001"

// ============================================================================
// EXAMPLE 5: Activity Logging
// ============================================================================

// OLD WAY (logging with id only):
$stmt = $conn->prepare("
    INSERT INTO admin_activity_log 
    (user_id, user_type, action, description) 
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("isss", $user_id, $user_type, $action, $description);

// NEW WAY (logging with both id and idnumber):
$stmt = $conn->prepare("
    INSERT INTO admin_activity_log 
    (user_id, user_idnumber, user_type, action, description) 
    VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("issss", $user_id, $user_idnumber, $user_type, $action, $description);

// ============================================================================
// EXAMPLE 6: Customer Management - Edit Customer
// ============================================================================

// OLD WAY (using id):
$customer_id = $_POST['customer_id'];
$stmt = $conn->prepare("UPDATE infos SET username = ?, email = ? WHERE id = ?");
$stmt->bind_param("ssi", $username, $email, $customer_id);

// NEW WAY (using idnumber):
$customer_idnumber = $_POST['customer_idnumber'];
$stmt = $conn->prepare("UPDATE infos SET username = ?, email = ? WHERE idnumber = ?");
$stmt->bind_param("sss", $username, $email, $customer_idnumber);

// ============================================================================
// EXAMPLE 7: Orders - Create Order with Customer Reference
// ============================================================================

// OLD WAY (using id):
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    INSERT INTO orders (order_number, user_id, total_amount) 
    VALUES (?, ?, ?)
");
$stmt->bind_param("sid", $order_number, $user_id, $total_amount);

// NEW WAY (if you add user_idnumber column to orders table):
$user_idnumber = $_SESSION['user_idnumber'];
$stmt = $conn->prepare("
    INSERT INTO orders (order_number, user_id, user_idnumber, total_amount) 
    VALUES (?, ?, ?, ?)
");
// Get id from idnumber for foreign key
$user_id = getIdFromIdNumber($conn, $user_idnumber);
$stmt->bind_param("sisd", $order_number, $user_id, $user_idnumber, $total_amount);

// ============================================================================
// EXAMPLE 8: Admin Management - Display Admin List
// ============================================================================

// OLD WAY (showing id in table):
?>
<table>
    <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Email</th>
    </tr>
    <?php foreach ($admins as $admin): ?>
    <tr>
        <td><?php echo $admin['id']; ?></td>
        <td><?php echo $admin['username']; ?></td>
        <td><?php echo $admin['email']; ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php
// NEW WAY (showing idnumber in table):
?>
<table>
    <tr>
        <th>ID Number</th>
        <th>Username</th>
        <th>Email</th>
    </tr>
    <?php foreach ($admins as $admin): ?>
    <tr>
        <td><?php echo $admin['idnumber']; ?></td>
        <td><?php echo $admin['username']; ?></td>
        <td><?php echo $admin['email']; ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php
// ============================================================================
// EXAMPLE 9: Profile Page - Show User ID
// ============================================================================

// OLD WAY:
?>
<div class="profile-info">
    <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
</div>

<?php
// NEW WAY:
?>
<div class="profile-info">
    <p><strong>ID Number:</strong> <?php echo $user['idnumber']; ?></p>
    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
</div>

<?php
// ============================================================================
// EXAMPLE 10: Backward Compatibility - Converting Between ID and IDNUMBER
// ============================================================================

// When you need to work with legacy code that expects id:
$user_idnumber = $_SESSION['user_idnumber'];
$user_id = getIdFromIdNumber($conn, $user_idnumber);

// Now you can use $user_id with old code
$stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ?");
$stmt->bind_param("i", $user_id);

// When you have id but need idnumber:
$user_id = 123;
$user_idnumber = getIdNumberFromId($conn, $user_id);
echo "User ID Number: " . $user_idnumber;

// ============================================================================
// EXAMPLE 11: Search/Filter by IDNUMBER
// ============================================================================

// Search for user by idnumber
$search_idnumber = $_GET['search'] ?? '';
$stmt = $conn->prepare("
    SELECT * FROM infos 
    WHERE idnumber LIKE ? 
    OR username LIKE ? 
    OR email LIKE ?
");
$search_param = "%$search_idnumber%";
$stmt->bind_param("sss", $search_param, $search_param, $search_param);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ============================================================================
// EXAMPLE 12: Reports - Export with IDNUMBER
// ============================================================================

// Export customer list with idnumber
$stmt = $conn->query("
    SELECT 
        idnumber as 'ID Number',
        username as 'Username',
        email as 'Email',
        full_name as 'Full Name',
        role as 'Role',
        status as 'Status'
    FROM infos
    WHERE role = 'customer'
    ORDER BY idnumber
");

// ============================================================================
// BEST PRACTICES
// ============================================================================

/*
1. ALWAYS use idnumber for display purposes
   - Show idnumber in UI, reports, exports
   - Use idnumber in URLs (when safe)
   - Reference users by idnumber in logs

2. KEEP id for database relationships
   - Foreign keys still use id (for performance)
   - Internal queries can use id
   - Convert between id and idnumber as needed

3. STORE both in session
   - $_SESSION['user_id'] for backward compatibility
   - $_SESSION['user_idnumber'] for new code
   - Gradually migrate to idnumber

4. USE helper functions
   - getUserByIdNumber() for lookups
   - getIdFromIdNumber() for conversions
   - getCurrentUserIdNumber() for session

5. UPDATE gradually
   - Start with display/UI changes
   - Then update queries
   - Keep backward compatibility
   - Test thoroughly

6. SECURITY considerations
   - Validate idnumber format
   - Don't expose sequential patterns
   - Use prepared statements
   - Sanitize user input
*/

// ============================================================================
// VALIDATION EXAMPLE
// ============================================================================

function validateIdNumber($idnumber, $role) {
    // Customer format: YYYY-NNNN (e.g., 2022-1432)
    if ($role === 'customer') {
        return preg_match('/^\d{4}-\d{4}$/', $idnumber);
    }
    
    // Super Admin format: SA-YYYY-NNN (e.g., SA-2026-001)
    if ($role === 'super_admin') {
        return preg_match('/^SA-\d{4}-\d{3}$/', $idnumber);
    }
    
    // Admin format: ADM-YYYY-NNN (e.g., ADM-2026-001)
    if ($role === 'admin') {
        return preg_match('/^ADM-\d{4}-\d{3}$/', $idnumber);
    }
    
    return false;
}

// Usage:
$idnumber = $_POST['idnumber'] ?? '';
$role = $_POST['role'] ?? 'customer';

if (!validateIdNumber($idnumber, $role)) {
    die("Invalid ID number format for role: $role");
}
