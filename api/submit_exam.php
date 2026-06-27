<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

$action = $input['action'] ?? '';
$student_exam_id = intval($input['student_exam_id'] ?? 0);
$answers = $input['answers'] ?? []; // Array of { question_id, student_answer }
$tab_switches = intval($input['tab_switches'] ?? 0);
$copy_pastes = intval($input['copy_pastes'] ?? 0);

if (!$student_exam_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid student exam ID.']);
    exit;
}

try {
    // Verify ownership
    $stmt = $pdo->prepare("SELECT status FROM student_exams WHERE id = ? AND student_id = ?");
    $stmt->execute([$student_exam_id, $_SESSION['student_user_id']]);
    $student_exam = $stmt->fetch();

    if (!$student_exam) {
        echo json_encode(['success' => false, 'message' => 'Exam submission record not found.']);
        exit;
    }

    if ($student_exam['status'] !== 'started') {
        echo json_encode(['success' => false, 'message' => 'This exam has already been submitted.']);
        exit;
    }

    // Process saving draft answers
    if (!empty($answers)) {
        foreach ($answers as $ans) {
            $question_id = intval($ans['question_id']);
            $text = trim($ans['student_answer'] ?? '');

            // Check if answer already exists
            $stmt = $pdo->prepare("SELECT id FROM student_answers WHERE student_exam_id = ? AND question_id = ?");
            $stmt->execute([$student_exam_id, $question_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update
                $stmt = $pdo->prepare("UPDATE student_answers SET student_answer = ? WHERE id = ?");
                $stmt->execute([$text, $existing['id']]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO student_answers (student_exam_id, question_id, student_answer) VALUES (?, ?, ?)");
                $stmt->execute([$student_exam_id, $question_id, $text]);
            }
        }
    }

    // Update proctoring counts and last active status
    $stmt = $pdo->prepare("UPDATE student_exams SET tab_switch_count = ?, copy_paste_count = ?, last_active = NOW() WHERE id = ?");
    $stmt->execute([$tab_switches, $copy_pastes, $student_exam_id]);

    if ($action === 'heartbeat') {
        // Also check if exam was force-submitted or terminated by admin, and fetch warnings!
        $stmt = $pdo->prepare("SELECT status, proctor_warning FROM student_exams WHERE id = ?");
        $stmt->execute([$student_exam_id]);
        $status_check = $stmt->fetch();
        
        $is_terminated = ($status_check && $status_check['status'] !== 'started');
        $warning_msg = $status_check ? $status_check['proctor_warning'] : null;
        
        // Clear warning message after fetching to prevent displaying it repeatedly
        if ($warning_msg) {
            $clear_stmt = $pdo->prepare("UPDATE student_exams SET proctor_warning = NULL WHERE id = ?");
            $clear_stmt->execute([$student_exam_id]);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Heartbeat received.', 
            'terminated' => $is_terminated,
            'warning_msg' => $warning_msg,
            'exam_status' => $status_check ? $status_check['status'] : ''
        ]);
        exit;
    }

    if ($action === 'autosave') {
        echo json_encode(['success' => true, 'message' => 'Draft saved successfully.']);
        exit;
    } 
    
    elseif ($action === 'submit') {
        // Mark exam as submitted
        $stmt = $pdo->prepare("UPDATE student_exams SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
        $stmt->execute([$student_exam_id]);

        // Trigger the Python grader
        $grader_path = __DIR__ . '/../engine/grader.py';
        
        // Check local python path
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id;
        $output = shell_exec($command . " 2>&1");
        
        $grader_run = false;
        if (strpos($output, 'Grading Completed Successfully') !== false) {
            $grader_run = true;
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Exam submitted successfully.',
            'grader_run' => $grader_run,
            'grader_log' => $output
        ]);
        exit;
    } 
    
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
