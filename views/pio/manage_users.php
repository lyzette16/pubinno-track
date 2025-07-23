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
    $_SESSION['message'] = 'Your PIO account is not associated with a campus. Cannot manage users.';
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

// Get the desired role from the URL, default to 'all'
$requested_role = $_GET['role'] ?? 'all';

// Define valid roles for filtering and their display titles
$valid_roles = [
    'all'               => 'All Users',
    'facilitator'       => 'Facilitators',
    'researcher'        => 'Researchers',
    'external_office' => 'External Evaluators'
    // 'pio' is not included here as PIOs generally don't manage other PIOs via this interface.
];

// Validate the requested role, default to 'all' if invalid
if (!array_key_exists($requested_role, $valid_roles)) {
    $requested_role = 'all';
}
$page_title = $valid_roles[$requested_role];

// Fetch departments for the PIO's campus (for assigning to facilitators/researchers)
$departments = [];
$stmt_departments = $conn->prepare("SELECT department_id, name FROM departments WHERE campus_id = ? ORDER BY name ASC");
if ($stmt_departments) {
    $stmt_departments->bind_param("i", $pio_campus_id);
    $stmt_departments->execute();
    $result_departments = $stmt_departments->get_result();
    while ($row = $result_departments->fetch_assoc()) {
        $departments[] = $row;
    }
    $stmt_departments->close();
} else {
    error_log("Failed to fetch departments for user management: " . $conn->error);
}


// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        $conn->begin_transaction();
        try {
            if ($action === 'add') {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';
                $department_id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT); // Can be null

                // Basic validation
                if (empty($name) || empty($email) || empty($password) || empty($role)) {
                    throw new Exception("All fields (Name, Email, Password, Role) are required.");
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email format.");
                }
                if (strlen($password) < 6) {
                    throw new Exception("Password must be at least 6 characters long.");
                }

                // Check for duplicate email
                $stmt_check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                if (!$stmt_check_email) {
                    throw new Exception("Failed to prepare email check statement: " . $conn->error);
                }
                $stmt_check_email->bind_param("s", $email);
                $stmt_check_email->execute();
                if ($stmt_check_email->get_result()->num_rows > 0) {
                    throw new Exception("User with this email already exists.");
                }
                $stmt_check_email->close();

                // Validate department_id for roles that require it
                if (($role === 'facilitator' || $role === 'researcher') && !$department_id) {
                    throw new Exception("Department is required for Facilitator and Researcher roles.");
                }
                // If department_id is provided, ensure it belongs to the PIO's campus
                if ($department_id) {
                    $stmt_check_dept = $conn->prepare("SELECT department_id FROM departments WHERE department_id = ? AND campus_id = ?");
                    if (!$stmt_check_dept) {
                        throw new Exception("Failed to prepare department check statement: " . $conn->error);
                    }
                    $stmt_check_dept->bind_param("ii", $department_id, $pio_campus_id);
                    $stmt_check_dept->execute();
                    if ($stmt_check_dept->get_result()->num_rows === 0) {
                        throw new Exception("Selected department is invalid or not part of your campus.");
                    }
                    $stmt_check_dept->close();
                } else {
                    // Ensure department_id is NULL for roles that don't require it
                    if ($role === 'external_evaluator' || $role === 'pio') {
                        $department_id = null;
                    } else {
                        throw new Exception("Department is required for this role.");
                    }
                }
                
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, department_id, campus_id) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare add user statement: " . $conn->error);
                }
                // Use 'i' for department_id and campus_id, 's' for others.
                $stmt->bind_param("ssssii", $name, $email, $password_hash, $role, $department_id, $pio_campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add user: " . $stmt->error);
                }
                $stmt->close();
                $message = "User '{$name}' ({$role}) added successfully.";
                $messageType = 'success';

            } elseif ($action === 'edit') {
                $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? ''; // Optional
                $role = $_POST['role'] ?? '';
                $department_id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT); // Can be null

                if (!$user_id || empty($name) || empty($email) || empty($role)) {
                    throw new Exception("Missing user ID or required fields for edit.");
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email format.");
                }
                if (!empty($password) && strlen($password) < 6) {
                    throw new Exception("New password must be at least 6 characters long.");
                }

                // Verify the user belongs to the PIO's campus
                $stmt_verify_user = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND campus_id = ?");
                if (!$stmt_verify_user) {
                    throw new Exception("Failed to prepare user verification statement: " . $conn->error);
                }
                $stmt_verify_user->bind_param("ii", $user_id, $pio_campus_id);
                $stmt_verify_user->execute();
                if ($stmt_verify_user->get_result()->num_rows === 0) {
                    throw new Exception("Unauthorized to edit this user or user not found in your campus.");
                }
                $stmt_verify_user->close();

                // Check for duplicate email (excluding the current user being edited)
                $stmt_check_email_dup = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                if (!$stmt_check_email_dup) {
                    throw new Exception("Failed to prepare duplicate email check statement: " . $conn->error);
                }
                $stmt_check_email_dup->bind_param("si", $email, $user_id);
                $stmt_check_email_dup->execute();
                if ($stmt_check_email_dup->get_result()->num_rows > 0) {
                    throw new Exception("Another user with this email already exists.");
                }
                $stmt_check_email_dup->close();

                // Validate department_id for roles that require it
                if (($role === 'facilitator' || $role === 'researcher') && !$department_id) {
                    throw new Exception("Department is required for Facilitator and Researcher roles.");
                }
                // If department_id is provided, ensure it belongs to the PIO's campus
                if ($department_id) {
                    $stmt_check_dept = $conn->prepare("SELECT department_id FROM departments WHERE department_id = ? AND campus_id = ?");
                    if (!$stmt_check_dept) {
                        throw new Exception("Failed to prepare department check statement: " . $conn->error);
                    }
                    $stmt_check_dept->bind_param("ii", $department_id, $pio_campus_id);
                    $stmt_check_dept->execute();
                    if ($stmt_check_dept->get_result()->num_rows === 0) {
                        throw new Exception("Selected department is invalid or not part of your campus.");
                    }
                    $stmt_check_dept->close();
                } else {
                    // Ensure department_id is NULL for roles that don't require it
                    if ($role === 'external_evaluator' || $role === 'pio') {
                        $department_id = null;
                    } else {
                        throw new Exception("Department is required for this role.");
                    }
                }

                $update_password_sql = '';
                $params = [];
                $types = '';

                if (!empty($password)) {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update_password_sql = ', password_hash = ?';
                    $params[] = $password_hash;
                    $types .= 's';
                }

                $sql = "UPDATE users SET name = ?, email = ?, role = ?, department_id = ? {$update_password_sql} WHERE user_id = ? AND campus_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare edit user statement: " . $conn->error);
                }

                $params = array_merge([$name, $email, $role, $department_id], $params, [$user_id, $pio_campus_id]);
                // Construct the type string dynamically
                $types = 'sssi' . $types . 'ii'; // name, email, role, department_id, (password_hash), user_id, campus_id
                
                // Use call_user_func_array to bind parameters because the number of parameters is dynamic
                $bind_names = array_merge([$types], $params);
                $refs = [];
                foreach ($bind_names as $key => $value) {
                    $refs[$key] = &$bind_names[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], $refs);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update user: " . $stmt->error);
                }
                $stmt->close();
                $message = "User updated successfully.";
                $messageType = 'success';

            } elseif ($action === 'delete') {
                $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

                if (!$user_id) {
                    throw new Exception("Missing user ID for delete.");
                }

                // Verify the user belongs to the PIO's campus and is not the current PIO
                if ($user_id == $pio_id) {
                    throw new Exception("You cannot delete your own PIO account.");
                }

                $stmt_verify_user = $conn->prepare("SELECT user_id, role FROM users WHERE user_id = ? AND campus_id = ?");
                if (!$stmt_verify_user) {
                    throw new Exception("Failed to prepare user verification statement: " . $conn->error);
                }
                $stmt_verify_user->bind_param("ii", $user_id, $pio_campus_id);
                $stmt_verify_user->execute();
                $verify_result = $stmt_verify_user->get_result();
                if ($verify_result->num_rows === 0) {
                    throw new Exception("Unauthorized to delete this user or user not found in your campus.");
                }
                $user_to_delete = $verify_result->fetch_assoc();
                $stmt_verify_user->close();

                // Check for associated data before deleting (e.g., submissions, comments, logs)
                // This is a simplified check. A real system might reassign or cascade delete.
                $has_dependencies = false;
                $dependency_message = "Cannot delete user. ";

                if ($user_to_delete['role'] === 'researcher') {
                    $stmt_check_submissions = $conn->prepare("SELECT COUNT(*) FROM submissions WHERE researcher_id = ?");
                    $stmt_check_submissions->bind_param("i", $user_id);
                    $stmt_check_submissions->execute();
                    $count = 0;
                    $stmt_check_submissions->bind_result($count);
                    $stmt_check_submissions->fetch();
                    $stmt_check_submissions->close();
                    if ($count > 0) {
                        $has_dependencies = true;
                        $dependency_message .= "This researcher has {$count} associated submissions.";
                    }
                } elseif ($user_to_delete['role'] === 'facilitator' || $user_to_delete['role'] === 'pio' || $user_to_delete['role'] === 'external_evaluator') {
                    $stmt_check_logs = $conn->prepare("SELECT COUNT(*) FROM submission_status_logs WHERE changed_by = ?");
                    $stmt_check_logs->bind_param("i", $user_id);
                    $stmt_check_logs->execute();
                    $count = 0;
                    $stmt_check_logs->bind_result($count);
                    $stmt_check_logs->fetch();
                    $stmt_check_logs->close();
                    if ($count > 0) {
                        $has_dependencies = true;
                        $dependency_message .= "This user has {$count} entries in submission logs.";
                    }

                    $stmt_check_comments = $conn->prepare("SELECT COUNT(*) FROM submission_comments WHERE user_id = ?");
                    $stmt_check_comments->bind_param("i", $user_id);
                    $stmt_check_comments->execute();
                    $count_comments = 0;
                    $stmt_check_comments->bind_result($count_comments);
                    $stmt_check_comments->fetch();
                    $stmt_check_comments->close();
                    if ($count_comments > 0) {
                        $has_dependencies = true;
                        $dependency_message .= ($has_dependencies ? " And " : "") . "This user has {$count_comments} associated comments.";
                    }
                }

                if ($has_dependencies) {
                    throw new Exception($dependency_message . " Please remove dependencies before deleting.");
                }

                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND campus_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete user statement: " . $conn->error);
                }
                $stmt->bind_param("ii", $user_id, $pio_campus_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete user: " . $stmt->error);
                }
                $stmt->close();
                $message = "User deleted successfully.";
                $messageType = 'success';

            } else {
                throw new Exception("Invalid action specified.");
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
            error_log("User Management Error: " . $e->getMessage());
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: manage_users.php?role=" . urlencode($requested_role));
        exit();
    }
}

// Fetch users for the current role and PIO's campus
$users = [];
$sql = "SELECT u.user_id, u.name, u.email, u.role, d.name AS department_name, c.campus_name AS campus_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.department_id
        LEFT JOIN campus c ON u.campus_id = c.campus_id
        WHERE u.campus_id = ?";
$params = [$pio_campus_id];
$types = "i";

if ($requested_role !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $requested_role;
    $types .= "s";
}
$sql .= " ORDER BY u.name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // Use call_user_func_array to bind parameters dynamically
    $bind_names = array_merge([$types], $params);
    $refs = [];
    foreach ($bind_names as $key => $value) {
        $refs[$key] = &$bind_names[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement for fetching users: " . $conn->error);
    $message = "Database error fetching users.";
    $messageType = 'danger';
}

$conn->close();

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - PIO Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
       /* Base Styling */
/* Base Styling */
/* Base Styling */
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 16px; /* Larger base font */
    background-color: #f4f6f8;
    color: #1a1a1a; /* Darker text */
    margin: 0;
    padding: 0;
}

/* Main Layout */
.main-content-wrapper {
    display: flex;
    flex-direction: row;
    flex-grow: 1;
    margin-top: 20px;
    padding: 0 20px;
    box-sizing: border-box;
}

#main-content {
    flex-grow: 1;
    padding-left: 20px;
}

/* Card Design */
.card {
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid #e0e0e0;
    background-color: #ffffff;
    overflow: hidden;
}

.card-header {
    background-color: #003366;
    color: white;
    padding: 16px 24px;
    font-size: 18px;
    font-weight: 600;
}

/* Table Styling */
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    color: #1a1a1a;
}

.table thead {
    background-color: #f0f0f0;
    font-weight: bold;
}

.table thead th {
    padding: 14px 16px;
    border-bottom: 2px solid #dee2e6;
    font-size: 15px;
    text-align: left;
}

.table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #e6e6e6;
    font-size: 15px;
}

.table tbody tr:hover {
    background-color: #f9f9f9;
}

/* Minimal Action Buttons */
.btn {
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid #bbb;
    background-color: transparent !important;
    color: #222 !important;
    margin-right: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn:hover {
    background-color: #e6e6e6;
    color: #000;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    padding: 20px 0;
    list-style: none;
    margin: 0;
    gap: 8px;
}

.pagination li {
    display: inline;
}

.pagination a {
    display: inline-block;
    padding: 8px 14px;
    border: 1px solid #ccc;
    border-radius: 5px;
    background-color: #fff;
    color: #333;
    text-decoration: none;
    font-size: 14px;
}

.pagination a:hover {
    background-color: #f0f0f0;
}

/* Modal Styling */
.modal-title {
    font-size: 17px;
    font-weight: 600;
}

.btn-close-white {
    filter: invert(1);
}

/* Responsive Table */
@media screen and (max-width: 768px) {
    .table thead {
        display: none;
    }

    .table tbody td {
        display: block;
        width: 100%;
        text-align: right;
        padding-left: 50%;
        position: relative;
        border-bottom: 1px solid #eee;
    }

    .table tbody td::before {
        content: attr(data-label);
        position: absolute;
        left: 10px;
        top: 10px;
        font-weight: bold;
        text-align: left;
        color: #666;
    }

    .table tbody tr {
        margin-bottom: 15px;
        display: block;
        border-bottom: 2px solid #ddd;
    }
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
            <h4 class="mb-4">Manage Users - <?= htmlspecialchars($page_title) ?></h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <ul class="nav nav-pills">
                    <?php foreach ($valid_roles as $role_key => $role_title): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($requested_role === $role_key) ? 'active' : '' ?>" href="manage_users.php?role=<?= urlencode($role_key) ?>">
                                <?= htmlspecialchars($role_title) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Add New User
                </button>
            </div>

            <?php if (empty($users)): ?>
                <p class="text-muted">No <?= htmlspecialchars(strtolower($page_title)) ?> found for your campus.</p>
            <?php else: ?>
                <div class="table-responsive bg-white p-3 rounded shadow-sm">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Role</th>
                                <th scope="col">Department</th>
                                <th scope="col">Campus</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $index => $user): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) ?></td>
                                    <td><?= htmlspecialchars($user['department_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($user['campus_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning edit-user-btn me-2"
                                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                                            data-id="<?= $user['user_id'] ?>"
                                            data-name="<?= htmlspecialchars($user['name']) ?>"
                                            data-email="<?= htmlspecialchars($user['email']) ?>"
                                            data-role="<?= htmlspecialchars($user['role']) ?>"
                                            data-department-id="<?= htmlspecialchars($user['department_id'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-user-btn"
                                            data-id="<?= $user['user_id'] ?>"
                                            data-name="<?= htmlspecialchars($user['name']) ?>"
                                            <?= ($user['user_id'] == $pio_id) ? 'disabled title="You cannot delete your own account"' : '' ?>>
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

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_users.php?role=<?= urlencode($requested_role) ?>" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="add_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="add_email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="add_password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="add_password" name="password" required minlength="6">
                            <small class="form-text text-muted">Minimum 6 characters.</small>
                        </div>
                        <div class="mb-3">
                            <label for="add_role" class="form-label">Role</label>
                            <select class="form-select" id="add_role" name="role" required onchange="toggleDepartmentDropdown('add_role', 'add_department_group')">
                                <option value="">-- Select Role --</option>
                                <option value="facilitator">Facilitator</option>
                                <option value="researcher">Researcher</option>
                                <option value="external_evaluator">External Evaluator</option>
                                <!-- PIO role not manageable here -->
                            </select>
                        </div>
                        <div class="mb-3" id="add_department_group" style="display: none;">
                            <label for="add_department_id" class="form-label">Department</label>
                            <select class="form-select" id="add_department_id" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['department_id']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Required for Facilitators and Researchers.</small>
                        </div>
                        <div class="mb-3">
                            <label for="add_campus_name" class="form-label">Campus</label>
                            <input type="text" class="form-control" id="add_campus_name" value="<?= htmlspecialchars($_SESSION['campus_name'] ?? 'N/A') ?>" readonly>
                            <input type="hidden" name="campus_id" value="<?= htmlspecialchars($pio_campus_id) ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_users.php?role=<?= urlencode($requested_role) ?>" method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (Leave blank to keep current)</label>
                            <input type="password" class="form-control" id="edit_password" name="password" minlength="6">
                            <small class="form-text text-muted">Minimum 6 characters.</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Role</label>
                            <select class="form-select" id="edit_role" name="role" required onchange="toggleDepartmentDropdown('edit_role', 'edit_department_group')">
                                <option value="facilitator">Facilitator</option>
                                <option value="researcher">Researcher</option>
                                <option value="external_evaluator">External Evaluator</option>
                                <!-- PIO role cannot be set/changed here -->
                            </select>
                        </div>
                        <div class="mb-3" id="edit_department_group">
                            <label for="edit_department_id" class="form-label">Department</label>
                            <select class="form-select" id="edit_department_id" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['department_id']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Required for Facilitators and Researchers.</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_campus_name" class="form-label">Campus</label>
                            <input type="text" class="form-control" id="edit_campus_name" value="<?= htmlspecialchars($_SESSION['campus_name'] ?? 'N/A') ?>" readonly>
                            <input type="hidden" name="campus_id" value="<?= htmlspecialchars($pio_campus_id) ?>">
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
        // Function to toggle department dropdown visibility based on role
        function toggleDepartmentDropdown(roleSelectId, departmentGroupId) {
            const roleSelect = document.getElementById(roleSelectId);
            const departmentGroup = document.getElementById(departmentGroupId);
            const departmentSelect = departmentGroup.querySelector('select');

            if (roleSelect.value === 'facilitator' || roleSelect.value === 'researcher') {
                departmentGroup.style.display = 'block';
                departmentSelect.setAttribute('required', 'required');
            } else {
                departmentGroup.style.display = 'none';
                departmentSelect.removeAttribute('required');
                departmentSelect.value = ''; // Clear selection when hidden
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initial call for add user modal (in case it's opened without role change)
            toggleDepartmentDropdown('add_role', 'add_department_group');

            // Handle Edit button click to populate modal
            document.querySelectorAll('.edit-user-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.dataset.id;
                    const userName = this.dataset.name;
                    const userEmail = this.dataset.email;
                    const userRole = this.dataset.role;
                    const userDepartmentId = this.dataset.departmentId;

                    document.getElementById('edit_user_id').value = userId;
                    document.getElementById('edit_name').value = userName;
                    document.getElementById('edit_email').value = userEmail;
                    document.getElementById('edit_role').value = userRole;
                    document.getElementById('edit_password').value = ''; // Clear password field on edit

                    // Set department dropdown and toggle visibility
                    document.getElementById('edit_department_id').value = userDepartmentId;
                    toggleDepartmentDropdown('edit_role', 'edit_department_group');
                });
            });

            // Handle Delete button click with confirmation
            document.querySelectorAll('.delete-user-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.dataset.id;
                    const userName = this.dataset.name;
                    // Using a custom confirmation modal is better than alert/confirm for UI consistency
                    if (confirm(`Are you sure you want to delete the user "${userName}"? This action cannot be undone and may affect associated data.`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'manage_users.php?role=<?= urlencode($requested_role) ?>';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'delete';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'user_id';
                        idInput.value = userId;
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
