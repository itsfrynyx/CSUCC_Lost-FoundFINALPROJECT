<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

const PASSWORD_RESET_OTP_TTL_SECONDS = 180;

function configure_otp_mailer(PHPMailer $mail): void
{
    $config = require __DIR__ . '/../config/email_config.php';
    $smtp = $config['smtp'];

    if ($smtp['username'] === '' || $smtp['password'] === '') {
        throw new Exception('Password reset SMTP credentials are missing.');
    }

    $mail->isSMTP();
    $mail->Host = $smtp['host'];
    $mail->Port = $smtp['port'];
    $mail->SMTPAuth = $smtp['auth'];
    $mail->SMTPSecure = $smtp['encryption'];
    $mail->Username = $smtp['username'];
    $mail->Password = $smtp['password'];
    $mail->setFrom($smtp['username'], 'Campus Lost & Found');
}
