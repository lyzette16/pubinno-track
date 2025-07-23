<?php
session_start();
require_once '../../config/config.php'; // Assuming config.php is here for general settings
require_once '../../config/connect.php'; // Assuming connect.php for database connection

// IMPORTANT: This block ensures only logged-in facilitators can access this page.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

// Get the facilitator's campus_id and department_id from the session
$facilitator_campus_id = $_SESSION['campus_id'] ?? null;
$facilitator_department_id = $_SESSION['department_id'] ?? null; // Get facilitator's department_id

// Handle the case where facilitator's campus_id or department_id is not set
if ($facilitator_campus_id === null || $facilitator_department_id === null) {
    error_log("Facilitator's campus_id or department_id not found in session for user_id: " . ($_SESSION['user_id'] ?? 'Unknown') . ". Redirecting to logout.");
    $_SESSION['message'] = "Error: Your session campus/department information is missing. Please log in again.";
    $_SESSION['message_type'] = "danger"; // Consider using a message type for display
    header("Location: ../../auth/logout.php");
    exit();
}

// NOTE: We no longer need to fetch ALL departments from the database
// because the department will be fixed to the facilitator's department.
// The $departments variable and associated database query are now removed.

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // No specific status for this page
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .navbar {
            background-color: #007bff;
            color: white;
            flex-shrink: 0;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
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
        /* Sidebar nav links style */
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
                    <?php if ($facilitator_campus_id): ?>
                        (Campus ID: <?= htmlspecialchars($facilitator_campus_id) ?>)
                    <?php endif; ?>
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; // Include the sidebar ?>

        <div id="main-content">
            <div class="bg-white p-4 rounded shadow-sm">
                <h4>Register New User</h4>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php elseif (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="../../auth/register_process.php">
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select name="role" id="role" class="form-select" required>
                            <option value="researcher" selected>Researcher</option>
                        </select>
                    </div>

                    <input type="hidden" name="campus_id" value="<?= htmlspecialchars($facilitator_campus_id) ?>">

                    <input type="hidden" name="department_id" value="<?= htmlspecialchars($facilitator_department_id) ?>">

                    <div class="d-flex justify-content-between">
                        <button class="btn btn-primary" type="submit">Register</button>
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>