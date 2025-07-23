<?php
// Include your config file to get database credentials
// Using __DIR__ for robust path resolution, ensuring it works regardless of where connect.php is included from.
require_once __DIR__ . '/config.php'; 

// Attempt to establish database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Log the error instead of echoing directly to prevent corrupting JSON responses
    // In a production environment, you might not want to display this error to the user.
    error_log("Database Connection Failed: " . $conn->connect_error);
    // For an AJAX call, it's critical not to output HTML here.
    // This 'die' will stop execution and output a message, which is still not JSON.
    // The get_submission_details.php script's error handling should ideally catch this
    // and return a JSON error message, but if the connection itself fails, it's a hard stop.
    die("Connection failed: " . $conn->connect_error);
}

// Set character set to UTF-8 for proper data handling, especially important for special characters
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
}

// IMPORTANT: No closing PHP tag here to prevent accidental whitespace output.
// This is a crucial practice for include files to avoid "Headers already sent" errors
// and "Unexpected token '<'" errors in AJAX responses.
