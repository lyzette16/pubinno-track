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
// Note: PIO campus_id is not directly used to filter programs, as programs are linked to colleges,
// and colleges are not directly linked to campuses in the provided schema.
// A PIO can manage programs for any active college.

$message = '';
$messageType = '';

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
                $program_name = trim($_POST['program_name'] ?? '');
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT);
                $program_code = trim($_POST['program_code'] ?? '');
                $is_active = $_POST['is_active'] ?? 1; // Default to active (1)

                if (empty($program_name)) {
                    throw new Exception("Program name cannot be empty.");
                }
                if (!$college_id) {
                    throw new Exception("Please select a college for the program.");
                }
                if (empty($program_code)) {
                    throw new Exception("Program Code cannot be empty.");
                }
                if (!preg_match('/^[0-9]{2}$/', $program_code)) {
                    throw new Exception("Program Code must be a 2-digit number.");
                }

                // Check for duplicate program name within the same college
                $stmt_check_name = $conn->prepare("SELECT program_id FROM programs WHERE program_name = ? AND college_id = ?");
                if (!$stmt_check_name) {
                    throw new Exception("Failed to prepare check name statement: " . $conn->error);
                }
                $stmt_check_name->bind_param("si", $program_name, $college_id);
                $stmt_check_name->execute();
                $check_name_result = $stmt_check_name->get_result();
                if ($check_name_result->num_rows > 0) {
                    throw new Exception("A program with this name already exists in the selected college.");
                }
                $stmt_check_name->close();

                // Check for duplicate program code across all programs (assuming program codes are globally unique)
                $stmt_check_code = $conn->prepare("SELECT program_id FROM programs WHERE program_code = ?");
                if (!$stmt_check_code) {
                    throw new Exception("Failed to prepare check code statement: " . $conn->error);
                }
                $stmt_check_code->bind_param("s", $program_code);
                $stmt_check_code->execute();
                $check_code_result = $stmt_check_code->get_result();
                if ($check_code_result->num_rows > 0) {
                    throw new Exception("A program with this Program Code already exists.");
                }
                $stmt_check_code->close();

                // Insert new program
                $stmt = $conn->prepare("INSERT INTO programs (program_name, college_id, program_code, is_active) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare add statement: " . $conn->error);
                }
                $stmt->bind_param("sisi", $program_name, $college_id, $program_code, $is_active);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add program: " . $stmt->error);
                }
                $stmt->close();
                $message = "Program '{$program_name}' added successfully.";
                $messageType = 'success';

            } elseif ($action === 'edit') {
                $program_id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
                $program_name = trim($_POST['program_name'] ?? '');
                $college_id = filter_var($_POST['college_id'] ?? null, FILTER_VALIDATE_INT);
                $program_code = trim($_POST['program_code'] ?? '');
                $is_active = $_POST['is_active'] ?? 1;

                if (!$program_id || empty($program_name) || !$college_id || empty($program_code)) {
                    throw new Exception("Missing program ID, name, college, or code for edit.");
                }
                if (!preg_match('/^[0-9]{2}$/', $program_code)) {
                    throw new Exception("Program Code must be a 2-digit number.");
                }

                // Check for duplicate program name (excluding the current program) within the same college
                $stmt_check_dup_name = $conn->prepare("SELECT program_id FROM programs WHERE program_name = ? AND college_id = ? AND program_id != ?");
                if (!$stmt_check_dup_name) {
                    throw new Exception("Failed to prepare duplicate name check statement: " . $conn->error);
                }
                $stmt_check_dup_name->bind_param("sii", $program_name, $college_id, $program_id);
                $stmt_check_dup_name->execute();
                if ($stmt_check_dup_name->get_result()->num_rows > 0) {
                    throw new Exception("A program with this name already exists in the selected college.");
                }
                $stmt_check_dup_name->close();

                // Check for duplicate program code (excluding the current program)
                $stmt_check_dup_code = $conn->prepare("SELECT program_id FROM programs WHERE program_code = ? AND program_id != ?");
                if (!$stmt_check_dup_code) {
                    throw new Exception("Failed to prepare duplicate code check statement: " . $conn->error);
                }
                $stmt_check_dup_code->bind_param("si", $program_code, $program_id);
                $stmt_check_dup_code->execute();
                if ($stmt_check_dup_code->get_result()->num_rows > 0) {
                    throw new Exception("A program with this Program Code already exists.");
                }
                $stmt_check_dup_code->close();

                // Update program
                $stmt = $conn->prepare("UPDATE programs SET program_name = ?, college_id = ?, program_code = ?, is_active = ?, updated_at = NOW() WHERE program_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare edit statement: " . $conn->error);
                }
                $stmt->bind_param("sisii", $program_name, $college_id, $program_code, $is_active, $program_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update program: " . $stmt->error);
                }
                $stmt->close();
                $message = "Program updated successfully.";
                $messageType = 'success';

            } elseif ($action === 'delete') {
                $program_id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);

                if (!$program_id) {
                    throw new Exception("Missing program ID for delete.");
                }

                // Check for associated submissions before deleting
                $stmt_check_submissions = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE program_id = ?");
                $stmt_check_submissions->bind_param("i", $program_id);
                $stmt_check_submissions->execute();
                $count_submissions = 0;
                $stmt_check_submissions->bind_result($count_submissions);
                $stmt_check_submissions->fetch();
                $stmt_check_submissions->close();

                if ($count_submissions > 0) {
                    throw new Exception("Cannot delete program. There are {$count_submissions} submissions associated with it. Please reassign submissions first.");
                }

                // Delete program
                $stmt = $conn->prepare("DELETE FROM programs WHERE program_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }
                $stmt->bind_param("i", $program_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete program: " . $stmt->error);
                }
                $stmt->close();
                $message = "Program deleted successfully.";
                $messageType = 'success';

            } else {
                throw new Exception("Invalid action specified.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
            error_log("Program Management Error: " . $e->getMessage());
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: manage_programs.php");
        exit();
    }
}

// Fetch all programs, including college name
$programs = [];
$sql_query = "SELECT
                p.program_id,
                p.program_name,
                p.college_id,
                c.college_name,
                p.program_code,
                p.is_active,
                p.created_at,
                p.updated_at
              FROM
                programs p
              LEFT JOIN
                colleges c ON p.college_id = c.college_id
              ORDER BY p.program_name ASC";

$stmt = $conn->prepare($sql_query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $programs[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching programs: " . $conn->error);
    $message = "Database error fetching programs.";
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
    <title>Manage Programs - PIO Panel</title>
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
            <h4 class="mb-4">Manage Programs</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                    <i class="bi bi-plus-circle me-1"></i> Add New Program
                </button>
            </div>

            <?php if (empty($programs)): ?>
                <p class="text-muted">No programs found. Click "Add New Program" to get started.</p>
            <?php else: ?>
                <div class="table-responsive bg-white p-3 rounded shadow-sm">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Program Name</th>
                                <th scope="col">College Name</th>
                                <th scope="col">Program Code</th>
                                <th scope="col">Status</th>
                                <th scope="col">Created At</th>
                                <th scope="col">Updated At</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programs as $index => $program): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($program['program_name']) ?></td>
                                    <td><?= htmlspecialchars($program['college_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($program['program_code']) ?></td>
                                    <td>
                                        <span class="badge <?= ($program['is_active'] == 1) ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= ($program['is_active'] == 1) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= date("F j, Y g:i A", strtotime($program['created_at'])) ?></td>
                                    <td><?= date("F j, Y g:i A", strtotime($program['updated_at'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning edit-program-btn me-2"
                                            data-bs-toggle="modal" data-bs-target="#editProgramModal"
                                            data-id="<?= $program['program_id'] ?>"
                                            data-name="<?= htmlspecialchars($program['program_name']) ?>"
                                            data-college-id="<?= htmlspecialchars($program['college_id']) ?>"
                                            data-code="<?= htmlspecialchars($program['program_code']) ?>"
                                            data-active="<?= htmlspecialchars($program['is_active']) ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-program-btn"
                                            data-id="<?= $program['program_id'] ?>"
                                            data-name="<?= htmlspecialchars($program['program_name']) ?>">
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

    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_programs.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addProgramModalLabel">Add New Program</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_program_name" class="form-label">Program Name</label>
                            <input type="text" class="form-control" id="add_program_name" name="program_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_college_id" class="form-label">College/Institute</label>
                            <select class="form-select" id="add_college_id" name="college_id" required>
                                <option value="">Select College</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?= htmlspecialchars($college['college_id']) ?>">
                                        <?= htmlspecialchars($college['college_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="add_program_code" class="form-label">Program Code</label>
                            <input type="text" class="form-control" id="add_program_code" name="program_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit program code (e.g., 01, 10).</div>
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
                        <button type="submit" class="btn btn-primary">Add Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Program Modal -->
    <div class="modal fade" id="editProgramModal" tabindex="-1" aria-labelledby="editProgramModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_programs.php" method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editProgramModalLabel">Edit Program</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="program_id" id="edit_program_id">
                        <div class="mb-3">
                            <label for="edit_program_name" class="form-label">Program Name</label>
                            <input type="text" class="form-control" id="edit_program_name" name="program_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_college_id" class="form-label">College/Institute</label>
                            <select class="form-select" id="edit_college_id" name="college_id" required>
                                <option value="">Select College</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?= htmlspecialchars($college['college_id']) ?>">
                                        <?= htmlspecialchars($college['college_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_program_code" class="form-label">Program Code</label>
                            <input type="text" class="form-control" id="edit_program_code" name="program_code" required maxlength="2" pattern="[0-9]{2}" title="Please enter a 2-digit number (e.g., 01, 10)">
                            <div class="form-text">Enter a unique 2-digit program code (e.g., 01, 10).</div>
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
            document.querySelectorAll('.edit-program-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const programId = this.dataset.id;
                    const programName = this.dataset.name;
                    const collegeId = this.dataset.collegeId;
                    const programCode = this.dataset.code;
                    const isActive = this.dataset.active;

                    document.getElementById('edit_program_id').value = programId;
                    document.getElementById('edit_program_name').value = programName;
                    document.getElementById('edit_college_id').value = collegeId;
                    document.getElementById('edit_program_code').value = programCode;
                    document.getElementById('edit_is_active').value = isActive;
                });
            });

            // Handle Delete button click with confirmation
            document.querySelectorAll('.delete-program-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const programId = this.dataset.id;
                    const programName = this.dataset.name;
                    if (confirm(`Are you sure you want to delete the program "${programName}"? This action cannot be undone if there are associated submissions.`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'manage_programs.php';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'delete';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'program_id';
                        idInput.value = programId;
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
