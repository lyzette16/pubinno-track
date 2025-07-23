<?php
// File: views/facilitator/view_submission.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a facilitator
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$facilitator_id = $_SESSION['user_id'];
$submission = null;
$errorMessage = "";
$successMessage = "";

// Get submission ID from URL
$submission_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$submission_id) {
    $errorMessage = "Submission ID is missing or invalid.";
} else {
    // Fetch submission details along with researcher, department, pub/inno type names
    $stmt = $conn->prepare("
        SELECT 
            s.*, 
            u.name AS researcher_name, 
            u.email AS researcher_email,
            d.name AS department_name, 
            c.campus_name,
            pt.type_name AS publication_type_name,
            it.type_name AS innovation_type_name
        FROM submissions s
        LEFT JOIN users u ON s.researcher_id = u.user_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN campus c ON s.campus_id = c.campus_id -- CORRECTED: Changed 'campuses' to 'campus'
        LEFT JOIN publication_types pt ON s.pub_type_id = pt.pub_type_id
        LEFT JOIN innovation_types it ON s.inno_type_id = it.inno_type_id
        WHERE s.submission_id = ? AND (s.department_id = ? OR ?) -- Allow facilitator to view submissions from their own department OR if facilitator_department_id is not set (admin scenario, though this is for facilitator view)
    ");

    if ($stmt) {
        // Assuming facilitators can only view submissions from their department.
        // If an admin/super-facilitator can view all, adjust the WHERE clause.
        // FIX: Assign the boolean result to a variable before passing to bind_param
        $allow_all_departments_flag = (int)!isset($_SESSION['department_id']);
        $stmt->bind_param("iii", $submission_id, $_SESSION['department_id'], $allow_all_departments_flag);
        $stmt->execute();
        $result = $stmt->get_result();
        $submission = $result->fetch_assoc();
        $stmt->close();

        if (!$submission) {
            $errorMessage = "Submission not found or you do not have permission to view it.";
        }
    } else {
        $errorMessage = "Database error fetching submission details: " . $conn->error;
        error_log("Database error in view_submission.php: " . $conn->error);
    }
}

// Check for and display session messages (e.g., after a successful reference number generation)
if (isset($_SESSION['message'])) {
    $successMessage = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = $_GET['status'] ?? ''; // Keep for sidebar active state if needed
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            background-color: #f0f2f5;
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
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #007bff;
            color: white;
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
            font-weight: bold;
        }
        .list-group-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1.25rem;
            border-color: #e9ecef;
        }
        .list-group-item strong {
            color: #34495e;
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
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; ?>

        <div id="main-content">
            <h4 class="mb-4">Submission Details</h4>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $successMessage ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif (!empty($errorMessage)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $errorMessage ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($submission): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        Submission ID: <?= htmlspecialchars($submission['submission_id']) ?>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item"><strong>Reference Number:</strong> 
                                <span id="refNumDisplay">
                                    <?= htmlspecialchars($submission['reference_number'] ?: 'N/A (Pending Generation)') ?>
                                </span>
                            </li>
                            <li class="list-group-item"><strong>Title:</strong> <?= htmlspecialchars($submission['title']) ?></li>
                            <li class="list-group-item"><strong>Abstract:</strong> <?= htmlspecialchars($submission['abstract']) ?></li>
                            <li class="list-group-item"><strong>Category:</strong> <?= htmlspecialchars(ucfirst($submission['submission_type'])) ?></li>
                            <li class="list-group-item"><strong>Type:</strong> 
                                <?= htmlspecialchars($submission['submission_type'] === 'publication' ? $submission['publication_type_name'] : $submission['innovation_type_name']) ?>
                            </li>
                            <li class="list-group-item"><strong>Main Researcher:</strong> <?= htmlspecialchars($submission['researcher_name']) ?> (<?= htmlspecialchars($submission['researcher_email']) ?>)</li>
                            <li class="list-group-item"><strong>Department:</strong> <?= htmlspecialchars($submission['department_name']) ?></li>
                            <li class="list-group-item"><strong>Campus:</strong> <?= htmlspecialchars($submission['campus_name']) ?></li>
                            <li class="list-group-item"><strong>Status:</strong> <span class="badge bg-primary"><?= htmlspecialchars(ucfirst($submission['status'])) ?></span></li>
                            <li class="list-group-item"><strong>Submission Date:</strong> <?= htmlspecialchars(date('M d, Y h:i A', strtotime($submission['submission_date']))) ?></li>
                            <?php if ($submission['generated_by_facilitator_id']): ?>
                                <?php
                                    // Fetch name of facilitator who generated it
                                    $gen_by_name = 'Unknown';
                                    $stmt_gen = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
                                    if ($stmt_gen) {
                                        $stmt_gen->bind_param("i", $submission['generated_by_facilitator_id']);
                                        $stmt_gen->execute();
                                        $res_gen = $stmt_gen->get_result();
                                        if ($row_gen = $res_gen->fetch_assoc()) {
                                            $gen_by_name = $row_gen['name'];
                                        }
                                        $stmt_gen->close();
                                    }
                                ?>
                                <li class="list-group-item"><strong>Generated By:</strong> <?= htmlspecialchars($gen_by_name) ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="card-footer text-end">
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                        <?php if (empty($submission['reference_number'])): ?>
                            <button id="generateRefBtn" class="btn btn-primary ms-2" 
                                data-submission-id="<?= htmlspecialchars($submission['submission_id']) ?>"
                                data-department-id="<?= htmlspecialchars($submission['department_id']) ?>">
                                Generate Reference Number
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Display submitted files -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        Attached Files
                    </div>
                    <div class="card-body">
                        <?php
                            $stmt_files = $conn->prepare("SELECT file_name, file_path FROM submission_files WHERE submission_id = ?");
                            if ($stmt_files) {
                                $stmt_files->bind_param("i", $submission_id);
                                $stmt_files->execute();
                                $files_result = $stmt_files->get_result();
                                if ($files_result->num_rows > 0) {
                                    echo '<ul class="list-group">';
                                    while ($file = $files_result->fetch_assoc()) {
                                        // Ensure file_path is relative if storing absolute paths, or adjust for public access
                                        $display_path = str_replace('../../uploads/', '../uploads/', $file['file_path']); // Adjust if your uploads directory is structured differently
                                        echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
                                        echo '<span>' . htmlspecialchars($file['file_name']) . '</span>';
                                        echo '<a href="' . htmlspecialchars($display_path) . '" target="_blank" class="btn btn-sm btn-outline-primary">View File <i class="bi bi-box-arrow-up-right"></i></a>';
                                        echo '</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<div class="alert alert-info">No additional files attached for this submission.</div>';
                                }
                                $stmt_files->close();
                            }
                        ?>
                    </div>
                </div>

            <?php else: ?>
                <div class="alert alert-danger">
                    <?= $errorMessage ?: "Unable to load submission details." ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const generateRefBtn = document.getElementById('generateRefBtn');
            const refNumDisplay = document.getElementById('refNumDisplay');
            const submissionId = generateRefBtn ? generateRefBtn.dataset.submissionId : null;
            const departmentId = generateRefBtn ? generateRefBtn.dataset.departmentId : null; // Get department ID

            if (generateRefBtn) {
                generateRefBtn.addEventListener('click', async () => {
                    if (confirm("Are you sure you want to generate a reference number for this submission? This action cannot be undone.")) {
                        generateRefBtn.disabled = true;
                        generateRefBtn.textContent = 'Generating...';

                        try {
                            const response = await fetch('../../actions/generate_reference_number.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: `submission_id=${submissionId}&department_id=${departmentId}` // Pass department_id
                            });

                            const data = await response.json();

                            if (data.success) {
                                refNumDisplay.textContent = data.reference_number;
                                generateRefBtn.remove(); // Remove the button on success
                                // Optionally show a success message
                                const alertDiv = document.createElement('div');
                                alertDiv.className = 'alert alert-success alert-dismissible fade show mt-3';
                                alertDiv.innerHTML = `Reference number generated: <strong>${data.reference_number}</strong>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
                                document.querySelector('#main-content').prepend(alertDiv);
                            } else {
                                alert(`Error generating reference number: ${data.message}`);
                                generateRefBtn.disabled = false;
                                generateRefBtn.textContent = 'Generate Reference Number';
                            }
                        } catch (error) {
                            console.error('Fetch error:', error);
                            alert('An unexpected error occurred. Please try again.');
                            generateRefBtn.disabled = false;
                            generateRefBtn.textContent = 'Generate Reference Number';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>
