<?php
// header.php
// This file contains the header section for the URDI Dashboard.
// It is designed to be included in other PHP files.
?>
<header class="bg-white shadow-sm py-4 px-6 flex items-center justify-between sticky top-0 z-10">
    <!-- Mobile menu button -->
    <button id="menu-toggle" class="lg:hidden p-2 rounded-md text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
    </button>

    <!-- Header Title -->
    <h2 class="text-2xl font-bold text-gray-800 hidden lg:block">Pubino Track</h2>

    <!-- Desktop Header Content (User Profile, Notifications) -->
    <div class="flex items-center space-x-4 ml-auto">
        <!-- Notifications Icon -->
        <button class="p-2 rounded-full text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
        </button>

        <!-- User Profile Dropdown (Placeholder) -->
        <div class="relative">
            <button class="flex items-center space-x-2 focus:outline-none">
                <img class="h-9 w-9 rounded-full object-cover" src="https://placehold.co/100x100/A78BFA/ffffff?text=JD" alt="User Avatar">
                <span class="font-medium text-gray-700 hidden sm:block">John Doe</span>
                <svg class="w-4 h-4 text-gray-500 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </button>
            <!-- Dropdown content would go here -->
        </div>
    </div>
</header>
