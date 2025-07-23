<?php
session_start();
require_once '../../config/connect.php'; // Ensure your database connection is included

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate inputs
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Hash this before storing
    $role = trim($_POST['role'] ?? '');
    $department_id = filter_var($_POST['department_id'] ?? '', FILTER_VALIDATE_INT);
    $campus_id = filter_var($_POST['campus_id'] ?? '', FILTER_VALIDATE_INT); // Get campus_id from hidden input

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($role) || $department_id === false || $campus_id === false) {
        header("Location: ../../views/facilitator/faci_register.php?error=All fields are required and valid IDs must be selected.");
        exit();
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Check if email already exists
    $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    if ($stmt_check) {
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            header("Location: ../../views/facilitator/faci_register.php?error=Email already registered.");
            $stmt_check->close();
            exit();
        }
        $stmt_check->close();
    } else {
        error_log("Error preparing email check statement: " . $conn->error);
        header("Location: ../../views/facilitator/faci_register.php?error=An unexpected error occurred during email check.");
        exit();
    }


    // Insert new user into the database
    // Ensure 'campus_id' is included in the INSERT statement
    $stmt_insert = $conn->prepare("INSERT INTO users (name, email, password, role, department_id, campus_id) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt_insert) {
        $stmt_insert->bind_param("ssssii", $name, $email, $hashed_password, $role, $department_id, $campus_id); // 'sssi' for name, email, password, role, department_id, campus_id types

        if ($stmt_insert->execute()) {
            header("Location: ../../views/facilitator/faci_register.php?success=User registered successfully!");
            exit();
        } else {
            error_log("Error executing user registration: " . $stmt_insert->error);
            header("Location: ../../views/facilitator/faci_register.php?error=Error registering user. Please try again.");
            exit();
        }
        $stmt_insert->close();
    } else {
        error_log("Error preparing user registration statement: " . $conn->error);
        header("Location: ../../views/facilitator/faci_register.php?error=An unexpected error occurred.");
        exit();
    }
} else {
    // If accessed directly without POST request
    header("Location: ../../views/facilitator/faci_register.php");
    exit();
}