<?php
require_once __DIR__ . '/includes/account_input_validation.php';
ob_start(); // Start output buffering to prevent header issues
session_start();

require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/otp_mailer.php';
require_once __DIR__ . '/includes/idnumber_mask_helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = "";
$recovery_username = account_input('recovery_username');
$account_id_hint = '';
if ($recovery_username !== '') {
    $hint_stmt = $conn->prepare('SELECT idnumber FROM infos WHERE username = ? LIMIT 1');
    $hint_stmt->bind_param('s', $recovery_username);
    $hint_stmt->execute();
    $hint_account = $hint_stmt->get_result()->fetch_assoc();
    $hint_stmt->close();
    if ($hint_account) {
        $account_id_hint = str_replace('-', ' - ', mask_idnumber($hint_account['idnumber']));
    }
}
$recovery_id = strtoupper(account_input('idnumber'));
$masked_id_preview = '';
if (preg_match('/^(?:EMP-\d{4}|\d{4}-?\d{4})$/', $recovery_id)) {
    if (preg_match('/^\d{8}$/', $recovery_id)) {
        $recovery_id = substr($recovery_id, 0, 4) . '-' . substr($recovery_id, 4);
    }
    $masked_id_preview = str_replace('-', ' - ', mask_idnumber($recovery_id));
}
if ($account_id_hint !== '') {
    $masked_id_preview = $account_id_hint;
}

// Check if email was already sent successfully - redirect immediately
if (isset($_SESSION['email_sent_success']) && $_SESSION['email_sent_success'] === true) {
    unset($_SESSION['email_sent_success']);
    header("Location: verify.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $idnumber = strtoupper(account_input('idnumber'));
    $idnumber = preg_replace('/^(\d{4})(\d{4})$/D', '$1-$2', $idnumber);

    if (!preg_match('/^(?:EMP-|[0-9]{4}-)[0-9]{4}$/D', $idnumber)) {
        $error = "Enter a valid ID number (for example, EMP-0001 or 2026-0001).";
    } else {
        $stmt = $conn->prepare("SELECT idnumber, email, approved, role FROM infos WHERE idnumber = ? LIMIT 1");
        $stmt->bind_param("s", $idnumber);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "ID number not found!";
        } else {
            $row = $result->fetch_assoc();
            
            // Check if account is pending approval (only for customers)
            if ($row['role'] === 'customer' && isset($row['approved']) && $row['approved'] == 0) {
                $error = "Your account is pending approval. Please wait for administrator approval before resetting your password.";
            } else {
                $email = $row['email'];
                
                // Generate OTP without the number 8
                do {
                    $otp = random_int(100000, 999999);
                } while (strpos((string)$otp, '8') !== false);

            $mail = new PHPMailer(true);

            try {
                configure_otp_mailer($mail);


                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset OTP - Campus Lost & Found';
                
                $mail->addCustomHeader('X-Mailer', 'PHPMailer');
                $mail->addCustomHeader('X-Priority', '3');



                $htmlBody = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Password Reset OTP</title></head><body style="font-family: Arial, sans-serif; background: #eef7ed; padding: 20px;"><div style="max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px;"><h2 style="color: #08783d;">Password Reset OTP</h2><p>Your OTP is: <strong style="font-size: 24px; color: #08783d;">'.$otp.'</strong></p><p>This OTP expires in 3 minutes.</p><p style="color: #666; font-size: 14px;">If you did not request this, please ignore this email.</p></div></body></html>';

                $mail->Body = $htmlBody;
                
                // Send the email
                $mail->send();
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_idnumber'] = $idnumber;
                $_SESSION['reset_masked_idnumber'] = mask_idnumber($row['idnumber']);
                $_SESSION['reset_otp'] = password_hash($otp, PASSWORD_DEFAULT);
                $_SESSION['otp_expire']  = time() + PASSWORD_RESET_OTP_TTL_SECONDS;
                $_SESSION['last_otp_sent_time'] = time();
                $_SESSION['otp_resend_count'] = 0;
                $_SESSION['otp_resend_window_start'] = time();
                unset($_SESSION['security_verified']);
                
                // If we reach here, email was sent successfully
                $_SESSION['sent_message'] = 'OTP sent to your registered email.';
                $_SESSION['otp_verified'] = false;
                $_SESSION['email_sent_success'] = true; // Flag to prevent resubmission
                
                // Clear all output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                // Force immediate redirect
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <script>window.location.replace("verify.php");</script>
                    <meta http-equiv="refresh" content="0;url=verify.php">
                </head>
                <body>
                    <p>Redirecting to verification page...</p>
                    <script>
                        setTimeout(function() {
                            window.location.href = "verify.php";
                        }, 100);
                    </script>
                </body>
                </html>
                <?php
                exit();

            } catch (Exception $e) {
                error_log("PHPMailer Exception: " . $e->getMessage());
                error_log("Mail ErrorInfo: " . $mail->ErrorInfo);
                
                $error = "Failed to send verification code. Please try again or contact support.";
            }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php if (isset($_SESSION['sent_message'])): ?>
  <script>window.location.replace("verify.php");</script>
  <meta http-equiv="refresh" content="0;url=verify.php">
  <?php endif; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - Caraga State University Lost & Found</title>
  <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Lottie Web Component -->
  <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@latest/dist/dotlottie-wc.js" type="module"></script>
  
  <style>
    html {
      height: 100vh;
      overflow: hidden;
    }
    
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #eef7ed 0%, #f8f4df 100%);
      position: relative;
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.35);
      z-index: -1;
    }
    
    /* Mobile-specific optimizations */
    @media (max-width: 640px) {
      html, body {
        height: 100vh;
        height: -webkit-fill-available;
        overflow: hidden;
      }
      
      body {
        display: flex;
        flex-direction: column;
      }
      
      /* Main content takes remaining space */
      .main-content-wrapper {
        flex: 1;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      
      /* Make form scrollable within fixed container */
      .form-container {
        max-height: calc(100vh - 16rem);
        max-height: calc(-webkit-fill-available - 16rem);
        overflow-y: auto;
        padding: 1.5rem !important;
      }
      
      /* Ensure touch targets are at least 44px */
      button, a {
        min-height: 44px;
      }
      
      /* Better input sizing */
      input {
        font-size: 16px !important; /* Prevents zoom on iOS */
      }
      
      /* Compact footer on mobile */
      footer {
        flex-shrink: 0;
      }
    }
    
    /* Tablet optimizations */
    @media (min-width: 641px) and (max-width: 1024px) {
      .main-content-wrapper {
        padding: 2rem 1rem;
      }
    }
    
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateX(-30px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }
    
    .animate-fadeInUp {
      animation: fadeInUp 0.6s ease-out;
    }
    
    .animate-slideIn {
      animation: slideIn 0.6s ease-out;
    }
    
    .input-focus:focus {
      transform: translateY(-1px);
      transition: all 0.2s;
    }
    
    .btn-hover:hover {
      transform: translateY(-2px);
      transition: all 0.3s;
    }
    
    .btn-hover:active {
      transform: translateY(0);
    }
    
    @media (max-width: 640px) {
      .input-focus {
        font-size: 16px;
      }
    }
  </style>
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#08783d',
            'primary-dark': '#04552c',
            accent: '#d7b94b',
          }
        }
      }
    }
  </script>
</head>
<body class="overflow-hidden" style="height: 100vh;">



<!-- Enhanced Navbar -->
<nav class="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-200/50">
  <div class="px-3 sm:px-6 lg:px-12">
    <div class="flex justify-between items-center h-14 sm:h-16">
      <a href="home_.php" class="flex items-center space-x-2 sm:space-x-3 group relative">
        <div class="relative">
          <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="relative h-8 w-8 sm:h-10 sm:w-10 object-contain group-hover:scale-105 transition-all duration-300">
        </div>
        <div>
          <h1 class="text-base sm:text-xl font-bold text-gray-900 group-hover:text-primary transition-colors duration-300 tracking-tight">Caraga State University</h1>
          <p class="text-xs text-gray-500 font-medium hidden sm:block">Lost & Found Management System</p>
        </div>
      </a>
      
      <div class="flex items-center space-x-1 sm:space-x-2">
        <a href="home_.php" class="group flex items-center space-x-1 sm:space-x-2 px-2 sm:px-4 py-2 sm:py-2.5 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-lg sm:rounded-xl transition-all duration-300 relative overflow-hidden">
          <span class="absolute inset-0 bg-gradient-to-r from-primary/0 via-primary/5 to-primary/0 translate-x-[-100%] group-hover:translate-x-[100%] transition-transform duration-700"></span>
          <svg class="w-4 h-4 sm:w-5 sm:h-5 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          <span class="hidden sm:inline font-semibold text-sm relative z-10">Home</span>
        </a>
        <a href="Sign_in.php" class="group flex items-center space-x-1 sm:space-x-2 px-2 sm:px-5 py-2 sm:py-2.5 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-lg sm:rounded-xl transition-all duration-300 relative overflow-hidden">
          <span class="absolute inset-0 bg-gradient-to-r from-primary/0 via-primary/5 to-primary/0 translate-x-[-100%] group-hover:translate-x-[100%] transition-transform duration-700"></span>
          <svg class="w-4 h-4 sm:w-5 sm:h-5 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
          </svg>
          <span class="hidden sm:inline font-semibold text-sm relative z-10">Sign In</span>
        </a>
      </div>
    </div>
  </div>
</nav>

<!-- Main Content -->
<div class="main-content-wrapper flex-1 flex items-center justify-center px-3 sm:px-4 overflow-hidden">
  <div class="max-w-6xl w-full my-auto">
    <div class="grid md:grid-cols-2 gap-4 sm:gap-6 lg:gap-8 items-center">
      
      <!-- Left Side - Info Section -->
      <div class="hidden md:block animate-slideIn">
        <div class="space-y-3 sm:space-y-4">
          <div class="inline-block">
            <span class="bg-primary/10 text-primary px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-full text-xs font-semibold">Password Recovery</span>
          </div>
          
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 leading-tight">
            Forgot Your <span class="text-primary">Password?</span>
          </h1>
          
          <p class="text-sm sm:text-base text-gray-600 leading-relaxed">
            No worries! Enter your ID number and we'll send a verification code to your registered email address.
          </p>
          
          <!-- Features -->
          <div class="grid grid-cols-1 gap-2 sm:gap-3 pt-2">
            <div class="flex items-start space-x-2">
              <div class="flex-shrink-0 w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center">
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-semibold text-gray-900 text-xs sm:text-sm">Secure Process</h3>
                <p class="text-xs text-gray-500">Your data is protected</p>
              </div>
            </div>
            
            <div class="flex items-start space-x-2">
              <div class="flex-shrink-0 w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center">
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-semibold text-gray-900 text-xs sm:text-sm">Email Verification</h3>
                <p class="text-xs text-gray-500">OTP sent to your email</p>
              </div>
            </div>
            
            <div class="flex items-start space-x-2">
              <div class="flex-shrink-0 w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center">
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-semibold text-gray-900 text-xs sm:text-sm">Quick Recovery</h3>
                <p class="text-xs text-gray-500">Reset in minutes</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Right Side - Form -->
      <div class="animate-fadeInUp w-full">
        <div class="form-container bg-white rounded-xl sm:rounded-2xl shadow-xl p-4 sm:p-6 md:p-8 border border-gray-100">
          <!-- Header -->
          <div class="text-center mb-4 sm:mb-6">
            <div class="inline-flex items-center justify-center mb-2 sm:mb-3">
              <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-12 w-12 sm:h-14 sm:w-14 object-contain">
            </div>
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1 sm:mb-2">Forgot Password</h2>
            <p class="text-gray-600 text-sm sm:text-base">Enter your ID number to reset your password</p>
            <div id="maskedIdPreview" role="status" aria-live="polite" style="<?= $masked_id_preview === '' ? 'display: none;' : '' ?>" class="mt-3 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/5 px-3 py-1.5 text-sm text-gray-700">
              <span class="font-medium">Account ID:</span>
              <strong id="maskedIdValue" class="text-primary tracking-wide"><?= htmlspecialchars($masked_id_preview) ?></strong>
            </div>
          </div>

          <!-- Inline Error Message -->
          <?php if ($account_id_hint === ''): ?>
            <p class="mb-4 text-center text-sm text-gray-600">For an Account ID reminder, enter your username on the <a href="Sign_in.php" class="font-semibold text-primary">sign-in page</a>, then select Forgot Password.</p>
          <?php endif; ?>
          <?php if (!empty($error)): ?>
            <div id="messageBox" class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border border-red-300 bg-red-50 animate-fadeInUp">
              <div class="flex items-start">
                <div class="flex-shrink-0 mr-2 sm:mr-3">
                  <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <p class="text-xs sm:text-sm text-red-700 font-medium"><?= htmlspecialchars($error) ?></p>
                </div>
              </div>
            </div>
          <?php endif; ?>
          
          <?php if (!empty($success)): ?>
            <div id="messageBox" class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border border-green-300 bg-green-50 animate-fadeInUp">
              <div class="flex items-start">
                <div class="flex-shrink-0 mr-2 sm:mr-3">
                  <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <p class="text-xs sm:text-sm text-green-700 font-medium"><?= htmlspecialchars($success) ?></p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Form -->
          <form action="forgotpass.php" method="POST" class="space-y-4 sm:space-y-5" id="forgotPasswordForm">
            <input type="hidden" name="recovery_username" value="<?= htmlspecialchars($recovery_username, ENT_QUOTES, 'UTF-8') ?>">
            
            <!-- ID Number Field -->
            <div>
              <label for="idnumber" class="block text-sm font-semibold text-gray-700 mb-1.5 sm:mb-2">
                ID Number <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                  <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                  </svg>
                </div>
                <input 
                  type="text" 
                  id="idnumber" 
                  name="idnumber" 
                  maxlength="9"
                  placeholder="EMP-0001 / 2026-0001"
                  value="<?= htmlspecialchars($_POST['idnumber'] ?? "") ?>"
                  class="input-focus block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 text-sm sm:text-base border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all duration-200 bg-gray-50 focus:bg-white"
                  required
                  autocomplete="off"
                  aria-label="ID Number"
                  aria-describedby="idnumber-help"
                />
              </div>
              <p class="mt-1.5 text-xs text-gray-500" id="idnumber-help">
                <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Enter the ID number associated with your account
              </p>
            </div>

            <!-- Submit Button -->
            <button 
              type="submit" 
              name="submit"
              id="submitBtn"
              onclick="showLoading(event)"
              class="btn-hover w-full bg-primary hover:bg-primary-dark text-white font-semibold py-2.5 sm:py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center space-x-2 text-sm sm:text-base"
            >
              <span id="btnText">Send Verification Code</span>
              <svg class="w-4 h-4 sm:w-5 sm:h-5" id="btnIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
              </svg>
            </button>

            <!-- Back to Sign In Link -->
            <p class="text-center text-xs sm:text-sm text-gray-600 mt-4 sm:mt-5">
              Remember your password? 
              <a href="Sign_in.php" class="font-semibold text-primary hover:text-primary-dark transition-colors duration-200">
                Sign in
              </a>
            </p>
          </form>
        </div>
      </div>
      
    </div>
  </div>
</div>

<!-- Enhanced Footer -->
<footer class="bg-gradient-to-r from-gray-50 via-white to-gray-50 border-t border-gray-200 flex-shrink-0">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 sm:py-4">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-2 sm:gap-4">
      <div class="flex items-center space-x-3">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-6 w-6 sm:h-8 sm:w-8 object-contain">
        <div class="text-left">
          <h3 class="text-xs sm:text-sm font-bold text-gray-900">Caraga State University</h3>
          <p class="text-xs text-gray-500 hidden sm:block">Lost & Found Management System</p>
        </div>
      </div>
      <p class="text-xs text-gray-500 text-center sm:text-right">
        © 2026 Caraga State University. All rights reserved.
      </p>
    </div>
  </div>
</footer>

<!-- Enhanced Scripts -->
<script>
// ID Number formatting (auto-add hyphen)
document.addEventListener('DOMContentLoaded', function() {
  const idInput = document.getElementById('idnumber');
  
  if (idInput) {
    const maskedIdPreview = document.getElementById('maskedIdPreview');
    const maskedIdValue = document.getElementById('maskedIdValue');
    const accountIdHint = <?= json_encode($account_id_hint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const updateMaskedIdPreview = function() {
      if (accountIdHint && maskedIdPreview && maskedIdValue) {
        maskedIdPreview.style.display = '';
        maskedIdValue.textContent = accountIdHint;
        return;
      }
      const digits = idInput.value.replace(/[^0-9]/g, '');

      if (!maskedIdPreview || !maskedIdValue) {
        return;
      }

      const value = idInput.value.trim().toUpperCase();
      const completeId = /^(?:EMP-\d{4}|\d{4}-?\d{4})$/.test(value);
      maskedIdPreview.style.display = completeId ? '' : 'none';
      if (!completeId) {
        maskedIdValue.textContent = '';
        return;
      }

      let digitIndex = 0;
      const formattedValue = /^\d{8}$/.test(value) ? value.slice(0, 4) + '-' + value.slice(4) : value;
      maskedIdValue.textContent = formattedValue.replace(/\d/g, digit => {
        const visible = digitIndex < 2 || digitIndex >= digits.length - 2;
        digitIndex++;
        return visible ? digit : '*';
      }).replace(/-/g, ' - ');
    };

    idInput.addEventListener('input', function(e) {
      let value = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
      if (/^[A-Z]/.test(value)) {
        e.target.value = value.slice(0, 8);
        updateMaskedIdPreview();
        return;
      }
      value = value.replace(/[^0-9]/g, '');
      
      if (value.length > 4) {
        value = value.slice(0, 4) + '-' + value.slice(4, 8);
      }
      
      e.target.value = value;
      updateMaskedIdPreview();
    });

    idInput.addEventListener('change', updateMaskedIdPreview);
    window.addEventListener('pageshow', updateMaskedIdPreview);
    updateMaskedIdPreview();
    
    // Focus on load
    idInput.focus();
  }
});

// Form submission with AJAX
function showLoading(event) {
  event.preventDefault();
  
  const idnumber = document.getElementById('idnumber');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = document.getElementById('btnText');
  const btnIcon = document.getElementById('btnIcon');
  
  // Enhanced validation
  if (!idnumber || !idnumber.value.trim()) {
    showNotification('Please enter your ID number', 'error');
    idnumber.focus();
    return false;
  }
  
  const idValue = idnumber.value.trim();
  if (!/^(?:EMP-\d{4}|\d{4}-?\d{4})$/.test(idValue)) {
    showNotification('Please enter a valid ID number (EMP-0001 or 2026-0001)', 'error');
    idnumber.focus();
    return false;
  }
  
  // Show loading state
  if (submitBtn && btnText && btnIcon) {
    btnText.textContent = 'Sending...';
    btnIcon.innerHTML = '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
  }
  
  // Send AJAX request
  const formData = new FormData();
  formData.append('idnumber', idnumber.value);
  
  fetch('send_otp.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Success - redirect to verify page
      showNotification(data.message, 'success');
      setTimeout(() => {
        window.location.href = data.redirect;
      }, 500);
    } else {
      // Error - show message and reset button
      showNotification(data.message, 'error');
      
      // Reset button state
      if (submitBtn && btnText && btnIcon) {
        btnText.textContent = 'Send Verification Code';
        btnIcon.innerHTML = '<svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>';
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
      }
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showNotification('An error occurred. Please try again.', 'error');
    
    // Reset button state
    if (submitBtn && btnText && btnIcon) {
      btnText.textContent = 'Send Verification Code';
      btnIcon.innerHTML = '<svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>';
      submitBtn.disabled = false;
      submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
    }
  });
  
  return false;
}

// Enhanced Inline Error Message helper
function showNotification(message, type = 'info') {
  const messageBox = document.getElementById('messageBox');
  
  if (!messageBox) {
    // Create message box if it doesn't exist
    const form = document.getElementById('forgotPasswordForm');
    if (!form) return;
    
    const newMessageBox = document.createElement('div');
    newMessageBox.id = 'messageBox';
    newMessageBox.className = 'mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border animate-fadeInUp';
    form.insertBefore(newMessageBox, form.firstChild);
  }
  
  const msgBox = document.getElementById('messageBox');
  if (!msgBox) return;

  // Reset classes
  msgBox.className = 'mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border animate-fadeInUp';

  // Set type styles
  let iconSvg = '';
  let textClass = '';
  
  if (type === 'error') {
    msgBox.classList.add('border-red-300', 'bg-red-50');
    textClass = 'text-red-700';
    iconSvg = '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  } else if (type === 'success') {
    msgBox.classList.add('border-green-300', 'bg-green-50');
    textClass = 'text-green-700';
    iconSvg = '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  } else {
    msgBox.classList.add('border-blue-300', 'bg-blue-50');
    textClass = 'text-blue-700';
    iconSvg = '<svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  }

  msgBox.innerHTML = `
    <div class="flex items-start">
      <div class="flex-shrink-0 mr-2 sm:mr-3">
        ${iconSvg}
      </div>
      <div class="flex-1">
        <p class="text-xs sm:text-sm ${textClass} font-medium">${message}</p>
      </div>
    </div>
  `;
  
  // Scroll to message if not in view
  msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    window.location.href = 'Sign_in.php';
  }
});
</script>

</body>
</html>

