<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
if (!isset($_SESSION['user_id'])) { 
    header("Location: Sign_in.php"); 
    exit(); 
}

require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/logger_helper.php';

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $conn->prepare("SELECT i.firstname, i.lastname, i.email, up.phone, up.address, up.city, up.country, up.postal_code, up.profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get cart items
$cart_query = "SELECT sc.id as cart_id, sc.quantity, b.id as book_id, b.title, b.author, b.price, b.stock_quantity, b.cover_image, b.isbn, b.is_active FROM shopping_cart sc JOIN books b ON sc.book_id = b.id WHERE sc.user_id = ? ORDER BY sc.added_at DESC";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// If cart is empty, redirect to cart page
if (empty($cart_items)) {
    header("Location: cart.php");
    exit();
}

// Calculate totals
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$tax_rate = 0.12;
$tax = $subtotal * $tax_rate;
$shipping = $subtotal > 500 ? 0 : 50;
$total = $subtotal + $tax + $shipping;

$_SESSION['checkout_form_token'] ??= bin2hex(random_bytes(32));
$shipping_input = [];
$payment_method = 'Cash on Delivery';
$payment_options = ['Cash on Delivery', 'GCash', 'Bank Transfer'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['address', 'city', 'postal_code', 'phone'] as $field) {
        $shipping_input[$field] = account_input($field);
    }
    ['address' => $address, 'city' => $city, 'postal_code' => $postal, 'phone' => $phone] = $shipping_input;
    $payment_method = account_input('payment_method');
    $full_address = str_replace(["\r", "\n", "\t"], ' ', $address . ', ' . $city . ' ' . $postal);
    if (!hash_equals($_SESSION['checkout_form_token'], account_input('checkout_token', false))) {
        $error_message = 'This order form expired or was already submitted.';
    } elseif ($address === '' || $city === '' || !account_text_is_valid($full_address, 255)
        || !account_postal_is_valid($postal, ($user['country'] ?? '') ?: 'Philippines') || !account_phone_is_valid($phone)) {
        $error_message = 'Enter a valid shipping address, city, postal code, and phone number.';
    } elseif (!in_array($payment_method, $payment_options, true)) {
        $error_message = 'Choose a listed payment method.';
    } else {
        $conn->begin_transaction();
        try {
            $owner = $conn->prepare('SELECT id FROM infos WHERE id = ? FOR UPDATE');
            $owner->bind_param('i', $user_id);
            $owner->execute();
            $owner->get_result()->fetch_assoc();

            $cart = $conn->prepare($cart_query . ' FOR UPDATE');
            $cart->bind_param('i', $user_id);
            $cart->execute();
            $lockedItems = $cart->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!$lockedItems || $lockedItems !== $cart_items) {
                throw new DomainException('Your cart changed. Review it and try again.');
            }
            $order_number = 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $order = $conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, status, payment_method, shipping_address) VALUES (?, ?, ?, 'pending', ?, ?)");
            $order->bind_param('isdss', $user_id, $order_number, $total, $payment_method, $full_address);
            $order->execute();
            $order_id = $conn->insert_id;
            $reserve = $conn->prepare('UPDATE books SET stock_quantity = stock_quantity - ? WHERE id = ? AND is_active = 1 AND stock_quantity >= ?');
            $line = $conn->prepare('INSERT INTO order_items (order_id, book_id, quantity, price_per_item, total_price) VALUES (?, ?, ?, ?, ?)');
            foreach ($lockedItems as $item) {
                $quantity = (int) $item['quantity'];
                if ($quantity <= 0 || (int) $item['is_active'] !== 1 || $item['price'] < 0) {
                    throw new DomainException('One or more books in your cart are unavailable.');
                }
                $reserve->bind_param('iii', $quantity, $item['book_id'], $quantity);
                $reserve->execute();
                if ($reserve->affected_rows !== 1) {
                    throw new DomainException('One or more books no longer have enough stock.');
                }
                $item_total = $item['price'] * $quantity;
                $line->bind_param('iiidd', $order_id, $item['book_id'], $quantity, $item['price'], $item_total);
                $line->execute();
            }

            $clear = $conn->prepare('DELETE FROM shopping_cart WHERE user_id = ?');
            $clear->bind_param('i', $user_id);
            $clear->execute();
            $conn->commit();
            unset($_SESSION['checkout_form_token']);
            $_SESSION['order_success'] = $order_number;
            try {
                log_order_action('create', $order_id, $order_number);
            } catch (Throwable $error) {
                error_log('Order activity logging failed: ' . $error->getMessage());
            }
            header('Location: order_success.php?order=' . rawurlencode($order_number), true, 303);
            exit;
        } catch (Throwable $error) {
            $conn->rollback();
            if ($error instanceof DomainException) {
                $error_message = $error->getMessage();
            } else {
                error_log('Order placement failed: ' . $error->getMessage());
                $error_message = 'Unable to place the order. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background: #FAF9F6; 
            padding-top: 70px;
            color: #1F2937;
            line-height: 1.6;
        }
        
        .main-content { 
            margin-left: 260px; 
            padding: 32px 48px 48px; 
            max-width: 1600px;
            width: calc(100% - 260px);
            min-height: calc(100vh - 70px);
        }
        
        /* Breadcrumb - Minimal */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: 0.875rem;
            color: #6B7280;
        }
        .breadcrumb-nav a {
            color: #6B7280;
            text-decoration: none;
            transition: color 0.2s;
        }
        .breadcrumb-nav a:hover {
            color: #1E3A5F;
        }
        .breadcrumb-nav .current {
            color: #1E3A5F;
            font-weight: 600;
        }
        
        /* Page Header - Clean */
        .page-header {
            background: white;
            padding: 32px 40px;
            border-radius: 12px;
            margin-bottom: 28px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i {
            color: #1E3A5F;
        }
        .page-header p {
            color: #6B7280;
            font-size: 0.95rem;
        }
        
        /* Checkout Steps - Minimal */
        .checkout-steps {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #F3F4F6;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            color: #6B7280;
        }
        .step.active {
            background: #1E3A5F;
            color: white;
        }
        .step i {
            font-size: 0.95rem;
        }
        
        /* Checkout Layout */
        .checkout-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
            align-items: start;
        }
        
        /* Form Section - Minimal */
        .checkout-form {
            background: white;
            border-radius: 12px;
            padding: 28px 32px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .form-section {
            margin-bottom: 28px;
        }
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #F3F4F6;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title i {
            color: #1E3A5F;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-label .required {
            color: #DC2626;
        }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #1F2937;
            transition: all 0.2s ease;
            background: white;
        }
        .form-input:focus {
            outline: none;
            border-color: #1E3A5F;
            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
        }
        textarea.form-input {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        /* Payment Options - Clean */
        .payment-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .payment-option {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }
        .payment-option:hover {
            border-color: #1E3A5F;
            background: #F9FAFB;
        }
        .payment-option input[type="radio"] {
            margin-right: 12px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .payment-option.selected {
            border-color: #1E3A5F;
            background: rgba(30, 58, 95, 0.05);
        }
        .payment-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #111827;
            flex: 1;
        }
        .payment-label i {
            font-size: 1.25rem;
            color: #1E3A5F;
        }
        
        /* Order Summary - Minimal */
        .order-summary {
            background: white;
            border-radius: 12px;
            padding: 28px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: sticky;
            top: 100px;
            height: fit-content;
        }
        
        .summary-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #F3F4F6;
        }
        
        .order-items {
            margin-bottom: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .order-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #F3F4F6;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        .item-image {
            width: 50px;
            height: 70px;
            border-radius: 4px;
            object-fit: cover;
            background: #F3F4F6;
            border: 1px solid #E5E7EB;
        }
        .item-details {
            flex: 1;
            min-width: 0;
        }
        .item-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .item-author {
            font-size: 0.75rem;
            color: #6B7280;
            margin-bottom: 4px;
        }
        .item-qty {
            font-size: 0.75rem;
            color: #6B7280;
        }
        .item-price {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1E3A5F;
            text-align: right;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #F3F4F6;
        }
        .summary-row:last-of-type {
            border-bottom: none;
        }
        .summary-label {
            color: #6B7280;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .summary-value {
            font-weight: 600;
            color: #111827;
            font-size: 0.95rem;
        }
        
        .total-row {
            padding: 18px 0;
            margin-top: 12px;
            border-top: 2px solid #1E3A5F;
        }
        .total-row .summary-label {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
        }
        .total-row .summary-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1E3A5F;
        }
        
        .place-order-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1E3A5F 0%, #2C5282 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 16px;
            box-shadow: 0 2px 8px rgba(30, 58, 95, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .place-order-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 58, 95, 0.35);
        }
        
        .secure-note {
            margin-top: 12px;
            padding: 12px;
            background: #F3F4F6;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #6B7280;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .secure-note i {
            color: #059669;
        }
        
        /* Error Message */
        .error-message {
            background: #FEE2E2;
            color: #991B1B;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.875rem;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .checkout-layout {
                grid-template-columns: 1fr;
            }
            .order-summary {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
                width: 100%;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .checkout-steps {
                flex-direction: column;
                align-items: stretch;
            }
        }
        
        /* Smooth Fade In */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .main-content > * {
            animation: fadeIn 0.4s ease-out;
            animation-fill-mode: both;
        }
        .breadcrumb-nav { animation-delay: 0.05s; }
        .page-header { animation-delay: 0.1s; }
        .checkout-layout { animation-delay: 0.15s; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <!-- Breadcrumb -->
    <nav class="breadcrumb-nav">
        <a href="Dashboard.php"><i class="fas fa-home"></i> Home</a>
        <span>/</span>
        <a href="cart.php"><i class="fas fa-shopping-cart"></i> Cart</a>
        <span>/</span>
        <span class="current"><i class="fas fa-credit-card"></i> Checkout</span>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-lock"></i> Secure Checkout</h1>
        <p>Complete your order securely</p>
        
        <div class="checkout-steps">
            <div class="step">
                <i class="fas fa-shopping-cart"></i>
                <span>Cart</span>
            </div>
            <div class="step active">
                <i class="fas fa-credit-card"></i>
                <span>Checkout</span>
            </div>
            <div class="step">
                <i class="fas fa-check-circle"></i>
                <span>Complete</span>
            </div>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="checkout_token" value="<?= htmlspecialchars($_SESSION['checkout_form_token']) ?>">
        <div class="checkout-layout">
            <!-- Checkout Form -->
            <div class="checkout-form">
                <!-- Shipping Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-shipping-fast"></i>
                        Shipping Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name <span class="required">*</span></label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($user['firstname'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name <span class="required">*</span></label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($user['lastname'] ?? '') ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone Number <span class="required">*</span></label>
                        <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($shipping_input['phone'] ?? $user['phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Shipping Address <span class="required">*</span></label>
                        <textarea name="address" class="form-input" required><?= htmlspecialchars($shipping_input['address'] ?? $user['address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">City <span class="required">*</span></label>
                            <input type="text" name="city" class="form-input" value="<?= htmlspecialchars($shipping_input['city'] ?? $user['city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Postal Code <span class="required">*</span></label>
                            <input type="text" name="postal_code" class="form-input" value="<?= htmlspecialchars($shipping_input['postal_code'] ?? $user['postal_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment Method
                    </h3>
                    
                    <div class="payment-options">
                        <label class="payment-option selected">
                            <input type="radio" name="payment_method" value="Cash on Delivery" <?= $payment_method === 'Cash on Delivery' ? 'checked' : '' ?>>
                            <span class="payment-label">
                                <i class="fas fa-money-bill-wave"></i>
                                Cash on Delivery
                            </span>
                        </label>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="GCash" <?= $payment_method === 'GCash' ? 'checked' : '' ?>>
                            <span class="payment-label">
                                <i class="fas fa-mobile-alt"></i>
                                GCash
                            </span>
                        </label>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="Bank Transfer" <?= $payment_method === 'Bank Transfer' ? 'checked' : '' ?>>
                            <span class="payment-label">
                                <i class="fas fa-university"></i>
                                Bank Transfer
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="order-summary">
                <h3 class="summary-title">Order Summary</h3>
                
                <div class="order-items">
                    <?php foreach ($cart_items as $item): ?>
                        <div class="order-item">
                            <?php 
                            $cover = empty($item['cover_image']) || $item['cover_image'] === '0' 
                                ? '../PICTURES/default-book.jpg' 
                                : '../uploads/books/' . $item['cover_image'];
                            ?>
                            <img src="<?= htmlspecialchars($cover) ?>" 
                                 alt="<?= htmlspecialchars($item['title']) ?>"
                                 class="item-image"
                                 onerror="this.src='../PICTURES/default-book.jpg'">
                            <div class="item-details">
                                <div class="item-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="item-author"><?= htmlspecialchars($item['author']) ?></div>
                                <div class="item-qty">Qty: <?= $item['quantity'] ?></div>
                            </div>
                            <div class="item-price">â‚±<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="summary-row">
                    <span class="summary-label">Subtotal</span>
                    <span class="summary-value">â‚±<?= number_format($subtotal, 2) ?></span>
                </div>
                
                <div class="summary-row">
                    <span class="summary-label">Tax (12%)</span>
                    <span class="summary-value">â‚±<?= number_format($tax, 2) ?></span>
                </div>
                
                <div class="summary-row">
                    <span class="summary-label">Shipping</span>
                    <span class="summary-value">
                        <?php if ($shipping == 0): ?>
                            <span style="color: #059669; font-weight: 700;">FREE</span>
                        <?php else: ?>
                            â‚±<?= number_format($shipping, 2) ?>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="summary-row total-row">
                    <span class="summary-label">Total</span>
                    <span class="summary-value">â‚±<?= number_format($total, 2) ?></span>
                </div>
                
                <button type="submit" name="place_order" class="place-order-btn">
                    <i class="fas fa-lock"></i>
                    Place Order
                </button>
                
                <div class="secure-note">
                    <i class="fas fa-shield-alt"></i>
                    Your payment information is secure
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    // Payment option selection
    document.querySelectorAll('.payment-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.payment-option').forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;
        });
    });
</script>
</body>
</html>

