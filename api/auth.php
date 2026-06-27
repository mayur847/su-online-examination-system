<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

$action = $input['action'] ?? '';

if ($action === 'login') {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $role = trim($input['role'] ?? 'student');

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    try {
        if ($role === 'admin') {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_full_name'] = $user['full_name'];
                echo json_encode(['success' => true, 'redirect' => 'admin.php']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            }
        } else {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['student_user_id'] = $user['id'];
                $_SESSION['student_full_name'] = $user['full_name'];
                $_SESSION['student_enrollment_no'] = $user['enrollment_no'];
                echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} 

elseif ($action === 'register') {
    $full_name = trim($input['full_name'] ?? '');
    $enrollment_no = trim($input['enrollment_no'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($full_name) || empty($enrollment_no) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    try {
        // Check duplicate email
        $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email is already registered.']);
            exit;
        }

        // Check duplicate enrollment number
        $stmt = $pdo->prepare("SELECT id FROM students WHERE enrollment_no = ?");
        $stmt->execute([$enrollment_no]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Enrollment number is already registered.']);
            exit;
        }

        // Hash and insert
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO students (enrollment_no, full_name, email, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$enrollment_no, $full_name, $email, $hashed_password]);

        echo json_encode(['success' => true, 'message' => 'Registration successful! You can now log in.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} 

else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
?>
