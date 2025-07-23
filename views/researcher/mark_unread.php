<?php
session_start();
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_id'])) {
    $id = (int) $_POST['notification_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 0 WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}
header("Location: notifications.php");
exit();
?>
