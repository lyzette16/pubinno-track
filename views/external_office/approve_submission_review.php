<?php
// File: controllers/external_office/approve_submission_review.php

// Start output buffering to catch any accidental output
ob_start();

session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Ensure the user is logged in and is an external_office
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
    $response['message'] = 'Unauthorized access.';
    ob_clean(); // Clean any buffered output before sending JSON
    echo json_encode($response);
    exit();
}

$reviewer_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $remarks = filter_input(INPUT_POST, 'remarks', FILTER_SANITIZE_STRING);
    $evidence_link = filter_input(INPUT_POST, 'evidence_link', FILTER_SANITIZE_URL); // New field
    $indexing_body = filter_input(INPUT_POST, 'indexing_body', FILTER_SANITIZE_STRING);
    $incentives_amount = filter_input(INPUT_POST, 'incentives_amount', FILTER_VALIDATE_FLOAT);
    $publisher = filter_input(INPUT_POST, 'publisher', FILTER_SANITIZE_STRING);
    $is_special_award = isset($_POST['is_special_award']) ? 1 : 0; // Checkbox value

    if (!$submission_id) {
        $response['message'] = 'Invalid submission ID.';
        ob_clean(); // Clean any buffered output
        echo json_encode($response);
        exit();
    }

    $conn->begin_transaction();

    try {
        // 1. Update submission status to 'approved'
        $stmt_update_submission = $conn->prepare("UPDATE submissions SET status = 'approved', updated_at = NOW() WHERE submission_id = ?");
        if (!$stmt_update_submission) {
            throw new Exception("Failed to prepare update submission statement: " . $conn->error);
        }
        $stmt_update_submission->bind_param("i", $submission_id);
        if (!$stmt_update_submission->execute()) {
            throw new Exception("Failed to update submission status: " . $stmt_update_submission->error);
        }
        $stmt_update_submission->close();

        // 2. Insert review details into the new `submission_reviews` table
        $stmt_insert_review = $conn->prepare(
            "INSERT INTO submission_reviews (submission_id, reviewer_id, is_special_award, remarks, evidence_link, indexing_body, incentives_amount, publisher)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt_insert_review) {
            throw new Exception("Failed to prepare insert review statement: " . $conn->error);
        }
        $stmt_insert_review->bind_param(
            "iiisssss", // Added 's' for evidence_link
            $submission_id,
            $reviewer_id,
            $is_special_award,
            $remarks,
            $evidence_link, // New parameter
            $indexing_body,
            $incentives_amount,
            $publisher
        );
        if (!$stmt_insert_review->execute()) {
            throw new Exception("Failed to insert review details: " . $stmt_insert_review->error);
        }
        $stmt_insert_review->close();

        // 3. Log the status change
        $stmt_log_status = $conn->prepare("INSERT INTO submission_status_logs (submission_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)");
        if (!$stmt_log_status) {
            throw new Exception("Failed to prepare log status statement: " . $conn->error);
        }
        // You might want to fetch the old status from the database before updating,
        // but for simplicity here, we assume it was 'accepted_by_external'.
        $old_status = 'accepted_by_external';
        $new_status = 'approved';
        $stmt_log_status->bind_param("issi", $submission_id, $old_status, $new_status, $reviewer_id);
        if (!$stmt_log_status->execute()) {
            throw new Exception("Failed to log status change: " . $stmt_log_status->error);
        }
        $stmt_log_status->close();

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Submission approved and review details saved.';

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Transaction failed: ' . $e->getMessage();
        error_log("Approve Submission Review Error: " . $e->getMessage());
    }
} else {
    $response['message'] = 'Invalid request method.';
}

ob_clean(); // Clean any buffered output before sending JSON
echo json_encode($response);
$conn->close();
?>
