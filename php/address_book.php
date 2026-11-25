<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require_once __DIR__ . '/../DB/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: Sign_in.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Check if table exists
$table_exists = false;
$result = $conn->query("SHOW TABLES LIKE 'user_addresses'");
if ($result && $result->num_rows > 0) {
    $table_exists = true;
}

$_SESSION['address_book_token'] ??= bin2hex(random_bytes(32));
$success_msg = $_SESSION['address_book_success'] ?? '';
unset($_SESSION['address_book_success']);
$error_msg = '';
$address_input = [];
$limits = ['address_label' => 50, 'full_name' => 200, 'phone' => 20, 'address_line1' => 255, 'address_line2' => 255, 'city' => 100, 'province' => 100, 'postal_code' => 20];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_exists) {
    $action = array_values(array_intersect(['add_address', 'delete_address', 'set_default'], array_keys($_POST)));
    if (!hash_equals($_SESSION['address_book_token'], account_input('address_token', false))) {
        $error_msg = 'This address form expired. Reload the page and try again.';
    } elseif (count($action) !== 1) {
        $error_msg = 'Choose a valid address action.';
    } elseif ($action[0] === 'add_address') {
        foreach ($limits as $field => $limit) {
            $address_input[$field] = account_input($field);
            if (($address_input[$field] === '' && $field !== 'address_line2') || !account_text_is_valid($address_input[$field], $limit)) {
                $error_msg = 'Complete each required address field within its length limit.';
            }
        }
        if (!account_name_is_valid($address_input['full_name']) || !account_phone_is_valid($address_input['phone'])
            || !account_postal_is_valid($address_input['postal_code'], 'Philippines')) {
            $error_msg = 'Enter a valid name, phone number, and four-digit postal code.';
        }
    } else {
        $address_id = account_positive_integer($_POST['address_id'] ?? null);
        if ($address_id === null) {
            $error_msg = 'Choose one of your saved addresses.';
        }
    }

    if ($error_msg === '') {
        $conn->begin_transaction();
        try {
            $owner = $conn->prepare('SELECT id FROM infos WHERE id = ? FOR UPDATE');
            $owner->bind_param('i', $user_id);
            $owner->execute();
            $owner->get_result()->fetch_assoc();

            if ($action[0] !== 'add_address') {
                $check = $conn->prepare('SELECT id FROM user_addresses WHERE id = ? AND user_id = ? FOR UPDATE');
                $check->bind_param('ii', $address_id, $user_id);
                $check->execute();
                if (!$check->get_result()->fetch_assoc()) {
                    throw new DomainException('Choose one of your saved addresses.');
                }
            }
            if ($action[0] === 'set_default' || ($action[0] === 'add_address' && isset($_POST['is_default']))) {
                $reset = $conn->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?');
                $reset->bind_param('i', $user_id);
                $reset->execute();
            }
            if ($action[0] === 'add_address') {
                $is_default = isset($_POST['is_default']) ? 1 : 0;
                $stmt = $conn->prepare('INSERT INTO user_addresses (user_id, address_label, full_name, phone, address_line1, address_line2, city, province, postal_code, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssssssi', $user_id, $address_input['address_label'], $address_input['full_name'], $address_input['phone'],
                    $address_input['address_line1'], $address_input['address_line2'], $address_input['city'], $address_input['province'], $address_input['postal_code'], $is_default);
                $success_msg = 'Address added.';
            } elseif ($action[0] === 'delete_address') {
                $stmt = $conn->prepare('DELETE FROM user_addresses WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $address_id, $user_id);
                $success_msg = 'Address deleted.';
            } else {
                $stmt = $conn->prepare('UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $address_id, $user_id);
                $success_msg = 'Default address updated.';
            }
            $stmt->execute();
            $conn->commit();
            unset($_SESSION['address_book_token']);
            $_SESSION['address_book_success'] = $success_msg;
            header('Location: address_book.php', true, 303);
            exit;
        } catch (Throwable $error) {
            $conn->rollback();
            $success_msg = '';
            if ($error instanceof DomainException) {
                $error_msg = $error->getMessage();
            } else {
                error_log('Address book update failed: ' . $error->getMessage());
                $error_msg = 'Unable to update the address book.';
            }
        }
    }
}

$addresses = [];
if ($table_exists) {
    $list = $conn->prepare('SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC');
    $list->bind_param('i', $user_id);
    $list->execute();
    $addresses = $list->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Address Book - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #F9FAFB; padding-top: 70px; }
        .main-content { margin-left: 260px; padding: 30px 40px; width: calc(100% - 260px); }
        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h1 { font-size: 2rem; font-weight: 700; color: #111827; }
        .btn-primary { background: #6366F1; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary:hover { background: #4F46E5; }
        .addresses-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
        .address-card { background: white; border: 2px solid #E5E7EB; border-radius: 12px; padding: 24px; position: relative; transition: all 0.2s; }
        .address-card.default { border-color: #6366F1; background: linear-gradient(to bottom, #EFF6FF 0%, white 100%); }
        .address-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .default-badge { position: absolute; top: 16px; right: 16px; background: #6366F1; color: white; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .address-label { font-size: 1.1rem; font-weight: 700; color: #111827; margin-bottom: 12px; }
        .address-details { color: #374151; line-height: 1.8; margin-bottom: 16px; }
        .address-actions { display: flex; gap: 8px; }
        .btn-small { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; cursor: pointer; border: none; font-weight: 600; }
        .btn-default { background: #F3F4F6; color: #374151; }
        .btn-default:hover { background: #E5E7EB; }
        .btn-delete { background: #FEE2E2; color: #991B1B; }
        .btn-delete:hover { background: #FEE2E2; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 16px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-content h2 { margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #E5E7EB; border-radius: 8px; font-size: 1rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #6366F1; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .checkbox-group { display: flex; align-items: center; gap: 8px; }
        .checkbox-group input { width: auto; }
        .btn-close { background: #F3F4F6; color: #374151; }
        .btn-close:hover { background: #E5E7EB; }
        .empty-state { text-align: center; padding: 80px 20px; }
        .empty-state i { font-size: 5rem; color: #E5E7EB; margin-bottom: 20px; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <?php if (!$table_exists): ?>
    <div style="background: #FEF3C7; border: 2px solid #F59E0B; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
        <h3 style="color: #92400E; margin-bottom: 12px;">
            <i class="fas fa-exclamation-triangle"></i> Database Table Missing
        </h3>
        <p style="color: #92400E; margin-bottom: 16px;">
            The <code>user_addresses</code> table doesn't exist yet. Please run the SQL migration to create it.
        </p>
        <details style="color: #92400E;">
            <summary style="cursor: pointer; font-weight: 600; margin-bottom: 12px;">Click to see SQL code</summary>
            <pre style="background: white; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 0.85rem;">
CREATE TABLE `user_addresses` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `address_label` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(200) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) NOT NULL,
    `province` VARCHAR(100) NOT NULL,
    `postal_code` VARCHAR(20) NOT NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
            <p style="margin-top: 12px;">
                <strong>How to run:</strong><br>
                1. Open phpMyAdmin (http://localhost/phpmyadmin)<br>
                2. Select database: <code>dajunos_security</code><br>
                3. Click "SQL" tab<br>
                4. Copy and paste the SQL above<br>
                5. Click "Go"<br>
                6. Refresh this page
            </p>
        </details>
    </div>
    <?php endif; ?>
    
    <div class="page-header">
        <h1><i class="fas fa-address-book"></i> Address Book</h1>
        <button class="btn-primary" onclick="openModal()">
            <i class="fas fa-plus"></i> Add New Address
        </button>
    </div>

    <?php if (empty($addresses)): ?>
        <div class="empty-state">
            <i class="fas fa-map-marker-alt"></i>
            <h3>No Addresses Saved</h3>
            <p>Add your first delivery address</p>
        </div>
    <?php else: ?>
        <div class="addresses-grid">
            <?php foreach ($addresses as $addr): ?>
            <div class="address-card <?= $addr['is_default'] ? 'default' : '' ?>">
                <?php if ($addr['is_default']): ?>
                    <span class="default-badge"><i class="fas fa-check"></i> Default</span>
                <?php endif; ?>
                
                <div class="address-label">
                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($addr['address_label']) ?>
                </div>
                
                <div class="address-details">
                    <strong><?= htmlspecialchars($addr['full_name']) ?></strong><br>
                    <?= htmlspecialchars($addr['phone']) ?><br>
                    <?= htmlspecialchars($addr['address_line1']) ?><br>
                    <?php if ($addr['address_line2']): ?>
                        <?= htmlspecialchars($addr['address_line2']) ?><br>
                    <?php endif; ?>
                    <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['province']) ?> <?= htmlspecialchars($addr['postal_code']) ?>
                </div>
                
                <div class="address-actions">
                    <?php if (!$addr['is_default']): ?>
                    <form method="POST" style="display: inline;">
                    <input type="hidden" name="address_token" value="<?= htmlspecialchars($_SESSION['address_book_token']) ?>">
                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                        <button type="submit" name="set_default" class="btn-small btn-default">
                            <i class="fas fa-star"></i> Set Default
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this address?')">
                    <input type="hidden" name="address_token" value="<?= htmlspecialchars($_SESSION['address_book_token']) ?>">
                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                        <button type="submit" name="delete_address" class="btn-small btn-delete">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Address Modal -->
<div class="modal" id="addressModal">
    <div class="modal-content">
        <h2><i class="fas fa-plus"></i> Add New Address</h2>
        <form method="POST">
                    <input type="hidden" name="address_token" value="<?= htmlspecialchars($_SESSION['address_book_token']) ?>">
            <div class="form-group">
                <label>Address Label (e.g., Home, Office)</label>
                <input type="text" name="address_label" value="<?= htmlspecialchars($address_input['address_label'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="50">
            </div>
            
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($address_input['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="200">
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($address_input['phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="20">
            </div>
            
            <div class="form-group">
                <label>Address Line 1</label>
                <input type="text" name="address_line1" value="<?= htmlspecialchars($address_input['address_line1'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="255">
            </div>
            
            <div class="form-group">
                <label>Address Line 2 (Optional)</label>
                <input type="text" name="address_line2" value="<?= htmlspecialchars($address_input['address_line2'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="255">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?= htmlspecialchars($address_input['city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="100">
                </div>
                
                <div class="form-group">
                    <label>Province</label>
                    <input type="text" name="province" value="<?= htmlspecialchars($address_input['province'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="100">
                </div>
            </div>
            
            <div class="form-group">
                <label>Postal Code</label>
                <input type="text" name="postal_code" value="<?= htmlspecialchars($address_input['postal_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="20">
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="is_default" id="is_default">
                <label for="is_default" style="margin: 0;">Set as default address</label>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" name="add_address" class="btn-primary" style="flex: 1;">
                    <i class="fas fa-save"></i> Save Address
                </button>
                <button type="button" class="btn-primary btn-close" onclick="closeModal()" style="flex: 1;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('addressModal').classList.add('active');
}

function closeModal() {
    document.getElementById('addressModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('addressModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

</body>
</html>

