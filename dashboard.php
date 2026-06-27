<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Log out action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Check auth
if (!isset($_SESSION['student_user_id'])) {
    header('Location: index.php');
    exit;
}

$student_id = $_SESSION['student_user_id'];
$full_name = $_SESSION['student_full_name'];
$enrollment_no = $_SESSION['student_enrollment_no'];

// Fetch student details (email, contact, photo)
try {
    $stmt = $pdo->prepare("SELECT email, contact_no, profile_photo FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student_data = $stmt->fetch();
    $student_email = $student_data['email'] ?? '';
    $student_contact = $student_data['contact_no'] ?? '';
    $student_photo = $student_data['profile_photo'] ?? '';
} catch (PDOException $e) {
    $student_email = '';
    $student_contact = '';
    $student_photo = '';
}

// Process profile update form
if (isset($_POST['update_profile'])) {
    $new_name = trim($_POST['full_name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_contact = trim($_POST['contact_no'] ?? '');
    $new_pass = $_POST['password'] ?? '';
    
    if (empty($new_name) || empty($new_email)) {
        $error_msg = "Name and Email are required.";
    } else {
        try {
            // Check if email already exists for another user
            $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
            $stmt->execute([$new_email, $student_id]);
            if ($stmt->fetch()) {
                $error_msg = "This email address is already in use by another user.";
            } else {
                
                // Process File Upload
                $photo_path = $student_photo; // default to existing
                
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['profile_photo']['tmp_name'];
                    $fileName = $_FILES['profile_photo']['name'];
                    $fileSize = $_FILES['profile_photo']['size'];
                    $fileNameCmps = explode(".", $fileName);
                    $fileExtension = strtolower(end($fileNameCmps));
                    
                    $allowedfileExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'heic', 'heif'];
                    if (in_array($fileExtension, $allowedfileExtensions)) {
                        if ($fileSize <= 2 * 1024 * 1024) { // 2MB max limit
                            $newFileName = 'photo_' . $student_id . '_' . time() . '.' . $fileExtension;
                            $uploadFileDir = __DIR__ . '/assets/uploads/';
                            $dest_path = $uploadFileDir . $newFileName;
                            
                            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                                $photo_path = 'assets/uploads/' . $newFileName;
                            } else {
                                $error_msg = "Error moving the uploaded file to assets folder.";
                            }
                        } else {
                            $error_msg = "Image size exceeds the 2MB limit.";
                        }
                    } else {
                        $error_msg = "Invalid image file type. Allowed: JPG, JPEG, PNG, GIF, WEBP, BMP, TIFF, HEIC, HEIF.";
                    }
                }
                
                // If no file errors, save
                if (!isset($error_msg)) {
                    if (!empty($new_pass)) {
                        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE students SET full_name = ?, email = ?, contact_no = ?, profile_photo = ?, password = ? WHERE id = ?");
                        $stmt->execute([$new_name, $new_email, $new_contact, $photo_path, $hashed, $student_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE students SET full_name = ?, email = ?, contact_no = ?, profile_photo = ? WHERE id = ?");
                        $stmt->execute([$new_name, $new_email, $new_contact, $photo_path, $student_id]);
                    }
                    
                    // Update session variables
                    $_SESSION['student_full_name'] = $new_name;
                    $full_name = $new_name; 
                    $student_contact = $new_contact;
                    $student_photo = $photo_path; // Update local view
                    
                    $success_msg = "Your profile has been updated successfully!";
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Database Error: " . $e->getMessage();
        }
    }
}

// Start an exam session helper
if (isset($_POST['start_exam'])) {
    $exam_id = intval($_POST['exam_id']);
    
    try {
        // Double check if record exists
        $stmt = $pdo->prepare("SELECT id, status FROM student_exams WHERE student_id = ? AND exam_id = ?");
        $stmt->execute([$student_id, $exam_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['status'] === 'started') {
                header("Location: exam.php?id=" . $existing['id']);
                exit;
            } else {
                $error_msg = "You have already submitted this exam.";
            }
        } else {
            // Get total possible score based on questions
            $stmt = $pdo->prepare("SELECT SUM(points) as total FROM questions WHERE exam_id = ?");
            $stmt->execute([$exam_id]);
            $total_points = intval($stmt->fetch()['total'] ?? 0);

            // Create new student exam session
            $stmt = $pdo->prepare("INSERT INTO student_exams (student_id, exam_id, status, total_possible_score) VALUES (?, ?, 'started', ?)");
            $stmt->execute([$student_id, $exam_id, $total_points]);
            $new_student_exam_id = $pdo->lastInsertId();

            header("Location: exam.php?id=" . $new_student_exam_id);
            exit;
        }
    } catch (PDOException $e) {
        $error_msg = "Database Error: " . $e->getMessage();
    }
}

// Fetch active exams
try {
    $stmt = $pdo->prepare("
        SELECT e.*, se.id as student_exam_id, se.status as exam_status 
        FROM exams e 
        LEFT JOIN student_exams se ON e.id = se.exam_id AND se.student_id = ? 
        WHERE e.status = 'active'
    ");
    $stmt->execute([$student_id]);
    $active_exams = $stmt->fetchAll();

    // Fetch history
    $stmt = $pdo->prepare("
        SELECT se.*, e.title, e.subject 
        FROM student_exams se 
        JOIN exams e ON se.exam_id = e.id 
        WHERE se.student_id = ? AND se.status IN ('submitted', 'graded', 'absent')
        ORDER BY COALESCE(se.submitted_at, se.started_at) DESC
    ");
    $stmt->execute([$student_id]);
    $exam_history = $stmt->fetchAll();

    // Compute stats for progress charts
    $total_exams = count($exam_history);
    $graded_exams = 0;
    $sum_scores = 0;
    $sum_possibles = 0;
    foreach ($exam_history as $hist) {
        if ($hist['status'] === 'graded') {
            $graded_exams++;
            $sum_scores += $hist['score'];
            $sum_possibles += $hist['total_possible_score'];
        }
    }
    $average_percent = ($sum_possibles > 0) ? round(($sum_scores / $sum_possibles) * 100, 1) : 0;

} catch (PDOException $e) {
    die("Error fetching dashboard data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Swaminarayan University</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .radial-progress {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: radial-gradient(closest-side, white 79%, transparent 80% 100%),
                        conic-gradient(var(--primary-saffron) <?php echo $average_percent; ?>%, var(--border-light) 0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--primary-maroon);
            margin: 0.5rem auto;
            border: 1px solid var(--border-light);
            box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
        }
        body.dark-mode .radial-progress {
            background: radial-gradient(closest-side, var(--card-dark) 79%, transparent 80% 100%),
                        conic-gradient(var(--primary-saffron) <?php echo $average_percent; ?>%, var(--border-dark) 0);
            border-color: var(--border-dark);
            color: var(--text-dark);
        }
        .stat-card-row {
            display: flex;
            justify-content: space-around;
            text-align: center;
            margin-top: 1.5rem;
            gap: 1rem;
        }
        .stat-item {
            flex: 1;
            padding: 0.75rem;
            background: rgba(148, 163, 184, 0.05);
            border-radius: 8px;
        }

        /* Modal Overlay Styles */
        .modal-backdrop {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(9, 13, 22, 0.6);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease-out;
        }
        .modal-content {
            width: 100%;
            max-width: 450px;
            animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>

    <header>
        <div class="navbar">
            <a href="dashboard.php" class="brand">
                <img src="assets/logo.png" alt="Swaminarayan University Logo">
                <div class="brand-text">
                    <span class="brand-name">Swaminarayan University</span>
                    <span class="brand-tagline">Student Portal</span>
                </div>
            </a>
            <div class="nav-actions">
                <button id="theme-toggle" class="btn" onclick="toggleTheme()" style="padding: 0.5rem; border-radius: 50%; font-size: 1.25rem; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border: none; cursor: pointer;">🌙</button>
                <span style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600;">
                    <?php if ($student_photo): ?>
                        <img src="<?php echo htmlspecialchars($student_photo); ?>" style="height: 32px; width: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-light);">
                    <?php endif; ?>
                    👋 <?php echo htmlspecialchars($full_name); ?>
                </span>
                <button class="btn btn-secondary" onclick="openProfileModal()" style="font-size: 0.9rem; padding: 0.5rem 1rem;">✏️ Edit Profile</button>
                <a href="?action=logout" class="btn btn-danger" style="font-size: 0.9rem; padding: 0.5rem 1rem;">Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Welcome banner -->
        <div class="welcome-banner">
            <h1 style="margin-bottom: 0.5rem;">Jay Swaminarayan, <?php echo htmlspecialchars($full_name); ?>!</h1>
            <p style="margin: 0; font-size: 1.1rem; opacity: 0.9;">Enrollment Number: <strong><?php echo htmlspecialchars($enrollment_no); ?></strong></p>
            <p style="margin: 0.5rem 0 0 0; font-size: 0.95rem; opacity: 0.8;">Welcome to the Swaminarayan University Online Examination System. Make sure you have a stable internet connection before beginning any exam.</p>
        </div>

        <?php if (isset($error_msg)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                ⚠️ <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($success_msg)): ?>
            <div id="profile-success-alert" style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; transition: opacity 0.5s ease-out;">
                ✅ <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            
            <!-- Left panel: Exams list -->
            <div>
                <div class="minimalist-card" style="margin-bottom: 2rem;">
                    <h3 style="color: var(--primary-maroon); border-bottom: 2px solid var(--border-light); padding-bottom: 0.5rem; margin-bottom: 1.5rem;">🎯 Available Examinations</h3>
                    <div class="exam-list">
                        <?php if (empty($active_exams)): ?>
                            <p style="color: var(--text-muted-light); text-align: center; padding: 2rem 0;">No active exams found at this moment. Please check back later!</p>
                        <?php else: ?>
                            <?php foreach ($active_exams as $exam): ?>
                                <div class="exam-item">
                                    <div class="exam-info">
                                        <h4><?php echo htmlspecialchars($exam['title']); ?></h4>
                                        <div class="exam-meta">
                                            <span>📚 Subject: <?php echo htmlspecialchars($exam['subject']); ?></span>
                                            <span>⏱️ Duration: <?php echo intval($exam['duration_minutes']); ?> Mins</span>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if ($exam['exam_status'] === null): ?>
                                            <form method="POST">
                                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                                <button type="submit" name="start_exam" class="minimalist-btn" onclick="return confirm('Do you want to start this exam? The timer will start immediately.');">Start Exam</button>
                                            </form>
                                        <?php elseif ($exam['exam_status'] === 'started'): ?>
                                            <a href="exam.php?id=<?php echo $exam['student_exam_id']; ?>" class="minimalist-btn">Resume Exam</a>
                                        <?php else: ?>
                                            <span class="badge badge-success">Submitted</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right panel: Performance Summary -->
            <div>
                <div class="minimalist-card">
                    <h3 style="color: var(--primary-maroon); text-align: center; border-bottom: 2px solid var(--border-light); padding-bottom: 0.5rem; margin-bottom: 1.5rem;">📈 Performance Overview</h3>
                    
                    <?php if ($total_exams === 0): ?>
                        <p style="color: var(--text-muted-light); text-align: center; padding: 2rem 0;">Complete an exam to see your performance overview here.</p>
                    <?php else: ?>
                        <div class="radial-progress">
                            <?php echo $average_percent; ?>%
                        </div>
                        <p style="text-align: center; font-size: 0.9rem; color: var(--text-muted-light); margin-top: 0.5rem;">Average Academic Performance</p>

                        <div class="stat-card-row">
                            <div class="stat-item">
                                <strong style="font-size: 1.25rem; color: var(--primary-maroon);"><?php echo $total_exams; ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted-light); margin-top: 0.25rem;">Exams Taken</div>
                            </div>
                            <div class="stat-item">
                                <strong style="font-size: 1.25rem; color: var(--success);"><?php echo $graded_exams; ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted-light); margin-top: 0.25rem;">Graded</div>
                            </div>
                        </div>

                        <div style="margin-top: 1.5rem; height: 180px; position: relative;">
                            <canvas id="studentPerformanceChart"></canvas>
                        </div>

                        <h4 style="margin: 1.5rem 0 1rem 0; border-top: 1px solid var(--border-light); padding-top: 1rem; font-size: 1rem;">Recent Results</h4>
                        <div class="results-list" style="max-height: 250px; overflow-y: auto;">
                            <?php foreach ($exam_history as $hist): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed var(--border-light); font-size: 0.9rem;">
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($hist['title']); ?></div>
                                        <span style="font-size: 0.75rem; color: var(--text-muted-light);">
                                            <?php echo $hist['submitted_at'] ? date('d M, Y H:i', strtotime($hist['submitted_at'])) : 'Exam Missed'; ?>
                                        </span>
                                    </div>
                                    <div style="text-align: right;">
                                        <?php if ($hist['status'] === 'graded'): ?>
                                            <strong style="color: var(--primary-maroon);"><?php echo floatval($hist['score']); ?>/<?php echo intval($hist['total_possible_score']); ?></strong>
                                        <?php elseif ($hist['status'] === 'absent'): ?>
                                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.2rem;">
                                                <strong style="color: var(--danger);"><?php echo floatval($hist['score']); ?>/<?php echo intval($hist['total_possible_score']); ?></strong>
                                                <span class="badge badge-danger" style="font-size: 0.65rem; padding: 0.15rem 0.35rem; line-height: 1;">Absent</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="font-size: 0.7rem;">Grading...</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Profile Edit Modal overlay -->
    <div class="modal-backdrop" id="profile-modal">
        <div class="minimalist-card modal-content" style="max-width: 480px;">
            <h3 style="color: var(--primary-maroon); margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-light); padding-bottom: 0.5rem;">✏️ Update Profile Details</h3>
            
            <form method="POST" enctype="multipart/form-data">
                <!-- Avatar Preview & Upload -->
                <div class="form-group" style="text-align: center; margin-bottom: 1.25rem;">
                    <label class="form-label" style="display: block; text-align: left;">Profile Photo</label>
                    <div style="display: flex; align-items: center; gap: 1rem; margin-top: 0.5rem;">
                        <img src="<?php echo $student_photo ? htmlspecialchars($student_photo) : 'assets/logo.png'; ?>" id="avatar-preview" style="height: 64px; width: 64px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-saffron); background: white;">
                        <input type="file" name="profile_photo" class="form-control" accept="image/*, .heic, .heif" style="flex-grow: 1; padding: 0.35rem;" onchange="previewImage(this)">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($full_name); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($student_email); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_no" class="form-control" value="<?php echo htmlspecialchars($student_contact); ?>" placeholder="e.g. +91 9876543210">
                </div>

                <div class="form-group">
                    <label class="form-label">New Password (Optional)</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="profile-password" class="form-control" placeholder="Leave blank to keep current">
                        <span class="password-toggle" onclick="togglePasswordVisibility('profile-password', this)">👁️</span>
                    </div>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem; justify-content: flex-end;">
                    <button type="button" class="minimalist-btn" onclick="closeProfileModal()" style="background: transparent; color: inherit; border-color: var(--border-light);">Cancel</button>
                    <button type="submit" name="update_profile" class="minimalist-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        function openProfileModal() {
            document.getElementById('profile-modal').style.display = 'flex';
        }
        
        function closeProfileModal() {
            document.getElementById('profile-modal').style.display = 'none';
        }

        // Close modal if user clicks on backdrop
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('profile-modal');
            if (e.target === modal) {
                closeProfileModal();
            }
        });

        // Toggle password show/hide inside modal
        function togglePasswordVisibility(inputId, toggleIcon) {
            const input = document.getElementById(inputId);
            if (!input) return;
            
            if (input.type === 'password') {
                input.type = 'text';
                toggleIcon.innerText = '🙈';
            } else {
                input.type = 'password';
                toggleIcon.innerText = '👁️';
            }
        }

        // Preview image in modal before uploading
        async function previewImage(input) {
            let file = input.files ? input.files[0] : null;
            if (!file) return;

            // Check if file is HEIC/HEIF
            const fileName = file.name.toLowerCase();
            if (fileName.endsWith('.heic') || fileName.endsWith('.heif')) {
                try {
                    if (typeof heic2any === 'undefined') {
                        // Dynamically load heic2any
                        await new Promise((resolve, reject) => {
                            const script = document.createElement('script');
                            script.src = 'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js';
                            script.onload = resolve;
                            script.onerror = reject;
                            document.head.appendChild(script);
                        });
                    }
                    
                    const convertedBlob = await heic2any({
                        blob: file,
                        toType: "image/jpeg",
                        quality: 0.8
                    });
                    
                    file = new File([convertedBlob], file.name.replace(/\.heic|\.heif/i, '.jpg'), {
                        type: 'image/jpeg'
                    });
                    
                    const container = new DataTransfer();
                    container.items.add(file);
                    input.files = container.files;
                } catch (err) {
                    console.error("HEIC conversion error: ", err);
                    alert("Error converting HEIC image. Please upload a standard image format.");
                    return;
                }
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('avatar-preview').src = e.target.result;
            }
            reader.readAsDataURL(file);
        }

        // Auto fade-out profile update success notification after 5 seconds
        document.addEventListener('DOMContentLoaded', () => {
            const successAlert = document.getElementById('profile-success-alert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    setTimeout(() => {
                        successAlert.remove();
                    }, 500);
                }, 5000); // 5000ms = 5 seconds
            }
        });

        // Render student performance chart
        document.addEventListener('DOMContentLoaded', () => {
            const historyData = <?php echo json_encode(array_reverse($exam_history)); ?>;
            const canvas = document.getElementById('studentPerformanceChart');
            if (!canvas || historyData.length === 0) return;
            
            const gradedHistory = historyData.filter(h => h.status === 'graded' || h.status === 'absent');
            if (gradedHistory.length === 0) return;

            const labels = gradedHistory.map(h => h.title);
            const scores = gradedHistory.map(h => {
                if (h.status === 'absent') return 0;
                const score = parseFloat(h.score);
                const total = parseFloat(h.total_possible_score);
                return total > 0 ? ((score / total) * 100).toFixed(1) : 0;
            });

            const ctx = canvas.getContext('2d');
            const isDarkMode = document.body.classList.contains('dark-mode');
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 180);
            gradient.addColorStop(0, 'rgba(230, 110, 25, 0.3)');
            gradient.addColorStop(1, 'rgba(230, 110, 25, 0.0)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Score Percentage (%)',
                        data: scores,
                        borderColor: '#e66e19',
                        borderWidth: 2,
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#5d101d',
                        pointBorderColor: '#e66e19',
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ` Score: ${context.parsed.y}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 0,
                            max: 100,
                            grid: {
                                color: isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                color: isDarkMode ? '#cbd5e1' : '#475569',
                                callback: function(value) { return value + "%"; }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: isDarkMode ? '#cbd5e1' : '#475569',
                                maxRotation: 15,
                                minRotation: 0,
                                callback: function(value, index) {
                                    const val = labels[index];
                                    return val.length > 15 ? val.substr(0, 12) + '...' : val;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
