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
                $college_name = trim($_POST['college_name'] ?? '');
                $college_code = trim($_POST['college_code'] ?? '');
                $is_active = $_POST['is_active'] ?? 1; // Default to active (1)

                if (empty($college_name)) {
                    throw new Exception("College name cannot be empty.");
                }
                if (empty($college_code)) {
                    throw new Exception("College Code cannot be empty.");
                }
                if (!preg_match('/^[0-9]{2}$/', $college_code)) {
                    throw new Exception("College Code must be a 2-digit number.");
                }

                // Check for duplicate college name
                $stmt_check_name = $conn->prepare("SELECT college_id FROM colleges WHERE college_name = ?");
                if (!$stmt_check_name) {
                    throw new Exception("Failed to prepare check name statement: " . $conn->error);
                }
                $stmt_check_name->bind_param("s", $college_name);
                $stmt_check_name->execute();
                $check_name_result = $stmt_check_name->get_result();
                if ($check_name_result->num_rows > 0) {
                    throw new Exception("A college with this name already exists.");
                }
                $stmt_check_name->close();

                // Check for duplicate college code
                $stmt_check_code = $conn->prepare("SELECT college_id FROM colleges WHERE college_code = ?");
                if (!$stmt_check_code) {
                    throw new Exception("Failed to prepare check code statement: " . $conn->error);
                }
                $stmt_check_code->bind_param("s", $college_code);
                $stmt_check_code->execute();
                $check_code_result = $stmt_check_code->get_result();
                if ($check_code_result->num_rows > 0) {
                    throw new Exception("A college with this College Code already exists.");
                }
                $stmt_check_code->close();

                // Insert new college
                $stmt = $conn->prepare("INSERT INTO colleges (college_name, college_code, is_active) VALUES (?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare add statement: " . $conn->error);
                }
                $stmt->bind_param("ssi", $college_name, $college_code, $is_active);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add college: " . $stmt->error);
                }
                $stmt->close();
                $message = "College '{$college_name}' added successfully.";
                $messageType = 'success';

            } elseif ($action === 'edit') {
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT);
                $college_name = trim($_POST['college_name'] ?? '');
                $college_code = trim($_POST['college_code'] ?? '');
                $is_active = $_POST['is_active'] ?? 1;

                if (!$college_id || empty($college_name) || empty($college_code)) {
                    throw new Exception("Missing college ID, name, or code for edit.");
                }
                if (!preg_match('/^[0-9]{2}$/', $college_code)) {
                    throw new Exception("College Code must be a 2-digit number.");
                }

                // Check for duplicate college name (excluding the current college)
                $stmt_check_dup_name = $conn->prepare("SELECT college_id FROM colleges WHERE college_name = ? AND college_id != ?");
                if (!$stmt_check_dup_name) {
                    throw new Exception("Failed to prepare duplicate name check statement: " . $conn->error);
                }
                $stmt_check_dup_name->bind_param("si", $college_name, $college_id);
                $stmt_check_dup_name->execute();
                if ($stmt_check_dup_name->get_result()->num_rows > 0) {
                    throw new Exception("A college with this name already exists.");
                }
                $stmt_check_dup_name->close();

                // Check for duplicate college code (excluding the current college)
                $stmt_check_dup_code = $conn->prepare("SELECT college_id FROM colleges WHERE college_code = ? AND college_id != ?");
                if (!$stmt_check_dup_code) {
                    throw new Exception("Failed to prepare duplicate code check statement: " . $conn->error);
                }
                $stmt_check_dup_code->bind_param("si", $college_code, $college_id);
                $stmt_check_dup_code->execute();
                if ($stmt_check_dup_code->get_result()->num_rows > 0) {
                    throw new Exception("A college with this College Code already exists.");
                }
                $stmt_check_dup_code->close();

                // Update college
                $stmt = $conn->prepare("UPDATE colleges SET college_name = ?, college_code = ?, is_active = ?, updated_at = NOW() WHERE college_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare edit statement: " . $conn->error);
                }
                $stmt->bind_param("ssii", $college_name, $college_code, $is_active, $college_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update college: " . $stmt->error);
                }
                $stmt->close();
                $message = "College updated successfully.";
                $messageType = 'success';

            } elseif ($action === 'delete') {
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT);

                if (!$college_id) {
                    throw new Exception("Missing college ID for delete.");
                }

                // Check for associated departments before deleting
                $stmt_check_departments = $conn->prepare("SELECT COUNT(*) FROM departments WHERE college_id = ?");
                $stmt_check_departments->bind_param("i", $college_id);
                $stmt_check_departments->execute();
                $count_departments = 0;
                $stmt_check_departments->bind_result($count_departments);
                $stmt_check_departments->fetch();
                $stmt_check_departments->close();

                if ($count_departments > 0) {
                    throw new Exception("Cannot delete college. There are {$count_departments} departments associated with it. Please reassign departments first.");
                }

                // Check for associated programs before deleting
                $stmt_check_programs = $conn->prepare("SELECT COUNT(*) FROM programs WHERE college_id = ?");
                $stmt_check_programs->bind_param("i", $college_id);
                $stmt_check_programs->execute();
                $count_programs = 0;
                $stmt_check_programs->bind_result($count_programs);
                $stmt_check_programs->fetch();
                $stmt_check_programs->close();

                if ($count_programs > 0) {
                    throw new Exception("Cannot delete college. There are {$count_programs} programs associated with it. Please reassign programs first.");
                }

                // Delete college
                $stmt = $conn->prepare("DELETE FROM colleges WHERE college_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }
                $stmt->bind_param("i", $college_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete college: " . $stmt->error);
                }
                $stmt->close();
                $message = "College deleted successfully.";
                $messageType = 'success';

            } else {
                throw new Exception("Invalid action specified.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
            error_log("College Management Error: " . $e->getMessage());
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: manage_colleges.php");
        exit();
    }
}

// Fetch all colleges
$colleges = [];
$stmt = $conn->prepare("SELECT college_id, college_name, college_code, is_active, created_at, updated_at FROM colleges ORDER BY college_name ASC");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $colleges[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching colleges: " . $conn->error);
    $message = "Database error fetching colleges.";
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
    <title>Manage Colleges - PIO Panel</title>
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
    color: #212529; /* darker text for better readability */
    font-size: 16px;
}

.btn {
    border-radius: 0.5rem;
    padding: 0.55rem 1rem;
    font-weight: 500;
    transition: all 0.3s ease;
    box-shadow: none;
    background: transparent;
    color: #333;
    border: 1px solid #ccc;
}

.btn:hover {
    background-color: #e9ecef;
    color: #000;
    border-color: #bbb;
}

/* Remove color styles from specific buttons */
.btn-primary,
.btn-warning,
.btn-danger,
.btn-secondary {
    background-color: transparent !important;
    border-color: #ccc !important;
    color: #333 !important;
}

.btn-primary:hover,
.btn-warning:hover,
.btn-danger:hover,
.btn-secondary:hover {
    background-color: #e9ecef !important;
    color: #000 !important;
    border-color: #bbb !important;
    transform: none;
    box-shadow: none;
}

.btn-outline-dark {
    border-color: #6c757d;
    color: #343a40;
}

.btn-outline-dark:hover {
    background-color: #343a40;
    color: white;
    border-color: #343a40;
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
}

.card-header {
    background-color: #0056b3;
    color: white;
    border-top-left-radius: 0.75rem !important;
    border-top-right-radius: 0.75rem !important;
    padding: 1rem 1.5rem;
    font-weight: 600;
    font-size: 1.15rem;
}

.table {
    font-size: 15px;
    color: #212529;
}

.table thead th {
    background-color: #e9ecef;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #212529;
}

.table tbody tr:hover {
    background-color: #f2f2f2;
}

/* Modal Styling */
.modal-header.bg-primary {
    background-color: #0056b3 !important;
}

.modal-header.bg-warning {
    background-color: #e0a800 !important;
}

.modal-title {
    font-weight: 600;
    color: #212529;
}

.btn-close-white {
    filter: invert(1);
}

/* Status Badges */
.badge-active {
    background-color: #28a745; /* Green */
    color: white;
    padding: 0.35em 0.65em;
    border-radius: 0.25rem;
    font-weight: 600;
}

.badge-inactive {
    background-color: #6c757d; /* Gray */
    color: white;
    padding: 0.35em 0.65em;
    border-radius: 0.25rem;
    font-weight: 600;
}


/* Pagination (optional if you're using it) */
.pagination {
    margin-top: 15px;
    justify-content: center;
}

.page-link {
    color: #333;
    border: 1px solid #ddd;
    border-radius: 0.375rem;
}

.page-link:hover {
    background-color: #e9ecef;
    border-color: #bbb;
    color: #000;
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
            <h4 class="mb-4">Manage Colleges</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollegeModal">
                    <i class="bi bi-plus-circle me-1"></i> Add New College
                </button>
            </div>

            <?php if (empty($colleges)): ?>
                <p class="text-muted">No colleges found. Click "Add New College" to get started.</p>
            <?php else: ?>
                <div class="table-responsive bg-white p-3 rounded shadow-sm">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">College Name</th>
                                <th scope="col">College Code</th>
                                <th scope="col">Status</th>
                                <th scope="col">Created At</th>
                                <th scope="col">Updated At</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($colleges as $index => $college): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($college['college_name']) ?></td>
                                    <td><?= htmlspecialchars($college['college_code']) ?></td>
                                    <td>
                                        <span class="badge <?= ($college['is_active'] == 1) ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= ($college['is_active'] == 1) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= date("F j, Y g:i A", strtotime($college['created_at'])) ?></td>
                                    <td><?= date("F j, Y g:i A", strtotime($college['updated_at'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning edit-college-btn me-2"
                                            data-bs-toggle="modal" data-bs-target="#editCollegeModal"
                                            data-id="<?= $college['college_id'] ?>"
                                            data-name="<?= htmlspecialchars($college['college_name']) ?>"
                                            data-code="<?= htmlspecialchars($college['college_code']) ?>"
                                            data-active="<?= htmlspecialchars($college['is_active']) ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-college-btn"
                                            data-id="<?= $college['college_id'] ?>"
                                            data-name="<?= htmlspecialchars($college['college_name']) ?>">
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

    <!-- Add College Modal -->
    <div class="modal fade" id="addCollegeModal" tabindex="-1" aria-labelledby="addCollegeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_colleges.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addCollegeModalLabel">Add New College</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_college_name" class="form-label">College Name</label>
                            <input type="text" class="form-control" id="add_college_name" name="college_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_college_code" class="form-label">College Code</label>
                            <input type="text" class="form-control" id="add_college_code" name="college_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit code for the college (e.g., 01, 10).</div>
                        </div>
                        <div class="mb-3">
                            <label for="add_is_active" class="form-label">Status</label>
                            <select class="form-select" id="add_is_active" name="is_active" required>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add College</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit College Modal -->
    <div class="modal fade" id="editCollegeModal" tabindex="-1" aria-labelledby="editCollegeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_colleges.php" method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editCollegeModalLabel">Edit College</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="college_id" id="edit_college_id">
                        <div class="mb-3">
                            <label for="edit_college_name" class="form-label">College Name</label>
                            <input type="text" class="form-control" id="edit_college_name" name="college_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_college_code" class="form-label">College Code</label>
                            <input type="text" class="form-control" id="edit_college_code" name="college_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit code for the college (e.g., 01, 10).</div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_is_active" class="form-label">Status</label>
                            <select class="form-select" id="edit_is_active" name="is_active" required>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
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
            document.querySelectorAll('.edit-college-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const collegeId = this.dataset.id;
                    const collegeName = this.dataset.name;
                    const collegeCode = this.dataset.code;
                    const isActive = this.dataset.active;

                    document.getElementById('edit_college_id').value = collegeId;
                    document.getElementById('edit_college_name').value = collegeName;
                    document.getElementById('edit_college_code').value = collegeCode;
                    document.getElementById('edit_is_active').value = isActive;
                });
            });

            // Handle Delete button click with confirmation
            document.querySelectorAll('.delete-college-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const collegeId = this.dataset.id;
                    const collegeName = this.dataset.name;
                    if (confirm(`Are you sure you want to delete the college "${collegeName}"? This action cannot be undone if there are associated departments or programs.`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'manage_colleges.php';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'delete';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'college_id';
                        idInput.value = collegeId;
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
