<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized admin access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

$action = $input['action'] ?? '';

try {
    if ($action === 'create_exam') {
        $title = trim($input['title'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $duration = intval($input['duration'] ?? 0);

        if (empty($title) || empty($subject) || $duration <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid Title, Subject, and Duration are required.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO exams (title, subject, duration_minutes, created_by, status) VALUES (?, ?, ?, ?, 'draft')");
        $stmt->execute([$title, $subject, $duration, $_SESSION['admin_user_id']]);
        
        echo json_encode(['success' => true, 'exam_id' => $pdo->lastInsertId(), 'message' => 'Exam created successfully in draft mode.']);
    } 
    
    elseif ($action === 'add_question') {
        $exam_id = intval($input['exam_id'] ?? 0);
        $type = $input['type'] ?? '';
        $question_text = trim($input['question_text'] ?? '');
        $points = intval($input['points'] ?? 1);

        if (!$exam_id || empty($type) || empty($question_text)) {
            echo json_encode(['success' => false, 'message' => 'Exam ID, type, and question text are required.']);
            exit;
        }

        // Check if exam is completed
        $status_stmt = $pdo->prepare("SELECT status FROM exams WHERE id = ?");
        $status_stmt->execute([$exam_id]);
        $exam_status = $status_stmt->fetchColumn();
        if ($exam_status === 'completed') {
            echo json_encode(['success' => false, 'message' => 'Cannot add questions to a completed exam.']);
            exit;
        }

        if ($type === 'mcq') {
            $opt_a = trim($input['option_a'] ?? '');
            $opt_b = trim($input['option_b'] ?? '');
            $opt_c = trim($input['option_c'] ?? '');
            $opt_d = trim($input['option_d'] ?? '');
            $correct = trim($input['correct_option'] ?? '');

            if ($opt_a === '' || $opt_b === '' || $opt_c === '' || $opt_d === '' || $correct === '') {
                echo json_encode(['success' => false, 'message' => 'All MCQ options and the correct answer are required.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, type, option_a, option_b, option_c, option_d, correct_option, points) VALUES (?, ?, 'mcq', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$exam_id, $question_text, $opt_a, $opt_b, $opt_c, $opt_d, $correct, $points]);
        } else {
            $model_answer = trim($input['model_answer'] ?? '');
            if (empty($model_answer)) {
                echo json_encode(['success' => false, 'message' => 'Model answer is required for descriptive questions.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, type, model_answer, points) VALUES (?, ?, 'descriptive', ?, ?)");
            $stmt->execute([$exam_id, $question_text, $model_answer, $points]);
        }

        echo json_encode(['success' => true, 'message' => 'Question added successfully.']);
    } 
    
    elseif ($action === 'update_exam_status') {
        $exam_id = intval($input['exam_id'] ?? 0);
        $status = $input['status'] ?? '';

        if (!$exam_id || !in_array($status, ['draft', 'active', 'completed'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid exam or status value.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE exams SET status = ? WHERE id = ?");
        $stmt->execute([$status, $exam_id]);

        // If closing the exam, mark all registered students who didn't take it as absent
        if ($status === 'completed') {
            // Compute total possible score of the exam questions
            $pts_stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) as total FROM questions WHERE exam_id = ?");
            $pts_stmt->execute([$exam_id]);
            $total_points = intval($pts_stmt->fetch()['total'] ?? 0);

            $stmt = $pdo->prepare("
                INSERT INTO student_exams (student_id, exam_id, status, score, total_possible_score)
                SELECT u.id, ?, 'absent', 0.00, ?
                FROM students u
                WHERE u.id NOT IN (SELECT student_id FROM student_exams WHERE exam_id = ?)
            ");
            $stmt->execute([$exam_id, $total_points, $exam_id]);
        }

        echo json_encode(['success' => true, 'message' => 'Exam status updated to ' . $status]);
    } 
    
    elseif ($action === 'run_grader') {
        $student_exam_id = intval($input['student_exam_id'] ?? 0);
        if (!$student_exam_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid student exam ID.']);
            exit;
        }

        $grader_path = __DIR__ . '/../engine/grader.py';
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id;
        $output = shell_exec($command . " 2>&1");

        $grader_run = false;
        if (strpos($output, 'Grading Completed Successfully') !== false) {
            $grader_run = true;
        }

        echo json_encode([
            'success' => $grader_run,
            'message' => $grader_run ? 'Grading completed.' : 'Grading failed.',
            'grader_log' => $output
        ]);
    } 
    
    elseif ($action === 'manual_grade') {
        $answer_id = intval($input['answer_id'] ?? 0);
        $marks = floatval($input['marks'] ?? 0.0);
        $feedback = trim($input['feedback'] ?? '');

        if (!$answer_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid answer ID.']);
            exit;
        }

        // Get student_exam_id and question points first to validate marks
        $stmt = $pdo->prepare("SELECT sa.student_exam_id, q.points FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.id = ?");
        $stmt->execute([$answer_id]);
        $record = $stmt->fetch();

        if (!$record) {
            echo json_encode(['success' => false, 'message' => 'Answer record not found.']);
            exit;
        }

        if ($marks < 0 || $marks > $record['points']) {
            echo json_encode(['success' => false, 'message' => 'Marks must be between 0 and ' . $record['points']]);
            exit;
        }

        // Update answer marks
        $stmt = $pdo->prepare("UPDATE student_answers SET marks_obtained = ?, auto_feedback = ? WHERE id = ?");
        $stmt->execute([$marks, $feedback . " (Manually Graded)", $answer_id]);

        // Recalculate student exam total score
        $student_exam_id = $record['student_exam_id'];
        $stmt = $pdo->prepare("SELECT SUM(marks_obtained) as total_score FROM student_answers WHERE student_exam_id = ?");
        $stmt->execute([$student_exam_id]);
        $total_score = floatval($stmt->fetch()['total_score'] ?? 0.0);

        // Update student exam score
        $stmt = $pdo->prepare("UPDATE student_exams SET score = ?, status = 'graded' WHERE id = ?");
        $stmt->execute([$total_score, $student_exam_id]);

        echo json_encode(['success' => true, 'message' => 'Grade updated successfully.', 'new_total_score' => $total_score]);
    } 
    
    elseif ($action === 'auto_grade_all_pending') {
        // Fetch all submitted student exams
        $stmt = $pdo->query("SELECT id FROM student_exams WHERE status = 'submitted'");
        $pending = $stmt->fetchAll();
        
        $grader_path = __DIR__ . '/../engine/grader.py';
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        
        $graded_count = 0;
        foreach ($pending as $exam) {
            $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . intval($exam['id']);
            shell_exec($command . " 2>&1");
            $graded_count++;
        }
        
        echo json_encode(['success' => true, 'message' => "Successfully auto-graded $graded_count pending submission(s)."]);
        exit;
    }
    
    elseif ($action === 'delete_question') {
        $question_id = intval($input['question_id'] ?? 0);
        if (!$question_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid question ID.']);
            exit;
        }

        // Check if exam is completed
        $status_stmt = $pdo->prepare("SELECT e.status FROM exams e JOIN questions q ON q.exam_id = e.id WHERE q.id = ?");
        $status_stmt->execute([$question_id]);
        $exam_status = $status_stmt->fetchColumn();
        if ($exam_status === 'completed') {
            echo json_encode(['success' => false, 'message' => 'Cannot delete questions from a completed exam.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->execute([$question_id]);

        echo json_encode(['success' => true, 'message' => 'Question deleted successfully.']);
        exit;
    }
    
    elseif ($action === 'simulate_mock_submissions') {
        $exam_id = 1; // Core mock exam
        
        $students = [
            [
                'enrollment' => 'SU2026002',
                'name' => 'Rohit Patel',
                'email' => 'rohit@student.su.edu.in',
                'tab_switches' => 0,
                'copy_pastes' => 0,
                'answers' => [
                    1 => 'D', // Correct
                    2 => 'C', // Correct
                    3 => "Client-side scripting runs on the user's browser, mainly using JavaScript, focusing on UI validation and user interactions. Server-side scripting runs on the server (like PHP, Python), focusing on processing data, querying databases, and page rendering before shipping to the client.",
                    4 => "A database management system (DBMS) is database controller software used to store, secure, retrieve, and process relational or non-relational data. It handles concurrency control, data integrity, transactions, and security checks."
                ]
            ],
            [
                'enrollment' => 'SU2026003',
                'name' => 'Priya Shah',
                'email' => 'priya@student.su.edu.in',
                'tab_switches' => 2,
                'copy_pastes' => 1,
                'answers' => [
                    1 => 'D', // Correct
                    2 => 'A', // Incorrect
                    3 => "Client-side scripting executes inside the web browser using JavaScript for interactive elements. Server-side scripting runs on the server to execute PHP code and query databases.",
                    4 => "A database management system is software to manage databases. It helps users insert, delete, and query tables of information."
                ]
            ],
            [
                'enrollment' => 'SU2026004',
                'name' => 'Amit Verma',
                'email' => 'amit@student.su.edu.in',
                'tab_switches' => 5,
                'copy_pastes' => 4,
                'answers' => [
                    1 => 'B', // Incorrect
                    2 => 'D', // Incorrect
                    3 => "Scripting runs on chrome browser for styling web pages.",
                    4 => "DBMS is used for storing files and directories."
                ]
            ]
        ];

        $grader_path = __DIR__ . '/../engine/grader.py';
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        $generated_names = [];

        foreach ($students as $stu) {
            // Register student if not exists
            $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ?");
            $stmt->execute([$stu['email']]);
            $user = $stmt->fetch();
 
            if (!$user) {
                $hash = password_hash('student123', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO students (enrollment_no, full_name, email, password) VALUES (?, ?, ?, ?)");
                $stmt->execute([$stu['enrollment'], $stu['name'], $stu['email'], $hash]);
                $user_id = $pdo->lastInsertId();
            } else {
                $user_id = $user['id'];
            }

            // Remove previous submission if exists to keep it fresh
            $stmt = $pdo->prepare("DELETE FROM student_exams WHERE student_id = ? AND exam_id = ?");
            $stmt->execute([$user_id, $exam_id]);

            // Get total possible score
            $stmt = $pdo->prepare("SELECT SUM(points) as total FROM questions WHERE exam_id = ?");
            $stmt->execute([$exam_id]);
            $total_points = intval($stmt->fetch()['total'] ?? 0);

            // Insert student exam record
            $stmt = $pdo->prepare("INSERT INTO student_exams (student_id, exam_id, status, tab_switch_count, copy_paste_count, total_possible_score) VALUES (?, ?, 'submitted', ?, ?, ?)");
            $stmt->execute([$user_id, $exam_id, $stu['tab_switches'], $stu['copy_pastes'], $total_points]);
            $student_exam_id = $pdo->lastInsertId();

            // Insert answers
            foreach ($stu['answers'] as $q_id => $ans_text) {
                $stmt = $pdo->prepare("INSERT INTO student_answers (student_exam_id, question_id, student_answer) VALUES (?, ?, ?)");
                $stmt->execute([$student_exam_id, $q_id, $ans_text]);
            }

            // Run python grader
            $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id;
            shell_exec($command . " 2>&1");
            
            $generated_names[] = $stu['name'];
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Simulated submissions for Rohit, Priya, and Amit. Automatic grades generated.'
        ]);
        exit;
    }
    
    elseif ($action === 'get_live_sessions') {
        // Fetch all student exams where status is 'started' and last_active is within last 45 seconds
        $stmt = $pdo->query("
            SELECT se.*, u.full_name, u.enrollment_no, u.profile_photo, e.title as exam_title, e.duration_minutes,
                   (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(se.started_at)) AS elapsed_seconds
          FROM student_exams se
          JOIN students u ON se.student_id = u.id
          JOIN exams e ON se.exam_id = e.id
          WHERE se.status = 'started' AND se.last_active >= NOW() - INTERVAL 45 SECOND
          ORDER BY se.last_active DESC
        ");
        $sessions = $stmt->fetchAll();
        echo json_encode(['success' => true, 'sessions' => $sessions]);
        exit;
    }
    
    elseif ($action === 'force_submit_exam') {
        $student_exam_id = intval($input['student_exam_id'] ?? 0);
        if (!$student_exam_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid student exam ID.']);
            exit;
        }
        
        // Update student exam status to 'submitted'
        $stmt = $pdo->prepare("UPDATE student_exams SET status = 'submitted', submitted_at = NOW() WHERE id = ? AND status = 'started'");
        $stmt->execute([$student_exam_id]);
        
        // Run the Python auto-grader immediately for this exam
        $grader_path = __DIR__ . '/../engine/grader.py';
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id;
        shell_exec($command . " 2>&1");
        
        echo json_encode(['success' => true, 'message' => 'Student exam session force-closed and submitted successfully.']);
        exit;
    }
    
    elseif ($action === 'get_submission_details') {
        $student_exam_id = intval($input['student_exam_id'] ?? 0);
        if (!$student_exam_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid student exam ID.']);
            exit;
        }
        
        // Fetch student exam info
        $stmt = $pdo->prepare("
            SELECT se.*, u.full_name, u.enrollment_no, u.email as student_email, u.contact_no, u.profile_photo, e.title as exam_title
            FROM student_exams se
            JOIN students u ON se.student_id = u.id
            JOIN exams e ON se.exam_id = e.id
            WHERE se.id = ?
        ");
        $stmt->execute([$student_exam_id]);
        $submission = $stmt->fetch();
        
        if (!$submission) {
            echo json_encode(['success' => false, 'message' => 'Submission details not found.']);
            exit;
        }
        
        // Fetch answers and questions
        $stmt = $pdo->prepare("
            SELECT sa.*, q.question_text, q.type as question_type, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option, q.model_answer, q.points
            FROM student_answers sa
            JOIN questions q ON sa.question_id = q.id
            WHERE sa.student_exam_id = ?
            ORDER BY q.id ASC
        ");
        $stmt->execute([$student_exam_id]);
        $answers = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'submission' => $submission,
            'answers' => $answers
        ]);
        exit;
    }
    
    elseif ($action === 'bulk_action') {
        $bulk_act = $input['bulk_action'] ?? '';
        $ids = $input['student_exam_ids'] ?? [];
        
        if (empty($ids) || !is_array($ids)) {
            echo json_encode(['success' => false, 'message' => 'No student submissions selected.']);
            exit;
        }
        
        $ids_clean = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids_clean), '?'));
        
        if ($bulk_act === 'grade') {
            $grader_path = __DIR__ . '/../engine/grader.py';
            $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
            
            $count = 0;
            foreach ($ids_clean as $id) {
                $command = "\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $id;
                shell_exec($command . " 2>&1");
                $count++;
            }
            echo json_encode(['success' => true, 'message' => "Successfully graded $count selected submission(s)."]);
            exit;
        }
        
        elseif ($bulk_act === 'reset') {
            // Deleting student exam record will cascade and delete student answers too
            $stmt = $pdo->prepare("DELETE FROM student_exams WHERE id IN ($placeholders)");
            $stmt->execute($ids_clean);
            echo json_encode(['success' => true, 'message' => 'Successfully reset student attempts. Students can now retake this exam.']);
            exit;
        }
        
        elseif ($bulk_act === 'finalize') {
            $stmt = $pdo->prepare("UPDATE student_exams SET status = 'graded' WHERE id IN ($placeholders)");
            $stmt->execute($ids_clean);
            echo json_encode(['success' => true, 'message' => 'Successfully marked selected submissions as Graded.']);
            exit;
        }
        
        else {
            echo json_encode(['success' => false, 'message' => 'Invalid bulk action.']);
            exit;
        }
    }
    
    elseif ($action === 'edit_exam') {
        $exam_id = intval($input['exam_id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $duration = intval($input['duration'] ?? 0);
        
        if (!$exam_id || empty($title) || empty($subject) || $duration <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid Exam ID, Title, Subject, and Duration are required.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE exams SET title = ?, subject = ?, duration_minutes = ? WHERE id = ?");
        $stmt->execute([$title, $subject, $duration, $exam_id]);
        
        echo json_encode(['success' => true, 'message' => 'Exam template updated successfully.']);
        exit;
    }
    
    elseif ($action === 'delete_exam') {
        $exam_id = intval($input['exam_id'] ?? 0);
        if (!$exam_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid exam ID.']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        
        echo json_encode(['success' => true, 'message' => 'Exam template deleted successfully.']);
        exit;
    }
    
    elseif ($action === 'edit_question') {
        $question_id = intval($input['question_id'] ?? 0);
        $type = $input['type'] ?? '';
        $question_text = trim($input['question_text'] ?? '');
        $points = intval($input['points'] ?? 1);
        
        if (!$question_id || empty($type) || empty($question_text)) {
            echo json_encode(['success' => false, 'message' => 'Question ID, Type, and Question text are required.']);
            exit;
        }

        // Check if exam is completed
        $status_stmt = $pdo->prepare("SELECT e.status FROM exams e JOIN questions q ON q.exam_id = e.id WHERE q.id = ?");
        $status_stmt->execute([$question_id]);
        $exam_status = $status_stmt->fetchColumn();
        if ($exam_status === 'completed') {
            echo json_encode(['success' => false, 'message' => 'Cannot edit questions of a completed exam.']);
            exit;
        }
        
        if ($type === 'mcq') {
            $opt_a = trim($input['option_a'] ?? '');
            $opt_b = trim($input['option_b'] ?? '');
            $opt_c = trim($input['option_c'] ?? '');
            $opt_d = trim($input['option_d'] ?? '');
            $correct = trim($input['correct_option'] ?? '');
            
            if ($opt_a === '' || $opt_b === '' || $opt_c === '' || $opt_d === '' || $correct === '') {
                echo json_encode(['success' => false, 'message' => 'All MCQ options and correct answer are required.']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, type = 'mcq', option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, points = ?, model_answer = NULL WHERE id = ?");
            $stmt->execute([$question_text, $opt_a, $opt_b, $opt_c, $opt_d, $correct, $points, $question_id]);
        } else {
            $model_answer = trim($input['model_answer'] ?? '');
            if (empty($model_answer)) {
                echo json_encode(['success' => false, 'message' => 'Model answer is required for descriptive questions.']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, type = 'descriptive', model_answer = ?, points = ?, option_a = NULL, option_b = NULL, option_c = NULL, option_d = NULL, correct_option = NULL WHERE id = ?");
            $stmt->execute([$question_text, $model_answer, $points, $question_id]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Question updated successfully.']);
        exit;
    }
    
    elseif ($action === 'send_proctor_warning') {
        $student_exam_id = intval($input['student_exam_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        
        if (!$student_exam_id || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Student Exam ID and warning message are required.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE student_exams SET proctor_warning = ? WHERE id = ?");
        $stmt->execute([$message, $student_exam_id]);

        echo json_encode(['success' => true, 'message' => 'Proctor warning sent successfully.']);
        exit;
    }
    
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
