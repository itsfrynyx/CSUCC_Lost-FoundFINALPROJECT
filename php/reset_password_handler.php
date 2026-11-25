<?php
require_once __DIR__ . '/includes/account_input_validation.php';
session_start();
require '../DB/conn.php';

// Set JSON header
header('Content-Type: application/json');

// Require that the user passed security verification
if (!isset($_SESSION['reset_email'], $_SESSION['reset_idnumber']) || ($_SESSION['otp_verified'] ?? false) !== true || ($_SESSION['security_verified'] ?? false) !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please start the password reset process again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = account_input('password', false);
    $confirmPassword = account_input('confirm_password', false);
    
    // Validation
    if (empty($password) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }
    
    if ($password !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match!']);
        exit();
    }
    
    if (!account_password_is_valid($password)) {
        echo json_encode(['success' => false, 'message' => 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.']);
        exit();
    }
    
    // Hash password and update database
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $email = $_SESSION['reset_email'];

    $stmt = $conn->prepare("UPDATE infos SET passw=? WHERE email=? AND idnumber=?");
    $stmt->bind_param("sss", $hashedPassword, $email, $_SESSION['reset_idnumber']);

    if ($stmt->execute() && $stmt->affected_rows === 1) {
        // Password updated successfully
        
        // Clear session data
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_idnumber']);
        unset($_SESSION['otp_verified']);
        unset($_SESSION['security_verified']);
        
        // Set success message for Sign_in.php
        $_SESSION['password_reset_success'] = true;
        
        echo json_encode(['success' => true, 'message' => 'Password reset successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset password. Please try again.']);
    }
    
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}

$conn->close();
?>
