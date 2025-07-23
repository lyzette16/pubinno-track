<?php
// File: views/researcher/fetch_submission_details.php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    echo '<div class="alert alert-danger p-4 rounded-md">Unauthorized access.</div>';
    exit();
}

$researcher_id = $_SESSION['user_id'];
$reference_number = trim($_GET['ref'] ?? '');

$submission = null;
$comments = [];
$stepTimestamps = [];
$elapsedTimes = [];
$isRejected = false;

// Define the steps in the tracking process (must match track_submission.php)
$statusSteps = [
    'submitted',
    'accepted_by_facilitator',
    'forwarded_to_pio',
    'accepted_by_pio',
    'under_external_review',
    'approved'
];

// Map database statuses to the human-readable tracking steps (must match track_submission.php)
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


if (empty($reference_number)) {
    echo '<div class="alert alert-warning p-4 rounded-md">No reference number provided.</div>';
    exit();
}

$stmt = $conn->prepare("SELECT s.*, u.name AS researcher_name, d.name AS department_name,
                               rejector.name AS rejected_by_name,
                               pt.type_name AS publication_type_name, pt.submission_category AS submission_category_name
                        FROM submissions s
                        JOIN users u ON s.researcher_id = u.user_id
                        LEFT JOIN departments d ON s.department_id = d.department_id
                        LEFT JOIN users rejector ON s.rejected_by = rejector.user_id
                        LEFT JOIN publication_types pt ON s.pub_type_id = pt.pub_type_id
                        WHERE s.reference_number = ? AND s.researcher_id = ?");
$stmt->bind_param("si", $reference_number, $researcher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $submission = $result->fetch_assoc();
    $isRejected = $submission['status'] === 'rejected';

    // Fetch all status logs for this submission
    $stmt2 = $conn->prepare("SELECT new_status, changed_at, u.name AS changed_by_name FROM submission_status_logs l LEFT JOIN users u ON l.changed_by = u.user_id WHERE submission_id = ? ORDER BY changed_at ASC");
    $stmt2->bind_param("i", $submission['submission_id']);
    $stmt2->execute();
    $logs = $stmt2->get_result();

    // Initialize timeline with the original submission time
    $lastTime = $submission['submission_date'] ?? $submission['created_at'];
    $stepTimestamps['submitted'] = [
        'time' => $submission['submission_date'] ?? $submission['created_at'],
        'by' => 'You (Researcher)' // More descriptive for the researcher
    ];

    foreach ($logs as $log) {
        $mapped = $statusMap[$log['new_status']] ?? null;
        $currentTime = $log['changed_at'];

        if ($mapped) {
            if (!isset($stepTimestamps[$mapped])) {
                $stepTimestamps[$mapped] = [
                    'time' => $currentTime,
                    'by' => htmlspecialchars($log['changed_by_name'] ?? 'N/A')
                ];
                $elapsedTimes[$mapped] = formatElapsed($lastTime, $currentTime);
                $lastTime = $currentTime;
            } else {
                $stepTimestamps[$mapped]['time'] = $currentTime;
                $stepTimestamps[$mapped]['by'] = htmlspecialchars($log['changed_by_name'] ?? 'N/A');
            }
        } elseif ($log['new_status'] === 'rejected') {
            $stepTimestamps['rejected'] = [
                'time' => $currentTime,
                'by' => htmlspecialchars($log['changed_by_name'] ?? 'N/A')
            ];
            $elapsedTimes['rejected'] = formatElapsed($lastTime, $currentTime);
            break;
        }
    }
    $stmt2->close();

    // After all logs are processed, set rejectedDetails if the submission is rejected
    if ($isRejected) {
        $rejectorName = 'N/A';
        $rejectionTime = $submission['updated_at'];

        if (isset($stepTimestamps['rejected']) && !empty($stepTimestamps['rejected']['by']) && $stepTimestamps['rejected']['by'] !== 'N/A') {
            $rejectorName = $stepTimestamps['rejected']['by'];
            $rejectionTime = $stepTimestamps['rejected']['time'];
        } elseif (isset($submission['rejected_by_name']) && !empty($submission['rejected_by_name'])) {
            $rejectorName = $submission['rejected_by_name'];
        }

        if ($rejectorName !== 'N/A') {
            $rejectedDetails = "Rejected by " . htmlspecialchars($rejectorName) . " on " . date("F j, Y g:i A", strtotime($rejectionTime));
        } else {
            $rejectedDetails = "Rejected on " . date("F j, Y g:i A", strtotime($rejectionTime));
        }
    }

    // Fetch Comments for this submission
    $stmt_comments = $conn->prepare("SELECT sc.*, u.name AS commenter_name FROM submission_comments sc LEFT JOIN users u ON sc.user_id = u.user_id WHERE sc.submission_id = ? ORDER BY sc.comment_date DESC");
    if ($stmt_comments) {
        $stmt_comments->bind_param("i", $submission['submission_id']);
        $stmt_comments->execute();
        $result_comments = $stmt_comments->get_result();
        while ($comment = $result_comments->fetch_assoc()) {
            $comments[] = $comment;
        }
        $stmt_comments->close();
    }

}
$stmt->close();
$conn->close();

if (!$submission) {
    echo '<div class="alert alert-danger p-4 rounded-md">Submission not found or you do not have permission to view it.</div>';
    exit();
}

// Render the HTML for the modal content
?>
<!-- Include Bootstrap Icons CSS here to ensure icons are available in the modal content -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    /* General body font for modal content */
    body {
        font-family: "Segoe UI", sans-serif;
    }
    .modal-body-content {
        background-color: #fcfdff; /* Very light background for the modal content */
        padding: 1.5rem; /* Consistent padding */
    }

    /* Card Styling */
    .card {
        border: none; /* Remove default Bootstrap border */
        border-radius: 0.75rem; /* Rounded corners */
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); /* Softer, more modern shadow */
    }
    .card-header {
        background-color: #ffffff; /* White header background */
        font-weight: 600; /* Semi-bold */
        border-bottom: 1px solid #e9ecef; /* Light separator */
        padding: 1rem 1.5rem; /* Padding for header */
    }
    .card-body {
        padding: 1.5rem; /* Consistent padding */
    }

    /* Submission Details Grid */
    .info-grid .col-md-6 p {
        margin-bottom: 0.25rem; /* Smaller margin for tighter info display */
    }
    .info-grid .fw-semibold {
        color: #495057; /* Darker gray for labels */
        font-size: 0.95rem; /* Slightly smaller font for labels */
    }
    .info-grid p:not(.fw-semibold) {
        color: #343a40; /* Darker text for values */
        font-size: 1rem; /* Standard font size for values */
    }

    /* Status Badge Styling */
    .status-badge {
        display: inline-flex;
        padding: 0.35em 0.7em;
        font-size: 0.8em; /* Slightly larger font for readability */
        font-weight: 700;
        line-height: 1;
        color: #fff;
        text-align: center;
        white-space: nowrap;
        vertical-align: middle;
        border-radius: 0.5rem; /* More rounded */
        margin-left: 0.5rem; /* Space from label */
    }
    .status-submitted { background-color: #6c757d; } /* Gray */
    .status-accepted_by_facilitator { background-color: #6610f2; } /* Indigo */
    .status-forwarded_to_pio { background-color: #6f42c1; } /* Purple */
    .status-accepted_by_pio { background-color: #0d6efd; } /* Blue */
    .status-under_external_review { background-color: #ffc107; color: #212529; } /* Yellow, dark text */
    .status-accepted_by_external { background-color: #20c997; } /* Teal */
    .status-approved { background-color: #198754; } /* Green */
    .status-rejected { background-color: #dc3545; } /* Red */
    .status-in-progress { background-color: #0d6efd; } /* Blue for in progress */
    .status-pending { background-color: transparent; color: #6c757d; border: 1px solid #ced4da; } /* Gray font, transparent background, subtle border */

    /* Timeline Styling */
    .timeline {
        padding-left: 50px; /* Adjusted padding to make space for the line and dots */
        position: relative; /* Needed for absolute positioning of the line */
    }
    .timeline-line {
        position: absolute;
        left: 17px; /* Position of the vertical line */
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: #e9ecef; /* Lighter gray line */
    }
    .timeline-step {
        position: relative;
        margin-bottom: 8px; /* Further reduced margin for closer details */
        padding-left: 45px; /* Increased padding to move text away from larger dot */
    }
    .timeline-dot {
        position: absolute;
        left: 3px; /* Adjusted to align with the vertical line */
        top: 0px; /* Adjusted to align with text baseline */
        width: 28px; /* Slightly larger size for the dot */
        height: 28px; /* Slightly larger size for the dot */
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600; /* Bolder font for numbers */
        z-index: 10;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1); /* Subtle shadow for all dots */
    }
    /* Specific styles for timeline dots based on status */
    .timeline-dot.completed {
        background-color: #198754; /* Green for completed */
        border: 1px solid #198754;
        color: #fff;
    }
    .timeline-dot.active {
        background-color: #2D3748; /* Darker blue/black for active (from image) */
        border: 1px solid #2D3748;
        color: #fff;
        box-shadow: 0 4px 8px rgba(45,55,72,0.3); /* More prominent shadow for active */
    }
    .timeline-dot.pending {
        background-color: #f8f9fa; /* Light background for pending */
        border: 1px solid #ced4da; /* Subtle border for pending */
        color: #6c757d; /* Gray font for numbers in pending state */
    }
    .timeline-dot.rejected {
        background-color: #dc3545; /* Red for rejected */
        border: 1px solid #dc3545;
        color: #fff;
    }
    .timeline-dot i.bi {
        font-size: 1.2rem; /* Adjusted icon size to fit dot */
        color: inherit; /* Inherit color from parent dot */
    }
    .timeline-step .fw-semibold {
        color: #343a40; /* Dark text for step name */
        font-size: 1rem;
        margin-bottom: 0.05rem; /* Further reduced space below step name */
    }
    .timestamp {
        font-size: 0.75rem; /* Slightly smaller timestamp font */
        color: #6c757d;
        margin-top: 0; /* Removed margin to bring it closer */
        line-height: 1.3;
    }
    /* Status badge styling within timeline steps - match image for pending/completed/in progress */
    .status-badge.status-completed {
        background-color: #D1FAE5; /* Light green from image */
        color: #065F46; /* Dark green text from image */
        padding: 0.2em 0.5em;
        font-size: 0.7em;
        font-weight: 600;
        border-radius: 0.25rem;
        margin-left: 0.25rem; /* Closer to the title */
    }
    .status-badge.status-in-progress {
        background-color: #DBEAFE; /* Light blue from image */
        color: #1E40AF; /* Dark blue text from image */
        padding: 0.2em 0.5em;
        font-size: 0.7em;
        font-weight: 600;
        border-radius: 0.25rem;
        margin-left: 0.25rem;
    }
    .status-badge.status-pending {
        background-color: #E5E7EB; /* Light gray from image */
        color: #4B5563; /* Dark gray text from image */
        padding: 0.2em 0.5em;
        font-size: 0.7em;
        font-weight: 600;
        border-radius: 0.25rem;
        margin-left: 0.25rem;
        border: none; /* Remove border for pending badge */
    }

    /* Comments Section Styling */
    .comments-section .comment-card {
        border-left: 5px solid #0d6efd; /* Primary blue border */
        border-radius: 0.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05); /* Subtle shadow */
        background-color: #ffffff; /* White background for comments */
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
        white-space: pre-wrap;
        color: #343a40; /* Darker text for comment body */
    }

    /* Button Styling */
    .btn-secondary {
        background-color: #e9ecef;
        color: #495057;
        border-color: #e9ecef;
        transition: all 0.2s ease-in-out;
    }
    .btn-secondary:hover {
        background-color: #dee2e6;
        border-color: #dee2e6;
        color: #343a40;
    }

    /* Modal Container Height Adjustment */
    .modal-container {
        max-height: 90vh; /* Increased height for modal */
    }
</style>

<div class="modal-body-content"> <!-- This div will be injected into the modal content area on the parent page -->
    <h2 class="h4 fw-bold text-dark mb-4">Submission Details</h2>
    <div class="card mb-4">
        <div class="card-body info-grid">
            <div class="row g-3">
                <div class="col-md-6">
                    <p class="fw-semibold">Reference Number:</p>
                    <p><?= htmlspecialchars($submission['reference_number']) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Title:</p>
                    <p><?= htmlspecialchars($submission['title']) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Researcher:</p>
                    <p><?= htmlspecialchars($submission['researcher_name']) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Department:</p>
                    <p><?= htmlspecialchars($submission['department_name'] ?? 'N/A') ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Submission Category:</p>
                    <p><?= htmlspecialchars(ucwords($submission['submission_category_name'] ?? 'N/A')) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Type of Submission:</p>
                    <p><?= htmlspecialchars($submission['publication_type_name'] ?? 'N/A') ?></p>
                </div>
                <div class="col-md-6">
                    <p class="fw-semibold">Submission Date:</p>
                    <p><?= date('M d, Y h:i A', strtotime($submission['submission_date'])) ?></p>
                </div>
                <div class="col-md-12">
                    <p class="fw-semibold">Current Status:</p>
                    <p>
                        <span class="badge 
                            <?php 
                                $statusClass = str_replace(' ', '_', strtolower($submission['status']));
                                echo 'status-' . (in_array($statusClass, ['submitted', 'under_external_review']) ? 'secondary' : 
                                              ($statusClass === 'rejected' ? 'danger' : 
                                              ($statusClass === 'approved' ? 'success' : 'primary')));
                            ?>">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $submission['status']))) ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isRejected): ?>
        <div class="alert alert-danger mt-4" role="alert">
            <p class="fw-semibold mb-1">Submission Rejected:</p>
            <p class="mb-0"><?= htmlspecialchars($rejectedDetails) ?></p>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Submission Timeline</div>
        <div class="card-body">
            <div class="timeline">
                <div class="timeline-line"></div> <!-- Vertical line -->
                <?php
                $stepCounter = 1;
                $submissionCurrentMappedStatus = $statusMap[$submission['status']] ?? null;

                foreach ($statusSteps as $step):
                    $dotClass = '';
                    $dotContent = $stepCounter;
                    $statusBadgeClass = '';
                    $statusBadgeText = '';
                    $timestampText = '';

                    // Determine dot class and content based on status
                    $isStepCompleted = isset($stepTimestamps[$step]);
                    $isCurrentStatus = ($submissionCurrentMappedStatus === $step);

                    if ($isRejected && isset($stepTimestamps['rejected'])) {
                        $rejectionTime = strtotime($stepTimestamps['rejected']['time']);
                        $currentStepTime = isset($stepTimestamps[$step]['time']) ? strtotime($stepTimestamps[$step]['time']) : PHP_INT_MAX;

                        if ($currentStepTime < $rejectionTime) { // Steps completed before rejection
                            $dotClass = 'completed';
                            $dotContent = '<i class="bi bi-check-lg"></i>';
                            $statusBadgeClass = 'status-completed';
                            $statusBadgeText = 'Completed';
                        } else { // Steps after rejection or pending during rejection
                            $dotClass = 'pending';
                            $dotContent = $stepCounter;
                            $statusBadgeClass = 'status-pending';
                            $statusBadgeText = 'Pending';
                        }
                    } elseif ($isStepCompleted) {
                        $dotClass = 'completed';
                        $dotContent = '<i class="bi bi-check-lg"></i>';
                        $statusBadgeClass = 'status-completed';
                        $statusBadgeText = 'Completed';
                    } elseif ($isCurrentStatus) {
                        $dotClass = 'active';
                        $dotContent = $stepCounter; // Changed to step number
                        $statusBadgeClass = 'status-in-progress';
                        $statusBadgeText = 'In Progress';
                    } else {
                        $dotClass = 'pending';
                        $dotContent = $stepCounter;
                        $statusBadgeClass = 'status-pending';
                        $statusBadgeText = 'Pending';
                    }

                    if (isset($stepTimestamps[$step])) {
                        $t = date("M j, Y g:i A", strtotime($stepTimestamps[$step]['time']));
                        $by = $stepTimestamps[$step]['by'];
                        $elapsed = $elapsedTimes[$step] ?? '';
                        $timestampText = "$t" . ($by !== 'N/A' ? "<br>By $by" : "") . ($elapsed ? "<br>Took $elapsed" : "");
                    }

                    echo "<div class='timeline-step'>
                            <div class='timeline-dot " . $dotClass . "'>" . $dotContent . "</div>
                            <div>
                                <div class='fw-semibold'>" . ucwords(str_replace('_', ' ', $step)) . "</div>";

                    // For Completed and In Progress, put timestamp next to badge
                    if (($isStepCompleted || $isCurrentStatus) && !empty($timestampText)) {
                        echo "<span class='status-badge " . $statusBadgeClass . "'>" . $statusBadgeText . "</span>";
                        echo "<span class='timestamp' style='margin-left: 0.5rem;'>" . $timestampText . "</span>"; // Add margin-left for spacing
                    } else { // For Pending or if no timestamp, put badge alone and timestamp below (if exists)
                        echo "<span class='status-badge " . $statusBadgeClass . "'>" . $statusBadgeText . "</span>";
                        if (!empty($timestampText)) {
                            echo "<div class='timestamp'>" . $timestampText . "</div>";
                        }
                    }
                    echo "      </div>
                        </div>";
                    $stepCounter++;
                endforeach;
                ?>

                <?php if ($isRejected && isset($stepTimestamps['rejected'])): ?>
                    <div class='timeline-step'>
                        <div class='timeline-dot rejected'><i class='bi bi-x-lg'></i></div>
                        <div>
                            <div class='fw-semibold text-danger'>Rejected</div>
                            <span class='status-badge status-rejected'>Rejected</span>
                            <div class='timestamp'>
                                <?= date("M j, Y g:i A", strtotime($stepTimestamps['rejected']['time'])) ?><br>
                                By <?= htmlspecialchars($stepTimestamps['rejected']['by']) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card comments-section">
        <div class="card-header">Comments</div>
        <div class="card-body">
            <?php if (!empty($comments)): ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($comments as $comment): ?>
                        <div class="card comment-card p-3">
                            <div class="comment-header">
                                <strong><?= htmlspecialchars($comment['commenter_name']) ?></strong>
                                <span class="float-end text-muted"><?= date("M d, Y h:i A", strtotime($comment['comment_date'])) ?></span>
                            </div>
                            <div class="comment-body">
                                <p class="mb-1"><?= nl2br(htmlspecialchars($comment['comment_text'])) ?></p>
                                <small class="text-muted">(Type: <?= ucwords(str_replace('_', ' ', htmlspecialchars($comment['comment_type']))) ?>)</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">No comments for this submission yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button onclick="closeModal()" class="btn btn-secondary">
            Close
        </button>
    </div>
</div>
