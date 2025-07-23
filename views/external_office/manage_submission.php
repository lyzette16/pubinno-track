<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Check if user is logged in and is an external_office
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
    echo json_encode(['error' => 'Unauthorized access.']);
    exit();
}

$submission_id = $_GET['id'] ?? null;
$submission = null;
$submission_files = [];
$message = '';
$messageType = '';

if (!$submission_id) {
    $message = "No submission ID provided.";
    $messageType = "danger";
} else {
    // Fetch full submission details, including the name of the user who rejected it
    $sql = "SELECT s.submission_id, s.reference_number, s.title, s.description, s.abstract, s.file_path,
                   s.submission_date, s.status, s.submission_type, s.pub_type_id, s.updated_at,
                   u.name AS researcher_name, d.name AS department_name, pt.type_name AS pub_type_name,
                   rejector.name AS rejected_by_name
            FROM submissions s
            JOIN users u ON s.researcher_id = u.user_id
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN publication_types pt ON s.pub_type_id = pt.pub_type_id
            LEFT JOIN users rejector ON s.rejected_by = rejector.user_id -- Join to get rejector's name
            WHERE s.submission_id = ? AND s.campus_id = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $submission_id, $_SESSION['campus_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $submission = $result->fetch_assoc();
        $stmt->close();

        if (!$submission) {
            $message = "Submission not found or you do not have permission to view it.";
            $messageType = "danger";
        } else {
            // Fetch associated files from submission_files table
            $files_sql = "SELECT sf.file_id, sf.file_name, sf.file_path, sf.file_mime_type, rm.requirement_name
                          FROM submission_files sf
                          JOIN requirements_master rm ON sf.requirement_id = rm.requirement_id
                          WHERE sf.submission_id = ?";
            $files_stmt = $conn->prepare($files_sql);
            if ($files_stmt) {
                $files_stmt->bind_param("i", $submission_id);
                $files_stmt->execute();
                $files_result = $files_stmt->get_result();
                while ($file_row = $files_result->fetch_assoc()) {
                    $submission_files[] = $file_row;
                }
                $files_stmt->close();
            } else {
                error_log("Manage Submission Files SQL Prepare Error: " . $conn->error);
            }
        }
    } else {
        $message = "Database error fetching submission details: " . $conn->error;
        $messageType = "danger";
        error_log("Manage Submission SQL Prepare Error: " . $conn->error);
    }
}

$conn->close();

// Output only the HTML content for the modal body
?>
<div class="p-6"> <!-- This div will be injected into the modal content area on the parent page -->
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Submission Details</h2>

    <?php if (!empty($message)): ?>
        <div class="p-4 mb-4 text-sm rounded-md
            <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : '' ?>
            <?= $messageType === 'danger' ? 'bg-red-100 text-red-700' : '' ?>
            <?= $messageType === 'info' ? 'bg-blue-100 text-blue-700' : '' ?>" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="float-right text-lg font-semibold leading-none" onclick="parent.closeModal();">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($submission): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700 mb-6">
            <div>
                <p class="font-semibold">Reference Number:</p>
                <p><?= htmlspecialchars($submission['reference_number']) ?></p>
            </div>
            <div>
                <p class="font-semibold">Title:</p>
                <p><?= htmlspecialchars($submission['title']) ?></p>
            </div>
            <div>
                <p class="font-semibold">Researcher:</p>
                <p><?= htmlspecialchars($submission['researcher_name']) ?></p>
            </div>
            <div>
                <p class="font-semibold">Department:</p>
                <p><?= htmlspecialchars($submission['department_name'] ?? 'N/A') ?></p>
            </div>
            <div>
                <p class="font-semibold">Submission Type:</p>
                <p><?= htmlspecialchars(ucwords(str_replace('_', ' ', $submission['submission_type']))) ?></p>
            </div>
            <div>
                <p class="font-semibold">Publication Type:</p>
                <p><?= htmlspecialchars($submission['pub_type_name'] ?? 'N/A') ?></p>
            </div>
            <div>
                <p class="font-semibold">Submission Date:</p>
                <p><?= date('M d, Y h:i A', strtotime($submission['submission_date'])) ?></p>
            </div>
            <div class="md:col-span-2">
                <p class="font-semibold">Status:</p>
                <p>
                    <span class="px-2 inline-flex text-sm leading-5 font-semibold rounded-full status-badge
                        <?= $submission['status'] === 'under_external_review' ? 'status-under_external_review' : '' ?>
                        <?= $submission['status'] === 'accepted_by_external' ? 'status-accepted_by_external' : '' ?>
                        <?= $submission['status'] === 'accepted_by_pio' ? 'status-accepted_by_pio' : '' ?>
                        <?= $submission['status'] === 'approved' ? 'status-approved' : '' ?>
                        <?= $submission['status'] === 'rejected' ? 'status-rejected' : '' ?>">
                        <?= ucwords(str_replace('_', ' ', $submission['status'])) ?>
                    </span>
                </p>
            </div>
            <?php if ($submission['status'] === 'rejected'): ?>
                <div class="md:col-span-2">
                    <p class="font-semibold">Rejected By:</p>
                    <p><?= htmlspecialchars($submission['rejected_by_name'] ?? 'N/A') ?></p>
                </div>
                <div class="md:col-span-2">
                    <p class="font-semibold">Rejection Date:</p>
                    <p><?= date('M d, Y h:i A', strtotime($submission['updated_at'])) ?></p>
                </div>
            <?php endif; ?>
            <div class="md:col-span-2">
                <p class="font-semibold">Description:</p>
                <p class="bg-gray-50 p-3 rounded-md text-sm leading-relaxed"><?= htmlspecialchars($submission['description']) ?></p>
            </div>
            <div class="md:col-span-2">
                <p class="font-semibold">Abstract:</p>
                <p class="bg-gray-50 p-3 rounded-md text-sm leading-relaxed"><?= htmlspecialchars($submission['abstract']) ?></p>
            </div>
            
            <!-- Main Submission File Section -->
            <div class="md:col-span-2 mt-4">
                <p class="font-semibold text-lg mb-2">Main Submission File:</p>
                <?php if (!empty($submission['file_path'])): ?>
                    <div class="flex items-center space-x-3 bg-gray-50 p-3 rounded-md shadow-sm">
                        <i class="bi bi-file-earmark-arrow-down text-blue-500 text-xl"></i>
                        <div>
                            <p class="font-medium text-gray-800">Main Article</p>
                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                Download Main File
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600">No main submission file uploaded.</p>
                <?php endif; ?>
            </div>

            <!-- Associated Files Section -->
            <div class="md:col-span-2 mt-4">
                <p class="font-semibold text-lg mb-2">Associated Files:</p>
                <?php if (!empty($submission_files)): ?>
                    <ul class="space-y-2">
                        <?php foreach ($submission_files as $file): ?>
                            <li class="flex items-center space-x-3 bg-gray-50 p-3 rounded-md shadow-sm">
                                <i class="bi bi-file-earmark-text text-blue-500 text-xl"></i>
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($file['requirement_name'] ?? 'File') ?></p>
                                    <a href="<?= htmlspecialchars($file['file_path']) ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                        <?= htmlspecialchars($file['file_name']) ?>
                                    </a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-gray-600">No additional associated files found for this submission.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 mt-6">
            <?php if ($submission['status'] === 'under_external_review'): ?>
                <button onclick="parent.updateStatus(<?= $submission['submission_id'] ?>, 'accepted_by_external')"
                        class="px-5 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors duration-200">
                    Accept Submission
                </button>
                <button onclick="parent.confirmReject(<?= $submission['submission_id'] ?>)"
                        class="px-5 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors duration-200">
                    Reject Submission
                </button>
            <?php elseif ($submission['status'] === 'accepted_by_external'): ?>
                <button onclick="parent.updateStatus(<?= $submission['submission_id'] ?>, 'approved')"
                        class="px-5 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors duration-200">
                    Approve Submission
                </button>
                <button onclick="parent.confirmReject(<?= $submission['submission_id'] ?>)"
                        class="px-5 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors duration-200">
                    Reject Submission
                </button>
            <?php endif; ?>
            <button onclick="parent.openCommentWindow(<?= $submission['submission_id'] ?>)"
                    class="px-5 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors duration-200">
                Comment
            </button>
            <button onclick="parent.closeModal()"
                    class="px-5 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                Close
            </button>
        </div>
    <?php endif; ?>
</div>

<script>
    // These functions are now expected to be defined in the parent window (e.g., new_submissions.php or accepted_submission.php)
    // and are called via `parent.functionName()`.
    // The custom message and confirmation dialogs will also be handled by the parent.
</script>
