<?php
// File: views/researcher/track_submission.php

// Conditionally start the session to prevent "session_start(): Ignoring session_start()" notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a researcher
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

$researcher_id = $_SESSION['user_id'];
$researcher_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'Researcher';

$track_message = ''; // Message to display for tracking success/failure (e.g., if ref number is empty)

// No direct submission details rendering here; it will be loaded via AJAX into a modal
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Submission | PubInno-track</title>
    <link rel="icon" href="../../assets/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS for styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts: Inter (used in custom styles) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* Body and overall page styling, aligned with researcher_repository.php */
        body {
            font-family: "Segoe UI", sans-serif; /* Consistent with researcher_repository.php */
            background-color: #f5f7fb; /* Light gray background consistent with researcher_repository.php */
            display: flex;
            flex-direction: column;
            min-height: 50vh;
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

        /* Main content wrapper styling, aligned with researcher_repository.php */
        .main-content-wrapper {
            max-width: 1000px; /* Increased max-width for the main content area */
            margin: 60px auto; /* Adjust margin for spacing from navbar */
            padding: 30px;
            width: 1000px;
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.05);
            flex-grow: 1; /* Allow it to grow and fill space */
        }

        /* Status Badge Styling (Bootstrap-friendly colors) */
        .status-badge {
            display: inline-flex;
            padding: 0.25em 0.6em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.375rem;
            margin-left: 5px;
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
        .status-pending { background-color: #6c757d; } /* Gray for pending */


        /* Timeline Styling */
        .timeline {
            position: relative;
            padding-left: 2.5rem; /* Space for the line and dots */
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 1rem; /* Position of the vertical line */
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #e5e7eb; /* light gray */
        }
        .timeline-step {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 1.5rem; /* Space between dot and text */
        }
        .timeline-dot {
            position: absolute;
            left: 0.5rem; /* Align with the vertical line */
            top: 0;
            width: 1.8rem; /* Increased size for the dot */
            height: 1.8rem; /* Increased size for the dot */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #fff;
            font-size: 0.875rem; /* text-sm */
            z-index: 10;
            border: 2px solid #fff; /* White border */
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .timeline-dot.pending { background-color: #6c757d; } /* Gray */
        .timeline-dot.active { background-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.4); } /* Blue with glow */
        .timeline-dot.completed { background-color: #198754; } /* Green */
        .timeline-dot.rejected { background-color: #dc3545; } /* Red */

        .timeline-dot i.bi {
            font-size: 1.3rem; /* Adjust icon size for better visibility */
            color: #fff; /* Ensure icon color is white */
        }

        .timestamp {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.2rem;
        }

        /* Custom styles for form elements to ensure Bootstrap appearance */
        .form-control {
            display: block;
            width: 100%;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            color: #212529;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .btn {
            display: inline-block;
            font-weight: 400;
            line-height: 1.5;
            color: #212529;
            text-align: center;
            text-decoration: none;
            vertical-align: middle;
            cursor: pointer;
            -webkit-user-select: none;
            -moz-user-select: none;
            user-select: none;
            background-color: transparent;
            border: 1px solid transparent;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            border-radius: 0.25rem;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .btn-primary {
            color: #fff;
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .btn-primary:hover {
            color: #fff;
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        .btn-secondary {
            color: #fff;
            background-color: #6c757d;
            border-color: #6c757d;
        }
        .btn-secondary:hover {
            color: #fff;
            background-color: #5c636a;
            border-color: #565e64;
        }

        /* Modal specific styles */
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
            max-height: 90vh; /* Increased height for modal */
            overflow-y: auto; /* Enable scrolling for modal content */
            position: relative;
            width: 900px; /* Wider width for modal */
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        .modal-overlay.show .modal-container {
            transform: translateY(0);
        }

        /* Card styling for form and details, aligned with researcher_repository.php */
        .card-custom {
            background: #fff;
            border-radius: 1rem; /* Consistent with main-content-wrapper */
            padding: 30px; /* Consistent with main-content-wrapper */
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.05); /* Consistent with main-content-wrapper */
            margin-bottom: 1.5rem; /* mb-6 */
        }
        .form-center-card {
            max-width: 700px; /* Increased max-width for the form card */
            margin-left: auto;
            margin-right: auto;
        }

        /* Styles for dynamic messages */
        .dynamic-message {
            position: fixed;
            top: 4rem; /* Adjust as needed */
            right: 1rem; /* Adjust as needed */
            padding: 1rem;
            border-radius: 0.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1050; /* Ensure it's above other content but below modals */
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: auto; /* Adjust width based on content */
            min-width: 250px; /* Minimum width */
            max-width: 90%; /* Max width */
        }
        .dynamic-message.success { background-color: #28a745; } /* Green */
        .dynamic-message.danger { background-color: #dc3545; } /* Red */
        .dynamic-message.warning { background-color: #ffc107; color: #343a40; } /* Yellow, dark text */
        .dynamic-message .close-btn {
            background: none;
            border: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
            margin-left: 1rem;
            line-height: 1;
        }
    </style>
</head>
<body>
    <?php include '_navbar_researcher.php'; ?>

    <div class="main-content-wrapper">
        <h1 class="h4 font-weight-bold text-dark mb-4">Track Your Submission</h1>

        <?php 
        // Display PHP-generated messages using the JS function for consistency
        if (!empty($track_message)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Assuming $track_message contains HTML with a specific class for type
                    // For simplicity, let's assume if it contains 'danger' it's danger, else warning
                    const messageText = `<?= strip_tags($track_message) ?>`; // Remove HTML tags
                    const messageType = messageText.includes('danger') ? 'danger' : 'warning';
                    showMessage(messageText, messageType);
                });
            </script>
        <?php endif; ?>

        <div class="card-custom form-center-card">
            <h2 class="h5 fw-semibold text-gray-700 mb-4">Find a Submission</h2>
            <form id="trackSubmissionForm" method="POST" class="d-flex flex-column flex-sm-row align-items-center gap-3">
                <div class="position-relative flex-grow-1 w-100 w-sm-50 w-lg-33">
                    <input type="text" name="reference_number" placeholder="Paste your tracking number here"
                           class="form-control ps-5 pe-3 py-2 w-100"
                           value="<?= htmlspecialchars($_POST['reference_number'] ?? $_GET['ref'] ?? '') ?>">
                    <i class="bi bi-search position-absolute start-3 top-50 translate-middle-y text-gray-400"></i>
                </div>
                <button type="submit" class="btn btn-primary w-100 w-sm-auto">
                    Track
                </button>
                <?php if (!empty($_POST['reference_number']) || isset($_GET['ref'])): ?>
                    <a href="track_submission.php" class="btn btn-secondary w-100 w-sm-auto text-center">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Submission Details Modal -->
    <div id="submissionDetailsModal" class="modal-overlay">
        <div class="modal-container">
            <div id="modalContent">
                <!-- Content from fetch_submission_details.php will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- Custom message display function ---
        function showMessage(message, type) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `dynamic-message ${type}`;
            messageDiv.innerHTML = `
                <span>${message}</span>
                <button class="close-btn" onclick="this.parentElement.remove()">&times;</button>
            `;
            document.body.appendChild(messageDiv);
            setTimeout(() => messageDiv.remove(), 5000); // Remove after 5 seconds
        }

        // --- Modal Functions ---
        const submissionDetailsModal = document.getElementById('submissionDetailsModal');
        const modalContent = document.getElementById('modalContent');

        // Function to open the modal
        function openModal() {
            submissionDetailsModal.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }

        // Function to close the modal
        window.closeModal = function() { // Attach to window for global access
            submissionDetailsModal.classList.remove('show');
            modalContent.innerHTML = ''; // Clear content when closing
            document.body.style.overflow = ''; // Restore background scrolling
        }

        // Handle form submission for tracking
        document.getElementById('trackSubmissionForm').addEventListener('submit', async function(event) {
            event.preventDefault(); // Prevent default form submission

            const referenceNumber = this.querySelector('input[name="reference_number"]').value.trim();

            if (referenceNumber) {
                try {
                    const response = await fetch(`fetch_submission_details.php?ref=${encodeURIComponent(referenceNumber)}`);
                    if (!response.ok) {
                        // If the PHP script returns an HTTP error (e.g., 404, 500)
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const htmlContent = await response.text();
                    
                    // Check if the fetched content indicates an error from the PHP script itself
                    // This is a simple check; a more robust solution would involve JSON responses from PHP
                    if (htmlContent.includes('alert alert-danger') || htmlContent.includes('alert alert-warning')) {
                        // If PHP returns an error HTML, parse it and display
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = htmlContent;
                        const alertText = tempDiv.querySelector('.alert')?.textContent.trim() || 'An unknown error occurred.';
                        showMessage(alertText, 'danger'); // Assuming it's a danger type if PHP returned an alert
                        // Do not open the modal if the content is an error message
                    } else {
                        modalContent.innerHTML = htmlContent;
                        openModal(); // Open the modal with the fetched content
                    }

                } catch (error) {
                    console.error('Error loading submission details:', error);
                    showMessage('Failed to load submission details. Please try again or check the reference number.', 'danger');
                }
            } else {
                showMessage('Please enter a reference number to track.', 'warning');
            }
        });
    </script>
</body>
</html>
