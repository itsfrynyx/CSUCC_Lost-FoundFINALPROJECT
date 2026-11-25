<?php
/**
 * Email Notification Service
 * Handles sending email notifications to customers for order status updates
 */

class EmailNotificationService {
    private $conn;
    private $config;
    private $from_email;
    private $from_name;
    private $smtp_enabled;
    
    public function __construct($conn) {
        $this->conn = $conn;
        
        // Load Composer autoloader if available (already installed for forgotpass)
        $autoload_path = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
        }
        
        // Load email configuration
        $config_file = __DIR__ . '/config/email_config.php';
        if (file_exists($config_file)) {
            $this->config = require $config_file;
        } else {
            // Default configuration
            $this->config = [
                'from_email' => 'noreply@curiosityquills.com',
                'from_name' => 'Campus Lost & Found',
                'method' => 'mail',
                'notifications' => ['enabled' => true],
                'log_emails' => true
            ];
        }
        
        $this->from_email = $this->config['from_email'];
        $this->from_name = $this->config['from_name'];
        $this->smtp_enabled = ($this->config['method'] === 'smtp');
    }
    
    /**
     * Send order status update email to customer
     */
    public function sendOrderStatusEmail($order_id, $customer_email, $customer_name, $order_number, $old_status, $new_status, $tracking_number = null, $estimated_delivery = null) {
        // Check if notifications are enabled
        if (!$this->config['notifications']['enabled']) {
            return false;
        }
        
        // Check if this specific status notification is enabled
        $status_key = 'send_on_' . $new_status;
        if (isset($this->config['notifications'][$status_key]) && !$this->config['notifications'][$status_key]) {
            return false;
        }
        
        // Get status configuration
        $status_config = $this->getStatusEmailConfig($new_status);
        
        // Prepare email content
        $subject = $status_config['subject'] . " - Order #" . $order_number;
        $message = $this->buildEmailMessage($customer_name, $order_number, $old_status, $new_status, $status_config, $tracking_number, $estimated_delivery);
        
        // Send email
        $sent = $this->sendEmail($customer_email, $customer_name, $subject, $message);
        
        // Update notification status in database
        $this->updateNotificationStatus($order_id, $sent);
        
        // Log the email attempt
        if ($this->config['log_emails']) {
            $this->logEmailAttempt($order_id, $customer_email, $subject, $sent);
        }
        
        return $sent;
    }
    
    /**
     * Build HTML email message
     */
    private function buildEmailMessage($customer_name, $order_number, $old_status, $new_status, $config, $tracking_number, $estimated_delivery) {
        $status_icon = $config['icon'];
        $status_color = $config['color'];
        $status_label = $config['label'];
        $status_message = $config['message'];
        
        $tracking_html = '';
        if ($tracking_number) {
            $tracking_html = "
            <div style='background: #F8FAFC; border: 2px solid #E2E8F0; padding: 20px; border-radius: 12px; margin: 25px 0;'>
                <div style='display: flex; align-items: center; gap: 12px; margin-bottom: 8px;'>
                    <div style='width: 40px; height: 40px; background: linear-gradient(135deg, #3B82F6, #2563EB); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;'>ðŸ“¦</div>
                    <div>
                        <p style='margin: 0; color: #64748B; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;'>Tracking Number</p>
                        <p style='margin: 0; color: #0F172A; font-size: 18px; font-weight: 700; font-family: monospace;'>{$tracking_number}</p>
                    </div>
                </div>
            </div>";
        }
        
        $delivery_html = '';
        if ($estimated_delivery) {
            $delivery_date = date('F j, Y', strtotime($estimated_delivery));
            $delivery_html = "
            <div style='background: #F0FDF4; border: 2px solid #BBF7D0; padding: 20px; border-radius: 12px; margin: 25px 0;'>
                <div style='display: flex; align-items: center; gap: 12px;'>
                    <div style='width: 40px; height: 40px; background: linear-gradient(135deg, #10B981, #059669); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;'>ðŸšš</div>
                    <div>
                        <p style='margin: 0; color: #166534; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;'>Estimated Delivery</p>
                        <p style='margin: 0; color: #0F172A; font-size: 18px; font-weight: 700;'>{$delivery_date}</p>
                    </div>
                </div>
            </div>";
        }
        
        $html = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <meta http-equiv='X-UA-Compatible' content='IE=edge'>
            <title>Order Status Update - Campus Lost & Found</title>
            <!--[if mso]>
            <style type='text/css'>
                body, table, td {font-family: Arial, sans-serif !important;}
            </style>
            <![endif]-->
        </head>
        <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #F1F5F9; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;'>
            <!-- Preheader Text -->
            <div style='display: none; max-height: 0; overflow: hidden; mso-hide: all;'>
                Your order #{$order_number} status has been updated to {$status_label}. {$status_message}
            </div>
            
            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #F1F5F9; padding: 40px 20px;'>
                <tr>
                    <td align='center'>
                        <!-- Main Container -->
                        <table width='600' cellpadding='0' cellspacing='0' border='0' style='background-color: #FFFFFF; border-radius: 16px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1); overflow: hidden; max-width: 600px;'>
                            
                            <!-- Header with Logo and Branding -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #882b2a 0%, #a14a22 100%); padding: 40px 30px; text-align: center; position: relative;'>
                                    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                        <tr>
                                            <td align='center'>
                                                <!-- Logo -->
                                                <div style='width: 70px; height: 70px; background: rgba(255, 255, 255, 0.15); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; backdrop-filter: blur(10px); border: 2px solid rgba(255, 255, 255, 0.2);'>
                                                    <span style='font-size: 36px;'>ðŸ“š</span>
                                                </div>
                                                
                                                <!-- Brand Name -->
                                                <h1 style='margin: 0; color: #FFFFFF; font-size: 26px; font-weight: 700; letter-spacing: -0.5px; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);'>
                                                    Campus Lost & Found
                                                </h1>
                                                <p style='margin: 8px 0 0 0; color: rgba(255, 255, 255, 0.95); font-size: 14px; font-weight: 500; letter-spacing: 0.3px;'>
                                                    AI-Enhanced Campus Lost & Found Management System
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            
                            <!-- Content Section -->
                            <tr>
                                <td style='padding: 50px 40px;'>
                                    <!-- Greeting -->
                                    <p style='margin: 0 0 10px 0; color: #64748B; font-size: 14px; font-weight: 500;'>
                                        Hello,
                                    </p>
                                    <h2 style='margin: 0 0 30px 0; color: #0F172A; font-size: 24px; font-weight: 700; line-height: 1.3;'>
                                        {$customer_name}
                                    </h2>
                                    
                                    <!-- Main Message -->
                                    <p style='margin: 0 0 30px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                        We're writing to inform you that your order has been updated. Here's the latest status of your purchase:
                                    </p>
                                    
                                    <!-- Order Info Card -->
                                    <div style='background: #F8FAFC; border: 2px solid #E2E8F0; border-radius: 12px; padding: 24px; margin: 30px 0;'>
                                        <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                            <tr>
                                                <td style='padding-bottom: 16px; border-bottom: 1px solid #E2E8F0;'>
                                                    <p style='margin: 0 0 4px 0; color: #64748B; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;'>
                                                        Order Number
                                                    </p>
                                                    <p style='margin: 0; color: #0F172A; font-size: 18px; font-weight: 700; font-family: monospace;'>
                                                        #{$order_number}
                                                    </p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style='padding-top: 16px;'>
                                                    <p style='margin: 0 0 12px 0; color: #64748B; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;'>
                                                        Current Status
                                                    </p>
                                                    <div style='display: inline-block; background: {$status_color}; padding: 12px 24px; border-radius: 8px;'>
                                                        <span style='color: #FFFFFF; font-size: 16px; font-weight: 700; letter-spacing: 0.3px;'>
                                                            {$status_icon} {$status_label}
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    
                                    <!-- Status Message -->
                                    <div style='background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%); border-left: 4px solid #3B82F6; padding: 20px 24px; border-radius: 8px; margin: 30px 0;'>
                                        <p style='margin: 0; color: #1E40AF; font-size: 15px; line-height: 1.6; font-weight: 500;'>
                                            {$status_message}
                                        </p>
                                    </div>
                                    
                                    {$tracking_html}
                                    {$delivery_html}
                                    
                                    <!-- Action Button -->
                                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin: 40px 0;'>
                                        <tr>
                                            <td align='center'>
                                                <a href='{$this->config['website_url']}/php/orders.php' style='display: inline-block; background: linear-gradient(135deg, #882b2a 0%, #a14a22 100%); color: #FFFFFF; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 12px rgba(136, 43, 42, 0.3); transition: all 0.3s ease;'>
                                                    View Order Details
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <!-- Order Timeline -->
                                    <div style='margin: 40px 0; padding-top: 30px; border-top: 2px solid #E2E8F0;'>
                                        <p style='margin: 0 0 20px 0; color: #0F172A; font-size: 16px; font-weight: 600;'>
                                            Order Timeline
                                        </p>
                                        <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                            <tr>
                                                <td style='padding: 8px 0; color: #64748B; font-size: 14px;'>
                                                    <span style='display: inline-block; width: 8px; height: 8px; background: #10B981; border-radius: 50%; margin-right: 12px;'></span>
                                                    Order Placed
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style='padding: 8px 0; color: #64748B; font-size: 14px;'>
                                                    <span style='display: inline-block; width: 8px; height: 8px; background: {$status_color}; border-radius: 50%; margin-right: 12px;'></span>
                                                    {$status_label}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    
                                    <!-- Help Section -->
                                    <div style='background: #FFFBEB; border: 2px solid #FDE68A; border-radius: 10px; padding: 20px; margin: 30px 0;'>
                                        <p style='margin: 0 0 8px 0; color: #92400E; font-size: 14px; font-weight: 600;'>
                                            ðŸ“ž Need Help?
                                        </p>
                                        <p style='margin: 0; color: #78350F; font-size: 13px; line-height: 1.6;'>
                                            If you have any questions about your order, please don't hesitate to contact our support team at <a href='mailto:{$this->config['support_email']}' style='color: #92400E; font-weight: 600; text-decoration: none;'>{$this->config['support_email']}</a>
                                        </p>
                                    </div>
                                    
                                    <!-- Closing -->
                                    <p style='margin: 30px 0 0 0; color: #475569; font-size: 15px; line-height: 1.6;'>
                                        Thank you for choosing AI-Enhanced Campus Lost & Found Management System. We appreciate your business!
                                    </p>
                                    
                                    <p style='margin: 20px 0 0 0; color: #64748B; font-size: 14px;'>
                                        Best regards,<br>
                                        <strong style='color: #0F172A;'>The Campus Lost & Found Team</strong>
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style='background: #F8FAFC; padding: 40px 40px; border-top: 1px solid #E2E8F0;'>
                                    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                        <tr>
                                            <td align='center'>
                                                <!-- Social Links (Optional) -->
                                                <div style='margin-bottom: 20px;'>
                                                    <p style='margin: 0 0 12px 0; color: #64748B; font-size: 13px; font-weight: 600;'>
                                                        Follow Us
                                                    </p>
                                                    <a href='#' style='display: inline-block; width: 36px; height: 36px; background: #3B82F6; border-radius: 8px; margin: 0 4px; text-decoration: none; line-height: 36px; color: white;'>f</a>
                                                    <a href='#' style='display: inline-block; width: 36px; height: 36px; background: #0EA5E9; border-radius: 8px; margin: 0 4px; text-decoration: none; line-height: 36px; color: white;'>t</a>
                                                    <a href='#' style='display: inline-block; width: 36px; height: 36px; background: #EC4899; border-radius: 8px; margin: 0 4px; text-decoration: none; line-height: 36px; color: white;'>i</a>
                                                </div>
                                                
                                                <!-- Footer Links -->
                                                <p style='margin: 0 0 16px 0; color: #64748B; font-size: 13px;'>
                                                    <a href='#' style='color: #64748B; text-decoration: none; margin: 0 8px;'>Privacy Policy</a> |
                                                    <a href='#' style='color: #64748B; text-decoration: none; margin: 0 8px;'>Terms of Service</a> |
                                                    <a href='#' style='color: #64748B; text-decoration: none; margin: 0 8px;'>Contact Us</a>
                                                </p>
                                                
                                                <!-- Copyright -->
                                                <p style='margin: 0 0 8px 0; color: #94A3B8; font-size: 12px; line-height: 1.6;'>
                                                    Â© " . date('Y') . " AI-Enhanced Campus Lost & Found Management System<br>
                                                    All rights reserved.
                                                </p>
                                                
                                                <!-- Unsubscribe -->
                                                <p style='margin: 8px 0 0 0; color: #CBD5E1; font-size: 11px;'>
                                                    This is an automated notification email. Please do not reply to this message.
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Send email using PHP mail() or SMTP
     */
    private function sendEmail($to_email, $to_name, $subject, $html_message) {
        // Set headers for HTML email
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$this->from_name} <{$this->from_email}>" . "\r\n";
        $headers .= "Reply-To: {$this->from_email}" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // Send email
        try {
            if ($this->smtp_enabled) {
                // Use SMTP (requires PHPMailer or similar)
                return $this->sendViaSMTP($to_email, $to_name, $subject, $html_message);
            } else {
                // Use PHP mail()
                return mail($to_email, $subject, $html_message, $headers);
            }
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send via SMTP (using PHPMailer or fallback)
     */
    private function sendViaSMTP($to_email, $to_name, $subject, $html_message) {
        // Check if PHPMailer is available
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendViaPHPMailer($to_email, $to_name, $subject, $html_message);
        }
        
        // Fallback: Try using PHP mail() function
        // Set headers for HTML email
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$this->from_name} <{$this->from_email}>" . "\r\n";
        $headers .= "Reply-To: {$this->from_email}" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // Try to send via mail()
        $sent = @mail($to_email, $subject, $html_message, $headers);
        
        // If mail() fails or is not available, save to file for debugging
        if (!$sent) {
            $email_file = __DIR__ . '/../logs/emails_sent.html';
            $email_content = "
            <div style='border: 2px solid #f59e0b; margin: 20px 0; padding: 20px; background: #fef3c7;'>
                <h3>âš ï¸ Email NOT SENT - Saved at " . date('Y-m-d H:i:s') . "</h3>
                <p><strong>To:</strong> {$to_name} &lt;{$to_email}&gt;</p>
                <p><strong>Subject:</strong> {$subject}</p>
                <p style='color: #dc2626;'><strong>Status:</strong> Failed to send via mail() function</p>
                <hr>
                {$html_message}
            </div>
            ";
            
            @file_put_contents($email_file, $email_content, FILE_APPEND);
        }
        
        return $sent;
    }
    
    /**
     * Send via PHPMailer (if available)
     */
    private function sendViaPHPMailer($to_email, $to_name, $subject, $html_message) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->config['smtp']['host'];
            $mail->SMTPAuth = $this->config['smtp']['auth'];
            $mail->Username = $this->config['smtp']['username'];
            $mail->Password = $this->config['smtp']['password'];
            $mail->SMTPSecure = $this->config['smtp']['encryption'];
            $mail->Port = $this->config['smtp']['port'];
            
            // Recipients
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to_email, $to_name);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_message;
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get email configuration for each status
     */
    private function getStatusEmailConfig($status) {
        $configs = [
            'pending' => [
                'label' => 'Order Received',
                'icon' => 'â³',
                'color' => '#F59E0B',
                'subject' => 'Order Received',
                'message' => 'We have received your order and it is being reviewed. You will receive another update once your order is confirmed.'
            ],
            'confirmed' => [
                'label' => 'Order Confirmed',
                'icon' => 'âœ“',
                'color' => '#3B82F6',
                'subject' => 'Order Confirmed',
                'message' => 'Great news! Your payment has been confirmed and your order is now being prepared for shipment.'
            ],
            'processing' => [
                'label' => 'Order Processing',
                'icon' => 'âš™ï¸',
                'color' => '#6366F1',
                'subject' => 'Order Being Prepared',
                'message' => 'Your order is currently being prepared and will be shipped soon. We\'ll notify you once it\'s on its way!'
            ],
            'shipped' => [
                'label' => 'Order Shipped',
                'icon' => 'ðŸšš',
                'color' => '#8B5CF6',
                'subject' => 'Order Shipped',
                'message' => 'Exciting news! Your order has been shipped and is on its way to you. Use the tracking number below to monitor your delivery.'
            ],
            'delivered' => [
                'label' => 'Order Delivered',
                'icon' => 'âœ“âœ“',
                'color' => '#10B981',
                'subject' => 'Order Delivered',
                'message' => 'Your order has been successfully delivered! We hope you enjoy your books. Thank you for shopping with us!'
            ],
            'cancelled' => [
                'label' => 'Order Cancelled',
                'icon' => 'âœ—',
                'color' => '#EF4444',
                'subject' => 'Order Cancelled',
                'message' => 'Your order has been cancelled as requested. If you have any questions, please don\'t hesitate to contact us.'
            ],
            'refunded' => [
                'label' => 'Order Refunded',
                'icon' => 'â†¶',
                'color' => '#6B7280',
                'subject' => 'Refund Processed',
                'message' => 'Your refund has been processed and should appear in your account within 5-7 business days.'
            ]
        ];
        
        return $configs[$status] ?? $configs['pending'];
    }
    
    /**
     * Update notification status in database
     */
    private function updateNotificationStatus($order_id, $sent) {
        $status = $sent ? 'sent' : 'failed';
        $sent_at = $sent ? date('Y-m-d H:i:s') : null;
        
        $stmt = $this->conn->prepare("
            UPDATE order_notifications 
            SET status = ?, sent_at = ?
            WHERE order_id = ? 
            AND status = 'pending'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("ssi", $status, $sent_at, $order_id);
        $stmt->execute();
    }
    
    /**
     * Log email attempt for debugging
     */
    private function logEmailAttempt($order_id, $email, $subject, $success) {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $log_message = date('Y-m-d H:i:s') . " - Order #{$order_id} - Email to {$email} - Subject: {$subject} - Status: {$status}\n";
        error_log($log_message, 3, __DIR__ . '/../logs/email_notifications.log');
    }
}

