-- SQL Script Portion 1: Database Creation and Campus Table

-- -----------------------------------------------------
-- Schema pubino_v3
-- -----------------------------------------------------
DROP DATABASE IF EXISTS `pubino_v3`; -- Explicitly drop if exists to ensure a clean start
CREATE DATABASE IF NOT EXISTS `pubino_v3` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pubino_v3`;

-- -----------------------------------------------------
-- Table `pubino_v3`.`campus`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`campus` (
  `campus_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_name` VARCHAR(100) NOT NULL UNIQUE,
  `campus_status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campus_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- SQL Script Portion 2: Departments and Users Tables

USE `pubino_v3`; -- Ensure we are using the correct database

-- -----------------------------------------------------
-- Table `pubino_v3`.`departments`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`departments` (
  `department_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_id` INT(11) NOT NULL, -- Added FK
  `name` VARCHAR(100) NOT NULL UNIQUE,
  PRIMARY KEY (`department_id`),
  INDEX `fk_department_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_department_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pubino_v3`.`campus` (`campus_id`)
    ON DELETE RESTRICT -- Prevent deleting campus if departments are linked
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`users`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`users` (
  `user_id` INT(11) NOT NULL AUTO_INCREMENT,
  `department_id` INT(11) DEFAULT NULL,
  `campus_id` INT(11) DEFAULT NULL, -- Added FK
  `role` ENUM('researcher', 'facilitator', 'pio', 'external') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL, -- Remember to hash passwords in production!
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  INDEX `fk_user_department_idx` (`department_id` ASC),
  INDEX `fk_user_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_user_department`
    FOREIGN KEY (`department_id`)
    REFERENCES `pubino_v3`.`departments` (`department_id`)
    ON DELETE SET NULL -- If a department is deleted, users from that department will have department_id set to NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_user_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pubino_v3`.`campus` (`campus_id`)
    ON DELETE SET NULL -- If a campus is deleted, users from that campus will have campus_id set to NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- SQL Script Portion 3: Publication Types and Submissions Tables

USE `pubino_v3`; -- Ensure we are using the correct database

-- -----------------------------------------------------
-- Table `pubino_v3`.`publication_types`
-- Description: Stores the specific types of publications (e.g., "Checklist for Publication Clearance").
-- This acts as the bridge between the general 'publication' type in `submissions` and the detailed requirements.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`publication_types` (
  `pub_type_id` INT(11) NOT NULL AUTO_INCREMENT,
  `type_name` VARCHAR(255) NOT NULL UNIQUE, -- e.g., "Checklist for Publication Clearance"
  `submission_category` ENUM('publication', 'innovation') NOT NULL, -- To link to general submission type
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1, -- To enable/disable publication types
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pub_type_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `pubino_v3`.`submissions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`submissions` (
  `submission_id` INT(11) NOT NULL AUTO_INCREMENT,
  `researcher_id` INT(11) NOT NULL,
  `facilitator_id` INT(11) DEFAULT NULL,
  `department_id` INT(11) NOT NULL,
  `campus_id` INT(11) NOT NULL,
  `pub_type_id` INT(11) DEFAULT NULL, -- NEW: Links to specific publication type checklist
  `submission_type` ENUM('publication', 'innovation') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `abstract` TEXT DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL, -- This might become redundant if all files are in submission_files
  `reference_number` VARCHAR(50) UNIQUE DEFAULT NULL,
  `status` ENUM(
    'draft',
    'submitted',
    'with_facilitator',
    'accepted_by_facilitator',
    'forwarded_to_pio',
    'accepted_by_pio',
    'under_external_review',
    'approved',
    'rejected',
    'completed'
  ) NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submission_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`submission_id`),
  INDEX `fk_submission_researcher_idx` (`researcher_id` ASC),
  INDEX `fk_submission_facilitator_idx` (`facilitator_id` ASC), -- FIXED: Changed 'facilitator' to 'facilitator_id'
  INDEX `fk_submission_department_idx` (`department_id` ASC),
  INDEX `fk_submission_campus_idx` (`campus_id` ASC),
  INDEX `fk_submission_pub_type_idx` (`pub_type_id` ASC), -- NEW INDEX
  CONSTRAINT `fk_submission_researcher`
    FOREIGN KEY (`researcher_id`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_facilitator`
    FOREIGN KEY (`facilitator_id`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_department`
    FOREIGN KEY (`department_id`)
    REFERENCES `pubino_v3`.`departments` (`department_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pubino_v3`.`campus` (`campus_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_pub_type`
    FOREIGN KEY (`pub_type_id`)
    REFERENCES `pubino_v3`.`publication_types` (`pub_type_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- SQL Script Portion 4: Remaining Tables

USE `pubino_v3`; -- Ensure we are using the correct database

-- -----------------------------------------------------
-- Table `pubino_v3`.`submission_status_logs`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`submission_status_logs` (
  `log_id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `changed_by` INT(11) NOT NULL,
  `old_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  INDEX `fk_log_submission_idx` (`submission_id` ASC),
  INDEX `fk_log_changed_by_idx` (`changed_by` ASC),
  CONSTRAINT `fk_log_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_log_changed_by`
    FOREIGN KEY (`changed_by`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`comments`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`comments` (
  `comment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `commented_by` INT(11) NOT NULL,
  `comment_text` TEXT NOT NULL,
  `comment_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment_type` VARCHAR(50) NOT NULL DEFAULT 'facilitator_comment',
  PRIMARY KEY (`comment_id`),
  INDEX `fk_comment_submission_idx` (`submission_id` ASC),
  INDEX `fk_comment_commented_by_idx` (`commented_by` ASC),
  CONSTRAINT `fk_comment_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_comment_commented_by`
    FOREIGN KEY (`commented_by`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`external_offices`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`external_offices` (
  `office_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_id` INT(11) DEFAULT NULL, -- Foreign Key to campus (nullable if office serves all campuses)
  `name` VARCHAR(100) NOT NULL,
  `sequence_order` INT(11) NOT NULL UNIQUE,
  PRIMARY KEY (`office_id`),
  INDEX `fk_external_office_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_external_office_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pubino_v3`.`campus` (`campus_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`external_reviews`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`external_reviews` (
  `review_id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `office_id` INT(11) NOT NULL,
  `reviewed_by` INT(11) DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `feedback` TEXT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`review_id`),
  INDEX `fk_review_submission_idx` (`submission_id` ASC),
  INDEX `fk_review_office_idx` (`office_id` ASC),
  INDEX `fk_review_reviewed_by_idx` (`reviewed_by` ASC),
  CONSTRAINT `fk_review_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_review_office`
    FOREIGN KEY (`office_id`)
    REFERENCES `pubino_v3`.`external_offices` (`office_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_review_reviewed_by`
    FOREIGN KEY (`reviewed_by`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`audit_logs`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`audit_logs` (
  `audit_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `submission_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  INDEX `fk_audit_user_idx` (`user_id` ASC),
  INDEX `fk_audit_submission_idx` (`submission_id` ASC),
  CONSTRAINT `fk_audit_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`notifications`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`notifications` (
  `notification_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `submission_id` INT(11) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  INDEX `fk_notification_user_idx` (`user_id` ASC),
  INDEX `fk_notification_submission_idx` (`submission_id` ASC),
  CONSTRAINT `fk_notification_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_notification_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`requirements_master`
-- Description: Stores the master list of all possible individual requirements.
-- This is where "Endorsement Form (F001)", "Full Copy of IMRAD Article" are defined once.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`requirements_master` (
  `requirement_id` INT(11) NOT NULL AUTO_INCREMENT,
  `requirement_name` VARCHAR(255) NOT NULL UNIQUE, -- e.g., "Endorsement Form (F001)"
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`requirement_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`pub_type_requirements`
-- Description: Junction table linking `publication_types` to `requirements_master`.
-- This defines which specific requirements belong to each publication type checklist.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`pub_type_requirements` (
  `pub_type_req_id` INT(11) NOT NULL AUTO_INCREMENT,
  `pub_type_id` INT(11) NOT NULL,
  `requirement_id` INT(11) NOT NULL,
  `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1, -- Is this requirement mandatory for this type?
  `order_sequence` INT(11) DEFAULT NULL, -- To order requirements within a checklist
  PRIMARY KEY (`pub_type_req_id`),
  UNIQUE INDEX `idx_pub_type_req_unique` (`pub_type_id`, `requirement_id`), -- A requirement can only be listed once per pub type
  INDEX `fk_ptr_requirement_idx` (`requirement_id` ASC),
  CONSTRAINT `fk_ptr_pub_type`
    FOREIGN KEY (`pub_type_id`)
    REFERENCES `pubino_v3`.`publication_types` (`pub_type_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_ptr_requirement`
    FOREIGN KEY (`requirement_id`)
    REFERENCES `pubino_v3`.`requirements_master` (`requirement_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pubino_v3`.`submission_files`
-- Description: Stores the actual files uploaded by users for a specific submission.
-- Linked to a submission and the specific requirement it fulfills.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pubino_v3`.`submission_files` (
  `file_id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `requirement_id` INT(11) NOT NULL, -- Which specific requirement this file fulfills
  `uploaded_by_user_id` INT(11) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(512) NOT NULL, -- Path to where the file is stored (e.g., on server)
  `file_mime_type` VARCHAR(100) DEFAULT NULL,
  `file_size_bytes` BIGINT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_approved` TINYINT(1) DEFAULT 0, -- To track if this file has been approved by a facilitator/PIO
  PRIMARY KEY (`file_id`),
  UNIQUE INDEX `idx_submission_req_file_unique` (`submission_id`, `requirement_id`), -- One file per requirement per submission
  INDEX `fk_sf_submission_idx` (`submission_id` ASC),
  INDEX `fk_sf_requirement_idx` (`requirement_id` ASC),
  INDEX `fk_sf_uploaded_by_idx` (`uploaded_by_user_id` ASC),
  CONSTRAINT `fk_sf_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pubino_v3`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_sf_requirement`
    FOREIGN KEY (`requirement_id`)
    REFERENCES `pubino_v3`.`requirements_master` (`requirement_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_sf_uploaded_by`
    FOREIGN KEY (`uploaded_by_user_id`)
    REFERENCES `pubino_v3`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- SQL Script Portion 5: Sample Data (TRUNCATE statements removed, requirement_name fixed, INSERT IGNORE added)

USE `pubino_v3`; -- Ensure we are using the correct database

-- -----------------------------------------------------
-- Sample Data
-- -----------------------------------------------------

-- Temporarily disable FK checks to allow inserts to proceed smoothly
SET FOREIGN_KEY_CHECKS = 0;

-- Removed all TRUNCATE TABLE statements as per your request.
-- This script will now only insert data assuming tables are empty.
-- INSERT IGNORE is used for all inserts to prevent duplicate entry errors on re-execution.


-- Insert Sample Campuses for DMMMSU
INSERT IGNORE INTO `campus` (`campus_name`, `campus_status`) VALUES
('DMMMSU NLUC (North La Union Campus)', 'active'),
('DMMMSU MLUC (Mid La Union Campus)', 'active'),
('DMMMSU SLUC (South La Union Campus)', 'active'),
('DMMMSU Open University System (DOUS)', 'active');


-- Insert Sample Departments (now linked to DMMMSU campus_id)
INSERT IGNORE INTO `departments` (`campus_id`, `name`) VALUES
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'College of Information Systems - NLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'College of Engineering - NLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU MLUC (Mid La Union Campus)'), 'College of Science - MLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'College of Arts and Sciences - SLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'College of Business Administration - SLUC');


-- Insert Sample Users (now linked to department_id and campus_id for DMMMSU)
INSERT IGNORE INTO `users` (`department_id`, `campus_id`, `role`, `name`, `email`, `password`) VALUES
-- NLUC Users
((SELECT department_id FROM departments WHERE name = 'College of Information Systems - NLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'pio', 'PIO Admin - NLUC', 'pio.nluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Information Systems - NLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'facilitator', 'Facilitator CIS - NLUC', 'fac_cis.nluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Engineering - NLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'facilitator', 'Facilitator Engineering - NLUC', 'fac_eng.nluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Information Systems - NLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'researcher', 'Alice Researcher - NLUC', 'alice.nluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Engineering - NLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'researcher', 'Bob Faculty - NLUC', 'bob.nluc@dmmmsu.edu.ph', 'password123'),

-- MLUC Users
((SELECT department_id FROM departments WHERE name = 'College of Science - MLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU MLUC (Mid La Union Campus)'), 'facilitator', 'Facilitator Science - MLUC', 'fac_sci.mluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Science - MLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU MLUC (Mid La Union Campus)'), 'researcher', 'Charlie Innovator - MLUC', 'charlie.mluc@dmmmsu.edu.ph', 'password123'),

-- SLUC Users
((SELECT department_id FROM departments WHERE name = 'College of Arts and Sciences - SLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'facilitator', 'Facilitator Arts - SLUC', 'fac_arts.sluc@dmmmsu.edu.ph', 'password123'),
((SELECT department_id FROM departments WHERE name = 'College of Business Administration - SLUC'), (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'researcher', 'Dana Marketer - SLUC', 'dana.sluc@dmmmsu.edu.ph', 'password123'),

-- External Reviewers (linked to DMMMSU Open University System for central management if applicable)
(NULL, (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU Open University System (DOUS)'), 'external', 'External Reviewer 1', 'reviewer1@external.com', 'password123'),
(NULL, (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU Open University System (DOUS)'), 'external', 'External Reviewer 2', 'reviewer2@external.com', 'password123'),
(NULL, (SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU Open University System (DOUS)'), 'external', 'URDI Reviewer', 'urdi@dmmmsu.edu.ph', 'password123');


-- Insert Sample External Offices (can be linked to a primary campus or NULL for central)
INSERT IGNORE INTO `external_offices` (`campus_id`, `name`, `sequence_order`) VALUES
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'Ethics Review Board - NLUC', 1),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'Innovation Screening Board - NLUC', 2),
(NULL, 'University Research & Dev. Institute (URDI) - Central', 3);


-- Insert Master Requirements for Publications
INSERT IGNORE INTO `requirements_master` (`requirement_name`) VALUES
('Endorsement Form (F001)'),
('Request Form (F005)'),
('TR Clearance Form (RES F017)'),
('Publication Clearance Form (F004)'),
('Full Copy of IMRAD Article'),
('JA Publication Form (F016)'),
('Proof of Scopus / WoS / ACI Indexing'),
('Thesis Title, Approval Sheet & Abstract'),
('Request Letter (Intent to Publish)'),
('Notarized Agreement Form (F018a)'),
('Notarized Agreement Form (F018b)'),
('Certificate of Research Authenticity (F019)'),
('MOA or President’s Approval'),
('Notice of Acceptance'),
('Full Copy of Submitted Manuscript'),
('Proof of Inclusion in the WFP'),
('Title Page, Approval Sheet, Abstract (for Thesis/Dissertation)'),
('ORIGINAL INVOICE'),
('Copy of Article Published'),
('TR Title Page & Abstract'),
('ORIGINAL OFFICIAL RECEIPT'),
('Amount to be reimbursed in peso'),
('Copy of Cited and Citing Article'),
('Proof of Scopus / WoS / ACI for citing and cited article'),
('Invitation as Peer Reviewer'),
('Proof of Scopus / WoS / ACI of Journal requesting the service'),
('IEC Material (printed)'),
('IEC Material (soft-copy) email to rne.iec.publication@dmmmsu.edu.ph'),
('Certification Form (F013)'),
('IEC Evaluation Form (F017)'),
('Request Form (F004)');


-- Insert Publication Types
INSERT IGNORE INTO `publication_types` (`type_name`, `submission_category`) VALUES
('Checklist for Publication Clearance', 'publication'),
('Checklist for JA Self-Submission', 'publication'),
('Checklist for JA Office-Assisted Submission', 'publication'),
('Checklist for Publication of Thesis / Dissertation', 'publication'),
('Checklist for Publication of Outside-Conducted Thesis / Dissertation', 'publication'),
('Checklist for Publication of Other Student Research Output', 'publication'),
('Checklist for Publication of Externally Funded Collaborative Research', 'publication'),
('Checklist for Publication Fee Assistance', 'publication'),
('Checklist for Reimbursement', 'publication'),
('Checklist for Publication Cash Awards', 'publication'),
('Checklist for Citation Cash Awards', 'publication'),
('Checklist for Authorization as Peer Reviewer', 'publication'),
('Checklist for Certification for IEC Materials Developed', 'publication');


-- Link Publication Types to their Requirements (pub_type_requirements)
-- All are assumed mandatory (is_mandatory = 1) unless specified otherwise.
-- Order sequence is arbitrary for now, can be adjusted if needed for UI presentation.

-- 1. Checklist for Publication Clearance
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'TR Clearance Form (RES F017)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Clearance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 6);

-- 2. Checklist for JA Self-Submission
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Self-Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6);

-- 3. Checklist for JA Office-Assisted Submission
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Office-Assisted Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Office-Assisted Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Office-Assisted Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for JA Office-Assisted Submission'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4);

-- 4. Checklist for Publication of Thesis / Dissertation
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Thesis Title, Approval Sheet & Abstract'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Letter (Intent to Publish)'), 1, 8),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Notarized Agreement Form (F018a)'), 1, 9);

-- 5. Checklist for Publication of Outside-Conducted Thesis / Dissertation
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Thesis Title, Approval Sheet & Abstract'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Letter (Intent to Publish)'), 1, 8),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Outside-Conducted Thesis / Dissertation'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Notarized Agreement Form (F018a)'), 1, 9);

-- 6. Checklist for Publication of Other Student Research Output
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Certificate of Research Authenticity (F019)'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Letter (Intent to Publish)'), 1, 8),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Other Student Research Output'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Notarized Agreement Form (F018a)'), 1, 9);

-- 7. Checklist for Publication of Externally Funded Collaborative Research
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of IMRAD Article'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'MOA or President’s Approval'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Letter (Intent to Publish)'), 1, 8),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication of Externally Funded Collaborative Research'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Notarized Agreement Form (F018b)'), 1, 9);

-- 8. Checklist for Publication Fee Assistance
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F004)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Full Copy of Submitted Manuscript'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Notice of Acceptance'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Inclusion in the WFP'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Title Page, Approval Sheet, Abstract (for Thesis/Dissertation)'), 0, 8),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Fee Assistance'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'ORIGINAL INVOICE'), 1, 9);

-- 9. Checklist for Reimbursement
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Copy of Article Published'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'ORIGINAL OFFICIAL RECEIPT'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Reimbursement'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Amount to be reimbursed in peso'), 1, 8);

-- 10. Checklist for Publication Cash Awards
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Copy of Article Published'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'TR Clearance Form (RES F017)'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'TR Title Page & Abstract'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 7),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Publication Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI Indexing'), 1, 8); -- FIX: Changed 'Proof of Scopus / WoS / ACI' to 'Proof of Scopus / WoS / ACI Indexing'

-- 11. Checklist for Citation Cash Awards
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Copy of Cited and Citing Article'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'TR Clearance Form (RES F017)'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Publication Clearance Form (F004)'), 1, 5),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'JA Publication Form (F016)'), 1, 6),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Citation Cash Awards'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI for citing and cited article'), 1, 7);

-- 12. Checklist for Authorization as Peer Reviewer
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Authorization as Peer Reviewer'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Authorization as Peer Reviewer'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Request Form (F005)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Authorization as Peer Reviewer'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Invitation as Peer Reviewer'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Authorization as Peer Reviewer'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Proof of Scopus / WoS / ACI of Journal requesting the service'), 1, 4);

-- 13. Checklist for Certification for IEC Materials Developed
INSERT IGNORE INTO `pub_type_requirements` (`pub_type_id`, `requirement_id`, `is_mandatory`, `order_sequence`) VALUES
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Certification for IEC Materials Developed'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Endorsement Form (F001)'), 1, 1),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Certification for IEC Materials Developed'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'IEC Material (printed)'), 1, 2),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Certification for IEC Materials Developed'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'IEC Material (soft-copy) email to rne.iec.publication@dmmmsu.edu.ph'), 1, 3),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Certification for IEC Materials Developed'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'Certification Form (F013)'), 1, 4),
((SELECT pub_type_id FROM publication_types WHERE type_name = 'Checklist for Certification for IEC Materials Developed'), (SELECT requirement_id FROM requirements_master WHERE requirement_name = 'IEC Evaluation Form (F017)'), 1, 5);

-- Re-enable FK checks
SET FOREIGN_KEY_CHECKS = 1;