<?php
ob_start(); // Start output buffering

session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'message' => ''];

// 1. Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    $response['message'] = 'Unauthorized access. Please log in as a PIO.';
    ob_end_clean();
    echo json_encode($response);
    exit();
}

// 2. PIO Campus ID Check
$pio_campus_id = $_SESSION['campus_id'] ?? null;
if (!$pio_campus_id) {
    $response['message'] = 'PIO campus ID not set in session. Please re-login.';
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

    error_log("get_submission_details_pio.php: Fetching details for submission_id: " . $submission_id . " and pio_campus_id: " . $pio_campus_id);

    // 4. Database Query to Fetch Main Submission Details
    // Use LEFT JOIN for publication_types and innovation_types to include all submissions
    $stmt = $conn->prepare("
        SELECT
            s.submission_id,
            s.reference_number,
            s.title,
            s.submission_type,
            s.abstract AS description, -- Renamed to abstract to match common usage
            s.file_path,
            s.submission_date,
            s.status,
            s.other_researchers_names,
            d.name as department_name,
            u.name as researcher_name,
            c.campus_name as campus_name,
            pt.type_name AS pub_type_name,    -- Alias for publication type name
            it.type_name AS inno_type_name    -- Alias for innovation type name
        FROM
            submissions s
        JOIN
            users u ON s.researcher_id = u.user_id
        LEFT JOIN -- Changed to LEFT JOIN for publication_types
            publication_types pt ON s.pub_type_id = pt.pub_type_id
        LEFT JOIN -- Added LEFT JOIN for innovation_types
            innovation_types it ON s.inno_type_id = it.inno_type_id
        LEFT JOIN -- Changed to LEFT JOIN for departments
            departments d ON s.department_id = d.department_id
        LEFT JOIN -- Changed to LEFT JOIN for campus
            campus c ON s.campus_id = c.campus_id
        WHERE
            s.submission_id = ? AND s.campus_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $submission_id, $pio_campus_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $submission = $result->fetch_assoc();
            $submission['submission_date'] = date("F j, Y g:i A", strtotime($submission['submission_date']));

            // Determine the correct submission type name for display
            if ($submission['submission_type'] === 'publication' && !empty($submission['pub_type_name'])) {
                $submission['submission_type_name'] = $submission['pub_type_name'];
            } elseif ($submission['submission_type'] === 'innovation' && !empty($submission['inno_type_name'])) {
                $submission['submission_type_name'] = $submission['inno_type_name'];
            } else {
                $submission['submission_type_name'] = 'N/A'; // Fallback
            }

            $response['success'] = true;
            $response['submission'] = $submission;
            error_log("get_submission_details_pio.php: Submission details fetched successfully for ID " . $submission_id);

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
                $response['submission_files'] = $submission_files;
                error_log("get_submission_details_pio.php: " . count($submission_files) . " associated files fetched for ID " . $submission_id);
            } else {
                error_log("Failed to prepare statement for fetching submission files in PIO details: " . $conn->error);
                $response['file_fetch_message'] = 'Error fetching associated files.';
            }

        } else {
            $response['message'] = 'Submission not found or you do not have access to this submission on your campus.';
            error_log("get_submission_details_pio.php: Submission ID " . $submission_id . " not found or unauthorized for campus " . $pio_campus_id);
        }
        $stmt->close();
    } else {
        $response['message'] = 'Database query failed to prepare: ' . $conn->error;
        error_log("SQL Prepare Error in get_submission_details_pio.php: " . $conn->error);
    }
} else {
    $response['message'] = 'No submission ID provided in the request.';
    error_log("get_submission_details_pio.php: No submission ID provided.");
}

ob_end_clean(); // Discard any output collected so far
echo json_encode($response);

if (isset($conn) && $conn) {
    $conn->close();
}
?>
