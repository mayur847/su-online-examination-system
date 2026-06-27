<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Check auth
if (!isset($_SESSION['admin_user_id'])) {
    header('Location: index.php');
    exit;
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle CSV export action before rendering any page header!
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=su_exam_results_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        
        // Write header line
        fputcsv($output, ['Enrollment No', 'Student Name', 'Email', 'Exam Paper', 'Date Submitted', 'Tab Switches', 'Copy Pastes', 'Marks Obtained', 'Total Marks', 'Status']);
        
        $stmt = $pdo->query("
            SELECT se.*, u.full_name, u.enrollment_no, u.email as student_email, e.title as exam_title 
            FROM student_exams se 
            JOIN students u ON se.student_id = u.id 
            JOIN exams e ON se.exam_id = e.id 
            ORDER BY se.submitted_at DESC
        ");
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['enrollment_no'],
                $row['full_name'],
                $row['student_email'],
                $row['exam_title'],
                $row['submitted_at'],
                $row['tab_switch_count'],
                $row['copy_paste_count'],
                $row['score'],
                $row['total_possible_score'],
                $row['status']
            ]);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        die("Export Error: " . $e->getMessage());
    }
}

try {
    // 1. Fetch Exams with Question Count and Total Points
    $stmt = $pdo->query("
        SELECT e.*, 
               (SELECT COUNT(*) FROM questions WHERE exam_id = e.id) as question_count,
               (SELECT COALESCE(SUM(points), 0) FROM questions WHERE exam_id = e.id) as total_points
        FROM exams e 
        ORDER BY e.id DESC
    ");
    $exams = $stmt->fetchAll();

    // 2. Fetch Submissions
    $stmt = $pdo->query("
        SELECT se.*, u.full_name, u.enrollment_no, e.title as exam_title 
        FROM student_exams se 
        JOIN students u ON se.student_id = u.id 
        JOIN exams e ON se.exam_id = e.id 
        ORDER BY se.submitted_at DESC
    ");
    $submissions = $stmt->fetchAll();

    // 3. Calculate Class Analytics & Performance Tier counts for Chart.js
    $excellent = 0;
    $first_class = 0;
    $second_class = 0;
    $failed = 0;
    $total_score_sum = 0;
    $total_score_count = 0;
    $total_warnings = 0;
    $actual_submissions_count = 0;

    foreach ($submissions as $sub) {
        if ($sub['status'] !== 'absent') {
            $actual_submissions_count++;
        }
        if ($sub['status'] === 'graded') {
            $total_score_sum += $sub['score'];
            $total_score_count++;
            
            $percent = ($sub['total_possible_score'] > 0) ? ($sub['score'] / $sub['total_possible_score']) * 100 : 0;
            
            if ($percent >= 80) $excellent++;
            elseif ($percent >= 60) $first_class++;
            elseif ($percent >= 40) $second_class++;
            else $failed++;
        }
        $total_warnings += $sub['tab_switch_count'] + $sub['copy_paste_count'];
    }

    $class_avg = ($total_score_count > 0) ? round($total_score_sum / $total_score_count, 2) : 0.00;
    $avg_warnings = ($actual_submissions_count > 0) ? round($total_warnings / $actual_submissions_count, 1) : 0.0;

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Handle detailed submission view
$view_exam_id = intval($_GET['view_submission'] ?? 0);
$detailed_submission = null;
$student_answers = [];

if ($view_exam_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT se.*, u.full_name, u.enrollment_no, e.title as exam_title 
            FROM student_exams se 
            JOIN students u ON se.student_id = u.id 
            JOIN exams e ON se.exam_id = e.id 
            WHERE se.id = ?
        ");
        $stmt->execute([$view_exam_id]);
        $detailed_submission = $stmt->fetch();

        if ($detailed_submission) {
            $stmt = $pdo->prepare("
                SELECT sa.*, q.question_text, q.type as question_type, q.model_answer, q.correct_option, q.points 
                FROM student_answers sa 
                JOIN questions q ON sa.question_id = q.id 
                WHERE sa.student_exam_id = ?
                ORDER BY q.id ASC
            ");
            $stmt->execute([$view_exam_id]);
            $student_answers = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        die("Error fetching submission details: " . $e->getMessage());
    }
}

// Handle detailed questions view
$view_questions_exam_id = intval($_GET['view_questions'] ?? 0);
$selected_exam_details = null;
$exam_questions = [];

if ($view_questions_exam_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$view_questions_exam_id]);
        $selected_exam_details = $stmt->fetch();

        if ($selected_exam_details) {
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
            $stmt->execute([$view_questions_exam_id]);
            $exam_questions = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        die("Error fetching exam questions: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" style="width=device-width, initial-scale=1.0" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - Swaminarayan University</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Load Chart.js CDN for visual reports -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .section-tab-content { display: none; }
        .section-tab-content.active { display: block; }
        .admin-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 992px) {
            .admin-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header>
        <div class="navbar">
            <a href="admin.php" class="brand">
                <img src="assets/logo.png" alt="Swaminarayan University Logo">
                <div class="brand-text">
                    <span class="brand-name">Swaminarayan University</span>
                    <span class="brand-tagline">Faculty Panel</span>
                </div>
            </a>
            <div class="nav-actions">
                <button id="theme-toggle" class="btn" onclick="toggleTheme()" style="padding: 0.5rem; border-radius: 50%; font-size: 1.25rem; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border: none; cursor: pointer;">🌙</button>
                <span style="font-weight: 600;">👨‍🏫 <?php echo htmlspecialchars($_SESSION['admin_full_name']); ?></span>
                <a href="?action=logout" class="btn btn-danger" style="font-size: 0.9rem; padding: 0.5rem 1rem;">Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        
        <h2 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">University Assessment Center</h2>

        <!-- Tab Controls -->
        <div class="admin-tab-container">
            <div class="admin-tab active" onclick="switchAdminTab('exams-tab')">📝 Manage Exams</div>
            <div class="admin-tab" onclick="switchAdminTab('submissions-tab')">🎓 Student Submissions</div>
            <div class="admin-tab" onclick="switchAdminTab('proctor-tab')">🛡️ Proctor & Cheating Logs</div>
        </div>

        <?php if ($detailed_submission): ?>
            <!-- View Individual Submission & Evaluation Module -->
            <div class="card" style="margin-bottom: 2rem; border-color: var(--primary-saffron);">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                    <div>
                        <h3 style="margin: 0; color: var(--primary-maroon);">Evaluating: <?php echo htmlspecialchars($detailed_submission['full_name']); ?></h3>
                        <p style="margin: 0.25rem 0 0 0; color: var(--text-muted-light); font-size: 0.9rem;">
                            Enrollment: <strong><?php echo htmlspecialchars($detailed_submission['enrollment_no']); ?></strong> | 
                            Exam: <strong><?php echo htmlspecialchars($detailed_submission['exam_title']); ?></strong>
                        </p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <button class="btn btn-primary" onclick="runPythonGrader(<?php echo $detailed_submission['id']; ?>)">🤖 Run Python Auto-Grader</button>
                        <a href="admin.php" class="btn btn-secondary">Close Details</a>
                    </div>
                </div>

                <div class="admin-grid" style="grid-template-columns: 2fr 1fr; gap: 2rem;">
                    
                    <!-- Question Responses -->
                    <div>
                        <h4 style="margin-bottom: 1rem;">Answer Breakdown</h4>
                        <?php if (empty($student_answers)): ?>
                            <p style="color: var(--text-muted-light); text-align: center;">No answers submitted for this exam session.</p>
                        <?php else: ?>
                            <?php foreach ($student_answers as $idx => $ans): ?>
                                <div class="card" style="margin-bottom: 1rem; padding: 1.25rem; <?php echo $ans['question_type'] === 'descriptive' ? 'border-left: 4px solid var(--primary-saffron);' : ''; ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                        <strong>Q<?php echo ($idx + 1); ?>. (<?php echo htmlspecialchars($ans['question_type']); ?>)</strong>
                                        <span class="badge badge-info"><?php echo floatval($ans['marks_obtained']); ?> / <?php echo intval($ans['points']); ?> pt(s)</span>
                                    </div>
                                    <p style="font-weight: 600; margin: 0 0 0.75rem 0;"><?php echo htmlspecialchars($ans['question_text']); ?></p>
                                    
                                    <div style="background: rgba(148,163,184,0.06); padding: 0.75rem; border-radius: 6px; margin-bottom: 0.5rem;">
                                        <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted-light); font-weight: bold; display: block; margin-bottom: 0.25rem;">Student Response:</span>
                                        <p style="margin: 0; white-space: pre-wrap; font-size: 0.95rem;"><?php echo htmlspecialchars($ans['student_answer'] ? $ans['student_answer'] : '[No answer submitted]'); ?></p>
                                    </div>

                                    <?php if ($ans['question_type'] === 'mcq'): ?>
                                        <div style="font-size: 0.85rem; color: var(--text-muted-light);">
                                            Correct Option: <strong><?php echo htmlspecialchars($ans['correct_option']); ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <div style="background: rgba(93, 16, 29, 0.05); padding: 0.75rem; border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; border-left: 2px solid var(--primary-maroon);">
                                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--primary-maroon); font-weight: bold; display: block; margin-bottom: 0.25rem;">University Model Answer:</span>
                                            <p style="margin: 0; font-style: italic;"><?php echo htmlspecialchars($ans['model_answer']); ?></p>
                                        </div>

                                        <!-- Manual grading override form -->
                                        <form onsubmit="handleManualGrade(event, <?php echo $ans['id']; ?>)" style="display: flex; gap: 1rem; align-items: flex-end; border-top: 1px dashed var(--border-light); padding-top: 0.75rem;">
                                            <div class="form-group" style="margin: 0; width: 120px;">
                                                <label class="form-label" style="font-size: 0.75rem;">Adjust Marks</label>
                                                <input type="number" step="0.1" min="0" max="<?php echo $ans['points']; ?>" value="<?php echo floatval($ans['marks_obtained']); ?>" class="form-control" name="marks" required style="padding: 0.4rem;">
                                            </div>
                                            <div class="form-group" style="margin: 0; flex-grow: 1;">
                                                <label class="form-label" style="font-size: 0.75rem;">Grading Comments</label>
                                                <input type="text" value="<?php echo htmlspecialchars($ans['auto_feedback']); ?>" class="form-control" name="feedback" required style="padding: 0.4rem;">
                                            </div>
                                            <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 1rem; font-size: 0.85rem; height: 33px;">Override Score</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Grading Summary -->
                    <div>
                        <h4 style="margin-bottom: 1rem;">Submission Details</h4>
                        <div class="card" style="padding: 1.25rem;">
                            <div style="margin-bottom: 1rem;">
                                <span style="font-size: 0.75rem; color: var(--text-muted-light);">Final Score:</span>
                                <div style="font-size: 2.25rem; font-weight: 800; color: var(--primary-maroon);">
                                    <?php echo floatval($detailed_submission['score']); ?> / <?php echo intval($detailed_submission['total_possible_score']); ?>
                                </div>
                            </div>
                            <hr style="border: 0; border-top: 1px solid var(--border-light); margin: 1rem 0;">
                            <p style="margin: 0.5rem 0;">STATUS: <span class="badge badge-<?php echo $detailed_submission['status'] === 'graded' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($detailed_submission['status']); ?></span></p>
                            <p style="margin: 0.5rem 0;">Submitted: <strong><?php echo date('d M, Y H:i:s', strtotime($detailed_submission['submitted_at'])); ?></strong></p>
                            <p style="margin: 0.5rem 0; color: var(--danger);">Tab Switches: <strong><?php echo intval($detailed_submission['tab_switch_count']); ?> / 5</strong></p>
                            <p style="margin: 0.5rem 0; color: var(--danger);">Copy-Paste Incidents: <strong><?php echo intval($detailed_submission['copy_paste_count']); ?></strong></p>
                            
                            <h4 style="margin: 1.5rem 0 0.5rem 0; border-top: 1px solid var(--border-light); padding-top: 1rem;">Auto-Grader Execution Log</h4>
                            <div class="terminal-block" id="grader-log-box">
                                Waiting for auto-grader execution logs...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tab 1: Manage Exams -->
        <div id="exams-tab" class="section-tab-content active">
            <div class="admin-grid">
                
                <!-- Create Exam -->
                <div class="card">
                    <h3 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">➕ Create New Exam Template</h3>
                    <form onsubmit="handleCreateExam(event)">
                        <div class="form-group">
                            <label class="form-label">Exam Title</label>
                            <input type="text" id="exam-title" class="form-control" placeholder="Object Oriented Programming Concepts" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Subject Category</label>
                            <input type="text" id="exam-subject" class="form-control" placeholder="Information Technology" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Exam Duration (Minutes)</label>
                            <input type="number" id="exam-duration" class="form-control" placeholder="60" min="5" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Initialize Exam Template</button>
                    </form>
                </div>

                <!-- Add Questions Panel -->
                <div class="card">
                    <div style="margin-bottom: 1.5rem;">
                        <h3 style="color: var(--primary-maroon); margin: 0;">📝 Populate Questions</h3>
                    </div>
                    <form onsubmit="handleAddQuestion(event)">
                        <div class="form-group">
                            <label class="form-label">Select Exam Template</label>
                            <select id="q-exam-id" class="form-control" onchange="checkSelectedExamStatus()" required>
                                <option value="">-- Choose Exam Template --</option>
                                <?php foreach ($exams as $ex): ?>
                                    <option value="<?php echo $ex['id']; ?>" data-status="<?php echo htmlspecialchars($ex['status']); ?>" <?php echo $view_questions_exam_id === $ex['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ex['title']); ?> (<?php echo htmlspecialchars($ex['status']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div id="exam-completed-warning" style="display: none; margin-top: 0.75rem; padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: bold; background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid #dc2626;">⚠️ This exam is completed. Questions cannot be added, edited, or deleted.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Question Type</label>
                            <select id="q-type" class="form-control" onchange="toggleQuestionTypeInputs()" required>
                                <option value="mcq">Multiple Choice Question (MCQ)</option>
                                <option value="descriptive">Descriptive Answer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Question Text</label>
                            <textarea id="q-text" class="form-control" rows="3" placeholder="Enter question description..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Points/Weightage</label>
                            <input type="number" id="q-points" class="form-control" value="2" min="1" required>
                        </div>

                        <!-- MCQ Inputs -->
                        <div id="mcq-inputs">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                                <input type="text" id="q-opt-a" class="form-control" placeholder="Option A">
                                <input type="text" id="q-opt-b" class="form-control" placeholder="Option B">
                                <input type="text" id="q-opt-c" class="form-control" placeholder="Option C">
                                <input type="text" id="q-opt-d" class="form-control" placeholder="Option D">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Correct Option</label>
                                <select id="q-correct" class="form-control">
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                </select>
                            </div>
                        </div>

                        <!-- Descriptive Inputs -->
                        <div id="descriptive-inputs" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">University Model Answer (For NLP comparison)</label>
                                <textarea id="q-model" class="form-control" rows="4" placeholder="Enter keywords and detailed sentences student answer must match..."></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 1rem;">Save Question</button>
                    </form>
                </div>
            </div>

            <!-- List of Exams -->
            <div class="card" style="margin-top: 2rem;">
                <h3 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">📁 Existing Exam Templates</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Exam Title</th>
                                <th>Subject</th>
                                <th>Duration</th>
                                <th>Questions</th>
                                <th>Total Points</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($exams)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted-light);">No exam templates found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($exams as $ex): ?>
                                    <tr>
                                        <td><?php echo $ex['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($ex['title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($ex['subject']); ?></td>
                                        <td><?php echo intval($ex['duration_minutes']); ?> Mins</td>
                                        <td><span class="badge badge-info"><?php echo intval($ex['question_count']); ?> Qs</span></td>
                                        <td><span class="badge badge-success"><?php echo intval($ex['total_points']); ?> Pts</span></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $ex['status'] === 'active' ? 'success' : ($ex['status'] === 'draft' ? 'warning' : 'danger'); 
                                            ?>"><?php echo htmlspecialchars($ex['status']); ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                                                <a href="?view_questions=<?php echo $ex['id']; ?>#exams-tab" class="btn btn-secondary" style="padding: 0.25rem 0.6rem; font-size: 0.8rem; text-decoration: none;">👁️ View/Edit Questions</a>
                                                <button class="btn btn-secondary" onclick="openEditExamModal(<?php echo $ex['id']; ?>, '<?php echo addslashes($ex['title']); ?>', '<?php echo addslashes($ex['subject']); ?>', <?php echo $ex['duration_minutes']; ?>)" style="padding: 0.25rem 0.6rem; font-size: 0.8rem;">✏️ Edit</button>
                                                <button class="btn btn-danger" onclick="deleteExam(<?php echo $ex['id']; ?>)" style="padding: 0.25rem 0.6rem; font-size: 0.8rem;">🗑️ Delete</button>
                                                <?php if ($ex['status'] === 'draft'): ?>
                                                    <button class="btn btn-primary" onclick="updateExamStatus(<?php echo $ex['id']; ?>, 'active')" style="padding: 0.25rem 0.6rem; font-size: 0.8rem;">Go Live</button>
                                                <?php elseif ($ex['status'] === 'active'): ?>
                                                    <button class="btn btn-danger" onclick="updateExamStatus(<?php echo $ex['id']; ?>, 'completed')" style="padding: 0.25rem 0.6rem; font-size: 0.8rem;">Close Exam</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($selected_exam_details): ?>
                <!-- Question Viewer Panel -->
                <div class="card" style="margin-top: 2rem; border-color: var(--primary-maroon);" id="question-viewer-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <h3 style="margin: 0; color: var(--primary-maroon);">Exam Questions: <?php echo htmlspecialchars($selected_exam_details['title']); ?></h3>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted-light); font-size: 0.9rem;">
                                Subject: <strong><?php echo htmlspecialchars($selected_exam_details['subject']); ?></strong> | Duration: <strong><?php echo intval($selected_exam_details['duration_minutes']); ?> Mins</strong>
                            </p>
                        </div>
                        <a href="admin.php#exams-tab" class="btn btn-secondary" style="text-decoration: none;">Hide Questions</a>
                    </div>
                    
                    <div>
                        <?php if (empty($exam_questions)): ?>
                            <p style="color: var(--text-muted-light); text-align: center; padding: 2rem 0;">No questions added to this exam template yet. Use the "Populate Questions" form above to add some!</p>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <?php foreach ($exam_questions as $q_idx => $eq): ?>
                                    <div class="card" style="padding: 1.25rem; background: rgba(148,163,184,0.03); display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5rem;">
                                        <div style="flex-grow: 1;">
                                            <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                                <span class="badge badge-info" style="font-size: 0.7rem;">Q<?php echo ($q_idx + 1); ?></span>
                                                <span class="badge badge-success" style="font-size: 0.7rem; text-transform: uppercase;"><?php echo htmlspecialchars($eq['type']); ?></span>
                                                <span class="badge badge-warning" style="font-size: 0.7rem;"><?php echo intval($eq['points']); ?> Point(s)</span>
                                            </div>
                                            <p style="font-weight: 600; margin: 0 0 0.75rem 0;"><?php echo htmlspecialchars($eq['question_text']); ?></p>
                                            
                                            <?php if ($eq['type'] === 'mcq'): ?>
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 0.5rem;">
                                                    <div>A: <?php echo htmlspecialchars($eq['option_a']); ?></div>
                                                    <div>B: <?php echo htmlspecialchars($eq['option_b']); ?></div>
                                                    <div>C: <?php echo htmlspecialchars($eq['option_c']); ?></div>
                                                    <div>D: <?php echo htmlspecialchars($eq['option_d']); ?></div>
                                                </div>
                                                <div style="font-size: 0.85rem; font-weight: bold; color: var(--success);">
                                                    Correct Option: (<?php echo htmlspecialchars($eq['correct_option']); ?>)
                                                </div>
                                            <?php else: ?>
                                                <div style="background: rgba(93, 16, 29, 0.03); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; font-style: italic; border-left: 2px solid var(--primary-maroon);">
                                                    Model Answer: <?php echo htmlspecialchars($eq['model_answer']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (($selected_exam_details['status'] ?? '') !== 'completed'): ?>
                                             <div style="display: flex; flex-direction: column; gap: 0.35rem; width: 100px;">
                                                 <button class="btn btn-secondary" onclick='openEditQuestionModal(<?php echo json_encode($eq, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="padding: 0.4rem 0.75rem; font-size: 0.8rem; width: 100%;">✏️ Edit</button>
                                                 <button class="btn btn-danger" onclick="deleteQuestion(<?php echo $eq['id']; ?>, <?php echo $view_questions_exam_id; ?>)" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; width: 100%;">🗑️ Delete</button>
                                             </div>
                                         <?php else: ?>
                                             <div style="display: flex; flex-direction: column; gap: 0.35rem; width: 100px; color: var(--text-muted-light); font-size: 0.8rem; text-align: center; font-style: italic; font-weight: 600;">
                                                 🔒 Read-Only
                                             </div>
                                         <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 2: Student Submissions -->
        <div id="submissions-tab" class="section-tab-content">
            
            <!-- Live Proctoring Monitor Widget -->
            <div class="card" style="margin-bottom: 2rem; border-color: var(--primary-saffron);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="color: var(--primary-maroon); margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <span class="pulsing-dot"></span> 🔴 Live Student Exam Monitor
                    </h3>
                    <span class="badge badge-info" id="live-count-badge">0 Students Active</span>
                </div>
                <p style="font-size: 0.9rem; color: var(--text-muted-light); margin: 0 0 1.25rem 0;">
                    Real-time proctoring view of students currently taking an exam. Auto-refreshes every 5 seconds.
                </p>
                <div class="live-student-grid" id="live-monitor-grid">
                    <!-- Live sessions will be populated here via AJAX -->
                    <p style="text-align: center; color: var(--text-muted-light); width: 100%; grid-column: 1/-1; padding: 2rem 0;">
                        No students are currently taking exams.
                    </p>
                </div>
            </div>

            <!-- Statistical Analytics Card & Chart -->
            <div class="admin-grid" style="margin-bottom: 2rem;">
                <!-- Class Stats -->
                <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                    <h3 style="color: var(--primary-maroon); margin-bottom: 1rem;">📊 Class Performance Analytics</h3>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; text-align: center; margin-bottom: 1.5rem;">
                        <div style="background: rgba(148, 163, 184, 0.05); padding: 0.85rem; border-radius: 10px;">
                            <span style="font-size: 0.75rem; color: var(--text-muted-light);">Total Submissions</span>
                            <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary-maroon); margin-top: 0.25rem;" id="stats-total-submissions"><?php echo count($submissions); ?></div>
                        </div>
                        <div style="background: rgba(148, 163, 184, 0.05); padding: 0.85rem; border-radius: 10px;">
                            <span style="font-size: 0.75rem; color: var(--text-muted-light);">Class Average Score</span>
                            <div style="font-size: 1.6rem; font-weight: 800; color: var(--success); margin-top: 0.25rem;"><?php echo $class_avg; ?></div>
                        </div>
                        <div style="background: rgba(148, 163, 184, 0.05); padding: 0.85rem; border-radius: 10px;">
                            <span style="font-size: 0.75rem; color: var(--text-muted-light);">Avg Warnings / Student</span>
                            <div style="font-size: 1.6rem; font-weight: 800; color: var(--warning); margin-top: 0.25rem;"><?php echo $avg_warnings; ?></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.75rem;">
                        <button class="btn btn-primary" onclick="triggerSimulator()" style="flex-grow: 1; font-size: 0.9rem; padding: 0.6rem;">⚡ Run Student Submission Simulator</button>
                        <a href="admin.php?action=export_csv" class="btn btn-secondary" style="font-size: 0.9rem; padding: 0.6rem;">📥 Export Results to CSV</a>
                    </div>
                </div>

                <!-- Chart Visualization -->
                <div class="card">
                    <h3 style="color: var(--primary-maroon); margin-bottom: 1rem;">📈 Score Distribution</h3>
                    <div style="position: relative; height: 180px;">
                        <canvas id="gradeDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Search & Filters Panel -->
            <div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
                <div class="filters-bar">
                    <div class="search-input-wrapper">
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="submissions-search" class="form-control" placeholder="Search student name or enrollment number..." onkeyup="filterSubmissionsTable()">
                    </div>
                    <select id="submissions-filter-exam" class="form-control filter-select" onchange="filterSubmissionsTable()">
                        <option value="">-- All Exams --</option>
                        <?php foreach ($exams as $ex): ?>
                            <option value="<?php echo htmlspecialchars($ex['title']); ?>"><?php echo htmlspecialchars($ex['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="submissions-filter-status" class="form-control filter-select" onchange="filterSubmissionsTable()">
                        <option value="">-- Statuses --</option>
                        <option value="submitted">Submitted</option>
                        <option value="graded">Graded</option>
                        <option value="absent">Absent</option>
                    </select>
                    <select id="submissions-filter-score" class="form-control filter-select" onchange="filterSubmissionsTable()">
                        <option value="">-- Score Tiers --</option>
                        <option value="excellent">Excellent (80%+)</option>
                        <option value="first">First Class (60-79%)</option>
                        <option value="second">Second Class (40-59%)</option>
                        <option value="failed">Failed (<40%)</option>
                    </select>
                    <select id="submissions-filter-proctor" class="form-control filter-select" onchange="filterSubmissionsTable()">
                        <option value="">-- Proctor Risk --</option>
                        <option value="clean">Clean (No incidents)</option>
                        <option value="suspicious">Suspicious (Warnings > 0)</option>
                        <option value="high">High Risk (Switches >= 5)</option>
                    </select>
                    <button class="btn btn-secondary filter-btn" onclick="clearSubmissionsFilters()">Reset Filters</button>
                    <button class="btn btn-primary filter-btn" onclick="autoGradeAllPending()" style="margin-left: auto;">🤖 Auto-Grade All Pending</button>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-muted-light); margin: 0.75rem 0 0 0;" id="submissions-count-indicator">
                    Showing <?php echo count($submissions); ?> of <?php echo count($submissions); ?> submissions.
                </p>
            </div>

            <div class="card">
                <h3 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">📊 Exam Submissions List</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-submissions" onclick="toggleSelectAllSubmissions(this)" class="bulk-checkbox"></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(1)">Student <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(2)">Enrollment No <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(3)">Exam Paper <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(4)">Date Submitted <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(5)">Tab Switches <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(6)">Copy Pastes <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(7)">Score <span class="sort-indicator">↕</span></th>
                                <th class="sortable-header" onclick="sortSubmissionsTable(8)">Status <span class="sort-indicator">↕</span></th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="submissions-table-body">
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; color: var(--text-muted-light);">No student submissions recorded yet. Try running the Student Submission Simulator!</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $sub): 
                                    // Highlight rules for suspicious cheating logs
                                    $rowStyle = "";
                                    if ($sub['tab_switch_count'] >= 5) {
                                        $rowStyle = "background: rgba(239, 68, 68, 0.04); border-left: 4px solid var(--danger);";
                                    } elseif ($sub['tab_switch_count'] >= 3) {
                                        $rowStyle = "background: rgba(245, 158, 11, 0.03); border-left: 4px solid var(--warning);";
                                    }
                                ?>
                                    <tr style="<?php echo $rowStyle; ?>" 
                                        data-student-name="<?php echo htmlspecialchars($sub['full_name']); ?>" 
                                        data-enrollment="<?php echo htmlspecialchars($sub['enrollment_no']); ?>" 
                                        data-exam-title="<?php echo htmlspecialchars($sub['exam_title']); ?>" 
                                        data-status="<?php echo htmlspecialchars($sub['status']); ?>"
                                        data-score="<?php echo floatval($sub['score']); ?>"
                                        data-score-max="<?php echo intval($sub['total_possible_score']); ?>"
                                        data-warnings="<?php echo intval($sub['tab_switch_count']) + intval($sub['copy_paste_count']); ?>"
                                        data-tab-switches="<?php echo intval($sub['tab_switch_count']); ?>"
                                        data-copy-pastes="<?php echo intval($sub['copy_paste_count']); ?>">
                                        <td><input type="checkbox" class="submission-row-checkbox bulk-checkbox" value="<?php echo $sub['id']; ?>" onclick="updateBulkActionsBar()"></td>
                                        <td><strong><?php echo htmlspecialchars($sub['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($sub['enrollment_no']); ?></td>
                                        <td><?php echo htmlspecialchars($sub['exam_title']); ?></td>
                                        <td><?php echo $sub['submitted_at'] ? date('d M, H:i', strtotime($sub['submitted_at'])) : '[Not Finished]'; ?></td>
                                        <td style="<?php echo $sub['tab_switch_count'] >= 4 ? 'color: var(--danger); font-weight: bold;' : ''; ?>">
                                            <?php echo intval($sub['tab_switch_count']); ?> / 5
                                        </td>
                                        <td><?php echo intval($sub['copy_paste_count']); ?></td>
                                        <td>
                                            <strong><?php echo floatval($sub['score']); ?></strong> / <?php echo intval($sub['total_possible_score']); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $sub['status'] === 'graded' ? 'success' : ($sub['status'] === 'submitted' ? 'warning' : ($sub['status'] === 'absent' ? 'danger' : 'info')); 
                                            ?>"><?php echo htmlspecialchars($sub['status']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($sub['status'] === 'submitted'): ?>
                                                <button onclick="openEvaluationDrawer(<?php echo $sub['id']; ?>)" class="btn btn-primary" style="padding: 0.25rem 0.6rem; font-size: 0.8rem; border: none; cursor: pointer;">📝 Grade Now</button>
                                            <?php elseif ($sub['status'] === 'graded'): ?>
                                                <button onclick="openEvaluationDrawer(<?php echo $sub['id']; ?>)" class="btn btn-secondary" style="padding: 0.25rem 0.6rem; font-size: 0.8rem; border: none; cursor: pointer;">👁️ View Details</button>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted-light); font-size: 0.85rem; font-style: italic;"><?php echo ucfirst($sub['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Proctor Logs -->
        <div id="proctor-tab" class="section-tab-content">
            <div class="card">
                <h3 style="color: var(--danger); margin-bottom: 1.5rem;">🚨 Active Proctor Warnings & Violations</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Enrollment No</th>
                                <th>Exam Paper</th>
                                <th>Tab Switches</th>
                                <th>Copy-Paste Incidents</th>
                                <th>Proctor Assessment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $suspicious_count = 0;
                            foreach ($submissions as $sub): 
                                if ($sub['tab_switch_count'] > 0 || $sub['copy_paste_count'] > 0):
                                    $suspicious_count++;
                                    $is_flagged_cheating = ($sub['tab_switch_count'] >= 5);
                            ?>
                                <tr style="<?php echo $is_flagged_cheating ? 'background: rgba(239, 68, 68, 0.05);' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($sub['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($sub['enrollment_no']); ?></td>
                                    <td><?php echo htmlspecialchars($sub['exam_title']); ?></td>
                                    <td style="font-weight: 700; color: <?php echo $sub['tab_switch_count'] >= 4 ? 'var(--danger)' : 'var(--warning)'; ?>;">
                                        <?php echo intval($sub['tab_switch_count']); ?> / 5
                                    </td>
                                    <td style="font-weight: 700; color: <?php echo $sub['copy_paste_count'] > 0 ? 'var(--danger)' : 'inherit'; ?>;">
                                        <?php echo intval($sub['copy_paste_count']); ?>
                                    </td>
                                    <td>
                                        <?php if ($is_flagged_cheating): ?>
                                            <span class="badge badge-danger">Session Terminated (Cheating Locked)</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Warnings (Logged)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php 
                                endif; 
                            endforeach; 
                            if ($suspicious_count === 0):
                            ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted-light);">Excellent! No proctoring incidents logged. All students are compliant.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- Floating Bulk Actions Bar -->
    <div class="bulk-action-bar" id="bulk-actions-bar">
        <span id="bulk-selected-count" style="font-weight: 700;">0 items selected</span>
        <button class="btn btn-primary" onclick="triggerBulkAction('grade')" style="padding: 0.5rem 1rem; font-size: 0.85rem; margin: 0;">🤖 Auto-Grade</button>
        <button class="btn btn-secondary" onclick="triggerBulkAction('finalize')" style="padding: 0.5rem 1rem; font-size: 0.85rem; border-color: white; color: white; margin: 0;">🔒 Finalize Grades</button>
        <button class="btn btn-danger" onclick="triggerBulkAction('reset')" style="padding: 0.5rem 1rem; font-size: 0.85rem; margin: 0;">🔄 Reset Attempts</button>
        <button class="btn btn-secondary" onclick="clearSubmissionsSelection()" style="padding: 0.5rem 1rem; font-size: 0.85rem; border: none; color: #94a3b8; margin: 0;">Cancel</button>
    </div>

    <!-- Side Slide-Over Evaluation Drawer -->
    <div class="evaluation-drawer-overlay" id="drawer-overlay" onclick="closeEvaluationDrawer()"></div>
    <div class="evaluation-drawer" id="evaluation-drawer">
        <div class="drawer-header">
            <div>
                <h3 style="margin: 0; color: var(--primary-maroon);" id="drawer-student-name">Student Evaluation</h3>
                <p style="margin: 0.25rem 0 0 0; color: var(--text-muted-light); font-size: 0.9rem;" id="drawer-student-meta">
                    Enrollment: -- | Exam: --
                </p>
            </div>
            <button class="drawer-close" onclick="closeEvaluationDrawer()">×</button>
        </div>
        <div class="drawer-body" id="drawer-content-box">
            <!-- Content will be populated via AJAX -->
        </div>
    </div>

    <!-- Modal 1: Edit Exam Template -->
    <div class="modal-overlay" id="edit-exam-modal" onclick="closeModalOnOverlay(event, 'edit-exam-modal')">
        <div class="modal-card">
            <button class="close-modal-btn" onclick="closeModal('edit-exam-modal')">×</button>
            <h3 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">✏️ Edit Exam Template</h3>
            <form onsubmit="handleEditExam(event)">
                <input type="hidden" id="edit-exam-id">
                <div class="form-group">
                    <label class="form-label">Exam Title</label>
                    <input type="text" id="edit-exam-title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Subject Category</label>
                    <input type="text" id="edit-exam-subject" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Exam Duration (Minutes)</label>
                    <input type="number" id="edit-exam-duration" class="form-control" min="5" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Update Exam Template</button>
            </form>
        </div>
    </div>

    <!-- Modal 2: Edit Question -->
    <div class="modal-overlay" id="edit-question-modal" onclick="closeModalOnOverlay(event, 'edit-question-modal')">
        <div class="modal-card">
            <button class="close-modal-btn" onclick="closeModal('edit-question-modal')">×</button>
            <h3 style="color: var(--primary-maroon); margin-bottom: 1.5rem;">✏️ Edit Question</h3>
            <form onsubmit="handleEditQuestion(event)">
                <input type="hidden" id="edit-q-id">
                <div class="form-group">
                    <label class="form-label">Question Type</label>
                    <select id="edit-q-type" class="form-control" onchange="toggleEditQuestionTypeInputs()" required>
                        <option value="mcq">Multiple Choice Question (MCQ)</option>
                        <option value="descriptive">Descriptive Answer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Question Text</label>
                    <textarea id="edit-q-text" class="form-control" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Points/Weightage</label>
                    <input type="number" id="edit-q-points" class="form-control" min="1" required>
                </div>

                <!-- MCQ Inputs -->
                <div id="edit-mcq-inputs">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem;">Option A</label>
                            <input type="text" id="edit-q-opt-a" class="form-control" placeholder="Option A">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.75rem;">Option B</label>
                            <input type="text" id="edit-q-opt-b" class="form-control" placeholder="Option B">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.75rem;">Option C</label>
                            <input type="text" id="edit-q-opt-c" class="form-control" placeholder="Option C">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.75rem;">Option D</label>
                            <input type="text" id="edit-q-opt-d" class="form-control" placeholder="Option D">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Correct Option</label>
                        <select id="edit-q-correct" class="form-control">
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>
                </div>

                <!-- Descriptive Inputs -->
                <div id="edit-descriptive-inputs" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">University Model Answer (For NLP comparison)</label>
                        <textarea id="edit-q-model" class="form-control" rows="4" placeholder="Enter keywords and concepts..."></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 1rem;">Save Question Changes</button>
            </form>
        </div>
    </div>





    </div>

    <script src="assets/js/app.js"></script>
    <script>


        // 1. Tab Management
        function switchAdminTab(tabId) {
            const tabs = document.querySelectorAll('.admin-tab');
            tabs.forEach(t => t.classList.remove('active'));
            
            const contents = document.querySelectorAll('.section-tab-content');
            contents.forEach(c => c.classList.remove('active'));

            if (tabId === 'exams-tab') {
                tabs[0].classList.add('active');
                document.getElementById('exams-tab').classList.add('active');
            } else if (tabId === 'submissions-tab') {
                tabs[1].classList.add('active');
                document.getElementById('submissions-tab').classList.add('active');
            } else if (tabId === 'proctor-tab') {
                tabs[2].classList.add('active');
                document.getElementById('proctor-tab').classList.add('active');
            }
            
            localStorage.setItem('admin-active-tab', tabId);
        }

        // Check if selected exam is completed and toggle field disabled state
        function checkSelectedExamStatus() {
            const selectEl = document.getElementById('q-exam-id');
            if (!selectEl) return;
            const selectedOpt = selectEl.options[selectEl.selectedIndex];
            const status = selectedOpt ? selectedOpt.getAttribute('data-status') : '';
            
            const warningBox = document.getElementById('exam-completed-warning');
            const submitBtn = document.querySelector('#exams-tab form[onsubmit="handleAddQuestion(event)"] button[type="submit"]');
            
            const fieldsToToggle = [
                document.getElementById('q-type'),
                document.getElementById('q-text'),
                document.getElementById('q-points'),
                document.getElementById('q-opt-a'),
                document.getElementById('q-opt-b'),
                document.getElementById('q-opt-c'),
                document.getElementById('q-opt-d'),
                document.getElementById('q-correct'),
                document.getElementById('q-model')
            ];

            if (status === 'completed') {
                if (warningBox) warningBox.style.display = 'block';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerText = 'Exam Completed (Read-Only)';
                }
                fieldsToToggle.forEach(field => {
                    if (field) field.disabled = true;
                });
            } else {
                if (warningBox) warningBox.style.display = 'none';
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Save Question';
                }
                fieldsToToggle.forEach(field => {
                    if (field) field.disabled = false;
                });
            }
        }



        document.addEventListener('DOMContentLoaded', () => {
            // Check initial selected exam status
            checkSelectedExamStatus();


            const persistedTab = localStorage.getItem('admin-active-tab');
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('view_questions')) {
                switchAdminTab('exams-tab');
            } else if (urlParams.has('view_submission')) {
                switchAdminTab('submissions-tab');
                // Auto open the submission details in drawer if query param is set
                openEvaluationDrawer(parseInt(urlParams.get('view_submission')));
            } else if (persistedTab) {
                switchAdminTab(persistedTab);
            }

            // Start Live Monitor polling
            pollLiveSessions();
            setInterval(pollLiveSessions, 5000);

            // Initialize Chart.js Class Score Distribution Chart
            const ctx = document.getElementById('gradeDistributionChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Excellent (80%+)', 'First Class (60-79%)', 'Second Class (40-59%)', 'Failed (<40%)'],
                        datasets: [{
                            label: 'Number of Students',
                            data: [
                                <?php echo $excellent; ?>, 
                                <?php echo $first_class; ?>, 
                                <?php echo $second_class; ?>, 
                                <?php echo $failed; ?>
                            ],
                            backgroundColor: [
                                '#10B981', // green
                                '#3B82F6', // blue
                                '#F59E0B', // warning
                                '#EF4444'  // danger
                            ],
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1 }
                            }
                        }
                    }
                });
            }
            
            // Scroll to the question viewer if it is active
            const qViewer = document.getElementById('question-viewer-box');
            if (qViewer) {
                qViewer.scrollIntoView({ behavior: 'smooth' });
            }
        });

        // 2. Exam Management Actions
        function toggleQuestionTypeInputs() {
            const type = document.getElementById('q-type').value;
            if (type === 'mcq') {
                document.getElementById('mcq-inputs').style.display = 'block';
                document.getElementById('descriptive-inputs').style.display = 'none';
            } else {
                document.getElementById('mcq-inputs').style.display = 'none';
                document.getElementById('descriptive-inputs').style.display = 'block';
            }
        }

        async function handleCreateExam(event) {
            event.preventDefault();
            const payload = {
                action: 'create_exam',
                title: document.getElementById('exam-title').value,
                subject: document.getElementById('exam-subject').value,
                duration: document.getElementById('exam-duration').value
            };

            const res = await apiCall('api/admin_api.php', payload);
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(res.message, 'danger');
            }
        }

        async function handleAddQuestion(event) {
            event.preventDefault();
            const examId = document.getElementById('q-exam-id').value;
            const type = document.getElementById('q-type').value;
            const payload = {
                action: 'add_question',
                exam_id: examId,
                type: type,
                question_text: document.getElementById('q-text').value,
                points: document.getElementById('q-points').value
            };

            if (type === 'mcq') {
                payload.option_a = document.getElementById('q-opt-a').value;
                payload.option_b = document.getElementById('q-opt-b').value;
                payload.option_c = document.getElementById('q-opt-c').value;
                payload.option_d = document.getElementById('q-opt-d').value;
                payload.correct_option = document.getElementById('q-correct').value;
            } else {
                payload.model_answer = document.getElementById('q-model').value;
            }

            const res = await apiCall('api/admin_api.php', payload);
            if (res.success) {
                showToast(res.message, 'success');
                document.getElementById('q-text').value = '';
                if (type === 'mcq') {
                    document.getElementById('q-opt-a').value = '';
                    document.getElementById('q-opt-b').value = '';
                    document.getElementById('q-opt-c').value = '';
                    document.getElementById('q-opt-d').value = '';
                } else {
                    document.getElementById('q-model').value = '';
                }
                
                const currentViewingExam = <?php echo $view_questions_exam_id ? $view_questions_exam_id : 0; ?>;
                if (parseInt(examId) === currentViewingExam) {
                    setTimeout(() => {
                        window.location.search = `?view_questions=${examId}#exams-tab`;
                    }, 1000);
                }
            } else {
                showToast(res.message, 'danger');
            }
        }

        async function updateExamStatus(examId, status) {
            if (!confirm(`Are you sure you want to change exam status to ${status}?`)) return;

            const res = await apiCall('api/admin_api.php', {
                action: 'update_exam_status',
                exam_id: examId,
                status: status
            });

            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(res.message, 'danger');
            }
        }

        async function deleteExam(examId) {
            if (!confirm("Are you sure you want to delete this exam template? All questions and student responses will be permanently deleted!")) return;
            const res = await apiCall('api/admin_api.php', {
                action: 'delete_exam',
                exam_id: examId
            });
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(res.message, 'danger');
            }
        }

        // 3. Edit Exam Modal
        function openEditExamModal(id, title, subject, duration) {
            document.getElementById('edit-exam-id').value = id;
            document.getElementById('edit-exam-title').value = title;
            document.getElementById('edit-exam-subject').value = subject;
            document.getElementById('edit-exam-duration').value = duration;
            document.getElementById('edit-exam-modal').classList.add('active');
        }

        async function handleEditExam(event) {
            event.preventDefault();
            const payload = {
                action: 'edit_exam',
                exam_id: document.getElementById('edit-exam-id').value,
                title: document.getElementById('edit-exam-title').value,
                subject: document.getElementById('edit-exam-subject').value,
                duration: document.getElementById('edit-exam-duration').value
            };
            const res = await apiCall('api/admin_api.php', payload);
            if (res.success) {
                showToast(res.message, 'success');
                closeModal('edit-exam-modal');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(res.message, 'danger');
            }
        }

        // 4. Edit Question Modal
        function openEditQuestionModal(qObj) {
            document.getElementById('edit-q-id').value = qObj.id;
            document.getElementById('edit-q-text').value = qObj.question_text;
            document.getElementById('edit-q-points').value = qObj.points;
            document.getElementById('edit-q-type').value = qObj.type;
            
            toggleEditQuestionTypeInputs();
            
            if (qObj.type === 'mcq') {
                document.getElementById('edit-q-opt-a').value = qObj.option_a || '';
                document.getElementById('edit-q-opt-b').value = qObj.option_b || '';
                document.getElementById('edit-q-opt-c').value = qObj.option_c || '';
                document.getElementById('edit-q-opt-d').value = qObj.option_d || '';
                document.getElementById('edit-q-correct').value = qObj.correct_option || 'A';
            } else {
                document.getElementById('edit-q-model').value = qObj.model_answer || '';
            }
            document.getElementById('edit-question-modal').classList.add('active');
        }

        function toggleEditQuestionTypeInputs() {
            const type = document.getElementById('edit-q-type').value;
            if (type === 'mcq') {
                document.getElementById('edit-mcq-inputs').style.display = 'block';
                document.getElementById('edit-descriptive-inputs').style.display = 'none';
            } else {
                document.getElementById('edit-mcq-inputs').style.display = 'none';
                document.getElementById('edit-descriptive-inputs').style.display = 'block';
            }
        }

        async function handleEditQuestion(event) {
            event.preventDefault();
            const type = document.getElementById('edit-q-type').value;
            const payload = {
                action: 'edit_question',
                question_id: document.getElementById('edit-q-id').value,
                type: type,
                question_text: document.getElementById('edit-q-text').value,
                points: document.getElementById('edit-q-points').value
            };

            if (type === 'mcq') {
                payload.option_a = document.getElementById('edit-q-opt-a').value;
                payload.option_b = document.getElementById('edit-q-opt-b').value;
                payload.option_c = document.getElementById('edit-q-opt-c').value;
                payload.option_d = document.getElementById('edit-q-opt-d').value;
                payload.correct_option = document.getElementById('edit-q-correct').value;
            } else {
                payload.model_answer = document.getElementById('edit-q-model').value;
            }

            const res = await apiCall('api/admin_api.php', payload);
            if (res.success) {
                showToast(res.message, 'success');
                closeModal('edit-question-modal');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(res.message, 'danger');
            }
        }



        // 6. Submissions List & Column Sorting
        let currentSortColumn = -1;
        let currentSortAscending = true;

        function sortSubmissionsTable(colIndex) {
            const table = document.querySelector('#submissions-tab table');
            const tbody = document.getElementById('submissions-table-body');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if (rows.length === 0 || rows[0].cells.length < colIndex) return;

            if (currentSortColumn === colIndex) {
                currentSortAscending = !currentSortAscending;
            } else {
                currentSortColumn = colIndex;
                currentSortAscending = true;
            }

            // Update Sort Indicators
            const headers = table.querySelectorAll('th.sortable-header');
            headers.forEach((h, idx) => {
                const ind = h.querySelector('.sort-indicator');
                if (idx + 1 === colIndex) {
                    ind.innerText = currentSortAscending ? '▲' : '▼';
                } else {
                    ind.innerText = '↕';
                }
            });

            rows.sort((rowA, rowB) => {
                let cellA = rowA.cells[colIndex].innerText.trim();
                let cellB = rowB.cells[colIndex].innerText.trim();

                // Handle warning switches sort
                if (colIndex === 5 || colIndex === 6) {
                    cellA = parseInt(cellA.split('/')[0]) || 0;
                    cellB = parseInt(cellB.split('/')[0]) || 0;
                }
                // Handle score sort
                else if (colIndex === 7) {
                    cellA = parseFloat(cellA.split('/')[0]) || 0;
                    cellB = parseFloat(cellB.split('/')[0]) || 0;
                }
                // Handle enrollment / numerical sorts
                else if (!isNaN(cellA) && !isNaN(cellB)) {
                    cellA = parseFloat(cellA);
                    cellB = parseFloat(cellB);
                }

                if (cellA < cellB) return currentSortAscending ? -1 : 1;
                if (cellA > cellB) return currentSortAscending ? 1 : -1;
                return 0;
            });

            tbody.innerHTML = '';
            rows.forEach(r => tbody.appendChild(r));
        }

        // 7. Advanced Filtering
        function filterSubmissionsTable() {
            const searchVal = document.getElementById('submissions-search').value.toLowerCase();
            const examFilter = document.getElementById('submissions-filter-exam').value;
            const statusFilter = document.getElementById('submissions-filter-status').value;
            const scoreFilter = document.getElementById('submissions-filter-score').value;
            const proctorFilter = document.getElementById('submissions-filter-proctor').value;
            
            const rows = document.querySelectorAll('#submissions-table-body tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (row.cells.length <= 1) return; // skip no record row
                
                const name = row.getAttribute('data-student-name').toLowerCase();
                const enroll = row.getAttribute('data-enrollment').toLowerCase();
                const exam = row.getAttribute('data-exam-title');
                const status = row.getAttribute('data-status');
                const score = parseFloat(row.getAttribute('data-score')) || 0;
                const scoreMax = parseInt(row.getAttribute('data-score-max')) || 1;
                const warnings = parseInt(row.getAttribute('data-warnings')) || 0;
                const tabSwitches = parseInt(row.getAttribute('data-tab-switches')) || 0;
                
                const matchesSearch = name.includes(searchVal) || enroll.includes(searchVal);
                const matchesExam = examFilter === "" || exam === examFilter;
                const matchesStatus = statusFilter === "" || status === statusFilter;
                
                // Score Filter Calculation
                let matchesScore = true;
                if (scoreFilter) {
                    const pct = (score / scoreMax) * 100;
                    if (scoreFilter === 'excellent') matchesScore = (pct >= 80);
                    else if (scoreFilter === 'first') matchesScore = (pct >= 60 && pct < 80);
                    else if (scoreFilter === 'second') matchesScore = (pct >= 40 && pct < 60);
                    else if (scoreFilter === 'failed') matchesScore = (pct < 40);
                }
                
                // Proctor Risk Filter Calculation
                let matchesProctor = true;
                if (proctorFilter) {
                    if (proctorFilter === 'clean') matchesProctor = (warnings === 0);
                    else if (proctorFilter === 'suspicious') matchesProctor = (warnings > 0 && tabSwitches < 5);
                    else if (proctorFilter === 'high') matchesProctor = (tabSwitches >= 5);
                }
                
                if (matchesSearch && matchesExam && matchesStatus && matchesScore && matchesProctor) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            document.getElementById('submissions-count-indicator').innerText = `Showing ${visibleCount} of ${rows.length} submissions.`;
        }

        function clearSubmissionsFilters() {
            document.getElementById('submissions-search').value = '';
            document.getElementById('submissions-filter-exam').value = '';
            document.getElementById('submissions-filter-status').value = '';
            document.getElementById('submissions-filter-score').value = '';
            document.getElementById('submissions-filter-proctor').value = '';
            filterSubmissionsTable();
        }

        // 8. Bulk Actions Logic
        function toggleSelectAllSubmissions(masterCheckbox) {
            const checkboxes = document.querySelectorAll('.submission-row-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = masterCheckbox.checked;
                }
            });
            updateBulkActionsBar();
        }

        function updateBulkActionsBar() {
            const checkboxes = Array.from(document.querySelectorAll('.submission-row-checkbox:checked'));
            const count = checkboxes.length;
            
            const bar = document.getElementById('bulk-actions-bar');
            if (count > 0) {
                document.getElementById('bulk-selected-count').innerText = `${count} submission(s) selected`;
                bar.classList.add('active');
            } else {
                bar.classList.remove('active');
                document.getElementById('select-all-submissions').checked = false;
            }
        }

        function clearSubmissionsSelection() {
            const checkboxes = document.querySelectorAll('.submission-row-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            document.getElementById('select-all-submissions').checked = false;
            updateBulkActionsBar();
        }

        async function triggerBulkAction(actionType) {
            const checkboxes = Array.from(document.querySelectorAll('.submission-row-checkbox:checked'));
            const ids = checkboxes.map(cb => parseInt(cb.value));
            
            if (ids.length === 0) return;
            
            let confirmMsg = `Are you sure you want to run this bulk action on ${ids.length} selected item(s)?`;
            if (actionType === 'reset') {
                confirmMsg = `🚨 WARNING: Resetting attempts will permanently DELETE the answers for these ${ids.length} student(s) and let them retake the exam. Continue?`;
            }
            
            if (!confirm(confirmMsg)) return;
            
            showToast("Processing bulk action...", "info");
            const res = await apiCall('api/admin_api.php', {
                action: 'bulk_action',
                bulk_action: actionType,
                student_exam_ids: ids
            });
            
            if (res.success) {
                showToast(res.message, "success");
                clearSubmissionsSelection();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(res.message, "danger");
            }
        }

        // 9. Sliding Evaluation Drawer & AI Highlights
        let activeOpenStudentExamId = 0;
        
        async function openEvaluationDrawer(studentExamId) {
            activeOpenStudentExamId = studentExamId;
            const drawer = document.getElementById('evaluation-drawer');
            const overlay = document.getElementById('drawer-overlay');
            const contentBox = document.getElementById('drawer-content-box');
            
            contentBox.innerHTML = `
                <div style="text-align: center; padding: 3rem 0; color: var(--text-muted-light);">
                    <div class="pulsing-dot" style="margin: 0 auto 1rem auto; width: 12px; height: 12px;"></div>
                    Fetching submission details from database...
                </div>
            `;
            
            drawer.classList.add('active');
            overlay.classList.add('active');
            
            const res = await apiCall('api/admin_api.php', {
                action: 'get_submission_details',
                student_exam_id: studentExamId
            });
            
            if (res.success) {
                renderEvaluationDetails(res.submission, res.answers);
            } else {
                contentBox.innerHTML = `<p style="color: var(--danger); text-align: center; padding: 2rem 0;">${res.message}</p>`;
            }
        }

        function closeEvaluationDrawer() {
            document.getElementById('evaluation-drawer').classList.remove('active');
            document.getElementById('drawer-overlay').classList.remove('active');
            activeOpenStudentExamId = 0;
            
            // Clean URL query parameters if openEvaluationDrawer was triggered on load
            const url = new URL(window.location);
            if (url.searchParams.has('view_submission')) {
                url.searchParams.delete('view_submission');
                window.history.replaceState({}, '', url);
            }
        }

        function renderEvaluationDetails(sub, answers) {
            document.getElementById('drawer-student-name').innerText = `Evaluating: ${sub.full_name}`;
            document.getElementById('drawer-student-meta').innerHTML = `
                Enrollment: <strong>${sub.enrollment_no}</strong> | 
                Contact: <strong>${sub.contact_no || 'N/A'}</strong> | 
                Exam: <strong>${sub.exam_title}</strong>
            `;
            
            const pct = (sub.total_possible_score > 0) ? (sub.score / sub.total_possible_score) * 100 : 0;
            
            let html = `
                <div class="admin-grid" style="grid-template-columns: 2fr 1.2fr; gap: 1.5rem; align-items: start;">
                    <div>
                        <h4 style="margin-bottom: 1rem; color: var(--primary-maroon);">Question & Answer Breakdown</h4>
            `;
            
            if (answers.length === 0) {
                html += `<p style="color: var(--text-muted-light);">No student answers recorded for this exam session.</p>`;
            } else {
                answers.forEach((ans, idx) => {
                    const isDescriptive = ans.question_type === 'descriptive';
                    const marks = parseFloat(ans.marks_obtained);
                    const pts = parseInt(ans.points);
                    
                    html += `
                        <div class="card" style="margin-bottom: 1rem; padding: 1.25rem; border-left: 4px solid ${isDescriptive ? 'var(--primary-saffron)' : 'var(--success)'};">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <strong>Q${idx + 1}. (${ans.question_type.toUpperCase()})</strong>
                                <span class="badge badge-info" style="font-size: 0.8rem;">${marks} / ${pts} pts</span>
                            </div>
                            <p style="font-weight: 600; margin: 0 0 0.75rem 0;">${escapeHTML(ans.question_text)}</p>
                    `;
                    
                    // Student Answer display with matched keyword highlights
                    let studentAnsText = ans.student_answer ? ans.student_answer : '[No answer submitted]';
                    if (isDescriptive && ans.student_answer && ans.model_answer) {
                        studentAnsText = highlightMatchingKeywords(ans.student_answer, ans.model_answer);
                    }
                    
                    html += `
                            <div style="background: rgba(148,163,184,0.06); padding: 0.75rem; border-radius: 6px; margin-bottom: 0.75rem;">
                                <span style="font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted-light); font-weight: bold; display: block; margin-bottom: 0.25rem;">Student Response:</span>
                                <p style="margin: 0; white-space: pre-wrap; font-size: 0.95rem;">${studentAnsText}</p>
                            </div>
                    `;
                    
                    if (ans.question_type === 'mcq') {
                        html += `
                            <div style="font-size: 0.85rem; color: var(--text-muted-light);">
                                Correct Option: <strong style="color: var(--success); font-size: 1rem;">(${ans.correct_option})</strong>
                            </div>
                        `;
                    } else {
                        // Descriptive model answer
                        html += `
                            <div style="background: rgba(93, 16, 29, 0.04); padding: 0.75rem; border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; border-left: 2px solid var(--primary-maroon);">
                                <span style="font-size: 0.7rem; text-transform: uppercase; color: var(--primary-maroon); font-weight: bold; display: block; margin-bottom: 0.25rem;">University Model Answer:</span>
                                <p style="margin: 0; font-style: italic;">${escapeHTML(ans.model_answer)}</p>
                            </div>
                            
                            <!-- AI Grader Feedback -->
                            <div style="font-size: 0.85rem; color: var(--text-muted-light); margin-bottom: 1rem;">
                                Grader Feedback: <i style="color: var(--primary-maroon); font-weight: bold;">${escapeHTML(ans.auto_feedback || 'No feedback logged')}</i>
                            </div>

                            <!-- Manual Score Override Tool -->
                            <form onsubmit="overrideDrawerGrade(event, ${ans.id})" style="border-top: 1px dashed var(--border-light); padding-top: 0.75rem;">
                                <div style="display: flex; gap: 0.5rem; align-items: flex-end;">
                                    <div class="form-group" style="margin: 0; width: 100px;">
                                        <label class="form-label" style="font-size: 0.7rem;">Marks (0-${pts})</label>
                                        <input type="number" step="0.1" min="0" max="${pts}" value="${marks}" class="form-control" name="marks" id="override-marks-${ans.id}" required style="padding: 0.4rem; font-size: 0.85rem;">
                                    </div>
                                    <div class="form-group" style="margin: 0; flex-grow: 1;">
                                        <label class="form-label" style="font-size: 0.7rem;">Comments</label>
                                        <input type="text" value="${escapeHTML(ans.auto_feedback || '')}" class="form-control" name="feedback" id="override-feedback-${ans.id}" required style="padding: 0.4rem; font-size: 0.85rem;">
                                    </div>
                                    <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; height: 32px; margin: 0;">Save</button>
                                </div>
                                
                                <!-- Quick Comment & Preset Tags -->
                                <div style="margin-top: 0.5rem;">
                                    <span style="font-size: 0.7rem; color: var(--text-muted-light); display: block; margin-bottom: 0.2rem;">Quick Scores:</span>
                                    <div class="preset-btn-container">
                                        <button type="button" class="btn-preset" onclick="setPresetScore(${ans.id}, ${pts})">Full Marks</button>
                                        <button type="button" class="btn-preset" onclick="setPresetScore(${ans.id}, ${pts / 2})">Half Marks</button>
                                        <button type="button" class="btn-preset" onclick="setPresetScore(${ans.id}, 0)">Zero Marks</button>
                                        <button type="button" class="btn-preset" onclick="adjustPresetScore(${ans.id}, 0.5, ${pts})">+0.5</button>
                                        <button type="button" class="btn-preset" onclick="adjustPresetScore(${ans.id}, -0.5, ${pts})">-0.5</button>
                                    </div>
                                    <span style="font-size: 0.7rem; color: var(--text-muted-light); display: block; margin: 0.5rem 0 0.2rem 0;">Feedback Presets:</span>
                                    <div style="display: flex; flex-wrap: wrap;">
                                        <span class="preset-tag" onclick="setPresetFeedback(${ans.id}, 'Excellent explanation, covered all major concepts.')">Excellent explanation</span>
                                        <span class="preset-tag" onclick="setPresetFeedback(${ans.id}, 'Correct keywords matched, well structured.')">Correct keywords</span>
                                        <span class="preset-tag" onclick="setPresetFeedback(${ans.id}, 'Incomplete response, missing core keywords.')">Incomplete response</span>
                                        <span class="preset-tag" onclick="setPresetFeedback(${ans.id}, 'Irrelevant answer, does not match model concepts.')">Irrelevant answer</span>
                                    </div>
                                </div>
                            </form>
                        `;
                    }
                    html += `</div>`;
                });
            }
            
            html += `
                    </div>
                    
                    <!-- Sidebar Evaluation Summary -->
                    <div style="position: sticky; top: 0;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary-maroon);">Session Profile</h4>
                        <div class="card" style="padding: 1.25rem; margin-bottom: 1rem; text-align: center;">
                            <img src="${sub.profile_photo ? sub.profile_photo : 'assets/uploads/default-avatar.png'}" 
                                 class="live-student-avatar" 
                                 style="width: 80px; height: 80px; margin: 0 auto 0.75rem auto; border-width: 3px; display: block;" 
                                 onerror="this.src='assets/uploads/default-avatar.png'">
                            <h4 style="margin: 0;">${escapeHTML(sub.full_name)}</h4>
                            <p style="margin: 0.25rem 0 0 0; font-size: 0.85rem; color: var(--text-muted-light);">${sub.enrollment_no}</p>
                            <p style="margin: 0.25rem 0 0 0; font-size: 0.8rem; color: var(--text-muted-light); word-break: break-all;">${sub.student_email}</p>
                        </div>
                        
                        <div class="card" style="padding: 1.25rem;">
                            <div style="margin-bottom: 1rem;">
                                <span style="font-size: 0.75rem; color: var(--text-muted-light); font-weight: bold; display: block; text-transform: uppercase;">Overall Marks:</span>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--primary-maroon);">
                                    ${parseFloat(sub.score)} / ${parseInt(sub.total_possible_score)}
                                </div>
                                <div class="similarity-gauge-container" style="margin-top: 0.5rem;">
                                    <div class="similarity-gauge-bar">
                                        <div class="similarity-gauge-fill" style="width: ${pct}%"></div>
                                    </div>
                                    <strong style="font-size: 0.85rem;">${Math.round(pct)}%</strong>
                                </div>
                            </div>
                            
                            <hr style="border: 0; border-top: 1px solid var(--border-light); margin: 1rem 0;">
                            
                            <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 1.5rem;">
                                <div>Status: <span class="badge badge-${sub.status === 'graded' ? 'success' : 'warning'}">${sub.status.toUpperCase()}</span></div>
                                <div>Submitted: <strong>${formatDateString(sub.submitted_at)}</strong></div>
                                <div style="color: var(--danger);">Tab Switches: <strong>${parseInt(sub.tab_switch_count)} / 5</strong></div>
                                <div style="color: var(--danger);">Copy Pastes: <strong>${parseInt(sub.copy_paste_count)}</strong></div>
                            </div>
                            
                            <button class="btn btn-primary" onclick="runPythonGraderInDrawer(${sub.id})" style="width: 100%; font-size: 0.9rem; padding: 0.6rem;">🤖 Run AI Auto-Grader</button>
                            
                            <h4 style="margin: 1.5rem 0 0.5rem 0; border-top: 1px solid var(--border-light); padding-top: 1rem; font-size: 0.9rem;">Python AI Execution Logs:</h4>
                            <div class="terminal-block" id="drawer-grader-log-box" style="max-height: 150px; font-size: 0.75rem; padding: 0.75rem;">
                                Waiting for auto-grader execution logs...
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('drawer-content-box').innerHTML = html;
        }

        // Drawer Scoring Helpers
        function setPresetScore(answerId, score) {
            const input = document.getElementById(`override-marks-${answerId}`);
            if (input) input.value = score.toFixed(1);
        }

        function adjustPresetScore(answerId, step, maxPoints) {
            const input = document.getElementById(`override-marks-${answerId}`);
            if (input) {
                let val = parseFloat(input.value) || 0;
                val = Math.max(0, Math.min(maxPoints, val + step));
                input.value = val.toFixed(1);
            }
        }

        function setPresetFeedback(answerId, comment) {
            const input = document.getElementById(`override-feedback-${answerId}`);
            if (input) input.value = comment;
        }

        async function overrideDrawerGrade(event, answerId) {
            event.preventDefault();
            const marks = document.getElementById(`override-marks-${answerId}`).value;
            const feedback = document.getElementById(`override-feedback-${answerId}`).value;
            
            showToast("Saving overrides...", "info");
            const res = await apiCall('api/admin_api.php', {
                action: 'manual_grade',
                answer_id: answerId,
                marks: marks,
                feedback: feedback
            });
            
            if (res.success) {
                showToast(res.message, 'success');
                // Refresh drawer details
                if (activeOpenStudentExamId) {
                    openEvaluationDrawer(activeOpenStudentExamId);
                }
            } else {
                showToast(res.message, 'danger');
            }
        }

        async function runPythonGraderInDrawer(studentExamId) {
            const logBox = document.getElementById('drawer-grader-log-box');
            if (logBox) logBox.innerText = 'Invoking Python AI grader engine... Please wait...';

            const res = await apiCall('api/admin_api.php', {
                action: 'run_grader',
                student_exam_id: studentExamId
            });

            if (res.success) {
                showToast(res.message, 'success');
                if (logBox) logBox.innerText = res.grader_log;
                setTimeout(() => {
                    openEvaluationDrawer(studentExamId);
                }, 2000);
            } else {
                showToast(res.message, 'danger');
                if (logBox) logBox.innerText = res.grader_log || 'Grader execution failed. Confirm Python configuration.';
            }
        }

        // Side-by-side keyword matching highlighter
        function highlightMatchingKeywords(studentAns, modelAns) {
            if (!studentAns || !modelAns) return escapeHTML(studentAns || '');
            
            const stopwords = new Set(['the', 'a', 'an', 'and', 'but', 'is', 'are', 'was', 'were', 'it', 'they', 'we', 'you', 'he', 'she', 'of', 'in', 'to', 'for', 'with', 'on', 'at', 'by', 'an', 'or', 'as', 'that', 'this']);
            
            // Extract unique keywords from model answer (length >= 4 and not stopwords)
            const cleanModel = modelAns.toLowerCase().replace(/[^\w\s]/g, '');
            const modelWords = new Set(cleanModel.split(/\s+/).filter(w => w.length >= 4 && !stopwords.has(w)));
            
            let htmlText = escapeHTML(studentAns);
            
            // Escape special chars for regex
            modelWords.forEach(word => {
                if (!word) return;
                try {
                    // Match the word as a full-word boundary case-insensitively
                    const regex = new RegExp(`\\b(${word})\\b`, 'gi');
                    htmlText = htmlText.replace(regex, '<span class="highlight-match">$1</span>');
                } catch(e) {}
            });
            
            return htmlText;
        }

        // 10. Live Exam Active Monitor Polling
        let activeLiveExamCache = {}; // student_exam_id -> warnings count

        async function pollLiveSessions() {
            // Only poll if Submissions tab is visible to conserve network resources
            const subTab = document.getElementById('submissions-tab');
            if (!subTab || !subTab.classList.contains('active')) return;
            
            const res = await apiCall('api/admin_api.php', { action: 'get_live_sessions' });
            if (res.success) {
                renderLiveSessions(res.sessions);
            }
        }

        function renderLiveSessions(sessions) {
            const grid = document.getElementById('live-monitor-grid');
            const countBadge = document.getElementById('live-count-badge');
            
            countBadge.innerText = `${sessions.length} Student(s) Active`;
            
            if (sessions.length === 0) {
                grid.innerHTML = `
                    <p style="text-align: center; color: var(--text-muted-light); width: 100%; grid-column: 1/-1; padding: 2rem 0; margin: 0;">
                        No students are currently taking exams.
                    </p>
                `;
                activeLiveExamCache = {};
                return;
            }
            
            let html = '';
            let currentPollIds = {};
            
            sessions.forEach(sess => {
                currentPollIds[sess.id] = true;
                const totalWarnings = parseInt(sess.tab_switch_count) + parseInt(sess.copy_paste_count);
                const elapsedMin = Math.floor(sess.elapsed_seconds / 60);
                const duration = parseInt(sess.duration_minutes);
                
                // Proctor Alert trigger if warning count increased
                const prevWarnings = activeLiveExamCache[sess.id];
                if (prevWarnings !== undefined && totalWarnings > prevWarnings) {
                    showToast(`⚠️ PROCTOR WARNING: "${sess.full_name}" triggered alert! (${sess.tab_switch_count}/5 Switches, ${sess.copy_paste_count} Copy-Pastes)`, 'danger');
                    // Play a soft alarm alert sound if allowed
                    try {
                        const context = new (window.AudioContext || window.webkitAudioContext)();
                        const osc = context.createOscillator();
                        osc.type = "sine";
                        osc.frequency.value = 880;
                        osc.connect(context.destination);
                        osc.start();
                        setTimeout(() => osc.stop(), 150);
                    } catch(e) {}
                }
                activeLiveExamCache[sess.id] = totalWarnings;
                
                // Calculate elapsed time display
                const timeStr = `${elapsedMin}/${duration} Mins`;
                const progressPct = Math.min(100, (elapsedMin / duration) * 100);
                
                // Proctor risk class
                let riskBadge = '<span class="badge badge-success">CLEAN</span>';
                let cardBorder = '';
                if (sess.tab_switch_count >= 5) {
                    riskBadge = '<span class="badge badge-danger">TERMINATED</span>';
                    cardBorder = 'border-color: var(--danger); background: rgba(239, 68, 68, 0.02);';
                } else if (sess.tab_switch_count >= 3 || sess.copy_paste_count > 0) {
                    riskBadge = '<span class="badge badge-warning">SUSPICIOUS</span>';
                    cardBorder = 'border-color: var(--warning); background: rgba(245, 158, 11, 0.02);';
                }
                
                html += `
                    <div class="live-student-card" style="${cardBorder}">
                        <div class="live-header">
                            <div class="live-student-info">
                                <img src="${sess.profile_photo ? sess.profile_photo : 'assets/uploads/default-avatar.png'}" 
                                     class="live-student-avatar" 
                                     onerror="this.src='assets/uploads/default-avatar.png'">
                                <div>
                                    <strong style="font-size: 0.95rem; display: block;">${escapeHTML(sess.full_name)}</strong>
                                    <span style="font-size: 0.75rem; color: var(--text-muted-light);">${sess.enrollment_no}</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                ${riskBadge}
                                <span class="pulsing-dot"></span>
                            </div>
                        </div>
                        
                        <div style="font-size: 0.85rem; margin-top: 0.25rem;">
                            Exam Paper: <strong style="color: var(--primary-maroon);">${escapeHTML(sess.exam_title)}</strong>
                        </div>
                        
                        <div class="live-stats">
                            <span>⏱️ Time: <strong>${timeStr}</strong></span>
                            <span class="${sess.tab_switch_count >= 4 ? 'live-warnings' : ''}">🎴 Switches: <strong>${sess.tab_switch_count}/5</strong></span>
                            <span>📋 Copied: <strong>${sess.copy_paste_count}</strong></span>
                        </div>
                        
                        <div class="similarity-gauge-bar" style="height: 4px; margin-top: 0.25rem;">
                            <div class="similarity-gauge-fill" style="width: ${progressPct}%; background: var(--gradient-maroon-saffron);"></div>
                        </div>
                        
                        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                            <button onclick="sendProctorWarning(${sess.id}, '${escapeHTML(sess.full_name)}')" class="btn btn-secondary" style="padding: 0.4rem; font-size: 0.8rem; border-radius: 6px; flex: 1; background: var(--gradient-saffron-gold); color: #fff; border: none; font-weight: 600;">
                                ⚠️ Warn
                            </button>
                            <button onclick="forceSubmitExam(${sess.id}, '${escapeHTML(sess.full_name)}')" class="btn btn-danger" style="padding: 0.4rem; font-size: 0.8rem; border-radius: 6px; flex: 1.2;">
                                🔒 Terminate
                            </button>
                        </div>
                    </div>
                `;
            });
            
            // Clean up cache of disconnected students
            Object.keys(activeLiveExamCache).forEach(id => {
                if (!currentPollIds[id]) {
                    delete activeLiveExamCache[id];
                }
            });
            
            grid.innerHTML = html;
        }

        async function forceSubmitExam(studentExamId, studentName) {
            if (!confirm(`Are you sure you want to forcibly close and submit the exam for student "${studentName}"? This will lock their live exam view instantly.`)) return;
            
            showToast("Force submitting exam...", "info");
            const res = await apiCall('api/admin_api.php', {
                action: 'force_submit_exam',
                student_exam_id: studentExamId
            });
            
            if (res.success) {
                showToast(res.message, "success");
                pollLiveSessions();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(res.message, "danger");
            }
        }

        async function sendProctorWarning(studentExamId, studentName) {
            const defaultWarning = "Please focus on your exam screen. You are moving out of window focus or switching tabs.";
            const msg = prompt(`Enter proctor warning message for student "${studentName}":`, defaultWarning);
            
            if (msg === null) return;
            const message = msg.trim();
            if (message === '') {
                showToast("Warning message cannot be empty.", "warning");
                return;
            }

            showToast("Sending warning alert...", "info");
            const res = await apiCall('api/admin_api.php', {
                action: 'send_proctor_warning',
                student_exam_id: studentExamId,
                message: message
            });

            if (res.success) {
                showToast("Warning broadcasted successfully!", "success");
            } else {
                showToast(res.message, "danger");
            }
        }

        // 11. Modal Utilities
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function closeModalOnOverlay(event, modalId) {
            if (event.target === document.getElementById(modalId)) {
                closeModal(modalId);
            }
        }

        // Helper string formatters
        function escapeHTML(str) {
            if (!str) return '';
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function formatDateString(str) {
            if (!str) return '--';
            const d = new Date(str);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) + ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        }

        // Simulator
        async function triggerSimulator() {
            if (!confirm("This will simulate 3 mock students (Rohit, Priya, Amit), generate their answers, automatically run the autograder, and refresh. Continue?")) return;
            
            showToast("Generating mock student submissions...", "info");
            
            const res = await apiCall('api/admin_api.php', {
                action: 'simulate_mock_submissions'
            });
            
            if (res.success) {
                showToast(res.message, "success");
                localStorage.setItem('admin-active-tab', 'submissions-tab');
                setTimeout(() => window.location.href = 'admin.php', 1500);
            } else {
                showToast(res.message, "danger");
            }
        }
        // Run auto-grading for all pending student submissions
        async function autoGradeAllPending() {
            if (!confirm("Are you sure you want to run the auto-grader for all pending submissions?")) return;
            
            showToast("Auto-grading all pending submissions... Please wait...", "info");
            
            const res = await apiCall('api/admin_api.php', {
                action: 'auto_grade_all_pending'
            });
            
            if (res.success) {
                showToast(res.message, "success");
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showToast(res.message, "danger");
            }
        }
    </script>
</body>
</html>
