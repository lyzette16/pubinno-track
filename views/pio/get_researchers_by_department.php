<?php
// Start output buffering to prevent accidental output before JSON header
ob_start();

session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'message' => '', 'researchers' => []];

// Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    $response['message'] = 'Unauthorized access. Please log in as a PIO.';
    ob_end_clean();
    echo json_encode($response);
    exit();
}

$pio_campus_id = $_SESSION['campus_id'] ?? null;

if (!$pio_campus_id) {
    $response['message'] = 'PIO campus ID not set in session.';
    ob_end_clean();
    echo json_encode($response);
    exit();
}

// Validate Department ID from GET request
$department_id = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);

if ($department_id === false || $department_id === null) {
    $response['message'] = 'Invalid or missing department ID.';
    ob_end_clean();
    echo json_encode($response);
    exit();
}

// Fetch researchers belonging to the specified department AND the PIO's campus
$stmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE role = 'researcher' AND department_id = ? AND campus_id = ? ORDER BY name ASC");

if ($stmt) {
    $stmt->bind_param("ii", $department_id, $pio_campus_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $response['researchers'][] = $row;
    }
    $response['success'] = true;
    $stmt->close();
} else {
    $response['message'] = 'Database query failed to prepare: ' . $conn->error;
    error_log("SQL Prepare Error in get_researchers_by_department.php: " . $conn->error);
}

ob_end_clean();
echo json_encode($response);

if (isset($conn) && $conn) {
    $conn->close();
}
// No closing PHP tag to prevent accidental whitespace.
