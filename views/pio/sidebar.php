<?php
// File: views/pio/sidebar.php

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

// Define pages for 'Manage Organizational Structure' dropdown
$manageOrgStructurePages = [
    'manage_campuses.php',
    'manage_departments.php',
    'manage_colleges.php',
    'manage_programs.php',
    'manage_projects.php'
];

?>
<style>
/* Overall Body and Layout adjustments for dark theme */
body {
    background-color: #eef2f6; /* Light background for the main content area */
    display: flex; /* Use flexbox for main content + sidebar */
    min-height: 100vh;
    font-family: 'Inter', sans-serif;
    color: #e0e0e0; /* Default text color for dark theme */
}

.main-content-wrapper {
    display: flex;
    flex-grow: 1;
    margin-top: 0; /* Remove top margin as sidebar is full height */
    padding: 0; /* Remove padding from wrapper, handled by main-content */
    box-sizing: border-box;
}

/* Sidebar Container */
#sidebar {
    background-color: #212529; /* Dark background */
    color: #e0e0e0; /* Light text color */
    padding: 1rem;
    border-radius: 0 1rem 1rem 0; /* Rounded corners on right side */
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2);
    width: 300px; /* Increased default expanded width */
    flex-shrink: 0;
    transition: width 0.3s ease, padding 0.3s ease;
    overflow-y: auto; /* Changed from hidden to auto to allow scrolling */
    position: sticky;
    top: 0;
    height: 100vh; /* Make it full height */
    display: flex;
    flex-direction: column;
    z-index: 1000; /* Ensure sidebar is on top */
}

/* Collapsed Sidebar State */
#sidebar.collapsed {
    width: 80px; /* Collapsed width */
    padding: 1rem 0.5rem; /* Reduced padding when collapsed */
}

#sidebar.collapsed .sidebar-header .toggle-btn {
    transform: rotate(180deg); /* Rotate arrow when collapsed */
}

#sidebar.collapsed .sidebar-header .app-logo,
#sidebar.collapsed h5,
#sidebar.collapsed .nav-link span,
#sidebar.collapsed .dropdown-toggle::after {
    display: none; /* Hide text and dropdowns when collapsed */
}

#sidebar.collapsed .nav-link {
    justify-content: center; /* Center icons when collapsed */
    padding: 0.75rem 0.5rem; /* Adjust padding for icons */
}

#sidebar.collapsed .nav-link i {
    margin-right: 0; /* Remove margin from icons */
}

/* Sidebar Header (Logo and Toggle Button) */
.sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-header .app-logo {
    font-size: 1.8rem;
    font-weight: bold;
    color: #0d6efd; /* Accent color for logo */
}

.sidebar-header .toggle-btn {
    background: none;
    border: none;
    color: #e0e0e0;
    font-size: 1.5rem;
    cursor: pointer;
    transition: transform 0.3s ease;
    padding: 0.2rem;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sidebar-header .toggle-btn:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

/* Section Headings */
#sidebar h5 {
    font-size: 0.9rem; /* Reduced font size */
    font-weight: 700;
    color: #888; /* Lighter grey for headings */
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 1rem;
    margin-bottom: 0.6rem; /* Reduced margin */
    padding-bottom: 0.3rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    white-space: normal; /* Allow wrapping for titles */
    overflow: visible; /* Ensure content is visible */
    text-overflow: clip; /* Prevent ellipsis */
    line-height: 1.2; /* Adjust line height for wrapped text */
}

/* Navigation Links */
#sidebar .nav-link {
    color: #e0e0e0;
    font-size: 0.9rem; /* Reduced font size */
    font-weight: 500;
    padding: 0.6rem 0.75rem; /* Reduced padding */
    margin-bottom: 0.4rem; /* Reduced margin */
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    transition: background-color 0.2s, color 0.2s, box-shadow 0.2s, border-left 0.2s, padding-left 0.2s;
    white-space: nowrap; /* Prevent text wrapping for nav links */
    overflow: hidden;
    text-overflow: ellipsis;
    border-left: 4px solid transparent; /* Default transparent border */
}

#sidebar .nav-link i {
    font-size: 1rem;
    margin-right: 10px;
    color: #e0e0e0; /* Icon color matches text */
    transition: color 0.2s;
}

/* Hover and Active Styles */
#sidebar .nav-link:hover {
    background-color: rgba(13, 110, 253, 0.15); /* Light blue tint on hover */
    color: #ffffff;
}

#sidebar .nav-link.active {
    background-color: rgba(13, 110, 253, 0.25); /* Lighter blue tint for active */
    color: #ffffff;
    font-weight: 600;
    border-left: 4px solid #0d6efd; /* Distinct left border */
    padding-left: 0.75rem; /* Adjust padding to account for border */
    box-shadow: none; /* Remove box-shadow for a simpler look */
}

#sidebar .nav-link.active i {
    color: #ffffff;
}

/* Section Spacing */
#sidebar ul.nav {
    margin-bottom: 1.2rem; /* Reduced margin */
}

/* Dropdown specific styles */
.dropdown-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dropdown-toggle::after {
    margin-left: 0.5rem;
    vertical-align: middle;
    transition: transform 0.2s ease-in-out;
    color: #e0e0e0; /* Caret color */
}

.dropdown-toggle[aria-expanded="true"]::after {
    transform: rotate(180deg);
}

.dropdown-menu {
    background-color: #2c3034; /* Slightly lighter dark for dropdown */
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.3);
    border-radius: 0.5rem;
    padding: 0.3rem 0;
    margin-top: 0.3rem;
    width: 200px; /* Fixed width for dropdowns */
    position: absolute; /* Ensure it overlays content */
    z-index: 1001; /* Ensure it's on top of other elements */
    left: calc(100% + 10px); /* Position next to the sidebar */
    top: 0; /* Align with the toggle button */
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
}

.dropdown-menu.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}


.dropdown-item {
    padding: 0.5rem 1rem;
    color: #e0e0e0;
    transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out;
    font-size: 0.85rem; /* Further reduced font size for dropdown items */
    display: flex;
    align-items: center;
    white-space: nowrap; /* Prevent text wrapping */
    overflow: hidden;
    text-overflow: ellipsis;
}

.dropdown-item i {
    margin-right: 0.6rem;
    color: #e0e0e0;
}

.dropdown-item:hover {
    background-color: rgba(13, 110, 253, 0.25); /* More prominent hover for dropdown items */
    color: #ffffff;
}

.dropdown-item.active, .dropdown-item:active {
    background-color: #0d6efd;
    color: white;
}
.dropdown-item.active i, .dropdown-item:active i {
    color: white;
}

/* Main content area adjustment */
#main-content {
    flex-grow: 1;
    padding: 20px; /* Add padding to main content */
    background-color: #eef2f6; /* Light background for main content */
    color: #333; /* Dark text for main content */
    border-radius: 1rem;
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


/* Responsive Adjustments */
@media (max-width: 992px) { /* Adjust breakpoint as needed for tablet/mobile */
    #sidebar {
        width: 80px; /* Always collapsed on smaller screens */
        padding: 1rem 0.5rem;
        border-radius: 0; /* No rounded corners on mobile for full height */
        position: fixed; /* Fixed position for mobile */
        left: 0;
        top: 0;
        z-index: 1050; /* Ensure it's above other content */
        height: 100vh;
    }

    #sidebar .sidebar-header .app-logo,
    #sidebar h5,
    #sidebar .nav-link span,
    #sidebar .dropdown-toggle::after {
        display: none;
    }

    #sidebar .nav-link {
        justify-content: center;
        padding: 0.75rem 0.5rem;
    }

    #sidebar .nav-link i {
        margin-right: 0;
    }

    #sidebar .sidebar-header .toggle-btn {
        display: none; /* Hide toggle button on mobile as it's always collapsed */
    }

    /* Adjust dropdown menu positioning for collapsed state */
    #sidebar .dropdown-menu {
        position: fixed; /* Fixed position when sidebar is collapsed */
        left: 80px; /* Position next to the collapsed sidebar */
        top: auto; /* Let Bootstrap handle vertical positioning */
        width: 200px; /* Fixed width for dropdowns in collapsed state */
        display: none; /* Hidden by default */
        opacity: 1; /* Override opacity for fixed position */
        visibility: visible; /* Override visibility */
        transform: translateY(0); /* Override transform */
    }

    /* Show dropdown when its parent is clicked in collapsed state */
    #sidebar .nav-item.dropdown .dropdown-toggle[aria-expanded="true"] + .dropdown-menu {
        display: block;
    }

    /* Main content area adjustment for small screens */
    .main-content-wrapper {
        margin-left: 80px; /* Adjust main content to start after collapsed sidebar */
        width: calc(100% - 80px); /* Fill remaining width */
    }

    .top-navbar {
        border-radius: 0; /* No rounded corners on mobile */
    }
}

</style>

<!-- Sidebar HTML -->
<div id="sidebar">
    <div class="sidebar-header">
        <div class="app-logo">PubInno</div>
        <button type="button" class="toggle-btn" id="sidebarToggle">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>

    <h5>Dashboard</h5>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php">
                <i class="bi bi-house-door-fill"></i> <span>Dashboard Home</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'repository.php') ? 'active' : '' ?>" href="repository.php">
                <i class="bi bi-inbox-fill"></i> <span>Repository</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'track_submission_pio.php') ? 'active' : '' ?>" href="track_submission_pio.php">
                <i class="bi bi-graph-up"></i> <span>Track Submissions (PIO)</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'submit_research_pio.php') ? 'active' : '' ?>" href="submit_research_pio.php">
                <i class="bi bi-upload"></i> <span>Submit Research (PIO)</span>
            </a>
        </li>
    </ul>

    <h5>Submission Management</h5>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'new_submissions.php') ? 'active' : '' ?>" href="new_submissions.php">
                <i class="bi bi-file-earmark-plus-fill"></i> <span>New Submissions (Facilitator)</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'accepted_by_pio_submissions.php') ? 'active' : '' ?>" href="accepted_by_pio_submissions.php">
                <i class="bi bi-check-circle-fill"></i> <span>Accepted by PIO</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'under_external_review_submissions.php') ? 'active' : '' ?>" href="under_external_review_submissions.php">
                <i class="bi bi-eye-fill"></i> <span>Under External Review</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'rejected_submissions.php') ? 'active' : '' ?>" href="rejected_submissions.php">
                <i class="bi bi-x-circle-fill"></i> <span>Rejected Submissions</span>
            </a>
        </li>
    </ul>

    <h5>User & System Tools</h5>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a class="nav-link <?= ($currentPage === 'manage_users.php') ? 'active' : '' ?>" href="manage_users.php">
                <i class="bi bi-people-fill"></i> <span>Manage Users</span>
            </a>
        </li>
       
        <!-- New Dropdown for Manage Organizational Structure -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= is_any_page_active($manageOrgStructurePages, $currentPage) ? 'active' : '' ?>" href="#" id="manageOrgStructureDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-diagram-3-fill"></i> <span>Org. Structure</span>
            </a>
            <ul class="dropdown-menu" aria-labelledby="manageOrgStructureDropdown">
                <li><a class="dropdown-item <?= ($currentPage === 'manage_campuses.php') ? 'active' : '' ?>" href="manage_campuses.php">
                    <i class="bi bi-geo-alt-fill"></i> <span>Campuses</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_colleges.php') ? 'active' : '' ?>" href="manage_colleges.php">
                    <i class="bi bi-award-fill"></i> <span>Colleges</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_departments.php') ? 'active' : '' ?>" href="manage_departments.php">
                    <i class="bi bi-building-fill-gear"></i> <span>Departments</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_programs.php') ? 'active' : '' ?>" href="manage_programs.php">
                    <i class="bi bi-journals"></i> <span>Programs</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_projects.php') ? 'active' : '' ?>" href="manage_projects.php">
                    <i class="bi bi-folder-fill"></i> <span>Projects</span>
                </a></li>
            </ul>
        </li>

        <!-- Existing Dropdown for Manage Types -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= is_any_page_active($manageTypesPages, $currentPage) ? 'active' : '' ?>" href="#" id="manageTypesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-journal-text"></i> <span>Manage Types</span>
            </a>
            <ul class="dropdown-menu" aria-labelledby="manageTypesDropdown">
                <li><a class="dropdown-item <?= ($currentPage === 'manage_publication_types.php') ? 'active' : '' ?>" href="manage_publication_types.php">
                    <i class="bi bi-file-earmark-text"></i> <span>Publication Types</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_innovation_types.php') ? 'active' : '' ?>" href="manage_innovation_types.php">
                    <i class="bi bi-lightbulb"></i> <span>Innovation Types</span>
                </a></li>
            </ul>
        </li>

        <!-- Existing Dropdown for Manage Requirements -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= is_any_page_active($manageRequirementsPages, $currentPage) ? 'active' : '' ?>" href="#" id="manageRequirementsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-list-check"></i> <span>Manage Requirements</span>
            </a>
            <ul class="dropdown-menu" aria-labelledby="manageRequirementsDropdown">
                <li><a class="dropdown-item <?= ($currentPage === 'manage_requirements_master.php') ? 'active' : '' ?>" href="manage_requirements_master.php">
                    <i class="bi bi-card-checklist"></i> <span>Master Requirements</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_pub_type_requirements.php') ? 'active' : '' ?>" href="manage_pub_type_requirements.php">
                    <i class="bi bi-link-45deg"></i> <span>Publication Type Links</span>
                </a></li>
                <li><a class="dropdown-item <?= ($currentPage === 'manage_inno_type_requirements.php') ? 'active' : '' ?>" href="manage_inno_type_requirements.php">
                    <i class="bi bi-link-45deg"></i> <span>Innovation Type Links</span>
                </a></li>
            </ul>
        </li>
    </ul>

    <ul class="nav nav-pills flex-column mt-auto"> <!-- Push to bottom -->
        <li class="nav-item">
            <a class="nav-link" href="profile.php"> <!-- Updated href to profile.php -->
                <i class="bi bi-person-circle"></i> <span>Profile</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="../../auth/logout.php">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        // Function to toggle sidebar collapse
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
        }

        // Event listener for the toggle button
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        // Handle dropdown active state and collapse on small screens
        const dropdownToggles = document.querySelectorAll('.sidebar .dropdown-toggle');
        dropdownToggles.forEach(toggle => {
            toggle.addEventListener('click', function(event) {
                // Only prevent default if sidebar is collapsed, to allow normal dropdown on desktop
                if (sidebar.classList.contains('collapsed')) {
                    event.preventDefault(); // Prevent Bootstrap's default dropdown toggle
                    const dropdownMenu = this.nextElementSibling;
                    // Toggle visibility of the dropdown menu
                    if (dropdownMenu.style.display === 'block') {
                        dropdownMenu.style.display = 'none';
                    } else {
                        // Hide other open dropdowns
                        document.querySelectorAll('.sidebar .dropdown-menu').forEach(menu => {
                            if (menu !== dropdownMenu) {
                                menu.style.display = 'none';
                            }
                        });
                        dropdownMenu.style.display = 'block';
                    }
                }
            });
        });

        // Close dropdowns when clicking outside (only for collapsed state)
        document.addEventListener('click', function(event) {
            if (sidebar.classList.contains('collapsed') && !sidebar.contains(event.target)) {
                document.querySelectorAll('.sidebar .dropdown-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });

        // Initial check for screen size to set collapsed state
        function setInitialSidebarState() {
            if (window.innerWidth <= 992) { // Adjust breakpoint as per your CSS media query
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }

        // Set initial state on load
        setInitialSidebarState();

        // Update state on window resize
        window.addEventListener('resize', setInitialSidebarState);

        // Ensure active dropdowns are open on page load if not collapsed
        const activeDropdownToggles = document.querySelectorAll('.nav-item.dropdown .nav-link.active');
        activeDropdownToggles.forEach(toggle => {
            if (!sidebar.classList.contains('collapsed')) {
                const dropdownMenu = toggle.nextElementSibling;
                if (dropdownMenu) {
                    toggle.setAttribute('aria-expanded', 'true');
                    // For Bootstrap 5, you might need to manually trigger the show class or use JS to open it
                    // For simplicity, we'll just ensure it's visually open if it's the active parent
                    // If you use Bootstrap's JS for dropdowns, it will handle this if the 'active' class is on the toggle.
                    // The current CSS handles the rotation, but Bootstrap's JS handles the display.
                    // If issues, ensure Bootstrap's JS is loaded *after* your custom script or use its methods.
                }
            }
        });
    });
</script>
