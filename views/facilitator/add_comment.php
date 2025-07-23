<?php
// File: views/facilitator/add_comment.php
session_start();
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

$facilitator_id = $_SESSION['user_id'];
$facilitator_name = $_SESSION['username'] ?? 'Facilitator'; // Get facilitator's name for notification message
$department_id = $_SESSION['department_id'] ?? null;
$campus_id = $_SESSION['campus_id'] ?? null; // Ensure campus_id is retrieved from session

$message = '';
$messageType = '';

// Get submission ID from URL
$submission_id = filter_var($_GET['submission_id'] ?? null, FILTER_VALIDATE_INT);
$return_status = $_GET['return_status'] ?? 'submitted'; // Status to return to after commenting

if ($submission_id === false || !$submission_id) {
    $_SESSION['message'] = 'Invalid submission ID provided.';
    $_SESSION['message_type'] = 'danger';
    header("Location: manage_submissions.php"); // Redirect to a safe default
    exit();
}

// Fetch submission details to display on the comment page
$submission = null;
if ($department_id && $campus_id) { // Ensure both department and campus are set for security
    $stmt = $conn->prepare("
        SELECT s.submission_id, s.reference_number, s.title, s.status, s.researcher_id, u.name as researcher_name,
        d.name AS department_name -- Added department name for display
        FROM submissions s
        JOIN users u ON s.researcher_id = u.user_id
        JOIN departments d ON s.department_id = d.department_id
        WHERE s.submission_id = ? AND s.department_id = ? AND s.campus_id = ? -- Added campus_id filter
    ");
    if ($stmt) {
        error_log("add_comment.php (Fetch Submission): Binding parameters 'iii' with values: submission_id=" . $submission_id . ", department_id=" . $department_id . ", campus_id=" . $campus_id);
        $stmt->bind_param("iii", $submission_id, $department_id, $campus_id); // Added campus_id to bind_param
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $submission = $result->fetch_assoc();
            } else {
                $_SESSION['message'] = 'Submission not found or you do not have access.';
                $_SESSION['message_type'] = 'danger';
                header("Location: manage_submissions.php?status=" . urlencode($return_status));
                exit();
            }
        } else {
            error_log("add_comment.php (Fetch Submission): Execute failed: " . $stmt->error);
            $_SESSION['message'] = 'Database error fetching submission details.';
            $_SESSION['message_type'] = 'danger';
            header("Location: manage_submissions.php?status=" . urlencode($return_status));
            exit();
        }
        $stmt->close();
    } else {
        error_log("add_comment.php (Fetch Submission): Failed to prepare statement: " . $conn->error);
        $_SESSION['message'] = 'Database error fetching submission details.';
        $_SESSION['message_type'] = 'danger';
        header("Location: manage_submissions.php?status=" . urlencode($return_status));
        exit();
    }
} else {
    $_SESSION['message'] = 'Facilitator department or campus not set in session.';
    $_SESSION['message_type'] = 'danger';
    header("Location: ../../auth/login.php"); // Redirect to login if session data is incomplete
    exit();
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_text'])) {
    $comment_text = trim($_POST['comment_text']);
    $comment_type = $_POST['comment_type'] ?? 'general'; // Added comment_type from form

    if (empty($comment_text)) {
        $message = 'Comment cannot be empty.';
        $messageType = 'warning';
    } else {
        // Use 'submission_comments' table as per your provided old code
        $stmt = $conn->prepare("INSERT INTO submission_comments (submission_id, user_id, comment_text, comment_type, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            error_log("add_comment.php (Insert Comment): Binding parameters 'iiss' with values: submission_id=" . $submission_id . ", facilitator_id=" . $facilitator_id . ", comment_text='" . $comment_text . "', comment_type='" . $comment_type . "'");
            $stmt->bind_param("iiss", $submission_id, $facilitator_id, $comment_text, $comment_type); // Added comment_type to bind_param
            if ($stmt->execute()) {

                // --- NEW CODE FOR NOTIFICATIONS STARTS HERE ---
                $researcher_id = $submission['researcher_id'];
                $reference_number = $submission['reference_number'] ?? 'N/A'; // Handle null reference number

                // Ensure the notification is for the researcher whose submission it is.
                if ($researcher_id && $researcher_id !== $facilitator_id) {
                    $notification_type = 'comment_added'; // Re-added notification type
                    // Link to the researcher's tracking page for this specific submission.
                    $notification_link = '../researcher/my_submissions.php?view_id=' . $submission_id; // Adjusted link for researcher view
                    $notification_message = htmlspecialchars($facilitator_name) . " added a new comment to your submission (Ref: **{$reference_number}**).";

                    // Modified INSERT statement for notifications: Re-added 'type' column
                    $insert_notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read) VALUES (?, ?, ?, ?, 0)");
                    if ($insert_notification_stmt) {
                        // Modified bind_param: Re-added 'type' parameter
                        $insert_notification_stmt->bind_param("isss", $researcher_id, $notification_type, $notification_message, $notification_link);
                        if (!$insert_notification_stmt->execute()) {
                            error_log("add_comment.php (Insert Notification): Failed to insert notification: " . $insert_notification_stmt->error);
                        }
                        $insert_notification_stmt->close();
                    } else {
                        error_log("add_comment.php (Insert Notification): Failed to prepare notification insert statement: " . $conn->error);
                    }
                }
                // --- NEW CODE FOR NOTIFICATIONS ENDS HERE ---

                $_SESSION['message'] = 'Comment added successfully for submission ' . htmlspecialchars($submission['reference_number'] ?? 'N/A') . '.';
                $_SESSION['message_type'] = 'success';
                header("Location: add_comment.php?submission_id=" . $submission_id . "&return_status=" . urlencode($return_status)); // Redirect back to comment page to show new comment
                exit();
            } else {
                error_log("add_comment.php (Insert Comment): Execute failed: " . $stmt->error);
                $message = 'Failed to add comment: ' . $stmt->error;
                $messageType = 'danger';
            }
            $stmt->close();
        } else {
            error_log("add_comment.php (Insert Comment): Failed to prepare statement: " . $conn->error);
            $message = 'Database error adding comment.';
            $messageType = 'danger';
        }
    }
}

// Fetch existing comments for the submission (using 'submission_comments' table)
$comments = []; // Initialize comments array
if ($submission_id) {
    $stmt = $conn->prepare("
        SELECT 
            c.comment_text, c.comment_type, c.created_at, u.name AS commenter_name, u.role AS commenter_role
        FROM submission_comments c -- Using submission_comments table
        JOIN users u ON c.user_id = u.user_id
        WHERE c.submission_id = ?
        ORDER BY c.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        $stmt->close();
    } else {
        error_log("DB error in add_comment.php (fetch comments): " . $conn->error);
    }
}

// Check for messages passed via GET after redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['msg_type'] ?? 'info';
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // Not directly applicable for this page, but kept for sidebar include
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Comment - <?= htmlspecialchars($submission['reference_number'] ?? 'Submission') ?></title>
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
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
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
            padding-right: 15px;
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
        .comment-item {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .comment-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .comment-meta {
            font-size: 0.85em;
            color: #6c757d;
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
        <?php include '_sidebar.php'; ?>

        <div id="main-content">
            <h4 class="mb-4">Add Comment for Submission: #<?= htmlspecialchars($submission['reference_number'] ?? 'N/A') ?></h4>
            <p class="text-muted"><strong>Title:</strong> <?= htmlspecialchars($submission['title'] ?? 'N/A') ?></p>
            <p class="text-muted"><strong>Current Status:</strong> <span class="badge bg-info"><?= htmlspecialchars(str_replace('_', ' ', $submission['status'] ?? 'N/A')) ?></span></p>
            <p class="text-muted"><strong>Researcher:</strong> <?= htmlspecialchars($submission['researcher_name'] ?? 'N/A') ?></p>
            <p class="text-muted"><strong>Department:</strong> <?= htmlspecialchars($submission['department_name'] ?? 'N/A') ?></p>


            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card p-4">
                <h5 class="mb-4">Add New Comment</h5>
                <form action="add_comment.php?submission_id=<?= htmlspecialchars($submission_id) ?>&return_status=<?= htmlspecialchars($return_status) ?>" method="POST">
                    <div class="mb-3">
                        <label for="comment_text" class="form-label">Your Comment:</label>
                        <textarea class="form-control" id="comment_text" name="comment_text" rows="5" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="comment_type" class="form-label">Comment Type:</label>
                        <select class="form-select" id="comment_type" name="comment_type">
                            <option value="general">General</option>
                            <option value="feedback">Feedback</option>
                            <option value="decision">Decision</option>
                            <option value="internal">Internal Note</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Comment</button>
                    <a href="manage_submissions.php?status=<?= htmlspecialchars($return_status) ?>" class="btn btn-secondary">Back to Submissions</a>
                </form>

                <h5 class="mt-5">Previous Comments</h5>
                <?php if (!empty($comments)): ?>
                    <div class="list-group">
                        <?php foreach ($comments as $comment): ?>
                            <div class="list-group-item comment-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($comment['commenter_name'] ?? 'Unknown User') ?> 
                                        <small class="text-muted">(<?= htmlspecialchars(ucfirst($comment['commenter_role'] ?? 'N/A')) ?>)</small>
                                    </h6>
                                    <small class="comment-meta"><?= date("F j, Y g:i A", strtotime($comment['created_at'])) ?></small>
                                </div>
                                <p class="mb-1"><?= htmlspecialchars($comment['comment_text'] ?? '') ?></p>
                                <small class="text-muted">Type: <?= htmlspecialchars(ucfirst($comment['comment_type'] ?? 'General')) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No comments yet for this submission.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn) {
    $conn->close();
}
?>
