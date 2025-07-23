<?php
// File: views/pio/_navbar_pio.php
// This file assumes that $conn is available from the calling script (e.g., dashboard.php)
// and that session_start() has already been called.

$pio_user_id = $_SESSION['user_id'] ?? 0;
// Removed notification count logic as requested.
// $unread_notifications_count = 0;

// Only attempt to fetch if $conn is set and user_id is valid
// if ($pio_user_id > 0 && isset($conn)) {
//     $stmt_notif_count = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
//     if ($stmt_notif_count) {
//         $stmt_notif_count->bind_param("i", $pio_user_id);
//         $stmt_notif_count->execute();
//         $stmt_notif_count->bind_result($unread_notifications_count);
//         $stmt_notif_count->fetch();
//         $stmt_notif_count->close();
//     } else {
//         // Log the error if the statement preparation fails
//         error_log("Failed to prepare statement for fetching notification count in PIO navbar: " . $conn->error);
//     }
// }
?>

<!-- Modern Light Navbar for PIO -->
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm py-3">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-primary" href="dashboard.php">
            <i class="bi bi-journal-bookmark-fill me-2"></i>PubInno-track: PIO Panel
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Left links for PIO -->
            <!-- <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link px-3 <?= (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active text-primary fw-semibold' : '' ?>" href="dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?= (basename($_SERVER['PHP_SELF']) == 'manage_users.php') ? 'active text-primary fw-semibold' : '' ?>" href="manage_users.php">Manage Users</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle px-3" href="#" id="navbarDropdownManagement" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        System Management
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navbarDropdownManagement">
                        <li><a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'manage_departments.php') ? 'active text-primary fw-semibold' : '' ?>" href="manage_departments.php">Manage Departments</a></li>
                        <li><a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'manage_campuses.php') ? 'active text-primary fw-semibold' : '' ?>" href="manage_campuses.php">Manage Campuses</a></li>
                        <li><a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'manage_publication_types.php') ? 'active text-primary fw-semibold' : '' ?>" href="manage_publication_types.php">Manage Submission Types</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?= (basename($_SERVER['PHP_SELF']) == 'manage_submissions.php') ? 'active text-primary fw-semibold' : '' ?>" href="manage_submissions.php">Manage Submissions</a>
                </li>
            </ul> -->

            <!-- Right actions for PIO -->
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item me-3">
                    <a class="nav-link text-dark fw-semibold d-flex align-items-center p-0" href="profile.php" title="View Profile">
                        <i class="bi bi-person-circle fs-5 me-2 text-secondary"></i> <!-- User Icon -->
                        Welcome, <?= htmlspecialchars(str_replace('*', '', $_SESSION['username'] ?? 'PIO')) ?>
                    </a>
                </li>

                <!-- Removed Notification section as requested -->
                <!--
                <li class="nav-item me-3">
                    <a class="nav-link position-relative p-0" href="notifications.php" title="Notifications">
                        <i class="bi bi-bell-fill fs-5 text-secondary"></i>
                        <?php if ($unread_notifications_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                                <?= $unread_notifications_count ?>
                                <span class="visually-hidden">unread messages</span>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                -->

                <li class="nav-item">
                    <a class="btn btn-outline-danger rounded-pill px-3" href="../../auth/logout.php">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Optional: Add some style transitions -->
<style>
    .navbar-nav .nav-link {
        transition: all 0.2s ease-in-out;
    }
    .navbar-nav .nav-link:hover {
        color: #0d6efd !important;
        background-color: #f1f5ff;
        border-radius: 0.375rem;
    }
    .btn-outline-danger {
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .btn-outline-danger:hover {
        background-color: #dc3545;
        color: #fff;
    }
    /* Style for the new profile link */
    .navbar-nav .nav-item .nav-link.d-flex {
        padding: 0.5rem 0.75rem; /* Adjust padding for the combined icon and text */
        border-radius: 0.375rem;
    }
    .navbar-nav .nav-item .nav-link.d-flex:hover {
        background-color: #e9ecef; /* Light hover background */
        color: #0056b3 !important; /* Blue text on hover */
    }
    .dropdown-menu .dropdown-item.active,
    .dropdown-menu .dropdown-item:active {
        background-color: #007bff; /* Bootstrap primary blue */
        color: white;
    }
</style>
