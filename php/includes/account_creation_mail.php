<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_temporary_account_password(string $email, string $username, string $temporaryPassword): void
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/otp_mailer.php';

    $mail = new PHPMailer(true);
    try {
        configure_otp_mailer($mail);
        $mail->Timeout = 10;
        $mail->addAddress($email);
        $mail->Subject = 'Your CSUCC-FIND account';
        $mail->Body = "Your CSUCC-FIND account is ready.\n\n"
            . "Username: $username\nTemporary password: $temporaryPassword\n\n"
            . "Sign in to CSUCC-FIND and complete Account Setup to replace this password and set your recovery questions.";
        $mail->send();
    } catch (Exception $error) {
        error_log('Account creation email failed: ' . $error->getMessage());
        throw new RuntimeException('The temporary password email could not be sent. The account was not created. Please try again.', 0, $error);
    }
}
