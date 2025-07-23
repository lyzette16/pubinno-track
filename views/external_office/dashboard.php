<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php';

// Check if user is logged in and is an external_office
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'external_office') {
    header("Location: ../../auth/login.php");
    exit();
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$user_name = htmlspecialchars($_SESSION['name'] ?? 'External User');
$external_office_campus_id = $_SESSION['campus_id'] ?? null;
$external_office_department_id = $_SESSION['department_id'] ?? null;

$message = '';
$messageType = '';

// Check for and display session messages (e.g., after a redirect)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

$dashboard_stats = [
    'total_submissions' => 0,
    'under_external_review' => 0,
    'accepted_by_external' => 0,
    'approved' => 0,
    'rejected' => 0
];

$submissions_per_year = []; // New array to hold yearly submission data

if (!$external_office_campus_id) {
    $message = 'Your External Office account is not associated with a campus. Please contact support.';
    $messageType = 'danger';
} else {
    // Base query for counts
    $base_count_sql = "SELECT COUNT(*) AS count FROM submissions WHERE campus_id = ?";
    $base_params = [$external_office_campus_id];
    $base_types = "i";

    // Add department filter if applicable
    if ($external_office_department_id) {
        $base_count_sql .= " AND department_id = ?";
        $base_params[] = $external_office_department_id;
        $base_types .= "i";
    }

    // Function to get count for a specific status
    function get_status_count($conn, $sql_template, $params, $types, $status = null) {
        $current_sql = $sql_template;
        $current_params = $params;
        $current_types = $types;

        if ($status) {
            $current_sql .= " AND status = ?";
            $current_params[] = $status;
            $current_types .= "s";
        }

        $stmt = $conn->prepare($current_sql);
        if ($stmt) {
            $stmt->bind_param($current_types, ...$current_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['count'];
        } else {
            error_log("Dashboard Count SQL Prepare Error: " . $conn->error);
            return 0;
        }
    }

    // Fetch counts for stat cards
    $dashboard_stats['total_submissions'] = get_status_count($conn, $base_count_sql, $base_params, $base_types);
    $dashboard_stats['under_external_review'] = get_status_count($conn, $base_count_sql, $base_params, $base_types, 'under_external_review');
    $dashboard_stats['accepted_by_external'] = get_status_count($conn, $base_count_sql, $base_params, $base_types, 'accepted_by_external');
    $dashboard_stats['approved'] = get_status_count($conn, $base_count_sql, $base_params, $base_types, 'approved');
    $dashboard_stats['rejected'] = get_status_count($conn, $base_count_sql, $base_params, $base_types, 'rejected');

    // Fetch submissions per year for the line chart
    $yearly_sql = "SELECT
                        YEAR(submission_date) AS submission_year,
                        COUNT(*) AS count
                    FROM
                        submissions
                    WHERE
                        campus_id = ?";
    $yearly_params = [$external_office_campus_id];
    $yearly_types = "i";

    if ($external_office_department_id) {
        $yearly_sql .= " AND department_id = ?";
        $yearly_params[] = $external_office_department_id;
        $yearly_types .= "i";
    }

    $yearly_sql .= " GROUP BY submission_year ORDER BY submission_year ASC";

    $stmt_yearly = $conn->prepare($yearly_sql);
    if ($stmt_yearly) {
        $stmt_yearly->bind_param($yearly_types, ...$yearly_params);
        $stmt_yearly->execute();
        $result_yearly = $stmt_yearly->get_result();
        while ($row = $result_yearly->fetch_assoc()) {
            $submissions_per_year[] = $row;
        }
        $stmt_yearly->close();
    } else {
        error_log("Dashboard Yearly Count SQL Prepare Error: " . $conn->error);
    }
}

$conn->close();

// Variables for sidebar active state (used in sidebar.php)
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Office Dashboard</title>
    <!-- Tailwind CSS for styling -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Chart.js for data visualization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons for icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        /* Custom styles to apply the Inter font */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc; /* A light gray background */
        }
        /* Hide scrollbar for webkit browsers */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php include 'header.php'; // Include the header ?>

    <div class="flex flex-1 overflow-hidden">
        <?php include 'sidebar.php'; // Include the sidebar ?>

        <!-- Main Content -->
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">External Office Dashboard</h1>

            <?php if (!empty($message)): ?>
                <div class="p-4 mb-4 text-sm rounded-md
                    <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : '' ?>
                    <?= $messageType === 'danger' ? 'bg-red-100 text-red-700' : '' ?>
                    <?= $messageType === 'info' ? 'bg-blue-100 text-blue-700' : '' ?>" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="float-right text-lg font-semibold leading-none" onclick="this.parentElement.style.display='none';">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 mb-6">
                <!-- Total Submissions Card -->
                <div class="bg-white p-6 rounded-xl shadow-md flex items-center space-x-4">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="bi bi-file-earmark-text text-blue-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Total Submissions</p>
                        <p class="text-3xl font-bold text-gray-800"><?= $dashboard_stats['total_submissions'] ?></p>
                    </div>
                </div>
                <!-- Under External Review Card -->
                <div class="bg-white p-6 rounded-xl shadow-md flex items-center space-x-4">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="bi bi-hourglass-split text-yellow-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Under External Review</p>
                        <p class="text-3xl font-bold text-gray-800"><?= $dashboard_stats['under_external_review'] ?></p>
                    </div>
                </div>
                <!-- Accepted by External Card -->
                <div class="bg-white p-6 rounded-xl shadow-md flex items-center space-x-4">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="bi bi-check-circle text-green-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Accepted by You</p>
                        <p class="text-3xl font-bold text-gray-800"><?= $dashboard_stats['accepted_by_external'] ?></p>
                    </div>
                </div>
                <!-- Approved Submissions Card -->
                <div class="bg-white p-6 rounded-xl shadow-md flex items-center space-x-4">
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i class="bi bi-patch-check text-purple-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Approved Submissions</p>
                        <p class="text-3xl font-bold text-gray-800"><?= $dashboard_stats['approved'] ?></p>
                    </div>
                </div>
                <!-- Rejected Submissions Card -->
                <div class="bg-white p-6 rounded-xl shadow-md flex items-center space-x-4">
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="bi bi-x-circle text-red-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Rejected Submissions</p>
                        <p class="text-3xl font-bold text-gray-800"><?= $dashboard_stats['rejected'] ?></p>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Line Chart for Submissions Per Year -->
                <div class="bg-white p-6 rounded-xl shadow-md h-[350px]"> <!-- Added fixed height -->
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Submissions Per Year</h3>
                    <canvas id="submissionsPerYearChart"></canvas>
                </div>
                <!-- Bar Chart Card -->
                <div class="bg-white p-6 rounded-xl shadow-md h-[350px]"> <!-- Added fixed height -->
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Your Submission Status Comparison</h3>
                    <canvas id="comparisonBarChart"></canvas>
                </div>
            </div>
        </main>
    </div>

    <script>
        // --- Dynamic data from PHP ---
        const dashboardStats = <?= json_encode($dashboard_stats) ?>;
        const submissionsPerYearData = <?= json_encode($submissions_per_year) ?>;

        // Prepare data for the line chart
        const years = submissionsPerYearData.map(item => item.submission_year);
        const counts = submissionsPerYearData.map(item => item.count);

        // 1. Line Chart for Submissions Per Year
        const lineCtx = document.getElementById('submissionsPerYearChart').getContext('2d');
        const submissionsPerYearChart = new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: years,
                datasets: [{
                    label: 'Number of Submissions',
                    data: counts,
                    borderColor: '#3B82F6', // Blue-500
                    backgroundColor: 'rgba(59, 130, 246, 0.2)', // Blue-500 with opacity
                    tension: 0.4, // Smooth line
                    fill: true,
                    pointBackgroundColor: '#3B82F6',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: '#3B82F6',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Allow charts to fill container height
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Submissions'
                        },
                        grid: {
                           color: '#e5e7eb' // Gray-200
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Year'
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false // Hide legend for single dataset
                    },
                    title: {
                        display: false,
                        text: 'Submissions Per Year'
                    }
                }
            }
        });

        // 2. Bar Chart for Status Comparison (remains the same)
        const barCtx = document.getElementById('comparisonBarChart').getContext('2d');
        const comparisonBarChart = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Under Review', 'Accepted by You', 'Approved', 'Rejected'],
                datasets: [{
                    label: 'Number of Submissions',
                    data: [
                        dashboardStats.under_external_review,
                        dashboardStats.accepted_by_external,
                        dashboardStats.approved,
                        dashboardStats.rejected
                    ],
                    backgroundColor: [
                        'rgba(252, 211, 77, 0.6)',  // Yellow-300 with opacity
                        'rgba(52, 211, 153, 0.6)',  // Green-300 with opacity
                        'rgba(167, 139, 250, 0.6)', // Purple-400 with opacity
                        'rgba(248, 113, 113, 0.6)'  // Red-400 with opacity
                    ],
                    borderColor: [
                        '#FCD34D',
                        '#34D399',
                        '#A78BFA',
                        '#F87171'
                    ],
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Allow charts to fill container height
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                           color: '#e5e7eb' // Gray-200
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // --- Sidebar Toggle Logic (MUST be included in every page using the sidebar) ---
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('-translate-x-full');
            });

            document.addEventListener('click', (event) => {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMenuToggle = menuToggle.contains(event.target);
                const isSidebarVisible = !sidebar.classList.contains('-translate-x-full');

                if (!isClickInsideSidebar && !isClickOnMenuToggle && isSidebarVisible && window.innerWidth < 1024) {
                    sidebar.classList.add('-translate-x-full');
                }
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) { // Tailwind's 'lg' breakpoint
                    sidebar.classList.remove('-translate-x-full'); // Ensure sidebar is visible on desktop
                } else {
                    sidebar.classList.add('-translate-x-full'); // Ensure sidebar is hidden on mobile by default
                }
            });

            window.onload = () => {
                if (window.innerWidth < 1024) {
                    sidebar.classList.add('-translate-x-full');
                }
            };
        }
    </script>
</body>
</html>
