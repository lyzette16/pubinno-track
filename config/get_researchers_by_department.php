<?php
// File: config/get_researchers_by_department.php
require_once '../config/connect.php'; // Adjust path if necessary

header('Content-Type: application/json');

$response = ['success' => false, 'researchers' => [], 'message' => ''];

if (isset($_GET['department_id']) && isset($_GET['campus_id'])) {
    $department_id = (int)$_GET['department_id'];
    $campus_id = (int)$_GET['campus_id'];

    if ($department_id <= 0 || $campus_id <= 0) {
        $response['message'] = 'Invalid department or campus ID provided.';
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE role = 'researcher' AND department_id = ? AND campus_id = ? ORDER BY name ASC");

    if ($stmt) {
        $stmt->bind_param("ii", $department_id, $campus_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $researchers = [];
        while ($row = $result->fetch_assoc()) {
            $researchers[] = $row;
        }
        $stmt->close();
        $response['success'] = true;
        $response['researchers'] = $researchers;
    } else {
        $response['message'] = 'Database error preparing statement: ' . $conn->error;
        error_log("Database error in get_researchers_by_department.php: " . $conn->error);
    }
} else {
    $response['message'] = 'Department ID or Campus ID not provided.';
}

echo json_encode($response);
$conn->close();
?>