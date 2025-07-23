<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer';
$pio_campus_id = $_SESSION['campus_id'] ?? null;

// Count total submissions
$stmt = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE campus_id = ?");
$stmt->bind_param("i", $pio_campus_id);
$stmt->execute();
$stmt->bind_result($total_submissions);
$stmt->fetch();
$stmt->close();

// Count total users
$stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE campus_id = ?");
$stmt->bind_param("i", $pio_campus_id);
$stmt->execute();
$stmt->bind_result($total_users);
$stmt->fetch();
$stmt->close();

// Count total accepted, rejected, under review
$statusCounts = ['accepted_by_pio' => 0, 'rejected' => 0, 'under_external_review' => 0];
foreach ($statusCounts as $status => &$count) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE status = ? AND campus_id = ?");
    $stmt->bind_param("si", $status, $pio_campus_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
}
unset($count);

?>
<!DOCTYPE html>
<html>
<head>
    <title>PIO Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f9f9f9;
            font-size: 15px;
            color: #333;
        }
        .main-content-wrapper {
            display: flex;
            flex-direction: row;
            margin-top: 20px;
        }
        #main-content {
            flex-grow: 1;
            padding: 0 20px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.08);
        }
        .card-title {
            font-weight: 600;
        }
        .btn-outline-dark {
            border-color: #ccc;
            color: #444;
        }
        .btn-outline-dark:hover {
            background-color: #444;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container-fluid main-content-wrapper">
    <?php include 'sidebar.php'; ?>

    <div id="main-content">
        <h4 class="mb-4">Welcome, <?= htmlspecialchars($pio_name) ?>!</h4>

        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card p-3">
                    <h5 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>Total Submissions</h5>
                    <p class="fs-4 fw-bold"><?= $total_submissions ?></p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card p-3">
                    <h5 class="card-title"><i class="bi bi-person-lines-fill me-2"></i>Total Users</h5>
                    <p class="fs-4 fw-bold"><?= $total_users ?></p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card p-3">
                    <h5 class="card-title"><i class="bi bi-clipboard-data me-2"></i>Under External Review</h5>
                    <p class="fs-4 fw-bold"><?= $statusCounts['under_external_review'] ?></p>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card p-3">
                    <h6 class="mb-3">Submission Status Distribution</h6>
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card p-3">
                    <h6 class="mb-3">Comparison: Accepted vs Rejected</h6>
                    <canvas id="barChart"></canvas>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const statusChart = new Chart(document.getElementById('statusChart'), {
        type: 'pie',
        data: {
            labels: ['Accepted', 'Rejected', 'Under Review'],
            datasets: [{
                data: [<?= $statusCounts['accepted_by_pio'] ?>, <?= $statusCounts['rejected'] ?>, <?= $statusCounts['under_external_review'] ?>],
                backgroundColor: ['#198754', '#dc3545', '#0d6efd']
            }]
        },
        options: {
            responsive: true
        }
    });

    const barChart = new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: ['Accepted', 'Rejected'],
            datasets: [{
                label: 'Submissions',
                data: [<?= $statusCounts['accepted_by_pio'] ?>, <?= $statusCounts['rejected'] ?>],
                backgroundColor: ['#198754', '#dc3545']
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>
