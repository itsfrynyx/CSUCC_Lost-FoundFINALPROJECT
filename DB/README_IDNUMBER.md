# IDNUMBER System - Complete Guide

## Overview

This campus lost and found system now uses **idnumber** (human-readable identifiers) instead of sequential database IDs for identifying users and administrators.

### What is IDNUMBER?

- **Customers**: `2022-1432` (year-number format)
- **Admins**: `ADM-2026-001` (prefix-year-number format)
- **Super Admins**: `SA-2026-001` (prefix-year-number format)

## Quick Start (5 Minutes)

### 1. Run Migration

```bash
php DB/migrations/migrate_to_idnumber_identifier.php
```

### 2. Test Setup

```bash
php DB/test_idnumber_setup.php
```

### 3. Test Login

Login to your system and verify session variables:

```php
// For customers
var_dump($_SESSION['user_idnumber']); // Should show "2022-1432"

// For admins
var_dump($_SESSION['super_admin_idnumber']); // Should show "ADM-2026-001" or "SA-2026-001"
```

### 4. Start Using

```php
// Include helpers
require_once __DIR__ . '/../DB/idnumber_helpers.php';

// Get current user
$user = getUserByIdNumber($conn, $_SESSION['user_idnumber']);

// Display
echo "Your ID: " . $user['idnumber'];
```

## Documentation Files

### Getting Started
- **README_IDNUMBER.md** (this file) - Overview and quick start
- **QUICK_START_IDNUMBER.md** - Quick reference guide
- **IDNUMBER_IMPLEMENTATION_SUMMARY.md** - What was done

### Detailed Guides
- **IDNUMBER_MIGRATION_GUIDE.md** - Complete migration guide
- **IDNUMBER_USAGE_EXAMPLES.php** - Code examples

### Scripts
- **migrations/migrate_to_idnumber_identifier.php** - Database migration
- **test_idnumber_setup.php** - Test script
- **idnumber_helpers.php** - Helper functions (created by migration)

## What Changed?

### 1. Sign_in.php âœ“

Now stores idnumber in session:

```php
// Customers
$_SESSION['user_id'] = $user['id'];  // Still available
$_SESSION['user_idnumber'] = $user['idnumber'];  // NEW

// Admins
$_SESSION['super_admin_id'] = $user['id'];  // Still available
$_SESSION['super_admin_idnumber'] = $user['idnumber'];  // NEW
```

### 2. Database âœ“

- UNIQUE constraint on idnumber
- Indexes for performance
- New columns in activity log tables

### 3. Helper Functions âœ“

Available in `DB/idnumber_helpers.php`:

```php
getUserByIdNumber($conn, $idnumber)
getIdFromIdNumber($conn, $idnumber)
getIdNumberFromId($conn, $user_id)
getCurrentUserIdNumber()
isLoggedInByIdNumber()
```

## How to Use

### Check Login

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

### Display User ID

```php
<!-- OLD -->
<p>User ID: <?php echo $user['id']; ?></p>

<!-- NEW -->
<p>ID Number: <?php echo $user['idnumber']; ?></p>
```

## Files to Update

### Priority 1 (User-Facing)
- âœ“ `php/Sign_in.php` - Already updated
- `php/Dashboard.php` - Customer dashboard
- `php/admin_dashboard.php` - Admin dashboard
- `php/unified_admin_dashboard.php` - Unified admin dashboard

### Priority 2 (Management)
- `php/admin_manage.php` - Admin management
- `php/admin_manage_customers.php` - Customer management
- `php/admin_profile.php` - Admin profile

### Priority 3 (Reports/Logs)
- `php/admin_logs.php` - Activity logs
- `php/customer_logs.php` - Customer logs
- `php/admin_reports.php` - Reports

## Benefits

### Security
âœ“ No sequential ID exposure
âœ“ Harder to enumerate users
âœ“ Better privacy compliance

### Usability
âœ“ Human-readable IDs
âœ“ Professional appearance
âœ“ Easier to reference

### Maintenance
âœ“ Clear user identification
âœ“ Better audit trails
âœ“ Easier debugging

## Backward Compatibility

âœ“ **Both id and idnumber work**
- Old code using `id` still works
- New code can use `idnumber`
- Gradual migration possible

âœ“ **No breaking changes**
- Session has both variables
- Database unchanged
- Foreign keys still use `id`

## Testing

After migration, test:

1. **Login**
   - [ ] Customer login works
   - [ ] Admin login works
   - [ ] Super admin login works

2. **Session Variables**
   - [ ] `$_SESSION['user_idnumber']` set for customers
   - [ ] `$_SESSION['super_admin_idnumber']` set for admins
   - [ ] Old variables still work

3. **Display**
   - [ ] Profile shows idnumber
   - [ ] Dashboard shows idnumber
   - [ ] Lists show idnumber

4. **Functionality**
   - [ ] All features work
   - [ ] No errors in logs
   - [ ] Activity logging works

## Troubleshooting

### Migration Script Fails

```bash
# Check database connection
php -r "require 'DB/conn.php'; echo 'Connected';"

# Check for errors
cat logs/error_log.txt
```

### Session Variables Not Set

```php
// Check Sign_in.php was updated
grep "user_idnumber" php/Sign_in.php
grep "super_admin_idnumber" php/Sign_in.php
```

### Helper Functions Not Found

```bash
# Check if file exists
ls -la DB/idnumber_helpers.php

# Run migration again
php DB/migrations/migrate_to_idnumber_identifier.php
```

## Support

### Documentation
- Read `QUICK_START_IDNUMBER.md` for quick reference
- Check `IDNUMBER_USAGE_EXAMPLES.php` for code examples
- Review `IDNUMBER_MIGRATION_GUIDE.md` for detailed guide

### Testing
- Run `test_idnumber_setup.php` to verify setup
- Check `logs/error_log.txt` for errors
- Test login for each user type

### Code Examples
- See `IDNUMBER_USAGE_EXAMPLES.php` for 12 examples
- Review helper functions in `idnumber_helpers.php`
- Check updated `Sign_in.php` for session management

## Next Steps

1. **Run migration** (if not done)
   ```bash
   php DB/migrations/migrate_to_idnumber_identifier.php
   ```

2. **Test setup**
   ```bash
   php DB/test_idnumber_setup.php
   ```

3. **Test login**
   - Login as each user type
   - Verify session variables

4. **Update code gradually**
   - Start with display code
   - Then update queries
   - Finally update reports

5. **Monitor**
   - Check error logs
   - Test all features
   - Verify activity logging

## Summary

âœ“ **Database ready** - Migration adds constraints and indexes
âœ“ **Login updated** - Sign_in.php stores idnumber in session
âœ“ **Helpers available** - Functions for easy usage
âœ“ **Backward compatible** - Both id and idnumber work
âœ“ **Well documented** - Multiple guides and examples

**You can now use idnumber throughout your application!**

## File Structure

```
DB/
â”œâ”€â”€ README_IDNUMBER.md                    # This file
â”œâ”€â”€ QUICK_START_IDNUMBER.md              # Quick reference
â”œâ”€â”€ IDNUMBER_MIGRATION_GUIDE.md          # Detailed guide
â”œâ”€â”€ IDNUMBER_IMPLEMENTATION_SUMMARY.md   # What was done
â”œâ”€â”€ IDNUMBER_USAGE_EXAMPLES.php          # Code examples
â”œâ”€â”€ test_idnumber_setup.php              # Test script
â”œâ”€â”€ idnumber_helpers.php                 # Helper functions (created by migration)
â””â”€â”€ migrations/
    â”œâ”€â”€ migrate_to_idnumber_identifier.php  # Migration script
    â””â”€â”€ update_signin_for_idnumber.php      # Sign_in update instructions

php/
â””â”€â”€ Sign_in.php                          # Updated to store idnumber
```

## Questions?

1. **What if I want to rollback?**
   - Both id and idnumber work, so no rollback needed
   - Just use the old session variables

2. **Do I need to update all files at once?**
   - No, update gradually
   - Both systems work during transition

3. **Will this break existing code?**
   - No, backward compatible
   - Old code still works

4. **How do I convert between id and idnumber?**
   - Use helper functions:
     - `getIdFromIdNumber($conn, $idnumber)`
     - `getIdNumberFromId($conn, $id)`

5. **What about foreign keys?**
   - Keep using id for foreign keys
   - Use idnumber for display and identification

## License

This implementation is part of your Campus Lost & Found System.

