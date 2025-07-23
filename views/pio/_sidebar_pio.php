<?php
// File: views/pio/_sidebar_pio.php

// Ensure $currentPage is defined for highlighting
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$currentStatus = $currentStatus ?? ''; // Used for submission management links

// Function to check if any of the given pages are active to highlight a parent dropdown
function is_any_page_active($pages, $currentPage) {
    foreach ($pages as $page) {
        if ($currentPage === $page) {
            return true;
        }
    }
    return false;
}

// Define pages for 'Manage Types' dropdown
$manageTypesPages = [
    'manage_publication_types.php',
    'manage_innovation_types.php'
];

// Define pages for 'Manage Requirements' dropdown
$manageRequirementsPages = [
    'manage_requirements_master.php',
    'manage_pub_type_requirements.php',
    'manage_inno_type_requirements.php'
];

?>
<style>
/* Sidebar container */
#sidebar {
    background-color: #f8f9fa;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Headings */
#sidebar h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #343a40;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 0.4rem;
    margin-bottom: 1rem;
}

/* Nav links */
#sidebar .nav-link {
    color: #495057;
    font-weight: 500;
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
    transition: all 0.2s ease-in-out;
    display: flex;
    align-items: center;
}

#sidebar .nav-link i {
    font-size: 1.1rem;
    color: #0d6efd;
    margin-right: 6px;
}

/* Hover effect */
#sidebar .nav-link:hover {
    background-color: #e9f2ff;
    color: #0d6efd;
}

/* Active link */
#sidebar .nav-link.active {
    background-color: #0d6efd;
    color: white;
    font-weight: 600;
}

#sidebar .nav-link.active i {
    color: white;
}

/* Section spacing */
#sidebar ul.nav {
    margin-bottom: 2rem;
}

/* Dropdown specific styles */
.dropdown-toggle::after {
    display: inline-block;
    margin-left: 0.5em;
    vertical-align: 0.255em;
    content: "";
    border-top: 0.3em solid;
    border-right: 0.3em solid transparent;
    border-bottom: 0;
    border-left: 0.3em solid transparent;
}

.dropdown-menu {
    border: none;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    border-radius: 0.5rem;
    padding: 0.5rem 0;
    margin-top: 0.25rem;
}

.dropdown-item {
    padding: 0.5rem 1rem;
    color: #495057;
    transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out;
    display: flex;
    align-items: center;
}

.dropdown-item i {
    margin-right: 0.5rem;
    color: #0d6efd;
}

.dropdown-item:hover {
    background-color: #e9ecef;
    color: #0d6efd;
}

.dropdown-item.active, .dropdown-item:active {
    background-color: #0d6efd;
    color: white;
}
.dropdown-item.active i, .dropdown-item:active i {
    color: white;
}

/* Adjust dropdown toggle padding to match nav-link */
.nav-item .dropdown-toggle {
    padding-right: 0.75rem; /* Match nav-link padding */
}

/* Ensure dropdown menu background is white */
.dropdown-menu {
    background-color: #ffffff;
}
</style>

<!-- Sidebar HTML -->
<div id="sidebar">
    <h5 class="mb-3">Dashboard</h5>
    <ul class="nav nav-pills flex-column mb-4">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php">
                <i class="bi bi-house-door"></i> Dashboard Home
            </a>
        </li>
    </ul>

    <h5 class="mb-3">User & System Management</h5>
    <ul class="nav nav-pills flex-column mb-4">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_users.php') ? 'active' : '' ?>" href="manage_users.php">
                <i class="bi bi-people"></i> Manage Users
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_departments.php') ? 'active' : '' ?>" href="manage_departments.php">
                <i class="bi bi-building-fill-gear"></i> Manage Departments
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_campuses.php') ? 'active' : '' ?>" href="manage_campuses.php">
                <i class="bi bi-geo-alt-fill"></i> Manage Campuses
            </a>
        </li>

        <!-- New Dropdown for Manage Types -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= is_any_page_active($manageTypesPages, $currentPage) ? 'active' : '' ?>" href="#" id="manageTypesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-journal-text"></i> Manage Types
            </a>
            <ul class="dropdown-menu" aria-labelledby="manageTypesDropdown">
                <li><a class="dropdown-item <?= ($currentPage === 'manage_publication_types.php') ? 'active' : '' ?>" href="manage_publication_types.php">
                    <i class="bi bi-file-earmark-text"></i> Publication Types
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_innovation_types.php') ? 'active' : '' ?>" href="manage_innovation_types.php">
                    <i class="bi bi-lightbulb"></i> Innovation Types
                </a></li>
            </ul>
        </li>

        <!-- New Dropdown for Manage Requirements -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= is_any_page_active($manageRequirementsPages, $currentPage) ? 'active' : '' ?>" href="#" id="manageRequirementsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-list-check"></i> Manage Requirements
            </a>
            <ul class="dropdown-menu" aria-labelledby="manageRequirementsDropdown">
                <li><a class="dropdown-item <?= ($currentPage === 'manage_requirements_master.php') ? 'active' : '' ?>" href="manage_requirements_master.php">
                    <i class="bi bi-card-checklist"></i> Master Requirements
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_pub_type_requirements.php') ? 'active' : '' ?>" href="manage_pub_type_requirements.php">
                    <i class="bi bi-link-45deg"></i> Publication Type Links
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_inno_type_requirements.php') ? 'active' : '' ?>" href="manage_inno_type_requirements.php">
                    <i class="bi bi-link-45deg"></i> Innovation Type Links
                </a></li>
            </ul>
        </li>
    </ul>

    <h5 class="mb-3">Manage Submissions</h5>
    <ul class="nav nav-pills flex-column">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'forwarded_to_pio') ? 'active' : '' ?>" href="manage_submissions.php?status=forwarded_to_pio">
                <i class="bi bi-inbox"></i> New Submissions (Facilitator)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'accepted_by_pio') ? 'active' : '' ?>" href="manage_submissions.php?status=accepted_by_pio">
                <i class="bi bi-check-circle"></i> Accepted by PIO
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'forwarded_to_external') ? 'active' : '' ?>" href="manage_submissions.php?status=forwarded_to_external">
                <i class="bi bi-send"></i> Forwarded to External
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'under_external_review') ? 'active' : '' ?>" href="manage_submissions.php?status=under_external_review">
                <i class="bi bi-eye"></i> Under External Review
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'approved') ? 'active' : '' ?>" href="manage_submissions.php?status=approved">
                <i class="bi bi-patch-check"></i> Approved Submissions
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_submissions.php' && $currentStatus === 'rejected') ? 'active' : '' ?>" href="manage_submissions.php?status=rejected">
                <i class="bi bi-x-circle"></i> Rejected Submissions
            </a>
        </li>
    </ul>
</div>
