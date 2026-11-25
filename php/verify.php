<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/otp_mailer.php';
require_once __DIR__ . '/includes/idnumber_mask_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ensure OTP session is valid, otherwise redirect back
if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_idnumber']) || !isset($_SESSION['otp_expire'])) {
    header('Location: forgotpass.php');
    exit();
}

$error = "";
$success = "";

if (isset($_SESSION['resend_success'])) {
    $success = $_SESSION['resend_success'];
    unset($_SESSION['resend_success']);
}
if (isset($_SESSION['sent_message'])) {
    $success = $_SESSION['sent_message'];
    unset($_SESSION['sent_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['resend'])) {
        if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_idnumber'])) {
            $error = 'No pending password reset found. Start the process again.';
        } else {
            $now = time();
            if (!isset($_SESSION['otp_resend_window_start']) || ($now - $_SESSION['otp_resend_window_start']) > 3600) {
                $_SESSION['otp_resend_window_start'] = $now;
                $_SESSION['otp_resend_count'] = 0;
            }
            $resendCount = $_SESSION['otp_resend_count'] ?? 0;
            $lastSent = $_SESSION['last_otp_sent_time'] ?? 0;

            $cooldownSecs = 30;
            $maxResends = 3;

            if ($now - $lastSent < $cooldownSecs) {
                $error = 'Please wait before requesting another OTP.';
            } elseif ($resendCount >= $maxResends) {
                $error = 'You have reached the maximum number of OTP resends. Try again later.';
            } else {
                // Generate OTP without the number 8
                do {
                    $otp = random_int(100000, 999999);
                } while (strpos((string)$otp, '8') !== false);
                
                $mail = new PHPMailer(true);
                try {
                    configure_otp_mailer($mail);
                    $mail->addAddress($_SESSION['reset_email']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset OTP (resend) - Campus Lost & Found';

                    $mail->Body = '<p>Your OTP is: <strong>' . $otp . '</strong></p><p>This OTP expires in 3 minutes.</p>';
                    $mail->send();
                    $_SESSION['reset_otp'] = password_hash($otp, PASSWORD_DEFAULT);
                    $sentAt = time();
                    $_SESSION['otp_expire'] = $sentAt + PASSWORD_RESET_OTP_TTL_SECONDS;
                    $_SESSION['last_otp_sent_time'] = $sentAt;
                    $_SESSION['otp_resend_count'] = $resendCount + 1;
                    $_SESSION['resend_success'] = 'OTP resent. Check your registered email.';
                    header('Location: verify.php?resend=1');
                    exit;
                } catch (Exception $e) {
                    error_log('Password reset OTP resend failed: ' . $e->getMessage());
                    $error = 'Failed to resend the verification code. Please try again.';
                }
            }
        }
    }

    if (isset($_POST['otp1'])) {
        $userOtp = '';
        for ($i = 1; $i <= 6; $i++) {
            $userOtp .= $_POST['otp' . $i] ?? '';
        }

        if (time() >= (int) $_SESSION['otp_expire'] || !isset($_SESSION['reset_otp'])) {
            $error = 'Code expired. Request a new code to continue.';
            unset($_SESSION['reset_otp']);
        } elseif (!password_verify($userOtp, $_SESSION['reset_otp'])) {
            $error = "Invalid OTP!";
        } else {
            $_SESSION['otp_verified'] = true;
            unset($_SESSION['reset_otp'], $_SESSION['otp_expire']);
            header("Location: verify_security.php");
            exit;
        }
    }
}

$renderedAt = time();
$remainingSeconds = max(0, (int) $_SESSION['otp_expire'] - $renderedAt);
if ($remainingSeconds === 0) {
    unset($_SESSION['reset_otp']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Verify OTP - Caraga State University Lost & Found</title>
  <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Lottie Web Component -->
  <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@latest/dist/dotlottie-wc.js" type="module"></script>
  
  <style>
    html {
      min-height: 100%;
      overflow-y: auto;
    }
    
    body {
      font-family: 'Inter', sans-serif;
      background: url('../PICTURES/Landing Page.jpg') center center/cover no-repeat fixed;
      position: relative;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
    }
    
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.85);
      z-index: -1;
    }
    
    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      opacity: 0;
      transition: opacity 0.2s ease-out;
    }
    
    .modal-overlay.show {
      opacity: 1;
    }
    
    .modal-content {
      background: white;
      border-radius: 12px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
      max-width: 400px;
      width: 100%;
      transform: scale(0.95);
      transition: transform 0.2s ease-out;
    }
    
    .modal-overlay.show .modal-content {
      transform: scale(1);
    }
    
    
    /* Mobile-specific optimizations */
    @media (max-width: 640px) {
      html, body {
        min-height: 100vh;
        min-height: -webkit-fill-available;
        overflow-y: auto;
      }
      
      body {
        display: flex;
        flex-direction: column;
      }
      
      /* Main content takes remaining space */
      .main-content-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem 0.75rem;
      }
      
      /* Let the page scroll naturally when the form is taller than the viewport. */
      .form-container {
        padding: 1.5rem !important;
      }
      
      /* Ensure touch targets are at least 44px */
      button, a {
        min-height: 44px;
      }
      
      /* Better OTP box sizing */
      .otp-box {
        width: 40px !important;
        height: 40px !important;
        font-size: 18px !important;
      }
      
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
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-fadeInUp {
      animation: fadeInUp 0.6s ease-out;
    }
    
    .otp-box {
      width: 50px;
      height: 50px;
      font-size: 24px;
      text-align: center;
      border-radius: 12px;
      border: 2px solid #e5e7eb;
      background-color: #f9fafb;
      outline: none;
      transition: all 0.2s;
      font-weight: 600;
    }
    
    .otp-box:focus {
      border-color: #882b2a;
      box-shadow: 0 0 0 3px rgba(136, 43, 42, 0.1);
      background-color: white;
    }
  </style>
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#882b2a',
            'primary-dark': '#6b2221',
          }
        }
      }
    }
  </script>
</head>
<body>

<!-- Book Loading Screen -->
<!-- Enhanced Navbar -->
<nav class="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-200/50">
  <div class="px-3 sm:px-6 lg:px-12">
    <div class="flex justify-between items-center h-14 sm:h-16">
      <a href="home_.php" class="flex items-center space-x-2 sm:space-x-3 group relative">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-8 w-8 sm:h-10 sm:w-10 object-contain group-hover:scale-105 transition-all duration-300">
        <div>
          <h1 class="text-base sm:text-xl font-bold text-gray-900 group-hover:text-primary transition-colors duration-300">Caraga State University</h1>
          <p class="text-xs text-gray-500 font-medium hidden sm:block">Lost & Found Management System</p>
        </div>
      </a>
      
      <div class="flex items-center space-x-2">
        <a href="home_.php" class="px-4 py-2 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-lg transition-all">
          <span class="hidden sm:inline">Home</span>
          <svg class="w-5 h-5 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
        </a>
        <a href="Sign_in.php" class="px-4 py-2 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-lg transition-all">
          <span class="hidden sm:inline">Sign In</span>
          <svg class="w-5 h-5 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
          </svg>
        </a>
      </div>
    </div>
  </div>
</nav>

<!-- Main Content -->
<div class="main-content-wrapper flex-1 flex items-center justify-center px-3 sm:px-4 py-6 sm:py-8">
  <div class="max-w-6xl w-full mx-auto my-auto">
    <div class="grid md:grid-cols-2 gap-6 sm:gap-8 items-center">
      
      <!-- Left Side - Info -->
      <div class="hidden md:block animate-fadeInUp">
        <div class="inline-block mb-4">
          <span class="bg-primary/10 text-primary px-3 py-1.5 rounded-full text-xs font-semibold">Step 2 of 4</span>
        </div>
        <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
          Verify Your <span class="text-primary">Identity</span>
        </h1>
        <p class="text-gray-600 mb-6">
          <?php
            $displayEmail = isset($_SESSION['reset_email']) ? $_SESSION['reset_email'] : null;
            if ($displayEmail) {
                $parts = explode('@', $displayEmail);
                $local = $parts[0];
                $domain = $parts[1] ?? '';
                $maskedLocal = strlen($local) > 1 ? substr($local,0,1) . str_repeat('*', max(1, min(3, strlen($local)-1))) : '*';
                $maskedEmail = $maskedLocal . '@' . $domain;
                echo 'We\'ve sent a 6-digit verification code to <strong class="text-primary">' . htmlspecialchars($maskedEmail) . '</strong>. Please enter it below.';
            } else {
                echo 'We\'ve sent a 6-digit verification code to your registered email. Please enter it below.';
            }
          ?>
        </p>
        
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-lg">
          <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
              <p class="text-sm text-blue-700 font-medium">Didn't receive the code?</p>
              <p class="text-xs text-blue-600 mt-1">Check your spam folder or request a new code below.</p>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Right Side - Form -->
      <div class="animate-fadeInUp w-full">
        <div class="form-container bg-white rounded-2xl shadow-xl p-6 sm:p-8 border border-gray-100">
          <?php
          if (isset($_SESSION['reset_idnumber'])) {
            require '../DB/conn.php';
            $idnumber = $_SESSION['reset_idnumber'];
            $sql_user = "SELECT username, idnumber FROM `infos` WHERE `idnumber` = ? LIMIT 1";
            $stmt_user = $conn->prepare($sql_user);
            $stmt_user->bind_param("s", $idnumber);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            $user_info = $result_user->fetch_assoc();
          }
          ?>
          
          <div class="text-center mb-6">
            <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-14 w-14 mx-auto mb-3">
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Enter OTP</h2>
            <p class="text-gray-600">Verification code sent to your email</p>
            
            <?php if (isset($user_info) && $user_info): ?>
              <div class="mt-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                <p class="text-sm text-gray-600">
                  <svg class="w-4 h-4 inline mr-1 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                  <strong class="text-primary"><?= htmlspecialchars($user_info['username']) ?></strong>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                  Account ID: <?= htmlspecialchars(mask_idnumber($user_info['idnumber'])) ?>
                </p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Inline Error/Success Message -->
          <?php if (!empty($error)): ?>
            <div id="messageBox" class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border border-red-300 bg-red-50 animate-fadeInUp">
              <div class="flex items-start">
                <div class="flex-shrink-0 mr-3">
                  <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <p class="text-sm text-red-700 font-medium"><?= htmlspecialchars($error) ?></p>
                </div>
              </div>
            </div>
          <?php endif; ?>
          
          <?php if (!empty($success)): ?>
            <div id="messageBox" class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border border-green-300 bg-green-50 animate-fadeInUp">
              <div class="flex items-start">
                <div class="flex-shrink-0 mr-3">
                  <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <p class="text-sm text-green-700 font-medium"><?= htmlspecialchars($success) ?></p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <form action="" method="POST">
            <!-- OTP Input -->
            <div class="mb-6">
              <label class="block text-sm font-semibold text-gray-700 mb-3 text-center" id="otp-label">
                Enter 6-Digit Code
              </label>
              <div class="flex justify-center gap-2" role="group" aria-labelledby="otp-label">
                <input type="text" name="otp1" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp1'] ?? "") ?>" required aria-label="Digit 1" autocomplete="off">
                <input type="text" name="otp2" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp2'] ?? "") ?>" required aria-label="Digit 2" autocomplete="off">
                <input type="text" name="otp3" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp3'] ?? "") ?>" required aria-label="Digit 3" autocomplete="off">
                <input type="text" name="otp4" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp4'] ?? "") ?>" required aria-label="Digit 4" autocomplete="off">
                <input type="text" name="otp5" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp5'] ?? "") ?>" required aria-label="Digit 5" autocomplete="off">
                <input type="text" name="otp6" maxlength="1" class="otp-box" value="<?= htmlspecialchars($_POST['otp6'] ?? "") ?>" required aria-label="Digit 6" autocomplete="off">
              </div>
            </div>

            <!-- Timer and Resend -->
            <div class="text-center mb-6">
              <div class="text-sm text-gray-600 mb-2">
                <svg class="w-4 h-4 inline mr-1 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Code expires in: <span id="otp_timer" class="font-semibold text-primary"><?php printf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60); ?></span>
              </div>
              <p id="otp-expired" role="status" class="text-sm text-red-700 mb-2" <?= $remainingSeconds > 0 || $error !== '' ? 'hidden' : '' ?>>
                Code expired. Request a new code to continue.
              </p>
              <div class="text-xs text-gray-500 mb-3">
                Resends left: <span id="resends_left" class="font-semibold"><?php echo isset($_SESSION['otp_resend_count']) ? max(0, 3 - $_SESSION['otp_resend_count']) : 3; ?></span>
              </div>
            </div>

            <!-- Submit Button -->
            <button 
              type="submit" 
              name="verify" id="verify-otp" <?= $remainingSeconds === 0 ? 'disabled' : '' ?>
              class="w-full bg-primary hover:bg-primary-dark text-white font-semibold py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center space-x-2"
            >
              <span>Verify Code</span>
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </button>
          </form>

          <!-- Resend Form -->
          <form method="post" class="mt-4">
            <button 
              type="submit" 
              id="resendBtn" 
              name="resend"
              class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2.5 px-4 rounded-lg transition-all duration-300"
            >
              Resend Code
            </button>
          </form>

          <p class="text-center text-sm text-gray-600 mt-4">
            <a href="forgotpass.php" class="text-primary hover:text-primary-dark transition-colors">
              â† Back to ID entry
            </a>
          </p>
        </div>
      </div>
      
    </div>
  </div>
</div>

<!-- Footer -->
<footer class="bg-gradient-to-r from-gray-50 via-white to-gray-50 border-t border-gray-200 flex-shrink-0">
  <div class="max-w-7xl mx-auto px-4 py-3 sm:py-4">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
      <div class="flex items-center space-x-3">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-6 w-6 sm:h-8 sm:w-8">
        <div>
          <h3 class="text-xs sm:text-sm font-bold text-gray-900">Caraga State University</h3>
          <p class="text-xs text-gray-500 hidden sm:block">Lost & Found Management System</p>
        </div>
      </div>
      <p class="text-xs text-gray-500">© 2026 Caraga State University. All rights reserved.</p>
    </div>
  </div>
</footer>

<!-- Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="display: none;">
  <div class="modal-content">
    <div class="p-6">
      <div class="flex items-start mb-4">
        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center mr-3">
          <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <div class="flex-1">
          <h3 id="modalTitle" class="text-lg font-semibold text-gray-900 mb-1"></h3>
          <p id="modalMessage" class="text-sm text-gray-600"></p>
        </div>
      </div>
      <div class="flex gap-3 justify-end">
        <button 
          id="modalCancel" 
          class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors duration-200"
        >
          Cancel
        </button>
        <button 
          id="modalConfirm" 
          class="px-4 py-2 text-sm font-medium text-white bg-primary hover:bg-primary-dark rounded-lg transition-colors duration-200"
        >
          Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Modal Helper Functions
function showModal(title, message, onConfirm) {
  const modal = document.getElementById('confirmModal');
  const modalTitle = document.getElementById('modalTitle');
  const modalMessage = document.getElementById('modalMessage');
  const modalConfirm = document.getElementById('modalConfirm');
  const modalCancel = document.getElementById('modalCancel');
  
  modalTitle.textContent = title;
  modalMessage.textContent = message;
  
  modal.style.display = 'flex';
  setTimeout(() => modal.classList.add('show'), 10);
  
  const closeModal = () => {
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = 'none', 200);
  };
  
  modalConfirm.onclick = () => {
    closeModal();
    if (onConfirm) onConfirm();
  };
  
  modalCancel.onclick = closeModal;
  
  modal.onclick = (e) => {
    if (e.target === modal) closeModal();
  };
}

// Enhanced OTP Input with Auto-focus and Paste Support
document.addEventListener('DOMContentLoaded', function() {
  const otpInputs = document.querySelectorAll('.otp-box');
  
  // Auto-focus first input
  if (otpInputs.length > 0) {
    otpInputs[0].focus();
  }
  
  otpInputs.forEach((input, index) => {
    // Input event - move to next
    input.addEventListener('input', (e) => {
      const value = e.target.value;
      
      // Only allow numbers
      if (!/^\d*$/.test(value)) {
        e.target.value = '';
        return;
      }
      
      if (value.length === 1 && index < otpInputs.length - 1) {
        otpInputs[index + 1].focus();
      }
      
      // Auto-submit when all filled
      checkAutoSubmit();
    });
    
    // Keydown event - handle backspace
    input.addEventListener('keydown', (e) => {
      if (e.key === "Backspace" && !input.value && index > 0) {
        otpInputs[index - 1].focus();
        otpInputs[index - 1].value = '';
      }
      
      // Arrow key navigation
      if (e.key === "ArrowLeft" && index > 0) {
        otpInputs[index - 1].focus();
      }
      if (e.key === "ArrowRight" && index < otpInputs.length - 1) {
        otpInputs[index + 1].focus();
      }
    });
    
    // Paste support - distribute digits across inputs
    input.addEventListener('paste', (e) => {
      e.preventDefault();
      const pastedData = e.clipboardData.getData('text').replace(/\D/g, '');
      
      if (pastedData.length === 6) {
        otpInputs.forEach((inp, idx) => {
          inp.value = pastedData[idx] || '';
        });
        otpInputs[5].focus();
        checkAutoSubmit();
      }
    });
    
    // Select all on focus
    input.addEventListener('focus', function() {
      this.select();
    });
  });
  
  function checkAutoSubmit() {
    const allFilled = Array.from(otpInputs).every(inp => inp.value.length === 1);
    if (allFilled) {
      // Optional: Auto-submit after brief delay
      setTimeout(() => {
        const form = document.querySelector('form');
        if (form && !document.getElementById('verify-otp').disabled) {
          showModal(
            'Submit OTP Code?',
            'All digits have been entered. Would you like to verify your code now?',
            () => {
              if (!document.getElementById('verify-otp').disabled) form.requestSubmit();
            }
          );
        }
      }, 500);
    }
  }
});

// Enhanced Timer and Resend Logic
(function(){
  var remainingSeconds = <?= $remainingSeconds ?>;
  var resendCooldownSeconds = <?= max(0, min(30, 30 - ($renderedAt - (int) ($_SESSION['last_otp_sent_time'] ?? $renderedAt)))) ?>;
  var resendsDone = <?= (int) ($_SESSION['otp_resend_count'] ?? 0) ?>;
  var maxResends = 3;

  var otpTimerSpan = document.getElementById('otp_timer');
  var resendsLeftSpan = document.getElementById('resends_left');
  var resendBtn = document.getElementById('resendBtn');
  
  // Track elapsed time
  var startTime = Date.now();
  var initialRemaining = remainingSeconds;

  function updateOtpTimer(){
    // Calculate elapsed time since page load
    var elapsed = Math.floor((Date.now() - startTime) / 1000);
    var rem = initialRemaining - elapsed;
    
    if (!otpTimerSpan) return;
    
    if (rem <= 0) {
      otpTimerSpan.textContent = '00:00';
      otpTimerSpan.classList.add('text-red-600');
      otpTimerSpan.classList.remove('text-orange-600', 'text-primary');
      document.getElementById('verify-otp').disabled = true;
      document.getElementById('otp-expired').hidden = false;
    } else {
      var m = String(Math.floor(rem/60)).padStart(2,'0');
      var s = String(rem%60).padStart(2,'0');
      otpTimerSpan.textContent = m + ':' + s;
      
      // Warning when less than 1 minute
      if (rem <= 60) {
        otpTimerSpan.classList.add('text-orange-600', 'font-bold');
      }
    }
  }
  
  updateOtpTimer();
  setInterval(updateOtpTimer, 1000);

  function setResendState(){
    // Calculate elapsed time since page load
    var elapsed = Math.floor((Date.now() - startTime) / 1000);
    var cooldownRemaining = Math.max(0, resendCooldownSeconds - elapsed);
    var resendsLeft = Math.max(0, maxResends - resendsDone);
    
    if (resendsLeftSpan) resendsLeftSpan.textContent = resendsLeft;

    if (!resendBtn) return;
    
    if (resendsLeft <= 0) {
      resendBtn.disabled = true;
      resendBtn.textContent = 'No resends left';
      resendBtn.classList.add('opacity-50', 'cursor-not-allowed');
      return;
    }
    
    if (cooldownRemaining > 0) {
      resendBtn.disabled = true;
      resendBtn.classList.add('opacity-50', 'cursor-not-allowed');
      resendBtn.textContent = 'Resend available in ' + cooldownRemaining + 's';
      
      var interval = setInterval(function(){
        var elapsedNow = Math.floor((Date.now() - startTime) / 1000);
        cooldownRemaining = Math.max(0, resendCooldownSeconds - elapsedNow);
        
        if (cooldownRemaining <= 0) {
          clearInterval(interval);
          resendBtn.disabled = false;
          resendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
          resendBtn.textContent = 'Resend Code';
        } else {
          resendBtn.textContent = 'Resend available in ' + cooldownRemaining + 's';
        }
      }, 1000);
    } else {
      resendBtn.disabled = false;
      resendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
      resendBtn.textContent = 'Resend Code';
    }
  }
  
  setResendState();
})();

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    showModal(
      'Cancel Verification?',
      'Are you sure you want to cancel and return to ID entry? Your progress will be lost.',
      () => window.location.href = 'forgotpass.php'
    );
  }
});
</script>

</body>
</html>

