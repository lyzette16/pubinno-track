<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = null;

$stmt = $conn->prepare("SELECT name, email, role, department_id, campus_id FROM users WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        if ($user_data['department_id']) {
            $stmt_dept = $conn->prepare("SELECT name FROM departments WHERE department_id = ?");
            $stmt_dept->bind_param("i", $user_data['department_id']);
            $stmt_dept->execute();
            $result_dept = $stmt_dept->get_result();
            $dept_name = $result_dept->fetch_assoc();
            $user_data['department_name'] = $dept_name['name'] ?? 'N/A';
            $stmt_dept->close();
        } else {
            $user_data['department_name'] = 'N/A';
        }
        if ($user_data['campus_id']) {
            $stmt_campus = $conn->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $stmt_campus->bind_param("i", $user_data['campus_id']);
            $stmt_campus->execute();
            $result_campus = $stmt_campus->get_result();
            $campus_name = $result_campus->fetch_assoc();
            $user_data['campus_name'] = $campus_name['campus_name'] ?? 'N/A';
            $stmt_campus->close();
        } else {
            $user_data['campus_name'] = 'N/A';
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - Researcher</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }
        .profile-container {
            max-width: 700px;
            margin: 40px auto;
            background: #fff;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }
        .profile-header {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        .profile-header h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .profile-info i {
            color: #6c757d;
            margin-right: 10px;
        }
        .profile-info .info-item {
            margin-bottom: 1.1rem;
            font-size: 1rem;
        }
        .badge-role {
            font-size: 0.85rem;
            background-color: #0d6efd;
        }
        .btn-back {
            margin-top: 30px;
        }
    </style>
</head>
<body>

<?php include '_navbar_researcher.php'; ?>

<div class="container">
    <div class="profile-container">
        <div class="profile-header d-flex justify-content-between align-items-center">
            <div>
                <h4>My Profile</h4>
                <p class="text-muted mb-0">Basic user information</p>
            </div>
            <span class="badge text-bg-primary badge-role">
                <i class="bi bi-person-badge-fill me-1"></i>
                <?= ucfirst($user_data['role'] ?? 'Researcher') ?>
            </span>
        </div>

        <?php if ($user_data): ?>
        <div class="profile-info">
            <div class="info-item"><i class="bi bi-person-fill"></i><strong>Name:</strong> <?= htmlspecialchars($user_data['name']) ?></div>
            <div class="info-item"><i class="bi bi-envelope-fill"></i><strong>Email:</strong> <?= htmlspecialchars($user_data['email']) ?></div>
            <div class="info-item"><i class="bi bi-building-fill"></i><strong>Department:</strong> <?= htmlspecialchars($user_data['department_name']) ?></div>
            <div class="info-item"><i class="bi bi-geo-alt-fill"></i><strong>Campus:</strong> <?= htmlspecialchars($user_data['campus_name']) ?></div>
            <!-- <div class="info-item"><i class="bi bi-hash"></i><strong>User ID:</strong> <?= htmlspecialchars($user_id) ?></div> -->
        </div>
        <?php else: ?>
        <div class="alert alert-warning text-center">Unable to fetch user details.</div>
        <?php endif; ?>

        <!-- <a href="dashboard.php" class="btn btn-secondary btn-back"><i class="bi bi-arrow-left"></i> Back to Dashboard</a> -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
if (isset($conn)) {
    $conn->close();
}
?>
