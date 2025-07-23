<?php
// File: views/facilitator/track_submission_facilitator.php (or similar, based on your file structure)

// Conditionally start the session to prevent "session_start(): Ignoring session_start()" notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a facilitator
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$facilitator_id = $_SESSION['user_id']; // Changed variable name for clarity
$facilitator_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'Facilitator'; // Changed variable name for clarity
$department_id = $_SESSION['department_id'] ?? null; // Get facilitator's department ID
$campus_id = $_SESSION['campus_id'] ?? null; // Get facilitator's campus ID

$submission = null;
$stepTimestamps = [];
$elapsedTimes = [];
$isRejected = false;
$rejectedDetails = '';
$comments = []; // New array to store comments
$track_message = ''; // Message to display for tracking success/failure

// Check if Facilitator has a department_id and campus_id set
if (!$department_id || !$campus_id) {
    $track_message = '<div class="alert alert-danger">Your Facilitator account is not associated with a department or campus. Cannot track submissions.</div>';
}

// Define the steps in the tracking process (removed 'forwarded to external')
$statusSteps = [
    'submitted',
    'accepted_by_facilitator',
    'forwarded_to_pio',
    'accepted_by_pio',
    'under_external_review',
    'approved'
];

// Map database statuses to the human-readable tracking steps
$statusMap = [
    'submitted' => 'submitted',
    'accepted_by_facilitator' => 'accepted_by_facilitator',
    'forwarded_to_pio' => 'forwarded_to_pio',
    'accepted_by_pio' => 'accepted_by_pio',
    'reviewed_by_pio' => 'accepted_by_pio', // PIO might just review and not approve immediately, map to accepted_by_pio for timeline
    'forwarded_to_external' => 'under_external_review', // Map 'forwarded_to_external' to 'under_external_review'
    'under_external_review' => 'under_external_review',
    'approved' => 'approved'
];

/**
 * Calculates and formats the elapsed time between two timestamps.
 * @param string $start Start timestamp.
 * @param string $end End timestamp.
 * @return string Formatted elapsed time (e.g., "2 days, 3 hours").
 */
function formatElapsed($start, $end) {
    $startDT = new DateTime($start);
    $endDT = new DateTime($end);
    $diff = $startDT->diff($endDT);

    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) $parts[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    if ($diff->d > 0) $parts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
    if ($diff->h > 0) $parts[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    if ($diff->i > 0) $parts[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');

    return implode(', ', $parts);
}

// Logic to retrieve submission based on GET (from dashboard table) or POST (from tracking form)
$submission_identifier = null; // Can be submission_id or reference_number
$identifier_type = null;
$is_post_request = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['reference_number']));

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Came from dashboard table 'Track' button
    $submission_identifier = (int) $_GET['id'];
    $identifier_type = 'id';
} elseif ($is_post_request) {
    // Came from the tracking form submission
    $submission_identifier = trim($_POST['reference_number']);
    $identifier_type = 'reference';
}

if ($submission_identifier && $department_id && $campus_id) {
    // Modified SQL query to include the rejector's name from the `submissions` table.
    $base_sql = "SELECT s.*, u.name AS researcher_name, d.name AS department_name,
                        rejector.name AS rejected_by_name
                 FROM submissions s
                 JOIN users u ON s.researcher_id = u.user_id
                 LEFT JOIN departments d ON s.department_id = d.department_id
                 LEFT JOIN users rejector ON s.rejected_by = rejector.user_id"; // Join to get rejector's name

    if ($identifier_type === 'id') {
        // For Facilitator, filter by department_id and campus_id
        $stmt = $conn->prepare($base_sql . " WHERE s.submission_id = ? AND s.department_id = ? AND s.campus_id = ?");
        $stmt->bind_param("iii", $submission_identifier, $department_id, $campus_id);
    } else { // identifier_type === 'reference'
        // For Facilitator, filter by department_id and campus_id
        $stmt = $conn->prepare($base_sql . " WHERE s.reference_number = ? AND s.department_id = ? AND s.campus_id = ?");
        $stmt->bind_param("sii", $submission_identifier, $department_id, $campus_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $submission = $result->fetch_assoc();
        $isRejected = $submission['status'] === 'rejected';

        // Fetch all status logs for this submission
        // Changed JOIN to LEFT JOIN for users to handle cases where changed_by might be null or invalid
        $stmt2 = $conn->prepare("SELECT new_status, changed_at, u.name AS changed_by_name FROM submission_status_logs l LEFT JOIN users u ON l.changed_by = u.user_id WHERE submission_id = ? ORDER BY changed_at ASC");
        $stmt2->bind_param("i", $submission['submission_id']);
        $stmt2->execute();
        $logs = $stmt2->get_result();

        // Initialize timeline with the original submission time
        $lastTime = $submission['submission_date'];
        $stepTimestamps['submitted'] = [
            'time' => $submission['submission_date'],
            'by' => htmlspecialchars($submission['researcher_name'])
        ];

        foreach ($logs as $log) {
            $mapped = $statusMap[$log['new_status']] ?? null;
            $currentTime = $log['changed_at'];

            if ($mapped) {
                if (!isset($stepTimestamps[$mapped])) {
                    $stepTimestamps[$mapped] = [
                        'time' => $currentTime,
                        'by' => htmlspecialchars($log['changed_by_name'] ?? 'N/A') // Handle null name from LEFT JOIN
                    ];
                    $elapsedTimes[$mapped] = formatElapsed($lastTime, $currentTime);
                } else {
                   $stepTimestamps[$mapped]['time'] = $currentTime;
                   $stepTimestamps[$mapped]['by'] = htmlspecialchars($log['changed_by_name'] ?? 'N/A'); // Handle null name
                }
                $lastTime = $currentTime;
            } elseif ($log['new_status'] === 'rejected') {
                $stepTimestamps['rejected'] = [
                    'time' => $currentTime,
                    'by' => htmlspecialchars($log['changed_by_name'] ?? 'N/A') // Handle null name
                ];
                $elapsedTimes['rejected'] = formatElapsed($lastTime, $currentTime);
                break; // Stop tracking further steps if rejected
            }
        }
        $stmt2->close();

        // After all logs are processed, set rejectedDetails if the submission is rejected
        if ($isRejected) {
            $rejectorName = 'N/A';
            $rejectionTime = $submission['updated_at']; // Default to submission's updated_at

            // Prefer information from status logs if 'rejected' entry exists and has a name
            if (isset($stepTimestamps['rejected']) && !empty($stepTimestamps['rejected']['by']) && $stepTimestamps['rejected']['by'] !== 'N/A') {
                $rejectorName = $stepTimestamps['rejected']['by'];
                $rejectionTime = $stepTimestamps['rejected']['time'];
            } elseif (isset($submission['rejected_by_name']) && !empty($submission['rejected_by_name'])) {
                // Fallback to rejected_by_name from the main submission query if logs don't provide it
                $rejectorName = $submission['rejected_by_name'];
                // Use submission's updated_at as rejection time if log time isn't available
            }

            if ($rejectorName !== 'N/A') { // Only set if we found a name
                $rejectedDetails = "Rejected by " . htmlspecialchars($rejectorName) . " on " . date("F j, Y g:i A", strtotime($rejectionTime));
            } else {
                // If no specific rejector name is found, just show the rejection time
                $rejectedDetails = "Rejected on " . date("F j, Y g:i A", strtotime($rejectionTime));
            }
        }


        // Fetch Comments for this submission from 'submission_comments' table
        $stmt_comments = $conn->prepare("SELECT sc.comment_id, sc.comment_text, sc.comment_type, sc.comment_date, u.name AS commenter_name FROM submission_comments sc JOIN users u ON sc.user_id = u.user_id WHERE sc.submission_id = ? ORDER BY sc.comment_date DESC");
        if ($stmt_comments) {
            $stmt_comments->bind_param("i", $submission['submission_id']);
            $stmt_comments->execute();
            $result_comments = $stmt_comments->get_result();
            while ($comment = $result_comments->fetch_assoc()) {
                $comments[] = $comment;
            }
            $stmt_comments->close();
        } else {
            error_log("Failed to prepare statement for fetching comments: " . $conn->error);
            $track_message = '<div class="alert alert-warning">Could not load comments due to a database error.</div>';
        }

    } else {
        $track_message = '<div class="alert alert-danger">No submission found with that reference number in your department.</div>';
    }
    $stmt->close();
} elseif ($is_post_request && empty($submission_identifier)) {
    $track_message = '<div class="alert alert-warning">Please enter a reference number to track.</div>';
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // No specific status filter for this page
?>
<!DOCTYPE html>
<html>
<head>
    <title>Track Submission (Facilitator)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
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
            font-size: 1.6rem;
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
        /* Tracking Timeline Specific Styles */
        .timeline {
            display: flex;
            flex-direction: column; /* Changed to vertical */
            padding: 1rem; /* Adjust padding for vertical */
            gap: 1.5rem; /* Space between vertical steps */
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            background-color: #f8f9fa;
            position: relative; /* For the main vertical line */
            padding-left: 3rem; /* Space for the vertical line and icons */
        }

        .timeline::before { /* Main vertical line */
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 2rem; /* Position of the vertical line */
            width: 2px;
            background-color: #ddd;
            z-index: 0;
        }

        .timeline-step {
            text-align: left; /* Align text to left */
            display: flex; /* Use flex for icon and text alignment */
            align-items: flex-start; /* Align items to the top */
            position: relative;
            padding-left: 2.5rem; /* Space for the icon and connection to line */
            padding-bottom: 0.5rem; /* Padding between steps */
        }

        .timeline-step:not(:last-child)::after {
            content: none; /* Remove horizontal line */
        }

        .timeline-icon {
            font-size: 1.8rem; /* Adjusted size for vertical layout */
            position: absolute;
            left: 1.2rem; /* Position relative to the main vertical line */
            top: 0;
            z-index: 1;
            background-color: #f8f9fa; /* Match background to hide line under icon */
            border-radius: 50%;
            padding: 0 5px;
            transform: translateX(-50%); /* Center icon on the vertical line */
        }

        .timestamp {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 0.2rem; /* Adjust margin for vertical layout */
        }

        /* Comments Section Styling */
        .comments-section .comment-card {
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            border-left: 5px solid #007bff; /* Blue border for comments */
            border-radius: 0.25rem;
        }
        .comments-section .comment-header {
            font-size: 0.9rem;
            color: #6c757d;
            border-bottom: 1px solid #f0f2f5;
            padding-bottom: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .comments-section .comment-body {
            font-size: 0.95rem;
            white-space: pre-wrap; /* Preserve whitespace and line breaks */
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
            .timeline {
                overflow-x: hidden; /* No horizontal scrolling for vertical timeline */
            }
            .timeline-step {
                width: auto; /* Allow width to be flexible */
            }
            /* No specific ::after adjustments needed if using the main ::before line */
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
        <?php include '_sidebar.php'; // Include the sidebar ?>

        <div id="main-content">
            <h4 class="mb-4">Track Submission</h4>

            <form method="POST" class="bg-white p-4 shadow rounded mb-4">
                <div class="mb-3">
                    <label for="reference_number" class="form-label">Enter Tracking Reference Number</label>
                    <input type="text" name="reference_number" id="reference_number" class="form-control" required placeholder="e.g., RES-2025-001" value="<?= htmlspecialchars($_POST['reference_number'] ?? $_GET['ref'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">Track</button>
            </form>

            <?= $track_message ?>

            <?php if ($submission): ?>
                <div class="bg-white p-4 shadow rounded mb-4">
                    <h5 class="mb-3">Tracking for Reference Number: **<?= htmlspecialchars($submission['reference_number']) ?>**</h5>
                    <h6 class="mb-3">Title: **<?= htmlspecialchars($submission['title']) ?>** (Researcher: **<?= htmlspecialchars($submission['researcher_name']) ?>**)</h6>
                    <p class="mb-3">Current Status:
                        <span class="fw-bold <?= $isRejected ? 'text-danger' : 'text-success' ?>">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $submission['status']))) ?>
                        </span>
                    </p>

                    <?php if ($isRejected): ?>
                        <div class="alert alert-danger fw-bold">
                            <?= htmlspecialchars($rejectedDetails) ?>
                        </div>
                    <?php endif; ?>

                    <h6 class="mt-4 mb-3">Submission Timeline:</h6>
                    <div class="timeline">
                        <?php
                        $currentStepReached = false;
                        foreach ($statusSteps as $step):
                            $iconClass = 'bi-circle text-secondary'; // Default inactive
                            $textClass = '';

                            // Check if this step has been completed (has a timestamp)
                            $isStepCompleted = isset($stepTimestamps[$step]);

                            // Determine if the current submission status matches this step
                            $isCurrentStatus = ($submission && ($statusMap[$submission['status']] ?? null) === $step);

                            if ($isRejected) {
                                // If rejected, steps before rejection are green, others gray.
                                $rejectionTime = isset($stepTimestamps['rejected']['time']) ? strtotime($stepTimestamps['rejected']['time']) : PHP_INT_MAX;
                                $stepCompletionTime = $isStepCompleted ? strtotime($stepTimestamps[$step]['time']) : PHP_INT_MAX;

                                if ($stepCompletionTime < $rejectionTime) {
                                    $iconClass = 'bi-check-circle-fill text-success';
                                } else {
                                    $iconClass = 'bi-circle text-secondary';
                                }
                            } elseif ($isCurrentStatus) {
                                // If this is the current active step, show a primary arrow icon
                                $iconClass = 'bi-arrow-down-circle-fill text-primary'; // Changed to down arrow
                                $textClass = 'fw-bold text-primary';
                                $currentStepReached = true; // Mark that we've reached or passed the current status in the loop
                            } elseif ($isStepCompleted) {
                                // If completed (and not rejected, and not current), show green check
                                $iconClass = 'bi-check-circle-fill text-success';
                            } else {
                                // Otherwise, it's an upcoming step, show gray circle
                                $iconClass = 'bi-circle text-secondary';
                            }

                            echo "<div class='timeline-step'>
                                    <i class='bi $iconClass timeline-icon'></i>
                                    <div>
                                        <div class='fw-semibold $textClass'>" . ucwords(str_replace('_', ' ', $step)) . "</div>"; // Human-readable step name

                            if (isset($stepTimestamps[$step])) {
                                $t = date("F j, Y g:i A", strtotime($stepTimestamps[$step]['time']));
                                $by = $stepTimestamps[$step]['by'];
                                $elapsed = $elapsedTimes[$step] ?? ''; // Elapsed time to reach THIS step
                                echo "<div class='timestamp'>$t<br>By $by" . ($elapsed ? "<br>Took $elapsed" : "") . "</div>";
                            }
                            echo "      </div>
                                </div>";
                        endforeach;

                        // Add a dedicated Rejected step if the submission is rejected
                        if ($isRejected && isset($stepTimestamps['rejected'])): ?>
                            <div class='timeline-step'>
                                <i class='bi bi-x-circle-fill text-danger timeline-icon'></i>
                                <div>
                                    <div class='fw-semibold text-danger'>Rejected</div>
                                    <div class='timestamp'>
                                        <?= date("F j, Y g:i A", strtotime($stepTimestamps['rejected']['time'])) ?><br>
                                        By <?= htmlspecialchars($stepTimestamps['rejected']['by']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white p-4 shadow rounded comments-section">
                    <h5 class="mb-3">Comments</h5>
                    <?php if (empty($comments)): ?>
                        <p class="text-muted">No comments found for this submission.</p>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="card comment-card p-3">
                                <div class="comment-header">
                                    <strong><?= htmlspecialchars($comment['commenter_name']) ?></strong>
                                    <span class="float-end text-muted"><?= date("F j, Y g:i A", strtotime($comment['comment_date'])) ?></span>
                                </div>
                                <div class="comment-body">
                                    <?= nl2br(htmlspecialchars($comment['comment_text'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <a href="dashboard.php" class="btn btn-secondary mt-4">⬅ Back to Dashboard</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
