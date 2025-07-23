<?php
// File: config/get_requirements.php
// This script dynamically fetches requirements for a given submission type (publication or innovation).

session_start(); // Start session to access user roles/permissions if needed
require_once '../config/connect.php'; // Adjust path as necessary

header('Content-Type: application/json'); // Ensure the response is JSON

$response = ['success' => false, 'message' => '', 'requirements' => []];

// Get parameters from the AJAX request
$type_id = filter_input(INPUT_GET, 'type_id', FILTER_VALIDATE_INT);
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING); // 'publication' or 'innovation'

if (!$type_id || empty($category)) {
    $response['message'] = 'Missing type ID or category parameter.';
    echo json_encode($response);
    exit();
}

$sql = "";
$params = [];
$types = "";

try {
    if ($category === 'publication') {
        // Fetch requirements for a publication type
        $sql = "SELECT
                    rm.requirement_id AS id,
                    rm.requirement_name AS name,
                    rm.description,
                    ptr.is_mandatory AS is_required,
                    ptr.order_sequence
                FROM
                    pub_type_requirements ptr
                JOIN
                    requirements_master rm ON ptr.requirement_id = rm.requirement_id
                WHERE
                    ptr.pub_type_id = ?
                ORDER BY
                    ptr.order_sequence ASC, rm.requirement_name ASC";
        $params = [$type_id];
        $types = "i";

    } elseif ($category === 'innovation') {
        // Fetch requirements for an innovation type
        $sql = "SELECT
                    rm.requirement_id AS id,
                    rm.requirement_name AS name,
                    rm.description,
                    itr.is_mandatory AS is_required,
                    itr.order_sequence
                FROM
                    inno_type_requirements itr
                JOIN
                    requirements_master rm ON itr.requirement_id = rm.requirement_id
                WHERE
                    itr.inno_type_id = ?
                ORDER BY
                    itr.order_sequence ASC, rm.requirement_name ASC";
        $params = [$type_id];
        $types = "i";
    } else {
        $response['message'] = 'Invalid submission category.';
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Convert is_required (TINYINT) to boolean for JavaScript
            $row['is_required'] = (bool)$row['is_required'];
            $response['requirements'][] = $row;
        }
        $response['success'] = true;
        $stmt->close();
    } else {
        $response['message'] = 'Database query preparation failed: ' . $conn->error;
        error_log("get_requirements.php SQL Prepare Error: " . $conn->error);
    }

} catch (Exception $e) {
    $response['message'] = 'Error fetching requirements: ' . $e->getMessage();
    error_log("get_requirements.php Exception: " . $e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response);
?>
