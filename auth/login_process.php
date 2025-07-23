<?php
session_start();
// Corrected path: Go up one directory from 'auth/' to 'Bweset/', then into 'config/'
require_once '../config/config.php'; 
require_once '../config/connect.php'; // This provides $conn

// Enable error reporting (development only)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Sanitize inputs
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header("Location: login.php?error=" . urlencode("Email and password are required."));
    exit();
}

// Check credentials
$stmt = $conn->prepare("SELECT user_id, name, email, password, role, department_id, campus_id FROM users WHERE email = ?");
if (!$stmt) {
    // Handle prepare error
    error_log("Login process prepare statement failed: " . $conn->error);
    header("Location: login.php?error=" . urlencode("An internal error occurred. Please try again."));
    exit();
}
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password'])) {
    // Set session variables
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['name']; // Using 'name' for username in session
    $_SESSION['role'] = $user['role'];
    $_SESSION['department_id'] = $user['department_id']; // Can be null
    $_SESSION['campus_id'] = $user['campus_id']; // Can be null

    // Redirect to appropriate dashboard based on role
    $dashboard_path = "../views/{$user['role']}/dashboard.php";
    
    // You might add a check here to ensure the dashboard file actually exists
    // For example: if (!file_exists(__DIR__ . '/' . $dashboard_path)) { /* fallback or error */ }
    // However, for this context, we assume the dashboard files are correctly placed.
    
    header("Location: " . $dashboard_path);
    exit();
} else {
    // Login failed
    header("Location: login.php?error=" . urlencode("Invalid email or password."));
    exit();
}

// Close connection (optional here as script exits, but good practice)
if (isset($conn) && $conn) {
    $conn->close();
}
?>
