<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

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

// Check for and display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        $conn->begin_transaction();
        try {
            if ($action === 'add') {
                $campus_name = trim($_POST['campus_name'] ?? '');
                $unit_code = trim($_POST['unit_code'] ?? ''); // Get unit_code
                $campus_status = $_POST['campus_status'] ?? 'active'; // Default to active

                if (empty($campus_name)) {
                    throw new Exception("Campus name cannot be empty.");
                }
                if (empty($unit_code)) {
                    throw new Exception("Unit Code cannot be empty.");
                }

                // Check for duplicate campus name
                $stmt_check_name = $conn->prepare("SELECT campus_id FROM campus WHERE campus_name = ?");
                if (!$stmt_check_name) {
                    throw new Exception("Failed to prepare check name statement: " . $conn->error);
                }
                $stmt_check_name->bind_param("s", $campus_name);
                $stmt_check_name->execute();
                $check_name_result = $stmt_check_name->get_result();
                if ($check_name_result->num_rows > 0) {
                    throw new Exception("A campus with this name already exists.");
                }
                $stmt_check_name->close();

                // Check for duplicate unit code
                $stmt_check_unit = $conn->prepare("SELECT campus_id FROM campus WHERE unit_code = ?");
                if (!$stmt_check_unit) {
                    throw new Exception("Failed to prepare check unit code statement: " . $conn->error);
                }
                $stmt_check_unit->bind_param("s", $unit_code);
                $stmt_check_unit->execute();
                $check_unit_result = $stmt_check_unit->get_result();
                if ($check_unit_result->num_rows > 0) {
                    throw new Exception("A campus with this Unit Code already exists.");
                }
                $stmt_check_unit->close();

                // Insert new campus including unit_code
                $stmt = $conn->prepare("INSERT INTO campus (campus_name, unit_code, campus_status) VALUES (?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare add statement: " . $conn->error);
                }
                $stmt->bind_param("sss", $campus_name, $unit_code, $campus_status);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add campus: " . $stmt->error);
                }
                $stmt->close();
                $message = "Campus '{$campus_name}' added successfully.";
                $messageType = 'success';

            } elseif ($action === 'edit') {
                $campus_id = filter_var($_POST['campus_id'] ?? null, FILTER_VALIDATE_INT);
                $campus_name = trim($_POST['campus_name'] ?? '');
                $unit_code = trim($_POST['unit_code'] ?? ''); // Get unit_code
                $campus_status = $_POST['campus_status'] ?? 'active';

                if (!$campus_id || empty($campus_name) || empty($unit_code)) {
                    throw new Exception("Missing campus ID, name, or unit code for edit.");
                }

                // Check for duplicate campus name (excluding the current campus being edited)
                $stmt_check_dup_name = $conn->prepare("SELECT campus_id FROM campus WHERE campus_name = ? AND campus_id != ?");
                if (!$stmt_check_dup_name) {
                    throw new Exception("Failed to prepare duplicate name check statement: " . $conn->error);
                }
                $stmt_check_dup_name->bind_param("si", $campus_name, $campus_id);
                $stmt_check_dup_name->execute();
                if ($stmt_check_dup_name->get_result()->num_rows > 0) {
                    throw new Exception("A campus with this name already exists.");
                }
                $stmt_check_dup_name->close();

                // Check for duplicate unit code (excluding the current campus being edited)
                $stmt_check_dup_unit = $conn->prepare("SELECT campus_id FROM campus WHERE unit_code = ? AND campus_id != ?");
                if (!$stmt_check_dup_unit) {
                    throw new Exception("Failed to prepare duplicate unit code check statement: " . $conn->error);
                }
                $stmt_check_dup_unit->bind_param("si", $unit_code, $campus_id);
                $stmt_check_dup_unit->execute();
                if ($stmt_check_dup_unit->get_result()->num_rows > 0) {
                    throw new Exception("A campus with this Unit Code already exists.");
                }
                $stmt_check_dup_unit->close();

                // Update campus including unit_code
                $stmt = $conn->prepare("UPDATE campus SET campus_name = ?, unit_code = ?, campus_status = ?, updated_at = NOW() WHERE campus_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare edit statement: " . $conn->error);
                }
                $stmt->bind_param("sssi", $campus_name, $unit_code, $campus_status, $campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update campus: " . $stmt->error);
                }
                $stmt->close();
                $message = "Campus updated successfully.";
                $messageType = 'success';

            } elseif ($action === 'delete') {
                $campus_id = filter_var($_POST['campus_id'] ?? null, FILTER_VALIDATE_INT);

                if (!$campus_id) {
                    throw new Exception("Missing campus ID for delete.");
                }

                // Check for associated users or departments before deleting (crucial for data integrity)
                $stmt_check_users = $conn->prepare("SELECT COUNT(*) FROM users WHERE campus_id = ?");
                $stmt_check_users->bind_param("i", $campus_id);
                $stmt_check_users->execute();
                $count_users = 0;
                $stmt_check_users->bind_result($count_users);
                $stmt_check_users->fetch();
                $stmt_check_users->close();

                if ($count_users > 0) {
                    throw new Exception("Cannot delete campus. There are {$count_users} users associated with it. Please reassign users first.");
                }

                $stmt_check_departments = $conn->prepare("SELECT COUNT(*) FROM departments WHERE campus_id = ?");
                $stmt_check_departments->bind_param("i", $campus_id);
                $stmt_check_departments->execute();
                $count_departments = 0;
                $stmt_check_departments->bind_result($count_departments);
                $stmt_check_departments->fetch();
                $stmt_check_departments->close();

                if ($count_departments > 0) {
                    throw new Exception("Cannot delete campus. There are {$count_departments} departments associated with it. Please reassign departments first.");
                }

                // You might also check for submissions associated with this campus if applicable
                $stmt_check_submissions = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE campus_id = ?");
                $stmt_check_submissions->bind_param("i", $campus_id);
                $stmt_check_submissions->execute();
                $count_submissions = 0;
                $stmt_check_submissions->bind_result($count_submissions);
                $stmt_check_submissions->fetch();
                $stmt_check_submissions->close();

                if ($count_submissions > 0) {
                    throw new Exception("Cannot delete campus. There are {$count_submissions} submissions associated with it.");
                }


                $stmt = $conn->prepare("DELETE FROM campus WHERE campus_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }
                $stmt->bind_param("i", $campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete campus: " . $stmt->error);
                }
                $stmt->close();
                $message = "Campus deleted successfully.";
                $messageType = 'success';

            } else {
                throw new Exception("Invalid action specified.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
            error_log("Campus Management Error: " . $e->getMessage());
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: manage_campuses.php");
        exit();
    }
}

// Fetch all campuses
$campuses = [];
// MODIFIED: Added unit_code to the SELECT statement
$stmt = $conn->prepare("SELECT campus_id, campus_name, unit_code, campus_status, created_at, updated_at FROM campus ORDER BY campus_name ASC");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $campuses[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching campuses: " . $conn->error);
    $message = "Database error fetching campuses.";
    $messageType = 'danger';
}

$conn->close();

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // No status filter for this page
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Campuses - PIO Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
 body {
    font-family: 'Inter', sans-serif;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background-color: #f0f2f5;
    color: #212529;
    font-size: 15px;
}

.main-content-wrapper {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    flex-grow: 1;
    margin-top: 20px;
    padding: 0 15px;
    box-sizing: border-box;
}

#main-content {
    flex-grow: 1;
    flex-basis: 0;
    min-width: 0;
    padding-left: 15px;
}

.card {
    border-radius: 0.75rem;
    box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.08);
    background-color: #fff;
}

.card-header {
    background-color: #0056b3;
    color: white;
    border-top-left-radius: 0.75rem !important;
    border-top-right-radius: 0.75rem !important;
    padding: 1rem 1.5rem;
    font-weight: 600;
    font-size: 1.1rem;
}

.table {
    font-size: 15px;
}

.table thead th {
    background-color: #e9ecef;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #212529;
}

.table tbody td {
    color: #212529;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Neutral Clean Button Styling */
.btn {
    border-radius: 0.5rem;
    padding: 0.5rem 1rem;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid #ced4da;
    background-color: transparent;
    color: #343a40;
    transition: all 0.2s ease-in-out;
    box-shadow: none;
}

.btn:hover {
    background-color: #e2e6ea;
    color: #212529;
    border-color: #adb5bd;
    transform: translateY(-1px);
}

/* Outline Button Variant for subtle feel */
.btn-outline-dark {
    border-color: #6c757d;
    color: #343a40;
}

.btn-outline-dark:hover {
    background-color: #dee2e6;
    color: #212529;
    border-color: #adb5bd;
}

.modal-header.bg-primary {
    background-color: #0056b3 !important;
    color: #fff;
}

.modal-header.bg-warning {
    background-color: #e0a800 !important;
    color: #fff;
}

.modal-title {
    font-weight: 600;
    font-size: 16px;
}

.btn-close-white {
    filter: invert(1);
}

.badge-status-active {
    background-color: #28a745;
    color: white;
    padding: 0.35em 0.65em;
    border-radius: 0.25rem;
    font-weight: 600;
}

.badge-status-inactive {
    background-color: #6c757d;
    color: white;
    padding: 0.35em 0.65em;
    border-radius: 0.25rem;
    font-weight: 600;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    padding: 1rem 0;
    list-style: none;
}

.pagination li {
    margin: 0 5px;
}

.pagination li a {
    color: #212529;
    border: 1px solid #ced4da;
    padding: 6px 12px;
    text-decoration: none;
    border-radius: 0.4rem;
    font-size: 14px;
}

.pagination li a:hover,
.pagination li.active a {
    background-color: #dee2e6;
    border-color: #adb5bd;
    font-weight: 600;
}

    </style>
</head>
<body class="bg-light">

    <div class="container-fluid main-content-wrapper">
        <?php
        // Define $currentPage for sidebar highlighting
        $currentPage = basename($_SERVER['PHP_SELF']);
        include 'sidebar.php'; // Include the PIO sidebar
        ?>

        <div id="main-content">
            <h4 class="mb-4">Manage Campuses</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCampusModal">
                    <i class="bi bi-plus-circle me-1"></i> Add New Campus
                </button>
            </div>

            <?php if (empty($campuses)): ?>
                <p class="text-muted">No campuses found. Click "Add New Campus" to get started.</p>
            <?php else: ?>
                <div class="table-responsive bg-white p-3 rounded shadow-sm">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Campus Name</th>
                                <th scope="col">Unit Code</th>
                                <th scope="col">Status</th>
                                <th scope="col">Created At</th>
                                <th scope="col">Updated At</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campuses as $index => $campus): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($campus['campus_name']) ?></td>
                                    <td><?= htmlspecialchars($campus['unit_code']) ?></td>
                                    <td>
                                        <span class="badge <?= ($campus['campus_status'] === 'active') ? 'badge-status-active' : 'badge-status-inactive' ?>">
                                            <?= htmlspecialchars(ucfirst($campus['campus_status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date("F j, Y g:i A", strtotime($campus['created_at'])) ?></td>
                                    <td><?= date("F j, Y g:i A", strtotime($campus['updated_at'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning edit-campus-btn me-2"
                                            data-bs-toggle="modal" data-bs-target="#editCampusModal"
                                            data-id="<?= $campus['campus_id'] ?>"
                                            data-name="<?= htmlspecialchars($campus['campus_name']) ?>"
                                            data-unit-code="<?= htmlspecialchars($campus['unit_code']) ?>"
                                            data-status="<?= htmlspecialchars($campus['campus_status']) ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-campus-btn"
                                            data-id="<?= $campus['campus_id'] ?>"
                                            data-name="<?= htmlspecialchars($campus['campus_name']) ?>">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Campus Modal -->
    <div class="modal fade" id="addCampusModal" tabindex="-1" aria-labelledby="addCampusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_campuses.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addCampusModalLabel">Add New Campus</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_campus_name" class="form-label">Campus Name</label>
                            <input type="text" class="form-control" id="add_campus_name" name="campus_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_unit_code" class="form-label">Unit Code</label>
                            <input type="text" class="form-control" id="add_unit_code" name="unit_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit unit code (e.g., 01, 10).</div>
                        </div>
                        <div class="mb-3">
                            <label for="add_campus_status" class="form-label">Status</label>
                            <select class="form-select" id="add_campus_status" name="campus_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Campus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Campus Modal -->
    <div class="modal fade" id="editCampusModal" tabindex="-1" aria-labelledby="editCampusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_campuses.php" method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editCampusModalLabel">Edit Campus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="campus_id" id="edit_campus_id">
                        <div class="mb-3">
                            <label for="edit_campus_name" class="form-label">Campus Name</label>
                            <input type="text" class="form-control" id="edit_campus_name" name="campus_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_unit_code" class="form-label">Unit Code</label>
                            <input type="text" class="form-control" id="edit_unit_code" name="unit_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit unit code (e.g., 01, 10).</div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_campus_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_campus_status" name="campus_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle Edit button click to populate modal
            document.querySelectorAll('.edit-campus-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const campusId = this.dataset.id;
                    const campusName = this.dataset.name;
                    const unitCode = this.dataset.unitCode;
                    const campusStatus = this.dataset.status;

                    document.getElementById('edit_campus_id').value = campusId;
                    document.getElementById('edit_campus_name').value = campusName;
                    document.getElementById('edit_unit_code').value = unitCode;
                    document.getElementById('edit_campus_status').value = campusStatus;
                });
            });

            // Handle Delete button click with confirmation
            document.querySelectorAll('.delete-campus-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const campusId = this.dataset.id;
                    const campusName = this.dataset.name;
                    if (confirm(`Are you sure you want to delete the campus "${campusName}"? This will also affect associated departments, users, and submissions. This action cannot be undone.`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'manage_campuses.php';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'delete';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'campus_id';
                        idInput.value = campusId;
                        form.appendChild(idInput);

                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>
