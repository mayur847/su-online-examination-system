<?php
session_start();
// Redirect already logged-in users
if (isset($_SESSION['admin_user_id'])) {
    header('Location: admin.php');
    exit;
} elseif (isset($_SESSION['student_user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swaminarayan University - Online Examination System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Animated Floating Background Shapes -->
    <div class="bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <header>
        <div class="navbar">
            <a href="index.php" class="brand">
                <img src="assets/logo.png" alt="Swaminarayan University Logo">
                <div class="brand-text">
                    <span class="brand-name">Swaminarayan University</span>
                    <span class="brand-tagline">Online Examination Portal</span>
                </div>
            </a>
            <div class="nav-actions">
                <button id="theme-toggle" class="btn" onclick="toggleTheme()" style="padding: 0.5rem; border-radius: 50%; font-size: 1.25rem; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border: none; cursor: pointer;">🌙</button>
            </div>
        </div>
    </header>

    <div class="auth-container">
        <div class="minimalist-card auth-card">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <img src="assets/logo.png" alt="SU Logo" style="height: 80px; width: auto;">
                <h3 style="margin-top: 1rem; color: var(--primary-maroon);">Online Examination System</h3>
            </div>
            
            <div class="auth-tabs">
                <div class="auth-tab active" onclick="switchTab('student-login')">Login</div>
                <div class="auth-tab" onclick="switchTab('student-register')">Register</div>
                <div class="auth-tab" onclick="switchTab('admin-login')">Faculty</div>
            </div>

            <!-- Student Login Form -->
            <form id="student-login-form" class="auth-form" onsubmit="handleAuth(event, 'login', 'student')">
                <div class="form-group">
                    <label class="form-label" for="login-email">Student Email</label>
                    <input type="email" id="login-email" class="form-control" placeholder="name@student.su.edu.in" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="login-password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="login-password" class="form-control" placeholder="••••••••" required>
                        <span class="password-toggle" onclick="togglePasswordVisibility('login-password', this)">👁️</span>
                    </div>
                </div>
                <button type="submit" class="minimalist-btn" style="width: 100%; margin-top: 1rem;">Sign In as Student</button>
            </form>

            <!-- Student Register Form -->
            <form id="student-register-form" class="auth-form" style="display: none;" onsubmit="handleAuth(event, 'register')">
                <div class="form-group">
                    <label class="form-label" for="reg-name">Full Name</label>
                    <input type="text" id="reg-name" class="form-control" placeholder="Mayur Ramavat" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-enroll">Enrollment Number</label>
                    <input type="text" id="reg-enroll" class="form-control" placeholder="SU2026001" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-email">Email Address</label>
                    <input type="email" id="reg-email" class="form-control" placeholder="mayur@student.su.edu.in" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="reg-password" class="form-control" placeholder="••••••••" required>
                        <span class="password-toggle" onclick="togglePasswordVisibility('reg-password', this)">👁️</span>
                    </div>
                </div>
                <button type="submit" class="minimalist-btn" style="width: 100%; margin-top: 1rem;">Create Account</button>
            </form>

            <!-- Admin Login Form -->
            <form id="admin-login-form" class="auth-form" style="display: none;" onsubmit="handleAuth(event, 'login', 'admin')">
                <div class="form-group">
                    <label class="form-label" for="admin-email">Faculty Email</label>
                    <input type="email" id="admin-email" class="form-control" placeholder="dean@su.edu.in" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="admin-password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="admin-password" class="form-control" placeholder="••••••••" required>
                        <span class="password-toggle" onclick="togglePasswordVisibility('admin-password', this)">👁️</span>
                    </div>
                </div>
                <button type="submit" class="minimalist-btn" style="width: 100%; margin-top: 1rem;">Faculty Sign In</button>
            </form>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        function switchTab(tabId) {
            // Manage tabs CSS class
            const tabs = document.querySelectorAll('.auth-tab');
            tabs.forEach(t => t.classList.remove('active'));
            
            // Manage forms visibility
            const forms = document.querySelectorAll('.auth-form');
            forms.forEach(f => f.style.display = 'none');

            if (tabId === 'student-login') {
                tabs[0].classList.add('active');
                document.getElementById('student-login-form').style.display = 'block';
            } else if (tabId === 'student-register') {
                tabs[1].classList.add('active');
                document.getElementById('student-register-form').style.display = 'block';
            } else if (tabId === 'admin-login') {
                tabs[2].classList.add('active');
                document.getElementById('admin-login-form').style.display = 'block';
            }
        }

        async function handleAuth(event, type, role = 'student') {
            event.preventDefault();
            
            let payload = { action: type };
            
            if (type === 'login') {
                payload.role = role;
                if (role === 'student') {
                    payload.email = document.getElementById('login-email').value;
                    payload.password = document.getElementById('login-password').value;
                } else {
                    payload.email = document.getElementById('admin-email').value;
                    payload.password = document.getElementById('admin-password').value;
                }
            } else {
                payload.full_name = document.getElementById('reg-name').value;
                payload.enrollment_no = document.getElementById('reg-enroll').value;
                payload.email = document.getElementById('reg-email').value;
                payload.password = document.getElementById('reg-password').value;
            }

            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerText;
            submitBtn.disabled = true;
            submitBtn.innerText = 'Processing...';

            const res = await apiCall('api/auth.php', payload);
            
            submitBtn.disabled = false;
            submitBtn.innerText = originalText;

            if (res.success) {
                if (type === 'login') {
                    showToast('Logged in successfully. Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = res.redirect;
                    }, 1000);
                } else {
                    showToast(res.message, 'success');
                    switchTab('student-login');
                    // Seed login email
                    document.getElementById('login-email').value = payload.email;
                }
            } else {
                showToast(res.message, 'danger');
            }
        }
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
    </script>
</body>
</html>
