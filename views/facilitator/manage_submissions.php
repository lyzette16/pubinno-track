<?php
// File: views/facilitator/manage_submissions.php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect if user is not logged in or not a facilitator
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$facilitator_id = $_SESSION['user_id'];
$facilitator_name = $_SESSION['username'] ?? 'Facilitator'; // Get facilitator's name for notification message
$facilitator_department_id = $_SESSION['department_id'] ?? null;
$facilitator_campus_id = $_SESSION['campus_id'] ?? null;

$message = ''; // Initialize message variable
$messageType = ''; // Initialize message type

// Check if facilitator has department_id and campus_id set
if (!$facilitator_department_id || !$facilitator_campus_id) {
    $_SESSION['message'] = 'Your facilitator account is not fully configured (missing department or campus). Cannot manage submissions.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php"); // Redirect to dashboard if configuration is missing
    exit();
}

// Check for and display session messages (e.g., if redirected from an action)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']); // Clear the message after displaying
    unset($_SESSION['message_type']);
}

// --- Status filter for this page ---
// ADDED 'forwarded_to_pio' to allowed statuses
$allowed_statuses = ['submitted', 'returned_for_revision', 'accepted_by_facilitator', 'forwarded_to_pio']; // Facilitator primarily manages these
$requested_status = $_GET['status'] ?? 'submitted'; // Default status to display

// Validate requested status
if (!in_array($requested_status, $allowed_statuses)) {
    $requested_status = 'submitted'; // Fallback to default if invalid status is requested
}

$page_title = 'New Submissions';
if ($requested_status === 'returned_for_revision') {
    $page_title = 'Submissions Returned for Revision';
} elseif ($requested_status === 'accepted_by_facilitator') {
    $page_title = 'Submissions Accepted by Facilitator'; // New title for accepted status
} elseif ($requested_status === 'forwarded_to_pio') {
    $page_title = 'Submissions Forwarded to PIO'; // New title for forwarded to PIO status
}


// Handle Facilitator actions: accept_with_ref, reject, forward_to_pio
// These actions are now primarily handled via AJAX in generate_reference_number.php for 'accept_with_ref'
// and direct GET requests for 'reject' and 'forward_to_pio'.
if (isset($_GET['action'], $_GET['id'])) {
    $submission_id = (int) $_GET['id'];
    $action = $_GET['action'];
    $new_reference_number = $_GET['reference_number'] ?? null; // For accept_with_reference

    error_log("Action triggered: " . $action . " for Submission ID: " . $submission_id);
    error_log("New Reference Number (if applicable): " . ($new_reference_number ?? 'N/A'));
    error_log("Facilitator Session - Department ID: " . ($facilitator_department_id ?? 'N/A') . ", Campus ID: " . ($facilitator_campus_id ?? 'N/A'));


    // Ensure department_id and campus_id are available for filtering actions
    if (!$facilitator_department_id || !$facilitator_campus_id) {
        $_SESSION['message'] = 'Facilitator session data incomplete. Cannot perform action.';
        $_SESSION['message_type'] = 'danger';
        header("Location: manage_submissions.php?status=" . urlencode($requested_status));
        exit();
    }

    // Get old status, researcher_id, and reference_number, filtered by department AND campus
    $oldStmt = $conn->prepare("SELECT status, researcher_id, reference_number, other_researchers_names FROM submissions WHERE submission_id = ? AND department_id = ? AND campus_id = ?");
    if ($oldStmt) {
        $oldStmt->bind_param("iii", $submission_id, $facilitator_department_id, $facilitator_campus_id);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $submission_info = $oldResult->fetch_assoc();
        $old_status = $submission_info['status'] ?? null;
        $researcher_id = $submission_info['researcher_id'] ?? null;
        $current_reference_number = $submission_info['reference_number'] ?? null; // Use a different var name
        $other_researchers_names = $submission_info['other_researchers_names'] ?? null;
        $oldStmt->close();
        error_log("Old status retrieved: " . ($old_status ?? 'N/A'));
    } else {
        error_log("Failed to prepare statement for fetching old status and researcher_id: " . $conn->error);
        $old_status = null;
        $researcher_id = null;
        $current_reference_number = null;
        $other_researchers_names = null;
    }

    // Only proceed if old status was found (meaning submission exists and belongs to this department AND campus)
    if ($old_status) {
        $new_status = '';
        $update_reference_query_part = '';
        $bind_types = ''; // Will be set dynamically
        $bind_params = []; // Will be populated with references

        if ($action === 'accept_with_reference') {
            if (empty($new_reference_number)) {
                $_SESSION['message'] = 'Reference number cannot be empty for acceptance.';
                $_SESSION['message_type'] = 'danger';
                header("Location: manage_submissions.php?status=" . urlencode($requested_status));
                exit();
            }
            // Status now becomes 'accepted_by_facilitator'
            $new_status = 'accepted_by_facilitator';
            $update_reference_query_part = ', reference_number = ?, generated_by_facilitator_id = ?';

            // Explicitly build bind_params with references for this action
            // Query placeholders: status=?, reference_number=?, generated_by_facilitator_id=?, submission_id=?, department_id=?, campus_id=?
            $bind_types = 'ssiiii'; // s (new_status), s (ref), i (gen_by), i (id), i (dept), i (campus)
            $bind_params = [
                &$new_status,           // 1st parameter for status = ?
                &$new_reference_number, // 2nd parameter for reference_number = ?
                &$facilitator_id,       // 3rd parameter for generated_by_facilitator_id = ?
                &$submission_id,        // 4th parameter for WHERE submission_id = ?
                &$facilitator_department_id,        // 5th parameter for AND department_id = ?
                &$facilitator_campus_id             // 6th parameter for AND campus_id = ?
            ];

            // Success message
            $message = 'Submission accepted and reference number assigned successfully! Status changed to Accepted by Facilitator.';
            $messageType = 'success';
        } elseif ($action === 'reject') {
            $new_status = 'rejected';
            $update_reference_query_part = ''; // No reference number update for reject
            $bind_types = 'siii'; // s (new_status), i (id), i (department_id), i (campus_id)
            $bind_params = [&$new_status, &$submission_id, &$facilitator_department_id, &$facilitator_campus_id];
            $message = 'Submission rejected.';
            $messageType = 'danger';
        } elseif ($action === 'forward_to_pio') {
            $new_status = 'forwarded_to_pio';
            $update_reference_query_part = ''; // No reference number update
            $bind_types = 'siii';
            $bind_params = [&$new_status, &$submission_id, &$facilitator_department_id, &$facilitator_campus_id];
            $message = 'Submission forwarded to PIO.';
            $messageType = 'info';
        }
        // 'revision_requested' action block removed
        else {
            // Invalid action, redirect back to current page
            $_SESSION['message'] = 'Invalid action requested.';
            $_SESSION['message_type'] = 'warning';
            header("Location: manage_submissions.php?status=" . urlencode($requested_status));
            exit();
        }

        // Only update if the status is actually changing OR if it's an accept_with_reference action and reference_number needs update
        if ($old_status !== $new_status || ($action === 'accept_with_reference' && $current_reference_number !== $new_reference_number)) {
            // Build the update query dynamically
            $update_query = "UPDATE submissions SET status = ?, updated_at = NOW() {$update_reference_query_part} WHERE submission_id = ? AND department_id = ? AND campus_id = ?";

            $stmt = $conn->prepare($update_query);

            if ($stmt) {
                // Manually bind parameters based on $bind_params array
                // The first argument to call_user_func_array must be by reference
                call_user_func_array([$stmt, 'bind_param'], array_merge([$bind_types], $bind_params));

                if ($stmt->execute()) {
                    error_log("Submission ID " . $submission_id . " status updated to: " . $new_status);
                    error_log("Update query executed successfully.");
                    // Insert into logs
                    $logStmt = $conn->prepare("INSERT INTO submission_status_logs (submission_id, changed_by, old_status, new_status, changed_at) VALUES (?, ?, ?, ?, NOW())");
                    if ($logStmt) {
                        $logStmt->bind_param("iiss", $submission_id, $facilitator_id, $old_status, $new_status);
                        $logStmt->execute();
                        $logStmt->close();
                    } else {
                        error_log("Failed to prepare statement for logging status change: " . $conn->error);
                    }

                    // --- START NOTIFICATION LOGIC ---
                    try {
                        if ($researcher_id && $researcher_id !== $facilitator_id) {
                            $notification_link = '../researcher/my_submissions.php';
                            $notification_message = '';

                            // Use $new_reference_number if available, otherwise fall back to $current_reference_number
                            $display_reference_number = $new_reference_number ?: $current_reference_number;

                            switch ($new_status) {
                                case 'accepted_by_facilitator': // Updated case for notification
                                    $notification_message = "Your submission (Ref: **" . htmlspecialchars($display_reference_number) . "**) has been **accepted** by the facilitator and assigned a reference number.";
                                    break;
                                case 'rejected':
                                    $notification_message = "Your submission (Ref: **" . htmlspecialchars($current_reference_number ?: 'N/A') . "**) has been **rejected** by the facilitator. See details for comments.";
                                    break;
                                case 'forwarded_to_pio':
                                    $notification_message = "Your submission (Ref: **" . htmlspecialchars($current_reference_number ?: 'N/A') . "**) has been **forwarded to the PIO** for review.";
                                    break;
                                case 'forwarded_to_external':
                                    $notification_message = "Your submission (Ref: **" . htmlspecialchars($current_reference_number ?: 'N/A') . "**) has been **forwarded to external evaluators**.";
                                    break;
                                // 'revision_requested' notification case removed
                                case 'pending_review': // This case is now less likely to be hit directly by action, but kept for completeness
                                    $notification_message = "Your submission (Ref: **" . htmlspecialchars($display_reference_number) . "**) has been assigned a reference number and is now **pending review** by the facilitator.";
                                    break;
                                default:
                                    $notification_message = "The status of your submission (Ref: **" . htmlspecialchars($current_reference_number ?: 'N/A') . "**) has been updated to **" . htmlspecialchars(str_replace('_', ' ', $new_status)) . "**.";
                                    break;
                            }

                            $insert_notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, submission_id, message, link, is_read) VALUES (?, ?, ?, ?, 0)");
                            if ($insert_notification_stmt) {
                                $insert_notification_stmt->bind_param("iiss", $researcher_id, $submission_id, $notification_message, $notification_link);
                                if (!$insert_notification_stmt->execute()) {
                                    error_log("Failed to insert status change notification: " . $insert_notification_stmt->error);
                                }
                                $insert_notification_stmt->close();
                            } else {
                                error_log("Failed to prepare notification insert statement for status change: " . $conn->error);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error during notification insertion for submission ID {$submission_id}: " . $e->getMessage());
                    }
                    // --- END NOTIFICATION LOGIC ---

                    $_SESSION['message'] = $message;
                    $_SESSION['message_type'] = $messageType;
                    header("Location: manage_submissions.php?status=" . urlencode($new_status));
                    exit();
                } else {
                    error_log("Failed to update submission status: " . $stmt->error);
                    $_SESSION['message'] = 'Failed to update submission status: ' . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                    header("Location: manage_submissions.php?status=" . urlencode($requested_status));
                    exit();
                }
                $stmt->close();
            } else {
                error_log("Failed to prepare statement for updating submission status: " . $conn->error);
                $_SESSION['message'] = 'Database error during status update.';
                $_SESSION['message_type'] = 'danger';
                header("Location: manage_submissions.php?status=" . urlencode($requested_status));
                exit();
            }
        } else {
            // Status did not actually change
            $_SESSION['message'] = 'Submission status is already ' . htmlspecialchars(str_replace('_', ' ', $new_status)) . '. No change made.';
            $_SESSION['message_type'] = 'info';
            header("Location: manage_submissions.php?status=" . urlencode($requested_status));
            exit();
        }
    } else {
        $_SESSION['message'] = 'Submission not found or unauthorized.';
        $_SESSION['message_type'] = 'danger';
        header("Location: manage_submissions.php?status=" . urlencode($requested_status));
        exit();
    }
}


// Fetch submissions for the current status, filtered by department AND campus
$submissions = [];
if ($facilitator_department_id && $facilitator_campus_id) { // Ensure both are available for the query
    error_log("Fetching submissions for Department ID: " . $facilitator_department_id . ", Campus ID: " . $facilitator_campus_id . ", Status: " . $requested_status);
    // MODIFIED SQL QUERY: Use LEFT JOIN for both publication_types and innovation_types
    $sql_query = "SELECT
                    s.*,
                    u.name AS researcher_name,
                    pt.type_name AS pub_type_name,
                    it.type_name AS inno_type_name
                  FROM
                    submissions s
                  JOIN
                    users u ON s.researcher_id = u.user_id
                  LEFT JOIN
                    publication_types pt ON s.pub_type_id = pt.pub_type_id
                  LEFT JOIN
                    innovation_types it ON s.inno_type_id = it.inno_type_id
                  WHERE
                    s.department_id = ?
                    AND s.status = ?
                    AND s.campus_id = ?
                  ORDER BY s.submission_date DESC";

    $stmt = $conn->prepare($sql_query);
    if ($stmt) {
        $stmt->bind_param("isi", $facilitator_department_id, $requested_status, $facilitator_campus_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $num_rows = $result->num_rows;
        error_log("Fetch query executed. Rows found: " . $num_rows);

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
        error_log("Failed to prepare statement for fetching submissions: " . $conn->error);
        // Optionally set a user-friendly error message
        $message = 'Database error: Could not fetch submissions.';
        $messageType = 'danger';
    }
} else {
    // Handle cases where department_id or campus_id are not set in session
    $message = 'Facilitator session data incomplete. Cannot fetch submissions.';
    $messageType = 'danger';
    error_log("Facilitator session missing department_id or campus_id.");
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facilitator Dashboard - <?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <style>
        body {
            font-family: -apple-system, BlinkMacMacFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
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
        /* Select2 Custom Styles to match Bootstrap 5 */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem !important; /* Match Bootstrap's form-control border-radius */
            min-height: calc(1.5em + 0.75rem + 2px) !important; /* Match Bootstrap's form-control height */
            padding: 0.375rem 0.75rem !important; /* Match Bootstrap's form-control padding */
            font-size: 1rem !important; /* Match Bootstrap's form-control font-size */
            line-height: 1.5 !important; /* Match Bootstrap's form-control line-height */
            border-color: #ced4da !important; /* Default border color */
        }
        .select2-container--bootstrap-5.select2-container--focus .select2-selection,
        .select2-container--bootstrap-5.select2-container--open .select2-selection {
            border-color: #86b7fe !important; /* Focus border color */
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important; /* Focus shadow */
        }
        .select2-container--bootstrap-5 .select2-dropdown {
            border-color: #ced4da !important;
            border-radius: 0.375rem !important;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        .select2-container--bootstrap-5 .select2-search__field {
            border-radius: 0.375rem !important;
            border-color: #ced4da !important;
        }
        .select2-container--bootstrap-5 .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #0d6efd !important; /* Highlight background */
            color: white !important; /* Highlight text color */
        }
        .select2-container--bootstrap-5 .select2-results__option--selected {
            background-color: #e9ecef !important; /* Selected option background */
            color: #212529 !important; /* Selected option text color */
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">PubInno-track: Publication and Innovation Tracking System</a>
            <div class="d-flex align-items-center ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <b><?= htmlspecialchars($_SESSION['role'] ?? '') ?></b> (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-dark">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; // Include the sidebar ?>

        <div id="main-content">
            <h4 class="mb-4"><?= htmlspecialchars($page_title) ?></h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($submissions)): ?>
                <p class="text-muted">No <?= htmlspecialchars(strtolower($page_title)) ?> found.</p>
            <?php else: ?>
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Title</th>
                            <th>Type</th> <!-- This will now show the specific type_name -->
                            <th>Researcher</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $s): ?>
                            <tr>
                                <td>
                                    <?php
                                    if (!empty($s['reference_number'])) {
                                        echo htmlspecialchars($s['reference_number']);
                                    } else {
                                        echo '<span class="text-muted">N/A (Pending)</span>'; // Changed text
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($s['title']) ?></td>
                                <td><?= htmlspecialchars($s['display_type_name']) ?></td> <!-- Displaying display_type_name -->
                                <td><?= htmlspecialchars($s['researcher_name']) ?></td>
                                <td><?= date("F j, Y g:i A", strtotime($s['submission_date'])) ?></td>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm view-submission-btn" data-bs-toggle="modal" data-bs-target="#submissionDetailsModal" data-submission-id="<?= $s['submission_id'] ?>">
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Submission Details Modal (Existing) -->
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
                    <!-- Action buttons - will be managed by JS -->
                    <button type="button" id="acceptSubmissionBtn" class="btn btn-success d-none">Accept</button>
                    <a href="#" id="forwardToPioBtn" class="btn btn-primary d-none">Forward to PIO</a>
                    <a href="#" id="rejectSubmissionBtn" class="btn btn-danger d-none">Reject</a>
                    <a href="#" id="addCommentBtn" class="btn btn-info d-none">Add Comment</a>
                </div>
            </div>
        </div>
    </div>

    <!-- New Modal for Generating Reference Number -->
    <div class="modal fade" id="generateRefNumModal" tabindex="-1" aria-labelledby="generateRefNumModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="generateRefNumForm" method="POST" action="actions/generate_reference_number.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="generateRefNumModalLabel">Generate Reference Number & Accept</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="submission_id" id="modalSubmissionId">
                        <input type="hidden" name="status_redirect" value="<?= urlencode($requested_status) ?>"> <!-- Preserve current status for redirect -->

                        <p>Submission Title: <strong id="modalSubmissionTitle"></strong></p>
                        <p>Main Researcher: <strong id="modalResearcherName"></strong></p>

                        <div class="mb-3">
                            <label for="modalCollegeId" class="form-label">College/Institute/Division:</label>
                            <select class="form-select" id="modalCollegeId" name="college_id" required>
                                <option value="">Select College</option>
                                <!-- Options will be loaded dynamically by JS -->
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="modalProgramId" class="form-label">Program:</label>
                            <select class="form-select" id="modalProgramId" name="program_id">
                                <option value="0">Not a Program (00)</option>
                                <!-- Options will be loaded dynamically by JS -->
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="modalProjectId" class="form-label">Project:</label>
                            <select class="form-select" id="modalProjectId" name="project_id" disabled>
                                <option value="0">Not a Project (00)</option>
                                <!-- Options will be loaded dynamically by JS -->
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="referenceNumberInput" class="form-label">Generated Tracking Number:</label>
                            <input type="text" class="form-control" id="referenceNumberInput" name="reference_number" readonly required>
                            <div class="form-text">This number is automatically generated based on your selections.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="acceptAndAssignBtn">Accept & Assign Reference</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Submission Details Modal Logic ---
        const submissionDetailsModal = new bootstrap.Modal(document.getElementById('submissionDetailsModal'));
        const modalBody = document.querySelector('#submissionDetailsModal .modal-body');
        const acceptSubmissionBtn = document.getElementById('acceptSubmissionBtn');
        const forwardToPioBtn = document.getElementById('forwardToPioBtn');
        const rejectSubmissionBtn = document.getElementById('rejectSubmissionBtn');
        const addCommentBtn = document.getElementById('addCommentBtn');

        // New elements for the Generate Reference Number Modal
        const generateRefNumModal = new bootstrap.Modal(document.getElementById('generateRefNumModal'));
        const modalSubmissionId = document.getElementById('modalSubmissionId');
        const modalSubmissionTitle = document.getElementById('modalSubmissionTitle');
        const modalResearcherName = document.getElementById('modalResearcherName');
        const modalCollegeId = document.getElementById('modalCollegeId');
        const modalProgramId = document.getElementById('modalProgramId');
        const modalProjectId = document.getElementById('modalProjectId');
        const referenceNumberInput = document.getElementById('referenceNumberInput');
        const generateRefNumForm = document.getElementById('generateRefNumForm');
        const acceptAndAssignBtn = document.getElementById('acceptAndAssignBtn');


        let currentSubmissionData = null; // Store submission data fetched for the modal

        // Initialize Select2 for the new dropdowns
        $(modalCollegeId).select2({ theme: "bootstrap-5", dropdownParent: $('#generateRefNumModal') });
        $(modalProgramId).select2({ theme: "bootstrap-5", dropdownParent: $('#generateRefNumModal') });
        $(modalProjectId).select2({ theme: "bootstrap-5", dropdownParent: $('#generateRefNumModal') });

        // Function to fetch and populate dropdowns and update RIPECode
        async function updateRipeCodeForm(submissionId, selectedCollege = null, selectedProgram = null, selectedProject = null) {
            try {
                // *** IMPORTANT CHANGE HERE: Adjust path for get_ripe_codes.php ***
                // Path from views/facilitator/ to views/facilitator/actions/
                const url = `actions/get_ripe_codes.php?submission_id=${submissionId}` +
                            (selectedCollege ? `&college_id=${selectedCollege}` : '') +
                            (selectedProgram ? `&program_id=${selectedProgram}` : '') +
                            (selectedProject ? `&project_id=${selectedProject}` : '');

                const response = await fetch(url);
                
                // Check if response is OK (status 200) and content-type is JSON
                const contentType = response.headers.get("content-type");
                if (!response.ok || !contentType || !contentType.includes("application/json")) {
                    const errorText = await response.text();
                    console.error('Server Response (not JSON):', errorText);
                    throw new Error(`Server responded with status ${response.status} and non-JSON content. Response: ${errorText.substring(0, 200)}...`);
                }

                const data = await response.json();

                if (data.success) {
                    // Populate College dropdown
                    // Preserve current selection if available, otherwise default to "Select College"
                    const currentCollegeVal = $(modalCollegeId).val();
                    $(modalCollegeId).empty().append('<option value="">Select College</option>');
                    data.colleges.forEach(college => {
                        const option = new Option(college.college_name, college.college_id, false, college.college_id == (selectedCollege || currentCollegeVal));
                        $(modalCollegeId).append(option);
                    });
                    $(modalCollegeId).val(selectedCollege || currentCollegeVal);

                    // Populate Program dropdown
                    const currentProgramVal = $(modalProgramId).val();
                    $(modalProgramId).empty().append('<option value="0">Not a Program (00)</option>');
                    if (data.programs.length > 0) {
                        modalProgramId.disabled = false;
                        data.programs.forEach(program => {
                            const option = new Option(program.program_name, program.program_id, false, program.program_id == (selectedProgram || currentProgramVal));
                            $(modalProgramId).append(option);
                        });
                    } else {
                        modalProgramId.disabled = true;
                    }
                    $(modalProgramId).val(selectedProgram || currentProgramVal || '0');

                    // Populate Project dropdown
                    const currentProjectVal = $(modalProjectId).val();
                    $(modalProjectId).empty().append('<option value="0">Not a Project (00)</option>');
                    if (data.projects.length > 0 && (selectedProgram !== '0' && selectedProgram !== null)) { // Only enable if a program is selected (not '0' or null)
                        modalProjectId.disabled = false;
                        data.projects.forEach(project => {
                            const option = new Option(project.project_name, project.project_id, false, project.project_id == (selectedProject || currentProjectVal));
                            $(modalProjectId).append(option);
                        });
                    } else {
                        modalProjectId.disabled = true;
                    }
                    $(modalProjectId).val(selectedProject || currentProjectVal || '0');


                    // Update the RIPECode preview
                    referenceNumberInput.value = data.ripe_code_preview || 'Generating...';

                } else {
                    console.error('Error fetching RIPE codes:', data.message);
                    alert('Error generating RIPE code preview: ' + (data.message || 'Unknown error. Check server logs for actions/get_ripe_codes.php.'));
                }
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Network or parsing error while fetching RIPE codes. Check browser console and server logs. Error: ' + error.message);
            }
        }

        // Event listener for "View" buttons
        document.querySelectorAll('.view-submission-btn').forEach(button => {
            button.addEventListener('click', function() {
                const submissionId = this.dataset.submissionId;
                modalBody.innerHTML = '<p>Loading submission details...</p>'; // Show loading message

                // Hide all action buttons initially for a clean slate
                acceptSubmissionBtn.classList.add('d-none');
                forwardToPioBtn.classList.add('d-none');
                rejectSubmissionBtn.classList.add('d-none');
                addCommentBtn.classList.add('d-none');

                // Fetch submission details via AJAX
                fetch('get_submission_details.php?submission_id=' + submissionId)
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
                            currentSubmissionData = data.submission; // Store for later use
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
                                            <strong>${htmlspecialchars(file.requirement_name)}:</strong>
                                            <a href="../../uploads/${htmlspecialchars(fileName)}" target="_blank" class="ms-2">
                                                ${htmlspecialchars(file.file_name)} <i class="bi bi-box-arrow-up-right ms-1"></i>
                                            </a>
                                            <small class="text-muted d-block ps-3">${htmlspecialchars(file.requirement_description || 'No description provided.')}</small>
                                        </li>
                                    `;
                                });
                                filesHtml += '</ul>';
                            } else {
                                filesHtml = '<p class="text-muted mt-4">No additional requirement files uploaded for this submission.</p>';
                            }

                            // Determine display for reference number
                            let displayRefNum = s.reference_number ? htmlspecialchars(s.reference_number) : '<span class="text-muted">N/A (Pending Generation)</span>';

                            modalBody.innerHTML = `
                                <p><strong>Reference No.:</strong> ${displayRefNum}</p>
                                <p><strong>Title:</strong> ${htmlspecialchars(s.title)}</p>
                                <p><strong>Category:</strong> ${htmlspecialchars(s.submission_type || 'N/A')}</p>
                                <p><strong>Type:</strong> ${htmlspecialchars(s.display_type_name || 'N/A')}</p>
                                <p><strong>Department:</strong> ${htmlspecialchars(s.department_name || 'N/A')}</p>
                                <p><strong>Main Researcher:</strong> ${htmlspecialchars(s.researcher_name || 'N/A')}</p>
                                ${s.other_researchers_names ? `<p><strong>Other Researchers:</strong> ${htmlspecialchars(s.other_researchers_names)}</p>` : ''}
                                <p><strong>Submission Date:</strong> ${htmlspecialchars(s.submission_date)}</p>
                                <p><strong>Status:</strong> <span class="badge bg-primary">${htmlspecialchars(s.status.replace(/_/g, ' '))}</span></p>
                                <p><strong>Abstract:</strong> ${htmlspecialchars(s.abstract || 'N/A')}</p>
                                ${s.file_path ? `<p><strong>Main Article File:</strong> <a href="../../uploads/${htmlspecialchars(s.file_path.split('/').pop())}" target="_blank">View Main File</a></p>` : ''}
                                ${filesHtml}
                            `;

                            // Dynamically set button visibility and hrefs based on submission status
                            const currentStatusFilter = '<?= urlencode($requested_status) ?>';

                            if (s.status === 'submitted') {
                                // "Accept" button now opens new modal for ref num generation
                                acceptSubmissionBtn.onclick = function() {
                                    submissionDetailsModal.hide(); // Hide the current modal
                                    
                                    // Populate the new modal with submission basic info
                                    modalSubmissionId.value = s.submission_id;
                                    modalSubmissionTitle.textContent = s.title;
                                    modalResearcherName.textContent = s.researcher_name;

                                    // Initialize and populate RIPECode dropdowns and preview
                                    // Pass initial selections if they exist, otherwise null
                                    updateRipeCodeForm(s.submission_id, s.college_id, s.program_id, s.project_id);

                                    generateRefNumModal.show(); // Show the new modal
                                };
                                acceptSubmissionBtn.classList.remove('d-none');

                                rejectSubmissionBtn.href = `manage_submissions.php?action=reject&id=${s.submission_id}&status=${currentStatusFilter}`;
                                rejectSubmissionBtn.classList.remove('d-none');

                                addCommentBtn.href = `add_comment.php?submission_id=${s.submission_id}&return_status=${currentStatusFilter}`;
                                addCommentBtn.classList.remove('d-none');
                                
                            } else if (s.status === 'pending_review' || s.status === 'accepted_by_facilitator') {
                                // For 'Pending Review' or 'Accepted by Facilitator' submissions, offer Forward to PIO, Reject, and Comment
                                forwardToPioBtn.href = `manage_submissions.php?action=forward_to_pio&id=${s.submission_id}&status=${currentStatusFilter}`;
                                forwardToPioBtn.classList.remove('d-none');

                                rejectSubmissionBtn.href = `manage_submissions.php?action=reject&id=${s.submission_id}&status=${currentStatusFilter}`;
                                rejectSubmissionBtn.classList.remove('d-none');

                                addCommentBtn.href = `add_comment.php?submission_id=${s.submission_id}&return_status=${currentStatusFilter}`;
                                addCommentBtn.classList.remove('d-none');

                            } else {
                                // For any other status (e.g., 'forwarded_to_pio', 'rejected', 'approved'), only offer Comment
                                addCommentBtn.href = `add_comment.php?submission_id=${s.submission_id}&return_status=${currentStatusFilter}`;
                                addCommentBtn.classList.remove('d-none');
                            }

                        } else {
                            // If success is false, display the message from the backend
                            modalBody.innerHTML = `<div class="alert alert-danger">Error loading submission details: ${htmlspecialchars(data.message || 'Unknown error from server.')}</div>`;
                            console.error('Server returned success: false', data.message);
                        }
                    })
                    .catch(error => {
                        // This catch block handles network errors or JSON parsing errors
                        console.error('Fetch error:', error);
                        modalBody.innerHTML = `<div class="alert alert-danger">An error occurred while fetching details. Please check console for more info. <br> Error: ${htmlspecialchars(error.message)}</div>`;
                    });
            });
        });

        // Event listeners for dropdown changes to update RIPECode preview
        $(modalCollegeId).on('change', function() {
            const selectedCollege = $(this).val();
            const submissionId = modalSubmissionId.value;
            // Reset program and project when college changes
            $(modalProgramId).empty().append('<option value="0">Not a Program (00)</option>');
            $(modalProjectId).empty().append('<option value="0">Not a Project (00)</option>');
            modalProjectId.disabled = true; // Disable project until program is selected

            if (selectedCollege) {
                updateRipeCodeForm(submissionId, selectedCollege, '0', '0'); // Pass '0' for program/project initially
            } else {
                // If no college selected, reset RIPE code preview
                referenceNumberInput.value = '';
                $(modalProgramId).empty().append('<option value="0">Not a Program (00)</option>');
                modalProgramId.disabled = true;
                $(modalProjectId).empty().append('<option value="0">Not a Project (00)</option>');
                modalProjectId.disabled = true;
            }
        });

        $(modalProgramId).on('change', function() {
            const selectedProgram = $(this).val();
            const selectedCollege = $(modalCollegeId).val();
            const submissionId = modalSubmissionId.value;
            // Reset project when program changes
            $(modalProjectId).empty().append('<option value="0">Not a Project (00)</option>');

            if (selectedProgram && selectedProgram !== '0') {
                modalProjectId.disabled = false; // Enable project dropdown if a program is selected
                updateRipeCodeForm(submissionId, selectedCollege, selectedProgram, '0'); // Pass '0' for project initially
            } else {
                modalProjectId.disabled = true; // Disable project dropdown if no program or "Not a Program" is selected
                updateRipeCodeForm(submissionId, selectedCollege, '0', '0'); // Update RIPE code with default program and project
            }
        });

        $(modalProjectId).on('change', function() {
            const selectedProject = $(this).val();
            const selectedProgram = $(modalProgramId).val();
            const selectedCollege = $(modalCollegeId).val();
            const submissionId = modalSubmissionId.value;

            updateRipeCodeForm(submissionId, selectedCollege, selectedProgram, selectedProject);
        });

        // --- Handle generateRefNumForm submission via AJAX ---
        generateRefNumForm.addEventListener('submit', async function(event) {
            event.preventDefault(); // Prevent default form submission

            // Disable button and show loading indicator
            acceptAndAssignBtn.disabled = true;
            acceptAndAssignBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

            const formData = new FormData(this);
            const actionUrl = this.action; // Get the action URL from the form

            try {
                const response = await fetch(actionUrl, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Hide the modal
                    generateRefNumModal.hide();
                    // Set a session message for display on the next page load
                    // The PHP code in generate_reference_number.php already sets $_SESSION['message']
                    // and $_SESSION['message_type'] for redirection.
                    // So, we just need to redirect the browser.
                    window.location.href = `manage_submissions.php?status=accepted_by_facilitator`; // Redirect to accepted submissions
                } else {
                    // Display error message in an alert
                    alert('Error: ' + (data.message || 'Unknown error. Check server logs.'));
                }
            } catch (error) {
                console.error('Form submission error:', error);
                alert('Network error during submission. Please try again. Error: ' + error.message);
            } finally {
                // Re-enable button and restore text
                acceptAndAssignBtn.disabled = false;
                acceptAndAssignBtn.innerHTML = 'Accept & Assign Reference';
            }
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
