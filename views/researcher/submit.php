<?php
// File: views/researcher/submit.php

// Conditionally start the session to prevent "session_start(): Ignoring session_start()" notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/connect.php';

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure user is logged in and has the 'researcher' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'researcher') {
    header("Location: ../../auth/login.php");
    exit();
}

// Get researcher's user_id, campus_id, and department_id directly from session
$researcher_user_id = $_SESSION['user_id'] ?? null;
$researcher_campus_id = $_SESSION['campus_id'] ?? null;
$researcher_department_id = $_SESSION['department_id'] ?? null;
$researcher_department_name = $_SESSION['department_name'] ?? 'N/A'; // Assuming department_name is also stored in session for display

// --- Fetch ALL Publication Types for the dropdowns ---
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
}


// --- Fetch the Facilitator Name for the researcher's fixed department ---
// This is for display purposes, showing who the submission will be routed to.
$facilitatorName = 'Not Assigned'; // Default
if ($researcher_department_id && $researcher_campus_id) {
    $stmt_facilitator = $conn->prepare("
        SELECT u.name AS facilitator_name 
        FROM users u 
        WHERE u.department_id = ? AND u.role = 'facilitator' AND u.campus_id = ?
        LIMIT 1
    ");
    if ($stmt_facilitator) {
        $stmt_facilitator->bind_param("ii", $researcher_department_id, $researcher_campus_id);
        $stmt_facilitator->execute();
        $result_facilitator = $stmt_facilitator->get_result();
        if ($row = $result_facilitator->fetch_assoc()) {
            $facilitatorName = $row['facilitator_name'];
        }
        $stmt_facilitator->close();
    } else {
        error_log("Error fetching facilitator: " . $conn->error);
    }
}


$successMessage = "";
$errorMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DEBUG: Log all POST and FILES data received
    error_log("--- NEW RESEARCHER SUBMISSION ATTEMPT ---");
    error_log("POST Data: " . print_r($_POST, true));
    error_log("FILES Data: " . print_r($_FILES, true));

    $conn->begin_transaction(); // Start transaction for atomicity
    error_log("Transaction started for researcher submission.");

    try {
        $title = trim($_POST['title'] ?? '');
        $abstract = trim($_POST['abstract'] ?? '');
        $submission_category = trim($_POST['submission_category'] ?? ''); // Get selected category
        $submission_type_id = filter_var($_POST['submission_type_id'] ?? '', FILTER_VALIDATE_INT); // This is either pub_type_id or inno_type_id
        
        // Basic validation - ensure all necessary session IDs are available
        if (empty($title) || empty($abstract) || empty($submission_category) || !$submission_type_id || !$researcher_user_id || !$researcher_campus_id || !$researcher_department_id) {
            throw new Exception("Missing required form data or researcher's session details (Campus ID, Department ID).");
        }

        // --- Handle main article file upload FIRST ---
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
            // Main article file is generally required. Adjust as per your system's rules.
            throw new Exception("Main article file is required.");
        }

        // --- IMPORTANT CHANGE: Reference number is now NULL initially ---
        $refNumber = null; // No reference number generated at this stage
        $status = 'submitted'; // Valid ENUM status for new submission
        error_log("Attempting to set researcher submission status to: " . $status); // DEBUG: Log the status being set

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
        // IMPORTANT: reference_number is now passed as NULL
        // Added inno_type_id to the insert statement
        $stmt = $conn->prepare("INSERT INTO submissions 
            (reference_number, title, abstract, submission_type, file_path, researcher_id, department_id, campus_id, status, submission_date, pub_type_id, inno_type_id, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare submission statement failed: " . $conn->error);
            throw new Exception("Prepare submission statement failed: " . $conn->error);
        }
        
        // Bind parameters for main submission
        // Corrected bind_param string: s (ref - can be NULL), s (title), s (abstract), s (submission_type), s (file_path), i (researcher_id), i (department_id), i (campus_id), s (status), i (pub_type_id), i (inno_type_id), s (description)
        $stmt->bind_param("sssssiiisiis", // 12 parameters
            $refNumber, $title, $abstract, $submission_category, $main_file_path, 
            $researcher_user_id, 
            $researcher_department_id, $researcher_campus_id, $status, 
            $pub_type_id_for_db, $inno_type_id_for_db, $abstract // Using abstract for description
        );
        
        if (!$stmt->execute()) {
            error_log("Execute submission statement failed: " . $stmt->error);
            throw new Exception("Execute submission statement failed: " . $stmt->error);
        }
        error_log("Submission main data inserted successfully for researcher.");

        $submission_id = $conn->insert_id; // Get the ID of the newly inserted submission
        error_log("New Researcher Submission ID successfully inserted: " . $submission_id);

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
                    error_log("Failed to move uploaded file for requirement ID: " . $requirement_id);
                    throw new Exception("Failed to move uploaded file for requirement ID: " . $requirement_id);
                }
                
                // Get file details for the database
                $original_file_name = basename($file_data['name']);
                $file_mime_type = mime_content_type($uploadPath); // More reliable than $_FILES['type']
                $file_size_bytes = $file_data['size'];

                // Insert file details into submission_files table
                $stmt_insert_file->bind_param("iiisssi", 
                    $submission_id, 
                    $requirement_id, 
                    $researcher_user_id, // uploaded_by_user_id
                    $original_file_name, // file_name
                    $uploadPath, // file_path
                    $file_mime_type, // file_mime_type
                    $file_size_bytes // file_size_bytes
                );
                if (!$stmt_insert_file->execute()) {
                    error_log("Error inserting submission file: " . $stmt_insert_file->error);
                    throw new Exception("Error inserting submission file: " . $stmt_insert_file->error);
                }
            } else if (str_starts_with($input_name, 'file_requirement_') && $file_data['error'] !== UPLOAD_ERR_NO_FILE) {
                 // Log other file upload errors (e.g., file too large, partial upload)
                error_log("File upload error for input {$input_name}: " . $file_data['error']);
            }
        }
        $stmt_insert_file->close();
        $stmt->close();
        $conn->commit(); // Commit transaction on success
        // --- UPDATED SUCCESS MESSAGE ---
        $successMessage = "✅ Submission Complete!<br>Your submission is now pending review. The facilitator will provide a tracking number once processed.";
        error_log("Transaction committed successfully for researcher submission ID: " . $submission_id);

    } catch (Exception $e) {
        $conn->rollback(); // Rollback transaction on error
        $errorMessage = "❌ Submission failed: " . $e->getMessage();
        error_log("Submission error: " . $e->getMessage() . " Stack: " . $e->getTraceAsString());
        // If files were moved, delete them due to database error
        if (isset($main_file_path) && file_exists($main_file_path)) {
            unlink($main_file_path);
            error_log("Deleted uploaded main file due to error: " . $main_file_path);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<head>
    <meta charset="UTF-8">
    <title>Submit Work | PubInno-track</title>
    <link rel="icon" href="../../assets/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Optional: Add your own CSS here -->
</head>    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
   <style>
    body {
        font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        background-color: #f0f2f5;
        margin: 0;
    }

    .container {
        max-width: 900px;
    }

    h4 {
        font-weight: 600;
        color: #2c3e50;
    }

    form {
        background-color: #ffffff;
        padding: 2rem;
        border-radius: 1rem;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        margin-top: 2rem;
        /* margin-top:-30px; */
        width: 1250px;
        margin-left:-200px;
    }

    .form-label {
        font-weight: 500;
        color: #34495e;
    }

    .form-control,
    .form-select {
        border-radius: 0.5rem;
        border: 1px solid #ced4da;
        transition: border-color 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #3498db;
        box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
    }

    .btn-success {
        background-color: #27ae60;
        border-color: #27ae60;
        font-weight: 500;
        padding: 0.6rem 1.5rem;
        border-radius: 0.5rem;
    }

    .btn-success:hover {
        background-color: #219150;
        border-color: #219150;
    }

    .btn-secondary {
        background-color: #7f8c8d;
        border-color: #7f8c8d;
        font-weight: 500;
        padding: 0.6rem 1.5rem;
        border-radius: 0.5rem;
    }

    .btn-secondary:hover {
        background-color: #707b7c;
        border-color: #707b7c;
    }

    .alert {
        border-radius: 0.5rem;
        padding: 1rem 1.5rem;
    }

    #requirementsContainer .form-check-label {
        font-weight: 500;
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
            
            const selectedCategoryId = submissionTypeSelect.value;
            const selectedCategoryName = submissionCategorySelect.value; // 'publication' or 'innovation'

            requirementsContainer.innerHTML = ''; // Clear previous requirements

            if (!selectedCategoryId || selectedCategoryId === "No types available for this category") {
                return; // Do nothing if no type is selected or if the placeholder is selected
            }

            try {
                // AJAX call to fetch requirements from your custom get_requirements.php
                // Pass both type_id and category
                const response = await fetch(`../../config/get_requirements.php?type_id=${selectedCategoryId}&category=${selectedCategoryName}`);
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

            // If a submission type was previously selected (e.g., after a failed form submission),
            // ensure its requirements are loaded.
            const submissionTypeSelect = document.getElementById('submission_type_id');
            if (submissionTypeSelect.value) {
                loadSubmissionRequirements();
            }
        });
    </script>
</head>
<body class="bg-light">


<?php include '_navbar_researcher.php'; ?>


<div class="container mt-5">

<?php if (!empty($successMessage) || !empty($errorMessage)): ?>
    <div class="position-fixed top-50 start-50 translate-middle z-3"
         style="min-width: 400px; max-width: 90%; background-color: #fefefe; border-left: 6px solid <?= !empty($successMessage) ? '#51cf66' : '#ff6b6b' ?>; color: #333; box-shadow: 0 6px 30px rgba(0,0,0,0.1); border-radius: 14px; padding: 1.75rem 2rem; font-family: 'Segoe UI', sans-serif;">
        
        <div class="d-flex align-items-start">
            <i class="bi <?= !empty($successMessage) ? 'bi-check2-circle' : 'bi-exclamation-triangle-fill' ?> fs-3 me-3"
               style="color: <?= !empty($successMessage) ? '#2f9e44' : '#e03131' ?>;"></i>
            <div>
                <h5 class="mb-2 fw-semibold"><?= !empty($successMessage) ? '✅ Submission Complete!' : '⚠️ Error Occurred' ?></h5>
                <div class="mb-3" style="white-space: pre-wrap;">
                    <?= !empty($successMessage) ? $successMessage : $errorMessage ?>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <?php if (!empty($successMessage)): ?>
                        <a href="submit.php" class="btn btn-outline-success px-3 rounded-pill">Submit Another</a>
                    <?php else: ?>
                        <a href="submit.php" class="btn btn-outline-danger px-3 rounded-pill">Try Again</a>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-primary px-3 rounded-pill">Return to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>


    <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow rounded">
    <h4><i class="bi bi-upload me-2 text-success"></i>Submit Your Publication or Innovation</h4>

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
        <select name="submission_category" id="submission_category" class="form-select" onchange="populateSubmissionTypes()" required>
            <option value="">Select Category</option>
            <option value="publication" <?= (($_POST['submission_category'] ?? '') == 'publication' ? 'selected' : '') ?>>Publication</option>
            <option value="innovation" <?= (($_POST['submission_category'] ?? '') == 'innovation' ? 'selected' : '') ?>>Innovation</option>
        </select>
    </div>

    <div class="mb-3">
        <label for="submission_type_id" class="form-label">Type of Submission</label>
        <select name="submission_type_id" id="submission_type_id" class="form-select" onchange="loadSubmissionRequirements()" required disabled>
            <option value="">Select Type</option>
        </select>
    </div>

    <div class="mb-3 border rounded bg-light">
        <label for="article_file" class="form-label d-block">Main Article File <span class="text-danger">*</span></label>
        <input type="file" class="form-control" id="article_file" name="article_file" required accept=".pdf,.doc,.docx">
        <small class="form-text">Upload the primary research paper or article here (PDF, DOC, DOCX).</small>
    </div>

    <div id="requirementsContainer" class="mt-4">
        <div class="alert alert-info mb-0">
            Please select a <strong>Type of Submission</strong> to view document checklist and requirements.
        </div>
    </div>

    <div class="mb-3 mt-4">
        <label for="facilitatorNameDisplay" class="form-label">Assigned Facilitator</label>
        <input type="text" id="facilitatorNameDisplay" class="form-control" value="<?= htmlspecialchars($facilitatorName) ?>" readonly>
        <small class="form-text">Your submission will be routed to this facilitator.</small>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i> Submit
        </button>
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-x-circle me-1"></i> Cancel
        </a>
    </div>
</form>



</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
