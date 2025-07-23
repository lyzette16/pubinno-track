<?php
// File: views/pio/submit_research_pio.php

// Conditionally start the session to prevent "session_start(): Ignoring session_start()" notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a PIO
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer';
$pio_campus_id = $_SESSION['campus_id'] ?? null;
$successMessage = "";
$errorMessage = "";

// Check if PIO has a campus_id set
if (!$pio_campus_id) {
    $errorMessage = 'Your PIO account is not associated with a campus. Cannot submit research.';
    // No exit here, as we still want to display the page, just with an error.
}

// Fetch all departments associated with the PIO's campus
$departments = [];
if ($pio_campus_id) {
    $stmt_departments = $conn->prepare("SELECT department_id, name FROM departments WHERE campus_id = ? ORDER BY name ASC");
    if ($stmt_departments) {
        $stmt_departments->bind_param("i", $pio_campus_id);
        $stmt_departments->execute();
        $result_departments = $stmt_departments->get_result();
        while ($row = $result_departments->fetch_assoc()) {
            $departments[] = $row;
        }
        $stmt_departments->close();
    } else {
        error_log("Failed to fetch departments: " . $conn->error);
        $errorMessage = "Database error fetching departments.";
    }
}

// Fetch ALL Publication Types for the dropdowns
$publicationTypes = [];
$stmt_pub_types = $conn->prepare("SELECT pub_type_id AS id, type_name AS name, submission_category FROM publication_types ORDER BY type_name");
if ($stmt_pub_types) {
    $stmt_pub_types->execute();
    $result_pub_types = $stmt_pub_types->get_result();
    while ($row = $result_pub_types->fetch_assoc()) {
        $publicationTypes[] = $row;
    }
    $stmt_pub_types->close();
} else {
    error_log("Error fetching publication types: " . $conn->error);
    $errorMessage = "Database error fetching publication types.";
}

// Fetch ALL Innovation Types for the dropdowns
$innovationTypes = [];
$stmt_inno_types = $conn->prepare("SELECT inno_type_id AS id, type_name AS name FROM innovation_types ORDER BY type_name");
if ($stmt_inno_types) {
    $stmt_inno_types->execute();
    $result_inno_types = $stmt_inno_types->get_result();
    while ($row = $result_inno_types->fetch_assoc()) {
        $innovationTypes[] = $row;
    }
    $stmt_inno_types->close();
} else {
    error_log("Error fetching innovation types: " . $conn->error);
    $errorMessage = "Database error fetching innovation types.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DEBUG: Log all POST and FILES data received
    error_log("--- NEW PIO SUBMISSION ATTEMPT ---");
    error_log("POST Data: " . print_r($_POST, true));
    error_log("FILES Data: " . print_r($_FILES, true));

    $conn->begin_transaction(); // Start transaction for atomicity
    error_log("Transaction started for PIO submission.");

    try {
        $selected_department_id = filter_var($_POST['department_id'] ?? '', FILTER_VALIDATE_INT);
        $main_researcher_id = filter_var($_POST['main_researcher_id'] ?? '', FILTER_VALIDATE_INT);
        $title = trim($_POST['title'] ?? '');
        $abstract = trim($_POST['abstract'] ?? '');
        $submission_category = trim($_POST['submission_category'] ?? ''); // Get selected category
        $submission_type_id = filter_var($_POST['submission_type_id'] ?? '', FILTER_VALIDATE_INT); // This is either pub_type_id or inno_type_id
        
        // Basic validation
        if (empty($selected_department_id) || empty($main_researcher_id) || empty($title) || empty($abstract) || empty($submission_category) || !$submission_type_id || !$pio_campus_id) {
            throw new Exception("Missing required form data or PIO's session details (Campus ID).");
        }

        // Validate main researcher belongs to the selected department and PIO's campus
        $stmt_validate_researcher = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'researcher' AND department_id = ? AND campus_id = ?");
        if (!$stmt_validate_researcher) {
            throw new Exception("Failed to prepare researcher validation statement: " . $conn->error);
        }
        $stmt_validate_researcher->bind_param("iii", $main_researcher_id, $selected_department_id, $pio_campus_id);
        $stmt_validate_researcher->execute();
        $result_validate_researcher = $stmt_validate_researcher->get_result();
        if ($result_validate_researcher->num_rows === 0) {
            throw new Exception("Selected researcher is invalid or does not belong to the chosen department/campus.");
        }
        $stmt_validate_researcher->close();

        // --- Handle main article file upload ---
        $main_file_path = null;
        if (isset($_FILES['article_file']) && $_FILES['article_file']['error'] === UPLOAD_ERR_OK) {
            $file_data = $_FILES['article_file'];
            $stored_file_name = time() . '_' . uniqid() . '_' . basename($file_data['name']);
            $uploadPath = '../../uploads/' . $stored_file_name;

            if (!is_dir('../../uploads')) {
                mkdir('../../uploads', 0777, true);
                error_log("Created uploads directory: ../../uploads");
            }

            if (!move_uploaded_file($file_data['tmp_name'], $uploadPath)) {
                error_log("Failed to move uploaded file. Temp name: " . $file_data['tmp_name'] . ", Destination: " . $uploadPath);
                throw new Exception("Failed to move the main article file. Check permissions for ../../uploads.");
            }
            $main_file_path = $uploadPath; // Store the path for the 'submissions' table
        } else if (isset($_FILES['article_file']) && $_FILES['article_file']['error'] !== UPLOAD_ERR_NO_FILE) {
             throw new Exception("Error uploading main article file: " . $_FILES['article_file']['error']);
        } else {
            throw new Exception("Main article file is required.");
        }

        $refNumber = uniqid('SUB'); // Generate unique reference number
        $status = 'submitted'; // Changed initial status to 'submitted'
        error_log("Attempting to set submission status to: " . $status); // DEBUG: Log the status being set

        // Determine which ID to use based on category
        $pub_type_id_for_db = null;
        $inno_type_id_for_db = null;

        if ($submission_category === 'publication') {
            $pub_type_id_for_db = $submission_type_id;
        } elseif ($submission_category === 'innovation') {
            $inno_type_id_for_db = $submission_type_id;
        } else {
            throw new Exception("Invalid submission category provided.");
        }

        // Insert main submission details into the 'submissions' table
        // Updated to include inno_type_id and conditionally set pub_type_id or inno_type_id
        $stmt = $conn->prepare("INSERT INTO submissions 
            (reference_number, title, abstract, submission_type, file_path, researcher_id, department_id, campus_id, status, submission_date, pub_type_id, inno_type_id, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare submission statement failed: " . $conn->error);
            throw new Exception("Prepare submission statement failed: " . $conn->error);
        }
        
        // Bind parameters for main submission
        // s (ref), s (title), s (abstract), s (submission_type), s (file_path), i (researcher_id), i (department_id), i (campus_id), s (status), i (pub_type_id), i (inno_type_id), s (description)
        $stmt->bind_param("sssssiiisiis", 
            $refNumber, $title, $abstract, $submission_category, $main_file_path, 
            $main_researcher_id, 
            $selected_department_id, $pio_campus_id, $status, 
            $pub_type_id_for_db, $inno_type_id_for_db, $abstract // Using abstract for description as well
        );
        
        if (!$stmt->execute()) {
            error_log("Execute submission statement failed: " . $stmt->error);
            throw new Exception("Execute submission statement failed: " . $stmt->error);
        }
        error_log("Submission main data inserted successfully.");

        $submission_id = $conn->insert_id; // Get the ID of the newly inserted submission
        error_log("New Submission ID successfully inserted: " . $submission_id);

        // Log the initial status change (by the PIO)
        $logStmt = $conn->prepare("INSERT INTO submission_status_logs (submission_id, changed_by, old_status, new_status) VALUES (?, ?, ?, ?)");
        if (!$logStmt) {
            throw new Exception("Prepare log statement failed: " . $conn->error);
        }
        $old_status = 'N/A'; // Initial submission has no old status
        $logStmt->bind_param("iiss", $submission_id, $pio_id, $old_status, $status);
        if (!$logStmt->execute()) {
            error_log("Execute log statement failed: " . $logStmt->error);
            throw new Exception("Execute log statement failed: " . $logStmt->error);
        }
        $logStmt->close();
        error_log("Submission status log inserted successfully.");


        // --- Handle dynamic file uploads for requirements ---
        $stmt_insert_file = $conn->prepare("INSERT INTO submission_files 
            (submission_id, requirement_id, uploaded_by_user_id, file_name, file_path, file_mime_type, file_size_bytes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt_insert_file) {
            error_log("Prepare submission_files statement failed: " . $conn->error);
            throw new Exception("Prepare submission_files statement failed: " . $conn->error);
        }

        foreach ($_FILES as $input_name => $file_data) {
            // Check for file inputs named like 'file_requirement_[id]'
            // Exclude the 'article_file' which is handled separately
            if (str_starts_with($input_name, 'file_requirement_') && $file_data['error'] === UPLOAD_ERR_OK) {
                $requirement_id = (int)str_replace('file_requirement_', '', $input_name);
                
                // Generate a unique file name for storage
                $stored_file_name = time() . '_' . uniqid() . '_' . basename($file_data['name']);
                $uploadPath = '../../uploads/' . $stored_file_name;

                if (!is_dir('../../uploads')) {
                    mkdir('../../uploads', 0777, true);
                }

                if (!move_uploaded_file($file_data['tmp_name'], $uploadPath)) {
                    error_log("Failed to move uploaded file for requirement ID: " . $requirement_id . ". Temp name: " . $file_data['tmp_name'] . ", Destination: " . $uploadPath);
                    throw new Exception("Failed to move uploaded file for requirement ID: " . $requirement_id . ". Check permissions.");
                }
                
                // Get file details for the database
                $original_file_name = basename($file_data['name']);
                $file_mime_type = mime_content_type($uploadPath); // More reliable than $_FILES['type']
                $file_size_bytes = $file_data['size'];

                // Insert file details into submission_files table
                $stmt_insert_file->bind_param("iiisssi", 
                    $submission_id, 
                    $requirement_id, 
                    $pio_id, // uploaded_by_user_id (the PIO who is submitting)
                    $original_file_name, // file_name
                    $uploadPath, // file_path
                    $file_mime_type, // file_mime_type
                    $file_size_bytes // file_size_bytes
                );
                if (!$stmt_insert_file->execute()) {
                    error_log("Error inserting submission file for requirement ID {$requirement_id}: " . $stmt_insert_file->error);
                    throw new Exception("Error inserting submission file for requirement ID {$requirement_id}: " . $stmt_insert_file->error);
                }
                error_log("Submission file for requirement ID {$requirement_id} inserted successfully.");
            } else if (str_starts_with($input_name, 'file_requirement_') && $file_data['error'] !== UPLOAD_ERR_NO_FILE) {
                    // Log other file upload errors (e.g., file too large, partial upload)
                error_log("File upload error for input {$input_name}: " . $file_data['error']);
            }
        }
        $stmt_insert_file->close();
        $stmt->close();
        
        $conn->commit(); // Commit transaction on success
        $_SESSION['submission_success'] = $refNumber;
        header("Location: " . $_SERVER['PHP_SELF']); // refresh to show the modal
        exit();
        error_log("Transaction committed successfully for submission ID: " . $submission_id);

    } catch (Exception $e) {
        $conn->rollback(); // Rollback transaction on error
        error_log("Transaction rolled back due to error.");
        $errorMessage = "❌ Submission failed: " . $e->getMessage();
        error_log("PIO Submission error (ROLLBACK): " . $e->getMessage() . " Stack: " . $e->getTraceAsString());
        // If main file was moved, delete them due to database error
        if (isset($main_file_path) && file_exists($main_file_path)) {
            unlink($main_file_path);
            error_log("Deleted uploaded main file due to error: " . $main_file_path);
        }
        // Note: For dynamically uploaded requirement files, a more complex cleanup loop would be needed
        // to delete any files that were successfully moved before the exception.
    }
}

// Check for and display session messages (e.g., after a redirect)
if (isset($_SESSION['message'])) {
    $successMessage = $_SESSION['message'];
    $messageType = $_SESSION['message_type']; // You might also store messageType
    unset($_SESSION['message']); // Clear message after displaying
    unset($_SESSION['message_type']);
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Submit Research - PIO Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            background-color: #f0f2f5;
        }
       
        .btn-outline-dark {
            border-color: #6c757d;
            color: #495057;
            transition: all 0.3s ease;
        }
        .btn-outline-dark:hover {
            background-color: #495057;
            color: white;
            border-color: #495057;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
            
        }
       
        #main-content {
            flex-grow: 1;
            padding-right: 15px;
            
            
        }
      
        @media (max-width: 768px) {
            .main-content-wrapper {
                flex-direction: column;
            }
          
        }
        .form-label {
            font-weight: 500;
        }
        .card{
            width: 1400px;
        }
        form{
        }
    
    </style>
    <script>
        // Global variable to store requirements fetched from server
        let allRequirements = {}; 
        // Store all publication types fetched from PHP
        const allPublicationTypes = <?= json_encode($publicationTypes) ?>;
        // Store all innovation types fetched from PHP
        const allInnovationTypes = <?= json_encode($innovationTypes) ?>;


        // Function to populate the "Type of Submission" dropdown based on category
        function populateSubmissionTypes() {
            const submissionCategorySelect = document.getElementById('submission_category');
            const submissionTypeSelect = document.getElementById('submission_type_id');
            const selectedCategory = submissionCategorySelect.value;

            // Clear previous options
            submissionTypeSelect.innerHTML = '<option value="">Select Type</option>';
            // Also clear requirements container when category changes
            document.getElementById('requirementsContainer').innerHTML = '<div class="alert alert-info">Please select a "Type of Submission" to see required documents and checklist.</div>';


            if (!selectedCategory) {
                submissionTypeSelect.disabled = true; // Disable if no category selected
                return;
            }

            submissionTypeSelect.disabled = false; // Enable if category selected

            let typesToDisplay = [];
            if (selectedCategory === 'publication') {
                typesToDisplay = allPublicationTypes;
            } else if (selectedCategory === 'innovation') {
                typesToDisplay = allInnovationTypes;
            }

            if (typesToDisplay.length > 0) {
                typesToDisplay.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.id;
                    option.textContent = type.name;
                    submissionTypeSelect.appendChild(option);
                });
            } else {
                submissionTypeSelect.innerHTML = '<option value="">No types available for this category</option>';
            }
        }

        // Function to dynamically load submission requirements (existing logic)
        async function loadSubmissionRequirements() {
            const submissionCategorySelect = document.getElementById('submission_category');
            const submissionTypeSelect = document.getElementById('submission_type_id');
            const requirementsContainer = document.getElementById('requirementsContainer');
            
            const selectedTypeId = submissionTypeSelect.value;
            const selectedCategoryName = submissionCategorySelect.value; // 'publication' or 'innovation'

            requirementsContainer.innerHTML = ''; // Clear previous requirements

            if (!selectedTypeId || selectedTypeId === "No types available for this category") {
                return; // Do nothing if no type is selected or if the placeholder is selected
            }

            try {
                // AJAX call to fetch requirements from your custom get_requirements.php
                // Pass both type_id and category
                const response = await fetch(`../../config/get_requirements.php?type_id=${selectedTypeId}&category=${selectedCategoryName}`);
                const data = await response.json();

                if (data.success && data.requirements.length > 0) {
                    let htmlContent = '<h5 class="mb-3">Required Documents & Checklist:</h5>';
                    data.requirements.forEach(req => {
                        // Store requirement details globally for potential client-side validation
                        allRequirements[req.id] = req; 

                        const requiredText = req.is_required ? '<span class="text-danger">*Required</span>' : '(Optional)';
                        const inputId = `file_requirement_${req.id}`;
                        
                        const acceptAttribute = ''; // No specific accept attribute for now (as 'allowed_extensions' column is not in requirements_master)

                        htmlContent += `
                            <div class="mb-3 p-3 border rounded bg-light">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="1" id="checklist_requirement_${req.id}" name="requirement_checked_${req.id}" ${req.is_required ? 'checked disabled' : ''}>
                                    <label class="form-check-label" for="checklist_requirement_${req.id}">
                                        ${req.name} ${requiredText}
                                    </label>
                                    <p class="form-text text-muted">${req.description || 'No description provided.'}</p>
                                </div>
                                <label for="${inputId}" class="form-label d-block">Upload File for "${req.name}":</label>
                                <input type="file" class="form-control" id="${inputId}" name="${inputId}" ${req.is_required ? 'required' : ''} ${acceptAttribute}>
                            </div>
                        `;
                    });
                    requirementsContainer.innerHTML = htmlContent;
                } else {
                    requirementsContainer.innerHTML = '<div class="alert alert-info">No specific requirements found for this submission type.</div>';
                }
            } catch (error) {
                console.error('Error fetching submission requirements:', error);
                requirementsContainer.innerHTML = '<div class="alert alert-danger">Error loading requirements. Please try again.</div>';
            }
        }

        // Function to populate the "Main Researcher" dropdown based on selected department
        async function populateResearchers() {
            const departmentSelect = document.getElementById('department_id');
            const researcherSelect = document.getElementById('main_researcher_id');
            const selectedDepartmentId = departmentSelect.value;

            // Clear previous options
            researcherSelect.innerHTML = '<option value="">Select Researcher </option>';
            researcherSelect.disabled = true; // Disable until a department is selected

            if (!selectedDepartmentId) {
                return;
            }

            try {
                const response = await fetch(`get_researchers_by_department.php?department_id=${selectedDepartmentId}`);
                const data = await response.json();

                if (data.success && data.researchers.length > 0) {
                    data.researchers.forEach(researcher => {
                        const option = document.createElement('option');
                        option.value = researcher.user_id;
                        option.textContent = `${researcher.name} (${researcher.email})`;
                        researcherSelect.appendChild(option);
                    });
                    researcherSelect.disabled = false; // Enable once populated
                } else {
                    researcherSelect.innerHTML = '<option value="">No researchers found in this department</option>';
                }
            } catch (error) {
                console.error('Error fetching researchers:', error);
                researcherSelect.innerHTML = '<option value="">Error loading researchers</option>';
            }
        }


        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Bootstrap form validation
            (function () {
                'use strict'
                var forms = document.querySelectorAll('.needs-validation')
                Array.prototype.slice.call(forms)
                    .forEach(function (form) {
                        form.addEventListener('submit', function (event) {
                            if (!form.checkValidity()) {
                                event.preventDefault()
                                event.stopPropagation()
                            }
                            form.classList.add('was-validated')
                        }, false)
                    })
            })();

            // Initial call to populate submission types based on default/pre-selected category
            populateSubmissionTypes();

            // If a submission type was previously selected (e.g., after a failed form submission),
            // ensure its requirements are loaded.
            const submissionTypeSelect = document.getElementById('submission_type_id');
            if (submissionTypeSelect.value) {
                loadSubmissionRequirements();
            }

            // Initial call to populate researchers if a department was pre-selected (e.g., after failed form submission)
            const departmentSelect = document.getElementById('department_id');
            if (departmentSelect.value) {
                populateResearchers();
            }
        });
    </script>
</head>
<body class="bg-light">


    <div class="container-fluid main-content-wrapper">
                <?php include 'sidebar.php'; // Include the PIO sidebar ?>

        
        <div class="card">
        <div class="card-header" style="background-color: #f8f9fa; color: #000; border-bottom: 1px solid #ddd; border-radius: 6px 6px 0 0; padding: 1rem 1.25rem;">
            <h5 class="mb-0" style="font-weight: 600; font-size: 1.35rem;">Submit Research on Behalf of a Researcher</h5>
        </div>

            <div class="card-body" style="background-color: #ffffff; padding: 2rem; border-radius: 0 0 6px 6px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); font-size: 1.05rem;">
    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $successMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <div class="mt-3">
                <a href="dashboard.php" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
    <?php elseif (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$pio_campus_id): ?>
        <p class="text-danger">Your PIO account is not associated with a campus. Please contact support to use this feature.</p>
    <?php else: ?>
        <form action="submit_research_pio.php" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
            <div class="mb-3">
                <label for="department_id" class="form-label fw-semibold">Select Department <span class="text-danger">*</span></label>
                <select class="form-select" id="department_id" name="department_id" onchange="populateResearchers()" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['department_id']) ?>" <?= ((isset($_POST['department_id']) && $_POST['department_id'] == $dept['department_id']) ? 'selected' : '') ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Please select a department.</div>
                <?php if (empty($departments)): ?>
                    <small class="text-danger mt-2 d-block">No departments found for your campus. Please configure departments first.</small>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="main_researcher_id" class="form-label fw-semibold">Select Researcher <span class="text-danger">*</span></label>
                <select class="form-select" id="main_researcher_id" name="main_researcher_id" required disabled>
                    <option value="">Select Researcher</option>
                </select>
                <div class="invalid-feedback">Please select a researcher.</div>
            </div>

            <div class="mb-3">
                <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                <div class="invalid-feedback">Please enter the research title.</div>
            </div>

            <div class="mb-3">
                <label for="abstract" class="form-label fw-semibold">Abstract <span class="text-danger">*</span></label>
                <textarea class="form-control" id="abstract" name="abstract" rows="5" required><?= htmlspecialchars($_POST['abstract'] ?? '') ?></textarea>
                <div class="invalid-feedback">Please enter the research abstract.</div>
            </div>

            <div class="mb-3">
                <label for="submission_category" class="form-label fw-semibold">Submission Category <span class="text-danger">*</span></label>
                <select name="submission_category" id="submission_category" class="form-select" onchange="populateSubmissionTypes()" required>
                    <option value="">Select Category</option>
                    <option value="publication" <?= (($_POST['submission_category'] ?? '') == 'publication' ? 'selected' : '') ?>>Publication</option>
                    <option value="innovation" <?= (($_POST['submission_category'] ?? '') == 'innovation' ? 'selected' : '') ?>>Innovation</option>
                </select>
                <div class="invalid-feedback">Please select a submission category.</div>
            </div>

            <div class="mb-3">
                <label for="submission_type_id" class="form-label fw-semibold">Type of Submission <span class="text-danger">*</span></label>
                <select name="submission_type_id" id="submission_type_id" class="form-select" onchange="loadSubmissionRequirements()" required disabled>
                    <option value="">Select Type</option>
                </select>
                <div class="invalid-feedback">Please select a type of submission.</div>
            </div>

            <div class="mb-3 p-3 border rounded bg-light">
                <label for="article_file" class="form-label fw-semibold d-block">Main Article File <span class="text-danger">*Required</span></label>
                <input type="file" class="form-control" id="article_file" name="article_file" required accept=".pdf,.doc,.docx">
                <small class="form-text text-muted">Upload the primary research paper or article here (PDF, DOC, DOCX).</small>
                <div class="invalid-feedback">Please upload the main article file (PDF, DOC, DOCX).</div>
            </div>

            <div id="requirementsContainer" class="mt-4">
                <div class="alert alert-info">Please select a "Type of Submission" to see required documents and checklist.</div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Submit Research</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['submission_success'])): ?>
<!-- Minimalist Success Modal -->
<div class="modal fade" id="submissionSuccessModal" tabindex="-1" aria-labelledby="submissionSuccessModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-sm">
      <div class="modal-header border-bottom-0">
        <h5 class="modal-title fw-semibold" id="submissionSuccessModalLabel">Submission Complete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <p class="fs-6 mb-2">Submission has been successfully made for the researcher.</p>
        <p class="fw-medium mb-0">Reference #: 
          <span class="text-body fw-bold"><?= htmlspecialchars($_SESSION['submission_success']) ?></span>
        </p>
      </div>
      <div class="modal-footer justify-content-center border-top-0">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php unset($_SESSION['submission_success']); endif; ?>

<script>
  document.addEventListener("DOMContentLoaded", function () {
    const modalEl = document.getElementById('submissionSuccessModal');
    if (modalEl) {
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    }
  });
</script>




    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
