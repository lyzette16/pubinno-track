<?php
// File: views/researcher/my_submissions.php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

$researcher_id = $_SESSION['user_id'];
$submissions = [];

// Modified query to include 'status'
$stmt = $conn->prepare("SELECT reference_number, title, submission_type, status FROM submissions WHERE researcher_id = ? ORDER BY submission_id DESC");
$stmt->bind_param("i", $researcher_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $submissions[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Submissions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Segoe UI", sans-serif;
            background-color: #f5f7fb;
        }

        .main-wrapper {
            max-width: 1100px;
            margin: 60px auto;
            padding: 30px;
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.05);
        }

        h4 {
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 1.5rem;
        }

        .table thead {
            background-color: #0d6efd;
            color: #ffffff;
        }

        .table th, .table td {
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .table tbody tr:hover {
            background-color: #f0f4ff;
        }

        .btn-back {
            background-color: #6c757d;
            color: #fff;
            transition: background-color 0.2s ease;
        }

        .btn-back:hover {
            background-color: #5a6268;
        }

        .no-data {
            background-color: #eef2f7;
            padding: 2rem;
            border-radius: 0.5rem;
            text-align: center;
        }

        @media (max-width: 768px) {
            .main-wrapper {
                margin: 30px 15px;
                padding: 20px;
            }

            .table th, .table td {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>

<?php include '_navbar_researcher.php'; ?>

<div class="main-wrapper">
    <h4><i class="bi bi-folder2-open me-2 text-primary"></i>My Submissions</h4>

    <?php if (count($submissions) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Reference No.</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th> </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $sub): ?>
                        <tr>
                            <td>
                                <?php
                                if (!empty($sub['reference_number'])) {
                                    echo htmlspecialchars($sub['reference_number']);
                                } else {
                                    echo '<span class="text-muted">Waiting for facilitator to generate tracking number</span>';
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($sub['title']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($sub['submission_type'])) ?></td>
                            <td><?= htmlspecialchars(ucfirst($sub['status'])) ?></td> </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-data">
            <i class="bi bi-exclamation-circle-fill fs-3 text-muted mb-2"></i>
            <p class="mb-0">No submissions found.</p>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>