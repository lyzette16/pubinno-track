<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Check if user is logged in and is an external_office
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
    header("Location: ../../auth/login.php");
    exit();
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$user_name = htmlspecialchars($_SESSION['name'] ?? 'External User');
$current_user_id = $_SESSION['user_id'] ?? null; // Get the ID of the current logged-in user
$external_office_campus_id = $_SESSION['campus_id'] ?? null;
$external_office_department_id = $_SESSION['department_id'] ?? null;

$submissions = [];
$message = '';
$messageType = '';

// Check for and display session messages (e.g., after a redirect)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Get search parameter
$search_query = $_GET['search'] ?? '';

// Fetch submissions relevant to the external office with status 'rejected'
if (!$external_office_campus_id || !$current_user_id) {
    $message = 'Your External Office account is not associated with a campus or user ID not found. Please contact support.';
    $messageType = 'danger';
} else {
    // Base query to fetch submissions with status 'rejected' AND rejected by the current user
    $sql = "SELECT s.submission_id, s.reference_number, s.title, s.submission_date, s.status, s.updated_at,
                   u.name AS researcher_name, d.name AS department_name,
                   rejector.name AS rejected_by_name
            FROM submissions s
            JOIN users u ON s.researcher_id = u.user_id
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN users rejector ON s.rejected_by = rejector.user_id -- Corrected to rejected_by
            WHERE s.campus_id = ? AND s.status = 'rejected' AND s.rejected_by = ?"; // Corrected to rejected_by

    $params = [$external_office_campus_id, $current_user_id];
    $types = "ii";

    // Add search query filter
    if (!empty($search_query)) {
        $search_term = '%' . $search_query . '%';
        $sql .= " AND (s.reference_number LIKE ? OR s.title LIKE ? OR u.name LIKE ? OR d.name LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ssss";
    }

    // If the external office is associated with a specific department, filter by it
    if ($external_office_department_id) {
        $sql .= " AND s.department_id = ?";
        $params[] = $external_office_department_id;
        $types .= "i";
    }

    $sql .= " ORDER BY s.updated_at DESC"; // Order by rejection timestamp

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $submissions[] = $row;
        }
        $stmt->close();
    } else {
        $message = 'Database error fetching submissions: ' . $conn->error;
        $messageType = 'danger';
        error_log("Rejected Submissions SQL Prepare Error: " . $conn->error);
    }
}

$conn->close();

// Variables for sidebar active state (used in sidebar.php)
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejected Submissions</title>
    <!-- Tailwind CSS for styling -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons for icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        /* Custom styles to apply the Inter font */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc; /* A light gray background */
        }
        /* Hide scrollbar for webkit browsers */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
        /* Custom status badge colors - these are no longer directly used in the table but kept for other views */
        .status-badge.status-under_external_review { background-color: #ffc107; color: #343a40; }
        .status-badge.status-accepted_by_pio { background-color: #10B981; color: #fff; }
        .status-badge.status-approved { background-color: #28a745; color: #fff; }
        .status-badge.status-rejected { background-color: #dc3545; color: #fff; }
        .status-badge.status-accepted_by_external { background-color: #28a745; color: #fff; }

        /* Modal specific styles (copied from new_submissions.php) */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000; /* Ensure it's on top */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-container {
            background-color: #fff;
            border-radius: 0.75rem; /* rounded-xl */
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); /* shadow-xl */
            max-width: 90%;
            max-height: 90vh; /* Limit height to viewport height */
            overflow-y: auto; /* Enable scrolling for modal content */
            position: relative;
            width: 800px; /* Fixed width for larger modal */
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        .modal-overlay.show .modal-container {
            transform: translateY(0);
        }
        #commentModal .modal-container {
            width: 600px; /* Smaller width for comment modal */
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php include 'header.php'; // Include the header ?>

    <div class="flex flex-1 overflow-hidden">
        <?php include 'sidebar.php'; // Include the sidebar ?>

        <!-- Main Content -->
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">Rejected Submissions</h1>

            <?php if (!empty($message)): ?>
                <div class="p-4 mb-4 text-sm rounded-md
                    <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : '' ?>
                    <?= $messageType === 'danger' ? 'bg-red-100 text-red-700' : '' ?>
                    <?= $messageType === 'info' ? 'bg-blue-100 text-blue-700' : '' ?>" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="float-right text-lg font-semibold leading-none" onclick="this.parentElement.style.display='none';">&times;</button>
                </div>
            <?php endif; ?>

            <div class="bg-white p-6 rounded-xl shadow-md mt-6">
                <h5 class="text-xl font-semibold text-gray-700 mb-4">List of Rejected Submissions</h5>
                
                <!-- Search Form -->
                <form method="GET" class="mb-6 flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-4">
                    <div class="relative w-full sm:w-1/2 lg:w-1/3">
                        <input type="text" name="search" placeholder="Search by Ref. No., Title, Researcher, Dept."
                               class="pl-10 pr-4 py-2 w-full rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               value="<?= htmlspecialchars($search_query) ?>">
                        <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Search
                    </button>
                    <?php if (!empty($search_query)): ?>
                        <a href="rejected_submissions.php" class="w-full sm:w-auto px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 text-center">
                            Reset Search
                        </a>
                    <?php endif; ?>
                </form>

                <?php if (empty($submissions)): ?>
                    <div class="text-gray-600 text-sm p-4 bg-blue-50 rounded-md">
                        No rejected submissions found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ref. No.</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Researcher</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rejected By</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rejection Date</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($submissions as $submission): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($submission['reference_number']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($submission['title']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($submission['researcher_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($submission['department_name'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($submission['rejected_by_name'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= date('M d, Y h:i A', strtotime($submission['updated_at'])) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <!-- View Details button -->
                                            <a href="#" onclick="openManageSubmission(<?= $submission['submission_id'] ?>); return false;"
                                               class="text-blue-600 hover:text-blue-900">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- The Modals Structure (copied from new_submissions.php and accepted_submission.php) -->
    <!-- Submission Details Modal -->
    <div id="submissionModal" class="modal-overlay">
        <div class="modal-container">
            <div id="modalContent">
                <!-- Content from manage_submission.php will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Comment Modal -->
    <div id="commentModal" class="modal-overlay">
        <div class="modal-container">
            <div id="commentModalContent">
                <!-- Content from comment.php will be loaded here -->
            </div>
        </div>
    </div>


    <script>
        // --- Sidebar Toggle Logic (copied for consistency) ---
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('-translate-x-full');
            });

            document.addEventListener('click', (event) => {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMenuToggle = menuToggle.contains(event.target);
                const isSidebarVisible = !sidebar.classList.contains('-translate-x-full');

                if (!isClickInsideSidebar && !isClickOnMenuToggle && isSidebarVisible && window.innerWidth < 1024) {
                    sidebar.classList.add('-translate-x-full');
                }
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) { // Tailwind's 'lg' breakpoint
                    sidebar.classList.remove('-translate-x-full'); // Ensure sidebar is visible on desktop
                } else {
                    sidebar.classList.add('-translate-x-full'); // Ensure sidebar is hidden on mobile by default
                }
            });

            window.onload = () => {
                if (window.innerWidth < 1024) {
                    sidebar.classList.add('-translate-x-full');
                }
            };
        }

        // --- General Modal Functions (copied for consistency) ---
        const submissionModal = document.getElementById('submissionModal');
        const modalContent = document.getElementById('modalContent');
        const commentModal = document.getElementById('commentModal');
        const commentModalContent = document.getElementById('commentModalContent');


        // Function to open manage_submission.php content in the main modal
        async function openManageSubmission(submissionId) {
            try {
                const response = await fetch(`manage_submission.php?id=${submissionId}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const htmlContent = await response.text();
                modalContent.innerHTML = htmlContent;
                submissionModal.classList.add('show');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            } catch (error) {
                console.error('Error loading submission details:', error);
                showMessage('Failed to load submission details. Please try again.', 'danger');
            }
        }

        // Function to close the main modal
        window.closeModal = function() { // Explicitly attach to window
            submissionModal.classList.remove('show');
            modalContent.innerHTML = ''; // Clear content when closing
            document.body.style.overflow = ''; // Restore background scrolling
            location.reload(); // Reload the parent page to reflect status changes
        }

        // Function to update submission status (called from within the manage_submission modal)
        window.updateStatus = async function(submissionId, newStatus) { // Explicitly attach to window
            const confirmed = newStatus === 'rejected' ? await showConfirmation('Are you sure you want to reject this submission?') : true;

            if (confirmed) {
                try {
                    const response = await fetch('update_submission_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `submission_id=${submissionId}&status=${newStatus}`
                    });
                    const result = await response.json();

                    if (result.success) {
                        showMessage(result.message, 'success');
                        setTimeout(() => {
                            closeModal(); // Close modal and reload parent
                        }, 1000);
                    } else {
                        showMessage('Error: ' + result.message, 'danger');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showMessage('An error occurred while updating status.', 'danger');
                }
            }
        }

        // Custom confirmation dialog (replaces window.confirm)
        window.showConfirmation = function(message) { // Explicitly attach to window
            return new Promise((resolve) => {
                const modalOverlay = document.createElement('div');
                // Increased z-index to ensure it's on top of other modals
                modalOverlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1100]';
                modalOverlay.innerHTML = `
                    <div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full">
                        <p class="text-lg font-semibold mb-4">${message}</p>
                        <div class="flex justify-end space-x-3">
                            <button id="confirm-cancel" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                            <button id="confirm-ok" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modalOverlay);

                document.getElementById('confirm-ok').onclick = () => {
                    modalOverlay.remove();
                    resolve(true);
                };
                document.getElementById('confirm-cancel').onclick = () => {
                    modalOverlay.remove();
                    resolve(false);
                };
            });
        }

        // Custom message display (replaces window.alert)
        window.showMessage = function(message, type) { // Explicitly attach to window
            const messageDiv = document.createElement('div');
            messageDiv.className = `fixed top-4 right-4 p-4 rounded-md text-white shadow-lg z-50
                ${type === 'success' ? 'bg-green-500' : ''}
                ${type === 'danger' ? 'bg-red-500' : ''}
                ${type === 'info' ? 'bg-blue-500' : ''}`;
            messageDiv.innerHTML = `
                <span>${message}</span>
                <button class="ml-4 font-bold" onclick="this.parentElement.remove()">&times;</button>
            `;
            document.body.appendChild(messageDiv);
            setTimeout(() => messageDiv.remove(), 5000); // Remove after 5 seconds
        }

        window.confirmReject = function(submissionId) { // Explicitly attach to window
            updateStatus(submissionId, 'rejected');
        }

        // --- Comment Modal Functions (copied for consistency) ---
        window.openCommentWindow = async function(submissionId) { // Explicitly attach to window
            try {
                const response = await fetch(`comment.php?id=${submissionId}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const htmlContent = await response.text();
                commentModalContent.innerHTML = htmlContent;
                commentModal.classList.add('show');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            } catch (error) {
                console.error('Error loading comment form:', error);
                showMessage('Failed to load comment form. Please try again.', 'danger');
            }
        }

        window.closeCommentModal = function() { // Explicitly attach to window
            commentModal.classList.remove('show');
            commentModalContent.innerHTML = ''; // Clear content when closing
            document.body.style.overflow = ''; // Restore background scrolling
        }
    </script>
</body>
</html>
