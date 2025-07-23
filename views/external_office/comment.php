<?php
// File: views/external_office/comment.php (for modal content)
ob_start(); // Start output buffering

session_start();
require_once '../../config/config.php';

// Attempt to connect to the database. Wrap in try-catch for robustness.
$conn = null;
try {
    require_once '../../config/connect.php'; // This file should establish $conn
    if (!isset($conn) || !$conn instanceof mysqli) {
        throw new Exception("Database connection failed to establish in connect.php.");
    }
} catch (Exception $e) {
    error_log("Comment.php: Database connection error: " . $e->getMessage());
    echo '<div class="p-4 text-red-700 bg-red-100 rounded-md">Database connection error. Please try again later.</div>';
    exit(); // Exit immediately if connection fails
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log script start
error_log("Comment.php: Script execution started. Request method: " . $_SERVER['REQUEST_METHOD']);

// Ensure the user is logged in and is an external_office
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
    error_log("Comment.php: Unauthorized access attempt - role not external_office.");
    // Output a simple error message for direct access or failed AJAX load
    echo '<div class="p-4 text-red-700 bg-red-100 rounded-md">Unauthorized access. Please log in.</div>';
    // Ensure connection is closed before exiting on unauthorized access
    if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
        $conn->close();
    }
    exit();
}

$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['name'] ?? 'External Office'; // Get user's name for notification message

if (!$user_id) {
    error_log("Comment.php: User ID not found in session for external_office.");
    echo '<div class="p-4 text-red-700 bg-red-100 rounded-md">User ID not found in session. Please re-login.</div>';
    // Ensure connection is closed before exiting on missing user ID
    if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
        $conn->close();
    }
    exit();
}

// Get submission ID from GET request (for initial modal load)
$submission_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$submission_details = null;
$comments = []; // Initialize comments array

// --- Handle POST request for adding a comment (AJAX submission) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set content type for JSON response
    header('Content-Type: application/json');
    error_log("Comment.php (POST): Request received.");

    $post_submission_id = filter_var($_POST['submission_id'] ?? null, FILTER_VALIDATE_INT);
    $comment_text = trim($_POST['comment_text'] ?? '');
    $comment_type = trim($_POST['comment_type'] ?? 'external_office_comment'); 

    // Validate POST inputs
    if (!$post_submission_id) {
        error_log("Comment.php (POST): Invalid submission ID in POST data: " . ($_POST['submission_id'] ?? 'NULL'));
        echo json_encode(['success' => false, 'message' => 'Invalid submission ID provided.']);
        // Close connection before exiting
        if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) { $conn->close(); }
        exit(); 
    }
    if (empty($comment_text)) {
        error_log("Comment.php (POST): Comment text is empty for submission ID: " . $post_submission_id);
        echo json_encode(['success' => false, 'message' => 'Comment text cannot be empty.']);
        // Close connection before exiting
        if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) { $conn->close(); }
        exit(); 
    }

    // Start database transaction
    $conn->begin_transaction();
    error_log("Comment.php (POST): Transaction started for submission ID: " . $post_submission_id);

    try {
        // 1. Insert comment into the database
        $stmt_insert_comment = $conn->prepare("INSERT INTO submission_comments (submission_id, user_id, comment_text, comment_type, comment_date) VALUES (?, ?, ?, ?, NOW())");
        if (!$stmt_insert_comment) {
            error_log("Comment.php (POST): Failed to prepare comment insert statement: " . $conn->error);
            throw new Exception("Failed to prepare comment insert statement: " . $conn->error);
        }
        $stmt_insert_comment->bind_param("iiss", $post_submission_id, $user_id, $comment_text, $comment_type);
        if (!$stmt_insert_comment->execute()) {
            error_log("Comment.php (POST): Error executing comment insert: " . $stmt_insert_comment->error);
            throw new Exception("Error adding comment: " . $stmt_insert_comment->error);
        }
        $stmt_insert_comment->close();
        error_log("Comment.php (POST): Comment inserted successfully for submission ID: " . $post_submission_id);

        // 2. Fetch submission info for notification
        $stmt_sub_info = $conn->prepare("SELECT researcher_id, reference_number, title FROM submissions WHERE submission_id = ?");
        if (!$stmt_sub_info) {
            error_log("Comment.php (POST): Failed to prepare submission info statement for notification: " . $conn->error);
            throw new Exception("Failed to prepare submission info statement for notification: " . $conn->error);
        }
        $stmt_sub_info->bind_param("i", $post_submission_id);
        $stmt_sub_info->execute();
        $result_sub_info = $stmt_sub_info->get_result();
        $sub_info = $result_sub_info->fetch_assoc();
        $stmt_sub_info->close();

        // 3. Send notification to the researcher (if submission info found)
        if ($sub_info) {
            $researcher_id = $sub_info['researcher_id'];
            $reference_number = $sub_info['reference_number'] ?? 'N/A';
            $submission_title = $sub_info['title'] ?? 'N/A';

            $notification_message = htmlspecialchars($user_name) . " added a new comment to your submission: \"**" . htmlspecialchars($submission_title) . "**\" (Ref: **{$reference_number}**).";
            $notification_message .= "\n\nComment: \"" . htmlspecialchars($comment_text) . "\"";
            $notification_link = "../researcher/my_submissions.php?view_id=" . $post_submission_id; // Link to researcher's view

            $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, submission_id) VALUES (?, ?, ?, ?, 0, ?)");
            if (!$stmt_notify) {
                error_log("Comment.php (POST): Failed to prepare notification insert statement: " . $conn->error);
                // Don't throw a full exception if notification fails, just log it.
            } else {
                $notification_type = 'new_comment'; // Ensure this matches your DB enum for type
                $stmt_notify->bind_param("isssi", $researcher_id, $notification_type, $notification_message, $notification_link, $post_submission_id);
                if (!$stmt_notify->execute()) {
                    error_log("Comment.php (POST): Failed to insert notification for researcher_id {$researcher_id}: " . $stmt_notify->error);
                }
                $stmt_notify->close();
                error_log("Comment.php (POST): Notification sent to researcher_id: " . $researcher_id);
            }
        } else {
            error_log("Comment.php (POST): Submission info not found for notification for submission ID: " . $post_submission_id);
        }

        // Commit transaction if all operations successful
        $conn->commit();
        error_log("Comment.php (POST): Transaction committed successfully.");
        echo json_encode(['success' => true, 'message' => 'Comment added successfully!']);
        // Close connection before exiting
        if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) { $conn->close(); }
        exit(); // Crucial: terminate script after successful JSON response

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Comment.php (POST): Transaction rolled back. Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => "Error adding comment: " . $e->getMessage()]);
        // Close connection before exiting
        if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) { $conn->close(); }
        exit(); // Crucial: terminate script after error JSON response
    }
}

// --- Handle GET request (initial modal load and refresh after POST) ---
// This part of the code will only execute if it's a GET request (initial load or iframe reload)
if ($submission_id) {
    // 1. Fetch main submission details
    $stmt_sub = $conn->prepare("SELECT title, reference_number, status FROM submissions WHERE submission_id = ?");
    if ($stmt_sub) {
        $stmt_sub->bind_param("i", $submission_id);
        $stmt_sub->execute();
        $result_sub = $stmt_sub->get_result();
        if ($result_sub->num_rows > 0) {
            $submission_details = $result_sub->fetch_assoc();
        }
        $stmt_sub->close();
    } else {
        error_log("Comment.php (GET): Failed to prepare submission details statement: " . $conn->error);
    }

    // 2. Fetch existing comments for the submission
    $stmt_comments = $conn->prepare("
        SELECT 
            c.comment_text, c.comment_type, c.comment_date, u.name AS commenter_name, u.role AS commenter_role
        FROM submission_comments c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.submission_id = ?
        ORDER BY c.comment_date DESC
    ");
    if ($stmt_comments) {
        $stmt_comments->bind_param("i", $submission_id);
        $stmt_comments->execute();
        $result_comments = $stmt_comments->get_result();
        while ($row = $result_comments->fetch_assoc()) {
            $comments[] = $row;
        }
        $stmt_comments->close();
    } else {
        error_log("Comment.php (GET): DB error fetching comments: " . $conn->error);
    }
} else {
    error_log("Comment.php (GET): No submission ID provided in GET request.");
}

// Log that the script reached the final connection close block (only for GET requests)
error_log("Comment.php: Script reached final connection close block (GET request).");

// Close the database connection only if it's an active mysqli object and still connected
if (isset($conn) && $conn instanceof mysqli) {
    if ($conn->thread_id) { // Check if the connection is still alive
        try {
            $conn->close();
            error_log("Comment.php: MySQLi connection successfully closed at end of script (GET).");
        } catch (Throwable $e) {
            error_log("Comment.php: Error closing MySQLi connection at end of script (GET): " . $e->getMessage());
        }
    } else {
        error_log("Comment.php: MySQLi connection object was valid, but already closed (thread_id is false/0) at end of script (GET). Not attempting to close.");
    }
} else {
    error_log("Comment.php: MySQLi connection object is not set or not a mysqli instance at end of script (GET). Not attempting to close.");
}

ob_end_flush(); // End output buffering and send output
?>
<div class="p-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Add Comment to Submission</h2>

    <?php if ($submission_details): ?>
        <p class="text-gray-700"><strong>Submission Title:</strong> <?= htmlspecialchars($submission_details['title'] ?? 'N/A') ?></p>
        <p class="text-gray-700"><strong>Reference Number:</strong> <?= htmlspecialchars($submission_details['reference_number'] ?? 'N/A') ?></p>
        <p class="text-gray-700 mb-4"><strong>Current Status:</strong> 
            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $submission_details['status'] ?? 'N/A'))) ?>
            </span>
        </p>
        <hr class="my-4 border-gray-200">

        <form id="commentForm" class="space-y-4">
            <input type="hidden" name="submission_id" value="<?= htmlspecialchars($submission_id ?? '') ?>">
            
            <div>
                <label for="comment_text" class="block text-sm font-medium text-gray-700">Comment</label>
                <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2" 
                          id="comment_text" name="comment_text" rows="5" required placeholder="Enter your comment here..."></textarea>
            </div>
            <div>
                <label for="comment_type" class="block text-sm font-medium text-gray-700">Comment Type</label>
                <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2" 
                        id="comment_type" name="comment_type">
                    <option value="external_office_comment">General Comment</option>
                    <option value="external_office_comment">Feedback</option>
                    <option value="external_office_comment">Rejection Reason</option>
                    <option value="external_office_comment">Clarification</option>
                </select>
            </div>
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="parent.closeCommentModal()"
                        class="px-5 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                    Cancel
                </button>
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors duration-200">
                    Add Comment
                </button>
            </div>
        </form>

        <h3 class="text-xl font-bold text-gray-800 mt-8 mb-4">Previous Comments</h3>
        <?php if (!empty($comments)): ?>
            <div class="space-y-4">
                <?php foreach ($comments as $comment): ?>
                    <div class="bg-gray-50 p-4 rounded-md border border-gray-200 shadow-sm">
                        <div class="flex justify-between items-center mb-2">
                            <p class="text-sm font-semibold text-gray-800">
                                <?= htmlspecialchars($comment['commenter_name'] ?? 'Unknown User') ?> 
                                <span class="text-gray-500 text-xs">(<?= htmlspecialchars(ucfirst($comment['commenter_role'] ?? 'N/A')) ?>)</span>
                            </p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars(date("F j, Y g:i A", strtotime($comment['comment_date'] ?? 'now'))) ?>
                            </p>
                        </div>
                        <p class="text-gray-700 text-sm mb-2"><?= htmlspecialchars($comment['comment_text'] ?? '') ?></p>
                        <p class="text-xs text-gray-600">Type: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $comment['comment_type'] ?? 'General'))) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-4 text-gray-700 bg-gray-100 rounded-md">No comments yet for this submission.</div>
        <?php endif; ?>

    <?php else: ?>
        <div class="p-4 text-orange-700 bg-orange-100 rounded-md">Submission details not found. Please ensure a valid submission ID is provided.</div>
        <div class="flex justify-end mt-4">
            <button type="button" onclick="parent.closeCommentModal()"
                    class="px-5 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                Close
            </button>
        </div>
    <?php endif; ?>
</div>

<script>
    // This script handles the form submission via AJAX
    document.getElementById('commentForm').addEventListener('submit', async function(event) {
        event.preventDefault(); // Prevent default form submission

        const formData = new FormData(this);
        const submissionId = formData.get('submission_id');
        const commentText = formData.get('comment_text');
        const commentType = formData.get('comment_type'); // Get the selected comment type

        // --- Debugging logs for client-side data ---
        console.log('Attempting to submit comment:');
        console.log('Submission ID:', submissionId);
        console.log('Comment Text:', commentText);
        console.log('Comment Type:', commentType);
        // --- End Debugging logs ---

        try {
            const response = await fetch('comment.php', { // POST to itself to handle logic
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // Pass all form data
                body: `submission_id=${submissionId}&comment_text=${encodeURIComponent(commentText)}&comment_type=${encodeURIComponent(commentType)}`
            });

            // Log the raw response status and text
            console.log('Fetch response status:', response.status);
            const responseText = await response.text(); // Get raw response text
            console.log('Fetch response raw text:', responseText);

            let result;
            try {
                result = JSON.parse(responseText); // Try to parse as JSON
            } catch (jsonError) {
                console.error('Failed to parse JSON response:', jsonError);
                console.error('Non-JSON response received:', responseText);
                // Inform the user about the unexpected response
                if (typeof parent.showMessage === 'function') {
                    parent.showMessage('An unexpected error occurred: Server did not return valid JSON. Check console for details.', 'danger');
                } else {
                    alert('An unexpected error occurred: Server did not return valid JSON. Check console for details.');
                }
                return; // Exit if JSON parsing fails
            }

            if (result.success) {
                if (typeof parent.showMessage === 'function') {
                    parent.showMessage(result.message, 'success');
                } else {
                    alert(result.message);
                }
                // Force reload the iframe content to show the new comment
                // This ensures the PHP script is re-executed and fetches the latest data.
                setTimeout(() => {
                    window.location.reload(); // Reloads the iframe itself
                }, 500); // Give a short delay for message to be seen
            } else {
                if (typeof parent.showMessage === 'function') {
                    parent.showMessage('Error: ' + result.message, 'danger');
                } else {
                    alert('Error: ' + result.message);
                }
            }
        } catch (error) {
            console.error('Error submitting comment:', error);
            if (typeof parent.showMessage === 'function') {
                parent.showMessage('An error occurred while submitting your comment.', 'danger');
            } else {
                alert('An error occurred while submitting your comment.');
            }
        }
    });
</script>
