<?php
// File: views/facilitator/actions/get_ripe_codes.php
// This script provides data for dynamic dropdowns (colleges, programs, projects)
// and calculates the next study number for RIPECode generation preview.

// Start output buffering to prevent any stray output from breaking JSON
ob_start();
// Immediately clean any output buffer content that might exist before this script.
// This helps prevent accidental whitespace/newlines before the JSON header.
ob_end_clean(); 


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// *** IMPORTANT: Adjust path to connect.php based on your actual file structure ***
// Path from views/facilitator/actions/ to config/
// Go up one (from actions/ to facilitator/)
// Go up two (from facilitator/ to views/)
// Go up three (from views/ to project_root/)
// Then down into config/
require_once '../../../config/connect.php'; 

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => '',
    'colleges' => [],
    'programs' => [],
    'projects' => [],
    'next_study_number' => '01', // Default
    'ripe_code_preview' => ''
];

error_log("get_ripe_codes.php: Script started.");
error_log("get_ripe_codes.php: GET parameters received: " . json_encode($_GET));


// Ensure user is logged in (optional, but good practice for internal APIs)
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Unauthorized access. User not logged in.';
    error_log("get_ripe_codes.php: Unauthorized access. Session user_id not set.");
    // Clear any buffered output before sending JSON
    ob_clean(); 
    echo json_encode($response);
    exit();
}

$facilitator_campus_id = $_SESSION['campus_id'] ?? null;
$submission_id = filter_var($_GET['submission_id'] ?? null, FILTER_VALIDATE_INT);
$selected_college_id = filter_var($_GET['college_id'] ?? null, FILTER_VALIDATE_INT);
$selected_program_id = filter_var($_GET['program_id'] ?? null, FILTER_VALIDATE_INT);
$selected_project_id = filter_var($_GET['project_id'] ?? null, FILTER_VALIDATE_INT);

error_log("get_ripe_codes.php: Session facilitator_campus_id: " . ($facilitator_campus_id ?? 'NULL'));
error_log("get_ripe_codes.php: submission_id: " . ($submission_id ?? 'NULL'));
error_log("get_ripe_codes.php: selected_college_id: " . ($selected_college_id ?? 'NULL'));
error_log("get_ripe_codes.php: selected_program_id: " . ($selected_program_id ?? 'NULL'));
error_log("get_ripe_codes.php: selected_project_id: " . ($selected_project_id ?? 'NULL'));


try {
    // Fetch Colleges
    $stmt_colleges = $conn->prepare("SELECT college_id, college_name, college_code FROM colleges WHERE is_active = 1 ORDER BY college_name ASC");
    if ($stmt_colleges) {
        $stmt_colleges->execute();
        $result_colleges = $stmt_colleges->get_result();
        while ($row = $result_colleges->fetch_assoc()) {
            $response['colleges'][] = $row;
        }
        $stmt_colleges->close();
        error_log("get_ripe_codes.php: Colleges fetched successfully. Count: " . count($response['colleges']));
    } else {
        throw new Exception("Failed to prepare statement for fetching colleges: " . $conn->error);
    }

    // Fetch Programs (if college_id is provided)
    if ($selected_college_id) {
        $stmt_programs = $conn->prepare("SELECT program_id, program_name, program_code FROM programs WHERE college_id = ? AND is_active = 1 ORDER BY program_name ASC");
        if ($stmt_programs) {
            $stmt_programs->bind_param("i", $selected_college_id);
            $stmt_programs->execute();
            $result_programs = $stmt_programs->get_result();
            while ($row = $result_programs->fetch_assoc()) {
                $response['programs'][] = $row;
            }
            $stmt_programs->close();
            error_log("get_ripe_codes.php: Programs fetched successfully for college_id " . $selected_college_id . ". Count: " . count($response['programs']));
        } else {
            throw new Exception("Failed to prepare statement for fetching programs: " . $conn->error);
        }
    } else {
        error_log("get_ripe_codes.php: No selected_college_id, skipping program fetch.");
    }

    // Fetch Projects (if program_id is provided)
    if ($selected_program_id && $selected_program_id !== 0) { // Ensure it's a valid program ID, not '0' for "Not a Program"
        $stmt_projects = $conn->prepare("SELECT project_id, project_name, project_code FROM projects WHERE program_id = ? AND is_active = 1 ORDER BY project_name ASC");
        if ($stmt_projects) {
            $stmt_projects->bind_param("i", $selected_program_id);
            $stmt_projects->execute();
            $result_projects = $stmt_projects->get_result();
            while ($row = $result_projects->fetch_assoc()) {
                $response['projects'][] = $row;
            }
            $stmt_projects->close();
            error_log("get_ripe_codes.php: Projects fetched successfully for program_id " . $selected_program_id . ". Count: " . count($response['projects']));
        } else {
            throw new Exception("Failed to prepare statement for fetching projects: " . $conn->error);
        }
    } else {
        error_log("get_ripe_codes.php: No selected_program_id or program_id is 0, skipping project fetch.");
    }

    // Calculate next Study Number and RIPECode Preview
    if ($submission_id) {
        $stmt_submission = $conn->prepare("
            SELECT
                s.submission_type,
                s.submission_date,
                c.unit_code, 
                d.college_id 
            FROM
                submissions s
            LEFT JOIN
                campus c ON s.campus_id = c.campus_id
            LEFT JOIN
                departments d ON s.department_id = d.department_id
            WHERE
                s.submission_id = ? AND s.campus_id = ?
        ");
        if ($stmt_submission) {
            $stmt_submission->bind_param("ii", $submission_id, $facilitator_campus_id);
            $stmt_submission->execute();
            $result_submission = $stmt_submission->get_result();
            $submission_info = $result_submission->fetch_assoc();
            $stmt_submission->close();

            if ($submission_info) {
                error_log("get_ripe_codes.php: Submission info retrieved: " . json_encode($submission_info));
                $ripe_code_char = '';
                switch (strtolower($submission_info['submission_type'])) {
                    case 'research': $ripe_code_char = 'R'; break;
                    case 'innovation': $ripe_code_char = 'I'; break;
                    case 'publication': $ripe_code_char = 'P'; break;
                    case 'extension': $ripe_code_char = 'E'; break;
                    default: throw new Exception("Invalid submission type '{$submission_info['submission_type']}' for RIPECode generation.");
                }

                $year = date('Y', strtotime($submission_info['submission_date']));
                $unit_code = $submission_info['unit_code'] ?? '0'; 
                error_log("get_ripe_codes.php: RIPE components - char: {$ripe_code_char}, year: {$year}, unit: {$unit_code}");


                $college_code_for_ripe = '00';
                if ($selected_college_id) {
                    $stmt_college_code = $conn->prepare("SELECT college_code FROM colleges WHERE college_id = ?");
                    if ($stmt_college_code) {
                        $stmt_college_code->bind_param("i", $selected_college_id);
                        $stmt_college_code->execute();
                        $res_college_code = $stmt_college_code->get_result();
                        if ($row_college_code = $res_college_code->fetch_assoc()) {
                            $college_code_for_ripe = $row_college_code['college_code'];
                        }
                        $stmt_college_code->close();
                        error_log("get_ripe_codes.php: College code from selection: " . $college_code_for_ripe);
                    } else {
                        throw new Exception("Failed to prepare statement for fetching college code: " . $conn->error);
                    }
                } else if ($submission_info['college_id']) { // Fallback to submission's department's college if no selection
                    $stmt_college_code = $conn->prepare("SELECT college_code FROM colleges WHERE college_id = ?");
                    if ($stmt_college_code) {
                        $stmt_college_code->bind_param("i", $submission_info['college_id']);
                        $stmt_college_code->execute();
                        $res_college_code = $stmt_college_code->get_result();
                        if ($row_college_code = $res_college_code->fetch_assoc()) {
                            $college_code_for_ripe = $row_college_code['college_code'];
                        }
                        $stmt_college_code->close();
                        error_log("get_ripe_codes.php: College code from submission's department: " . $college_code_for_ripe);
                    } else {
                        throw new Exception("Failed to prepare statement for fetching fallback college code: " . $conn->error);
                    }
                } else {
                    error_log("get_ripe_codes.php: No selected college_id and no college_id in submission's department, using default '00'.");
                }


                $program_code_for_ripe = '00';
                if ($selected_program_id && $selected_program_id !== 0) { // Only fetch if a specific program is selected
                    $stmt_program_code = $conn->prepare("SELECT program_code FROM programs WHERE program_id = ?");
                    if ($stmt_program_code) {
                        $stmt_program_code->bind_param("i", $selected_program_id);
                        $stmt_program_code->execute();
                        $res_program_code = $stmt_program_code->get_result();
                        if ($row_program_code = $res_program_code->fetch_assoc()) {
                            $program_code_for_ripe = $row_program_code['program_code'];
                        }
                        $stmt_program_code->close();
                        error_log("get_ripe_codes.php: Program code from selection: " . $program_code_for_ripe);
                    } else {
                        throw new Exception("Failed to prepare statement for fetching program code: " . $conn->error);
                    }
                } else {
                    error_log("get_ripe_codes.php: No selected_program_id or program_id is 0, using default '00'.");
                }

                $project_code_for_ripe = '00';
                if ($selected_project_id && $selected_project_id !== 0) { // Only fetch if a specific project is selected
                    $stmt_project_code = $conn->prepare("SELECT project_code FROM projects WHERE project_id = ?");
                    if ($stmt_project_code) {
                        $stmt_project_code->bind_param("i", $selected_project_id);
                        $stmt_project_code->execute();
                        $res_project_code = $stmt_project_code->get_result();
                        if ($row_project_code = $res_project_code->fetch_assoc()) {
                            $project_code_for_ripe = $row_project_code['project_code'];
                        }
                        $stmt_project_code->close();
                        error_log("get_ripe_codes.php: Project code from selection: " . $project_code_for_ripe);
                    } else {
                        throw new Exception("Failed to prepare statement for fetching project code: " . $conn->error);
                    }
                } else {
                    error_log("get_ripe_codes.php: No selected_project_id or project_id is 0, using default '00'.");
                }
                
                error_log("get_ripe_codes.php: Final RIPE components for query - Unit: {$unit_code}, College: {$college_code_for_ripe}, Program: {$program_code_for_ripe}, Project: {$project_code_for_ripe}");

                // Query for the next study number (without incrementing)
                $stmt_study_num = $conn->prepare("
                    SELECT last_study_number FROM tracking_sequences
                    WHERE ripe_code_char = ? AND year = ? AND unit_code = ? AND college_code = ? AND program_code = ? AND project_code = ?
                ");
                if ($stmt_study_num) {
                    $stmt_study_num->bind_param("sissss",
                        $ripe_code_char,
                        $year,
                        $unit_code,
                        $college_code_for_ripe,
                        $program_code_for_ripe,
                        $project_code_for_ripe
                    );
                    $stmt_study_num->execute();
                    $stmt_study_num->bind_result($last_study_number);
                    $stmt_study_num->fetch();
                    $stmt_study_num->close();

                    $next_study_number = ($last_study_number ?? 0) + 1;
                    $response['next_study_number'] = sprintf("%02d", $next_study_number);
                    error_log("get_ripe_codes.php: Calculated next_study_number: " . $response['next_study_number']);

                    // Construct preview RIPECode
                    $response['ripe_code_preview'] = sprintf("%s-%s-%s-%s-%s-%s-%s",
                        $ripe_code_char,
                        $year,
                        $unit_code,
                        $college_code_for_ripe,
                        $program_code_for_ripe,
                        $project_code_for_ripe,
                        $response['next_study_number']
                    );
                    error_log("get_ripe_codes.php: Generated ripe_code_preview: " . $response['ripe_code_preview']);
                } else {
                    throw new Exception("Failed to prepare statement for fetching study number: " . $conn->error);
                }
            } else {
                throw new Exception("Submission details not found for ID: " . $submission_id . " or campus ID: " . $facilitator_campus_id);
            }
        } else {
            throw new Exception("Failed to prepare statement for fetching submission info: " . $conn->error);
        }
    } else {
        error_log("get_ripe_codes.php: No submission_id provided, skipping RIPE code generation.");
    }

    $response['success'] = true;

} catch (Exception $e) {
    $response['message'] = "Error fetching RIPE codes: " . $e->getMessage();
    error_log("get_ripe_codes.php error: " . $e->getMessage());
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
    // Clear any buffered output before sending JSON and exit
    ob_clean(); 
    echo json_encode($response);
    exit();
}
?>
