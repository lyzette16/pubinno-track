<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

$researcher_user_id = $_SESSION['user_id'];
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = trim($_GET['search'] ?? '');
$limit = 10;
$offset = ($page - 1) * $limit;

$where_clause = "user_id = ?";
$params = [$researcher_user_id];
$types = "i";

if ($filter === 'read') {
    $where_clause .= " AND is_read = 1";
} elseif ($filter === 'unread') {
    $where_clause .= " AND is_read = 0";
}

if (!empty($search)) {
    $where_clause .= " AND message LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $researcher_user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php?filter=$filter&search=" . urlencode($search));
    exit();
}

// Fetch filtered notifications with pagination
$sql = "SELECT notification_id, user_id, submission_id, message, link, is_read, created_at, type 
        FROM notifications 
        WHERE $where_clause 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count total notifications
$count_sql = "SELECT COUNT(*) FROM notifications WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param(substr($types, 0, -2), ...array_slice($params, 0, -2));
$count_stmt->execute();
$count_stmt->bind_result($total);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - Researcher Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f1f4f9;
        }
        .notification-card {
            cursor: pointer;
            border: none;
            border-left: 6px solid transparent;
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            transition: 0.2s;
        }
        .notification-card:hover {
            background-color: #f8fbff;
        }
        .notification-card.unread {
            border-left-color: #0d6efd;
            background-color: #e9f3ff;
        }
        /* New style for important/completion notifications */
        .notification-card.submission-complete {
            border-left-color: #28a745; /* Green border */
            background-color: #e6ffe6; /* Light green background */
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.2); /* Green shadow */
            font-weight: bold;
        }
        .icon-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #dbeafe;
            color: #0d6efd;
            margin-right: 1rem;
            font-size: 1.25rem;
        }
        /* Icon circle for important notifications */
        .notification-card.submission-complete .icon-circle {
            background-color: #d4edda; /* Lighter green */
            color: #28a745; /* Green */
        }
        .notification-title {
            font-weight: 600;
            font-size: 1.05rem;
        }
        .notification-time {
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
<?php include '_navbar_researcher.php'; ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-bell-fill me-2"></i>My Notifications</h3>
        <form method="get" class="d-flex align-items-center gap-2">
            <select name="filter" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread</option>
                <option value="read" <?= $filter === 'read' ? 'selected' : '' ?>>Read</option>
            </select>
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>

    <form method="get" class="mb-3">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <div class="input-group">
            <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by title or reference number">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </div>
    </form>

    <form method="post" class="mb-4">
        <button type="submit" name="mark_all_read" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-eye-fill me-1"></i> Mark All as Read
        </button>
    </form>

    <?php if (empty($notifications)): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle-fill me-2"></i> No notifications found.</div>
    <?php else: ?>
        <?php foreach ($notifications as $n): ?>
            <?php
            // Extract the main message and optional comment part
            $message_parts = explode("\n\nComment: \"", $n['message'], 2);
            $raw_main_message = $message_parts[0]; // This is the part before "Comment: "
            $comment_content = isset($message_parts[1]) ? rtrim($message_parts[1], '"') : '';

            // Remove markdown bolding from the raw main message for parsing
            $clean_main_message = str_replace('**', '', $raw_main_message);

            $submission_title_extracted = 'N/A';
            $reference_number_extracted = 'N/A';
            $modal_status_description = "A general update regarding your submission."; // Default modal description

            // Regex to extract title and reference number (more robust)
            if (preg_match('/\"([^\"]+)\" \(Ref: ([^)]+)\)/', $clean_main_message, $matches)) {
                $submission_title_extracted = $matches[1];
                $reference_number_extracted = $matches[2];
            } elseif (preg_match('/\(Ref: ([^)]+)\)/', $clean_main_message, $matches)) { // Fallback for messages without title but with ref
                 $reference_number_extracted = $matches[1];
            }


            // Determine the specific action/status phrase and set the card title and modal description
            $card_title_prefix = "Notification"; // Default card title prefix
            $icon = 'bi-bell-fill'; // Default icon

            // Prioritize specific notification types or explicit status phrases
            if ($n['type'] === 'submission_complete') {
                $card_title_prefix = "Submission Process Complete";
                $modal_status_description = "The review and approval process for your submission is now complete.";
                $icon = 'bi-award-fill';
            } elseif (str_contains($clean_main_message, 'new comment')) {
                $card_title_prefix = "New Comment";
                $modal_status_description = "A new comment has been added to your submission.";
                $icon = 'bi-chat-left-text-fill';
            } elseif (str_contains($clean_main_message, 'rejected')) {
                $card_title_prefix = "Submission Rejected";
                $modal_status_description = "Your submission has been rejected.";
                $icon = 'bi-x-circle-fill';
            } elseif (str_contains($clean_main_message, 'approved')) { // Check for 'approved' first before 'accepted by external'
                $card_title_prefix = "Submission Approved";
                $modal_status_description = "Your submission has been approved.";
                $icon = 'bi-patch-check-fill';
            } elseif (str_contains($clean_main_message, 'accepted by external')) {
                $card_title_prefix = "Submission Accepted by External Office"; // More specific and clear
                $modal_status_description = "Your submission has been accepted by the External Office for review.";
                $icon = 'bi-check-circle-fill';
            } elseif (str_contains($clean_main_message, 'forwarded') || str_contains($clean_main_message, 'under review')) {
                $card_title_prefix = "Submission Status Update";
                $modal_status_description = "Your submission status has been updated and is currently under review.";
                $icon = 'bi-hourglass-split';
            } else {
                // Fallback for any other general message format
                $modal_status_description = htmlspecialchars($clean_main_message); // Show the clean message as description
            }

            // Construct the display title for the notification card
            // Ensure title and ref are always included if extracted
            if ($submission_title_extracted !== 'N/A' && $reference_number_extracted !== 'N/A') {
                $display_title = $card_title_prefix . ": \"" . htmlspecialchars($submission_title_extracted) . "\" (Ref: " . htmlspecialchars($reference_number_extracted) . ")";
            } elseif ($reference_number_extracted !== 'N/A') {
                $display_title = $card_title_prefix . " (Ref: " . htmlspecialchars($reference_number_extracted) . ")";
            } else {
                $display_title = $card_title_prefix; // Just the prefix if no title/ref found
            }
            ?>
            <div class="notification-card <?= !$n['is_read'] ? 'unread' : '' ?> <?= $n['type'] === 'submission_complete' ? 'submission-complete' : '' ?>" data-bs-toggle="modal" data-bs-target="#notifModal<?= $n['notification_id'] ?>">
                <div class="d-flex">
                    <div class="icon-circle"><i class="bi <?= $icon ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="notification-title"><?= htmlspecialchars($display_title) ?></div>
                        <div class="notification-time"><i class="bi bi-clock me-1"></i> <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Modal for Notification Details -->
            <div class="modal fade" id="notifModal<?= $n['notification_id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi <?= $icon ?> me-2"></i>Notification Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Display the parsed title -->
                            <p><strong>Subject:</strong> <?= htmlspecialchars($display_title) ?></p>
                            <p><strong>Status Update:</strong> <?= htmlspecialchars($modal_status_description) ?></p>
                            <?php if (!empty($comment_content)): ?>
                                <p><strong>Comment:</strong> <?= htmlspecialchars($comment_content) ?></p>
                            <?php endif; ?>
                            <p><strong>Received:</strong> <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></p>
                            <?php if ($n['link']): ?>
                                <a href="<?= htmlspecialchars($n['link']) ?>" class="btn btn-primary mt-3" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i> View Evidence/Link</a>
                            <?php endif; ?>
                            <form method="post" action="mark_unread.php" class="mt-3">
                                <input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-eye-slash-fill me-1"></i> Mark as Unread
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <nav>
            <ul class="pagination justify-content-center mt-4">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('show.bs.modal', function () {
            const notifId = this.id.replace('notifModal', '');
            fetch('mark_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + encodeURIComponent(notifId)
            }).then(response => {
                if (response.ok) {
                    const notifCard = document.querySelector('[data-bs-target="#notifModal' + notifId + '"]');
                    if (notifCard) notifCard.classList.remove('unread');
                }
            });
        });
    });
});
</script>
</body>
</html>
