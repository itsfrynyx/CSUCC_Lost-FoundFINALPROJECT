<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/otp_mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit();
}

$idnumber = strtoupper(account_input('idnumber'));
$idnumber = preg_replace('/^(\d{4})(\d{4})$/D', '$1-$2', $idnumber);

if (!preg_match('/^(?:EMP-|[0-9]{4}-)[0-9]{4}$/D', $idnumber)) {
    $response['message'] = 'Enter a valid ID number (for example, EMP-0001 or 2026-0001).';
    echo json_encode($response);
    exit();
}

// Find email by ID number and check approval status
$stmt = $conn->prepare("SELECT email, approved, role FROM infos WHERE idnumber = ? LIMIT 1");
$stmt->bind_param("s", $idnumber);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $response['message'] = 'ID number not found!';
    echo json_encode($response);
    exit();
}

$row = $result->fetch_assoc();

// Check if account is pending approval (only for customers)
if ($row['role'] === 'customer' && isset($row['approved']) && $row['approved'] == 0) {
    $response['message'] = 'Your account is pending approval. Please wait for administrator approval before resetting your password.';
    echo json_encode($response);
    exit();
}

$email = $row['email'];

// Generate OTP without the number 8
do {
    $otp = random_int(100000, 999999);
} while (strpos((string)$otp, '8') !== false);

// Send email via PHPMailer
$mail = new PHPMailer(true);

try {
    configure_otp_mailer($mail);


    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Password Reset OTP - Campus Lost & Found';
    
    $mail->addCustomHeader('X-Mailer', 'PHPMailer');
    $mail->addCustomHeader('X-Priority', '3');



    $htmlBody = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Password Reset OTP</title></head><body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;"><div style="max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px;"><h2 style="color: #882b2a;">Password Reset OTP</h2><p>Your OTP is: <strong style="font-size: 24px; color: #2a8840;">'.$otp.'</strong></p><p>This OTP expires in 3 minutes.</p><p style="color: #666; font-size: 14px;">If you did not request this, please ignore this email.</p></div></body></html>';

    $mail->Body = $htmlBody;
    
    // Send the email
    $mail->send();
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_idnumber'] = $idnumber;
    $_SESSION['reset_otp'] = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['otp_expire'] = time() + PASSWORD_RESET_OTP_TTL_SECONDS;
    $_SESSION['last_otp_sent_time'] = time();
    $_SESSION['otp_resend_count'] = 0;
    $_SESSION['otp_resend_window_start'] = time();
    unset($_SESSION['security_verified']);
    
    $_SESSION['sent_message'] = 'OTP sent to your registered email.';
    $_SESSION['otp_verified'] = false;
    
    $response['success'] = true;
    $response['message'] = 'OTP sent successfully to your registered email.';
    $response['redirect'] = 'verify.php';
    
} catch (Exception $e) {
    error_log("PHPMailer Exception: " . $e->getMessage());
    error_log("Mail ErrorInfo: " . $mail->ErrorInfo);
    
    $response['message'] = 'Failed to send verification code. Please try again or contact support.';
}

echo json_encode($response);
exit();

