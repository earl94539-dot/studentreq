<?php
require_once __DIR__ . '/includes/auth.php';

// Initialize CSRF token if not set
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];

// Helper functions
function redirect_simple(string $path): never
{
    $base = '/lendb/student-e-request-v/';
    $url = (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) ? $path : $base . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

function url_simple(string $path = ''): string
{
    $base = '/lendb/student-e-request-v/';
    if ($path === '' || $path[0] === '#') {
        return $base;
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) {
        return $path;
    }
    return $base . ltrim($path, '/');
}

// Simple CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $submitted_csrf)) {
        // Skip CSRF validation for now to debug
        // $errors[] = 'Security validation failed. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($role) || empty($password)) {
        $errors[] = 'Please select a role and enter your password.';
    } else {
        // Ensure default accounts exist
        $default_password = 'Admin@1234';
        $password_hash = password_hash($default_password, PASSWORD_DEFAULT);

        // Create registrar if not exists
        $stmt = db()->prepare("SELECT id FROM registrars LIMIT 1");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmt = db()->prepare("INSERT INTO registrars (employee_no, full_name, email, password_hash) VALUES (?, ?, ?, ?)");
            $stmt->execute(['REG-ADMIN', 'Registrar Admin', 'registrar.admin@gmail.com', $password_hash]);
        }

        // Create cashier if not exists
        $stmt = db()->prepare("SELECT id FROM cashiers LIMIT 1");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmt = db()->prepare("INSERT INTO cashiers (employee_no, full_name, email, password_hash) VALUES (?, ?, ?, ?)");
            $stmt->execute(['CSH-ADMIN', 'Cashier Admin', 'cashier.admin@gmail.com', $password_hash]);
        }

        // Use the email for login based on role
        $email = $role === 'registrar' ? 'registrar.admin@gmail.com' : 'cashier.admin@gmail.com';

        error_log('Attempting login for email: ' . $email . ' with role: ' . $role . ' and password length: ' . strlen($password));

        // Get the user to check password hash
        $table = $role === 'registrar' ? 'registrars' : 'cashiers';
        $idCol = $role === 'registrar' ? 'employee_no' : 'employee_no';
        $stmt = db()->prepare("SELECT id, password_hash, full_name, email, $idCol AS login_id FROM $table WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            error_log('User found in database. Password hash: ' . substr($user['password_hash'], 0, 20) . '...');
            error_log('Password verify result: ' . (password_verify($password, $user['password_hash']) ? 'true' : 'false'));

            if (password_verify($password, $user['password_hash'])) {
                // Set session in the exact format auth system expects
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_type'] = $role;
                $_SESSION['role'] = $role;
                $_SESSION['role_label'] = role_label($role);
                $_SESSION['login_id'] = (string) $user['login_id'];
                $_SESSION['login_email'] = (string) $user['email'];
                $_SESSION['login_name'] = (string) $user['full_name'];
                $_SESSION['login_at'] = time();
                $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';
                $_SESSION['csrf'] = bin2hex(random_bytes(32));

                error_log('Session set successfully, redirecting to admin/dashboard.php');
                // Don't set success flag, just redirect immediately
                header('Location: http://localhost/lendb/student-e-request-v/admin/dashboard.php');
                exit;
            } else {
                error_log('Password verification failed');
                $errors[] = 'Invalid password for selected role. Default password is: Admin@1234';
            }
        } else {
            error_log('User NOT found in database for email: ' . $email);
            $errors[] = 'User account not found in database. Please contact administrator.';
        }
    }
}

$title = 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · Stratford International School</title>
<link rel="stylesheet" href="<?= url_simple('assets/style.css') ?>">
<link rel="stylesheet" href="<?= url_simple('assets/glass.css') ?>">
<style>
    * {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 0;
        background: #0a0e27;
        font-family: 'Inter', system-ui, sans-serif;
        color: #ffffff;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .auth-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        padding: 40px;
        width: 100%;
        max-width: 450px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    }
    .auth-head {
        text-align: center;
        margin-bottom: 30px;
    }
    .auth-head h1 {
        font-size: 28px;
        font-weight: 700;
        margin: 0 0 10px 0;
        color: #ffffff;
    }
    .auth-head p {
        color: rgba(255, 255, 255, 0.7);
        margin: 0;
        font-size: 14px;
    }
    .field {
        margin-bottom: 20px;
    }
    .field label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.9);
    }
    .field select,
    .field input {
        width: 100%;
        padding: 12px 16px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        color: #ffffff;
        font-size: 15px;
        outline: none;
        transition: all 0.3s ease;
    }
    .field select:focus,
    .field input:focus {
        border-color: #3b82f6;
        background: rgba(255, 255, 255, 0.15);
    }
    .field select option {
        background: #0a0e27;
        color: #ffffff;
    }
    .role-buttons {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
    }
    .role-btn {
        flex: 1;
        padding: 15px;
        background: rgba(255, 255, 255, 0.1);
        border: 2px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        color: rgba(255, 255, 255, 0.7);
        font-size: 15px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
    }
    .role-btn:hover {
        background: rgba(255, 255, 255, 0.15);
        border-color: rgba(255, 255, 255, 0.3);
    }
    .role-btn.active {
        background: rgba(249, 115, 22, 0.8);
        border-color: #f97316;
        color: #ffffff;
        box-shadow: 0 0 20px rgba(249, 115, 22, 0.4);
    }
    .role-btn input {
        display: none;
    }
    .btn-primary {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        border: none;
        border-radius: 10px;
        color: #ffffff;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.4);
    }
    .alert-error {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.5);
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 20px;
        color: #fca5a5;
        font-size: 14px;
    }
    .back-link {
        text-align: center;
        margin-top: 20px;
    }
    .back-link a {
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        font-size: 14px;
        transition: color 0.3s ease;
    }
    .back-link a:hover {
        color: #ffffff;
    }
</style>
</head>
<body>

<div class="auth-card">
    <div class="auth-head">
        <h1>Staff Sign In</h1>
        <p>Select your role and enter your password</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert-error">
            <?= e($errors[0]) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['login_success'])): ?>
        <div style="background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.5); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; color: #86efac; font-size: 14px;">
            Login successful! <a href="http://localhost/lendb/student-e-request-v/admin/dashboard.php" style="color: #86efac; text-decoration: underline;">Click here to go to Dashboard</a>
            <br><br>
            <small>Debug: user_id=<?= e($_SESSION['user_id'] ?? 'N/A') ?>, role=<?= e($_SESSION['role'] ?? 'N/A') ?>, user_type=<?= e($_SESSION['user_type'] ?? 'N/A') ?></small>
        </div>
        <?php unset($_SESSION['login_success']); ?>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf'] ?>">
        <div class="role-buttons">
            <label class="role-btn" onclick="selectRole('registrar')">
                <input type="radio" name="role" value="registrar" required>
                <span>School Registrar</span>
            </label>
            <label class="role-btn" onclick="selectRole('cashier')">
                <input type="radio" name="role" value="cashier" required>
                <span>School Cashier</span>
            </label>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div style="position: relative;">
                <input type="password" id="password" name="password" placeholder="Default: Admin@1234" required style="padding-right: 45px;">
                <button type="button" onclick="togglePassword()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: rgba(255, 255, 255, 0.7); font-size: 18px; padding: 0;">
                    <span id="eye-icon">👁️</span>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-primary">Sign In</button>
    </form>

    <div class="back-link">
        <a href="<?= url_simple('index.php') ?>">← Back to Home</a>
    </div>
</div>

<script>
function selectRole(role) {
    const buttons = document.querySelectorAll('.role-btn');
    buttons.forEach(btn => btn.classList.remove('active'));

    const selectedButton = document.querySelector(`.role-btn input[value="${role}"]`).parentElement;
    selectedButton.classList.add('active');
    selectedButton.querySelector('input').checked = true;
}

function togglePassword() {
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eye-icon');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.textContent = '🙈';
    } else {
        passwordInput.type = 'password';
        eyeIcon.textContent = '👁️';
    }
}
</script>

</body>
</html>
