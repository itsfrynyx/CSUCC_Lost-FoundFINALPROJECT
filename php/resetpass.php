<?php
session_start();
require '../DB/conn.php';

// Require that the user passed security verification (both OTP and Q&A)
if (!isset($_SESSION['reset_email']) || empty($_SESSION['security_verified'])) {
    header("Location: forgotpass.php");
    exit();
}

// Fetch user info to display username and ID
$user_info = null;
if (isset($_SESSION['reset_email'])) {
  $email = $_SESSION['reset_email'];
  $sql_user = "SELECT username, idnumber FROM `infos` WHERE `email` = ? LIMIT 1";
  $stmt_user = $conn->prepare($sql_user);
  $stmt_user->bind_param("s", $email);
  $stmt_user->execute();
  $result_user = $stmt_user->get_result();
  $user_info = $result_user->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - Caraga State University Lost & Found</title>
  <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Google Fonts -->
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
      background: url('../PICTURES/Landing Page.jpg') center center/cover no-repeat fixed;
      position: relative;
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    
    /* Overlay for better readability */
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
        padding: 1rem 0.75rem;
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
      
      /* Password strength bar */
      .strength-bar {
        height: 3px;
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
    
    /* Password strength indicator */
    .strength-bar {
      height: 4px;
      border-radius: 2px;
      transition: all 0.3s;
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



<!-- Enhanced Navbar -->
<nav class="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-200/50">
  <div class="px-3 sm:px-6 lg:px-12">
    <div class="flex justify-between items-center h-14 sm:h-16">
      <!-- Logo -->
      <a href="home_.php" class="flex items-center space-x-2 sm:space-x-3 group relative">
        <div class="relative">
          <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="relative h-8 w-8 sm:h-10 sm:w-10 object-contain group-hover:scale-105 transition-all duration-300">
        </div>
        <div>
          <h1 class="text-base sm:text-xl font-bold text-gray-900 group-hover:text-primary transition-colors duration-300 tracking-tight">Caraga State University</h1>
          <p class="text-xs text-gray-500 font-medium hidden sm:block">Lost & Found Management System</p>
        </div>
      </a>
      
      <!-- Nav Links -->
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
            <span class="bg-primary/10 text-primary px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-full text-xs font-semibold">Almost Done!</span>
          </div>
          
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 leading-tight">
            Create Your <span class="text-primary">New Password</span>
          </h1>
          
          <p class="text-sm sm:text-base text-gray-600 leading-relaxed">
            Enter your new password below and confirm it by re-entering. Make sure it is strong and secure to protect your account.
          </p>
          
          <!-- Password Tips -->
          <div class="bg-white/80 backdrop-blur-sm rounded-xl p-4 border border-gray-200">
            <h3 class="font-semibold text-gray-900 text-sm mb-3 flex items-center">
              <svg class="w-5 h-5 text-primary mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Password Requirements:
            </h3>
            <ul class="space-y-2 text-xs text-gray-600">
              <li class="flex items-start">
                <svg class="w-4 h-4 text-green-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                At least 8 characters long
              </li>
              <li class="flex items-start">
                <svg class="w-4 h-4 text-green-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Mix of uppercase and lowercase letters
              </li>
              <li class="flex items-start">
                <svg class="w-4 h-4 text-green-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Include numbers and special characters
              </li>
              <li class="flex items-start">
                <svg class="w-4 h-4 text-green-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Avoid common words or patterns
              </li>
            </ul>
          </div>
        </div>
      </div>
      
      <!-- Right Side - Reset Password Form -->
      <div class="animate-fadeInUp w-full">
        <div class="form-container bg-white rounded-xl sm:rounded-2xl shadow-xl p-4 sm:p-6 md:p-8 border border-gray-100">
          <!-- Header -->
          <div class="text-center mb-4 sm:mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 sm:w-14 sm:h-14 bg-primary/10 rounded-full mb-2 sm:mb-3">
              <svg class="w-6 h-6 sm:w-7 sm:h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
              </svg>
            </div>
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1 sm:mb-2">Reset Password</h2>
            <p class="text-gray-600 text-sm sm:text-base">Create a strong and secure password</p>
            
            <?php if ($user_info): ?>
              <div class="mt-3 bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs sm:text-sm text-gray-700">
                  <svg class="w-4 h-4 inline-block mr-1 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                  <strong><?= htmlspecialchars($user_info['username']) ?></strong>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                  <svg class="w-4 h-4 inline-block mr-1 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                  </svg>
                  ID: <?= htmlspecialchars($user_info['idnumber']) ?>
                </p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Inline Error Message -->
          <div id="errorMessage" class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border border-red-300 bg-red-50 animate-fadeInUp hidden">
            <div class="flex items-start">
              <div class="flex-shrink-0 mr-2 sm:mr-3">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
              <div class="flex-1">
                <p id="errorText" class="text-xs sm:text-sm text-red-700 font-medium"></p>
              </div>
            </div>
          </div>

          <!-- Reset Password Form -->
          <form action="" method="POST" id="resetForm" class="space-y-4 sm:space-y-5">
            
            <!-- New Password Field -->
            <div>
              <label for="password" class="block text-sm font-semibold text-gray-700 mb-1.5 sm:mb-2">
                New Password <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                  <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                </div>
                <input 
                  type="password" 
                  id="password" 
                  name="password" 
                  maxlength="15"
                  required
                  class="input-focus block w-full pl-9 sm:pl-11 pr-10 sm:pr-12 py-2.5 sm:py-3 text-sm sm:text-base border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all duration-200 bg-gray-50 focus:bg-white"
                  placeholder="Enter new password"
                />
                <button 
                  type="button" 
                  onclick="togglePassword('password', 'toggleIcon1')" 
                  class="absolute inset-y-0 right-0 pr-3 sm:pr-4 flex items-center text-gray-400 hover:text-primary transition-colors duration-200"
                >
                  <svg class="w-5 h-5" id="toggleIcon1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                </button>
              </div>
              
              <!-- Password Strength Indicator -->
              <div class="mt-2">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-xs text-gray-600">Password Strength:</span>
                  <span id="strengthText" class="text-xs font-semibold text-gray-500">-</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-1.5">
                  <div id="strengthBar" class="strength-bar bg-gray-300 rounded-full" style="width: 0%"></div>
                </div>
              </div>
            </div>

            <!-- Confirm Password Field -->
            <div>
              <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-1.5 sm:mb-2">
                Confirm Password <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                  <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                </div>
                <input 
                  type="password" 
                  id="confirm_password" 
                  name="confirm_password" 
                  maxlength="15"
                  required
                  class="input-focus block w-full pl-9 sm:pl-11 pr-10 sm:pr-12 py-2.5 sm:py-3 text-sm sm:text-base border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all duration-200 bg-gray-50 focus:bg-white"
                  placeholder="Re-enter new password"
                />
                <button 
                  type="button" 
                  onclick="togglePassword('confirm_password', 'toggleIcon2')" 
                  class="absolute inset-y-0 right-0 pr-3 sm:pr-4 flex items-center text-gray-400 hover:text-primary transition-colors duration-200"
                >
                  <svg class="w-5 h-5" id="toggleIcon2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                </button>
              </div>
              
              <!-- Match Indicator -->
              <div id="matchIndicator" class="mt-2 text-xs hidden">
                <span class="flex items-center">
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                  </svg>
                  <span id="matchText"></span>
                </span>
              </div>
            </div>

            <!-- Submit Button -->
            <button 
              type="submit" 
              id="submitBtn"
              class="btn-hover w-full bg-primary hover:bg-primary-dark text-white font-semibold py-2.5 sm:py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center space-x-2 text-sm sm:text-base"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span>Reset Password</span>
              <span id="loadingSpinner" class="hidden">
                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
            </button>

            <!-- Security Notice -->
            <div class="bg-blue-50 border-l-4 border-blue-400 p-3 rounded-r-lg">
              <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-xs sm:text-sm text-blue-700">
                  <strong>Security Tip:</strong> After resetting your password, you'll be redirected to the sign-in page. Use your new password to log in.
                </p>
              </div>
            </div>
          </form>
        </div>
      </div>
      
    </div>
  </div>
</div>

<!-- Enhanced Footer -->
<footer class="bg-gradient-to-r from-gray-50 via-white to-gray-50 border-t border-gray-200 mt-auto">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-6">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
      
      <!-- Left: Brand -->
      <div class="flex items-center space-x-3">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-8 w-8 object-contain">
        <div class="text-left">
          <h3 class="text-sm font-bold text-gray-900">Caraga State University</h3>
          <p class="text-xs text-gray-500">Lost & Found Management System</p>
        </div>
      </div>
      
      <!-- Center: Quick Links -->
      <div class="flex flex-wrap items-center justify-center gap-3 sm:gap-4">
        <a href="home_.php" class="text-xs text-gray-600 hover:text-primary transition-colors duration-200">Home</a>
        <a href="browse_books.php" class="text-xs text-gray-600 hover:text-primary transition-colors duration-200">Browse Books</a>
        <a href="#" class="text-xs text-gray-600 hover:text-primary transition-colors duration-200">Contact</a>
      </div>
      
      <!-- Right: Copyright -->
      <div class="flex flex-col items-center sm:items-end gap-2">
        <p class="text-xs text-gray-500 text-center sm:text-right">
          © <?php echo date("Y"); ?> Caraga State University. All rights reserved.
        </p>
        <div class="flex items-center gap-2 text-xs text-gray-600">
          <a href="#" class="hover:text-primary transition-colors">Privacy</a>
          <span>â€¢</span>
          <a href="#" class="hover:text-primary transition-colors">Terms</a>
        </div>
      </div>
      
    </div>
  </div>
</footer>

<!-- Enhanced JavaScript -->
<script>
  // Toggle password visibility with enhanced feedback
  function togglePassword(fieldId, iconId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    
    if (field.type === 'password') {
      field.type = 'text';
      icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
      icon.parentElement.setAttribute('title', 'Hide password');
    } else {
      field.type = 'password';
      icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
      icon.parentElement.setAttribute('title', 'Show password');
    }
  }

  // Enhanced password strength checker with detailed feedback
  document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchIndicator = document.getElementById('matchIndicator');
    const matchText = document.getElementById('matchText');
    
    // Auto-focus password field
    if (passwordInput) {
      passwordInput.focus();
    }
    
    passwordInput.addEventListener('input', function() {
      const password = this.value;
      let strength = 0;
      let feedback = [];
      
      // Check criteria
      if (password.length >= 8) {
        strength++;
      } else if (password.length > 0) {
        feedback.push('At least 8 characters');
      }
      
      if (password.match(/[a-z]/) && password.match(/[A-Z]/)) {
        strength++;
      } else if (password.length > 0) {
        feedback.push('Mix of upper & lowercase');
      }
      
      if (password.match(/[0-9]/)) {
        strength++;
      } else if (password.length > 0) {
        feedback.push('Include numbers');
      }
      
      if (password.match(/[^a-zA-Z0-9]/)) {
        strength++;
      } else if (password.length > 0) {
        feedback.push('Add special characters');
      }
      
      // Update strength bar with animation
      const widths = ['0%', '25%', '50%', '75%', '100%'];
      const colors = ['bg-gray-300', 'bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500'];
      const texts = ['-', 'Weak', 'Fair', 'Good', 'Strong'];
      const textColors = ['text-gray-500', 'text-red-500', 'text-orange-500', 'text-yellow-600', 'text-green-600'];
      
      strengthBar.style.width = widths[strength];
      strengthBar.className = 'strength-bar rounded-full transition-all duration-300 ' + colors[strength];
      strengthText.textContent = texts[strength];
      strengthText.className = 'text-xs font-semibold transition-colors duration-300 ' + textColors[strength];
      
      // Show feedback tooltip
      if (feedback.length > 0 && password.length > 0) {
        strengthText.setAttribute('title', 'Missing: ' + feedback.join(', '));
      }
      
      checkPasswordMatch();
    });
    
    confirmInput.addEventListener('input', checkPasswordMatch);
    
    function checkPasswordMatch() {
      const password = passwordInput.value;
      const confirm = confirmInput.value;
      
      if (confirm.length > 0) {
        matchIndicator.classList.remove('hidden');
        
        if (password === confirm && password.length > 0) {
          matchIndicator.className = 'mt-2 text-xs text-green-600 flex items-center animate-fadeInUp';
          matchText.textContent = 'Passwords match âœ“';
          matchIndicator.querySelector('svg').innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>';
        } else {
          matchIndicator.className = 'mt-2 text-xs text-red-600 flex items-center animate-fadeInUp';
          matchText.textContent = 'Passwords do not match';
          matchIndicator.querySelector('svg').innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>';
        }
      } else {
        matchIndicator.classList.add('hidden');
      }
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        if (confirm('Cancel password reset and return to sign in?')) {
          window.location.href = 'Sign_in.php';
        }
      }
    });
  });

  // Enhanced form submission with AJAX
  document.getElementById('resetForm').addEventListener('submit', function(e) {
    e.preventDefault(); // Prevent default form submission
    
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    const errorMessage = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    
    // Hide previous errors
    errorMessage.classList.add('hidden');
    
    if (!/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}$/.test(password)) {
      errorText.textContent = 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.';
      errorMessage.classList.remove('hidden');
      return false;
    }

    if (password !== confirm) {
      errorText.textContent = 'Passwords do not match!';
      errorMessage.classList.remove('hidden');
      return false;
    }

    const submitBtn = document.getElementById('submitBtn');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const btnText = submitBtn.querySelector('span:nth-of-type(2)');
    
    // Disable button and show loading
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
    if (btnText) {
      btnText.textContent = 'Resetting...';
    }
    if (loadingSpinner) {
      loadingSpinner.classList.remove('hidden');
    }
    
    // Send AJAX request
    const formData = new FormData();
    formData.append('password', password);
    formData.append('confirm_password', confirm);
    
    fetch('reset_password_handler.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show success notification
        showNotification('Password reset successfully! Redirecting to sign in...', 'success');
        
        // Redirect after short delay
        setTimeout(() => {
          window.location.href = 'Sign_in.php';
        }, 1500);
      } else {
        // Show error message
        errorText.textContent = data.message || 'Failed to reset password. Please try again.';
        errorMessage.classList.remove('hidden');
        
        // Re-enable button
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
        if (btnText) {
          btnText.textContent = 'Reset Password';
        }
        if (loadingSpinner) {
          loadingSpinner.classList.add('hidden');
        }
      }
    })
    .catch(error => {
      console.error('Error:', error);
      errorText.textContent = 'An error occurred. Please try again.';
      errorMessage.classList.remove('hidden');
      
      // Re-enable button
      submitBtn.disabled = false;
      submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
      if (btnText) {
        btnText.textContent = 'Reset Password';
      }
      if (loadingSpinner) {
        loadingSpinner.classList.add('hidden');
      }
    });
    
    return false;
  });
  
  // Notification helper - shows inline error messages
  function showNotification(message, type = 'info') {
    // Remove any existing notification
    const existingMsg = document.getElementById('dynamicMessage');
    if (existingMsg) {
      existingMsg.remove();
    }
    
    const form = document.querySelector('form');
    if (!form) return;
    
    const messageBox = document.createElement('div');
    messageBox.id = 'dynamicMessage';
    messageBox.className = 'mb-4 sm:mb-5 p-3 sm:p-4 rounded-lg border animate-fadeInUp';
    
    let iconSvg = '';
    let textClass = '';
    
    if (type === 'error') {
      messageBox.classList.add('border-red-300', 'bg-red-50');
      textClass = 'text-red-700';
      iconSvg = '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    } else if (type === 'success') {
      messageBox.classList.add('border-green-300', 'bg-green-50');
      textClass = 'text-green-700';
      iconSvg = '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    } else {
      messageBox.classList.add('border-blue-300', 'bg-blue-50');
      textClass = 'text-blue-700';
      iconSvg = '<svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    }
    
    messageBox.innerHTML = `
      <div class="flex items-start">
        <div class="flex-shrink-0 mr-3">
          ${iconSvg}
        </div>
        <div class="flex-1">
          <p class="text-sm ${textClass} font-medium">${message}</p>
        </div>
      </div>
    `;
    
    form.insertBefore(messageBox, form.firstChild);
    messageBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
</script>

</body>
</html>

