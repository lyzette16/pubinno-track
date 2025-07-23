-- SQL Script to Create the pub_inno_tracking Database and Tables
-- This script includes:
-- 1. Database Creation
-- 2. Table Creations with all specified columns, primary keys, and data types
-- 3. Foreign Key Constraints to maintain referential integrity
-- 4. Default Values and Nullability as per your DESCRIBE outputs
-- 5. Updated Sample Data for DMMMSU Campuses (Campus, Departments, Users, External Offices)

-- -----------------------------------------------------
-- Schema pub_inno_tracking
-- -----------------------------------------------------
CREATE DATABASE IF NOT EXISTS `pub_inno_tracking` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pub_inno_tracking`;

-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`campus`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`campus` (
  `campus_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_name` VARCHAR(100) NOT NULL UNIQUE,
  `campus_status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campus_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`departments`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`departments` (
  `department_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_id` INT(11) NOT NULL, -- Foreign Key to campus
  `name` VARCHAR(100) NOT NULL UNIQUE,
  PRIMARY KEY (`department_id`),
  INDEX `fk_department_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_department_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pub_inno_tracking`.`campus` (`campus_id`)
    ON DELETE RESTRICT -- Prevent deleting campus if departments are linked
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`users`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`users` (
  `user_id` INT(11) NOT NULL AUTO_INCREMENT,
  `department_id` INT(11) DEFAULT NULL,
  `campus_id` INT(11) DEFAULT NULL, -- Foreign Key to campus (can be NULL for external users not tied to a specific campus)
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
    REFERENCES `pub_inno_tracking`.`departments` (`department_id`)
    ON DELETE SET NULL -- If a department is deleted, users from that department will have department_id set to NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_user_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pub_inno_tracking`.`campus` (`campus_id`)
    ON DELETE SET NULL -- If a campus is deleted, users from that campus will have campus_id set to NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`submissions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`submissions` (
  `submission_id` INT(11) NOT NULL AUTO_INCREMENT,
  `researcher_id` INT(11) NOT NULL,
  `facilitator_id` INT(11) DEFAULT NULL, -- Can be NULL until assigned/accepted by a facilitator
  `department_id` INT(11) NOT NULL,
  `campus_id` INT(11) NOT NULL, -- Foreign Key to campus
  `submission_type` ENUM('publication', 'innovation') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `abstract` TEXT DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
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
  `submission_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- User-provided or system-generated submission date
  PRIMARY KEY (`submission_id`),
  INDEX `fk_submission_researcher_idx` (`researcher_id` ASC),
  INDEX `fk_submission_facilitator_idx` (`facilitator_id` ASC),
  INDEX `fk_submission_department_idx` (`department_id` ASC),
  INDEX `fk_submission_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_submission_researcher`
    FOREIGN KEY (`researcher_id`)
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE RESTRICT -- Do not delete user if they have submissions
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_facilitator`
    FOREIGN KEY (`facilitator_id`)
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE SET NULL -- If a facilitator is deleted, their facilitator_id on submissions is set to NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_department`
    FOREIGN KEY (`department_id`)
    REFERENCES `pub_inno_tracking`.`departments` (`department_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_submission_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pub_inno_tracking`.`campus` (`campus_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`submission_status_logs`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`submission_status_logs` (
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
    REFERENCES `pub_inno_tracking`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_log_changed_by`
    FOREIGN KEY (`changed_by`)
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`comments`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`comments` (
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
    REFERENCES `pub_inno_tracking`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_comment_commented_by`
    FOREIGN KEY (`commented_by`)
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`external_offices`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`external_offices` (
  `office_id` INT(11) NOT NULL AUTO_INCREMENT,
  `campus_id` INT(11) DEFAULT NULL, -- Foreign Key to campus (nullable if office serves all campuses)
  `name` VARCHAR(100) NOT NULL,
  `sequence_order` INT(11) NOT NULL UNIQUE,
  PRIMARY KEY (`office_id`),
  INDEX `fk_external_office_campus_idx` (`campus_id` ASC),
  CONSTRAINT `fk_external_office_campus`
    FOREIGN KEY (`campus_id`)
    REFERENCES `pub_inno_tracking`.`campus` (`campus_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`external_reviews`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`external_reviews` (
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
    REFERENCES `pub_inno_tracking`.`submissions` (`submission_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_review_office`
    FOREIGN KEY (`office_id`)
    REFERENCES `pub_inno_tracking`.`external_offices` (`office_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_review_reviewed_by`
    FOREIGN KEY (`reviewed_by`)
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`audit_logs`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`audit_logs` (
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
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pub_inno_tracking`.`submissions` (`submission_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `pub_inno_tracking`.`notifications`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pub_inno_tracking`.`notifications` (
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
    REFERENCES `pub_inno_tracking`.`users` (`user_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_notification_submission`
    FOREIGN KEY (`submission_id`)
    REFERENCES `pub_inno_tracking`.`submissions` (`submission_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Sample Data (Updated for DMMMSU Campuses)
-- -----------------------------------------------------

-- Clear existing data if necessary (USE WITH CAUTION IN PRODUCTION!)
-- SET FOREIGN_KEY_CHECKS = 0;
-- TRUNCATE TABLE notifications;
-- TRUNCATE TABLE audit_logs;
-- TRUNCATE TABLE external_reviews;
-- TRUNCATE TABLE external_offices;
-- TRUNCATE TABLE comments;
-- TRUNCATE TABLE submission_status_logs;
-- TRUNCATE TABLE submissions;
-- TRUNCATE TABLE users;
-- TRUNCATE TABLE departments;
-- TRUNCATE TABLE campus;
-- SET FOREIGN_KEY_CHECKS = 1;


-- Insert Sample Campuses for DMMMSU
INSERT INTO `campus` (`campus_name`, `campus_status`) VALUES
('DMMMSU NLUC (North La Union Campus)', 'active'),
('DMMMSU MLUC (Mid La Union Campus)', 'active'),
('DMMMSU SLUC (South La Union Campus)', 'active'),
('DMMMSU Open University System (DOUS)', 'active'); -- Added for external roles/central services


-- Insert Sample Departments (now linked to DMMMSU campus_id)
INSERT INTO `departments` (`campus_id`, `name`) VALUES
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'College of Information Systems - NLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'College of Engineering - NLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU MLUC (Mid La Union Campus)'), 'College of Science - MLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'College of Arts and Sciences - SLUC'),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU SLUC (South La Union Campus)'), 'College of Business Administration - SLUC');


-- Insert Sample Users (now linked to department_id and campus_id for DMMMSU)
-- Note: Passwords are 'password123' for demonstration.
-- You MUST hash passwords in a production environment!
INSERT INTO `users` (`department_id`, `campus_id`, `role`, `name`, `email`, `password`) VALUES
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
INSERT INTO `external_offices` (`campus_id`, `name`, `sequence_order`) VALUES
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'Ethics Review Board - NLUC', 1),
((SELECT campus_id FROM campus WHERE campus_name = 'DMMMSU NLUC (North La Union Campus)'), 'Innovation Screening Board - NLUC', 2),
(NULL, 'University Research & Dev. Institute (URDI) - Central', 3); -- Central URDI for all campuses

-- No sample data for `submissions`, `submission_status_logs`, `comments`, `external_reviews`, `audit_logs`, `notifications`
-- as these tables are typically populated by the application's ongoing usage.