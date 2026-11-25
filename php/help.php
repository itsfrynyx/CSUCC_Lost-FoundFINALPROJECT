<?php
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center - Campus Lost & Found</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="includes/curiosity_quills_colors.css?v=3.0">
    <link rel="stylesheet" href="includes/customer_mobile_responsive.css?v=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #F9FAFB; padding-top: 70px; }
        .main-content { margin-left: 260px; padding: 30px 40px; width: calc(100% - 260px); }
        .page-header { background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%); color: white; padding: 40px; border-radius: 16px; margin-bottom: 30px; text-align: center; }
        .page-header h1 { font-size: 2rem; font-weight: 700; margin-bottom: 8px; }
        .page-header p { opacity: 0.9; font-size: 1.1rem; }
        .help-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .help-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 24px; text-align: center; transition: all 0.2s; }
        .help-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); border-color: #6366F1; }
        .help-card i { font-size: 3rem; color: #6366F1; margin-bottom: 16px; }
        .help-card h3 { font-size: 1.25rem; font-weight: 600; margin-bottom: 12px; color: #111827; }
        .help-card p { color: #6B7280; margin-bottom: 16px; }
        .help-card a { color: #6366F1; text-decoration: none; font-weight: 600; }
        .help-card a:hover { color: #4F46E5; }
        .faq-section { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 32px; }
        .faq-section h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 24px; color: #111827; }
        .faq-item { border-bottom: 1px solid #E5E7EB; padding: 20px 0; }
        .faq-item:last-child { border-bottom: none; }
        .faq-question { font-weight: 600; font-size: 1.1rem; color: #111827; margin-bottom: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .faq-question:hover { color: #6366F1; }
        .faq-answer { color: #6B7280; line-height: 1.6; display: none; padding-top: 8px; }
        .faq-answer.active { display: block; }
        .contact-section { background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%); color: white; padding: 40px; border-radius: 12px; text-align: center; margin-top: 40px; }
        .contact-section h2 { font-size: 1.75rem; font-weight: 700; margin-bottom: 12px; }
        .contact-section p { opacity: 0.9; margin-bottom: 24px; }
        .btn-white { background: white; color: #6366F1; padding: 12px 24px; border-radius: 8px; text-decoration: none; display: inline-block; font-weight: 600; }
        .btn-white:hover { background: #F3F4F6; }
    </style>
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>
<?php include 'includes/mobile_menu_button.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-question-circle"></i> Help Center</h1>
        <p>We're here to help you with any questions</p>
    </div>

    <div class="help-grid">
        <div class="help-card">
            <i class="fas fa-shopping-cart"></i>
            <h3>Orders & Shipping</h3>
            <p>Track orders, shipping info, and delivery updates</p>
            <a href="#orders-faq">Learn More â†’</a>
        </div>
        <div class="help-card">
            <i class="fas fa-credit-card"></i>
            <h3>Payment & Billing</h3>
            <p>Payment methods, invoices, and refunds</p>
            <a href="#payment-faq">Learn More â†’</a>
        </div>
        <div class="help-card">
            <i class="fas fa-user-circle"></i>
            <h3>Account Settings</h3>
            <p>Manage your profile, password, and preferences</p>
            <a href="settings.php">Go to Settings â†’</a>
        </div>
    </div>

    <div class="faq-section">
        <h2><i class="fas fa-comments"></i> Frequently Asked Questions</h2>
        
        <div class="faq-item" id="orders-faq">
            <div class="faq-question" onclick="toggleFaq(this)">
                How do I track my order?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                You can track your order by going to "My Orders" in the sidebar. Click on any order to see detailed tracking information and delivery status.
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                What payment methods do you accept?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                We accept credit cards, debit cards, PayPal, and cash on delivery for eligible orders.
            </div>
        </div>

        <div class="faq-item" id="payment-faq">
            <div class="faq-question" onclick="toggleFaq(this)">
                How long does shipping take?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                Standard shipping takes 3-5 business days. Express shipping is available for 1-2 business days delivery.
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                Can I cancel or modify my order?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                You can cancel or modify your order within 24 hours of placing it. Go to "My Orders" and click "Cancel Order" or contact support.
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                What is your return policy?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                We offer a 30-day return policy for unopened books in original condition. Contact support to initiate a return.
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                How do I add books to my wishlist?
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="faq-answer">
                Click the heart icon on any book to add it to your wishlist. You can view all your wishlist items from the sidebar.
            </div>
        </div>
    </div>

    <div class="contact-section">
        <h2>Still Need Help?</h2>
        <p>Our support team is available 24/7 to assist you</p>
        <a href="mailto:support@curiosityquills.com" class="btn-white">
            <i class="fas fa-envelope"></i> Contact Support
        </a>
    </div>
</div>

<script>
function toggleFaq(element) {
    const answer = element.nextElementSibling;
    const icon = element.querySelector('i');
    
    answer.classList.toggle('active');
    
    if (answer.classList.contains('active')) {
        icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
    } else {
        icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
    }
}
</script>

</body>
</html>

