<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Ensure the user is logged in and is a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = null;

// Fetch user data
$stmt = $conn->prepare("SELECT name, email, role, department_id, campus_id FROM users WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        // Fetch department name (PIO might not have one, or it might be a general one)
        if ($user_data['department_id']) {
            $stmt_dept = $conn->prepare("SELECT name FROM departments WHERE department_id = ?");
            $stmt_dept->bind_param("i", $user_data['department_id']);
            $stmt_dept->execute();
            $result_dept = $stmt_dept->get_result();
            $dept_name = $result_dept->fetch_assoc();
            $user_data['department_name'] = $dept_name['name'] ?? 'N/A';
            $stmt_dept->close();
        } else {
            $user_data['department_name'] = 'N/A';
        }
        // Fetch campus name
        if ($user_data['campus_id']) {
            $stmt_campus = $conn->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $stmt_campus->bind_param("i", $user_data['campus_id']);
            $stmt_campus->execute();
            $result_campus = $stmt_campus->get_result();
            $campus_name = $result_campus->fetch_assoc();
            $user_data['campus_name'] = $campus_name['campus_name'] ?? 'N/A';
            $stmt_campus->close();
        } else {
            $user_data['campus_name'] = 'N/A';
        }

    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - PIO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Styles from sidebar.php relevant to the overall layout */
        body {
            background-color: #eef2f6; /* Light background for the main content area */
            display: flex; /* Use flexbox for main content + sidebar */
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            margin: 0; /* Remove default body margin */
            color: #212529; /* Default text color for main content */
        }

        /* Main Content Wrapper - now handles the entire right side */
        .main-content-area {
            display: flex;
            flex-direction: column; /* Stack header and content vertically */
            flex-grow: 1; /* Allows it to take up remaining space */
            background-color: #eef2f6; /* Consistent background */
            color: #333; /* Dark text for main content */
            border-radius: 1rem 0 0 0; /* Rounded top-left corner */
            margin-left: 20px; /* Space between sidebar and main content */
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05);
        }

        /* Top Navbar Style (Integrated) */
        .top-navbar {
            background-color: #ffffff;
            padding: 1rem 20px;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #333;
            flex-shrink: 0; /* Don't allow it to shrink */
            border-radius: 1rem 0 0 0; /* Rounded top-left corner */
        }

        .top-navbar .welcome-text {
            font-weight: 500;
            font-size: 1rem;
        }

        .top-navbar .logout-btn {
            background-color: #f8f9fa;
            border: 1px solid #ced4da;
            color: #333;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .top-navbar .logout-btn:hover {
            background-color: #e2e6ea;
            border-color: #dae0e5;
        }

        /* Profile specific styles */
        .profile-container {
            max-width: 850px; /* Increased width */
            margin: 40px auto; /* Center within the main-content-area's padding */
            background: #fff;
            border-radius: 1rem;
            padding: 3rem; /* Increased padding */
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }
        .profile-header {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 1.5rem; /* Increased padding */
            margin-bottom: 2.5rem; /* Increased margin */
        }
        .profile-header h4 {
            font-weight: 700; /* Made bolder */
            margin-bottom: 0.75rem; /* Adjusted margin for more space */
            font-size: 2rem; /* Larger font for prominence */
            color: #0056b3; /* A distinct color for the heading */
        }
        .profile-info i {
            color: #6c757d;
            margin-right: 15px; /* Increased margin */
            font-size: 1.2rem; /* Slightly larger icon */
            width: 25px; /* Fixed width for alignment */
            text-align: center;
        }
        .profile-info .info-item {
            margin-bottom: 1.5rem; /* Increased margin */
            font-size: 1.1rem; /* Slightly larger font */
            display: flex;
            align-items: center;
            border-bottom: 1px dashed #eee; /* Subtle separator */
            padding-bottom: 1.2rem;
        }
        .profile-info .info-item:last-child {
            border-bottom: none; /* No border for the last item */
            padding-bottom: 0;
            margin-bottom: 0;
        }
        .profile-info .info-item strong {
            min-width: 120px; /* Align labels */
            display: inline-block;
        }
        .badge-role {
            font-size: 0.95rem; /* Slightly larger badge font */
            background-color: #0d6efd;
            padding: 0.6em 0.9em;
            border-radius: 0.5rem;
        }

        /* Responsive Adjustments (from sidebar.php) */
        @media (max-width: 992px) { /* Adjust breakpoint as needed for tablet/mobile */
            .main-content-area {
                margin-left: 80px; /* Adjust main content to start after collapsed sidebar */
                width: calc(100% - 80px); /* Fill remaining width */
                border-radius: 0; /* No rounded corners on mobile */
            }
            .top-navbar {
                border-radius: 0; /* No rounded corners on mobile */
            }
            .profile-container {
                margin: 20px auto; /* Adjust margin for smaller screens */
                padding: 1.5rem; /* Adjust padding for smaller screens */
            }
            .profile-header h4 {
                font-size: 1.5rem;
            }
            .profile-info .info-item {
                font-size: 1rem;
                margin-bottom: 1rem;
                padding-bottom: 1rem;
            }
            .profile-info i {
                font-size: 1rem;
                margin-right: 10px;
                width: 20px;
            }
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; // Include the sidebar ?>

    <div class="main-content-area">
     

        <div class="container-fluid p-4 pt-5"> <!-- Added padding-top to create space -->
            <div class="profile-container">
                <div class="profile-header d-flex justify-content-between align-items-center">
                    <div>
                        <h4>My Profile</h4>
                        <p class="text-muted mb-0">Basic user information</p>
                    </div>
                    <span class="badge text-bg-primary badge-role">
                        <i class="bi bi-person-badge-fill me-1"></i>
                        <?= ucfirst($user_data['role'] ?? 'PIO') ?>
                    </span>
                </div>

                <?php if ($user_data): ?>
                <div class="profile-info">
                    <div class="info-item"><i class="bi bi-person-fill"></i><strong>Name:</strong> <?= htmlspecialchars($user_data['name']) ?></div>
                    <div class="info-item"><i class="bi bi-envelope-fill"></i><strong>Email:</strong> <?= htmlspecialchars($user_data['email']) ?></div>
                    <div class="info-item"><i class="bi bi-building-fill"></i><strong>Department:</strong> <?= htmlspecialchars($user_data['department_name']) ?></div>
                    <div class="info-item"><i class="bi bi-geo-alt-fill"></i><strong>Campus:</strong> <?= htmlspecialchars($user_data['campus_name']) ?></div>
                    <!-- User ID is usually not displayed on public profile, but can be added if needed -->
                    <!-- <div class="info-item"><i class="bi bi-hash"></i><strong>User ID:</strong> <?= htmlspecialchars($user_id) ?></div> -->
                </div>
                <?php else: ?>
                <div class="alert alert-warning text-center">Unable to fetch user details.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Close the connection ONLY after all PHP code (including included files) has finished using it.
if (isset($conn) && $conn) {
    $conn->close();
}
?>
