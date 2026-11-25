<?php
// script.php

// List of allowed referer domains
$allowedReferers = [
    'localhost',
    '127.0.0.1',
    '192.168.100.46'
];

// Get the HTTP_REFERER value
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$allowed = false;

// Check if referer is from localhost or local network (192.168.x.x or 10.x.x.x or 172.16-31.x.x)
if (strpos($referer, 'localhost') !== false || 
    strpos($referer, '127.0.0.1') !== false ||
    preg_match('/192\.168\.\d+\.\d+/', $referer) ||
    preg_match('/10\.\d+\.\d+\.\d+/', $referer) ||
    strpos($referer, 'ngrok-free.app') !== false ||
    strpos($referer, 'ngrok-free.dev') !== false ||
    strpos($referer, 'ngrok.io') !== false) {
    $allowed = true;
}

// If the referer is not allowed, redirect to the status code page
if (!$allowed) {
    header("Location: ../php/status-code.php", true, 403);
    exit();
}

// Get the file parameter
$file = isset($_GET['file']) ? basename($_GET['file']) : '';
$dir = isset($_GET['dir']) ? basename($_GET['dir']) : 'php'; // Default to 'php'

// Define directories for JS, CSS, and PHP files
$jsDirectory = '../js/';
$cssDirectory = '../css/';
$phpDirectory = '../php/';

// Determine the directory and build the file path based on the provided dir parameter
$filePath = '';

if ($dir === 'css') {
    $filePath = $cssDirectory . $file;
} elseif ($dir === 'js') {
    $filePath = $jsDirectory . $file;
} elseif ($dir === 'php') {
    $filePath = $phpDirectory . $file;
}

// Check if the file exists
if (file_exists($filePath)) {
    // Handle PHP files differently
    if ($dir === 'php') {
        // Validate that the file is indeed a PHP file
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            include $filePath;
        } else {
            http_response_code(400);
            echo "Invalid file type for PHP directory.";
        }
    } else {
        // Set the correct Content-Type based on the directory
        if ($dir === 'css') {
            header("Content-Type: text/css");
        } elseif ($dir === 'js') {
            header("Content-Type: application/javascript");
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
        echo file_get_contents($filePath);
    }
} else {
    // Handle file not found
    http_response_code(403);
    echo "Error: File not found at: $filePath";
}
exit();
