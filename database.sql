CREATE DATABASE IF NOT EXISTS su_exam_db;
USE su_exam_db;

-- 1. Admins Table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    contact_no VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Students Table
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_no VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    contact_no VARCHAR(20) DEFAULT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Exams Table
CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    subject VARCHAR(100) NOT NULL,
    duration_minutes INT NOT NULL,
    created_by INT,
    status ENUM('draft', 'active', 'completed') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- 3. Questions Table
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_text TEXT NOT NULL,
    type ENUM('mcq', 'descriptive') NOT NULL,
    option_a VARCHAR(255) NULL,
    option_b VARCHAR(255) NULL,
    option_c VARCHAR(255) NULL,
    option_d VARCHAR(255) NULL,
    correct_option CHAR(1) NULL, -- 'A', 'B', 'C', 'D'
    model_answer TEXT NULL,       -- Used for semantic grading
    points INT DEFAULT 1,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- 4. Student Exams Table
CREATE TABLE IF NOT EXISTS student_exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    status ENUM('started', 'submitted', 'graded') DEFAULT 'started',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    tab_switch_count INT DEFAULT 0,
    copy_paste_count INT DEFAULT 0,
    score DECIMAL(5,2) DEFAULT 0.00,
    total_possible_score INT DEFAULT 0,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    UNIQUE KEY (student_id, exam_id)
);

-- 5. Student Answers Table
CREATE TABLE IF NOT EXISTS student_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_exam_id INT NOT NULL,
    question_id INT NOT NULL,
    student_answer TEXT NOT NULL,
    marks_obtained DECIMAL(5,2) DEFAULT 0.00,
    auto_feedback TEXT NULL,
    FOREIGN KEY (student_exam_id) REFERENCES student_exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- Seed Default Admin
INSERT INTO admins (id, full_name, email, password) VALUES
(1, 'Dr. Rajesh Sharma (Admin)', 'admin@su.edu.in', '$2y$10$HD04GsiJuYaQkE7u6ern1OLPP9tWtDL3Qnh5mTCXRbTjZNY3fksMa')
ON DUPLICATE KEY UPDATE id=id;

-- Seed Default Student
INSERT INTO students (id, enrollment_no, full_name, email, password) VALUES
(2, 'SU2026001', 'Mayur Ramavat', 'mayur@student.su.edu.in', '$2y$10$fLAz20bsRTS9E2/rrHgKq.ru5KtrswJX58bK1yf4wVCd3DdFMw7/m')
ON DUPLICATE KEY UPDATE id=id;

-- Seed Sample Exam
INSERT INTO exams (id, title, subject, duration_minutes, created_by, status) VALUES
(1, 'Web Development & Scripting Languages', 'Computer Science & Engineering', 30, 1, 'active')
ON DUPLICATE KEY UPDATE id=id;

-- Seed Questions for Exam 1
INSERT INTO questions (exam_id, question_text, type, option_a, option_b, option_c, option_d, correct_option, model_answer, points) VALUES
(1, 'Which of the following is a server-side scripting language?', 'mcq', 'HTML', 'CSS', 'JavaScript', 'PHP', 'D', NULL, 2),
(1, 'What does the abbreviation CSS stand for?', 'mcq', 'Computer Style Sheets', 'Creative Style Sheets', 'Cascading Style Sheets', 'Colorful Style Sheets', 'C', NULL, 2),
(1, 'Explain the core differences between Client-Side scripting and Server-Side scripting.', 'descriptive', NULL, NULL, NULL, NULL, NULL, 'Client-side scripting runs on the user\'s web browser, mainly using JavaScript, focusing on UI validation and user interactions. Server-side scripting runs on the server (like PHP, Python), focusing on processing data, querying databases, and page rendering before shipping to the client.', 5),
(1, 'What is a Database Management System (DBMS) and what are its primary roles?', 'descriptive', NULL, NULL, NULL, NULL, NULL, 'A database management system (DBMS) is database controller software used to store, secure, retrieve, and process relational or non-relational data. It handles concurrency control, data integrity, transactions, and security checks.', 5)
ON DUPLICATE KEY UPDATE id=id;
