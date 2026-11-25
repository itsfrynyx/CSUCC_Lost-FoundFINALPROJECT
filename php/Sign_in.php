<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start(); // Start a PHP session
require '../DB/conn.php';
require_once __DIR__ . '/includes/super_admin_handoff.php';

// Check if the user is already logged in
if (isset($_SESSION['user_id'])) {
  // Redirect to the dashboard
  header("Location: Dashboard.php");
  exit();
}

// Check if admin is already logged in
if (isset($_SESSION['super_admin_id'])) {
  // Redirect based on admin role
  if ($_SESSION['admin_role'] === 'super_admin') {
    header("Location: admin_dashboard.php");
  } else {
    header("Location: unified_admin_dashboard.php");
  }
  exit();
}

// Include the getIpAddr function
function getIpAddr()
{
  if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ipAddr = $_SERVER['HTTP_CLIENT_IP'];
  } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ipAddr = $_SERVER['HTTP_X_FORWARDED_FOR'];
  } else {
    $ipAddr = $_SERVER['REMOTE_ADDR'];
  }
  return $ipAddr;
}


function ensureLoginAttemptTable($conn) {
  $checkSql = "SHOW TABLES LIKE 'attempt'";
  $checkResult = $conn->query($checkSql);

  if ($checkResult && $checkResult->num_rows === 0) {
    $createSql = "
      CREATE TABLE attempt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        login_time BIGINT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($createSql)) {
      throw new Exception("Failed to create attempt table: " . $conn->error);
    }
  }
}

$response = [];
if (isset($_POST['submit'])) {
  try {
    ensureLoginAttemptTable($conn);
  } catch (Exception $e) {
    $response['error'] = '<span style="color: red; text-align: center;">Database setup error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>';
  }

  if (!isset($response['error'])) {
    $ip = getIpAddr();

    $time = time() - 15;
    $sql = "SELECT COUNT(*) AS total_count FROM attempt WHERE ip = ? AND login_time > ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $ip, $time);
    $stmt->execute();
    $result = $stmt->get_result();

    $check_login_row = $result->fetch_assoc();
    $total_count = $check_login_row['total_count'] ?? 0;

    if ($total_count >= 3) {
      header('location: A_denied.php');
      exit();
    }

    $username = account_input('username');
    $password = account_input('passw', false);

    $sql = "SELECT * FROM `infos` WHERE `username` = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && $password !== '' && strlen($password) <= 72 && password_verify($password, $user['passw'])) {
      if (isset($user['is_blocked']) && $user['is_blocked'] == 1) {
        $sql = "INSERT INTO attempt (ip, login_time) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $time = time();
        $stmt->bind_param("si", $ip, $time);
        $stmt->execute();

        $_SESSION['login_blocked'] = true;
        $_SESSION['blocked_username'] = $user['username'];
        header('location: Sign_in.php');
        exit();
      }

      if ($user['role'] === 'customer' && isset($user['approved']) && $user['approved'] == 0) {
        $_SESSION['login_pending_approval'] = true;
        $_SESSION['pending_username'] = $user['username'];
        header('location: Sign_in.php');
        exit();
      }

      if ($user['role'] === 'customer' && isset($user['approved']) && $user['approved'] == 2) {
        $_SESSION['login_rejected'] = true;
        $_SESSION['rejected_username'] = $user['username'];
        header('location: Sign_in.php');
        exit();
      }

      if ($user['role'] === 'super_admin') {
        complete_super_admin_handoff($conn, (int) $user['id']);
      }

      $current_time = new DateTime('now', new DateTimeZone('Asia/Manila'));
      $iso_time = $current_time->format('c');

      $update_login_stmt = $conn->prepare("UPDATE infos SET last_login = NOW(), last_activity = NOW(), status = 'active' WHERE id = ?");
      $update_login_stmt->bind_param("i", $user['id']);
      $update_login_stmt->execute();

      session_regenerate_id(true);
      require_once __DIR__ . "/activity_logger.php";

      $user_role = isset($user['role']) ? $user['role'] : 'customer';
      $requires_account_setup = isset($user['must_complete_setup']) && (int) $user['must_complete_setup'] === 1;

      $sql = "DELETE FROM attempt WHERE ip=?";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("s", $ip);
      $stmt->execute();
      unset($_SESSION['timeIncrement']);

      if ($user_role === 'super_admin') {
        $_SESSION['super_admin_id'] = $user['id'];
        $_SESSION['super_admin_idnumber'] = $user['idnumber'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_full_name'] = $user['full_name'] ?? $user['firstname'];
        $_SESSION['admin_role'] = 'super_admin';
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['session_id'] = session_id();

        logLogin($user['id'], 'super_admin');

        header("location: " . ($requires_account_setup ? 'account_setup.php' : 'admin_dashboard.php'));
        exit();
      } elseif ($user_role === 'admin') {
        $_SESSION['super_admin_id'] = $user['id'];
        $_SESSION['super_admin_idnumber'] = $user['idnumber'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_full_name'] = $user['full_name'] ?? $user['firstname'];
        $_SESSION['admin_role'] = 'admin';
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['session_id'] = session_id();

        logLogin($user['id'], 'admin');

        header("location: " . ($requires_account_setup ? 'account_setup.php' : 'unified_admin_dashboard.php'));
        exit();
      } else {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_idnumber'] = $user['idnumber'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_fname'] = $user['firstname'];
        $_SESSION['session_id'] = session_id();

        logLogin($user['id'], 'customer');

        header("location: " . ($requires_account_setup ? 'account_setup.php' : 'Dashboard.php'));
        exit();
      }
    } else {
      $login_time = time();
      $sql = "INSERT INTO attempt(ip, login_time) VALUES (?, ?)";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("si", $ip, $login_time);
      $stmt->execute();

      if ($user) {
        $ip_address = getIpAddr();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $failed_log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, ?, 'failed_login', 'Failed login attempt - incorrect password', ?, ?)");
        $user_type = $user['role'];
        $failed_log_stmt->bind_param("isss", $user['id'], $user_type, $ip_address, $user_agent);
        $failed_log_stmt->execute();
      }

      $total_count++;
      $rem_attm = 3 - $total_count;
      if ($rem_attm <= 0) {
        if ($user) {
          $ip_address = getIpAddr();
          $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
          $denied_log_stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, ?, 'account_locked', 'Account temporarily locked due to multiple failed login attempts', ?, ?)");
          $user_type = $user['role'];
          $denied_log_stmt->bind_param("isss", $user['id'], $user_type, $ip_address, $user_agent);
          $denied_log_stmt->execute();
        }

        header('Location: A_denied.php?username=' . urlencode($username));
        exit();
      }

      $response['error'] = "<span style='color: red; text-align: center;'>Incorrect username or password. $rem_attm attempts remaining.</span>";
    }
  }
}

// Keep the recovery link state consistent after a failed-login refresh.
$login_attempts = 0;
$attempt_time_limit = time() - 15;
$attempt_count_stmt = $conn->prepare("SELECT COUNT(*) AS total_count FROM attempt WHERE ip = ? AND login_time > ?");
if ($attempt_count_stmt) {
  $current_ip = getIpAddr();
  $attempt_count_stmt->bind_param("si", $current_ip, $attempt_time_limit);
  $attempt_count_stmt->execute();
  $attempt_count_result = $attempt_count_stmt->get_result();
  $login_attempts = (int) ($attempt_count_result->fetch_assoc()['total_count'] ?? 0);
  $attempt_count_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CSUCC Lost & Found - Sign In</title>
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
      background: #f3f5f1;
      position: relative;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
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
    
    /* Mobile-specific optimizations */
    @media (max-width: 640px) {
      html {
        height: 100%;
        overflow-x: hidden;
      }
      
      body {
        min-height: 100vh;
        min-height: -webkit-fill-available;
        height: auto;
        overflow-x: hidden;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
      }
      
      /* Ensure touch targets are at least 44px */
      button, a, input[type="checkbox"] {
        min-height: 44px;
      }
      
      /* Better spacing on mobile */
      .input-focus {
        font-size: 16px; /* Prevents zoom on iOS */
      }
      
      /* Allow scrolling on mobile */
      .mobile-vh-fix {
        min-height: 100vh;
        min-height: -webkit-fill-available;
        height: auto;
        overflow-x: hidden;
        overflow-y: auto;
      }
      
      /* Responsive navbar - compact on mobile */
      nav {
        flex-shrink: 0;
      }
      
      nav .flex {
        height: 3.5rem !important;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
      }
      
      nav img {
        height: 2rem !important;
        width: 2rem !important;
      }
      
      nav h1 {
        font-size: 0.875rem !important;
      }
      
      nav p {
        font-size: 0.625rem !important;
      }
      
      /* Responsive main wrapper - adapts to screen height */
      .main-wrapper {
        flex: 1 1 auto;
        overflow-y: visible;
        min-height: 0;
        padding: 0.75rem 0.75rem 1rem;
        display: flex;
        align-items: flex-start;
      }
      
      /* Responsive form container wrapper */
      .max-w-6xl {
        width: 100%;
        margin: 0 auto;
      }
      
      /* Responsive card spacing */
      .animate-fadeInUp {
        margin-top: 0 !important;
        margin-bottom: 0 !important;
        width: 100%;
      }
      
      /* Responsive card padding - more compact */
      .bg-white.rounded-xl {
        padding: 1rem !important;
        margin-bottom: 0 !important;
        border-radius: 0.75rem !important;
        max-height: none !important;
        overflow-y: visible !important;
      }
      
      /* Responsive header spacing - more compact */
      .text-center.mb-4, .text-center.mb-6 {
        margin-bottom: 0.75rem !important;
      }
      
      /* Logo in form header */
      .text-center.mb-4 img, .text-center.mb-6 img {
        height: 2.5rem !important;
        width: 2.5rem !important;
      }
      
      /* Form title */
      .text-center h2 {
        font-size: 1.5rem !important;
        margin-bottom: 0.25rem !important;
      }
      
      .text-center p {
        font-size: 0.875rem !important;
      }
      
      /* Responsive form field spacing - more compact */
      form.space-y-4, form.space-y-5 {
        display: flex;
        flex-direction: column;
        gap: 0.875rem !important;
      }
      
      /* Responsive button padding */
      button, .btn-hover {
        padding-top: 0.625rem !important;
        padding-bottom: 0.625rem !important;
        font-size: 0.875rem !important;
      }
      
      /* Responsive social buttons - more compact */
      .grid.grid-cols-3 {
        gap: 0.5rem !important;
        margin-top: 0.75rem !important;
        margin-bottom: 0.75rem !important;
      }
      
      .grid.grid-cols-3 button {
        padding: 0.625rem 0.5rem !important;
      }
      
      /* Divider spacing */
      .relative.my-4, .relative.my-5 {
        margin-top: 0.875rem !important;
        margin-bottom: 0.875rem !important;
      }
      
      /* Sign up link */
      form p.text-center {
        margin-top: 0.75rem !important;
        font-size: 0.875rem !important;
      }
      
      /* Responsive footer - compact and always visible */
      footer {
        flex-shrink: 0;
        margin-top: 1rem;
        padding: 0.875rem 0.75rem !important;
        background: #f9fafb;
      }
      
      footer .max-w-7xl {
        padding: 0 0.5rem;
      }
      
      /* Main footer flex container - stack vertically on mobile */
      footer > .max-w-7xl > .flex {
        flex-direction: column !important;
        align-items: center !important;
        gap: 0.75rem !important;
        text-align: center;
      }
      
      /* Footer brand - centered */
      footer .flex.items-center.space-x-3 {
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 0.5rem !important;
        width: 100%;
      }
      
      footer .flex.items-center.space-x-3 img {
        height: 1.75rem !important;
        width: 1.75rem !important;
      }
      
      footer .flex.items-center.space-x-3 .text-left {
        text-align: center !important;
      }
      
      footer h3 {
        font-size: 0.75rem !important;
        line-height: 1.2;
      }
      
      footer p {
        font-size: 0.625rem !important;
        line-height: 1.2;
      }
      
      /* Footer links - horizontal centered on mobile */
      footer .flex.flex-wrap {
        flex-wrap: wrap !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 0.5rem !important;
        width: 100%;
      }
      
      footer .flex.flex-wrap a {
        font-size: 0.625rem !important;
        white-space: nowrap;
      }
      
      /* Footer right section - centered */
      footer .flex.flex-col {
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.375rem !important;
        width: 100%;
      }
      
      /* Footer social icons - centered and smaller */
      footer .flex.items-center.gap-2 {
        justify-content: center !important;
        gap: 0.5rem !important;
      }
      
      footer .flex.items-center.gap-2 a {
        width: 1.75rem !important;
        height: 1.75rem !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
      }
      
      footer .flex.items-center.gap-2 svg {
        width: 0.875rem !important;
        height: 0.875rem !important;
      }
      
      /* Footer text elements - all centered */
      footer .text-xs {
        text-align: center !important;
        font-size: 0.625rem !important;
        line-height: 1.3;
      }
      
      /* Footer bottom links (Privacy, Terms, Returns) */
      footer .flex.items-center.gap-2:last-child {
        flex-wrap: wrap;
        justify-content: center !important;
      }
      
      footer .flex.items-center.gap-2:last-child a,
      footer .flex.items-center.gap-2:last-child span {
        font-size: 0.625rem !important;
      }
      
      /* Hide welcome section on mobile to save space */
      .animate-slideIn {
        display: none;
      }
      
      /* Responsive input fields */
      input, textarea, select {
        font-size: 0.875rem !important;
        padding: 0.625rem 0.75rem !important;
      }
      
      /* Input with icons */
      input[type="text"], input[type="password"] {
        padding-left: 2.25rem !important;
      }
      
      /* Labels */
      label {
        font-size: 0.875rem !important;
        margin-bottom: 0.375rem !important;
      }
      
      /* Error messages */
      .bg-red-50, .bg-green-50 {
        padding: 0.75rem !important;
        margin-bottom: 0.875rem !important;
      }
      
      .bg-red-50 p, .bg-green-50 p {
        font-size: 0.875rem !important;
      }
    }
    
    /* Extra small phones (< 375px) - ultra compact */
    @media (max-width: 374px) {
      .main-wrapper {
        padding: 0.5rem 0.5rem 0.75rem;
      }
      
      .bg-white.rounded-xl {
        padding: 0.75rem !important;
      }
      
      .text-center h2 {
        font-size: 1.25rem !important;
      }
      
      form.space-y-4, form.space-y-5 {
        gap: 0.75rem !important;
      }
      
      footer {
        padding: 0.625rem 0.5rem !important;
      }
      
      footer h3 {
        font-size: 0.625rem !important;
      }
      
      footer p, footer a {
        font-size: 0.5625rem !important;
      }
      
      footer .flex.items-center.space-x-3 img {
        height: 1.5rem !important;
        width: 1.5rem !important;
      }
      
      footer .flex.items-center.gap-2 a {
        width: 1.5rem !important;
        height: 1.5rem !important;
      }
      
      footer .flex.items-center.gap-2 svg {
        width: 0.75rem !important;
        height: 0.75rem !important;
      }
    }
    
    /* Small phones (375px - 414px) - standard compact */
    @media (min-width: 375px) and (max-width: 414px) {
      .main-wrapper {
        padding: 0.625rem 0.625rem 0.875rem;
      }
      
      .bg-white.rounded-xl {
        padding: 0.875rem !important;
      }
    }
    
    /* Large phones (415px - 640px) - more spacious */
    @media (min-width: 415px) and (max-width: 640px) {
      .main-wrapper {
        padding: 1rem 1rem 1.25rem;
      }
      
      .bg-white.rounded-xl {
        padding: 1.25rem !important;
      }
      
      form.space-y-4, form.space-y-5 {
        gap: 1rem !important;
      }
      
      footer {
        padding: 1rem 0.75rem !important;
      }
      
      footer h3 {
        font-size: 0.875rem !important;
      }
      
      footer p, footer a {
        font-size: 0.6875rem !important;
      }
      
      footer .flex.items-center.space-x-3 img {
        height: 2rem !important;
        width: 2rem !important;
      }
      
      footer .flex.items-center.gap-2 a {
        width: 2rem !important;
        height: 2rem !important;
      }
      
      footer .flex.items-center.gap-2 svg {
        width: 1rem !important;
        height: 1rem !important;
      }
    }
    
    /* Landscape orientation on mobile - extra compact */
    @media (max-width: 640px) and (orientation: landscape) {
      .main-wrapper {
        padding: 0.5rem 1rem;
      }
      
      .bg-white.rounded-xl {
        padding: 0.75rem !important;
      }
      
      .text-center.mb-4, .text-center.mb-6 {
        margin-bottom: 0.5rem !important;
      }
      
      .text-center img {
        height: 2rem !important;
        width: 2rem !important;
      }
      
      .text-center h2 {
        font-size: 1.25rem !important;
        margin-bottom: 0.125rem !important;
      }
      
      form.space-y-4, form.space-y-5 {
        gap: 0.625rem !important;
      }
      
      footer {
        padding: 0.5rem 0.5rem !important;
      }
      
      nav .flex {
        height: 3rem !important;
      }
    }
    
    /* Fix for iOS Safari bottom bar */
    @supports (-webkit-touch-callout: none) {
      html, body {
        height: -webkit-fill-available;
      }
      
      .mobile-vh-fix {
        min-height: -webkit-fill-available;
      }
    }
    /* CSUCC split login presentation */
    html, body { width: 100%; height: 100%; overflow: hidden; }
    body { background: linear-gradient(135deg, #edf7f1 0%, #eaf0ea 35%, #f7f4ea 100%); color: #172b4d; }
    body::before, nav, footer { display: none; }
    .main-wrapper {
      width: min(1240px, calc(100vw - 48px)); height: min(760px, calc(100vh - 32px)); min-height: 0;
      margin: auto; padding: 0; overflow: hidden; display: block; flex: none;
      background: linear-gradient(135deg, rgba(11,93,68,0.98), rgba(7,60,46,0.96));
      border: 1px solid rgba(23,43,77,.08); border-radius: 24px;
      box-shadow: 0 24px 70px rgba(31,50,77,.16);
      position: relative;
    }
    .main-wrapper::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at top left, rgba(220, 191, 93, 0.18), transparent 30%),
        radial-gradient(circle at bottom right, rgba(255,255,255,0.08), transparent 35%);
      pointer-events: none;
    }
    .main-wrapper > .max-w-6xl { width: 100%; height: 100%; max-width: none; margin: 0; }
    .main-wrapper .grid { height: 100%; display: block; }
    .main-wrapper .animate-slideIn { display: none; }
    .main-wrapper .animate-slideIn::after, .main-wrapper .animate-slideIn > div { display: none; }
    .main-wrapper .animate-fadeInUp { width: 52%; height: 100%; animation: none; }
    .main-wrapper > .max-w-6xl > .grid > .animate-fadeInUp > div { position: relative; height: 100%; max-height: none; overflow: hidden; padding: 16px 64px 16px; border: 0; border-radius: 0; box-shadow: 0 0 30px rgba(23,43,77,.1); background: #fff; }
    .login-home-link { position: absolute; top: 24px; right: 28px; z-index: 2; display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px; color: #52627a; border: 1px solid #d9e0e9; border-radius: 7px; background: #fff; font-size: 12px; font-weight: 600; text-decoration: none; transition: .2s ease; }
    .login-home-link:hover { color: #1d4f91; border-color: #1d4f91; background: #f5f8fc; }
    .login-home-link svg { width: 15px; height: 15px; }
    .main-wrapper .animate-fadeInUp > div::before { content: ''; display: block; width: 34px; height: 34px; margin-bottom: 6px; background: url('../PICTURES/Site-Logo.png') center / contain no-repeat; }
    .main-wrapper .animate-fadeInUp .text-center.mb-4, .main-wrapper .animate-fadeInUp .text-center.mb-6 { margin-bottom: 8px; text-align: left; }
    .main-wrapper .animate-fadeInUp .text-center.mb-4 img, .main-wrapper .animate-fadeInUp .text-center.mb-6 img { display: none; }
    .main-wrapper .animate-fadeInUp .text-center h2 { margin-bottom: 4px; color: #172b4d; font-size: 28px; letter-spacing: -.03em; }
    .main-wrapper .animate-fadeInUp .text-center h2::after { display: none; }
    .main-wrapper .animate-fadeInUp .text-center h2 + p { display: block; margin: 0; color: #718096; font-size: 14px; font-weight: 400; }
    .main-wrapper .login-system-name { display: block; margin: 4px 0 8px; color: #8793a5; font-size: 10px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
    .main-wrapper form.space-y-4, .main-wrapper form.space-y-5 { gap: 6px !important; }
    .main-wrapper form label { color: #52627a; font-weight: 600; }
    .main-wrapper form input:not([type='checkbox']) { min-height: 40px; border-color: #d9e0e9; border-radius: 8px; background: #fbfcfe; }
    .main-wrapper form input:not([type='checkbox']):focus { border-color: #1d4f91; box-shadow: 0 0 0 3px rgba(29,79,145,.12); outline: 0; }
    .main-wrapper form .flex.items-center.justify-between { justify-content: flex-end; }
    .main-wrapper form .flex.items-center.justify-between label { display: none; }
    .main-wrapper form .flex.items-center.justify-between a, .main-wrapper form > p a { color: #1d4f91; }
    .main-wrapper form button[type='submit'] { width: 82%; min-height: 40px; margin: 0 auto; background: #1d4f91; border-radius: 8px; box-shadow: 0 8px 16px rgba(29,79,145,.18); }
    .main-wrapper form button[type='submit']:hover { background: #163d72; }
    .main-wrapper .bg-red-50.animate-fadeInUp, .main-wrapper .bg-green-50.animate-fadeInUp { width: auto; height: auto; animation: none; }
    .main-wrapper .bg-red-50 > div, .main-wrapper .bg-green-50 > div { position: static; height: auto; max-height: none; overflow: visible; padding: 0; border: 0; border-radius: 0; box-shadow: none; background: transparent; }
    .main-wrapper .bg-red-50 > div::before, .main-wrapper .bg-green-50 > div::before { display: none; }
    .main-wrapper .bg-red-50 p, .main-wrapper .bg-green-50 p { min-width: 0; overflow-wrap: anywhere; }
    .main-wrapper form .relative.my-4, .main-wrapper form .relative.my-5, .main-wrapper form .grid.grid-cols-3 { display: none; }
    .main-wrapper form > p.text-center { position: static; margin: 8px 0 0; color: #718096; text-align: center; }
    .main-wrapper > .max-w-6xl { position: relative; z-index: 1; height: 100%; }
    @media (max-width: 900px) { .main-wrapper { width: calc(100vw - 32px); height: calc(100vh - 24px); min-height: 0; } .main-wrapper .animate-fadeInUp { width: 58%; } .main-wrapper .animate-fadeInUp > div { padding: 26px 40px 56px; } .main-wrapper form > p.text-center { right: 40px; left: 40px; } }
    @media (max-width: 680px) { .main-wrapper { width: calc(100vw - 24px); height: calc(100vh - 24px); min-height: 0; border-radius: 18px; background-position: center; } .main-wrapper .animate-fadeInUp { width: 100%; height: 100%; } .main-wrapper .animate-fadeInUp > div { padding: 42px 26px 58px; } .main-wrapper .animate-fadeInUp .text-center.mb-4, .main-wrapper .animate-fadeInUp .text-center.mb-6 { margin-bottom: 28px; } .main-wrapper .animate-fadeInUp .text-center h2 { font-size: 25px; } .main-wrapper form.space-y-4, .main-wrapper form.space-y-5 { gap: 14px !important; } .main-wrapper form input:not([type='checkbox']) { min-height: 46px; } .main-wrapper form button[type='submit'] { width: 100%; } .main-wrapper form > p.text-center { right: 26px; left: 26px; } }

    /* Final reference layout overrides the legacy login styles above. */
    html, body { width: 100%; height: 100%; overflow: auto; }
    body { display: block; background: linear-gradient(135deg, #eef7ed 0%, #f8f4df 100%); color: #172b1f; }
    body::before, nav, footer { display: none; }
    .main-wrapper { display: none; }
    .auth-shell { width: min(1220px, calc(100vw - 48px)); min-height: 720px; margin: 28px auto; display: grid; grid-template-columns: 1.05fr .95fr; overflow: hidden; border-radius: 28px; background: #f8f6ea; box-shadow: 0 30px 60px rgba(17,65,39,.2); }
    .left-panel { position: relative; overflow: hidden; padding: 30px 40px 24px; color: #fff; background: linear-gradient(180deg, #08783d, #04552c); }
    .left-panel::before, .left-panel::after { content: ''; position: absolute; border-radius: 50%; background: rgba(244,196,0,.2); }
    .left-panel::before { width: 170px; height: 170px; left: 10%; top: 15%; }
    .left-panel::after { width: 210px; height: 210px; right: 12%; bottom: 12%; }
    .brand-row, .hero-copy, .illustration-wrap, .login-as { position: relative; z-index: 1; }
    .brand-row { margin-bottom: 48px; font-size: 1.05rem; font-weight: 600; }
    .hero-copy { max-width: 420px; }
    .hero-copy h1 { margin: 0; color: #fff; font-size: clamp(2.3rem, 4vw, 4rem); line-height: 1.1; font-weight: 800; }
    .hero-copy p { margin-top: 18px; color: rgba(255,255,255,.85); font-size: 1rem; line-height: 1.75; }
    .illustration-wrap { height: 260px; margin-top: 26px; display: flex; align-items: center; justify-content: center; }
    .rocket { position: relative; width: 210px; height: 210px; filter: drop-shadow(0 18px 30px rgba(20,32,75,.25)); }
    .rocket-body { position: absolute; inset: 42px 58px 22px 56px; border-radius: 32px 32px 38px 38px; background: linear-gradient(#ffb16d, #f77a4f); transform: rotate(-8deg); }
    .rocket-window { position: absolute; top: 90px; left: 92px; width: 44px; height: 44px; border: 8px solid #fce1c3; border-radius: 50%; background: #0f1b2f; }
    .rocket-fin { position: absolute; bottom: 34px; width: 48px; height: 50px; border-radius: 16px; background: #eb6f47; }
    .rocket-fin.left { left: 40px; transform: rotate(24deg); }
    .rocket-fin.right { right: 30px; transform: rotate(-24deg); }
    .rocket-flame { position: absolute; bottom: 0; left: 102px; width: 22px; height: 38px; background: linear-gradient(#ffed88, #ff5a36); clip-path: polygon(50% 0, 100% 100%, 0 100%); animation: flicker .6s ease-in-out infinite alternate; }
    @keyframes flicker { from { transform: scaleY(.9); } to { transform: scaleY(1.1); } }
    .cloud { position: absolute; z-index: -1; width: 90px; height: 26px; border-radius: 50px; background: rgba(255,255,255,.82); }
    .cloud.c1 { left: 10px; top: 110px; } .cloud.c2 { right: 40px; top: 120px; } .cloud.c3 { left: 80px; bottom: 82px; }
    .login-as { padding-top: 10px; }
    .login-as h4 { margin: 0 0 18px; color: rgba(255,255,255,.9); font-size: 1.1rem; }
    .login-users { display: flex; gap: 24px; flex-wrap: wrap; }
    .user-chip { display: flex; align-items: center; gap: 12px; min-width: 140px; }
    .avatar { width: 52px; height: 52px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 3px solid rgba(255,255,255,.4); border-radius: 50%; background: #4c5664; font-weight: 700; }
    .avatar.alt { background: #d5d6e4; color: #1a1d2a; }
    .user-name { color: #fff; font-weight: 600; } .user-role { color: rgba(255,255,255,.7); font-size: .75rem; }
    .right-panel { display: flex; align-items: center; justify-content: center; padding: 28px 38px 32px; background: #f8f6ea; }
    .form-card { width: min(100%, 470px); padding: 26px; border: 1px solid rgba(148,163,184,.2); border-radius: 26px; background: rgba(255,255,255,.92); box-shadow: 0 20px 40px rgba(31,41,55,.08); }
    .home-button { display: inline-flex; align-items: center; gap: 7px; margin-bottom: 18px; padding: 8px 12px; border: 1px solid #d8e3da; border-radius: 9px; background: #fff; color: #08783d; font-size: .84rem; font-weight: 700; text-decoration: none; transition: .2s ease; }
    .home-button:hover { border-color: #08783d; background: #eef7ed; }
    .home-button svg { width: 17px; height: 17px; }
    .welcome-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; color: #6f7e90; font-size: .92rem; }
    .welcome-row strong, .form-card h2 { color: #173b27; } .welcome-row a, .row-link a, .bottom-link a { color: #08783d; font-weight: 600; }
    .form-card h2 { margin: 8px 0 18px; font-size: clamp(2.1rem, 3vw, 3rem); line-height: 1.1; font-weight: 800; }
    .social-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
    .social-btn { height: 46px; display: flex; align-items: center; justify-content: center; border: 1px solid #dfe5ee; border-radius: 12px; background: #fff; }
    .social-btn svg { width: 18px; height: 18px; }
    .or-divider { display: flex; align-items: center; gap: 14px; margin: 8px 0 18px; color: #8a95a6; font-size: .72rem; }
    .or-divider::before, .or-divider::after { content: ''; height: 1px; flex: 1; background: #dfe3ea; }
    .form-card form { display: flex; flex-direction: column; gap: 14px; }
    .field-wrap label { display: block; margin-bottom: 8px; color: #4f5d74; font-size: .9rem; font-weight: 600; }
    .field-wrap { position: relative; }
    .field-wrap input { width: 100%; height: 54px; box-sizing: border-box; padding: 0 16px 0 48px; border: 1px solid #dfe5ee; border-radius: 12px; background: #f8fafc; color: #112033; font-size: 1rem; }
    .field-icon { position: absolute; top: 43px; left: 16px; width: 20px; height: 20px; color: #6b7c72; pointer-events: none; }
    .password-toggle { position: absolute; top: 34px; right: 12px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: transparent; color: #6b7c72; cursor: pointer; }
    .password-toggle:hover, .password-toggle:focus-visible { color: #08783d; background: rgba(8,120,61,.08); outline: none; }
    .password-toggle svg { width: 20px; height: 20px; }
    .password-field input { padding-right: 54px; }
    .field-wrap input:focus { outline: none; border-color: #08783d; box-shadow: 0 0 0 4px rgba(8,120,61,.14); background: #fff; }
    .row-link { display: flex; justify-content: flex-end; } .submit-btn { height: 52px; margin-top: 8px; border: 0; border-radius: 12px; background: linear-gradient(135deg, #08783d, #04552c); color: #fff; font-size: 1.05rem; font-weight: 700; cursor: pointer; box-shadow: 0 10px 20px rgba(8,120,61,.2); }
    .bottom-link { margin-top: 18px; color: #65748d; text-align: center; font-size: .94rem; }
    @media (max-width: 980px) { .auth-shell { width: calc(100vw - 28px); grid-template-columns: 1fr; } .left-panel { min-height: 420px; } }
    @media (max-width: 520px) { body { padding: 16px; } .auth-shell { width: 100%; min-height: auto; margin: 0; border-radius: 20px; } .left-panel, .right-panel { padding-right: 18px; padding-left: 18px; } .form-card { padding: 20px 18px; } }
  </style>
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#0d5c3c',
            'primary-dark': '#0a4732',
            accent: '#d7b94b',
            campus: '#f5f1df'
          }
        }
      }
    }
  </script>
</head>

<body class="mobile-vh-fix">
<div class="auth-shell">
  <aside class="left-panel">
    <div class="brand-row">CSUCC Lost &amp; Found</div>

    <div class="hero-copy">
      <h1>Find what matters.</h1>
      <p>Sign in to CSUCC Lost &amp; Found to report missing belongings, browse found items, and help return property to its rightful owner.</p>
    </div>

    <div class="illustration-wrap" aria-hidden="true">
      <div class="rocket">
        <div class="cloud c1"></div>
        <div class="cloud c2"></div>
        <div class="cloud c3"></div>
        <div class="rocket-fin left"></div>
        <div class="rocket-fin right"></div>
        <div class="rocket-body"></div>
        <div class="rocket-window"></div>
        <div class="rocket-flame"></div>
      </div>
    </div>

    <div class="login-as">
      <h4>Campus recovery made simple</h4>
      <div class="login-users">
        <div class="user-chip">
          <div class="avatar">JP</div>
          <div>
            <div class="user-name">Report an item</div>
            <div class="user-role">Submit a lost or found report</div>
          </div>
        </div>
        <div class="user-chip">
          <div class="avatar alt">AS</div>
          <div>
            <div class="user-name">Track a claim</div>
            <div class="user-role">Verify and recover belongings</div>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <main class="right-panel">
    <div class="form-card">
      <a href="home_.php" class="home-button" aria-label="Return to home">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V10Z" />
        </svg>
        <span>Home</span>
      </a>
      <div class="welcome-row">
        <span>Welcome to <strong>CSUCC FIND</strong></span>
        <a href="Register.php">Create account</a>
      </div>

      <h2>Sign in</h2>

      <div class="social-row" aria-label="Continue with social accounts">
        <button type="button" class="social-btn" aria-label="Sign in with Google">
          <svg viewBox="0 0 48 48" aria-hidden="true">
            <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20c10.493 0 19.438-7.616 19.9-17.307z"/>
            <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
            <path fill="#4CAF50" d="M24 44c5.166 0 9.86-.985 13.821-2.965l-6.396-5.421C29.642 37.106 26.982 38 24 38c-5.222 0-9.653-3.343-11.303-8H6.126v5.801C9.382 40.499 16.085 44 24 44z"/>
            <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-1.297 3.666-4.312 6.666-8.303 7.697l6.396 5.421C39.421 39.83 44 35.105 44 24c0-1.24-.12-2.466-.389-3.917z"/>
          </svg>
        </button>
        <button type="button" class="social-btn" aria-label="Sign in with Facebook">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M12 2.04c-5.5 0-9.96 4.46-9.96 9.96 0 4.97 3.66 9.1 8.44 9.88v-6.98H7.9v-2.9h2.54V9.39c0-2.5 1.49-3.88 3.77-3.88 1.09 0 2.23.2 2.23.2v2.45h-1.26c-1.24 0-1.62.77-1.62 1.56v1.87h2.78l-.45 2.9h-2.33V22.9c4.78-.78 8.44-4.91 8.44-9.88 0-5.5-4.46-9.96-9.96-9.96Z"/></svg>
        </button>
        <button type="button" class="social-btn" aria-label="Sign in with Apple">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#111827" d="M16.7 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9-.8 0-1.9-.9-3.1-.9-1.6 0-3.1.9-3.9 2.3-1.7 2.9-.4 7.1 1.2 9.4.8 1.1 1.7 2.3 2.9 2.3 1.1 0 1.6-.7 3-.7s1.8.7 3 .7c1.2 0 2-.9 2.7-2 .8-1.2 1.2-2.5 1.2-2.6-.1-.1-2.2-.9-3.7-3.3ZM15.7 5.8c.6-.7 1-1.7 1-2.7-.9.1-2.1.6-2.8 1.4-.6.7-1.1 1.7-1 2.7.9.1 2.1-.5 2.8-1.4Z"/></svg>
        </button>
      </div>

      <div class="or-divider">OR</div>

      <?php if (!empty($response['error'])): ?>
        <div class="mb-3 bg-red-50 border-l-4 border-red-500 p-3 rounded-r-lg">
          <p class="text-xs text-red-700"><?= $response['error'] ?></p>
        </div>
      <?php endif; ?>

      <form action="" method="POST" onsubmit="return loginValidation()">
        <div class="field-wrap">
          <label for="username">Enter your username</label>
          <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21a8 8 0 0 1 16 0" />
          </svg>
          <input type="text" id="username" name="username" maxlength="30" value="<?= htmlspecialchars(account_input('username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="username" />
          <span id="username_val" class="text-xs text-red-500 mt-1 block"></span>
        </div>

        <div class="field-wrap password-field">
          <label for="password">Enter your Password</label>
          <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V7a4.5 4.5 0 0 0-9 0v3.5M6 10.5h12A1.5 1.5 0 0 1 19.5 12v7A1.5 1.5 0 0 1 18 20.5H6A1.5 1.5 0 0 1 4.5 19v-7A1.5 1.5 0 0 1 6 10.5Z" />
          </svg>
          <input type="password" id="password" name="passw" maxlength="15" placeholder="Password" />
          <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Show password" title="Show password">
            <svg id="toggleIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7Z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
          </button>
          <span id="password_val" class="text-xs text-red-500 mt-1 block"></span>
        </div>

        <div class="row-link">
          <?php if ($login_attempts >= 2): ?>
            <a href="forgotpass.php" onclick="event.preventDefault(); const recoveryForm = document.createElement('form'); recoveryForm.method = 'POST'; recoveryForm.action = this.href; const recoveryUsername = document.createElement('input'); recoveryUsername.type = 'hidden'; recoveryUsername.name = 'recovery_username'; recoveryUsername.value = document.getElementById('username').value.trim(); recoveryForm.appendChild(recoveryUsername); document.body.appendChild(recoveryForm); recoveryForm.submit();">Forgot Password?</a>
          <?php endif; ?>
        </div>

        <button type="submit" name="submit" class="submit-btn">Sign in</button>
      </form>

      <div class="bottom-link">New to CSUCC Lost &amp; Found? <a href="Register.php">Create an account</a></div>
    </div>
  </main>
</div>

<!-- Password Toggle Script -->
<script>
  function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      // Eye slash icon
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
    } else {
      passwordInput.type = 'password';
      // Eye icon
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
    }
  }
</script>

  <!-- Blocked Account Modal -->
  <?php if (isset($_SESSION['login_blocked']) && $_SESSION['login_blocked'] === true): ?>
  <style>
  /* Minimalist Blocked Account Modal */
  .login-block-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.75);
      backdrop-filter: blur(8px);
      z-index: 99999;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: fadeIn 0.3s ease;
  }

  .login-block-modal {
      background: white;
      border-radius: 12px;
      max-width: 480px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: slideUp 0.3s ease;
      overflow: hidden;
      position: relative;
  }

  .login-block-header {
      padding: 40px 32px 24px;
      text-align: center;
      border-bottom: 1px solid #f1f5f9;
  }

  .login-block-icon {
      width: 64px;
      height: 64px;
      margin: 0 auto 20px;
      border-radius: 50%;
      background: #dc2626;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
  }

  .login-block-title {
      font-size: 24px;
      font-weight: 600;
      margin: 0 0 8px 0;
      color: #1e293b;
  }

  .login-block-subtitle {
      font-size: 14px;
      color: #64748b;
      margin: 0;
  }

  .login-block-body {
      padding: 32px;
  }

  .login-block-username {
      background: #fef2f2;
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      text-align: center;
      font-size: 15px;
      font-weight: 600;
      color: #dc2626;
      border: 1px solid #fecaca;
  }

  .login-block-message {
      font-size: 15px;
      color: #475569;
      line-height: 1.6;
      margin-bottom: 24px;
      text-align: center;
      margin-left: 20px;
  }

  .login-block-details {
      background: #f8fafc;
      border-left: 3px solid #dc2626;
      padding: 20px;
      border-radius: 8px;
      margin-bottom: 24px;
      text-align: center;
  }

  .login-block-details h4 {
      font-size: 14px;
      font-weight: 600;
      color: #1e293b;
      margin: 0 0 12px 0;
      text-align: center;
  }

  .login-block-details ul {
      margin: 0;
      padding: 0;
      list-style: none;
      display: inline-block;
      text-align: left;
  }

  .login-block-details li {
      padding: 8px 0;
      color: #64748b;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
  }

  .login-block-details li i {
      color: #dc2626;
      font-size: 14px;
      width: 20px;
  }

  .login-block-btn {
      width: 100%;
      padding: 12px 24px;
      border: none;
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      background: #dc2626;
      color: white;
      transition: all 0.2s ease;
  }

  .login-block-btn:hover {
      background: #b91c1c;
  }

  .login-block-btn:active {
      transform: scale(0.98);
  }
  }

  .login-block-btn:active {
      transform: scale(0.98);
  }

  /* Simple Animations */
  @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
  }

  @keyframes slideUp {
      from {
          opacity: 0;
          transform: translateY(20px);
      }
      to {
          opacity: 1;
          transform: translateY(0);
      }
  }

  @keyframes fadeOut {
      from { opacity: 1; }
      to { opacity: 0; }
  }

  /* Responsive */
  @media (max-width: 576px) {
      .login-block-modal {
          width: 95%;
      }
      
      .login-block-header {
          padding: 32px 24px 20px;
      }
      
      .login-block-body {
          padding: 24px;
      }
      
      .login-block-icon {
          width: 56px;
          height: 56px;
          font-size: 28px;
      }
      
      .login-block-title {
          font-size: 20px;
      }
  }
  </style>

  <div class="login-block-overlay" id="loginBlockOverlay">
      <div class="login-block-modal">
          <div class="login-block-header">
              <div class="login-block-icon">
                  <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
              </div>
              <h3 class="login-block-title">Account Blocked</h3>
              <p class="login-block-subtitle">Access Denied</p>
          </div>
          <div class="login-block-body">
              <div class="login-block-username">
                  <?php echo htmlspecialchars($_SESSION['blocked_username'] ?? 'Your account'); ?>
              </div>
              
              <p class="login-block-message">
        Your account has been blocked by the Super Administrator. 
                  <br> You will not be able to login until your account is unblocked.
              </p>
              
              <div class="login-block-details">
                  <h4>What this means:</h4>
                  <ul>
                      <li>
                          <svg class="w-5 h-5 text-red-600 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                          </svg>
                          <span>You cannot login to the system</span>
                      </li>
                      <li>
                          <svg class="w-5 h-5 text-red-600 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                          </svg>
                          <span>All access has been revoked</span>
                      </li>
                      <li>
                          <svg class="w-5 h-5 text-red-600 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                          </svg>
                          <span>Contact support for assistance</span>
                      </li>
                  </ul>
              </div>
              
              <button class="login-block-btn" onclick="closeBlockModal()">
                  I Understand
              </button>
          </div>
      </div>
  </div>

  <?php endif; ?>

  <script>
  function closeBlockModal() {
      const overlay = document.getElementById('loginBlockOverlay');
      overlay.style.animation = 'fadeOut 0.2s ease';
      setTimeout(() => {
          overlay.style.display = 'none';
      }, 200);
      <?php 
      unset($_SESSION['login_blocked']);
      unset($_SESSION['blocked_username']);
      ?>
  }
  
  // Keyboard support
  document.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === 'Escape') {
          const overlay = document.getElementById('loginBlockOverlay');
          if (overlay && overlay.style.display !== 'none') {
              closeBlockModal();
          }
      }
  });
  </script>

  <!-- Pending Approval Modal -->
  <?php if (isset($_SESSION['login_pending_approval']) && $_SESSION['login_pending_approval'] === true): ?>
  <style>
  /* Enhanced Aesthetic Pending Approval Modal */
  .pending-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      animation: fadeIn 0.3s ease-out;
  }
  
  .pending-modal {
      background: white;
      border-radius: 20px;
      padding: 0;
      max-width: 480px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
      overflow: hidden;
  }
  
  .pending-header {
      background: #0d5c3c;
      padding: 40px 30px 30px;
      text-align: center;
      position: relative;
  }
  
  .pending-icon-wrapper {
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      animation: pulse 2s infinite;
  }
  
  .pending-icon {
      width: 50px;
      height: 50px;
      color: white;
  }
  
  .pending-title {
      color: white;
      font-size: 26px;
      font-weight: 600;
      margin: 0;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }
  
  .pending-body {
      padding: 35px 30px;
      text-align: center;
  }
  
  .pending-message {
      color: #555;
      font-size: 16px;
      line-height: 1.8;
      margin: 0 0 30px;
  }
  
  .pending-message strong {
      color: #0d5c3c;
      font-weight: 600;
  }
  
  .pending-info-box {
      background: #f3f7f2;
      border-left: 4px solid #0d5c3c;
      padding: 15px 20px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: left;
  }
  
  .pending-info-box p {
      margin: 8px 0;
      color: #666;
      font-size: 14px;
  }
  
  .pending-info-box i {
      color: #0d5c3c;
      margin-right: 10px;
  }
  
  .pending-button {
      background: #0d5c3c;
      color: white;
      border: none;
      padding: 14px 40px;
      border-radius: 50px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(13, 92, 60, 0.25);
      display: inline-flex;
      align-items: center;
      gap: 10px;
  }
  
  .pending-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(13, 92, 60, 0.32);
      background: #094631;
  }
  
  @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
  }
  
  @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
  }
  
  @keyframes modalSlideIn {
      from {
          opacity: 0;
          transform: translateY(-50px) scale(0.9);
      }
      to {
          opacity: 1;
          transform: translateY(0) scale(1);
      }
  }
  
  @keyframes modalSlideOut {
      from {
          opacity: 1;
          transform: translateY(0) scale(1);
      }
      to {
          opacity: 0;
          transform: translateY(-50px) scale(0.9);
      }
  }
  </style>
  
  <div class="pending-overlay" id="loginPendingOverlay">
      <div class="pending-modal">
          <div class="pending-header">
              <div class="pending-icon-wrapper">
                  <svg class="pending-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10"></circle>
                      <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
              </div>
              <h3 class="pending-title">Account Pending Approval</h3>
          </div>
          
          <div class="pending-body">
              <p class="pending-message">
                  Your account is currently <strong>pending approval</strong> by the Super Administrator.
              </p>
              
              <div class="pending-info-box">
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      Your registration has been received
                  </p>
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      Waiting for administrator review
                  </p>
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      You'll be notified once approved
                  </p>
              </div>
              
              <p class="pending-message" style="font-size: 14px; color: #888; margin-top: 20px;">
                  Please wait for the administrator to verify your account.<br>
                  You will be able to login once approved.
              </p>
              
              <button class="pending-button" onclick="closePendingModal()">
                  <span>Understood</span>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
              </button>
          </div>
      </div>
  </div>

  <script>
  function closePendingModal() {
      const overlay = document.getElementById('loginPendingOverlay');
      const modal = overlay.querySelector('.pending-modal');
      
      modal.style.animation = 'modalSlideOut 0.3s ease-out forwards';
      overlay.style.animation = 'fadeOut 0.3s ease-out forwards';
      
      setTimeout(() => {
          overlay.style.display = 'none';
      }, 300);
  }

  window.addEventListener('load', function() {
      const overlay = document.getElementById('loginPendingOverlay');
      if (overlay) {
          setTimeout(() => {
              overlay.style.display = 'flex';
              overlay.style.animation = 'fadeIn 0.3s ease-out forwards';
              overlay.querySelector('.login-block-modal').style.animation = 'modalSlideIn 0.3s ease-out forwards';
          }, 200);
          <?php 
          unset($_SESSION['login_pending_approval']);
          unset($_SESSION['pending_username']);
          ?>
      }
  });
  </script>
  <?php endif; ?>

  <!-- Rejected Account Modal -->
  <?php if (isset($_SESSION['login_rejected']) && $_SESSION['login_rejected'] === true): ?>
  <style>
  /* Enhanced Aesthetic Rejected Account Modal */
  .rejected-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      animation: fadeIn 0.3s ease-out;
  }
  
  .rejected-modal {
      background: white;
      border-radius: 20px;
      padding: 0;
      max-width: 480px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
      overflow: hidden;
  }
  
  .rejected-header {
      background: #0d5c3c;
      padding: 40px 30px 30px;
      text-align: center;
      position: relative;
  }
  
  .rejected-icon-wrapper {
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      animation: shake 0.5s ease-out;
  }
  
  .rejected-icon {
      width: 50px;
      height: 50px;
      color: white;
  }
  
  .rejected-title {
      color: white;
      font-size: 26px;
      font-weight: 600;
      margin: 0;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }
  
  .rejected-body {
      padding: 35px 30px;
      text-align: center;
  }
  
  .rejected-message {
      color: #555;
      font-size: 16px;
      line-height: 1.8;
      margin: 0 0 30px;
  }
  
  .rejected-message strong {
      color: #0d5c3c;
      font-weight: 600;
  }
  
  .rejected-info-box {
      background: #f3f7f2;
      border-left: 4px solid #0d5c3c;
      padding: 15px 20px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: left;
  }
  
  .rejected-info-box p {
      margin: 8px 0;
      color: #666;
      font-size: 14px;
  }
  
  .rejected-info-box i {
      color: #0d5c3c;
      margin-right: 10px;
  }
  
  .rejected-button {
      background: #0d5c3c;
      color: white;
      border: none;
      padding: 14px 40px;
      border-radius: 50px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(13, 92, 60, 0.25);
      display: inline-flex;
      align-items: center;
      gap: 10px;
  }
  
  .rejected-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(13, 92, 60, 0.32);
      background: #094631;
  }
  
  @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-10px); }
      75% { transform: translateX(10px); }
  }
  </style>
  
  <div class="rejected-overlay" id="loginRejectedOverlay">
      <div class="rejected-modal">
          <div class="rejected-header">
              <div class="rejected-icon-wrapper">
                  <svg class="rejected-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10"></circle>
                      <line x1="15" y1="9" x2="9" y2="15"></line>
                      <line x1="9" y1="9" x2="15" y2="15"></line>
                  </svg>
              </div>
              <h3 class="rejected-title">Account Not Approved</h3>
          </div>
          
          <div class="rejected-body">
              <p class="rejected-message">
                  Your account registration was <strong>not approved</strong> by the Super Administrator.
              </p>
              
              <div class="rejected-info-box">
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      Your registration was reviewed
                  </p>
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                      </svg>
                      Access has been denied
                  </p>
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                      </svg>
                      Contact administrator for details
                  </p>
              </div>
              
              <p class="rejected-message" style="font-size: 14px; color: #888; margin-top: 20px;">
                  Please contact the administrator for more information<br>
                  about your registration status.
              </p>
              
              <button class="rejected-button" onclick="closeRejectedModal()">
                  <span>Close</span>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
              </button>
          </div>
      </div>
  </div>

  <script>
  function closeRejectedModal() {
      const overlay = document.getElementById('loginRejectedOverlay');
      const modal = overlay.querySelector('.rejected-modal');
      
      modal.style.animation = 'modalSlideOut 0.3s ease-out forwards';
      overlay.style.animation = 'fadeOut 0.3s ease-out forwards';
      
      setTimeout(() => {
          overlay.style.display = 'none';
      }, 300);
  }

  window.addEventListener('load', function() {
      const overlay = document.getElementById('loginRejectedOverlay');
      if (overlay) {
          setTimeout(() => {
              overlay.style.display = 'flex';
              overlay.style.animation = 'fadeIn 0.3s ease-out forwards';
              overlay.querySelector('.rejected-modal').style.animation = 'modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards';
          }, 200);
          <?php 
          unset($_SESSION['login_rejected']);
          unset($_SESSION['rejected_username']);
          ?>
      }
  });
  </script>
  <?php endif; ?>

  <!-- Registration Success - Pending Approval Modal -->
  <?php if (isset($_SESSION['registration_pending']) && $_SESSION['registration_pending'] === true): ?>
  <style>
  /* Enhanced Registration Success Modal */
  .registration-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      animation: fadeIn 0.3s ease-out;
  }
  
  .registration-modal {
      background: white;
      border-radius: 20px;
      padding: 0;
      max-width: 500px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
      overflow: hidden;
  }
  
  .registration-header {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      padding: 40px 30px 30px;
      text-align: center;
      position: relative;
  }
  
  .registration-icon-wrapper {
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      animation: successPulse 1.5s ease-out;
  }
  
  .registration-icon {
      width: 50px;
      height: 50px;
      color: white;
  }
  
  .registration-title {
      color: white;
      font-size: 26px;
      font-weight: 600;
      margin: 0;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }
  
  .registration-body {
      padding: 35px 30px;
      text-align: center;
  }
  
  .registration-message {
      color: #555;
      font-size: 16px;
      line-height: 1.8;
      margin: 0 0 25px;
  }
  
  .registration-message strong {
      color: #10b981;
      font-weight: 600;
  }
  
  .registration-steps {
      background: #f0fdf4;
      border-left: 4px solid #10b981;
      padding: 20px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: left;
  }
  
  .registration-step {
      display: flex;
      align-items: flex-start;
      margin: 12px 0;
      color: #666;
      font-size: 14px;
  }
  
  .step-number {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 600;
      margin-right: 12px;
      flex-shrink: 0;
  }
  
  .registration-note {
      background: #f3f7f2;
      border-left: 4px solid #0d5c3c;
      padding: 15px 20px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: left;
  }
  
  .registration-note p {
      margin: 0;
      color: #666;
      font-size: 14px;
  }
  
  .registration-note i {
      color: #0d5c3c;
      margin-right: 10px;
  }
  
  .registration-button {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border: none;
      padding: 14px 40px;
      border-radius: 50px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
  }
  
  .registration-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
  }
  
  @keyframes successPulse {
      0% { transform: scale(0); opacity: 0; }
      50% { transform: scale(1.1); }
      100% { transform: scale(1); opacity: 1; }
  }
  </style>
  
  <div class="registration-overlay" id="registrationPendingOverlay">
      <div class="registration-modal">
          <div class="registration-header">
              <div class="registration-icon-wrapper">
                  <svg class="registration-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                      <polyline points="22 4 12 14.01 9 11.01"></polyline>
                  </svg>
              </div>
              <h3 class="registration-title">Registration Successful!</h3>
          </div>
          
          <div class="registration-body">
              <p class="registration-message">
                  Your account has been <strong>created successfully</strong>.
              </p>
              
              <div class="registration-steps">
                  <div class="registration-step">
                      <span class="step-number">1</span>
                      <span>Your registration has been submitted</span>
                  </div>
                  <div class="registration-step">
                      <span class="step-number">2</span>
                      <span>Super Administrator will review your account</span>
                  </div>
                  <div class="registration-step">
                      <span class="step-number">3</span>
                      <span>You'll be able to login once approved</span>
                  </div>
              </div>
              
              <div class="registration-note">
                  <p>
                      <svg class="w-5 h-5 text-primary inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      <strong>Please wait for approval</strong> - You will receive notification once your account is verified.
                  </p>
              </div>
              
              <button class="registration-button" onclick="closeRegistrationModal()">
                  <span>Got it, Thanks!</span>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
              </button>
          </div>
      </div>
  </div>

  <script>
  function closeRegistrationModal() {
      const overlay = document.getElementById('registrationPendingOverlay');
      const modal = overlay.querySelector('.registration-modal');
      
      modal.style.animation = 'modalSlideOut 0.3s ease-out forwards';
      overlay.style.animation = 'fadeOut 0.3s ease-out forwards';
      
      setTimeout(() => {
          overlay.style.display = 'none';
      }, 300);
  }

  window.addEventListener('load', function() {
      const overlay = document.getElementById('registrationPendingOverlay');
      if (overlay) {
          setTimeout(() => {
              overlay.style.display = 'flex';
              overlay.style.animation = 'fadeIn 0.3s ease-out forwards';
              overlay.querySelector('.registration-modal').style.animation = 'modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards';
          }, 200);
          <?php 
          unset($_SESSION['registration_pending']);
          ?>
      }
  });
  </script>
  <?php endif; ?>

<!-- Login Validation Script -->
<script src="../js/login_val.js?v=2"></script>

</body>
</html>

