<?php
session_start();
require_once '../config/connect.php';

// Fetch departments for dropdown
$departments = $conn->query("SELECT * FROM departments");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script>
        function toggleDepartment() {
            const role = document.querySelector('select[name="role"]').value;
            const deptGroup = document.getElementById('dept-group');
            deptGroup.style.display = (role === 'researcher' || role === 'facilitator') ? 'block' : 'none';
        }
    </script>
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="col-md-6 offset-md-3 bg-white p-4 rounded shadow-sm">
        <h4>Register New User</h4>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <form method="POST" action="register_process.php">
            <div class="mb-3">
                <label>Full Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Role</label>
                <select name="role" class="form-select" onchange="toggleDepartment()" required>
                    <option value="researcher">Researcher</option>
                    <option value="facilitator">Facilitator</option>
                    <option value="pio">PIO</option>
                </select>
            </div>

            <div class="mb-3" id="dept-group">
                <label>Department</label>
                <select name="department_id" class="form-select">
                    <?php while ($dept = $departments->fetch_assoc()): ?>
                        <option value="<?= $dept['department_id'] ?>">
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button class="btn btn-primary w-100" type="submit">Register</button>
        </form>
    </div>
</div>

<script>
    // Call on load in case of browser prefill
    toggleDepartment();
</script>
</body>
</html>
