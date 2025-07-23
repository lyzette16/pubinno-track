<?php
// File: views/facilitator/submit.php

// Conditionally start the session to prevent "session_start(): Ignoring session_start()" notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the user is logged in and is a facilitator
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'facilitator') {
    header("Location: ../../auth/login.php");
    exit();
}

$facilitator_id = $_SESSION['user_id'];
$facilitator_department_id = $_SESSION['department_id'] ?? null; // Facilitator's own department
$facilitator_campus_id = $_SESSION['campus_id'] ?? null; // Facilitator's own campus
$successMessage = "";
$errorMessage = "";

// --- Fetch ALL Departments for the dropdown ---
$departments = [];
$stmt_depts = $conn->prepare("SELECT department_id, name AS department_name FROM departments ORDER BY name ASC");if ($stmt_depts) {
    $stmt_depts->execute();
    $result_depts = $stmt_depts->get_result();
    while ($row = $result_depts->fetch_assoc()) {
        $departments[] = $row;
    }
    $stmt_depts->close();
} else {
    error_log("Error fetching departments: " . $conn->error);
    $errorMessage = "Database error fetching departments.";
}


// --- Fetch ALL Publication Types for the dropdowns ---
$publicationTypes = [];
$stmt_pub_types = $conn->prepare("SELECT pub_type_id AS id, type_name AS name FROM publication_types ORDER BY type_name");
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

// --- Fetch ALL Innovation Types for the dropdowns ---
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
    error_log("--- NEW FACILITATOR SUBMISSION ATTEMPT ---");
    error_log("POST Data: " . print_r($_POST, true));
    error_log("FILES Data: " . print_r($_FILES, true));

    $conn->begin_transaction();
    error_log("Transaction started for facilitator submission.");

    try {
        $title = trim($_POST['title'] ?? '');
        $abstract = trim($_POST['abstract'] ?? '');
        $submission_category = trim($_POST['submission_category'] ?? ''); // 'publication' or 'innovation'
        $submission_type_id = filter_var($_POST['submission_type_id'] ?? '', FILTER_VALIDATE_INT); // This is either pub_type_id or inno_type_id
        $main_researcher_id = (int)($_POST['main_researcher_id'] ?? 0);
        $selected_department_id = (int)($_POST['department_id'] ?? 0); // Department chosen in the form
        
        // Basic validation
        if (empty($title) || empty($abstract) || empty($submission_category) || !$submission_type_id || !$main_researcher_id || !$selected_department_id || !$facilitator_campus_id) {
            throw new Exception("Missing required form data or facilitator's session details (Campus ID, Department ID).");
        }

        // Validate main researcher exists AND belongs to the *selected* department and facilitator's campus
        $stmt_check_researcher = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'researcher' AND department_id = ? AND campus_id = ?");
        if (!$stmt_check_researcher) {
            throw new Exception("Failed to prepare researcher validation statement: " . $conn->error);
        }
        $stmt_check_researcher->bind_param("iii", $main_researcher_id, $selected_department_id, $facilitator_campus_id);
        $stmt_check_researcher->execute();
        $res_check = $stmt_check_researcher->get_result();
        if ($res_check->num_rows === 0) {
            throw new Exception("Invalid main researcher selected or researcher does not belong to the chosen department/your campus.");
        }
        $stmt_check_researcher->close();
        
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
            $main_file_path = $uploadPath;
        } else if (isset($_FILES['article_file']) && $_FILES['article_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new Exception("Error uploading main article file: " . $_FILES['article_file']['error']);
        } else {
            throw new Exception("Main article file is required.");
        }

        // --- IMPORTANT CHANGE: Reference number is now NULL initially ---
        $refNumber = null; // No reference number generated at this stage
        $status = 'submitted'; // Set initial status to 'submitted'
        error_log("Attempting to set submission status to: " . $status);

        // Determine which ID to use based on category for database insertion
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
        // s (ref - can be NULL), s (title), s (abstract), s (submission_type), s (file_path), i (researcher_id), i (department_id), i (campus_id), s (status), i (pub_type_id), i (inno_type_id), s (description)
        $stmt->bind_param("sssssiiisiis",
            $refNumber, $title, $abstract, $submission_category, $main_file_path, 
            $main_researcher_id, 
            $selected_department_id, // Use the selected department_id from the form
            $facilitator_campus_id, // Use facilitator's campus_id
            $status, 
            $pub_type_id_for_db, $inno_type_id_for_db, $abstract // Using abstract for description
        );
        
        if (!$stmt->execute()) {
            error_log("Execute submission statement failed: " . $stmt->error);
            throw new Exception("Execute submission statement failed: " . $stmt->error);
        }
        error_log("Submission main data inserted successfully.");

        $submission_id = $conn->insert_id;
        error_log("New Submission ID successfully inserted: " . $submission_id);

        // Log the initial status change (by the facilitator)
        $logStmt = $conn->prepare("INSERT INTO submission_status_logs (submission_id, changed_by, old_status, new_status) VALUES (?, ?, ?, ?)");
        if (!$logStmt) {
            error_log("Prepare log statement failed: " . $conn->error);
            throw new Exception("Prepare log statement failed: " . $conn->error);
        }
        $old_status = 'N/A';
        $logStmt->bind_param("iiss", $submission_id, $facilitator_id, $old_status, $status);
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
            if (str_starts_with($input_name, 'file_requirement_') && $file_data['error'] === UPLOAD_ERR_OK) {
                $requirement_id = (int)str_replace('file_requirement_', '', $input_name);
                
                $stored_file_name = time() . '_' . uniqid() . '_' . basename($file_data['name']);
                $uploadPath = '../../uploads/' . $stored_file_name;

                if (!is_dir('../../uploads')) {
                    mkdir('../../uploads', 0777, true);
                }

                if (!move_uploaded_file($file_data['tmp_name'], $uploadPath)) {
                    error_log("Failed to move uploaded file for requirement ID: " . $requirement_id . ". Temp name: " . $file_data['tmp_name'] . ", Destination: " . $uploadPath);
                    throw new Exception("Failed to move uploaded file for requirement ID: " . $requirement_id . ". Check permissions.");
                }
                
                $original_file_name = basename($file_data['name']);
                $file_mime_type = mime_content_type($uploadPath);
                $file_size_bytes = $file_data['size'];

                $stmt_insert_file->bind_param("iiisssi", 
                    $submission_id, 
                    $requirement_id, 
                    $facilitator_id, // Uploaded by facilitator
                    $original_file_name,
                    $uploadPath,
                    $file_mime_type,
                    $file_size_bytes
                );
                if (!$stmt_insert_file->execute()) {
                    error_log("Error inserting submission file for requirement ID {$requirement_id}: " . $stmt_insert_file->error);
                    throw new Exception("Error inserting submission file for requirement ID {$requirement_id}: " . $stmt_insert_file->error);
                }
                error_log("Submission file for requirement ID {$requirement_id} inserted successfully.");
            } else if (str_starts_with($input_name, 'file_requirement_') && $file_data['error'] !== UPLOAD_ERR_NO_FILE) {
                error_log("File upload error for input {$input_name}: " . $file_data['error']);
            }
        }
        $stmt_insert_file->close();
        $stmt->close();
        
        $conn->commit();
        // --- UPDATED SUCCESS MESSAGE ---
        $successMessage = "✅ Submission Complete for researcher! It is now pending review.<br>A tracking number will be assigned once the submission is processed by the facilitator.";

        $_SESSION['message'] = $successMessage;
        $_SESSION['message_type'] = 'success';
        header("Location: submit.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "❌ Submission failed: " . $e->getMessage();
        error_log("Facilitator Submission error (ROLLBACK): " . $e->getMessage() . " Stack: " . $e->getTraceAsString());
        // Clean up main file if it was uploaded before error
        if (isset($main_file_path) && file_exists($main_file_path)) {
            unlink($main_file_path);
            error_log("Deleted uploaded main file due to error: " . $main_file_path);
        }
        // No need to delete other requirement files as they would not have been linked in DB if transaction rolled back.
    }
}

// Check for and display session messages (e.g., after a redirect)
if (isset($_SESSION['message'])) {
    $successMessage = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Variables for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$currentStatus = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Submit Work for Researcher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            background-color: #f0f2f5;
        }
        .navbar {
            background-color: #007bff;
            color: white;
            flex-shrink: 0;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
        }
        #sidebar {
            width: 250px;
            flex-shrink: 0;
            background-color: #f8f9fa;
            padding: 15px;
            border-right: 1px solid #dee2e6;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 0.5rem;
            margin-right: 20px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        #main-content {
            flex-grow: 1;
            padding-right: 15px;
        }
        #sidebar .nav-link {
            color: #495057;
            font-weight: 500;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 5px;
            transition: all 0.2s ease;
        }
        #sidebar .nav-link:hover {
            background-color: #e9ecef;
            color: #0056b3;
        }
        #sidebar .nav-link.active {
            background-color: #007bff;
            color: white;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 123, 255, 0.25);
        }
        /* Adjustments for smaller screens */
        @media (max-width: 768px) {
            .main-content-wrapper {
                flex-direction: column;
            }
            #sidebar {
                width: 100%;
                margin-right: 0;
                margin-bottom: 20px;
                position: static;
                border-right: none;
                border-bottom: 1px solid #dee2e6;
            }
            #sidebar .nav-pills {
                flex-direction: row !important;
                justify-content: center;
                flex-wrap: wrap;
            }
            #sidebar .nav-link {
                margin: 5px;
            }
        }
        /* Form specific styles */
        .container.mt-5 {
            max-width: 900px;
        }
        form {
            background-color: #ffffff;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            margin-top: 2rem;
        }
        .form-label {
            font-weight: 500;
            color: #34495e;
        }
        .form-control, .form-select {
            border-radius: 0.5rem;
            border: 1px solid #ced4da;
        }
        .btn-success {
            background-color: #27ae60;
            border-color: #27ae60;
        }
        .btn-secondary {
            background-color: #7f8c8d;
            border-color: #7f8c8d;
        }
        .border.rounded.bg-light {
            background-color: #f9f9f9 !important;
            border: 1px solid #dee2e6 !important;
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 0.75rem;
        }
        small.form-text.text-muted {
            color: #6c757d !important;
        }
    </style>
    <script>
        // Global variable to store requirements fetched from server
        let allRequirements = {}; 
        // Store all publication types fetched from PHP
        const allPublicationTypes = <?= json_encode($publicationTypes) ?>;
        // Store all innovation types fetched from PHP
        const allInnovationTypes = <?= json_encode($innovationTypes) ?>;
        // Store the originally selected department_id from a failed POST, if any
        const initialSelectedDepartment = <?= json_encode($_POST['department_id'] ?? null) ?>;
        const initialSelectedResearcher = <?= json_encode($_POST['main_researcher_id'] ?? null) ?>;


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

        // --- NEW: Function to dynamically load researchers based on selected department ---
        async function loadResearchersByDepartment() {
            const departmentSelect = document.getElementById('department_id');
            const researcherSelect = document.getElementById('main_researcher_id');
            const selectedDepartmentId = departmentSelect.value;

            // Clear previous options
            researcherSelect.innerHTML = '<option value="">Select Main Researcher</option>';
            researcherSelect.disabled = true; // Disable until researchers are loaded or if no department is selected

            if (!selectedDepartmentId) {
                document.getElementById('noResearchersAlert').classList.add('d-none'); // Hide if no department
                return;
            }

            try {
                // AJAX call to fetch researchers from get_researchers_by_department.php
                const response = await fetch(`../../config/get_researchers_by_department.php?department_id=${selectedDepartmentId}&campus_id=<?= $facilitator_campus_id ?>`);
                const data = await response.json();

                if (data.success && data.researchers.length > 0) {
                    data.researchers.forEach(researcher => {
                        const option = document.createElement('option');
                        option.value = researcher.user_id;
                        option.textContent = `${researcher.name} (${researcher.email})`;
                        // Retain selection if it was the previously selected researcher from a failed POST
                        if (initialSelectedResearcher && researcher.user_id == initialSelectedResearcher) {
                            option.selected = true;
                        }
                        researcherSelect.appendChild(option);
                    });
                    researcherSelect.disabled = false;
                    document.getElementById('noResearchersAlert').classList.add('d-none');
                    document.getElementById('submitButton').disabled = false; // Enable submit button if researchers are available
                } else {
                    researcherSelect.innerHTML = '<option value="">No researchers found for this department</option>';
                    researcherSelect.disabled = true;
                    document.getElementById('noResearchersAlert').classList.remove('d-none'); // Show alert
                    document.getElementById('submitButton').disabled = true; // Disable submit button
                }
            } catch (error) {
                console.error('Error fetching researchers:', error);
                researcherSelect.innerHTML = '<option value="">Error loading researchers</option>';
                researcherSelect.disabled = true;
                document.getElementById('noResearchersAlert').classList.remove('d-none');
                document.getElementById('submitButton').disabled = true;
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
                requirementsContainer.innerHTML = '<div class="alert alert-info">Please select a "Type of Submission" to see required documents and checklist.</div>';
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

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Initial call to populate submission types based on default/pre-selected category
            populateSubmissionTypes();

            // If a department was previously selected (e.g., after a failed form submission),
            // load researchers for that department.
            const departmentSelect = document.getElementById('department_id');
            if (initialSelectedDepartment) {
                departmentSelect.value = initialSelectedDepartment; // Set the selected value
                loadResearchersByDepartment(); // Load researchers for it
            } else {
                // If no department was pre-selected, disable researcher dropdown initially
                document.getElementById('main_researcher_id').disabled = true;
                document.getElementById('noResearchersAlert').classList.remove('d-none');
                document.getElementById('submitButton').disabled = true; // Also disable submit
            }

            // If a submission type was previously selected (e.g., after a failed form submission),
            // ensure its requirements are loaded.
            const submissionTypeSelect = document.getElementById('submission_type_id');
            if (submissionTypeSelect.value) {
                loadSubmissionRequirements();
            }

            // Add event listeners
            document.getElementById('department_id').addEventListener('change', loadResearchersByDepartment);
            document.getElementById('submission_category').addEventListener('change', populateSubmissionTypes);
            document.getElementById('submission_type_id').addEventListener('change', loadSubmissionRequirements);
        });
    </script>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">PubInno-track: Publication and Innovation Tracking System</a>
            <div class="d-flex ms-auto">
                <span class="navbar-text me-3 text-white">
                    Welcome, Facilitator (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
                </span>
                <a href="../../auth/logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid main-content-wrapper">
        <?php include '_sidebar.php'; ?>

        <div id="main-content">
            <h4 class="mb-4">Submit a Work on Behalf of a Researcher</h4>

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

            <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow rounded">
                <div class="mb-3">
                    <label for="department_id" class="form-label">Department of Researcher</label>
                    <select name="department_id" id="department_id" class="form-control" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= (($dept['department_id'] == ($_POST['department_id'] ?? '')) ? 'selected' : '') ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($departments)): ?>
                        <small class="text-danger mt-2 d-block">No departments found. Please ensure departments are set up.</small>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="main_researcher_id" class="form-label">Main Researcher</label>
                    <select name="main_researcher_id" id="main_researcher_id" class="form-control" required>
                        <option value="">Select Main Researcher</option>
                        </select>
                    <div id="noResearchersAlert" class="alert alert-warning mt-2 d-none">
                        No researchers found for the selected department. Please ensure researcher accounts exist for this department.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" name="title" id="title" class="form-control" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label for="abstract" class="form-label">Abstract</label>
                    <textarea name="abstract" id="abstract" class="form-control" rows="4" required><?= htmlspecialchars($_POST['abstract'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="submission_category" class="form-label">Submission Category</label>
                    <select name="submission_category" id="submission_category" class="form-select" required>
                        <option value="">Select Category</option>
                        <option value="publication" <?= (($_POST['submission_category'] ?? '') == 'publication' ? 'selected' : '') ?>>Publication</option>
                        <option value="innovation" <?= (($_POST['submission_category'] ?? '') == 'innovation' ? 'selected' : '') ?>>Innovation</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="submission_type_id" class="form-label">Type of Submission</label>
                    <select name="submission_type_id" id="submission_type_id" class="form-select" required disabled>
                        <option value="">Select Type</option>
                        </select>
                </div>

                <div class="mb-3 p-3 border rounded bg-light">
                    <label for="article_file" class="form-label d-block">Main Article File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="article_file" name="article_file" required accept=".pdf,.doc,.docx">
                    <small class="form-text text-muted">Upload the primary research paper or article here (PDF, DOC, DOCX).</small>
                </div>

                <div id="requirementsContainer" class="mt-4">
                    <div class="alert alert-info">Please select a "Type of Submission" to see required documents and checklist.</div>
                </div>
                
                <button type="submit" id="submitButton" class="btn btn-success" disabled>Submit</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn) {
    $conn->close();
}
?>