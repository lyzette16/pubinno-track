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
$pio_campus_id = $_SESSION['campus_id'] ?? null;

$message = '';
$messageType = '';

// Check if PIO has a campus_id set
if (!$pio_campus_id) {
    $_SESSION['message'] = 'Your PIO account is not associated with a campus. Cannot manage departments.';
    $_SESSION['message_type'] = 'danger';
    header("Location: dashboard.php");
    exit();
}

// Check for and display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Fetch all active colleges for dropdowns
$colleges = [];
$stmt_colleges = $conn->prepare("SELECT college_id, college_name FROM colleges WHERE is_active = 1 ORDER BY college_name ASC");
if ($stmt_colleges) {
    $stmt_colleges->execute();
    $result_colleges = $stmt_colleges->get_result();
    while ($row = $result_colleges->fetch_assoc()) {
        $colleges[] = $row;
    }
    $stmt_colleges->close();
} else {
    error_log("Failed to prepare statement for fetching colleges: " . $conn->error);
    $message = "Database error fetching colleges for dropdowns.";
    $messageType = 'danger';
}


// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        $conn->begin_transaction();
        try {
            if ($action === 'add') {
                $department_name = trim($_POST['department_name'] ?? '');
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT); // Get college_id

                if (empty($department_name)) {
                    throw new Exception("Department name cannot be empty.");
                }
                if (!$college_id) { // Check if a valid college was selected
                    throw new Exception("Please select a college for the department.");
                }

                // Check for duplicate department name within the same campus
                $stmt_check = $conn->prepare("SELECT department_id FROM departments WHERE name = ? AND campus_id = ?");
                if (!$stmt_check) {
                    throw new Exception("Failed to prepare check statement: " . $conn->error);
                }
                $stmt_check->bind_param("si", $department_name, $pio_campus_id);
                $stmt_check->execute();
                $check_result = $stmt_check->get_result();
                if ($check_result->num_rows > 0) {
                    throw new Exception("A department with this name already exists in your campus.");
                }
                $stmt_check->close();

                // Insert new department including college_id
                $stmt = $conn->prepare("INSERT INTO departments (name, campus_id, college_id) VALUES (?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare add statement: " . $conn->error);
                }
                $stmt->bind_param("sii", $department_name, $pio_campus_id, $college_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add department: " . $stmt->error);
                }
                $stmt->close();
                $message = "Department '{$department_name}' added successfully.";
                $messageType = 'success';

            } elseif ($action === 'edit') {
                $department_id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
                $department_name = trim($_POST['department_name'] ?? '');
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT); // Get college_id

                if (!$department_id || empty($department_name)) {
                    throw new Exception("Missing department ID or name for edit.");
                }
                if (!$college_id) { // Check if a valid college was selected
                    throw new Exception("Please select a college for the department.");
                }

                // Verify the department belongs to the PIO's campus
                $stmt_verify = $conn->prepare("SELECT department_id FROM departments WHERE department_id = ? AND campus_id = ?");
                if (!$stmt_verify) {
                    throw new Exception("Failed to prepare verification statement: " . $conn->error);
                }
                $stmt_verify->bind_param("ii", $department_id, $pio_campus_id);
                $stmt_verify->execute();
                if ($stmt_verify->get_result()->num_rows === 0) {
                    throw new Exception("Unauthorized to edit this department or department not found in your campus.");
                }
                $stmt_verify->close();

                // Check for duplicate department name (excluding the current department being edited) within the same campus
                $stmt_check_dup = $conn->prepare("SELECT department_id FROM departments WHERE name = ? AND campus_id = ? AND department_id != ?");
                if (!$stmt_check_dup) {
                    throw new Exception("Failed to prepare duplicate check statement: " . $conn->error);
                }
                $stmt_check_dup->bind_param("sii", $department_name, $pio_campus_id, $department_id);
                $stmt_check_dup->execute();
                if ($stmt_check_dup->get_result()->num_rows > 0) {
                    throw new Exception("A department with this name already exists in your campus.");
                }
                $stmt_check_dup->close();

                // Update department including college_id
                $stmt = $conn->prepare("UPDATE departments SET name = ?, college_id = ? WHERE department_id = ? AND campus_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare edit statement: " . $conn->error);
                }
                $stmt->bind_param("siii", $department_name, $college_id, $department_id, $pio_campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update department: " . $stmt->error);
                }
                $stmt->close();
                $message = "Department updated successfully.";
                $messageType = 'success';

            } elseif ($action === 'delete') {
                $department_id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);

                if (!$department_id) {
                    throw new Exception("Missing department ID for delete.");
                }

                // Verify the department belongs to the PIO's campus before deleting
                $stmt_verify = $conn->prepare("SELECT department_id FROM departments WHERE department_id = ? AND campus_id = ?");
                if (!$stmt_verify) {
                    throw new Exception("Failed to prepare verification statement: " . $conn->error);
                }
                $stmt_verify->bind_param("ii", $department_id, $pio_campus_id);
                $stmt_verify->execute();
                if ($stmt_verify->get_result()->num_rows === 0) {
                    throw new Exception("Unauthorized to delete this department or department not found in your campus.");
                }
                $stmt_verify->close();

                // Check for associated users or submissions before deleting (optional but recommended for data integrity)
                $stmt_check_usage = $conn->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
                $stmt_check_usage->bind_param("i", $department_id);
                $stmt_check_usage->execute();
                $count_users = 0;
                $stmt_check_usage->bind_result($count_users);
                $stmt_check_usage->fetch();
                $stmt_check_usage->close();

                if ($count_users > 0) {
                    throw new Exception("Cannot delete department. There are {$count_users} users associated with it. Please reassign users first.");
                }
                
                // You might also check for submissions associated with this department if applicable
                $stmt_check_submissions = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE department_id = ?");
                $stmt_check_submissions->bind_param("i", $department_id);
                $stmt_check_submissions->execute();
                $count_submissions = 0;
                $stmt_check_submissions->bind_result($count_submissions);
                $stmt_check_submissions->fetch();
                $stmt_check_submissions->close();

                if ($count_submissions > 0) {
                    throw new Exception("Cannot delete department. There are {$count_submissions} submissions associated with it.");
                }


                $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ? AND campus_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }
                $stmt->bind_param("ii", $department_id, $pio_campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete department: " . $stmt->error);
                }
                $stmt->close();
                $message = "Department deleted successfully.";
                $messageType = 'success';

            } else {
                throw new Exception("Invalid action specified.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
            error_log("Department Management Error: " . $e->getMessage());
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: manage_departments.php");
        exit();
    }
}

// Fetch all departments for the current PIO's campus, including college name
$departments = [];
$sql_query = "SELECT
                d.department_id,
                d.name,
                d.college_id,
                c.college_name
              FROM
                departments d
              LEFT JOIN
                colleges c ON d.college_id = c.college_id
              WHERE
                d.campus_id = ?
              ORDER BY d.name ASC";

$stmt = $conn->prepare($sql_query);
if ($stmt) {
    $stmt->bind_param("i", $pio_campus_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching departments: " . $conn->error);
    $message = "Database error fetching departments.";
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
    <title>Manage Departments - PIO Panel</title>
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
            <h4 class="mb-4">Manage Departments</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                    <i class="bi bi-plus-circle me-1"></i> Add New Department
                </button>
            </div>

            <?php if (empty($departments)): ?>
                <p class="text-muted">No departments found for your campus. Click "Add New Department" to get started.</p>
            <?php else: ?>
                <div class="table-responsive bg-white p-3 rounded shadow-sm">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Department Name</th>
                                <th scope="col">College Name</th> <!-- New column for College Name -->
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments as $index => $dept): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($dept['name']) ?></td>
                                    <td><?= htmlspecialchars($dept['college_name'] ?? 'N/A') ?></td> <!-- Display College Name -->
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning edit-dept-btn me-2"
                                            data-bs-toggle="modal" data-bs-target="#editDepartmentModal"
                                            data-id="<?= $dept['department_id'] ?>"
                                            data-name="<?= htmlspecialchars($dept['name']) ?>"
                                            data-college-id="<?= htmlspecialchars($dept['college_id']) ?>"> <!-- Pass college_id -->
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-dept-btn"
                                            data-id="<?= $dept['department_id'] ?>"
                                            data-name="<?= htmlspecialchars($dept['name']) ?>">
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

    <!-- Add Department Modal -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_departments.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addDepartmentModalLabel">Add New Department</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_department_name" class="form-label">Department Name</label>
                            <input type="text" class="form-control" id="add_department_name" name="department_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_college_id" class="form-label">College/Institute</label> <!-- New College dropdown -->
                            <select class="form-select" id="add_college_id" name="college_id" required>
                                <option value="">Select College</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?= htmlspecialchars($college['college_id']) ?>">
                                        <?= htmlspecialchars($college['college_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-labelledby="editDepartmentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_departments.php" method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editDepartmentModalLabel">Edit Department</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="department_id" id="edit_department_id">
                        <div class="mb-3">
                            <label for="edit_department_name" class="form-label">Department Name</label>
                            <input type="text" class="form-control" id="edit_department_name" name="department_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_college_id" class="form-label">College/Institute</label> <!-- New College dropdown -->
                            <select class="form-select" id="edit_college_id" name="college_id" required>
                                <option value="">Select College</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?= htmlspecialchars($college['college_id']) ?>">
                                        <?= htmlspecialchars($college['college_name']) ?>
                                    </option>
                                <?php endforeach; ?>
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
            document.querySelectorAll('.edit-dept-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const deptId = this.dataset.id;
                    const deptName = this.dataset.name;
                    const collegeId = this.dataset.collegeId; // Get college_id from data attribute

                    document.getElementById('edit_department_id').value = deptId;
                    document.getElementById('edit_department_name').value = deptName;
                    document.getElementById('edit_college_id').value = collegeId; // Set selected college
                });
            });

            // Handle Delete button click with confirmation
            document.querySelectorAll('.delete-dept-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const deptId = this.dataset.id;
                    const deptName = this.dataset.name;
                    if (confirm(`Are you sure you want to delete the department "${deptName}"? This action cannot be undone.`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'manage_departments.php';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'delete';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'department_id';
                        idInput.value = deptId;
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
