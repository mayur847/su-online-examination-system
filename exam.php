<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Check auth
if (!isset($_SESSION['student_user_id'])) {
    header('Location: index.php');
    exit;
}

$student_exam_id = intval($_GET['id'] ?? 0);
if (!$student_exam_id) {
    header('Location: dashboard.php');
    exit;
}

try {
    // Fetch student exam details
    $stmt = $pdo->prepare("
        SELECT se.*, e.title, e.subject, e.duration_minutes,
               (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(se.started_at)) AS elapsed_seconds
        FROM student_exams se 
        JOIN exams e ON se.exam_id = e.id 
        WHERE se.id = ? AND se.student_id = ?
    ");
    $stmt->execute([$student_exam_id, $_SESSION['student_user_id']]);
    $student_exam = $stmt->fetch();

    if (!$student_exam) {
        header('Location: dashboard.php');
        exit;
    }

    if ($student_exam['status'] !== 'started') {
        // Exam has already been submitted or graded
        echo "<script>alert('This exam has already been submitted.'); window.location.href='dashboard.php';</script>";
        exit;
    }

    $exam_id = $student_exam['exam_id'];

    // Calculate time left in seconds timezone-safely
    $elapsed_seconds = intval($student_exam['elapsed_seconds']);
    $duration_seconds = $student_exam['duration_minutes'] * 60;
    $time_left_seconds = $duration_seconds - $elapsed_seconds;

    if ($time_left_seconds <= 0) {
        // Auto submit if time already elapsed on load
        $stmt = $pdo->prepare("UPDATE student_exams SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
        $stmt->execute([$student_exam_id]);
        
        // Trigger Python grader in background or synchronously
        $grader_path = __DIR__ . '/engine/grader.py';
        $pythonPath = file_exists('C:/Python314/python.exe') ? 'C:/Python314/python.exe' : 'python';
        shell_exec("\"$pythonPath\" \"" . addslashes($grader_path) . "\" " . $student_exam_id);

        echo "<script>alert('Time limit exceeded. Your answers were auto-submitted.'); window.location.href='dashboard.php';</script>";
        exit;
    }

    $time_left_minutes = ceil($time_left_seconds / 60);

    // Fetch questions (Exclude correct options and model answers for security!)
    $stmt = $pdo->prepare("
        SELECT id, question_text, type, option_a, option_b, option_c, option_d, points 
        FROM questions 
        WHERE exam_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$exam_id]);
    $questions = $stmt->fetchAll();

    // Fetch existing student answers
    $stmt = $pdo->prepare("SELECT question_id, student_answer FROM student_answers WHERE student_exam_id = ?");
    $stmt->execute([$student_exam_id]);
    $saved_answers = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Exam - Swaminarayan University</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Disable text selection inside the exam content */
        .no-select {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
    </style>
</head>
<body class="no-select">

    <header>
        <div class="navbar">
            <a href="#" class="brand" onclick="return false;">
                <img src="assets/logo.png" alt="Swaminarayan University Logo">
                <div class="brand-text">
                    <span class="brand-name">Swaminarayan University</span>
                    <span class="brand-tagline">Proctored Examination</span>
                </div>
            </a>
            <div class="nav-actions">
                <button id="theme-toggle" class="btn" onclick="toggleTheme()" style="padding: 0.5rem; border-radius: 50%; font-size: 1.25rem; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border: none; cursor: pointer;">🌙</button>
                <span id="autosave-status" style="font-size: 0.8rem; color: var(--success); font-weight: bold; margin-right: 0.75rem; display: inline-flex; align-items: center; gap: 0.25rem;">💾 Connected</span>
                <span style="font-weight: 600;">Student: <?php echo htmlspecialchars($_SESSION['student_full_name']); ?></span>
                <span style="font-size: 0.85rem; color: var(--primary-saffron); font-weight: bold; border: 1px solid var(--primary-saffron); padding: 0.25rem 0.5rem; border-radius: 4px;">🛡️ Proctored Session</span>
            </div>
        </div>
    </header>

    <div class="exam-body">
        
        <!-- Sidebar Palette Navigation -->
        <div class="exam-sidebar">
            <div class="timer-widget" id="timer-widget">
                ⏱️ 00:00
            </div>
            
            <h4 style="margin: 0.5rem 0 0 0; font-size: 0.95rem; border-bottom: 1px solid var(--border-light); padding-bottom: 0.5rem;">Navigation Palette</h4>
            <div class="question-palette" id="palette-container">
                <!-- Javascript will populate buttons -->
            </div>
            
            <div style="margin-top: auto; display: flex; flex-direction: column; gap: 0.75rem; border-top: 1px solid var(--border-light); padding-top: 1rem;">
                <button class="minimalist-btn" id="review-btn" onclick="toggleFlagReview()" style="font-size: 0.9rem; background: transparent; color: inherit; border-color: var(--border-light);">🏳️ Mark for Review</button>
                <button class="minimalist-btn btn-danger" onclick="confirmSubmitExam()" style="font-size: 0.9rem;">Submit Assessment</button>
            </div>
        </div>
        
        <!-- Main Exam Area -->
        <div class="exam-content-area">
            <div class="minimalist-card question-card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="question-meta" id="question-number-title">Question 1 of 1</span>
                    <span class="badge badge-info" id="question-points-badge">1 Point</span>
                </div>
                
                <div class="question-text" id="question-text-content">
                    Loading question text...
                </div>
                
                <div id="question-answer-area">
                    <!-- MCQ Options or Textarea will load here -->
                </div>
                
                <div class="exam-controls">
                    <button class="minimalist-btn" id="prev-btn" onclick="navigateQuestion(-1)" disabled style="background: transparent; color: inherit; border-color: var(--border-light);">◀ Previous Question</button>
                    <button class="minimalist-btn" id="next-btn" onclick="navigateQuestion(1)">Next Question ▶</button>
                    <button class="minimalist-btn btn-danger" id="submit-exam-btn" onclick="confirmSubmitExam()" style="display: none;">Submit Exam</button>
                </div>
            </div>
        </div>

    <!-- Modal: Proctor Warning Alert -->
    <div class="cheat-overlay" id="proctor-warning-modal" style="display: none; z-index: 9999;">
        <div class="minimalist-card cheat-card" style="border-top: 5px solid var(--danger); background: var(--bg-dark);">
            <h1 style="color: var(--danger); font-size: 1.8rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">🚨 PROCTOR WARNING</h1>
            <p id="proctor-warning-text" style="font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0; line-height: 1.5; color: var(--text-dark); background: rgba(239, 68, 68, 0.08); padding: 1.25rem; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2);"></p>
            <button class="minimalist-btn btn-danger" onclick="dismissProctorWarning()" style="padding: 0.65rem 2rem; font-size: 0.95rem;">Dismiss & Acknowledge</button>
        </div>
    </div>

    <!-- Load App & Exam JavaScript -->
    <script src="assets/js/app.js"></script>
    <script src="assets/js/exam.js"></script>
    <script>
        // Inject data from PHP to JS
        const studentExamId = <?php echo $student_exam_id; ?>;
        const examId = <?php echo $exam_id; ?>;
        const durationMinutes = <?php echo $student_exam['duration_minutes']; ?>;
        const timeLeftSeconds = <?php echo $time_left_seconds; ?>;
        const questions = <?php echo json_encode($questions); ?>;
        const savedAnswers = <?php echo json_encode($saved_answers); ?>;
        const tabSwitches = <?php echo intval($student_exam['tab_switch_count']); ?>;
        const copyPastes = <?php echo intval($student_exam['copy_paste_count']); ?>;
        
        // Initialize the exam environment
        initExam(studentExamId, examId, durationMinutes, questions, savedAnswers, tabSwitches, copyPastes);
        
        // Override initial time left with computed values
        currentExam.timeLeftSeconds = timeLeftSeconds;
    </script>
</body>
</html>
