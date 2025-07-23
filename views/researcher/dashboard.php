<?php
// File: views/researcher/dashboard.php
session_start();
require_once '../../config/config.php';
require_once '../../config/connect.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Researcher Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"> 
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f5f8fc;
        }

        .card {
            border: none;
            border-radius: 1rem;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            background-color: #ffffff;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .card h5 {
            color: #0d6efd;
            font-weight: 600;
        }

        .card p {
            color: #6c757d;
            margin-bottom: 0;
        }

        .dashboard-wrapper {
            max-width: 1200px;
            margin: auto;
            padding: 40px 20px;
        }

        .section-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .section-header h4 {
            font-weight: 700;
            color: #333;
        }

        .steps-card {
            border-radius: 1rem;
            background-color: #fff;
            border: 1px solid #dee2e6;
            padding: 2rem;
            margin-top: 40px;
        }

        .list-group-item {
            border: none;
            padding-left: 0;
        }

        .pagination .page-link {
            border-radius: 0.5rem;
            margin: 0 3px;
        }

        @media (max-width: 576px) {
            .card {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>

<?php include '_navbar_researcher.php'; ?>

<div class="dashboard-wrapper">
    <div class="section-header">
        <h4><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Researcher Dashboard</h4>
        <p class="text-muted">Quick access to your research activities</p>
    </div>

    <!-- Card Buttons in a Horizontal Row -->
    <div class="row g-4 justify-content-center"> <!-- Reverted to row for horizontal layout -->
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4"> <!-- Adjusted column classes for responsive horizontal layout -->
            <a href="submit.php" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-upload fs-1 mb-2 text-primary"></i>
                    <h5>Submit Work</h5>
                    <p>Upload your Work here</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4">
            <a href="track.php" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-search fs-1 mb-2 text-primary"></i>
                    <h5>Track Submission</h5>
                    <p>Check your submission status</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4">
            <a href="my_submissions.php" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-folder-check fs-1 mb-2 text-primary"></i>
                    <h5>My Submissions</h5>
                    <p>View all your submissions</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4">
            <a href="researcher_repository.php" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-journal-check fs-1 mb-2 text-primary"></i>
                    <h5>Published/Implemented</h5>
                    <p>View your completed works</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4">
            <a href="https://drive.google.com/drive/folders/1rA0CddOebjYebE8Va7X2SQMGxYM62LZD?usp=sharing" target="_blank" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-box-arrow-up-right fs-1 mb-2 text-primary"></i>
                    <h5>Publication Forms</h5>
                    <p>Visit to View Publication Form</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-2-4">
            <a href="https://drive.google.com/drive/folders/1FH2WTSJ_yP7PPwnOKMQErirsCtFmM9Ir?usp=sharing" target="_blank" class="text-decoration-none">
                <div class="card p-4 text-center shadow-sm">
                    <i class="bi bi-box-arrow-up-right fs-1 mb-2 text-primary"></i>
                    <h5>Innovation Forms</h5>
                    <p>Visit to View Innovation Form</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Steps to Complete Submission -->
    <div class="steps-card mt-5 shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="text-primary"><i class="bi bi-list-check me-2"></i>Steps to Complete a Submission</h5>
        </div>

        <ul class="pagination justify-content-center mb-4" id="stepPagination">
            <li class="page-item active"><button class="page-link" onclick="showStep(1)">1</button></li>
            <li class="page-item"><button class="page-link" onclick="showStep(2)">2</button></li>
        </ul>

        <div id="stepGroup1">
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><i class="bi bi-1-circle-fill text-primary me-2"></i> Prepare your publication or innovation document (PDF, DOCX).</li>
                <li class="list-group-item"><i class="bi bi-2-circle-fill text-primary me-2"></i> Ensure all required documents and details are complete.</li>
                <li class="list-group-item"><i class="bi bi-3-circle-fill text-primary me-2"></i> Submit your work via the <strong>Submit Work</strong> section.</li>
            </ul>
        </div>

        <div id="stepGroup2" class="d-none">
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><i class="bi bi-4-circle-fill text-primary me-2"></i> Wait for facilitator review and PIO routing.</li>
                <li class="list-group-item"><i class="bi bi-5-circle-fill text-primary me-2"></i> Track your submission and check for comments or actions.</li>
                <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i> Receive final approval and completion notice.</li>
            </ul>
        </div>
    </div>
</div>

<script>
function showStep(step) {
    document.getElementById('stepGroup1').classList.add('d-none');
    document.getElementById('stepGroup2').classList.add('d-none');

    if (step === 1) {
        document.getElementById('stepGroup1').classList.remove('d-none');
    } else {
        document.getElementById('stepGroup2').classList.remove('d-none');
    }

    const pageItems = document.querySelectorAll("#stepPagination .page-item");
    pageItems.forEach((item, index) => {
        item.classList.toggle("active", index === (step - 1));
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
