<?php
$host = 'localhost';
$db   = 'su_exam_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     // 0. Auto-migration: Split 'users' table into 'admins' and 'students'
     try {
         $users_exist = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
         if ($users_exist) {
             // Disable foreign key checks
             $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

             // Create admins table
             $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
                 id INT AUTO_INCREMENT PRIMARY KEY,
                 full_name VARCHAR(100) NOT NULL,
                 email VARCHAR(100) UNIQUE NOT NULL,
                 password VARCHAR(255) NOT NULL,
                 contact_no VARCHAR(20) DEFAULT NULL,
                 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB");

             // Create students table
             $pdo->exec("CREATE TABLE IF NOT EXISTS students (
                 id INT AUTO_INCREMENT PRIMARY KEY,
                 enrollment_no VARCHAR(50) UNIQUE NOT NULL,
                 full_name VARCHAR(100) NOT NULL,
                 email VARCHAR(100) UNIQUE NOT NULL,
                 password VARCHAR(255) NOT NULL,
                 contact_no VARCHAR(20) DEFAULT NULL,
                 profile_photo VARCHAR(255) DEFAULT NULL,
                 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB");

             // Copy admins
             $pdo->exec("INSERT INTO admins (id, full_name, email, password, contact_no, created_at)
                 SELECT id, full_name, email, password, contact_no, created_at 
                 FROM users WHERE role = 'admin'");

             // Copy students
             $pdo->exec("INSERT INTO students (id, enrollment_no, full_name, email, password, contact_no, profile_photo, created_at)
                 SELECT id, COALESCE(enrollment_no, CONCAT('SU_MIG_', id)), full_name, email, password, contact_no, profile_photo, created_at 
                 FROM users WHERE role = 'student'");

             // Helper to dynamically drop constraints
             $drop_fks = function($pdo, $table, $column) {
                 $stmt = $pdo->prepare("
                     SELECT CONSTRAINT_NAME 
                     FROM information_schema.KEY_COLUMN_USAGE 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = ? 
                       AND COLUMN_NAME = ? 
                       AND REFERENCED_TABLE_NAME IS NOT NULL
                 ");
                 $stmt->execute([$table, $column]);
                 $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
                 foreach ($constraints as $constraint) {
                     try {
                         $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`");
                     } catch (\PDOException $e) {
                         // Ignore
                     }
                 }
             };

             // Drop legacy foreign keys
             $drop_fks($pdo, 'exams', 'created_by');
             $drop_fks($pdo, 'student_exams', 'student_id');

             // Add new foreign keys
             $pdo->exec("ALTER TABLE exams ADD CONSTRAINT exams_created_by_fk FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL");
             $pdo->exec("ALTER TABLE student_exams ADD CONSTRAINT student_exams_student_id_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");

             // Drop legacy users table
             $pdo->exec("DROP TABLE users");

             // Re-enable foreign key checks
             $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
         }
     } catch (\PDOException $migrationException) {
         throw new \PDOException("Database auto-migration failed: " . $migrationException->getMessage());
     }

     // Self-repair check for last_active column in student_exams table
     try {
         $pdo->query("SELECT last_active FROM student_exams LIMIT 1");
     } catch (\PDOException $e) {
         $pdo->exec("ALTER TABLE student_exams ADD COLUMN last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
     }

     // Self-repair check for proctor_warning column in student_exams table
     try {
         $pdo->query("SELECT proctor_warning FROM student_exams LIMIT 1");
     } catch (\PDOException $e) {
         $pdo->exec("ALTER TABLE student_exams ADD COLUMN proctor_warning VARCHAR(255) DEFAULT NULL");
     }

     // Self-repair check for ENUM status column in student_exams table (add 'absent')
     try {
         $stmt = $pdo->query("DESCRIBE student_exams status");
         $row = $stmt->fetch();
         if ($row) {
             $type = $row['Type'];
             if (strpos($type, 'absent') === false) {
                 $pdo->exec("ALTER TABLE student_exams MODIFY COLUMN status ENUM('started', 'submitted', 'graded', 'absent') DEFAULT 'started'");
             }
         }
     } catch (\PDOException $e) {
         // Ignore
     }

     // Automatic Exam Closure Sweeper (runs in background on every page request)
     try {
         $expired_stmt = $pdo->query("
             SELECT se.id, se.exam_id, e.duration_minutes, se.started_at 
             FROM student_exams se
             JOIN exams e ON se.exam_id = e.id
             WHERE se.status = 'started' 
               AND (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(se.started_at)) > (e.duration_minutes * 60)
         ");
         $expired = $expired_stmt->fetchAll();
         
         if (!empty($expired)) {
             $grader_path = __DIR__ . '/../engine/grader.py';
             $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
             
             foreach ($expired as $exp) {
                 $student_exam_id = intval($exp['id']);
                 
                 // Calculate exact expiration time
                 $expiration_time = date('Y-m-d H:i:s', strtotime($exp['started_at']) + ($exp['duration_minutes'] * 60));
                 
                 // Update status to submitted
                 $update_stmt = $pdo->prepare("UPDATE student_exams SET status = 'submitted', submitted_at = ? WHERE id = ?");
                 $update_stmt->execute([$expiration_time, $student_exam_id]);
                 
                 // Run the Python autograder in background/synchronously
                 $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id;
                 shell_exec($command . " 2>&1");
             }
         }
     } catch (\Exception $e) {
         // Fail silently to prevent disrupting main request
     }
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
