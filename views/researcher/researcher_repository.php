<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php'; // Ensure this establishes $conn

// Check if user is logged in and is a researcher
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

$researcher_user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$user_name = htmlspecialchars($_SESSION['name'] ?? 'Researcher');

$submissions_data = [];
$message = '';
$messageType = '';

// Get search and filter parameters
$search_query = $_GET['search'] ?? '';
$incentives_filter = $_GET['incentives_filter'] ?? 'all'; // New filter parameter

// Fetch only approved submissions for the logged-in researcher
$sql = "SELECT
            s.submission_id,
            s.title,
            s.submission_type,
            s.submission_date,
            u.name AS author_name,
            sr.created_at AS review_date,
            sr.publisher,
            sr.indexing_body,
            sr.incentives_amount,
            sr.evidence_link
        FROM
            submissions s
        JOIN
            users u ON s.researcher_id = u.user_id
        LEFT JOIN
            submission_reviews sr ON s.submission_id = sr.submission_id
        WHERE
            s.researcher_id = ? AND s.status = 'approved'"; // Filter by researcher_id and approved status

$params = [$researcher_user_id];
$types = "i";

// Add search query filter
if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $sql .= " AND (s.reference_number LIKE ? OR s.title LIKE ? OR sr.publisher LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Add incentives filter
if ($incentives_filter === 'with_incentives') {
    $sql .= " AND sr.incentives_amount IS NOT NULL AND sr.incentives_amount > 0";
} elseif ($incentives_filter === 'no_incentives') {
    $sql .= " AND (sr.incentives_amount IS NULL OR sr.incentives_amount = 0)";
}

$sql .= " ORDER BY s.submission_date DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    // Dynamically bind parameters
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $submissions_data[] = $row;
    }
    $stmt->close();
} else {
    $message = 'Database error fetching repository data: ' . $conn->error;
    $messageType = 'danger';
    error_log("Researcher Repository SQL Prepare Error: " . $conn->error);
}

// IMPORTANT: Do NOT close the connection here. It must be closed at the very end of the file
// to allow included files (like the navbar) to use it.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Published/Implemented Submissions</title>
    <!-- Bootstrap CSS for styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons for icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        /* Custom styles to apply the Segoe UI font and background color for consistency with my_submissions.php */
        body {
            font-family: "Segoe UI", sans-serif;
            background-color: #f5f7fb; /* Light gray background consistent with my_submissions.php */
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
        /* Status badge colors */
        .status-badge.status-approved { background-color: #28a745; color: #fff; } /* Success */
        /* Using a distinct color for 'Published/Implemented' in this view */
        .status-badge.status-published-implemented { background-color: #6f42c1; color: #fff; } /* Purple */

        /* Main content wrapper styling */
        .main-content-wrapper {
            max-width: 1200px;
            margin: 60px auto; /* Adjust margin for spacing from navbar */
            padding: 30px;
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.05);
        }

        /* Search form specific styling */
        .search-form-container {
            background-color: #f8f9fa; /* Light background for the form */
            padding: 20px;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }
        .search-form-container .input-group-text {
            background-color: #e9ecef;
            border-color: #ced4da;
            color: #495057;
        }
        .search-form-container .form-control:focus {
            border-color: #80bdff;
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
        }
        .search-form-container .btn {
            border-radius: 0.25rem;
        }

        /* Table header styling */
        .table thead {
            background-color: #0d6efd;
            color: #ffffff;
        }
        .table th, .table td {
            vertical-align: middle;
            font-size: 0.95rem;
        }
        .table tbody tr:hover {
            background-color: #f0f4ff;
        }
        .no-data {
            background-color: #eef2f7;
            padding: 2rem;
            border-radius: 0.5rem;
            text-align: center;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <?php include '_navbar_researcher.php'; // Include the researcher navbar at the top of the body ?>

    <div class="main-content-wrapper">
        <h1 class="h4 font-weight-bold text-dark mb-4"><i class="bi bi-journal-check me-2 text-primary"></i>My Published/Implemented Submissions</h1>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'danger' ? 'danger' : 'info') ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="bg-white p-4 rounded-xl shadow-md mt-4">
            <h5 class="h6 text-gray-700 mb-4">List of My Approved Submissions</h5>
            
            <!-- Search and Filter Form -->
            <form method="GET" class="search-form-container d-flex flex-column flex-md-row align-items-md-center gap-3">
                <div class="input-group flex-grow-1">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" placeholder="Search by Title, Ref. No., Publisher..."
                           class="form-control"
                           value="<?= htmlspecialchars($search_query) ?>">
                </div>
                
                <div class="flex-shrink-0">
                    <select name="incentives_filter" class="form-select">
                        <option value="all" <?= $incentives_filter === 'all' ? 'selected' : '' ?>>All Incentives</option>
                        <option value="with_incentives" <?= $incentives_filter === 'with_incentives' ? 'selected' : '' ?>>With Incentives</option>
                        <option value="no_incentives" <?= $incentives_filter === 'no_incentives' ? 'selected' : '' ?>>No Incentives</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary flex-shrink-0">
                    Apply Filters
                </button>
                <?php if (!empty($search_query) || $incentives_filter !== 'all'): ?>
                    <a href="researcher_repository.php" class="btn btn-outline-secondary flex-shrink-0">
                        Reset Filters
                    </a>
                <?php endif; ?>
            </form>

            <?php if (empty($submissions_data)): ?>
                <div class="no-data">
                    <i class="bi bi-info-circle-fill fs-3 text-muted mb-2"></i>
                    <p class="mb-0">No published or implemented submissions found for your account matching the criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive mt-4">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Title</th>
                                <th scope="col">Authors</th>
                                <th scope="col">Date Published/Implemented</th>
                                <th scope="col">Publisher/Innovator</th>
                                <th scope="col">Indexing/Certification Body</th>
                                <th scope="col">Incentives</th>
                                <th scope="col">Status</th>
                                <th scope="col">Link for Evidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions_data as $submission): ?>
                                <tr>
                                    <td><?= htmlspecialchars($submission['title']) ?></td>
                                    <td><?= htmlspecialchars($submission['author_name']) ?></td>
                                    <td>
                                        <?php
                                        // Display review_date (which is when it was approved/published)
                                        echo date('M d, Y', strtotime($submission['review_date']));
                                        ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($submission['publisher'] ?? 'N/A') ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($submission['submission_type'] === 'innovation') {
                                            echo 'Certification Body: ' . htmlspecialchars($submission['indexing_body'] ?? 'N/A');
                                        } else {
                                            echo 'Indexing Body: ' . htmlspecialchars($submission['indexing_body'] ?? 'N/A');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?= !empty($submission['incentives_amount']) ? '$' . number_format($submission['incentives_amount'], 2) : 'N/A' ?>
                                    </td>
                                    <td>
                                        <span class="badge status-badge status-published-implemented">
                                            Published/Implemented
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($submission['evidence_link'])): ?>
                                            <a href="<?= htmlspecialchars($submission['evidence_link']) ?>" target="_blank" class="text-primary text-decoration-underline">View Evidence</a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); // Close the database connection at the very end ?>
