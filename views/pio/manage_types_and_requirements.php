<?php
// File: views/pio/manage_types_and_requirements.php
session_start();
require_once '../../config/config.php';

// Attempt to connect to the database.
$conn = null;
try {
    require_once '../../config/connect.php'; // This file should establish $conn
    if (!isset($conn) || !$conn instanceof mysqli) {
        throw new Exception("Database connection failed to establish in connect.php.");
    }
} catch (Exception $e) {
    error_log("manage_types_and_requirements.php: Database connection error: " . $e->getMessage());
    $_SESSION['message'] = 'Database connection error. Please try again later.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php"); // Redirect to a safe page
    exit();
}

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect if user is not logged in or not a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer';
$pio_campus_id = $_SESSION['campus_id'] ?? null;

$message = '';
$messageType = '';

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Fetch counts for each category
$publication_types_count = 0;
$innovation_types_count = 0;
$requirements_master_count = 0;

$stmt_pub_types = $conn->prepare("SELECT COUNT(*) FROM publication_types WHERE is_active = 1");
if ($stmt_pub_types) {
    $stmt_pub_types->execute();
    $stmt_pub_types->bind_result($publication_types_count);
    $stmt_pub_types->fetch();
    $stmt_pub_types->close();
}

$stmt_inno_types = $conn->prepare("SELECT COUNT(*) FROM innovation_types WHERE is_active = 1");
if ($stmt_inno_types) {
    $stmt_inno_types->execute();
    $stmt_inno_types->bind_result($innovation_types_count);
    $stmt_inno_types->fetch();
    $stmt_inno_types->close();
}

$stmt_req_master = $conn->prepare("SELECT COUNT(*) FROM requirements_master WHERE is_active = 1");
if ($stmt_req_master) {
    $stmt_req_master->execute();
    $stmt_req_master->bind_result($requirements_master_count);
    $stmt_req_master->fetch();
    $stmt_req_master->close();
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>PIO - Manage Types & Requirements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacMacFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #f0f2f5;
        }
        .navbar {
            background-color: #007bff;
            border-bottom: 1px solid #0056b3;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            letter-spacing: -0.5px;
        }
        .navbar-text {
            color: white !important;
            font-size: 0.98rem;
            font-weight: 500;
        }
        .btn-outline-dark {
            border-color: #fff;
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-outline-dark:hover {
            background-color: #fff;
            color: #007bff;
            border-color: #fff;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
            padding: 0 15px;
            box-sizing: border-box;
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
            flex-basis: 0;
            min-width: 0;
            padding-left: 15px;
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
                padding: 0 10px;
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
            #main-content {
                padding-left: 0;
            }
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .card-title {
            font-weight: bold;
            color: #343a40;
        }
        .card-text {
            color: #6c757d;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        .stat-card {
            display: flex;
            align-items: center;
            padding: 20px;
            border-radius: 0.75rem;
            background-color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 2.5rem;
            margin-right: 15px;
            color: #007bff;
        }
        .stat-value {
            font-size: 2.2rem;
            font-weight: bold;
            color: #343a40;
        }
        .stat-label {
            font-size: 1rem;
            color: #6c757d;
            margin-bottom: 0;
        }
    </style>
</head>
<body class="bg-light">

    <div class="container-fluid main-content-wrapper">
        <?php include 'sidebar.php'; // Include the PIO sidebar ?>

        <div id="main-content">
            <h4 class="mb-4">Manage System Data</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6 col-lg-4 mb-4">
                    <a href="manage_publication_types.php" class="text-decoration-none">
                        <div class="stat-card">
                            <i class="bi bi-journal-text stat-icon text-primary"></i>
                            <div>
                                <h5 class="stat-value"><?= htmlspecialchars($publication_types_count) ?></h5>
                                <p class="stat-label">Publication Types</p>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6 col-lg-4 mb-4">
                    <a href="manage_innovation_types.php" class="text-decoration-none">
                        <div class="stat-card">
                            <i class="bi bi-lightbulb stat-icon text-success"></i>
                            <div>
                                <h5 class="stat-value"><?= htmlspecialchars($innovation_types_count) ?></h5>
                                <p class="stat-label">Innovation Types</p>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6 col-lg-4 mb-4">
                    <a href="manage_requirements_master.php" class="text-decoration-none">
                        <div class="stat-card">
                            <i class="bi bi-file-earmark-check stat-icon text-warning"></i>
                            <div>
                                <h5 class="stat-value"><?= htmlspecialchars($requirements_master_count) ?></h5>
                                <p class="stat-label">Master Requirements</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Manage Type-Specific Requirements</h5>
                            <p class="card-text">
                                Link specific requirements to different publication and innovation types.
                            </p>
                            <div class="d-grid gap-2 d-md-block">
                                <a href="manage_pub_type_requirements.php" class="btn btn-primary me-md-2">
                                    <i class="bi bi-link-45deg me-2"></i> Publication Type Requirements
                                </a>
                                <a href="manage_inno_type_requirements.php" class="btn btn-success">
                                    <i class="bi bi-link-45deg me-2"></i> Innovation Type Requirements
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    try {
        $conn->close();
    } catch (Throwable $e) {
        error_log("manage_types_and_requirements.php: Error closing MySQLi connection at end of script: " . $e->getMessage());
    }
} else {
    error_log("manage_types_and_requirements.php: MySQLi connection object is not set or not a mysqli instance at end of script. Not attempting to close.");
}
ob_end_flush();
?>
