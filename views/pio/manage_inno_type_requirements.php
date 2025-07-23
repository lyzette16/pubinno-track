<?php
// File: views/pio/manage_inno_type_requirements.php
// This file allows PIO users to manage the linking of master requirements
// to specific innovation types, including adding, editing, and deleting these links.

session_start(); // Start the session to access session variables
require_once '../../config/config.php'; // Include global configuration
require_once '../../config/connect.php'; // Include database connection script

// Enable error reporting for debugging during development.
// In a production environment, these should be turned off or logged to a file.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect if the user is not logged in or their role is not 'pio'
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pio') {
    header("Location: ../../auth/login.php");
    exit();
}

// Get current PIO user information from session
$pio_id = $_SESSION['user_id'];
$pio_name = $_SESSION['name'] ?? $_SESSION['email'] ?? 'PIO Officer';

// Initialize message variables for user feedback
$message = '';
$messageType = '';

// Check for and display any session messages (e.g., from a previous POST redirect)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']); // Clear the message after displaying
    unset($_SESSION['message_type']);
}

// --- Handle POST requests for Add, Edit, Delete operations ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if an action is specified in the POST data
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Sanitize and validate input data
        $inno_type_id = filter_var($_POST['inno_type_id'] ?? null, FILTER_VALIDATE_INT);
        $requirement_id = filter_var($_POST['requirement_id'] ?? null, FILTER_VALIDATE_INT);
        $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0; // Checkbox value
        $order_sequence = filter_var($_POST['order_sequence'] ?? 0, FILTER_VALIDATE_INT);
        $inno_type_req_id = filter_var($_POST['inno_type_req_id'] ?? null, FILTER_VALIDATE_INT); // For edit/delete

        // Start a database transaction for atomicity
        $conn->begin_transaction();
        try {
            if ($action === 'add') {
                // Validate required fields for adding
                if (!$inno_type_id || !$requirement_id) {
                    throw new Exception("Innovation Type and Requirement are required.");
                }

                // Check for duplicate entry before inserting
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inno_type_requirements WHERE inno_type_id = ? AND requirement_id = ?");
                if (!$check_stmt) {
                    throw new Exception("Failed to prepare duplicate check statement: " . $conn->error);
                }
                $check_stmt->bind_param("ii", $inno_type_id, $requirement_id);
                $check_stmt->execute();
                $check_stmt->bind_result($count);
                $check_stmt->fetch();
                $check_stmt->close();

                if ($count > 0) {
                    throw new Exception("This requirement is already linked to this innovation type. Cannot add duplicate.");
                }

                // Prepare and execute the INSERT statement
                $stmt = $conn->prepare("INSERT INTO inno_type_requirements (inno_type_id, requirement_id, is_mandatory, order_sequence) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Failed to prepare insert statement: " . $conn->error);
                }
                $stmt->bind_param("iiii", $inno_type_id, $requirement_id, $is_mandatory, $order_sequence);
                if (!$stmt->execute()) {
                    throw new Exception("Error adding innovation type requirement: " . $stmt->error);
                }
                $stmt->close();
                $message = "Innovation type requirement added successfully.";
                $messageType = "success";

            } elseif ($action === 'edit') {
                // Validate required fields for editing
                if (!$inno_type_req_id || !$inno_type_id || !$requirement_id) {
                    throw new Exception("Missing ID or required fields for editing.");
                }

                // Check for duplicate entry on edit (excluding the current record being edited)
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inno_type_requirements WHERE inno_type_id = ? AND requirement_id = ? AND inno_type_req_id != ?");
                if (!$check_stmt) {
                    throw new Exception("Failed to prepare duplicate check statement for edit: " . $conn->error);
                }
                $check_stmt->bind_param("iii", $inno_type_id, $requirement_id, $inno_type_req_id);
                $check_stmt->execute();
                $check_stmt->bind_result($count);
                $check_stmt->fetch();
                $check_stmt->close();

                if ($count > 0) {
                    throw new Exception("This requirement is already linked to this innovation type. Cannot create duplicate link.");
                }

                // Prepare and execute the UPDATE statement
                $stmt = $conn->prepare("UPDATE inno_type_requirements SET inno_type_id = ?, requirement_id = ?, is_mandatory = ?, order_sequence = ? WHERE inno_type_req_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare update statement: " . $conn->error);
                }
                $stmt->bind_param("iiiii", $inno_type_id, $requirement_id, $is_mandatory, $order_sequence, $inno_type_req_id);
                if (!$stmt->execute()) {
                    throw new Exception("Error updating innovation type requirement: " . $stmt->error);
                }
                $stmt->close();
                $message = "Innovation type requirement updated successfully.";
                $messageType = "success";

            } elseif ($action === 'delete') {
                // Validate ID for deletion
                if (!$inno_type_req_id) {
                    throw new Exception("Missing ID for deletion.");
                }

                // Prepare and execute the DELETE statement
                $stmt = $conn->prepare("DELETE FROM inno_type_requirements WHERE inno_type_req_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }
                $stmt->bind_param("i", $inno_type_req_id);
                if (!$stmt->execute()) {
                    throw new Exception("Error deleting innovation type requirement: " . $stmt->error);
                }
                $stmt->close();
                $message = "Innovation type requirement deleted successfully.";
                $messageType = "success";

            } else {
                throw new Exception("Invalid action specified.");
            }

            // Commit the transaction if all operations were successful
            $conn->commit();

        } catch (Exception $e) {
            // Rollback the transaction on any error
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            error_log("manage_inno_type_requirements.php POST error: " . $e->getMessage());
        }
    } else {
        $message = "No action specified.";
        $messageType = "warning";
    }

    // Store the message in session and redirect to prevent form resubmission
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $messageType;
    header("Location: manage_inno_type_requirements.php");
    exit();
}

// --- Fetch data for forms and table display (GET request) ---
$grouped_inno_type_requirements = []; // Array to store existing links, grouped by innovation type
$innovation_types = [];             // Array for dropdown of innovation types
$requirements_master = [];           // Array for dropdown of master requirements

// 1. Fetch all active innovation types for the dropdowns
$stmt_inno_types = $conn->prepare("SELECT inno_type_id, type_name FROM innovation_types WHERE is_active = 1 ORDER BY type_name ASC");
if ($stmt_inno_types) {
    $stmt_inno_types->execute();
    $result_inno_types = $stmt_inno_types->get_result();
    while ($row = $result_inno_types->fetch_assoc()) {
        $innovation_types[] = $row;
    }
    $stmt_inno_types->close();
} else {
    error_log("Database error fetching innovation types: " . $conn->error);
    // Continue execution, but display an error if no types are fetched
}

// 2. Fetch all active master requirements for the dropdowns
$stmt_req_master = $conn->prepare("SELECT requirement_id, requirement_name FROM requirements_master WHERE is_active = 1 ORDER BY requirement_name ASC");
if ($stmt_req_master) {
    $stmt_req_master->execute();
    $result_req_master = $stmt_req_master->get_result();
    while ($row = $result_req_master->fetch_assoc()) {
        $requirements_master[] = $row;
    }
    $stmt_req_master->close();
} else {
    error_log("Database error fetching master requirements: " . $conn->error);
    // Continue execution
}

// 3. Fetch existing innovation type requirements with their names for the table, then group them
$stmt_inno_reqs = $conn->prepare("
    SELECT
        itr.inno_type_req_id,
        itr.inno_type_id,
        it.type_name AS innovation_type_name,
        itr.requirement_id,
        rm.requirement_name AS master_requirement_name,
        itr.is_mandatory,
        itr.order_sequence
    FROM
        inno_type_requirements itr
    JOIN
        innovation_types it ON itr.inno_type_id = it.inno_type_id
    JOIN
        requirements_master rm ON itr.requirement_id = rm.requirement_id
    ORDER BY
        it.type_name ASC, itr.order_sequence ASC
");
if ($stmt_inno_reqs) {
    $stmt_inno_reqs->execute();
    $result_inno_reqs = $stmt_inno_reqs->get_result();
    while ($row = $result_inno_reqs->fetch_assoc()) {
        // Group requirements by innovation type name
        $grouped_inno_type_requirements[$row['innovation_type_name']][] = $row;
    }
    $stmt_inno_reqs->close();
} else {
    $message = "Database error fetching innovation type requirements for display: " . $conn->error;
    $messageType = "danger";
}

// Variable for sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>PIO - Manage Innovation Type Requirements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #f0f2f5;
        }
        .navbar {
            background-color: #007bff;
            border-bottom: 1px solid #0056b3;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .navbar-brand {
            color: white !important;
            font-weight: bold;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            letter-spacing: -0.5px;
        }
        .navbar-text {
            color: white !important;
            font-size: 0.98rem;
            font-weight: 500;
        }
        .btn-outline-dark {
            border-color: #fff;
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-outline-dark:hover {
            background-color: #fff;
            color: #007bff;
            border-color: #fff;
        }
        .main-content-wrapper {
            display: flex;
            flex-grow: 1;
            margin-top: 20px;
            padding: 0 15px;
            box-sizing: border-box;
        }
        #sidebar {
            width: 250px;
            flex-shrink: 0;
            background-color: #ffffff;
            padding: 15px;
            border-right: 1px solid #e0e0e0;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            border-radius: 0.5rem;
            margin-right: 20px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        #main-content {
            flex-grow: 1;
            flex-basis: 0;
            min-width: 0;
            padding-left: 15px;
        }
        #sidebar .nav-link {
            color: #343a40;
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
        @media (max-width: 768px) {
            .main-content-wrapper {
                flex-direction: column;
                padding: 0 10px;
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
            #main-content {
                padding-left: 0;
            }
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .card-title {
            font-weight: bold;
            color: #343a40;
        }
        /* Select2 Custom Styles to match Bootstrap 5 */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem !important; /* Match Bootstrap's form-control border-radius */
            min-height: calc(1.5em + 0.75rem + 2px) !important; /* Match Bootstrap's form-control height */
            padding: 0.375rem 0.75rem !important; /* Match Bootstrap's form-control padding */
            font-size: 1rem !important; /* Match Bootstrap's form-control font-size */
            line-height: 1.5 !important; /* Match Bootstrap's form-control line-height */
            border-color: #ced4da !important; /* Default border color */
        }
        .select2-container--bootstrap-5.select2-container--focus .select2-selection,
        .select2-container--bootstrap-5.select2-container--open .select2-selection {
            border-color: #86b7fe !important; /* Focus border color */
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important; /* Focus shadow */
        }
        .select2-container--bootstrap-5 .select2-dropdown {
            border-color: #ced4da !important;
            border-radius: 0.375rem !important;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        .select2-container--bootstrap-5 .select2-search__field {
            border-radius: 0.375rem !important;
            border-color: #ced4da !important;
        }
        .select2-container--bootstrap-5 .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #0d6efd !important; /* Highlight background */
            color: white !important; /* Highlight text color */
        }
        .select2-container--bootstrap-5 .select2-results__option--selected {
            background-color: #e9ecef !important; /* Selected option background */
            color: #212529 !important; /* Selected option text color */
        }
    </style>
</head>
<body class="bg-light">

    <div class="container-fluid main-content-wrapper">
        <?php include 'sidebar.php'; // Include the PIO sidebar ?>

        <div id="main-content">
            <h4 class="mb-4">Manage Innovation Type Requirements</h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Add New Innovation Type Requirement Link</h5>
                    <form action="manage_inno_type_requirements.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="inno_type_id" class="form-label">Innovation Type</label>
                            <select class="form-select" id="inno_type_id" name="inno_type_id" required>
                                <option value="">Select Innovation Type</option>
                                <?php foreach ($innovation_types as $it): ?>
                                    <option value="<?= htmlspecialchars($it['inno_type_id']) ?>"><?= htmlspecialchars($it['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="requirement_id" class="form-label">Requirement</label>
                            <select class="form-select" id="requirement_id" name="requirement_id" required>
                                <option value="">Select Requirement</option>
                                <?php foreach ($requirements_master as $rm): ?>
                                    <option value="<?= htmlspecialchars($rm['requirement_id']) ?>"><?= htmlspecialchars($rm['requirement_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="is_mandatory_add" name="is_mandatory">
                            <label class="form-check-label" for="is_mandatory_add">
                                Is Mandatory
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="order_sequence" class="form-label">Order Sequence</label>
                            <input type="number" class="form-control" id="order_sequence" name="order_sequence" value="0" min="0" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Link</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Existing Innovation Type Requirements Links</h5>
                    <?php if (empty($grouped_inno_type_requirements)): ?>
                        <p class="text-muted">No innovation type requirements links found.</p>
                    <?php else: ?>
                        <?php foreach ($grouped_inno_type_requirements as $inno_type_name => $requirements_for_type): ?>
                            <div class="mb-4 p-3 border rounded bg-light">
                                <h6 class="mb-3">Innovation Type: <strong><?= htmlspecialchars($inno_type_name) ?></strong></h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th>Link ID</th>
                                                <th>Requirement</th>
                                                <th>Mandatory</th>
                                                <th>Order</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($requirements_for_type as $itr): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($itr['inno_type_req_id']) ?></td>
                                                    <td><?= htmlspecialchars($itr['master_requirement_name']) ?></td>
                                                    <td><?= $itr['is_mandatory'] ? 'Yes' : 'No' ?></td>
                                                    <td><?= htmlspecialchars($itr['order_sequence']) ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-warning btn-sm edit-btn"
                                                                data-bs-toggle="modal" data-bs-target="#editModal"
                                                                data-id="<?= htmlspecialchars($itr['inno_type_req_id']) ?>"
                                                                data-inno-type-id="<?= htmlspecialchars($itr['inno_type_id']) ?>"
                                                                data-requirement-id="<?= htmlspecialchars($itr['requirement_id']) ?>"
                                                                data-mandatory="<?= htmlspecialchars($itr['is_mandatory']) ?>"
                                                                data-order="<?= htmlspecialchars($itr['order_sequence']) ?>">
                                                            Edit
                                                        </button>
                                                        <form action="manage_inno_type_requirements.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this link?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="inno_type_req_id" value="<?= htmlspecialchars($itr['inno_type_req_id']) ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Innovation Type Requirement Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="manage_inno_type_requirements.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="inno_type_req_id" id="edit_inno_type_req_id">
                        <div class="mb-3">
                            <label for="edit_inno_type_id" class="form-label">Innovation Type</label>
                            <select class="form-select" id="edit_inno_type_id" name="inno_type_id" required>
                                <?php foreach ($innovation_types as $it): ?>
                                    <option value="<?= htmlspecialchars($it['inno_type_id']) ?>"><?= htmlspecialchars($it['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_requirement_id" class="form-label">Requirement</label>
                            <select class="form-select" id="edit_requirement_id" name="requirement_id" required>
                                <?php foreach ($requirements_master as $rm): ?>
                                    <option value="<?= htmlspecialchars($rm['requirement_id']) ?>"><?= htmlspecialchars($rm['requirement_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="edit_is_mandatory" name="is_mandatory">
                            <label class="form-check-label" for="edit_is_mandatory">
                                Is Mandatory
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="edit_order_sequence" class="form-label">Order Sequence</label>
                            <input type="number" class="form-control" id="edit_order_sequence" name="order_sequence" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 for the dropdowns in the "Add New" form
            $('#inno_type_id').select2({
                theme: "bootstrap-5",
                dropdownParent: $('#inno_type_id').parent() // Ensures dropdown is correctly positioned
            });
            $('#requirement_id').select2({
                theme: "bootstrap-5",
                dropdownParent: $('#requirement_id').parent()
            });

            var editModal = document.getElementById('editModal');
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget; // Button that triggered the modal
                var id = button.getAttribute('data-id');
                var innoTypeId = button.getAttribute('data-inno-type-id');
                var requirementId = button.getAttribute('data-requirement-id');
                var mandatory = button.getAttribute('data-mandatory');
                var order = button.getAttribute('data-order');

                var modalId = editModal.querySelector('#edit_inno_type_req_id');
                var modalInnoTypeId = editModal.querySelector('#edit_inno_type_id');
                var modalRequirementId = editModal.querySelector('#edit_requirement_id');
                var modalIsMandatory = editModal.querySelector('#edit_is_mandatory');
                var modalOrderSequence = editModal.querySelector('#edit_order_sequence');

                // Set values for the modal's form fields
                modalId.value = id;
                modalOrderSequence.value = order;
                modalIsMandatory.checked = (mandatory === '1');

                // Set selected values for Select2 dropdowns
                // Ensure Select2 is initialized before setting values
                // and use .val().trigger('change') for Select2 to update its display
                $(modalInnoTypeId).val(innoTypeId).trigger('change');
                $(modalRequirementId).val(requirementId).trigger('change');

                // Initialize Select2 for modal dropdowns if not already initialized
                // Ensure dropdownParent is set correctly for modals
                $('#edit_inno_type_id').select2({
                    theme: "bootstrap-5",
                    dropdownParent: $('#editModal') // Attach dropdown to the modal
                });
                $('#edit_requirement_id').select2({
                    theme: "bootstrap-5",
                    dropdownParent: $('#editModal') // Attach dropdown to the modal
                });
            });

            // Destroy Select2 instances when modal is hidden to prevent duplicates on re-open
            editModal.addEventListener('hidden.bs.modal', function (event) {
                $('#edit_inno_type_id').select2('destroy');
                $('#edit_requirement_id').select2('destroy');
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    try {
        $conn->close();
    } catch (Throwable $e) {
        error_log("manage_inno_type_requirements.php: Error closing MySQLi connection at end of script: " . $e->getMessage());
    }
} else {
    error_log("manage_inno_type_requirements.php: MySQLi connection object is not set or not a mysqli instance at end of script. Not attempting to close.");
}
?>
