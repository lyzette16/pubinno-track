-- Create the database
CREATE DATABASE IF NOT EXISTS pub_inno_tracking;
USE pub_inno_tracking;

-- Table: departments
CREATE TABLE IF NOT EXISTS departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- Sample Departments
INSERT INTO departments (name) VALUES 
('College of Information Systems'),
('College of Engineering'),
('College of Science');

-- Table: users
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT,
    role ENUM('researcher', 'facilitator', 'pio', 'external') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
);

-- Sample Users
INSERT INTO users (department_id, role, name, email, password) VALUES
(1, 'pio', 'PIO Admin', 'pio@university.edu', 'password123'),
(1, 'facilitator', 'Facilitator CIS', 'fac_cis@university.edu', 'password123'),
(2, 'facilitator', 'Facilitator Engineering', 'fac_eng@university.edu', 'password123'),
(1, 'researcher', 'Alice Researcher', 'alice@university.edu', 'password123'),
(1, 'researcher', 'Bob Faculty', 'bob@university.edu', 'password123');

-- Table: submissions
CREATE TABLE IF NOT EXISTS submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    researcher_id INT NOT NULL,
    facilitator_id INT,
    department_id INT NOT NULL,
    submission_type ENUM('publication', 'innovation') NOT NULL,
    title VARCHAR(255) NOT NULL,
    abstract TEXT,
    file_path VARCHAR(255),
    reference_number VARCHAR(50) UNIQUE,
    status ENUM(
        'draft', 'submitted', 'with_facilitator', 'forwarded_to_pio',
        'under_external_review', 'approved', 'rejected', 'completed'
    ) DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (researcher_id) REFERENCES users(user_id),
    FOREIGN KEY (facilitator_id) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
);

-- Table: submission_status_logs
CREATE TABLE IF NOT EXISTS submission_status_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (changed_by) REFERENCES users(user_id)
);

-- Table: comments
CREATE TABLE IF NOT EXISTS comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    commented_by INT NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (commented_by) REFERENCES users(user_id)
);

-- Table: external_offices
CREATE TABLE IF NOT EXISTS external_offices (
    office_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sequence_order INT UNIQUE NOT NULL
);

-- Sample External Offices
INSERT INTO external_offices (name, sequence_order) VALUES
('Ethics Review Board', 1),
('Innovation Screening Board', 2),
('URDI (Final Approval)', 3);

-- Table: external_reviews
CREATE TABLE IF NOT EXISTS external_reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    office_id INT NOT NULL,
    reviewed_by INT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    feedback TEXT,
    reviewed_at DATETIME,
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (office_id) REFERENCES external_offices(office_id),
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
);

-- Sample External Reviewers
INSERT INTO users (role, name, email, password, department_id) VALUES
('external', 'Reviewer A', 'reviewer1@external.com', 'password123', NULL),
('external', 'Reviewer B', 'reviewer2@external.com', 'password123', NULL),
('external', 'Reviewer URDI', 'urdi@external.com', 'password123', NULL);

-- Table: audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    submission_id INT,
    action VARCHAR(255) NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id)
);

-- Table: notifications
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    submission_id INT,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id)
);
