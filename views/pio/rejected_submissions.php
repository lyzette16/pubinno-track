<?php
// File: views/pio/rejected_submissions.php
session_start();
require_once '../../config/config.php';

// Attempt to connect to the database.
$conn = null;
try {
    require_once '../../config/connect.php'; // This file should establish $conn
    if (!isset($conn) || !$conn instanceof mysqli) {
        throw new Exception("Database connection failed to establish in connect.php.");
    }
} catch (Exception $e) {
    error_log("rejected_submissions.php: Database connection error: " . $e->getMessage());
    $_SESSION['message'] = 'Database connection error. Please try again later.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php"); // Redirect to a safe page
    exit();
}

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect if user is not logged in or not a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer'; // Get PIO's name/email for display
$pio_campus_id = $_SESSION['campus_id'] ?? null; // Retrieve PIO's campus_id from session

$message = ''; // Initialize message variable
$messageType = ''; // Initialize message type

// Check if PIO has a campus_id set
if (!$pio_campus_id) {
    $_SESSION['message'] = 'Your PIO account is not associated with a campus. Cannot manage submissions.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php"); // Redirect to dashboard if campus_id is missing
    exit();
}

// Check for and display session messages (e.g., if redirected from an action)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']); // Clear the message after displaying
    unset($_SESSION['message_type']);
}

// --- Specific status for this file ---
$requested_status = 'rejected';
$page_title = 'Rejected Submissions';

// No direct 'accept', 'forward_external', 'approve', 'reject' actions via GET from this page's table view.
// Actions are handled via the modal, which typically links to a separate action script or add_comment_pio.php.
// The previous action handling block is removed to avoid unintended state changes from this view.

// Fetch submissions for the current status, filtered by PIO's campus
$submissions = [];
$stmt = $conn->prepare("
    SELECT
        s.*,
        u.name AS researcher_name,
        pt.type_name AS pub_type_name,    -- Alias for publication type name
        it.type_name AS inno_type_name,    -- Alias for innovation type name
        d.name AS department_name,
        c.campus_name AS campus_name
    FROM
        submissions s
    JOIN
        users u ON s.researcher_id = u.user_id
    LEFT JOIN -- Use LEFT JOIN to include all submissions even if type is null
        publication_types pt ON s.pub_type_id = pt.pub_type_id
    LEFT JOIN -- Added LEFT JOIN for innovation types
        innovation_types it ON s.inno_type_id = it.inno_type_id
    LEFT JOIN
        departments d ON s.department_id = d.department_id
    LEFT JOIN
        campus c ON s.campus_id = c.campus_id
    WHERE
        s.status = ? AND s.campus_id = ?
    ORDER BY
        s.submission_date DESC
");

if ($stmt) {
    $stmt->bind_param("si", $requested_status, $pio_campus_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($submission = $result->fetch_assoc()) {
        // Determine the correct type name based on submission_type
        if ($submission['submission_type'] === 'publication' && !empty($submission['pub_type_name'])) {
            $submission['display_type_name'] = $submission['pub_type_name'];
        } elseif ($submission['submission_type'] === 'innovation' && !empty($submission['inno_type_name'])) {
            $submission['display_type_name'] = $submission['inno_type_name'];
        } else {
            $submission['display_type_name'] = 'N/A'; // Fallback if type is not set or names are missing
        }
        $submissions[] = $submission;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching PIO rejected submissions: " . $conn->error);
    $message = "Database error fetching submissions.";
    $messageType = 'danger';
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // No status param on this page, as it's dedicated to one status
?>
<!DOCTYPE html>
<html>
<head>
    <title>PIO Dashboard - <?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #f0f2f5;
        }
        .navbar {
            background-color: #007bff;
            border-bottom: 1px solid #0056b3;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            letter-spacing: -0.5px;
        }
        .navbar-text {
            color: white !important;
            font-size: 0.98rem;
            font-weight: 500;
        }
        .btn-outline-dark {
            border-color: #fff;
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-outline-dark:hover {
            background-color: #fff;
            color: #007bff;
            border-color: #fff;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
            padding: 0 15px;
            box-sizing: border-box;
        }
        #sidebar {
            width: 250px;
            flex-shrink: 0;
            background-color: #ffffff;
            padding: 15px;
            border-right: 1px solid #e0e0e0;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            border-radius: 0.5rem;
            margin-right: 20px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        #main-content {
            flex-grow: 1;
            flex-basis: 0;
            min-width: 0;
            padding-left: 15px;
        }
        #sidebar .nav-link {
            color: #343a40;
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
                padding: 0 10px;
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
            #main-content {
                padding-left: 0;
            }
        }
        /* Modal specific styles for details */
        #submissionDetailsModal .modal-body {
            padding: 20px;
        }
        #submissionDetailsModal .modal-body strong {
            display: inline-block;
            min-width: 120px; /* Align labels */
            margin-bottom: 5px;
        }
        #submissionDetailsModal .modal-body p {
            margin-bottom: 10px;
        }
        #submissionDetailsModal .modal-footer {
            justify-content: flex-start; /* Align buttons to the left */
        }
        .file-list-item {
            padding: 8px 0;
            border-bottom: 1px dashed #e9ecef;
        }
        .file-list-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body class="bg-light">

    <div class="container-fluid main-content-wrapper">
        <?php
        // Define $currentPage for sidebar highlighting
        $currentPage = basename($_SERVER['PHP_SELF']);
        include 'sidebar.php'; // Include the PIO sidebar
        ?>

        <div id="main-content">
            <h4 class="mb-4"><?= htmlspecialchars($page_title) ?></h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($submissions)): ?>
                <p class="text-muted">No <?= htmlspecialchars(strtolower($page_title)) ?> found for your campus.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Researcher</th>
                                <th>Department</th>
                                <th>Campus</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $s): ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['reference_number'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['title'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['display_type_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['researcher_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['campus_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars(date("F j, Y g:i A", strtotime($s['submission_date'] ?? 'now'))) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-info btn-sm view-submission-btn" data-bs-toggle="modal" data-bs-target="#submissionDetailsModal" data-submission-id="<?= htmlspecialchars($s['submission_id'] ?? '') ?>">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Submission Details Modal -->
    <div class="modal fade" id="submissionDetailsModal" tabindex="-1" aria-labelledby="submissionDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="submissionDetailsModalLabel">Submission Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Loading submission details...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <!-- For rejected submissions, only 'Add Comment' is typically available -->
                    <a href="#" id="addCommentBtn" class="btn btn-info d-none">Add Comment</a>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Submission Details Modal Logic ---
        const submissionDetailsModal = new bootstrap.Modal(document.getElementById('submissionDetailsModal'));
        const modalBody = document.querySelector('#submissionDetailsModal .modal-body');
        const addCommentBtn = document.getElementById('addCommentBtn'); // Only this button is relevant here

        document.querySelectorAll('.view-submission-btn').forEach(button => {
            button.addEventListener('click', function() {
                const submissionId = this.dataset.submissionId;
                modalBody.innerHTML = '<p>Loading submission details...</p>'; // Show loading message

                // Hide all action buttons initially for a clean slate
                addCommentBtn.classList.add('d-none'); // Hide Add Comment button

                // Fetch submission details via AJAX
                // This URL is specific for PIO and filters by campus_id
                fetch('get_submission_details_pio.php?submission_id=' + submissionId)
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => {
                                throw new Error(`HTTP error! Status: ${response.status}. Response: ${text}`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const s = data.submission;
                            const files = data.submission_files;

                            let filesHtml = '';
                            if (files && files.length > 0) {
                                filesHtml += '<h6 class="mt-4 mb-2">Attached Requirement Files:</h6>';
                                filesHtml += '<ul class="list-unstyled border rounded p-3 bg-light">';
                                files.forEach(file => {
                                    const fileName = file.file_path.split('/').pop();
                                    filesHtml += `
                                        <li class="file-list-item">
                                            <strong>${htmlspecialchars(file.requirement_name ?? 'N/A')}:</strong>
                                            <a href="../../uploads/${htmlspecialchars(fileName ?? 'N/A')}" target="_blank" class="ms-2">
                                                ${htmlspecialchars(file.file_name ?? 'N/A')} <i class="bi bi-box-arrow-up-right ms-1"></i>
                                            </a>
                                            <small class="text-muted d-block ps-3">${htmlspecialchars(file.requirement_description || 'No description provided.')}</small>
                                        </li>
                                    `;
                                });
                                filesHtml += '</ul>';
                            } else {
                                filesHtml = '<p class="text-muted mt-4">No additional requirement files uploaded for this submission.</p>';
                            }

                            modalBody.innerHTML = `
                                <p><strong>Reference No.:</strong> ${htmlspecialchars(s.reference_number ?? 'N/A')}</p>
                                <p><strong>Title:</strong> ${htmlspecialchars(s.title ?? 'N/A')}</p>
                                <p><strong>Type:</strong> ${htmlspecialchars(s.submission_type_name ?? 'N/A')}</p>
                                <p><strong>Department:</strong> ${htmlspecialchars(s.department_name ?? 'N/A')}</p>
                                <p><strong>Campus:</strong> ${htmlspecialchars(s.campus_name ?? 'N/A')}</p>
                                <p><strong>Main Researcher:</strong> ${htmlspecialchars(s.researcher_name ?? 'N/A')}</p>
                                ${s.other_researchers_names ? `<p><strong>Other Researchers:</strong> ${htmlspecialchars(s.other_researchers_names)}</p>` : ''}
                                <p><strong>Submission Date:</strong> ${htmlspecialchars(s.submission_date ?? 'N/A')}</p>
                                <p><strong>Status:</strong> <span class="badge bg-primary">${htmlspecialchars(s.status ? s.status.replace(/_/g, ' ') : 'N/A')}</span></p>
                                <p><strong>Abstract:</strong> ${htmlspecialchars(s.abstract ?? 'N/A')}</p>
                                ${s.file_path ? `<p><strong>Main Article File:</strong> <a href="../../uploads/${htmlspecialchars(s.file_path.split('/').pop() ?? 'N/A')}" target="_blank">View Main File</a></p>` : ''}
                                ${filesHtml}
                            `;

                            // Dynamically set button visibility and hrefs based on submission status
                            const currentFileName = '<?= basename($_SERVER['PHP_SELF']) ?>'; // rejected_submissions.php

                            // For rejected submissions, only show 'Add Comment'
                            addCommentBtn.href = `add_comment_pio.php?submission_id=${s.submission_id}&return_page=${currentFileName}`;
                            addCommentBtn.classList.remove('d-none');

                        } else {
                            modalBody.innerHTML = `<div class="alert alert-danger">Error loading submission details: ${htmlspecialchars(data.message || 'Unknown error from server.')}</div>`;
                            console.error('Server returned success: false', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        modalBody.innerHTML = `<div class="alert alert-danger">An error occurred while fetching details. Please check console for more info. <br> Error: ${htmlspecialchars(error.message)}</div>`;
                    });
            });
        });

        // Helper function for HTML escaping in JS for dynamic content
        function htmlspecialchars(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    });
</script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    try {
        $conn->close();
    } catch (Throwable $e) {
        error_log("rejected_submissions.php: Error closing MySQLi connection at end of script: " . $e->getMessage());
    }
} else {
    error_log("rejected_submissions.php: MySQLi connection object is not set or not a mysqli instance at end of script. Not attempting to close.");
}
?>
