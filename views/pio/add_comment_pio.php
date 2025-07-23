<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer'; // Get PIO's name/email for display
$pio_campus_id = $_SESSION['campus_id'] ?? null; // Retrieve PIO's campus_id from session

$message = '';
$messageType = '';

// Get submission ID from URL
$submission_id = filter_var($_GET['submission_id'] ?? null, FILTER_VALIDATE_INT);
$return_page = $_GET['return_page'] ?? 'dashboard.php'; // Page to return to after commenting

if ($submission_id === false || !$submission_id) {
    $_SESSION['message'] = 'Invalid submission ID provided.';
    $_SESSION['message_type'] = 'danger';
    header("Location: " . htmlspecialchars($return_page)); // Redirect to a safe default
    exit();
}

// Check if PIO has a campus_id set
if (!$pio_campus_id) {
    $_SESSION['message'] = 'Your PIO account is not associated with a campus. Cannot manage submissions.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php"); // Redirect to dashboard if campus_id is missing
    exit();
}

// Fetch submission details to display on the comment page, filtered by PIO's campus
$submission = null;
$stmt = $conn->prepare("
    SELECT 
        s.submission_id, s.reference_number, s.title, s.status, s.researcher_id, 
        u.name as researcher_name,
        d.name AS department_name,
        c.campus_name AS campus_name
    FROM submissions s
    JOIN users u ON s.researcher_id = u.user_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN campus c ON s.campus_id = c.campus_id
    WHERE s.submission_id = ? AND s.campus_id = ?
");
if ($stmt) {
    error_log("add_comment_pio.php (Fetch Submission): Binding parameters 'ii' with values: submission_id=" . $submission_id . ", pio_campus_id=" . $pio_campus_id);
    $stmt->bind_param("ii", $submission_id, $pio_campus_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $submission = $result->fetch_assoc();
        } else {
            $_SESSION['message'] = 'Submission not found or you do not have access to it on your campus.';
            $_SESSION['message_type'] = 'danger';
            header("Location: " . htmlspecialchars($return_page));
            exit();
        }
    } else {
        error_log("add_comment_pio.php (Fetch Submission): Execute failed: " . $stmt->error);
        $_SESSION['message'] = 'Database error fetching submission details.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . htmlspecialchars($return_page));
        exit();
    }
    $stmt->close();
} else {
    error_log("add_comment_pio.php (Fetch Submission): Failed to prepare statement: " . $conn->error);
    $_SESSION['message'] = 'Database error fetching submission details.';
    $_SESSION['message_type'] = 'danger';
    header("Location: " . htmlspecialchars($return_page));
    exit();
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_text'])) {
    $comment_text = trim($_POST['comment_text']);
    $comment_type = $_POST['comment_type'] ?? 'general';

    if (empty($comment_text)) {
        $message = 'Comment cannot be empty.';
        $messageType = 'warning';
    } else {
        // Use 'submission_comments' table
        $stmt = $conn->prepare("INSERT INTO submission_comments (submission_id, user_id, comment_text, comment_type, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            error_log("add_comment_pio.php (Insert Comment): Binding parameters 'iiss' with values: submission_id=" . $submission_id . ", pio_id=" . $pio_id . ", comment_text='" . $comment_text . "', comment_type='" . $comment_type . "'");
            $stmt->bind_param("iiss", $submission_id, $pio_id, $comment_text, $comment_type);
            if ($stmt->execute()) {

                // --- NEW CODE FOR NOTIFICATIONS STARTS HERE ---
                $researcher_id = $submission['researcher_id'];
                $reference_number = $submission['reference_number'] ?? 'N/A'; // Handle null reference number

                // Ensure the notification is for the researcher whose submission it is.
                if ($researcher_id && $researcher_id !== $pio_id) { // PIO sending notification to researcher
                    $notification_type = 'comment_added';
                    // Link to the researcher's tracking page for this specific submission.
                    $notification_link = '../researcher/my_submissions.php?view_id=' . $submission_id;
                    $notification_message = htmlspecialchars($pio_name) . " (PIO) added a new comment to your submission (Ref: **{$reference_number}**).";

                    $insert_notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read) VALUES (?, ?, ?, ?, 0)");
                    if ($insert_notification_stmt) {
                        $insert_notification_stmt->bind_param("isss", $researcher_id, $notification_type, $notification_message, $notification_link);
                        if (!$insert_notification_stmt->execute()) {
                            error_log("add_comment_pio.php (Insert Notification): Failed to insert notification: " . $insert_notification_stmt->error);
                        }
                        $insert_notification_stmt->close();
                    } else {
                        error_log("add_comment_pio.php (Insert Notification): Failed to prepare notification insert statement: " . $conn->error);
                    }
                }
                // --- NEW CODE FOR NOTIFICATIONS ENDS HERE ---

                $_SESSION['message'] = 'Comment added successfully for submission ' . htmlspecialchars($submission['reference_number'] ?? 'N/A') . '.';
                $_SESSION['message_type'] = 'success';
                // Redirect back to comment page to show new comment and clear POST data
                header("Location: add_comment_pio.php?submission_id=" . $submission_id . "&return_page=" . urlencode($return_page));
                exit();
            } else {
                error_log("add_comment_pio.php (Insert Comment): Execute failed: " . $stmt->error);
                $message = 'Failed to add comment: ' . $stmt->error;
                $messageType = 'danger';
            }
            $stmt->close();
        } else {
            error_log("add_comment_pio.php (Insert Comment): Failed to prepare statement: " . $conn->error);
            $message = 'Database error adding comment.';
            $messageType = 'danger';
        }
    }
}

// Fetch existing comments for the submission (using 'submission_comments' table)
$comments = [];
if ($submission_id) {
    $stmt = $conn->prepare("
        SELECT 
            c.comment_text, c.comment_type, c.created_at, u.name AS commenter_name, u.role AS commenter_role
        FROM submission_comments c
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
        error_log("DB error in add_comment_pio.php (fetch comments): " . $conn->error);
    }
}

// Check for messages passed via GET after redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['msg_type'] ?? 'info';
}

$currentPage = basename($_SERVER['PHP_SELF']);
// $currentStatus is not directly applicable here but might be used by included sidebar
$currentStatus = ''; 
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

    <div class="container-fluid main-content-wrapper">
        <?php include 'sidebar.php'; // Use PIO sidebar ?>

        <div id="main-content">
            <h4 class="mb-4">Add Comment for Submission: #<?= htmlspecialchars($submission['reference_number'] ?? 'N/A') ?></h4>
            <p class="text-muted"><strong>Title:</strong> <?= htmlspecialchars($submission['title'] ?? 'N/A') ?></p>
            <p class="text-muted"><strong>Current Status:</strong> <span class="badge bg-info"><?= htmlspecialchars(str_replace('_', ' ', $submission['status'] ?? 'N/A')) ?></span></p>
            <p class="text-muted"><strong>Researcher:</strong> <?= htmlspecialchars($submission['researcher_name'] ?? 'N/A') ?></p>
            <p class="text-muted"><strong>Department:</strong> <?= htmlspecialchars($submission['department_name'] ?? 'N/A') ?></p>
            <p class="text-muted"><strong>Campus:</strong> <?= htmlspecialchars($submission['campus_name'] ?? 'N/A') ?></p>


            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card p-4">
                <h5 class="mb-4">Add New Comment</h5>
                <form action="add_comment_pio.php?submission_id=<?= htmlspecialchars($submission_id) ?>&return_page=<?= htmlspecialchars($return_page) ?>" method="POST">
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
                    <a href="<?= htmlspecialchars($return_page) ?>" class="btn btn-secondary">Back to Submissions</a>
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
                                    <small class="comment-meta"><?= htmlspecialchars(date("F j, Y g:i A", strtotime($comment['created_at'] ?? 'now'))) ?></small>
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
