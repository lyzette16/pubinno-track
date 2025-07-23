<?php
// Start output buffering. This will catch any unintended output (like spaces, newlines, errors)
// and prevent them from being sent to the browser before your JSON header.
ob_start();

session_start();
require_once '../../config/config.php'; // Adjust path if necessary
require_once '../../config/connect.php'; // Adjust path if necessary

// Ensure the response is JSON. This header MUST be sent before any other content.
header('Content-Type: application/json');

// Enable error reporting for this script as well during development.
// In a production environment, you might want to log errors without displaying them.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize response array
$response = ['success' => false, 'message' => ''];

// 1. Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    $response['message'] = 'Unauthorized access. Please log in as a facilitator.';
    // Before echoing JSON, ensure any buffered output is cleared.
    ob_end_clean();
    echo json_encode($response);
    exit();
}

// 2. Facilitator Department ID and Campus ID Check
$department_id = $_SESSION['department_id'] ?? null;
$campus_id = $_SESSION['campus_id'] ?? null; // Added campus_id check
if (!$department_id || !$campus_id) { // Ensure both are present
    $response['message'] = 'Facilitator department or campus ID not set in session. Please re-login.';
    ob_end_clean();
    echo json_encode($response);
    exit();
}

// 3. Validate Submission ID from GET request
if (isset($_GET['submission_id'])) {
    $submission_id = filter_var($_GET['submission_id'], FILTER_VALIDATE_INT);

    if ($submission_id === false) {
        $response['message'] = 'Invalid submission ID provided.';
        ob_end_clean();
        echo json_encode($response);
        exit();
    }

    // 4. Database Query to Fetch Main Submission Details
    // Joins with 'departments' to get department name (now explicitly using d.name)
    // Joins with 'users' to get the researcher's name (aliased as 'researcher_name' for consistency)
    // IMPORTANT: Added s.other_researchers_names to the SELECT statement
    $stmt = $conn->prepare("
        SELECT
            s.submission_id,
            s.reference_number,
            s.title,
            s.abstract, -- Added abstract to select as it's displayed in modal
            s.submission_type,
            s.description,
            s.file_path,
            s.submission_date,
            s.status,
            s.other_researchers_names, -- NEW: Added this column
            d.name as department_name,
            u.name as researcher_name
        FROM
            submissions s
        JOIN
            departments d ON s.department_id = d.department_id
        JOIN
            users u ON s.researcher_id = u.user_id
        WHERE
            s.submission_id = ? AND s.department_id = ? AND s.campus_id = ? -- Filter by campus_id as well
    ");

    if ($stmt) {
        $stmt->bind_param("iii", $submission_id, $department_id, $campus_id); // Added campus_id to bind_param
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $submission = $result->fetch_assoc();
            // Format submission_date for better readability in the modal
            $submission['submission_date'] = date("F j, Y g:i A", strtotime($submission['submission_date']));

            $response['success'] = true;
            $response['submission'] = $submission;

            // --- Fetch associated files for this submission ---
            $submission_files = [];
            $stmt_files = $conn->prepare("
                SELECT
                    sf.file_name,
                    sf.file_path,
                    rm.requirement_name AS requirement_name,
                    rm.description AS requirement_description
                FROM
                    submission_files sf
                JOIN
                    requirements_master rm ON sf.requirement_id = rm.requirement_id
                WHERE
                    sf.submission_id = ?
                ORDER BY
                    rm.requirement_name ASC
            ");
            if ($stmt_files) {
                $stmt_files->bind_param("i", $submission_id);
                $stmt_files->execute();
                $result_files = $stmt_files->get_result();
                while ($file = $result_files->fetch_assoc()) {
                    $submission_files[] = $file;
                }
                $stmt_files->close();
                $response['submission_files'] = $submission_files; // Add files to the response
            } else {
                error_log("Failed to prepare statement for fetching submission files: " . $conn->error);
                $response['file_fetch_message'] = 'Error fetching associated files.';
            }

        } else {
            $response['message'] = 'Submission not found or you do not have access to this submission in your department/campus.';
        }
        $stmt->close();
    } else {
        $response['message'] = 'Database query failed to prepare: ' . $conn->error;
        error_log("SQL Prepare Error in get_submission_details.php: " . $conn->error);
    }
} else {
    $response['message'] = 'No submission ID provided in the request.';
}

// 5. Final Output: Clear buffer and send JSON
ob_end_clean(); // Discard any output collected so far
echo json_encode($response);

// 6. Close Database Connection
if (isset($conn) && $conn) {
    $conn->close();
}
// IMPORTANT: No closing PHP tag here to prevent accidental whitespace.
