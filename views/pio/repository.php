<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Check if user is logged in and is a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$user_name = htmlspecialchars($_SESSION['name'] ?? 'PIO User');

$submissions_data = [];
$message = '';
$messageType = '';

// Get search parameter
$search_query = $_GET['search'] ?? '';

// Fetch all relevant submission data, including review details and payment status
// Filtered to only show submissions with 'approved' status
$sql = "SELECT
            s.submission_id,
            s.title,
            s.submission_type,
            s.submission_date,
            s.status,
            u.name AS author_name,
            sr.created_at AS review_date,
            sr.publisher,
            sr.indexing_body,
            sr.incentives_amount,
            sr.evidence_link,
            sr.payment_status
        FROM
            submissions s
        JOIN
            users u ON s.researcher_id = u.user_id
        LEFT JOIN
            submission_reviews sr ON s.submission_id = sr.submission_id
        WHERE
            s.status = 'approved'"; // Only show approved submissions

$params = [];
$types = "";

// Add search query filter
if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $sql .= " AND (s.reference_number LIKE ? OR s.title LIKE ? OR u.name LIKE ? OR sr.publisher LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

$sql .= " ORDER BY s.submission_date DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $submissions_data[] = $row;
    }
    $stmt->close();
} else {
    $message = 'Database error fetching repository data: ' . $conn->error;
    $messageType = 'danger';
    error_log("PIO Repository SQL Prepare Error: " . $conn->error);
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
    <title>PIO Research Repository</title>
    <!-- Tailwind CSS for styling -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons for icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
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
        /* Status badge colors (re-using existing ones) */
        .status-badge.status-under_external_review { background-color: #ffc107; color: #343a40; }
        .status-badge.status-accepted_by_pio { background-color: #10B981; color: #fff; }
        .status-badge.status-approved { background-color: #28a745; color: #fff; }
        .status-badge.status-rejected { background-color: #dc3545; color: #fff; }
        .status-badge.status-accepted_by_external { background-color: #28a745; color: #fff; }
        /* Add a style for 'Published/Implemented' if it's a distinct final status */
        .status-badge.status-published { background-color: #007bff; color: #fff; } /* Blue for published */
        .status-badge.status-implemented { background-color: #6f42c1; color: #fff; } /* Purple for implemented */

        /* Payment Status Badges */
        .payment-status-badge.status-pending { background-color: #ffc107; color: #343a40; } /* Yellow for pending */
        .payment-status-badge.status-paid { background-color: #10B981; color: #fff; } /* Green for paid */

        /* Confirmation Modal specific styles - HIGHER Z-INDEX */
        .confirmation-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6); /* Slightly darker overlay for confirmation */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 99999; /* Increased Z-INDEX to ensure it's on top of ALL other modals/elements */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .confirmation-modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .confirmation-modal-container {
            background-color: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            max-width: 90%;
            width: 400px; /* Fixed width for confirmation modal */
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        .confirmation-modal-overlay.show .confirmation-modal-container {
            transform: translateY(0);
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <div class="flex flex-1 overflow-hidden">
        <?php include 'sidebar.php'; // Include the sidebar (assuming shared sidebar) ?>

        <!-- Main Content -->
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">PIO Research Repository</h1>

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
                <h5 class="text-xl font-semibold text-gray-700 mb-4">Approved Submissions (Manage Payments)</h5>
                
                <!-- Search Form -->
                <form method="GET" class="mb-6 flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-4">
                    <div class="relative w-full sm:w-1/2 lg:w-1/3">
                        <input type="text" name="search" placeholder="Search by Title, Author, Publisher..."
                               class="pl-10 pr-4 py-2 w-full rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               value="<?= htmlspecialchars($search_query) ?>">
                        <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Search
                    </button>
                    <?php if (!empty($search_query)): ?>
                        <a href="pio_repository.php" class="w-full sm:w-auto px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 text-center">
                            Reset Search
                        </a>
                    <?php endif; ?>
                </form>

                <?php if (empty($submissions_data)): ?>
                    <div class="text-gray-600 text-sm p-4 bg-blue-50 rounded-md">
                        No approved submissions found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Authors</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Published/Implemented</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Publisher/Innovator</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Indexing/Certification Body</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Incentives</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Link for Evidence</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($submissions_data as $submission): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($submission['title']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($submission['author_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?php
                                            // Display review_date if approved, otherwise submission_date
                                            if ($submission['status'] === 'approved' && !empty($submission['review_date'])) {
                                                echo date('M d, Y', strtotime($submission['review_date']));
                                            } else {
                                                echo date('M d, Y', strtotime($submission['submission_date']));
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?= htmlspecialchars($submission['publisher'] ?? 'N/A') ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?php
                                            if ($submission['submission_type'] === 'innovation') {
                                                echo 'Certification Body: ' . htmlspecialchars($submission['indexing_body'] ?? 'N/A');
                                            } else {
                                                echo 'Indexing Body: ' . htmlspecialchars($submission['indexing_body'] ?? 'N/A');
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?= !empty($submission['incentives_amount']) ? '$' . number_format($submission['incentives_amount'], 2) : 'N/A' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full status-badge
                                                <?= $submission['status'] === 'under_external_review' ? 'bg-yellow-100 text-yellow-800' : '' ?>
                                                <?= $submission['status'] === 'accepted_by_pio' ? 'bg-blue-100 text-blue-800' : '' ?>
                                                <?= $submission['status'] === 'accepted_by_external' ? 'bg-green-100 text-green-800' : '' ?>
                                                <?= $submission['status'] === 'approved' ? 'bg-purple-100 text-purple-800' : '' ?>
                                                <?= $submission['status'] === 'rejected' ? 'bg-red-100 text-red-800' : '' ?>">
                                                <?php
                                                if ($submission['status'] === 'approved') {
                                                    echo 'Published/Implemented';
                                                } else {
                                                    echo ucwords(str_replace('_', ' ', $submission['status']));
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 hover:text-blue-800">
                                            <?php if (!empty($submission['evidence_link'])): ?>
                                                <a href="<?= htmlspecialchars($submission['evidence_link']) ?>" target="_blank" class="underline">View Evidence</a>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span id="payment_status_<?= $submission['submission_id'] ?>" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full payment-status-badge status-<?= strtolower($submission['payment_status'] ?? 'pending') ?>">
                                                <?= htmlspecialchars($submission['payment_status'] ?? 'Pending') ?>
                                            </span>
                                            <?php if (($submission['payment_status'] ?? 'Pending') === 'Pending'): ?>
                                                <button onclick="updatePaymentStatus(<?= $submission['submission_id'] ?>, 'Paid')"
                                                        class="ml-2 px-3 py-1 border border-gray-400 text-gray-700 rounded-md hover:bg-blue-500 hover:text-white text-xs transition-colors duration-200">
                                                    Mark as Paid
                                                </button>
                                            <?php endif; ?>
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

    <script>
        // Custom confirmation dialog (replaces window.confirm)
        function showConfirmation(message) {
            return new Promise((resolve) => {
                const modalOverlay = document.createElement('div');
                modalOverlay.className = 'confirmation-modal-overlay show';
                modalOverlay.innerHTML = `
                    <div class="confirmation-modal-container p-6">
                        <p class="text-lg font-semibold mb-4">${message}</p>
                        <div class="flex justify-end space-x-3">
                            <button id="confirm-cancel" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                            <button id="confirm-ok" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Confirm</button>
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
        function showMessage(message, type) {
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

        // Function to update payment status
        async function updatePaymentStatus(submissionId, newStatus) {
            const confirmed = await showConfirmation(`Are you sure you want to mark this submission as '${newStatus}'?`);

            if (confirmed) {
                try {
                    const formData = new FormData();
                    formData.append('submission_id', submissionId);
                    formData.append('payment_status', newStatus);

                    const response = await fetch('update_payment_status.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        showMessage('Payment status updated successfully!', 'success');
                        setTimeout(() => {
                            location.reload(); // Reload the page to reflect the change
                        }, 1000);
                    } else {
                        showMessage('Error updating payment status: ' + result.message, 'danger');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showMessage('An error occurred while updating payment status.', 'danger');
                }
            }
        }

        // --- Sidebar Toggle Logic (MUST be included in every page using the sidebar) ---
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
    </script>
</body>
</html>
