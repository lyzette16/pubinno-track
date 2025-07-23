-- Create the database if it doesn't already exist
CREATE DATABASE IF NOT EXISTS pub_inno_tracking;

-- Use the newly created or existing database
USE pub_inno_tracking;

-- Table: departments
-- Observation: The image shows 'department_name' but the previous CREATE TABLE had 'name'.
-- I'll use 'name' as it's more common and aligns with the previous CREATE. If you intend 'department_name', please let me know.
CREATE TABLE IF NOT EXISTS departments (
    department_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE -- Added UNIQUE constraint as department names should be unique
);

-- Table: users
CREATE TABLE IF NOT EXISTS users (
    user_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    department_id INT(11) DEFAULT NULL, -- Can be NULL for roles like 'external'
    role ENUM('researcher', 'facilitator', 'pio', 'external') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
);

-- Table: submissions
-- Observations:
-- - 'description' is TEXT and NOT NULL here, but was nullable in a previous CREATE. Stick with NOT NULL as per image.
-- - 'submission_date' is explicitly present and NOT NULL, defaulting to CURRENT_TIMESTAMP in the image,
--   while 'created_at' is also there. This implies 'submission_date' might be the user-provided date,
--   and 'created_at' is the system timestamp. I'll include both as per the image.
-- - 'status' ENUM values are now much more detailed, including 'accepted_by_facilitator' and 'accepted_by_pio' which are good additions.
CREATE TABLE IF NOT EXISTS submissions (
    submission_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    researcher_id INT(11) NOT NULL,
    facilitator_id INT(11) DEFAULT NULL, -- Can be NULL until assigned/accepted by a facilitator
    department_id INT(11) NOT NULL,
    submission_type ENUM('publication', 'innovation') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL, -- As per image
    abstract TEXT DEFAULT NULL, -- As per previous CREATE (not in DESCRIBE image, but common)
    file_path VARCHAR(255) DEFAULT NULL, -- As per image (Nullable)
    reference_number VARCHAR(50) UNIQUE DEFAULT NULL, -- As per image (Nullable)
    status ENUM(
        'draft',
        'submitted',
        'with_facilitator',
        'accepted_by_facilitator', -- New from image
        'forwarded_to_pio',
        'accepted_by_pio',          -- New from image
        'under_external_review',
        'approved',
        'rejected',
        'completed'
    ) DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, -- As per image
    FOREIGN KEY (researcher_id) REFERENCES users(user_id),
    FOREIGN KEY (facilitator_id) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
);


-- Table: submission_status_logs
-- Observation: 'old_status' and 'new_status' are VARCHAR(50) and nullable.
CREATE TABLE IF NOT EXISTS submission_status_logs (
    log_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    submission_id INT(11) NOT NULL,
    changed_by INT(11) NOT NULL,
    old_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (changed_by) REFERENCES users(user_id)
);

-- Table: comments (renamed from submission_comments based on image_789364.png)
-- Observations:
-- - Table name is 'comments' in image_789364.png, not 'submission_comments'.
-- - Column names are 'commented_by' and 'comment_text', not 'user_id' and 'comment'.
-- - 'comment_type' is new, VARCHAR(50) DEFAULT 'facilitator_comment'.
-- - 'created_at' column is named 'comment_date' in the image.
CREATE TABLE IF NOT EXISTS comments (
    comment_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    submission_id INT(11) NOT NULL,
    commented_by INT(11) NOT NULL, -- Renamed from user_id as per image
    comment_text TEXT NOT NULL,    -- Renamed from comment as per image
    comment_date DATETIME DEFAULT CURRENT_TIMESTAMP, -- Renamed from created_at as per image
    comment_type VARCHAR(50) DEFAULT 'facilitator_comment', -- New from image
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (commented_by) REFERENCES users(user_id)
);

-- Table: external_offices
CREATE TABLE IF NOT EXISTS external_offices (
    office_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sequence_order INT(11) UNIQUE NOT NULL
);

-- Table: external_reviews
-- Observation: reviewed_at is DATETIME and nullable.
CREATE TABLE IF NOT EXISTS external_reviews (
    review_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    submission_id INT(11) NOT NULL,
    office_id INT(11) NOT NULL,
    reviewed_by INT(11) DEFAULT NULL, -- Can be NULL, as per image
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    feedback TEXT DEFAULT NULL, -- Can be NULL, as per image
    reviewed_at DATETIME DEFAULT NULL, -- Can be NULL, as per image
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id),
    FOREIGN KEY (office_id) REFERENCES external_offices(office_id),
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
);

-- Table: audit_logs
-- Observation: action is VARCHAR(255) NOT NULL.
CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    submission_id INT(11) DEFAULT NULL, -- Can be NULL
    action VARCHAR(255) NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id)
);

-- Table: notifications
-- Observation: This table *does* have the 'link' column now, which is great!
-- This resolves our previous discussion point.
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    submission_id INT(11) DEFAULT NULL, -- Can be NULL. Useful if notification relates directly to a submission
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL, -- As per image (Nullable)
    is_read TINYINT(1) DEFAULT 0,  -- TINYINT(1) for BOOLEAN, default 0
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (submission_id) REFERENCES submissions(submission_id)
);