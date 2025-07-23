<?php
// File: controllers/pio/update_payment_status.php

// Start output buffering to catch any accidental output
ob_start();

session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Ensure the user is logged in and is a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    $response['message'] = 'Unauthorized access.';
    ob_clean(); // Clean any buffered output before sending JSON
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id']; // The PIO user ID

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $payment_status = filter_input(INPUT_POST, 'payment_status', FILTER_SANITIZE_STRING);

    // Validate inputs
    if (!$submission_id) {
        $response['message'] = 'Invalid submission ID.';
        ob_clean();
        echo json_encode($response);
        exit();
    }
    // Basic validation for payment status (e.g., allow only 'Paid' or 'Pending')
    if (!in_array($payment_status, ['Pending', 'Paid'])) {
        $response['message'] = 'Invalid payment status provided.';
        ob_clean();
        echo json_encode($response);
        exit();
    }

    $conn->begin_transaction();

    try {
        // Update the payment_status in the submission_reviews table
        // Note: payment_status is in submission_reviews, not submissions
        $stmt = $conn->prepare("UPDATE submission_reviews SET payment_status = ?, updated_at = NOW() WHERE submission_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error);
        }
        $stmt->bind_param("si", $payment_status, $submission_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update payment status: " . $stmt->error);
        }
        $stmt->close();

        // Optionally, log this action
        $log_message = "Payment status for submission ID {$submission_id} changed to '{$payment_status}' by PIO (User ID: {$user_id}).";
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, description, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt_log) {
            $activity_type = 'payment_update';
            $stmt_log->bind_param("iss", $user_id, $activity_type, $log_message);
            $stmt_log->execute();
            $stmt_log->close();
        } else {
            error_log("Failed to prepare activity log statement: " . $conn->error);
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Payment status updated successfully.';

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Transaction failed: ' . $e->getMessage();
        error_log("Update Payment Status Error: " . $e->getMessage());
    }
} else {
    $response['message'] = 'Invalid request method.';
}

ob_clean(); // Clean any buffered output before sending JSON
echo json_encode($response);
$conn->close();
?>
