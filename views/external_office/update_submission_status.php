<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php'; // Ensure this file handles database connection and error reporting

header('Content-Type: application/json'); // Indicate JSON response

$response = ['success' => false, 'message' => ''];

// Enable MySQLi error reporting for better debugging
// This should ideally be in connect.php, but adding here for direct impact
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Check if user is logged in and is an external_office
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
        throw new Exception('Unauthorized access.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submission_id = $_POST['submission_id'] ?? null;
        $new_status = trim($_POST['status'] ?? null); // Trim whitespace
        $external_office_campus_id = $_SESSION['campus_id'] ?? null;
        $current_user_id = $_SESSION['user_id'] ?? null; // Get the ID of the current logged-in user

        error_log("DEBUG: update_submission_status.php received POST. submission_id: {$submission_id}, new_status: '{$new_status}'");

        if (!$submission_id || !$new_status || !$external_office_campus_id || !$current_user_id) {
            throw new Exception('Missing required parameters or user session data.');
        }

        // Validate new_status against allowed values for external office
        $allowed_statuses = ['accepted_by_external', 'rejected', 'approved'];
        if (!in_array($new_status, $allowed_statuses)) {
            throw new Exception('Invalid status provided: ' . htmlspecialchars($new_status));
        }

        // Start transaction for atomicity
        $conn->begin_transaction();

        $sql = "";
        $types = "";
        $params = [];

        switch ($new_status) {
            case 'rejected':
                // For 'rejected' status, update status, updated_at, and rejected_by column
                $sql = "UPDATE submissions SET status = ?, updated_at = NOW(), rejected_by = ? WHERE submission_id = ? AND campus_id = ?";
                $types = "siii"; // s (status), i (rejected_by), i (submission_id), i (campus_id)
                $params = [$new_status, $current_user_id, $submission_id, $external_office_campus_id];
                break;
            case 'accepted_by_external':
            case 'approved':
                // For 'accepted_by_external' or 'approved', update status, updated_at, and set rejected_by to NULL
                $sql = "UPDATE submissions SET status = ?, updated_at = NOW(), rejected_by = NULL WHERE submission_id = ? AND campus_id = ?";
                $types = "sii"; // s (status), i (submission_id), i (campus_id)
                $params = [$new_status, $submission_id, $external_office_campus_id];
                break;
            default:
                throw new Exception("Unhandled status: " . htmlspecialchars($new_status));
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
        }

        // Dynamically bind parameters using call_user_func_array
        $bind_args = [$types];
        foreach ($params as &$param) {
            $bind_args[] = &$param;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_args);

        error_log("DEBUG: Executing SQL: " . $sql . " with types: " . $types . " and params: " . json_encode($params));

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response['success'] = true;
                $response['message'] = 'Submission status updated successfully.';

                // Add notification for the researcher
                // Fetch submission details AND evidence_link (if exists) for notification message
                $stmt_sub_info = $conn->prepare("
                    SELECT s.researcher_id, s.reference_number, s.title, sr.evidence_link
                    FROM submissions s
                    LEFT JOIN submission_reviews sr ON s.submission_id = sr.submission_id
                    WHERE s.submission_id = ?
                ");
                if ($stmt_sub_info) {
                    $stmt_sub_info->bind_param("i", $submission_id);
                    $stmt_sub_info->execute();
                    $result_sub_info = $stmt_sub_info->get_result();
                    $sub_info = $result_sub_info->fetch_assoc();
                    $stmt_sub_info->close();

                    if ($sub_info) {
                        $researcher_id = $sub_info['researcher_id'];
                        $reference_number = $sub_info['reference_number'];
                        $submission_title = $sub_info['title'];
                        $evidence_link = $sub_info['evidence_link'] ?? null; // Get evidence link
                        $user_name = $_SESSION['name'] ?? 'External Office';

                        $notification_message = "";
                        $notification_link = "../researcher/track_submission.php?ref=" . urlencode($reference_number); // Default link
                        $notification_type = 'status_update'; // Default type

                        if ($new_status === 'accepted_by_external') {
                            // Concise message for "accepted by external"
                            $notification_message = htmlspecialchars($user_name) . " accepted your submission: \"**" . htmlspecialchars($submission_title) . "**\" (Ref: **{$reference_number}**).";
                        } elseif ($new_status === 'approved') {
                            // Message and type for "approved" status, indicating completion
                            $notification_type = 'submission_complete'; // Set type for special handling in notifications.php
                            $notification_message = "The submission process for your research: \"**" . htmlspecialchars($submission_title) . "**\" (Ref: **{$reference_number}**). is complete! You can view the evidence via the link provided.";
                            if ($evidence_link) {
                                $notification_link = htmlspecialchars($evidence_link); // Use evidence link for approved notification
                            }
                        } else {
                            // General status update message for other statuses like 'rejected'
                            $notification_message = htmlspecialchars($user_name) . " has " . htmlspecialchars(str_replace('_', ' ', $new_status)) . " your submission: \"**" . htmlspecialchars($submission_title) . "**\" (Ref: **{$reference_number}**).";
                        }

                        $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, submission_id) VALUES (?, ?, ?, ?, 0, ?)");
                        if ($stmt_notify) {
                            $stmt_notify->bind_param("isssi", $researcher_id, $notification_type, $notification_message, $notification_link, $submission_id);
                            if (!$stmt_notify->execute()) {
                                error_log("Failed to insert notification for researcher_id {$researcher_id}: " . $stmt_notify->error);
                            }
                            $stmt_notify->close();
                        } else {
                            error_log("Failed to prepare notification insert statement: " . $conn->error);
                        }
                    } else {
                        error_log("Submission info not found for notification for submission ID: " . $submission_id);
                    }
                } else {
                    error_log("Failed to prepare submission info statement for notification: " . $conn->error);
                }
            } else {
                $response['message'] = 'Submission not found or status already updated.';
            }
        } else {
            throw new Exception('Database execution error: ' . $stmt->error);
        }
        $stmt->close();
        $conn->commit(); // Commit transaction on success

    } else {
        throw new Exception('Invalid request method.');
    }
} catch (Exception $e) {
    if ($conn && $conn->in_transaction) {
        $conn->rollback(); // Rollback transaction on error
    }
    $response['message'] = 'Operation failed: ' . $e->getMessage();
    error_log("Update Status Error: " . $e->getMessage()); // Log the specific error
} finally {
    if ($conn) {
        $conn->close();
    }
    echo json_encode($response);
}
