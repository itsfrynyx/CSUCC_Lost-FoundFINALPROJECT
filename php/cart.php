<?php
session_start();
if (!isset($_SESSION['user_id'])) { 
    header("Location: Sign_in.php"); 
    exit(); 
}

require_once __DIR__ . '/../DB/conn.php';
$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $conn->prepare("SELECT firstname, lastname, profile_picture FROM infos i LEFT JOIN user_profiles up ON i.id = up.user_id WHERE i.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get cart items
$cart_query = "SELECT sc.id as cart_id, sc.quantity, sc.added_at, 
               b.id as book_id, b.title, b.author, b.price, b.stock_quantity, b.cover_image, b.isbn 
               FROM shopping_cart sc 
               JOIN books b ON sc.book_id = b.id 
               WHERE sc.user_id = ? 
               ORDER BY sc.added_at DESC";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate totals
$subtotal = 0;
foreach ($cart_items as $item) { 
    $subtotal += $item['price'] * $item['quantity']; 
}
$tax_rate = 0.12; 
$tax = $subtotal * $tax_rate; 
$shipping = $subtotal > 500 ? 0 : 50; 
$total = $subtotal + $tax + $shipping;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Campus Lost & Found</title>
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
        
        /* Cart Layout */
        .cart-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
            align-items: start;
        }
        
        /* Cart Items Section - Minimal */
        .cart-items-section {
            background: white;
            border-radius: 12px;
            padding: 28px 32px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #F3F4F6;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title i {
            color: #1E3A5F;
        }
        .section-title .count {
            margin-left: auto;
            background: #1E3A5F;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Cart Item - Clean Design */
        .cart-item {
            display: flex;
            gap: 18px;
            padding: 18px;
            background: #FAFBFC;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }
        .cart-item:hover {
            border-color: #1E3A5F;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .book-image-wrapper {
            width: 90px;
            height: 130px;
            border-radius: 6px;
            overflow: hidden;
            background: #F3F4F6;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid #E5E7EB;
        }
        .book-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .book-image-placeholder {
            font-size: 2rem;
            color: #D1D5DB;
        }
        
        .item-details {
            flex: 1;
            min-width: 0;
        }
        .book-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .book-author {
            color: #6B7280;
            font-size: 0.875rem;
            margin-bottom: 6px;
        }
        .book-author i {
            color: #1E3A5F;
            margin-right: 4px;
        }
        .book-isbn {
            color: #9CA3AF;
            font-size: 0.75rem;
            font-family: 'Courier New', monospace;
            background: #F3F4F6;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 12px;
        }
        
        .item-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
        }
        .qty-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: #1E3A5F;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
        .qty-btn:hover {
            background: #2C5282;
        }
        .qty-input {
            width: 50px;
            text-align: center;
            border: none;
            background: transparent;
            font-weight: 600;
            font-size: 0.95rem;
            color: #1E3A5F;
        }
        
        .remove-btn {
            padding: 6px 14px;
            background: #DC2626;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .remove-btn:hover {
            background: #B91C1C;
        }
        
        .item-price {
            text-align: right;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-end;
            min-width: 100px;
        }
        .price-label {
            color: #6B7280;
            font-size: 0.75rem;
            margin-bottom: 2px;
        }
        .price-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1E3A5F;
        }
        .unit-price {
            font-size: 0.75rem;
            color: #9CA3AF;
            margin-top: 2px;
        }
        
        .stock-status {
            font-size: 0.75rem;
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
        }
        .in-stock {
            background: #D1FAE5;
            color: #065F46;
        }
        .low-stock {
            background: #FEF3C7;
            color: #92400E;
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
        
        .checkout-btn {
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
        }
        .checkout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 58, 95, 0.35);
        }
        
        .continue-shopping {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: #1E3A5F;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .continue-shopping:hover {
            background: #F9FAFB;
            border-color: #1E3A5F;
        }
        
        /* Empty Cart - Minimal */
        .empty-cart {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
        }
        .empty-cart i {
            font-size: 4rem;
            color: #D1D5DB;
            margin-bottom: 20px;
        }
        .empty-cart h3 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #111827;
            font-weight: 700;
        }
        .empty-cart p {
            color: #6B7280;
            font-size: 0.95rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .btn {
            background: linear-gradient(135deg, #1E3A5F 0%, #2C5282 100%);
            color: white;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(30, 58, 95, 0.25);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 58, 95, 0.35);
        }
        
        .empty-cart-features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #F3F4F6;
        }
        .empty-cart-feature {
            padding: 16px;
            background: #F9FAFB;
            border-radius: 8px;
        }
        .empty-cart-feature i {
            font-size: 1.75rem;
            margin-bottom: 8px;
        }
        .empty-cart-feature h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
        }
        .empty-cart-feature p {
            font-size: 0.8rem;
            color: #6B7280;
            margin: 0;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .cart-layout {
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
            .cart-item {
                flex-direction: column;
            }
            .book-image-wrapper {
                width: 100%;
                height: 200px;
            }
            .item-actions {
                flex-direction: column;
                width: 100%;
            }
            .quantity-control,
            .remove-btn {
                width: 100%;
            }
            .empty-cart-features {
                grid-template-columns: 1fr;
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
        .cart-layout { animation-delay: 0.15s; }
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
        <span class="current"><i class="fas fa-shopping-cart"></i> Shopping Cart</span>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-shopping-cart"></i> Shopping Cart</h1>
        <p>Review your items and proceed to secure checkout</p>
    </div>

    <?php if (empty($cart_items)): ?>
        <!-- Empty Cart -->
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <h3>Your Cart is Empty</h3>
            <p>Looks like you haven't added any books yet.<br>Start exploring our collection and find your next great read!</p>
            
            <a href="browse_books.php" class="btn">Discover Books</a>
            
            <div class="empty-cart-features">
                <div class="empty-cart-feature">
                    <i class="fas fa-truck" style="color: #059669;"></i>
                    <h4>Free Shipping</h4>
                    <p>On orders over â‚±500</p>
                </div>
                <div class="empty-cart-feature">
                    <i class="fas fa-tag" style="color: #D97706;"></i>
                    <h4>Best Prices</h4>
                    <p>Affordable second-hand books</p>
                </div>
                <div class="empty-cart-feature">
                    <i class="fas fa-heart" style="color: #1E3A5F;"></i>
                    <h4>Curated Selection</h4>
                    <p>Quality books for everyone</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Cart Layout -->
        <div class="cart-layout">
            <!-- Cart Items -->
            <div class="cart-items-section">
                <h2 class="section-title">
                    <i class="fas fa-list-ul"></i>
                    <span>Cart Items</span>
                    <span class="count"><?= count($cart_items) ?> <?= count($cart_items) === 1 ? 'Item' : 'Items' ?></span>
                </h2>

                <?php foreach ($cart_items as $item): ?>
                    <div class="cart-item" data-cart-id="<?= $item['cart_id'] ?>">
                        <div class="book-image-wrapper">
                            <?php 
                            $image_path = '';
                            if (!empty($item['cover_image'])) {
                                if (strpos($item['cover_image'], 'uploads/') !== false) {
                                    $image_path = '../' . $item['cover_image'];
                                } else {
                                    $image_path = '../uploads/books/' . $item['cover_image'];
                                }
                            }
                            
                            if (!empty($image_path) && file_exists($image_path)): 
                            ?>
                                <img src="<?= htmlspecialchars($image_path) ?>" 
                                     alt="<?= htmlspecialchars($item['title']) ?>" 
                                     class="book-image"
                                     onerror="this.parentElement.innerHTML='<i class=\'fas fa-book book-image-placeholder\'></i>'">
                            <?php else: ?>
                                <i class="fas fa-book book-image-placeholder"></i>
                            <?php endif; ?>
                        </div>
                        
                        <div class="item-details">
                            <h3 class="book-title"><?= htmlspecialchars($item['title']) ?></h3>
                            <p class="book-author">
                                <i class="fas fa-user-edit"></i>
                                <?= htmlspecialchars($item['author']) ?>
                            </p>
                            <p class="book-isbn">
                                <i class="fas fa-barcode"></i> <?= htmlspecialchars($item['isbn']) ?>
                            </p>
                            
                            <div class="item-actions">
                                <div class="quantity-control">
                                    <button type="button" class="qty-btn" onclick="updateQuantity(<?= $item['cart_id'] ?>, -1)">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <input type="number" class="qty-input" value="<?= $item['quantity'] ?>" 
                                           min="1" max="<?= $item['stock_quantity'] ?>" readonly>
                                    <button type="button" class="qty-btn" onclick="updateQuantity(<?= $item['cart_id'] ?>, 1)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                <button type="button" class="remove-btn" onclick="removeItem(<?= $item['cart_id'] ?>)">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </button>
                            </div>
                            
                            <?php if ($item['stock_quantity'] <= 5): ?>
                                <div class="stock-status low-stock">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Only <?= $item['stock_quantity'] ?> left in stock
                                </div>
                            <?php else: ?>
                                <div class="stock-status in-stock">
                                    <i class="fas fa-check-circle"></i>
                                    In Stock
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="item-price">
                            <div class="price-label">Total</div>
                            <div class="price-value">â‚±<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
                            <div class="unit-price">â‚±<?= number_format($item['price'], 2) ?> each</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Order Summary -->
            <div class="order-summary">
                <h3 class="summary-title">Order Summary</h3>
                
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
                
                <button type="button" class="checkout-btn" onclick="window.location.href='checkout.php'">
                    <i class="fas fa-lock"></i> Proceed to Checkout
                </button>
                
                <button type="button" class="continue-shopping" onclick="window.location.href='browse_books.php'">
                    <i class="fas fa-arrow-left"></i> Continue Shopping
                </button>
                
                <?php if ($subtotal < 500 && $subtotal > 0): ?>
                    <div style="margin-top: 16px; padding: 12px; background: #FEF3C7; border-radius: 8px; font-size: 0.8rem; color: #92400E; text-align: center;">
                        <i class="fas fa-info-circle"></i> Add â‚±<?= number_format(500 - $subtotal, 2) ?> more for FREE shipping!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function updateQuantity(cartId, change) {
        const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
        const qtyInput = cartItem.querySelector('.qty-input');
        let newQty = parseInt(qtyInput.value) + change;
        const maxQty = parseInt(qtyInput.max);
        
        if (newQty < 1) newQty = 1;
        if (newQty > maxQty) {
            alert(`Sorry, only ${maxQty} items available in stock.`);
            return;
        }
        
        fetch('cart_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `cart_id=${cartId}&quantity=${newQty}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to update quantity');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function removeItem(cartId) {
        if (!confirm('Are you sure you want to remove this item from your cart?')) {
            return;
        }
        
        fetch('cart_remove.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `cart_id=${cartId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to remove item');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
</script>
</body>
</html>

