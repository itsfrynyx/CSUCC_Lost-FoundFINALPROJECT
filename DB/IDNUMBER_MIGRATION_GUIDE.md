# Migration Guide: Switching from ID to IDNUMBER

## Overview

This guide explains how to migrate your bookstore system from using auto-increment `id` to using `idnumber` as the primary identifier for customers and admin users.

## Why This Change?

- **Human-readable identifiers**: IDNUMBER provides meaningful identifiers (e.g., "SA-2026-001", "2022-1432")
- **Better for external systems**: Easier to reference in reports, exports, and integrations
- **Privacy**: Doesn't expose sequential database IDs
- **Business logic**: Aligns with real-world ID systems

## Current State

### Session Variables
- Customers: `$_SESSION['user_id']` stores the database `id`
- Admins: `$_SESSION['super_admin_id']` stores the database `id`

### Database Structure
- `infos` table has both `id` (auto-increment primary key) and `idnumber` (unique varchar)
- All foreign keys reference `id`
- Activity logs use `user_id` columns

## Migration Strategy

### Phase 1: Preparation (Database)

1. **Run the migration script**:
   ```bash
   php DB/migrations/migrate_to_idnumber_identifier.php
   ```

   This script will:
   - Ensure `idnumber` has UNIQUE constraint
   - Add indexes for performance
   - Add `idnumber` columns to activity log tables
   - Populate existing records with idnumber values
   - Create helper functions

### Phase 2: Session Management

2. **Update Sign_in.php** to store both `id` and `idnumber`:

   ```php
   // For customers
   $_SESSION['user_id'] = $user['id'];  // Keep for backward compatibility
   $_SESSION['user_idnumber'] = $user['idnumber'];  // New identifier
   
   // For admins
   $_SESSION['super_admin_id'] = $user['id'];  // Keep for backward compatibility
   $_SESSION['super_admin_idnumber'] = $user['idnumber'];  // New identifier
   ```

### Phase 3: Code Updates

3. **Use helper functions** (created by migration script):

   ```php
   require_once __DIR__ . '/../DB/idnumber_helpers.php';
   
   // Get user by idnumber
   $user = getUserByIdNumber($conn, $_SESSION['user_idnumber']);
   
   // Get current user's idnumber
   $idnumber = getCurrentUserIdNumber();
   
   // Convert between id and idnumber
   $id = getIdFromIdNumber($conn, $idnumber);
   $idnumber = getIdNumberFromId($conn, $id);
   ```

4. **Update queries gradually**:

   Before:
   ```php
   $stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
   $stmt->bind_param("i", $_SESSION['user_id']);
   ```

   After:
   ```php
   $stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
   $stmt->bind_param("s", $_SESSION['user_idnumber']);
   ```

### Phase 4: Activity Logging

5. **Update activity logging** to use idnumber:

   ```php
   // Log with idnumber
   $stmt = $conn->prepare("
       INSERT INTO admin_activity_log 
       (user_id, user_idnumber, user_type, action, description) 
       VALUES (?, ?, ?, ?, ?)
   ");
   $stmt->bind_param("issss", $user_id, $user_idnumber, $user_type, $action, $description);
   ```

## Files That Need Updates

### Critical Files (Update First)
1. `php/Sign_in.php` - Store idnumber in session
2. `php/activity_logger.php` - Use idnumber for logging
3. `php/includes/ActivityLogger.php` - Update class methods

### Admin Management Files
4. `php/admin_manage.php` - Use idnumber for admin operations
5. `php/admin_manage_customers.php` - Use idnumber for customer operations
6. `php/admin_dashboard.php` - Display idnumber instead of id
7. `php/unified_admin_dashboard.php` - Display idnumber

### Customer Files
8. `php/Dashboard.php` - Use idnumber for customer data
9. `php/checkout.php` - Use idnumber for orders
10. `php/orders.php` - Display idnumber in order history

### Authentication Files
11. `php/Register.php` - Already uses idnumber (no change needed)
12. `php/Logout.php` - Clear idnumber session variables

## Testing Checklist

- [ ] Login as customer - verify session has idnumber
- [ ] Login as admin - verify session has idnumber
- [ ] Login as super admin - verify session has idnumber
- [ ] View customer profile - displays idnumber
- [ ] View admin profile - displays idnumber
- [ ] Activity logs show idnumber
- [ ] Orders reference customer by idnumber
- [ ] Admin management uses idnumber
- [ ] Customer management uses idnumber
- [ ] Reports show idnumber instead of id

## Rollback Plan

If issues occur:

1. **Session variables**: Both `id` and `idnumber` are stored, so old code still works
2. **Database**: The `id` column remains unchanged and functional
3. **Queries**: Can revert to using `id` without data loss

## Performance Considerations

- **Indexes**: Migration adds indexes on `idnumber` columns
- **Query performance**: VARCHAR lookups are slightly slower than INT, but negligible with proper indexing
- **Storage**: Minimal increase (VARCHAR vs INT)

## Security Benefits

- **No sequential IDs exposed**: Harder to enumerate users
- **Audit trail**: Easier to track users across systems
- **Compliance**: Better for GDPR/privacy requirements

## Example Code Changes

### Before (using id):
```php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
```

### After (using idnumber):
```php
session_start();
require_once __DIR__ . '/../DB/idnumber_helpers.php';

if (!isLoggedInByIdNumber()) {
    header('Location: Sign_in.php');
    exit();
}

$user_idnumber = getCurrentUserIdNumber();
$user = getUserByIdNumber($conn, $user_idnumber);
```

## Support

If you encounter issues during migration:

1. Check `logs/error_log.txt` for errors
2. Verify database indexes are created
3. Ensure helper functions are included
4. Test with a single user first before full rollout

## Timeline

Recommended migration timeline:

- **Week 1**: Run database migration, test helper functions
- **Week 2**: Update Sign_in.php and session management
- **Week 3**: Update admin management files
- **Week 4**: Update customer-facing files
- **Week 5**: Full testing and validation
- **Week 6**: Deploy to production

## Notes

- Keep both `id` and `idnumber` during transition
- Update one module at a time
- Test thoroughly after each update
- Monitor performance after deployment
