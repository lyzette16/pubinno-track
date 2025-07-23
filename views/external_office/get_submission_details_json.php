<?php
// File: controllers/external_office/get_submission_details_json.php

// Start output buffering to catch any accidental output
ob_start();

session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Set content type header BEFORE any output
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'data' => null];

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Unauthorized access.';
    // Clean any buffered output before sending JSON
    ob_clean();
    echo json_encode($response);
    exit();
}

if (isset($_GET['id'])) {
    $submission_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$submission_id) {
        $response['message'] = 'Invalid submission ID.';
        ob_clean(); // Clean any buffered output
        echo json_encode($response);
        exit();
    }

    // Fetch submission details including submission_type
    $stmt = $conn->prepare("SELECT submission_id, submission_type, title, reference_number FROM submissions WHERE submission_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $submission = $result->fetch_assoc();
            $response['success'] = true;
            $response['data'] = $submission;
        } else {
            $response['message'] = 'Submission not found.';
        }
        $stmt->close();
    } else {
        $response['message'] = 'Database query preparation failed: ' . $conn->error;
    }
} else {
    $response['message'] = 'No submission ID provided.';
}

// Clean any buffered output before sending JSON
ob_clean();
echo json_encode($response);
$conn->close();
?>
