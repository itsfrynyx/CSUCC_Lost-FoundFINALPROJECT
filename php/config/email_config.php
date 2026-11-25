<?php
/**
 * Email Configuration
 * Configure your email settings here
 */

$local_smtp_path = __DIR__ . '/email.local.php';
$local_smtp = is_file($local_smtp_path) ? require $local_smtp_path : [];

return [
    // Basic Email Settings
    'from_email' => 'princessfrynyxnicole.deloy@csucc.edu.ph',
    'from_name' => 'AI-Enhanced Campus Lost & Found Management System',
    'support_email' => 'princessfrynyxnicole.deloy@csucc.edu.ph',
    'website_url' => 'http://localhost/Online%20Lost & Found%20System/php/admin_manage_orders.php',
    
    // Email Method: 'mail' or 'smtp'
    'method' => 'smtp',
    
    // SMTP Settings
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => getenv('CSUCC_SMTP_USERNAME') ?: ($local_smtp['username'] ?? 'princessfrynyxnicole.deloy@csucc.edu.ph'),
        'password' => preg_replace('/\s+/', '', getenv('CSUCC_SMTP_PASSWORD') ?: ($local_smtp['password'] ?? '')),
        'encryption' => 'tls',
        'auth' => true
    ],
    
    // Email Templates
    'templates' => [
        'header_color' => '#882b2a',
        'button_color' => '#882b2a',
        'logo_url' => 'http://yourdomain.com/PICTURES/bookstore.png'
    ],
    
    // Notification Settings
    'notifications' => [
        'enabled' => true,
        'send_on_pending' => true,
        'send_on_confirmed' => true,
        'send_on_processing' => true,
        'send_on_shipped' => true,
        'send_on_delivered' => true,
        'send_on_cancelled' => true,
        'send_on_refunded' => true
    ],
    
    // Logging
    'log_emails' => true,
    'log_file' => __DIR__ . '/../../logs/email_notifications.log'
];


