<?php
session_start();
require_once __DIR__ . '/config/database.php';

function redirect_simple(string $path): never
{
    $base = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') . '/' : '/lendb/student-e-request-v/';
    $url = (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) ? $path : $base . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

function url_simple(string $path = ''): string
{
    $base = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') . '/' : '/lendb/student-e-request-v/';
    if ($path === '' || $path[0] === '#') {
        return $base;
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) {
        return $path;
    }
    return $base . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function peso(float|int|string|null $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Security token expired or invalid. Please go back and try again.');
    }
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function audit_log(int $actorId, string $action, string $detail, ?string $actorType = null): void
{
    try {
        if ($actorType === null) {
            $actorType = 'student';
        }
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_type, student_id, action, detail, created_at) VALUES (?, ?, ?, ?, NOW())');
        $studentId = $actorType === 'student' ? $actorId : null;
        $stmt->execute([$actorType, $studentId, $action, $detail]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function icon(string $name, string $class = 'ic'): string
{
    $paths = [
        'login' => 'M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3',
        'doc' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 0v6h6M9 15h6M9 11h3',
    ];
    $d = $paths[$name] ?? '';
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . e($d) . '"/></svg>';
}

$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY fee, name')->fetchAll();
$selected_service_id = $_GET['service'] ?? null;

// Multi-step form handling
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$gcash_code = '09123456789'; // Replace with actual GCash number

// Check for success message from previous submission
$success_data = $_SESSION['success_data'] ?? null;

// If success data exists, stay on step 2 to show overlay
if ($success_data) {
    $step = 2;
}

// Handle confirmation dismissal
if (isset($_GET['dismiss']) && $_GET['dismiss'] === '1') {
    unset($_SESSION['success_data']);
    unset($_SESSION['form_data']);
    redirect_simple('index.php');
}

// Store form data in session
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['form_data'] = $_POST;
    session_write_close();
    redirect_simple('guest-request.php?step=2');
}

// Handle screenshot upload and verification
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_screenshot'])) {
    csrf_check();
    
    // Get form data from session first
    $form_data = $_SESSION['form_data'] ?? [];
    
    if (empty($form_data)) {
        $errors[] = 'Session expired. Please start over from Step 1.';
    } else {
        // Handle file upload
        $payment_screenshot = null;
        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0775, true);
            }
            
            $file = $_FILES['payment_screenshot'];
            
            // Basic verification
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/jfif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types)) {
                $errors[] = 'Invalid file type. Please upload a JPEG, PNG, or JFIF image.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'File size too large. Maximum size is 5MB.';
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'payment_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $payment_screenshot = 'uploads/' . $filename;
                }
            }
        }
        
        if (!$payment_screenshot) {
            $errors[] = 'Failed to upload payment screenshot. Please try again.';
        } else {
            $last_name = trim($form_data['last_name'] ?? '');
            $first_name = trim($form_data['first_name'] ?? '');
            $middle_name = trim($form_data['middle_name'] ?? '');
            $address = trim($form_data['address'] ?? '');
            $email = trim($form_data['email'] ?? '');
            $course = trim($form_data['course'] ?? '');
            $year_level = (int) ($form_data['year_level'] ?? 0);
            $contact_number = trim($form_data['contact_number'] ?? '');
            $service_id = (int) ($form_data['service_id'] ?? 0);
            $notes = trim($form_data['notes'] ?? '');
            
            // Combine name parts
            $full_name = trim("$first_name $middle_name $last_name");
            
            // Validation
            $errors = [];
            
            if (empty($last_name)) {
                $errors[] = 'Last name is required.';
            }
            if (empty($first_name)) {
                $errors[] = 'First name is required.';
            }
            if (empty($address)) {
                $errors[] = 'Address is required.';
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid Gmail address is required.';
            }
            if (empty($course)) {
                $errors[] = 'Course is required.';
            }
            if ($year_level < 1 || $year_level > 6) {
                $errors[] = 'Year level must be between 1 and 6.';
            }
            if (empty($contact_number)) {
                $errors[] = 'Contact number is required.';
            }
            if ($service_id <= 0) {
                $errors[] = 'Please select a service.';
            }
            
            if (empty($errors)) {
                try {
                    // Check if service exists and is active
                    $stmt = db()->prepare('SELECT id, name, fee, processing_days FROM services WHERE id = ? AND is_active = 1');
                    $stmt->execute([$service_id]);
                    $service = $stmt->fetch();
                    
                    if (!$service) {
                        $errors[] = 'Invalid service selected.';
                    } else {
                        // Check if student already exists by email
                        $stmt = db()->prepare('SELECT id, student_no, full_name FROM students WHERE email = ?');
                        $stmt->execute([$email]);
                        $existing_student = $stmt->fetch();
                        
                        if ($existing_student) {
                            // Use existing student account
                            $student_id = $existing_student['id'];
                            // Update student info if needed
                            $stmt = db()->prepare('UPDATE students SET full_name = ?, course = ?, year_level = ?, phone = ? WHERE id = ?');
                            $stmt->execute([$full_name, $course, $year_level, $contact_number, $student_id]);
                        } else {
                            // Create a new student account
                            $temp_student_no = 'GUEST-' . date('YmdHis');
                            $password_hash = password_hash('TempPass123!', PASSWORD_DEFAULT);
                            
                            $stmt = db()->prepare('INSERT INTO students (student_no, full_name, email, password_hash, course, year_level, phone, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                            $stmt->execute([$temp_student_no, $full_name, $email, $password_hash, $course, $year_level, $contact_number]);
                            $student_id = db()->lastInsertId();
                        }
                        
                        // Generate a unique reference number
                        $ref_no = 'REQ-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
                        
                        // Create the request
                        $stmt = db()->prepare('INSERT INTO requests (student_id, service_id, ref_no, notes, fee, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                        $stmt->execute([$student_id, $service_id, $ref_no, $notes, $service['fee'], 'pending']);
                        $request_id = db()->lastInsertId();
                        
                        // Create payment record
                        $payment_ref = 'PAY-' . date('YmdHis');
                        $stmt = db()->prepare('INSERT INTO payments (request_id, method, amount, currency, transaction_number, payment_url, status, proof_image, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                        $stmt->execute([$request_id, 'gcash', $service['fee'], 'PHP', $payment_ref, null, 'pending', $payment_screenshot]);
                        
                        // Log the request
                        audit_log($student_id, 'guest_request_submitted', "Guest request $ref_no submitted with payment $payment_ref (fee: {$service['fee']})", 'student');
                        
                        // Store success data for confirmation overlay
                        $_SESSION['success_data'] = [
                            'student_name' => $full_name,
                            'ref_no' => $ref_no,
                            'payment_ref' => $payment_ref,
                            'amount' => $service['fee'],
                            'contact_number' => $contact_number
                        ];
                        
                        // Clear form data
                        unset($_SESSION['form_data']);
                        unset($_SESSION['payment_data']);
                        
                        // Redirect to show confirmation on same page
                        redirect_simple('guest-request.php?step=2&success=1');
                    }
                } catch (PDOException $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                    error_log('Guest request PDO error: ' . $e->getMessage());
                } catch (Exception $e) {
                    $errors[] = 'An error occurred: ' . $e->getMessage();
                    error_log('Guest request error: ' . $e->getMessage());
                }
            }
        }
    }
}

// Hide form if showing success overlay
$hide_form = $success_data ? true : false;

$title = 'Submit Request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<style>
    * {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        font-family: 'Times New Roman', serif;
        color: #000000;
        min-height: 100vh;
    }
    .paper-form {
        width: 70%;
        margin: 0 auto;
        min-height: 100vh;
        padding: 40px 50px;
        background: #ffffff;
    }
    .form-header {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 40px;
        border-bottom: 3px solid #000000;
        padding-bottom: 20px;
        text-align: center;
    }
    .school-logo {
        width: 120px;
        height: 120px;
        margin-bottom: 15px;
        object-fit: contain;
    }
    .header-text {
        text-align: center;
    }
    .form-header h1 {
        font-size: 28px;
        font-weight: bold;
        text-transform: uppercase;
        margin: 0 0 10px 0;
        letter-spacing: 2px;
    }
    .form-header h2 {
        font-size: 20px;
        font-weight: bold;
        margin: 0;
    }
    .form-header p {
        font-size: 14px;
        margin: 5px 0 0 0;
        font-style: italic;
    }
    .form-section {
        margin-bottom: 30px;
    }
    .form-section h3 {
        font-size: 16px;
        font-weight: bold;
        text-transform: uppercase;
        margin: 0 0 20px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #000000;
    }
    .field {
        margin-bottom: 18px;
    }
    .field label {
        display: block;
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .field input,
    .field select,
    .field textarea {
        width: 100%;
        padding: 5px 10px;
        background: transparent;
        color: #000000;
        border: none;
        border-bottom: 2px solid #000000;
        border-radius: 0;
        font-size: 14px;
        font-family: 'Times New Roman', serif;
        outline: none;
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        border-bottom: 3px solid #000000;
        background: transparent;
    }
    .field input::placeholder,
    .field textarea::placeholder {
        color: #cccccc;
    }
    .field select {
        background: transparent;
        cursor: pointer;
    }
    .field select option {
        background: #ffffff;
        color: #000000;
    }
    .file-upload-zone {
        border: 3px dashed #000000;
        border-radius: 10px;
        padding: 40px 20px;
        text-align: center;
        background: #f9f9f9;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
    }
    .file-upload-zone:hover {
        background: #f0f0f0;
        border-color: #333333;
    }
    .file-upload-zone.dragover {
        background: #e8f4f8;
        border-color: #0066cc;
    }
    .file-upload-zone input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    .file-upload-icon {
        font-size: 48px;
        margin-bottom: 15px;
        color: #666666;
    }
    .file-upload-text {
        font-size: 16px;
        font-weight: bold;
        color: #000000;
        margin-bottom: 8px;
    }
    .file-upload-subtext {
        font-size: 14px;
        color: #666666;
    }
    .file-preview {
        margin-top: 20px;
        border: 2px solid #000000;
        border-radius: 8px;
        padding: 10px;
        background: #ffffff;
        display: none;
    }
    .file-preview.show {
        display: block;
    }
    .file-preview img {
        max-width: 100%;
        max-height: 300px;
        display: block;
        margin: 0 auto;
    }
    .file-preview-remove {
        display: block;
        margin-top: 10px;
        padding: 8px 16px;
        background: #d32f2f;
        color: #ffffff;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
        text-align: center;
    }
    .file-preview-remove:hover {
        background: #b71c1c;
    }
    .confirmation-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .confirmation-box {
        background: #ffffff;
        border: 3px solid #000000;
        border-radius: 10px;
        padding: 40px;
        max-width: 500px;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }
    .confirmation-box h2 {
        font-size: 24px;
        font-weight: bold;
        margin: 0 0 20px 0;
        color: #10b981;
    }
    .confirmation-box p {
        font-size: 16px;
        margin: 10px 0;
        color: #333333;
        line-height: 1.6;
    }
    .confirmation-box .details {
        background: #f5f5f5;
        padding: 20px;
        border: 2px solid #000000;
        margin: 20px 0;
        text-align: left;
    }
    .confirmation-box .details p {
        margin: 8px 0;
        font-weight: bold;
    }
    .confirmation-box .details span {
        font-weight: normal;
    }
    .confirmation-buttons {
        margin-top: 20px;
        display: flex;
        gap: 10px;
        justify-content: center;
    }
    .form-row {
        display: flex;
        gap: 30px;
        margin-bottom: 18px;
    }
    .form-row .field {
        flex: 1;
    }
    .form-row-label {
        display: flex;
        gap: 30px;
        margin-bottom: 18px;
    }
    .form-row-label .field {
        flex: 1;
    }
    .error-list {
        background: #fff0f0;
        border: 2px solid #d32f2f;
        color: #c62828;
        padding: 15px 20px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    .error-list li {
        margin: 5px 0;
    }
    .form-actions {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 3px solid #000000;
        text-align: center;
    }
    .btn-primary {
        background: #000000;
        color: #ffffff;
        border: 2px solid #000000;
        padding: 15px 40px;
        font-size: 16px;
        font-weight: bold;
        font-family: 'Times New Roman', serif;
        text-transform: uppercase;
        letter-spacing: 2px;
        cursor: pointer;
        margin: 0 10px;
    }
    .btn-primary:hover {
        background: #333333;
    }
    .btn-ghost {
        background: #ffffff;
        color: #000000;
        border: 2px solid #000000;
        padding: 15px 40px;
        font-size: 16px;
        font-weight: bold;
        font-family: 'Times New Roman', serif;
        text-transform: uppercase;
        letter-spacing: 2px;
        cursor: pointer;
        margin: 0 10px;
    }
    .btn-ghost:hover {
        background: #f0f0f0;
    }
    .date-field {
        width: 150px !important;
    }
    .inline-label {
        display: inline;
        margin-right: 10px;
    }
</style>
</head>
<body>

<div class="paper-form" style="<?= $hide_form ? 'display: none;' : '' ?>">
    <div class="form-header">
        <img src="<?= url_simple('assets/logo (2).png') ?>" alt="School Logo" class="school-logo">
        <div class="header-text">
            <h1>Stratford International School</h1>
            <h2>Document Request Form</h2>
            <p>Please fill out all required fields</p>
        </div>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="error-list">
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if ($step === 1): ?>
    <!-- Step 1: Personal and Academic Information -->
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="2">
        
        <div class="form-section">
            <h3>Personal Information</h3>
            
            <div class="form-row-label">
                <div class="field">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= e($_POST['middle_name'] ?? '') ?>">
                </div>
            </div>
            
            <div class="field">
                <label for="address">Complete Address</label>
                <input type="text" id="address" name="address" value="<?= e($_POST['address'] ?? '') ?>" required>
            </div>
            
            <div class="form-row">
                <div class="field">
                    <label for="email">Gmail Address</label>
                    <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="contact_number">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX" required>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Academic Information</h3>
            
            <div class="form-row">
                <div class="field">
                    <label for="course">Course / Program</label>
                    <input type="text" id="course" name="course" value="<?= e($_POST['course'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="year_level">Year Level</label>
                    <select id="year_level" name="year_level" required>
                        <option value="">Select Year Level</option>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?= $i ?>" <?= (isset($_POST['year_level']) && (int)$_POST['year_level'] === $i) ? 'selected' : '' ?>><?= $i ?><?= $i === 1 ? 'st' : ($i === 2 ? 'nd' : ($i === 3 ? 'rd' : 'th')) ?> Year</option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Document Request</h3>
            
            <div class="field">
                <label for="service_id">Select Document Type</label>
                <select id="service_id" name="service_id" required>
                    <option value="">Select a document</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($selected_service_id == $s['id'] || (isset($_POST['service_id']) && (int)$_POST['service_id'] === $s['id'])) ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> - <?= peso($s['fee']) ?> (<?= $s['processing_days'] ?> days)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="field">
                <label for="notes">Additional Notes (Optional)</label>
                <textarea id="notes" name="notes" rows="4"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn-primary">Continue to Payment</button>
            <a href="<?= url_simple('index.php') ?>" class="btn-ghost">Cancel</a>
        </div>
    </form>
    
    <?php elseif ($step === 2): ?>
    <!-- Step 2: Payment Information & Screenshot Upload -->
    <?php
    $service_id = (int) ($_SESSION['form_data']['service_id'] ?? 0);
    $stmt = db()->prepare('SELECT fee FROM services WHERE id = ?');
    $stmt->execute([$service_id]);
    $service = $stmt->fetch();
    $amount = $service['fee'] ?? 0;
    ?>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="2">
        
        <div class="form-section">
            <h3>Payment Information</h3>
            
            <div style="background: #f5f5f5; padding: 30px; border: 2px solid #000000; margin-bottom: 20px; text-align: center;">
                <p style="font-size: 18px; font-weight: bold; margin: 0 0 20px 0;">Scan the GCash QR Code below to send payment:</p>
                <img src="<?= url_simple('assets/gcash.jfif') ?>" alt="GCash QR Code" style="max-width: 300px; margin: 0 auto 20px; display: block;">
                <p style="font-size: 20px; font-weight: bold; margin: 0 0 10px 0;">GCash Number: <?= e($gcash_code) ?></p>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">Amount to Pay: <?= peso($amount) ?></p>
            </div>
            
            <div class="field">
                <label for="payment_screenshot">Upload Payment Screenshot *</label>
                <div class="file-upload-zone" id="uploadZone">
                    <input type="file" id="payment_screenshot" name="payment_screenshot" accept="image/jpeg,image/png,image/jfif" required>
                    <div class="file-upload-icon">📷</div>
                    <div class="file-upload-text">Click or drag and drop to upload</div>
                    <div class="file-upload-subtext">Supports: JPEG, PNG, JFIF (Max 5MB)</div>
                </div>
                <div class="file-preview" id="filePreview">
                    <img id="previewImage" src="" alt="Payment Screenshot Preview">
                    <button type="button" class="file-preview-remove" onclick="removeFile()">Remove</button>
                </div>
                <p style="font-size: 12px; color: #666; margin-top: 10px;">Please upload a clear screenshot of your GCash payment receipt showing the payment amount and date.</p>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn-primary">Submit Request</button>
            <button type="button" onclick="history.back()" class="btn-ghost">Back</button>
        </div>
    </form>
    
    <?php endif; ?>
</div>

<?php if ($success_data): ?>
<div class="confirmation-overlay">
    <div class="confirmation-box">
        <h2>✓ Payment Submitted Successfully!</h2>
        <p>Thank you, <strong><?= e($success_data['student_name']) ?></strong></p>
        <p>Your payment has been received and will be verified by the cashier.</p>
        
        <div class="details">
            <p>Reference Number: <span><?= e($success_data['ref_no']) ?></span></p>
            <p>Payment Reference: <span><?= e($success_data['payment_ref']) ?></span></p>
            <p>Amount Paid: <span><?= peso($success_data['amount']) ?></span></p>
            <p>Contact Number: <span><?= e($success_data['contact_number']) ?></span></p>
        </div>
        
        <p style="font-size: 14px; color: #666; margin-top: 15px;">You will be contacted using the contact number provided above once your document is ready.</p>
        
        <div class="confirmation-buttons">
            <a href="<?= url_simple('guest-request.php?dismiss=1') ?>" class="btn-primary">OK</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('payment_screenshot');
    const filePreview = document.getElementById('filePreview');
    const previewImage = document.getElementById('previewImage');
    
    if (uploadZone && fileInput) {
        // Handle file selection
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                showPreview(file);
            }
        });
        
        // Drag and drop handlers
        uploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            uploadZone.classList.add('dragover');
        });
        
        uploadZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            uploadZone.classList.remove('dragover');
        });
        
        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            uploadZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showPreview(files[0]);
            }
        });
    }
    
    function showPreview(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            filePreview.classList.add('show');
            uploadZone.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
});

function removeFile() {
    const fileInput = document.getElementById('payment_screenshot');
    const filePreview = document.getElementById('filePreview');
    const uploadZone = document.getElementById('uploadZone');
    
    fileInput.value = '';
    filePreview.classList.remove('show');
    uploadZone.style.display = 'block';
}
</script>

</body>
</html>
