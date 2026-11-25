<?php
session_start();

$increment = .5;

// Check if the time increment is set in the session
if(!isset($_SESSION['timeIncrement'])){
    $timeIncrement = $increment;
} else if($_SESSION['timeIncrement'] == 1){
   $increment = 1;
   $timeIncrement =  $_SESSION['timeIncrement'];
} else if($_SESSION['timeIncrement'] >= 2) {
    $increment = 0;
    $timeIncrement =  $_SESSION['timeIncrement'];
}

// Update the time increment in the session
$_SESSION['timeIncrement'] = $timeIncrement + $increment;

// Get username from URL if available
$username = isset($_GET['username']) ? htmlspecialchars($_GET['username']) : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied - Caraga State University Lost & Found</title>
  <link rel="icon" href="../PICTURES/Site-Logo.png" type="image/x-icon">
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Lottie Web Component -->
  <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@latest/dist/dotlottie-wc.js" type="module"></script>
  
  <style>
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
    
    /* Main content wrapper */
    .main-wrapper {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
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
    
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
      20%, 40%, 60%, 80% { transform: translateX(10px); }
    }
    
    .animate-fadeInUp {
      animation: fadeInUp 0.6s ease-out;
    }
    
    .animate-shake {
      animation: shake 0.5s ease-in-out;
    }
    
    /* Mobile-specific optimizations */
    @media (max-width: 640px) {
      html {
        height: 100%;
        overflow: hidden;
      }
      
      body {
        height: 100vh;
        height: -webkit-fill-available;
        overflow: hidden;
        display: flex;
        flex-direction: column;
      }
      
      .main-wrapper {
        flex: 1 1 auto;
        overflow-y: auto;
        overflow-x: hidden;
        min-height: 0;
        padding: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      
      nav .flex {
        height: 3rem !important;
        padding-top: 0.375rem;
        padding-bottom: 0.375rem;
      }
      
      nav img {
        height: 1.75rem !important;
        width: 1.75rem !important;
      }
      
      nav h1 {
        font-size: 0.75rem !important;
      }
      
      nav p {
        display: none !important;
      }
      
      .bg-white.rounded-xl {
        padding: 0.75rem !important;
        margin: 0 !important;
        border-radius: 0.75rem !important;
        max-height: none !important;
        width: 100%;
      }
      
      /* Compact header on mobile */
      .text-center.mb-4 {
        margin-bottom: 0.5rem !important;
      }
      
      .w-16.h-16 {
        width: 3rem !important;
        height: 3rem !important;
        margin-bottom: 0.5rem !important;
      }
      
      .w-16.h-16 svg {
        width: 1.5rem !important;
        height: 1.5rem !important;
      }
      
      .text-xl {
        font-size: 1.125rem !important;
      }
      
      /* Compact alerts and boxes */
      .bg-red-50, .bg-blue-50, .bg-gradient-to-br {
        padding: 0.5rem !important;
        margin-bottom: 0.5rem !important;
      }
      
      .text-3xl {
        font-size: 1.75rem !important;
      }
      
      /* Compact buttons */
      .space-y-2 {
        gap: 0.375rem !important;
      }
      
      .space-y-2 a {
        padding-top: 0.5rem !important;
        padding-bottom: 0.5rem !important;
        font-size: 0.75rem !important;
      }
      
      .space-y-2 svg {
        width: 0.875rem !important;
        height: 0.875rem !important;
      }
      
      footer {
        flex-shrink: 0;
        padding: 0.5rem !important;
        background: #f9fafb;
      }
      
      footer .max-w-7xl {
        padding: 0 0.25rem;
      }
      
      footer > .max-w-7xl > .flex {
        flex-direction: column !important;
        align-items: center !important;
        gap: 0.375rem !important;
        text-align: center;
      }
      
      footer .flex.items-center.space-x-2 {
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 0.25rem !important;
        width: 100%;
      }
      
      footer .flex.items-center.space-x-2 img {
        height: 1.25rem !important;
        width: 1.25rem !important;
      }
      
      footer h3 {
        font-size: 0.625rem !important;
        line-height: 1.2;
      }
      
      footer p {
        font-size: 0.5625rem !important;
        line-height: 1.2;
      }
      
      footer .flex.flex-wrap {
        display: none !important;
      }
      
      footer .flex.flex-col {
        flex-direction: row !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.25rem !important;
        width: 100%;
        flex-wrap: wrap;
      }
      
      footer .flex.items-center.gap-2 {
        display: none !important;
      }
      
      footer .text-xs {
        text-align: center !important;
        font-size: 0.5625rem !important;
        line-height: 1.2;
      }
      
      footer .flex.items-center.gap-2:last-child {
        display: none !important;
      }
    }
    
    /* Fix for iOS Safari bottom bar */
    @supports (-webkit-touch-callout: none) {
      html, body {
        height: -webkit-fill-available;
        overflow: hidden;
      }
      
      .mobile-vh-fix {
        height: -webkit-fill-available;
        overflow: hidden;
      }
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

<body class="mobile-vh-fix">


<!-- Enhanced Navbar -->
<nav class="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-200/50">
  <div class="px-3 sm:px-6 lg:px-12">
    <div class="flex justify-between items-center h-14 sm:h-16">
      <!-- Logo - Left Edge -->
      <a href="home_.php" class="flex items-center space-x-2 sm:space-x-3 group relative">
        <div class="relative">
          <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="relative h-8 w-8 sm:h-10 sm:w-10 object-contain group-hover:scale-105 transition-all duration-300">
        </div>
        <div>
          <h1 class="text-base sm:text-xl font-bold text-gray-900 group-hover:text-primary transition-colors duration-300 tracking-tight">Caraga State University</h1>
          <p class="text-xs text-gray-500 font-medium hidden sm:block">Lost & Found Management System</p>
        </div>
      </a>
      
      <!-- Nav Links - Right Edge -->
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
<div class="main-wrapper flex-1 flex items-center justify-center px-3 sm:px-4 py-2 sm:py-4">
  <div class="max-w-md w-full my-auto">
    
    <!-- Access Denied Card -->
    <div class="bg-white rounded-2xl shadow-2xl p-4 sm:p-6 animate-fadeInUp max-h-[calc(100vh-10rem)] overflow-y-auto">
      
      <!-- Icon and Header -->
      <div class="text-center mb-4">
        <div class="mx-auto w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mb-3 animate-shake">
          <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
        </div>
        <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">Access Denied</h2>
        <p class="text-gray-600 text-xs sm:text-sm">Too many failed login attempts</p>
      </div>

      <!-- Alert Message -->
      <div class="bg-red-50 border-l-4 border-red-500 p-3 mb-4 rounded-lg">
        <div class="flex items-start">
          <svg class="w-5 h-5 text-red-500 mt-0.5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
          </svg>
          <div class="flex-1">
            <p class="text-xs font-semibold text-red-800 mb-1">Security Lock Activated</p>
            <p class="text-xs text-red-700">Your account has been temporarily locked. Please wait for the timer to expire.</p>
            <?php if($username): ?>
            <p class="text-xs text-red-600 mt-1 font-medium">Account: <?php echo $username; ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>


      <!-- Timer Display -->
      <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-4 mb-4 text-center border border-gray-200">
        <p class="text-xs text-gray-600 mb-2 font-medium">Time Remaining</p>
        <div id="total-time-left" class="text-3xl font-bold text-primary mb-1">
          <!-- Timer will be inserted here by JavaScript -->
        </div>
        <p class="text-xs text-gray-500">Please wait until the timer expires</p>
      </div>

      <!-- Hidden input for JavaScript -->
      <input type="hidden" id="timeIncrement" value="<?php echo $timeIncrement; ?>">

      <!-- Information Box -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <div class="flex items-start">
          <svg class="w-5 h-5 text-blue-500 mt-0.5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
          </svg>
          <div class="flex-1">
            <p class="text-xs font-semibold text-blue-800 mb-1">Security Tips</p>
            <ul class="text-xs text-blue-700 space-y-0.5 list-disc list-inside">
              <li>Check your username and password</li>
              <li>Verify Caps Lock is disabled</li>
              <li>Use "Forgot Password" if needed</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="space-y-2">
        <a href="Sign_in.php" class="block w-full bg-primary hover:bg-primary-dark text-white font-semibold py-2.5 px-4 rounded-xl transition-all duration-300 text-center shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 text-sm">
          <svg class="w-4 h-4 inline-block mr-2 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
          </svg>
          Return to Sign In
        </a>
        
        <a href="home_.php" class="block w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2.5 px-4 rounded-xl transition-all duration-300 text-center border border-gray-300 text-sm">
          <svg class="w-4 h-4 inline-block mr-2 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          Go to Home
        </a>
      </div>

    </div>

  </div>
</div>

<!-- Enhanced Footer -->
<footer class="bg-gray-50 border-t border-gray-200 py-2 sm:py-3 mt-auto flex-shrink-0">
  <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
    <div class="flex flex-col md:flex-row justify-between items-center gap-2">
      
      <!-- Brand -->
      <div class="flex items-center space-x-2">
        <img src="../PICTURES/Site-Logo.png" alt="CSUCC FIND Logo" class="h-6 w-6 object-contain">
        <div class="text-left">
          <h3 class="text-xs font-bold text-gray-900">Caraga State University</h3>
          <p class="text-xs text-gray-500">Lost & Found Management System</p>
        </div>
      </div>
      
      <!-- Links -->
      <div class="flex flex-wrap justify-center items-center gap-2 text-xs text-gray-600">
        <a href="#" class="hover:text-primary transition-colors">About</a>
        <span class="text-gray-300">|</span>
        <a href="#" class="hover:text-primary transition-colors">Contact</a>
        <span class="text-gray-300">|</span>
        <a href="#" class="hover:text-primary transition-colors">Help Center</a>
      </div>
      
      <!-- Copyright & Social -->
      <div class="flex flex-col items-center md:items-end gap-1">
        <div class="flex items-center gap-2">
          <a href="#" class="text-gray-400 hover:text-primary transition-colors p-1 hover:bg-primary/5 rounded-lg">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          </a>
          <a href="#" class="text-gray-400 hover:text-primary transition-colors p-1 hover:bg-primary/5 rounded-lg">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
          </a>
          <a href="#" class="text-gray-400 hover:text-primary transition-colors p-1 hover:bg-primary/5 rounded-lg">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm4.441 16.892c-2.102.144-6.784.144-8.883 0C5.282 16.736 5.017 15.622 5 12c.017-3.629.285-4.736 2.558-4.892 2.099-.144 6.782-.144 8.883 0C18.718 7.264 18.982 8.378 19 12c-.018 3.629-.285 4.736-2.559 4.892zM10 9.658l4.917 2.338L10 14.342V9.658z"/></svg>
          </a>
        </div>
        <p class="text-xs text-gray-500">© 2024 Caraga State University. All rights reserved.</p>
        <div class="flex items-center gap-2 text-xs text-gray-500">
          <a href="#" class="hover:text-primary transition-colors">Privacy</a>
          <span>â€¢</span>
          <a href="#" class="hover:text-primary transition-colors">Terms</a>
          <span>â€¢</span>
          <a href="#" class="hover:text-primary transition-colors">Returns</a>
        </div>
      </div>
      
    </div>
  </div>
</footer>

<!-- JavaScript -->
<script>
  // No loader needed
</script>

<!-- Include JavaScript file for Timer functionality -->
<script src="../js/Timer.js"></script>

</body>
</html>

