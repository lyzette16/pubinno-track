<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

if (!isset($_GET['action'], $_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$submission_id = (int)$_GET['id'];
$action = $_GET['action'];

// Determine new status based on action
if ($action === 'accept') {
    $new_status = 'accepted_by_facilitator';
} elseif ($action === 'reject') {
    $new_status = 'rejected';
} else {
    header("Location: dashboard.php");
    exit();
}

// Update the status
$stmt = $conn->prepare("UPDATE submissions SET status = ? WHERE submission_id = ?");
$stmt->bind_param("si", $new_status, $submission_id);
$stmt->execute();
$stmt->close();

header("Location: dashboard.php");
exit();
