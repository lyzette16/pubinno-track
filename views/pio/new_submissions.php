<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

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


$requested_status = 'forwarded_to_pio';
$page_title = 'New Submissions (Forwarded by Facilitators)';


// Handle PIO actions: accept / reject / forward_external / approve
if (isset($_GET['action'], $_GET['id'])) {
    $submission_id = (int) $_GET['id'];
    $action = $_GET['action'];

    error_log("PIO Action triggered: " . $action . " for Submission ID: " . $submission_id);
    error_log("PIO Session - Campus ID: " . ($pio_campus_id ?? 'N/A'));

    // Get old status, researcher_id, and reference_number, filtered by PIO's campus
    $oldStmt = $conn->prepare("SELECT status, researcher_id, reference_number FROM submissions WHERE submission_id = ? AND campus_id = ?");
    if ($oldStmt) {
        $oldStmt->bind_param("ii", $submission_id, $pio_campus_id);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $submission_info = $oldResult->fetch_assoc();
        $old_status = $submission_info['status'] ?? null;
        $researcher_id = $submission_info['researcher_id'] ?? null;
        $reference_number = $submission_info['reference_number'] ?? null;
        $oldStmt->close();
        error_log("Old status retrieved for submission " . $submission_id . ": " . ($old_status ?? 'N/A'));
    } else {
        error_log("Failed to prepare statement for fetching old status in PIO actions: " . $conn->error);
        $_SESSION['message'] = 'Database error during action preparation.';
        $_SESSION['message_type'] = 'danger';
        header("Location: new_submissions.php"); // Redirect to this specific page
        exit();
    }

    // Only proceed if old status was found (meaning submission exists and belongs to this campus)
    if ($old_status) {
        $new_status = '';
        $notification_message = '';
        $notification_link = '../researcher/my_submissions.php'; // Default link for researcher

        switch ($action) {
            case 'accept':
                $new_status = 'accepted_by_pio'; // Should not happen if already accepted_by_pio
                $notification_message = "Your submission (Ref: **" . htmlspecialchars($reference_number ?? 'N/A') . "**) has been **accepted** by the PIO.";
                $message = 'Submission accepted successfully!';
                $messageType = 'success';
                break;
            case 'reject':
                $new_status = 'rejected';
                $notification_message = "Your submission (Ref: **" . htmlspecialchars($reference_number ?? 'N/A') . "**) has been **rejected** by the PIO. See details for comments.";
                $message = 'Submission rejected.';
                $messageType = 'danger';
                break;
            // Removed 'forward_external' and 'approve' cases as per previous request
            default:
                $_SESSION['message'] = 'Invalid action requested.';
                $_SESSION['message_type'] = 'warning';
                header("Location: new_submissions.php"); // Redirect to this specific page
                exit();
        }

        // Only update if the status is actually changing
        if ($old_status !== $new_status) {
            $conn->begin_transaction();
            try {
                // Update submission status, filtered by campus
                $stmt_update = $conn->prepare("UPDATE submissions SET status = ?, updated_at = NOW() WHERE submission_id = ? AND campus_id = ?");
                if (!$stmt_update) {
                    throw new Exception("Failed to prepare update statement: " . $conn->error);
                }
                $stmt_update->bind_param("sii", $new_status, $submission_id, $pio_campus_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to update submission status: " . $stmt_update->error);
                }
                $stmt_update->close();
                error_log("Submission ID " . $submission_id . " status updated to: " . $new_status . " by PIO.");


                // Insert into logs
                $logStmt = $conn->prepare("INSERT INTO submission_status_logs (submission_id, changed_by, old_status, new_status, changed_at) VALUES (?, ?, ?, ?, NOW())");
                if (!$logStmt) {
                    throw new Exception("Failed to prepare log statement: " . $conn->error);
                }
                $logStmt->bind_param("iiss", $submission_id, $pio_id, $old_status, $new_status);
                if (!$logStmt->execute()) {
                    throw new Exception("Failed to log status change: " . $logStmt->error);
                }
                $logStmt->close();

                // Send notification to the researcher
                if ($researcher_id && !empty($notification_message)) {
                    $notification_type = 'status_update'; // Generic type for status changes
                    $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, submission_id) VALUES (?, ?, ?, ?, 0, ?)");
                    if (!$stmt_notify) {
                        throw new Exception("Failed to prepare notification statement: " . $conn->error);
                    }
                    $stmt_notify->bind_param("isssi", $researcher_id, $notification_type, $notification_message, $notification_link, $submission_id);
                    if (!$stmt_notify->execute()) {
                        error_log("Failed to insert notification for researcher_id {$researcher_id}: " . $stmt_notify->error);
                    }
                    $stmt_notify->close();
                }

                $conn->commit();
                $_SESSION['message'] = $message;
                $_SESSION['message_type'] = $messageType;
                header("Location: new_submissions.php"); // Redirect back to this specific page
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['message'] = "Error updating status: " . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
                error_log("PIO Submission Action Error: " . $e->getMessage());
                header("Location: new_submissions.php"); // Redirect back to this specific page
                exit();
            }
        } else {
            // Status did not actually change
            $_SESSION['message'] = 'Submission status is already ' . htmlspecialchars(str_replace('_', ' ', $new_status)) . '. No change made.';
            $_SESSION['message_type'] = 'info';
            header("Location: new_submissions.php"); // Redirect back to this specific page
            exit();
        }
    } else {
        $_SESSION['message'] = 'Submission not found or unauthorized for your campus.';
        $_SESSION['message_type'] = 'danger';
        header("Location: new_submissions.php"); // Redirect back to this specific page
        exit();
    }
}


// Fetch submissions for the current status, filtered by PIO's campus
$submissions = [];
if ($pio_campus_id) { // Ensure campus_id is available for the query
    error_log("PIO Fetching submissions for Campus ID: " . $pio_campus_id . ", Status: " . $requested_status);
    $sql_query = "
        SELECT
            s.*,
            u.name AS researcher_name,
            pt.type_name AS pub_type_name, -- Alias for clarity
            it.type_name AS inno_type_name, -- Alias for clarity
            d.name AS department_name,
            c.campus_name AS campus_name
        FROM
            submissions s
        JOIN
            users u ON s.researcher_id = u.user_id
        LEFT JOIN -- Changed to LEFT JOIN
            publication_types pt ON s.pub_type_id = pt.pub_type_id
        LEFT JOIN -- Added LEFT JOIN for innovation types
            innovation_types it ON s.inno_type_id = it.inno_type_id
        LEFT JOIN -- Changed to LEFT JOIN
            departments d ON s.department_id = d.department_id
        LEFT JOIN -- Changed to LEFT JOIN
            campus c ON s.campus_id = c.campus_id
        WHERE
            s.status = ? AND s.campus_id = ?
        ORDER BY
            s.submission_date DESC
    ";

    $stmt = $conn->prepare($sql_query);
    if ($stmt) {
        $stmt->bind_param("si", $requested_status, $pio_campus_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $num_rows = $result->num_rows;
        error_log("PIO Fetch query executed. Rows found: " . $num_rows);

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
        error_log("Failed to prepare statement for fetching PIO submissions: " . $conn->error);
        $message = "Database error fetching submissions.";
        $messageType = 'danger';
    }
} else {
    $message = 'PIO campus ID not set. Cannot fetch submissions.';
    $messageType = 'danger';
    error_log("PIO session missing campus_id.");
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
            background-color: #007bff; /* Changed to primary blue for consistency */
            border-bottom: 1px solid #0056b3;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .navbar-brand {
            color: white !important; /* Changed to white */
            font-weight: bold;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            letter-spacing: -0.5px;
        }
        .navbar-text {
            color: white !important; /* Changed to white */
            font-size: 0.98rem;
            font-weight: 500;
        }
        .btn-outline-dark {
            border-color: #fff; /* Changed to white */
            color: #fff; /* Changed to white */
            transition: all 0.3s ease;
        }
        .btn-outline-dark:hover {
            background-color: #fff; /* Changed to white */
            color: #007bff; /* Changed to primary blue */
            border-color: #fff;
        }
        .main-content-wrapper {
            display: flex; /* Make it a flex container */
            flex-direction: row; /* Arrange children in a row (default, but explicit) */
            flex-wrap: nowrap; /* Prevent wrapping onto the next line on larger screens */
            flex-grow: 1; /* Allow it to grow to fill vertical space */
            margin-top: 20px;
            padding: 0 15px; /* Add horizontal padding to the wrapper itself */
            box-sizing: border-box; /* Include padding/border in width calculation */
        }
        
        #main-content {
            flex-grow: 1; /* Allow main content to take up remaining space */
            flex-basis: 0; /* Important for flex-grow to work correctly with variable content */
            min-width: 0; /* Allow content to shrink if necessary without overflowing */
            padding-left: 15px; /* Add left padding to the main content */
        }
    
        @media (max-width: 768px) {
            .main-content-wrapper {
                flex-direction: column; /* Stack vertically on small screens */
                padding: 0 10px; /* Adjust padding for mobile */
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
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
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
                    <a href="#" id="acceptSubmissionBtn" class="btn btn-success d-none">Accept</a>
                    <a href="#" id="forwardToExternalBtn" class="btn btn-primary d-none">Forward to External</a>
                    <a href="#" id="approveSubmissionBtn" class="btn btn-success d-none">Approve</a>
                    <a href="#" id="rejectSubmissionBtn" class="btn btn-danger d-none">Reject</a>
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
        const acceptSubmissionBtn = document.getElementById('acceptSubmissionBtn');
        const forwardToExternalBtn = document.getElementById('forwardToExternalBtn');
        const approveSubmissionBtn = document.getElementById('approveSubmissionBtn');
        const rejectSubmissionBtn = document.getElementById('rejectSubmissionBtn');
        const addCommentBtn = document.getElementById('addCommentBtn');

        document.querySelectorAll('.view-submission-btn').forEach(button => {
            button.addEventListener('click', function() {
                const submissionId = this.dataset.submissionId;
                modalBody.innerHTML = '<p>Loading submission details...</p>'; // Show loading message

                // Hide all action buttons initially for a clean slate
                acceptSubmissionBtn.classList.add('d-none');
                forwardToExternalBtn.classList.add('d-none');
                approveSubmissionBtn.classList.add('d-none');
                rejectSubmissionBtn.classList.add('d-none');
                addCommentBtn.classList.add('d-none');

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
                            const currentFileName = '<?= basename($_SERVER['PHP_SELF']) ?>'; // Get the current file name

                            // All actions redirect back to the current specific page
                            const baseUrl = `${currentFileName}?id=${s.submission_id}`;

                            if (s.status === 'forwarded_to_pio') {
                                acceptSubmissionBtn.href = `${baseUrl}&action=accept`;
                                acceptSubmissionBtn.classList.remove('d-none');

                                rejectSubmissionBtn.href = `${baseUrl}&action=reject`;
                                rejectSubmissionBtn.classList.remove('d-none');

                                addCommentBtn.href = `add_comment_pio.php?submission_id=${s.submission_id}&return_page=${currentFileName}`;
                                addCommentBtn.classList.remove('d-none');
                            } else if (s.status === 'accepted_by_pio' || s.status === 'under_external_review') {
                                forwardToExternalBtn.href = `${baseUrl}&action=forward_external`;
                                forwardToExternalBtn.classList.remove('d-none');

                                approveSubmissionBtn.href = `${baseUrl}&action=approve`;
                                approveSubmissionBtn.classList.remove('d-none');

                                rejectSubmissionBtn.href = `${baseUrl}&action=reject`;
                                rejectSubmissionBtn.classList.remove('d-none');

                                addCommentBtn.href = `add_comment_pio.php?submission_id=${s.submission_id}&return_page=${currentFileName}`;
                                addCommentBtn.classList.remove('d-none');
                            } else if (s.status === 'forwarded_to_external') {
                                // PIO can still approve/reject even if forwarded, or add comments
                                approveSubmissionBtn.href = `${baseUrl}&action=approve`;
                                approveSubmissionBtn.classList.remove('d-none');

                                rejectSubmissionBtn.href = `${baseUrl}&action=reject`;
                                rejectSubmissionBtn.classList.remove('d-none');

                                addCommentBtn.href = `add_comment_pio.php?submission_id=${s.submission_id}&return_page=${currentFileName}`;
                                addCommentBtn.classList.remove('d-none');
                            } else {
                                // For 'approved' or 'rejected' status, only allow adding comments
                                addCommentBtn.href = `add_comment_pio.php?submission_id=${s.submission_id}&return_page=${currentFileName}`;
                                addCommentBtn.classList.remove('d-none');
                            }

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
if (isset($conn) && $conn) {
    $conn->close();
}
?>
