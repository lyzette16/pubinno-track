<?php
session_start();
if (isset($_SESSION['role'])) {
    header("Location: ../views/{$_SESSION['role']}/dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PubInno Track</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
        }
        /* Custom styling for the eye icon button */
        .input-group .absolute {
            right: 0;
            padding-right: 0.75rem; /* pr-3 */
            height: 100%; /* Make it fill the height of the input */
            display: flex;
            align-items: center;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-gray-100 flex flex-col items-center justify-center min-h-screen p-4">
    <div class="login-container flex flex-col-reverse lg:flex-row items-center lg:items-center justify-center bg-white rounded-xl shadow-lg max-w-6xl w-full p-8 lg:p-12 gap-10">
        <!-- Login Form Section (Left Side) -->
        <div class="login-card w-full lg:w-1/2 p-6 lg:p-8 bg-white rounded-lg">
            <h2 class="text-green-600 text-3xl font-bold mb-6 text-center lg:text-left">PUBINNO-TRACK</h2>
            <h3 class="text-xl font-semibold text-gray-800 mb-6 text-center lg:text-left">Welcome back!</h3>
            <p class="text-gray-600 mb-6 text-center lg:text-left">Login to access your account.</p>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md relative mb-4" role="alert">
                    <?= htmlspecialchars($_GET['error']) ?>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" onclick="this.parentElement.style.display='none';">
                        <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
                    </span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md relative mb-4" role="alert">
                    <?= htmlspecialchars($_GET['success']) ?>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" onclick="this.parentElement.style.display='none';">
                        <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
                    </span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login_process.php" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="email" class="block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" required autofocus>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" class="block w-full pr-10 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" required>
                        <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 text-gray-500" id="togglePassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    LOGIN
                </button>
            </form>

            <p class="text-center text-sm mt-4">
                Forgot Password? <a href="#" class="text-blue-600 hover:underline">Reset Here</a>
            </p>
        </div>

        <!-- Logo Section (Right Side) -->
        <div class="logo-section text-center lg:w-1/2 p-4">
            <img src="../images/dmmmsu_logo.png" alt="DMMMSU Logo" class="max-w-xs lg:max-w-full mx-auto mb-4">
            <p class="text-xl text-gray-700 font-semibold italic">"From thy portals, We learn"</p>
        </div>
    </div>

    <!-- Footer Section -->
    <div class="w-full text-center text-gray-600 text-sm mt-8">
        <p>Capstone project CIS NLUC | Version 2.7.0</p>
        <p>2025-2026 DMMMSU All rights reserved</p>
    </div>

    <!-- Removed Bootstrap JS as it's not needed with Tailwind, keeping only for the eye icon script -->
    <script>
        document.getElementById('togglePassword').addEventListener('click', function () {
            const passwordField = document.getElementById('password');
            const icon = this.querySelector('i');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    </script>
</body>
</html>
