<?php
// sidebar.php
// This file contains the sidebar navigation for the URDI Dashboard.
// It is designed to be included in other PHP files.

// Determine the current page to highlight the active link
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside id="sidebar" class="transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out
                          fixed inset-y-0 left-0 z-20 w-64 bg-white text-gray-800 flex flex-col shadow-lg
                          lg:relative lg:flex-shrink-0 lg:w-64 no-scrollbar overflow-y-auto">
    <!-- Sidebar Header/Logo -->
    <div class="flex items-center justify-center h-16 bg-gray-100 shadow-md">
        <span class="text-2xl font-bold text-blue-600">URDI</span>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 px-4 py-6 space-y-2">
        <a href="dashboard.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'external_office_dashboard.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-speedometer2 text-lg"></i>
            <span>Dashboard</span>
        </a>
        <a href="new_submissions.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'new_submissions.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-file-earmark-plus text-lg"></i>
            <span>Submissions <p>(from Publication & Innovation Office)</p></span>
        </a>
        <a href="accepted_submissions.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'accepted_submissions.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-check-circle text-lg"></i>
            <span>Accepted Submissions</span>
        </a>
        
         <a href="repository.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'approved_submissions.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-patch-check text-lg"></i>
            <span>Repository</span>
        </a>
        <a href="rejected_submissions.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'rejected_submissions.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-x-circle text-lg"></i>
            <span>Rejected Submissions</span>
        </a>
        <a href="settings.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200 <?= ($currentPage == 'settings.php') ? 'bg-blue-100 text-blue-700' : '' ?>">
            <i class="bi bi-gear text-lg"></i>
            <span>Settings</span>
        </a>
        <a href="../../auth/logout.php" class="flex items-center space-x-3 px-3 py-2 rounded-md text-gray-700 hover:bg-blue-100 hover:text-blue-700 transition-colors duration-200">
            <i class="bi bi-box-arrow-right text-lg"></i>
            <span>Logout</span>
        </a>
    </nav>

    <!-- Sidebar Footer (Optional) -->
    <div class="px-4 py-4 text-center text-gray-500 text-sm">
        &copy; 2025 URDI. All rights reserved.
    </div>
</aside>
