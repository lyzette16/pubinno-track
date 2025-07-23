<?php
// File: actions/generate_reference_number.php
// This script generates a unique RIPECode tracking number for a submission
// and updates the submission status. It is called via AJAX from the facilitator dashboard.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// *** FIXED PATH HERE ***
require_once '../../../config/connect.php'; // Adjust path as necessary

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'reference_number' => null];

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure user is logged in and is a facilitator
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'facilitator') {
    $response['message'] = 'Unauthorized access. User not logged in or not a facilitator.';
    error_log("generate_reference_number.php: Unauthorized access attempt. Session user_id: " . ($_SESSION['user_id'] ?? 'N/A') . ", role: " . ($_SESSION['role'] ?? 'N/A'));
    echo json_encode($response);
    exit();
}

$facilitator_id = $_SESSION['user_id'];
$facilitator_department_id = $_SESSION['department_id'] ?? null;
$facilitator_campus_id = $_SESSION['campus_id'] ?? null;

error_log("generate_reference_number.php: Script started. Facilitator ID: {$facilitator_id}, Dept ID: {$facilitator_department_id}, Campus ID: {$facilitator_campus_id}");


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_id = filter_var($_POST['submission_id'] ?? null, FILTER_VALIDATE_INT);
    $selected_college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT);
    $selected_program_id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
    $selected_project_id = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);

    error_log("generate_reference_number.php: POST data - submission_id: {$submission_id}, college_id: {$selected_college_id}, program_id: {$selected_program_id}, project_id: {$selected_project_id}");


    if (!$submission_id) {
        $response['message'] = 'Missing submission ID.';
        error_log("generate_reference_number.php: Missing submission ID in POST data.");
        echo json_encode($response);
        exit();
    }

    // Start transaction for atomicity
    $conn->begin_transaction();

    try {
        // 1. Fetch submission details and related codes for RIPECode generation
        // Ensure that the submission belongs to the facilitator's department and campus
        $stmt = $conn->prepare("
            SELECT
                s.submission_id,
                s.reference_number,
                s.status,
                s.submission_date,
                s.submission_type, -- 'research', 'innovation', 'publication', 'extension'
                s.campus_id,
                s.department_id,
                c.unit_code, -- from campus table
                -- We are now fetching college_code, program_code, project_code based on selected IDs, not necessarily from submission's initial joins
                s.researcher_id -- To send notification
            FROM
                submissions s
            LEFT JOIN
                campus c ON s.campus_id = c.campus_id
            WHERE
                s.submission_id = ?
                AND s.reference_number IS NULL -- Ensure no reference number exists yet
                AND s.status = 'submitted' -- Only generate for new submissions to facilitator
                AND s.department_id = ?
                AND s.campus_id = ?
        ");

        if (!$stmt) {
            throw new Exception("Prepare statement for fetching submission details failed: " . $conn->error);
        }

        $stmt->bind_param("iii",
            $submission_id,
            $facilitator_department_id,
            $facilitator_campus_id
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $submission_data = $result->fetch_assoc();
        $stmt->close();

        if (!$submission_data) {
            $response['message'] = 'Submission not found, already has a reference number, is not awaiting facilitator action, or you do not have permission.';
            error_log("generate_reference_number.php: " . $response['message'] . " (Submission ID: {$submission_id}, Dept ID: {$facilitator_department_id}, Campus ID: {$facilitator_campus_id})");
            throw new Exception($response['message']); // Throw to rollback
        }
        error_log("generate_reference_number.php: Submission data fetched: " . json_encode($submission_data));

        // Fetch codes for selected college, program, project explicitly if they were chosen
        $college_code = '00';
        if ($selected_college_id) {
            $stmt_college = $conn->prepare("SELECT college_code FROM colleges WHERE college_id = ?");
            if ($stmt_college) {
                $stmt_college->bind_param("i", $selected_college_id);
                $stmt_college->execute();
                $res_college = $stmt_college->get_result();
                if ($row_college = $res_college->fetch_assoc()) {
                    $college_code = $row_college['college_code'];
                }
                $stmt_college->close();
            } else {
                error_log("generate_reference_number.php: Failed to prepare statement for fetching college code: " . $conn->error);
            }
        }
        error_log("generate_reference_number.php: College Code: " . $college_code);


        $program_code = '00';
        if ($selected_program_id && $selected_program_id !== 0) { // Only fetch if a specific program is selected
            $stmt_program = $conn->prepare("SELECT program_code FROM programs WHERE program_id = ?");
            if ($stmt_program) {
                $stmt_program->bind_param("i", $selected_program_id);
                $stmt_program->execute();
                $res_program = $stmt_program->get_result();
                if ($row_program = $res_program->fetch_assoc()) {
                    $program_code = $row_program['program_code'];
                }
                $stmt_program->close();
            } else {
                error_log("generate_reference_number.php: Failed to prepare statement for fetching program code: " . $conn->error);
            }
        }
        error_log("generate_reference_number.php: Program Code: " . $program_code);


        $project_code = '00';
        if ($selected_project_id && $selected_project_id !== 0) { // Only fetch if a specific project is selected
            $stmt_project = $conn->prepare("SELECT project_code FROM projects WHERE project_id = ?");
            if ($stmt_project) {
                $stmt_project->bind_param("i", $selected_project_id);
                $stmt_project->execute();
                $res_project = $stmt_project->get_result();
                if ($row_project = $res_project->fetch_assoc()) {
                    $project_code = $row_project['project_code'];
                }
                $stmt_project->close();
            } else {
                error_log("generate_reference_number.php: Failed to prepare statement for fetching project code: " . $conn->error);
            }
        }
        error_log("generate_reference_number.php: Project Code: " . $project_code);


        // Extract components for RIPECode
        $ripe_code_char = '';
        switch (strtolower($submission_data['submission_type'])) {
            case 'research': $ripe_code_char = 'R'; break;
            case 'innovation': $ripe_code_char = 'I'; break;
            case 'publication': $ripe_code_char = 'P'; break;
            case 'extension': $ripe_code_char = 'E'; break;
            default: throw new Exception("Invalid submission type '{$submission_data['submission_type']}' for RIPECode generation.");
        }

        $year = date('Y', strtotime($submission_data['submission_date']));
        $unit_code = $submission_data['unit_code'] ?? '0'; // Default to '0' if campus unit_code not found
        error_log("generate_reference_number.php: RIPE components - Char: {$ripe_code_char}, Year: {$year}, Unit: {$unit_code}, College: {$college_code}, Program: {$program_code}, Project: {$project_code}");


        // 2. Get and increment the Study# from 'tracking_sequences' table
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle creation and incrementing
        $stmt_seq_upsert = $conn->prepare("
            INSERT INTO tracking_sequences
                (ripe_code_char, year, unit_code, college_code, program_code, project_code, last_study_number)
            VALUES (?, ?, ?, ?, ?, ?, 1) -- Set to 1 if new entry
            ON DUPLICATE KEY UPDATE
                last_study_number = last_study_number + 1,
                updated_at = NOW()
        ");
        if (!$stmt_seq_upsert) {
            throw new Exception("Prepare tracking sequence upsert statement failed: " . $conn->error);
        }
        $stmt_seq_upsert->bind_param("sissss",
            $ripe_code_char,
            $year,
            $unit_code,
            $college_code,
            $program_code,
            $project_code
        );
        if (!$stmt_seq_upsert->execute()) {
            throw new Exception("Execute tracking sequence upsert failed: " . $stmt_seq_upsert->error);
        }
        $stmt_seq_upsert->close();
        error_log("generate_reference_number.php: Tracking sequence upserted successfully.");


        // Now, retrieve the updated last_study_number
        $stmt_get_study_num = $conn->prepare("
            SELECT last_study_number FROM tracking_sequences
            WHERE ripe_code_char = ? AND year = ? AND unit_code = ? AND college_code = ? AND program_code = ? AND project_code = ?
        ");
        if (!$stmt_get_study_num) {
            throw new Exception("Prepare get study number statement failed: " . $conn->error);
        }
        $stmt_get_study_num->bind_param("sissss",
            $ripe_code_char,
            $year,
            $unit_code,
            $college_code,
            $program_code,
            $project_code
        );
        $stmt_get_study_num->execute();
        $stmt_get_study_num->bind_result($current_study_number);
        $stmt_get_study_num->fetch();
        $stmt_get_study_num->close();
        error_log("generate_reference_number.php: Retrieved current_study_number: " . $current_study_number);


        // Format Study# to 2 digits
        $study_number_formatted = sprintf("%02d", $current_study_number);

        // Construct the full RIPECode tracking number
        $new_reference_number = sprintf("%s-%s-%s-%s-%s-%s-%s",
            $ripe_code_char,
            $year,
            $unit_code,
            $college_code,
            $program_code,
            $project_code,
            $study_number_formatted
        );
        error_log("generate_reference_number.php: Generated new_reference_number: " . $new_reference_number);


        // 3. Update the submission with the new reference number, status, and selected program/project IDs
        $old_status = $submission_data['status'];
        $new_status = 'accepted_by_facilitator'; // Change status to indicate it's accepted by facilitator

        // Prepare program_id and project_id for database insertion (handle 0 or false as NULL if columns are nullable)
        // Assuming program_id and project_id in 'submissions' table are INT NULL
        $program_id_for_db = ($selected_program_id === false || $selected_program_id === 0) ? null : $selected_program_id;
        $project_id_for_db = ($selected_project_id === false || $selected_project_id === 0) ? null : $selected_project_id;

        $update_stmt = $conn->prepare("
            UPDATE submissions
            SET
                reference_number = ?,
                generated_by_facilitator_id = ?,
                status = ?,
                program_id = ?,
                project_id = ?,
                updated_at = NOW()
            WHERE
                submission_id = ?
        ");
        if (!$update_stmt) {
            throw new Exception("Prepare update submission statement failed: " . $conn->error);
        }
        // Bind parameters: s (ref), i (gen_by), s (status), i (program_id), i (project_id), i (submission_id)
        // Use 'i' for program_id_for_db and project_id_for_db, as bind_param handles NULL for 'i' type correctly.
        $update_stmt->bind_param("sissii",
            $new_reference_number,
            $facilitator_id,
            $new_status,
            $program_id_for_db, // Use the potentially null value
            $project_id_for_db, // Use the potentially null value
            $submission_id
        );
        if (!$update_stmt->execute()) {
            throw new Exception("Update submission failed: " . $update_stmt->error);
        }
        $update_stmt->close();
        error_log("generate_reference_number.php: Submission ID {$submission_id} updated with new reference number and status.");


        // 4. Log the status change
        // Removed 'remarks' column as per user request
        $log_stmt = $conn->prepare("INSERT INTO submission_status_logs (submission_id, changed_by, old_status, new_status, changed_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$log_stmt) {
            throw new Exception("Prepare log statement failed: " . $conn->error);
        }
        // Removed $remarks variable as it's no longer needed for the log
        $log_stmt->bind_param("iiss", $submission_id, $facilitator_id, $old_status, $new_status);
        if (!$log_stmt->execute()) {
            throw new Exception("Log status change failed: " . $log_stmt->error);
        }
        $log_stmt->close();
        error_log("generate_reference_number.php: Status change logged for submission ID {$submission_id}.");


        // 5. Send notification to the researcher
        $researcher_id = $submission_data['researcher_id'];
        $notification_message = "Your submission (Ref: **{$new_reference_number}**) has been **accepted** by the facilitator. You can now track its progress.";
        $notification_link = '../researcher/my_submissions.php'; // Link to researcher's tracking page
        $notification_type = 'status_update';

        $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, submission_id, created_at) VALUES (?, ?, ?, ?, 0, ?, NOW())");
        if (!$stmt_notify) {
            throw new Exception("Failed to prepare notification statement: " . $conn->error);
        }
        $stmt_notify->bind_param("isssi", $researcher_id, $notification_type, $notification_message, $notification_link, $submission_id);
        if (!$stmt_notify->execute()) {
            error_log("generate_reference_number.php: Failed to insert notification for researcher_id {$researcher_id}: " . $stmt_notify->error);
        }
        $stmt_notify->close();
        error_log("generate_reference_number.php: Notification sent to researcher ID {$researcher_id}.");


        $conn->commit(); // Commit the transaction
        $response['success'] = true;
        $response['message'] = 'Reference number generated and submission accepted successfully.';
        $response['reference_number'] = $new_reference_number;
        $_SESSION['message'] = "Reference number <strong>{$new_reference_number}</strong> generated and submission accepted!";
        $_SESSION['message_type'] = 'success';
        error_log("generate_reference_number.php: Transaction committed successfully.");

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        $response['message'] = "Failed to generate reference number: " . $e->getMessage();
        error_log("generate_reference_number.php error (ROLLBACK): " . $e->getMessage());
        $_SESSION['message'] = $response['message']; // Set for redirection
        $_SESSION['message_type'] = 'danger';
    }
} else {
    $response['message'] = 'Invalid request method.';
    error_log("generate_reference_number.php: Invalid request method. Expected POST.");
}

echo json_encode($response);
if (isset($conn) && $conn) {
    $conn->close();
    error_log("generate_reference_number.php: Database connection closed.");
}
?>
