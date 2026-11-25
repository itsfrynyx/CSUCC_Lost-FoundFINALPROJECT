# Quick Start: Using IDNUMBER Instead of ID

## What Changed?

Your login system now stores **idnumber** in addition to **id** in session variables.

### Session Variables

| User Type | Old Variable | New Variable | Example Value |
|-----------|-------------|--------------|---------------|
| Customer | `$_SESSION['user_id']` | `$_SESSION['user_idnumber']` | "2022-1432" |
| Admin | `$_SESSION['super_admin_id']` | `$_SESSION['super_admin_idnumber']` | "ADM-2026-001" |
| Super Admin | `$_SESSION['super_admin_id']` | `$_SESSION['super_admin_idnumber']` | "SA-2026-001" |

## Quick Migration Steps

### 1. Run Database Migration (5 minutes)

```bash
php DB/migrations/migrate_to_idnumber_identifier.php
```

This creates:
- Helper functions in `DB/idnumber_helpers.php`
- Indexes on idnumber columns
- New columns in activity log tables

### 2. Sign_in.php Already Updated ✓

The Sign_in.php file has been updated to store idnumber in session. Test by logging in and checking:

```php
// After login, check session:
var_dump($_SESSION['user_idnumber']); // For customers
var_dump($_SESSION['super_admin_idnumber']); // For admins
```

### 3. Update Your Code (Gradually)

#### Simple Pattern to Follow:

**Before:**
```php
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
```

**After:**
```php
$user_idnumber = $_SESSION['user_idnumber'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
$stmt->bind_param("s", $user_idnumber);
```

#### Or Use Helper Functions:

```php
require_once __DIR__ . '/../DB/idnumber_helpers.php';

// Get current user
$user = getUserByIdNumber($conn, $_SESSION['user_idnumber']);

// Check if logged in
if (!isLoggedInByIdNumber()) {
    header('Location: Sign_in.php');
    exit();
}
```

## Common Use Cases

### Display User ID in UI

```php
<!-- OLD -->
<p>User ID: <?php echo $user['id']; ?></p>

<!-- NEW -->
<p>ID Number: <?php echo $user['idnumber']; ?></p>
```

### Check Login Status

```php
// OLD
if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// NEW
if (!isset($_SESSION['user_idnumber'])) {
    header('Location: Sign_in.php');
    exit();
}
```

### Get User Data

```php
// OLD
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);

// NEW
$stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
$stmt->bind_param("s", $_SESSION['user_idnumber']);
```

### Activity Logging

```php
// OLD
$stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, action) VALUES (?, ?)");
$stmt->bind_param("is", $user_id, $action);

// NEW (log both for compatibility)
$stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_idnumber, action) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $user_id, $user_idnumber, $action);
```

## Files to Update (Priority Order)

### High Priority (User-Facing)
1. ✓ `php/Sign_in.php` - Already updated
2. `php/Dashboard.php` - Customer dashboard
3. `php/admin_dashboard.php` - Admin dashboard
4. `php/admin_profile.php` - Admin profile
5. `php/customer_profile.php` - Customer profile

### Medium Priority (Management)
6. `php/admin_manage.php` - Admin management
7. `php/admin_manage_customers.php` - Customer management
8. `php/admin_manage_orders.php` - Order management

### Low Priority (Reports/Logs)
9. `php/admin_logs.php` - Activity logs
10. `php/customer_logs.php` - Customer logs
11. `php/admin_reports.php` - Reports

## Testing Checklist

After each file update, test:

- [ ] Login as customer - check session has `user_idnumber`
- [ ] Login as admin - check session has `super_admin_idnumber`
- [ ] View profile - displays idnumber correctly
- [ ] Activity logs - records idnumber
- [ ] No errors in `logs/error_log.txt`

## Rollback

If something breaks:

1. **Session still has old variables** - Both `id` and `idnumber` are stored
2. **Database unchanged** - The `id` column still works
3. **Revert code** - Change queries back to use `id`

## Benefits

✓ **Human-readable IDs**: "SA-2026-001" instead of "3"
✓ **Better security**: No sequential ID exposure
✓ **Easier auditing**: Clear user identification in logs
✓ **Professional**: Matches real-world ID systems

## Need Help?

- Check `DB/IDNUMBER_USAGE_EXAMPLES.php` for code examples
- Read `DB/IDNUMBER_MIGRATION_GUIDE.md` for detailed guide
- Review `DB/idnumber_helpers.php` for helper functions

## Summary

1. ✓ Database migration done
2. ✓ Sign_in.php updated
3. ⏳ Update other files gradually
4. ✓ Test after each change
5. ✓ Both id and idnumber work during transition

**You can now use `$_SESSION['user_idnumber']` and `$_SESSION['super_admin_idnumber']` in your code!**
