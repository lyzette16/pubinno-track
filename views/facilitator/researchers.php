<?php
session_start();
require_once '../../config/config.php'; // Assuming config.php is here for general settings
require_once '../../config/connect.php';

// Ensure facilitator is logged in
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$department_id = $_SESSION['department_id'] ?? null;
$message = '';
$message_type = '';

if (!$department_id) {
    echo "Department not found.";
    exit();
}

// Delete researcher
if (isset($_GET['delete'])) {
    $delete_id = (int) $_GET['delete'];

    // Start transaction for deletion
    $conn->begin_transaction();
    try {
        // Delete related submissions first to avoid foreign key constraints
        // Or handle this depending on your database's foreign key ON DELETE/UPDATE rules.
        // For simplicity, we'll try to delete the user. If submissions exist and FK is CASCADE, they'll be deleted.
        // If not, you might need to handle submissions (e.g., reassign, delete, or reject user deletion).
        // For this example, assuming ON DELETE CASCADE or no submissions for user.
        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'researcher' AND department_id = ?");
        if (!$stmt_delete_user) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $stmt_delete_user->bind_param("ii", $delete_id, $department_id);
        if (!$stmt_delete_user->execute()) {
            throw new Exception("Execute statement failed: " . $stmt_delete_user->error);
        }
        $rows_affected = $stmt_delete_user->affected_rows;
        $stmt_delete_user->close();

        if ($rows_affected > 0) {
            $conn->commit();
            $_SESSION['message'] = 'Researcher deleted successfully.';
            $_SESSION['message_type'] = 'success';
        } else {
            $conn->rollback();
            $_SESSION['message'] = 'Researcher not found or could not be deleted.';
            $_SESSION['message_type'] = 'danger';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error deleting researcher: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: researchers.php");
    exit();
}

// Update researcher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'])) {
    $id = (int) $_POST['update_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password']; // Can be empty

    if (empty($name) || empty($email)) {
        $_SESSION['message'] = "Name and Email cannot be empty.";
        $_SESSION['message_type'] = 'danger';
        header("Location: researchers.php");
        exit();
    }

    $conn->begin_transaction(); // Start transaction for update
    try {
        if (!empty($password)) {
            // Validate password length if necessary
            if (strlen($password) < 6) { // Example: minimum 6 characters
                throw new Exception("Password must be at least 6 characters long.");
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE user_id = ? AND role = 'researcher' AND department_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            $stmt->bind_param("sssii", $name, $email, $hashed, $id, $department_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE user_id = ? AND role = 'researcher' AND department_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            $stmt->bind_param("ssii", $name, $email, $id, $department_id);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute statement failed: " . $stmt->error);
        }
        $rows_affected = $stmt->affected_rows;
        $stmt->close();

        if ($rows_affected > 0) {
            $conn->commit();
            $_SESSION['message'] = 'Researcher updated successfully.';
            $_SESSION['message_type'] = 'success';
        } else {
            $conn->rollback();
            // Could mean no changes were made or user not found/not in department
            $_SESSION['message'] = 'No changes made or researcher not found in your department.';
            $_SESSION['message_type'] = 'info';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error updating researcher: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: researchers.php");
    exit();
}

// Fetch researchers for display
$stmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE role = 'researcher' AND department_id = ? ORDER BY name ASC");
if (!$stmt) {
    // Handle error if prepare fails (e.g., log it)
    die("Failed to prepare statement: " . $conn->error);
}
$stmt->bind_param("i", $department_id);
$stmt->execute();
$result = $stmt->get_result();

// Check for and display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']); // Clear message after displaying
    unset($_SESSION['message_type']);
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = ''; // No specific status for this page
?>

<!DOCTYPE html>
<html>
<head>
    <title>Researchers in Department</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">PubInno-track: Publication and Innovation Tracking System</a>
            <div class="d-flex ms-auto">
                <span class="navbar-text me-3 text-white">
                    Welcome, Facilitator (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; // Include the sidebar ?>

        <div id="main-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Researchers in Your Department</h4>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($result->num_rows === 0): ?>
                <div class="alert alert-warning">No researchers found in your department. You can <a href="faci_register.php">register new researchers here</a>.</div>
            <?php else: ?>
                <div class="bg-white p-4 rounded shadow-sm">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success me-2" onclick="openEditModal(<?= $row['user_id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>', '<?= htmlspecialchars(addslashes($row['email'])) ?>')">Update</button>
                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $row['user_id'] ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel">Edit Researcher</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="update_id" id="update_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Full Name</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (optional)</label>
                            <input type="password" name="password" id="edit_password" class="form-control" placeholder="Leave blank to keep current password">
                            <small class="form-text text-muted">Enter a new password if you want to change it.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function openEditModal(id, name, email) {
        document.getElementById('update_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_password').value = ''; // Clear password field on opening
        var editModal = new bootstrap.Modal(document.getElementById('editModal'));
        editModal.show();
    }

    function confirmDelete(id) {
        if (confirm('Are you sure you want to delete this researcher? This action cannot be undone.')) {
            window.location.href = 'researchers.php?delete=' + id;
        }
    }
    </script>
</body>
</html>