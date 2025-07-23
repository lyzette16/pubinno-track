<?php
// File: views/researcher/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<div class="d-flex flex-column flex-shrink-0 p-3 bg-white shadow" style="width: 250px; height: 100vh; position: fixed; top: 0; left: 0;">
    <a href="dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none">
        <span class="fs-4 fw-bold text-primary">PubInno Track</span>
    </a>
    <hr>
    <ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item">
            <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : 'text-dark' ?>">
                <i class="bi bi-speedometer2 me-2"></i> Dashboard
            </a>
        </li>
        <li>
            <a href="submit.php" class="nav-link <?= $currentPage === 'submit.php' ? 'active' : 'text-dark' ?>">
                <i class="bi bi-upload me-2"></i> Submit Work
            </a>
        </li>
        <li>
            <a href="track.php" class="nav-link <?= $currentPage === 'track.php' ? 'active' : 'text-dark' ?>">
                <i class="bi bi-search me-2"></i> Track Submission
            </a>
        </li>
        <li>
            <a href="my_submissions.php" class="nav-link <?= $currentPage === 'my_submissions.php' ? 'active' : 'text-dark' ?>">
                <i class="bi bi-archive me-2"></i> My Submissions
            </a>
        </li>
    </ul>
    <hr>
    <div>
        <a href="../../auth/logout.php" class="btn btn-outline-danger w-100">
            <i class="bi bi-box-arrow-right me-2"></i> Logout
        </a>
    </div>
</div>
