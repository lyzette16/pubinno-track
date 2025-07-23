<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$facilitator_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'] ?? null;
// NEW: Get facilitator's campus_id from the session
$campus_id = $_SESSION['campus_id'] ?? null; 

// Basic check if critical session data is missing
if (!$department_id || !$campus_id) {
    // Log error or redirect to a page indicating a setup issue
    error_log("Facilitator session missing department_id or campus_id for user_id: " . $facilitator_id);
    $_SESSION['message'] = "Your session data is incomplete. Please log in again.";
    $_SESSION['message_type'] = "danger";
    header("Location: ../../auth/logout.php"); // Or login page
    exit();
}

$message = ''; // Initialize message variable
$messageType = ''; // Initialize message type

// Check for and display session messages (e.g., if redirected from an action)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']); // Clear the message after displaying
    unset($_SESSION['message_type']);
}

// Data for Submission Status Chart
// ADDED 'Forwarded to External' to labels
$submissionStatusLabels = ['New', 'Accepted', 'Forwarded to PIO', 'Forwarded to External', 'Rejected'];
$submissionStatusData = [0, 0, 0, 0, 0]; // Initialize with zeros, matching the number of labels

if ($department_id && $campus_id) { // Ensure both are available for the query
    // Fetch counts for all relevant statuses for the chart, filtered by department AND campus
    $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM submissions WHERE department_id = ? AND campus_id = ? GROUP BY status");
    if ($stmt) {
        $stmt->bind_param("ii", $department_id, $campus_id); // Bind both department_id and campus_id
        $stmt->execute();
        $result = $stmt->get_result();

        $statusCounts = [];
        while ($row = $result->fetch_assoc()) {
            $statusCounts[$row['status']] = $row['count'];
        }
        $stmt->close();

        // Mapped statuses to labels (adjust database status names if they differ)
        $submissionStatusData[0] = $statusCounts['submitted'] ?? 0;
        $submissionStatusData[1] = $statusCounts['accepted_by_facilitator'] ?? $statusCounts['with_facilitator'] ?? 0; 
        $submissionStatusData[2] = $statusCounts['forwarded_to_pio'] ?? 0;
        $submissionStatusData[3] = $statusCounts['forwarded_to_external'] ?? 0; 
        $submissionStatusData[4] = $statusCounts['rejected'] ?? 0;
    } else {
        error_log("Failed to prepare statement for submission status: " . $conn->error);
    }
}


// Data for Submissions Per Month Line Chart
$submissionsPerMonth = [];
if ($department_id && $campus_id) { // Ensure both are available for the query
    // Filter monthly submissions by department AND campus
    $stmtMonth = $conn->prepare("SELECT DATE_FORMAT(submission_date, '%Y-%m') as month, COUNT(*) as count FROM submissions WHERE department_id = ? AND campus_id = ? GROUP BY month ORDER BY month ASC");
    if ($stmtMonth) {
        $stmtMonth->bind_param("ii", $department_id, $campus_id); // Bind both department_id and campus_id
        $stmtMonth->execute();
        $resultMonth = $stmtMonth->get_result();
        while ($row = $resultMonth->fetch_assoc()) {
            $submissionsPerMonth[$row['month']] = $row['count'];
        }
        $stmtMonth->close();
    } else {
        error_log("Failed to prepare statement for monthly submissions: " . $conn->error);
    }
}
$monthlyLabels = array_keys($submissionsPerMonth);
$monthlyData = array_values($submissionsPerMonth);

// Data for Submissions Per Department Bar Chart (Filtered by Facilitator's Campus)
$submissionsPerDepartment = [];
if ($campus_id) { // This chart is now campus-specific
    // FIX APPLIED HERE: Changed d.department_name to d.name AS department_name
    // And changed GROUP BY d.department_name to GROUP BY d.name
    $stmtDept = $conn->prepare("SELECT d.name AS department_name, COUNT(s.submission_id) as count FROM submissions s JOIN departments d ON s.department_id = d.department_id WHERE s.campus_id = ? GROUP BY d.name ORDER BY count DESC");
    if ($stmtDept) {
        $stmtDept->bind_param("i", $campus_id); // Filter by campus_id
        $stmtDept->execute();
        $resultDept = $stmtDept->get_result();
        while ($row = $resultDept->fetch_assoc()) {
            $submissionsPerDepartment[$row['department_name']] = $row['count'];
        }
        $stmtDept->close();
    } else {
        error_log("Failed to prepare statement for department submissions: " . $conn->error);
    }
}

$departmentLabels = array_keys($submissionsPerDepartment);
$departmentData = array_values($submissionsPerDepartment);


// Data for Recent Activity
$recentActivity = [];
if ($department_id && $campus_id) { // Ensure both are available for the query
    $stmtActivity = $conn->prepare("
        SELECT
            sl.submission_id,
            s.title,
            s.reference_number,
            sl.old_status,
            sl.new_status,
            u.name as changed_by_name,
            sl.changed_at as change_timestamp
        FROM
            submission_status_logs sl
        JOIN
            submissions s ON sl.submission_id = s.submission_id
        JOIN
            users u ON sl.changed_by = u.user_id
        WHERE
            s.department_id = ? AND s.campus_id = ? -- Filter by both department AND campus
        ORDER BY
            sl.changed_at DESC
        LIMIT 5
    ");
    if ($stmtActivity) {
        $stmtActivity->bind_param("ii", $department_id, $campus_id); // Bind both department_id and campus_id
        $stmtActivity->execute();
        $resultActivity = $stmtActivity->get_result();
        while ($row = $resultActivity->fetch_assoc()) {
            $recentActivity[] = $row;
        }
        $stmtActivity->close();
    } else {
        error_log("Failed to prepare statement for recent activity: " . $conn->error);
    }
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = $_GET['status'] ?? ''; // No status param on dashboard, but set for consistency

// DEBUGGING OUTPUT - View this in your browser's "View Page Source"
// Removed the redundant echo statements here as they would output HTML before the doctype.
// If you need debugging, consider using var_dump($variable); within specific PHP blocks.
?>
<!DOCTYPE html>
<html>
<head>
    <title>Facilitator Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .navbar {
            background-color: #007bff;
            color: white;
            flex-shrink: 0;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
        }
        #sidebar {
            width: 250px;
            flex-shrink: 0;
            background-color: #f8f9fa;
            padding: 15px;
            border-right: 1px solid #dee2e6;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 0.5rem;
            margin-right: 20px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        #main-content {
            flex-grow: 1;
            padding-right: 15px;
        }
        .chart-container, .recent-activity-container {
            width: 100%;
            margin-bottom: 2rem; /* Added margin-bottom for spacing in 2x2 grid */
            padding: 1rem;
            background-color: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            height: 400px; /* Fixed height for consistency */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .recent-activity-container {
            align-items: stretch; /* Allow content to stretch */
            text-align: left; /* Align text within */
            padding-top: 1.5rem; /* More padding at top for heading */
            overflow-y: auto; /* Enable scrolling if content exceeds height */
        }
        /* Recent Activity Specific Styles */
        .list-group-item small {
            font-size: 0.85em; /* Slightly smaller text for details */
        }
        .list-group-item .badge {
            font-size: 0.75em; /* Smaller badges */
            padding: 0.3em 0.6em;
        }
        /* Sidebar nav links style */
        #sidebar .nav-link {
            color: #495057;
            font-weight: 500;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 5px;
            transition: all 0.2s ease;
        }
        #sidebar .nav-link:hover {
            background-color: #e9ecef;
            color: #0056b3;
        }
        #sidebar .nav-link.active {
            background-color: #007bff;
            color: white;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 123, 255, 0.25);
        }
        /* Adjustments for smaller screens */
        @media (max-width: 768px) {
            .main-content-wrapper {
                flex-direction: column;
            }
            #sidebar {
                width: 100%;
                margin-right: 0;
                margin-bottom: 20px;
                position: static;
                border-right: none;
                border-bottom: 1px solid #dee2e6;
            }
            #sidebar .nav-pills {
                flex-direction: row !important;
                justify-content: center;
                flex-wrap: wrap;
            }
            #sidebar .nav-link {
                margin: 5px;
            }
            /* Make charts full width on small screens */
            .col-md-6 {
                width: 100%;
            }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">PubInno-track: Publication and Innovation Tracking System</a>
            <div class="d-flex ms-auto">
                <span class="navbar-text me-3 text-white">
                    Welcome, Facilitator (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
                    <?php if ($campus_id): ?>
                        (Campus ID: <?= htmlspecialchars($campus_id) ?>) <?php endif; ?>
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; // Include the sidebar ?>

        <div id="main-content">
            <h4 class="mb-4">Facilitator Dashboard Overview</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <?php if (!empty($submissionStatusData) && array_sum($submissionStatusData) > 0): ?>
                        <div class="chart-container">
                            <h5>Submission Status (Your Department & Campus)</h5>
                            <canvas id="statusBarChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info chart-container d-flex align-items-center justify-content-center">No submission status data to display chart for your department and campus.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6 mb-4">
                    <?php if (!empty($monthlyData)): ?>
                        <div class="chart-container">
                            <h5>Submissions Per Month (Your Department & Campus)</h5>
                            <canvas id="monthlySubmissionsChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info chart-container d-flex align-items-center justify-content-center">No monthly submission data to display chart for your department and campus.</div>
                    <?php endif; ?>
                </div>

          

                <div class="col-md-6 mb-4">
                    <?php if (!empty($departmentData)): ?>
                    
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info chart-container d-flex align-items-center justify-content-center">No department submission data to display chart for your campus.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Data for Submission Status Bar Chart
    const submissionStatusLabels = <?= json_encode($submissionStatusLabels); ?>;
    const submissionBackgroundColors = [
        'rgba(255, 193, 7, 0.7)',   // New (Warning - Yellow)
        'rgba(40, 167, 69, 0.7)',   // Accepted (Success - Green)
        'rgba(23, 162, 184, 0.7)',  // Forwarded to PIO (Info - Teal)
        'rgba(108, 117, 125, 0.7)', // Forwarded to External (Secondary - Grey)
        'rgba(220, 53, 69, 0.7)'    // Rejected (Danger - Red)
    ];
    const submissionBorderColors = [
        'rgba(255, 193, 7, 1)',
        'rgba(40, 167, 69, 1)',
        'rgba(23, 162, 184, 1)',
        'rgba(108, 117, 125, 1)',
        'rgba(220, 53, 69, 1)'
    ];

    // Get the PHP-generated data directly (it's already encoded)
    const submissionStatusData = <?= json_encode($submissionStatusData); ?>;
    const monthlyLabels = <?= json_encode($monthlyLabels); ?>;
    const monthlyData = <?= json_encode($monthlyData); ?>;
    const departmentLabels = <?= json_encode($departmentLabels); ?>;
    const departmentData = <?= json_encode($departmentData); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Charts only if their canvases exist and data is available
        // Submission Status Bar Chart
        const statusBarCtx = document.getElementById('statusBarChart');
        if (statusBarCtx && submissionStatusData.some(count => count > 0)) {
            new Chart(statusBarCtx, {
                type: 'bar',
                data: {
                    labels: submissionStatusLabels,
                    datasets: [{
                        label: 'Number of Submissions',
                        data: submissionStatusData,
                        backgroundColor: submissionBackgroundColors,
                        borderColor: submissionBorderColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { if (value % 1 === 0) { return value; } }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        // Monthly Submissions Line Chart
        const monthlySubmissionsCtx = document.getElementById('monthlySubmissionsChart');
        if (monthlySubmissionsCtx && monthlyData.length > 0) {
            new Chart(monthlySubmissionsCtx, {
                type: 'line',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Submissions Per Month',
                        data: monthlyData,
                        fill: false,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { if (value % 1 === 0) { return value; } }
                            }
                        }
                    }
                }
            });
        }

        // Department Submissions Bar Chart
        const departmentSubmissionsCtx = document.getElementById('departmentSubmissionsChart');
        if (departmentSubmissionsCtx && departmentData.length > 0) {
            new Chart(departmentSubmissionsCtx, {
                type: 'bar',
                data: {
                    labels: departmentLabels,
                    datasets: [{
                        label: 'Submissions Per Department',
                        data: departmentData,
                        backgroundColor: 'rgba(153, 102, 255, 0.7)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { if (value % 1 === 0) { return value; } }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    });
</script>
</body>
</html>