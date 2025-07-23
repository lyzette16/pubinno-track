<?php
// File: views/pio/manage_innovation_types.php
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
    error_log("manage_innovation_types.php: Database connection error: " . $e->getMessage());
    $_SESSION['message'] = 'Database connection error. Please try again later.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php");
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect if user is not logged in or not a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer';

$message = '';
$messageType = '';

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Handle Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $type_name = trim($_POST['type_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $inno_type_id = filter_var($_POST['inno_type_id'] ?? null, FILTER_VALIDATE_INT);

        $conn->begin_transaction();
        try {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO innovation_types (type_name, description, is_active, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
                $stmt->bind_param("ssi", $type_name, $description, $is_active);
                if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
                $message = "Innovation type added successfully.";
                $messageType = "success";
            } elseif ($action === 'edit' && $inno_type_id) {
                $stmt = $conn->prepare("UPDATE innovation_types SET type_name = ?, description = ?, is_active = ?, updated_at = NOW() WHERE inno_type_id = ?");
                if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
                $stmt->bind_param("ssii", $type_name, $description, $is_active, $inno_type_id);
                if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
                $message = "Innovation type updated successfully.";
                $messageType = "success";
            } elseif ($action === 'delete' && $inno_type_id) {
                // Check for associated inno_type_requirements before deleting
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inno_type_requirements WHERE inno_type_id = ?");
                $check_stmt->bind_param("i", $inno_type_id);
                $check_stmt->execute();
                $check_stmt->bind_result($count);
                $check_stmt->fetch();
                $check_stmt->close();

                if ($count > 0) {
                    throw new Exception("Cannot delete: This innovation type has associated requirements. Please remove them first.");
                }

                $stmt = $conn->prepare("DELETE FROM innovation_types WHERE inno_type_id = ?");
                if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
                $stmt->bind_param("i", $inno_type_id);
                if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
                $message = "Innovation type deleted successfully.";
                $messageType = "success";
            } else {
                throw new Exception("Invalid action or missing ID.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            error_log("manage_innovation_types.php POST error: " . $e->getMessage());
        }
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $messageType;
    header("Location: manage_innovation_types.php");
    exit();
}

// Fetch existing innovation types
$innovation_types = [];
$stmt = $conn->prepare("SELECT inno_type_id, type_name, description, is_active FROM innovation_types ORDER BY type_name ASC");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $innovation_types[] = $row;
    }
    $stmt->close();
} else {
    $message = "Database error fetching innovation types: " . $conn->error;
    $messageType = "danger";
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>PIO - Manage Innovation Types</title>
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
    </style>
</head>
<body class="bg-light">

    <div class="container-fluid main-content-wrapper">
        <?php include 'sidebar.php'; ?>

        <div id="main-content">
            <h4 class="mb-4">Manage Innovation Types</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Add New Innovation Type</h5>
                    <form action="manage_innovation_types.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="type_name" class="form-label">Type Name</label>
                            <input type="text" class="form-control" id="type_name" name="type_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="is_active_add" name="is_active" checked>
                            <label class="form-check-label" for="is_active_add">
                                Is Active
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Innovation Type</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Existing Innovation Types</h5>
                    <?php if (empty($innovation_types)): ?>
                        <p class="text-muted">No innovation types found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Type Name</th>
                                        <th>Description</th>
                                        <th>Active</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($innovation_types as $type): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($type['inno_type_id']) ?></td>
                                            <td><?= htmlspecialchars($type['type_name']) ?></td>
                                            <td><?= htmlspecialchars($type['description'] ?? 'N/A') ?></td>
                                            <td><?= $type['is_active'] ? 'Yes' : 'No' ?></td>
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm edit-btn" 
                                                        data-id="<?= htmlspecialchars($type['inno_type_id']) ?>"
                                                        data-name="<?= htmlspecialchars($type['type_name']) ?>"
                                                        data-description="<?= htmlspecialchars($type['description'] ?? '') ?>"
                                                        data-active="<?= htmlspecialchars($type['is_active']) ?>">
                                                    Edit
                                                </button>
                                                <form action="manage_innovation_types.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this innovation type? This will also remove associated requirements!');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="inno_type_id" value="<?= htmlspecialchars($type['inno_type_id']) ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Innovation Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="manage_innovation_types.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="inno_type_id" id="edit_inno_type_id">
                        <div class="mb-3">
                            <label for="edit_type_name" class="form-label">Type Name</label>
                            <input type="text" class="form-control" id="edit_type_name" name="type_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Is Active
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var editModal = document.getElementById('editModal');
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget; // Button that triggered the modal
                var id = button.getAttribute('data-id');
                var name = button.getAttribute('data-name');
                var description = button.getAttribute('data-description');
                var active = button.getAttribute('data-active');

                var modalId = editModal.querySelector('#edit_inno_type_id');
                var modalName = editModal.querySelector('#edit_type_name');
                var modalDescription = editModal.querySelector('#edit_description');
                var modalIsActive = editModal.querySelector('#edit_is_active');

                modalId.value = id;
                modalName.value = name;
                modalDescription.value = description;
                modalIsActive.checked = (active === '1');
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    try {
        $conn->close();
    } catch (Throwable $e) {
        error_log("manage_innovation_types.php: Error closing MySQLi connection at end of script: " . $e->getMessage());
    }
} else {
    error_log("manage_innovation_types.php: MySQLi connection object is not set or not a mysqli instance at end of script. Not attempting to close.");
}
ob_end_flush();
?>
