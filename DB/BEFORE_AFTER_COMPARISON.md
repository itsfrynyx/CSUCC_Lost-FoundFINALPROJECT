# Before & After: ID vs IDNUMBER

## Visual Comparison

### User Profile Display

#### BEFORE (Using ID)
```
┌─────────────────────────────────┐
│ User Profile                    │
├─────────────────────────────────┤
│ User ID: 3                      │
│ Username: superadmin            │
│ Email: superadmin@example.com   │
│ Role: Super Admin               │
└─────────────────────────────────┘
```

#### AFTER (Using IDNUMBER)
```
┌─────────────────────────────────┐
│ User Profile                    │
├─────────────────────────────────┤
│ ID Number: SA-2026-001          │
│ Username: superadmin            │
│ Email: superadmin@example.com   │
│ Role: Super Admin               │
└─────────────────────────────────┘
```

### Admin Management Table

#### BEFORE (Using ID)
```
┌────┬──────────────┬─────────────────────────┬─────────────┐
│ ID │ Username     │ Email                   │ Role        │
├────┼──────────────┼─────────────────────────┼─────────────┤
│ 3  │ superadmin   │ superadmin@example.com  │ Super Admin │
│ 4  │ systemadmin  │ systemadmin@example.com │ Admin       │
│ 25 │ johndoe      │ john@example.com        │ Customer    │
└────┴──────────────┴─────────────────────────┴─────────────┘
```

#### AFTER (Using IDNUMBER)
```
┌───────────────┬──────────────┬─────────────────────────┬─────────────┐
│ ID Number     │ Username     │ Email                   │ Role        │
├───────────────┼──────────────┼─────────────────────────┼─────────────┤
│ SA-2026-001   │ superadmin   │ superadmin@example.com  │ Super Admin │
│ ADM-2026-001  │ systemadmin  │ systemadmin@example.com │ Admin       │
│ 2022-1432     │ johndoe      │ john@example.com        │ Customer    │
└───────────────┴──────────────┴─────────────────────────┴─────────────┘
```

### Activity Log

#### BEFORE (Using ID)
```
┌────────────┬─────────┬────────┬─────────────────────────┐
│ Timestamp  │ User ID │ Action │ Description             │
├────────────┼─────────┼────────┼─────────────────────────┤
│ 10:30 AM   │ 3       │ login  │ Super Admin logged in   │
│ 10:35 AM   │ 4       │ login  │ Admin logged in         │
│ 10:40 AM   │ 25      │ login  │ Customer logged in      │
└────────────┴─────────┴────────┴─────────────────────────┘
```

#### AFTER (Using IDNUMBER)
```
┌────────────┬───────────────┬────────┬─────────────────────────┐
│ Timestamp  │ ID Number     │ Action │ Description             │
├────────────┼───────────────┼────────┼─────────────────────────┤
│ 10:30 AM   │ SA-2026-001   │ login  │ Super Admin logged in   │
│ 10:35 AM   │ ADM-2026-001  │ login  │ Admin logged in         │
│ 10:40 AM   │ 2022-1432     │ login  │ Customer logged in      │
└────────────┴───────────────┴────────┴─────────────────────────┘
```

### Order Details

#### BEFORE (Using ID)
```
┌─────────────────────────────────┐
│ Order #ORD-2026-001             │
├─────────────────────────────────┤
│ Customer ID: 25                 │
│ Customer: John Doe              │
│ Total: $125.50                  │
│ Status: Delivered               │
└─────────────────────────────────┘
```

#### AFTER (Using IDNUMBER)
```
┌─────────────────────────────────┐
│ Order #ORD-2026-001             │
├─────────────────────────────────┤
│ Customer ID: 2022-1432          │
│ Customer: John Doe              │
│ Total: $125.50                  │
│ Status: Delivered               │
└─────────────────────────────────┘
```

## Code Comparison

### Session Management

#### BEFORE
```php
// Login
$_SESSION['user_id'] = $user['id'];  // Stores: 25

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get user
$user_id = $_SESSION['user_id'];
```

#### AFTER
```php
// Login
$_SESSION['user_id'] = $user['id'];              // Stores: 25 (backward compat)
$_SESSION['user_idnumber'] = $user['idnumber'];  // Stores: "2022-1432"

// Check login
if (!isset($_SESSION['user_idnumber'])) {
    header('Location: Sign_in.php');
    exit();
}

// Get user
$user_idnumber = $_SESSION['user_idnumber'];
```

### Database Queries

#### BEFORE
```php
// Get user by ID
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Update user
$stmt = $conn->prepare("UPDATE infos SET email = ? WHERE id = ?");
$stmt->bind_param("si", $email, $user_id);
$stmt->execute();
```

#### AFTER
```php
// Get user by IDNUMBER
$stmt = $conn->prepare("SELECT * FROM infos WHERE idnumber = ?");
$stmt->bind_param("s", $user_idnumber);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Update user
$stmt = $conn->prepare("UPDATE infos SET email = ? WHERE idnumber = ?");
$stmt->bind_param("ss", $email, $user_idnumber);
$stmt->execute();
```

### Display Code

#### BEFORE
```php
<!-- Profile page -->
<div class="profile-card">
    <h2>User Profile</h2>
    <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
    <p><strong>Email:</strong> <?php echo $user['email']; ?></p>
</div>
```

#### AFTER
```php
<!-- Profile page -->
<div class="profile-card">
    <h2>User Profile</h2>
    <p><strong>ID Number:</strong> <?php echo $user['idnumber']; ?></p>
    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
    <p><strong>Email:</strong> <?php echo $user['email']; ?></p>
</div>
```

### Activity Logging

#### BEFORE
```php
// Log activity
$stmt = $conn->prepare("
    INSERT INTO admin_activity_log 
    (user_id, action, description) 
    VALUES (?, ?, ?)
");
$stmt->bind_param("iss", $user_id, $action, $description);
$stmt->execute();
```

#### AFTER
```php
// Log activity with IDNUMBER
$stmt = $conn->prepare("
    INSERT INTO admin_activity_log 
    (user_id, user_idnumber, action, description) 
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("isss", $user_id, $user_idnumber, $action, $description);
$stmt->execute();
```

### Helper Functions

#### BEFORE
```php
// No helper functions - direct queries everywhere
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
```

#### AFTER
```php
// Use helper functions
require_once __DIR__ . '/../DB/idnumber_helpers.php';

// Get user by idnumber
$user = getUserByIdNumber($conn, $user_idnumber);

// Convert between id and idnumber
$id = getIdFromIdNumber($conn, $user_idnumber);
$idnumber = getIdNumberFromId($conn, $user_id);

// Check login
if (!isLoggedInByIdNumber()) {
    header('Location: Sign_in.php');
    exit();
}
```

## URL Comparison

### BEFORE (Exposing Sequential IDs)
```
https://bookstore.com/profile.php?user_id=3
https://bookstore.com/profile.php?user_id=4
https://bookstore.com/profile.php?user_id=5
```
**Problem**: Easy to enumerate users by incrementing ID

### AFTER (Using IDNUMBER)
```
https://bookstore.com/profile.php?idnumber=SA-2026-001
https://bookstore.com/profile.php?idnumber=ADM-2026-001
https://bookstore.com/profile.php?idnumber=2022-1432
```
**Benefit**: Harder to guess other user IDs

## Export/Report Comparison

### BEFORE (CSV Export)
```csv
ID,Username,Email,Role,Status
3,superadmin,superadmin@example.com,super_admin,active
4,systemadmin,systemadmin@example.com,admin,active
25,johndoe,john@example.com,customer,active
```

### AFTER (CSV Export)
```csv
ID Number,Username,Email,Role,Status
SA-2026-001,superadmin,superadmin@example.com,super_admin,active
ADM-2026-001,systemadmin,systemadmin@example.com,admin,active
2022-1432,johndoe,john@example.com,customer,active
```

## Database Schema Comparison

### BEFORE
```sql
CREATE TABLE `infos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idnumber` varchar(250) NOT NULL,
  `username` varchar(250) NOT NULL,
  `email` varchar(250) NOT NULL,
  PRIMARY KEY (`id`)
);
```

### AFTER
```sql
CREATE TABLE `infos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idnumber` varchar(250) NOT NULL,
  `username` varchar(250) NOT NULL,
  `email` varchar(250) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_idnumber` (`idnumber`),
  KEY `idx_idnumber` (`idnumber`)
);
```

## Security Comparison

### BEFORE (Sequential IDs)
```
User enumeration attack:
- Try user_id=1, 2, 3, 4, 5...
- Easy to discover all users
- Predictable pattern
```

### AFTER (IDNUMBER)
```
User enumeration attack:
- Try idnumber=SA-2026-001, SA-2026-002...
- Still possible but requires knowledge of format
- Less predictable
- Better privacy
```

## Benefits Summary

| Aspect | BEFORE (ID) | AFTER (IDNUMBER) |
|--------|-------------|------------------|
| **Readability** | `3` | `SA-2026-001` |
| **Professional** | ❌ | ✅ |
| **Privacy** | Low | Better |
| **Enumeration** | Easy | Harder |
| **Audit Trail** | Unclear | Clear |
| **Support** | "User 3" | "User SA-2026-001" |
| **Reports** | Generic | Professional |
| **Security** | Basic | Improved |

## Migration Impact

### What Stays the Same ✓
- Database structure (id is still primary key)
- Foreign key relationships
- Existing queries still work
- No data loss
- No downtime required

### What Changes ✓
- Session variables (adds idnumber)
- Display code (shows idnumber)
- New queries (can use idnumber)
- Activity logs (includes idnumber)
- Reports (shows idnumber)

### Backward Compatibility ✓
- Both id and idnumber work
- Old code continues to function
- Gradual migration possible
- No breaking changes

## Real-World Example

### Support Ticket

#### BEFORE
```
Ticket #1234
Customer: "I can't login"
Support: "What's your user ID?"
Customer: "I don't know, maybe 25?"
Support: "Let me check user 25... is your email john@example.com?"
```

#### AFTER
```
Ticket #1234
Customer: "I can't login"
Support: "What's your ID number?"
Customer: "2022-1432"
Support: "Found you! Let me help with account 2022-1432"
```

### Audit Log

#### BEFORE
```
2026-02-09 10:30:00 - User 3 modified user 25
2026-02-09 10:35:00 - User 4 deleted user 26
```
**Question**: Who is user 3? Who is user 25?

#### AFTER
```
2026-02-09 10:30:00 - SA-2026-001 (superadmin) modified 2022-1432 (johndoe)
2026-02-09 10:35:00 - ADM-2026-001 (systemadmin) deleted 2022-1433 (janedoe)
```
**Clear**: Immediately know who did what to whom

## Conclusion

The migration from ID to IDNUMBER provides:

✅ **Better user experience** - Clear, professional identifiers
✅ **Improved security** - Harder to enumerate users
✅ **Easier support** - Clear user identification
✅ **Better auditing** - Clear activity trails
✅ **Professional appearance** - Matches real-world systems
✅ **Backward compatible** - No breaking changes

**The change is minimal in code but significant in benefits!**
