<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sms.php';
$user = require_student();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $course = trim((string) ($_POST['course'] ?? ''));
        $year  = (int) ($_POST['year_level'] ?? 0);
        $phoneRaw = trim((string) ($_POST['phone'] ?? ''));
        $phone    = $phoneRaw === '' ? null : sms_normalize_phone($phoneRaw);

        if ($phoneRaw !== '' && $phone === null) {
            $errors[] = 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (!str_ends_with(strtolower($email), '@gmail.com')) {
            $errors[] = 'You must use a @gmail.com address.';
        } elseif ($year < 1 || $year > 5) {
            $errors[] = 'Please choose a valid year level.';
        } else {
            $stmt = db()->prepare('SELECT id FROM students WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'That email is already used by another account.';
            }
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare('UPDATE students SET email = ?, course = ?, year_level = ?, phone = ? WHERE id = ?');
                $stmt->execute([$email, $course, $year, $phone, $user['id']]);
            } catch (PDOException $e) {
                // Database not upgraded yet (no phone column): save everything else.
                error_log('profile phone save failed: ' . $e->getMessage());
                $stmt = db()->prepare('UPDATE students SET email = ?, course = ?, year_level = ? WHERE id = ?');
                $stmt->execute([$email, $course, $year, $user['id']]);
            }

            flash_set('success', 'Profile updated.');
            redirect('student/profile.php');
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM students WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (!$errors) {
            $stmt = db()->prepare('UPDATE students SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash_set('success', 'Password changed successfully.');
            redirect('student/profile.php');
        }
    }
}

$currentYear = (int) ($user['year_level'] ?? 0);
$currentPhone = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])
    ? (string) $_POST['phone']
    : (string) (student_phone((int) $user['id']) ?? '');

$title = 'Profile';
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>My profile</h1>
        <p class="muted">Keep your contact and program details up to date.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass" role="alert"><?= icon('alert') ?><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <div class="panel glass">
        <div class="profile-top">
            <span class="avatar avatar-lg"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
            <div>
                <h2 style="margin:0"><?= e($user['full_name']) ?></h2>
                <span class="muted small"><code><?= e($user['student_no']) ?></code> · Student</span>
            </div>
        </div>
        <dl class="kv">
            <dt>Student No.</dt><dd><code><?= e($user['student_no']) ?></code> <span class="dim small">(fixed)</span></dd>
            <dt>Full name</dt><dd><?= e($user['full_name']) ?></dd>
            <dt>Member since</dt><dd><?= nice_date($user['created_at']) ?></dd>
        </dl>
    </div>

    <form method="post" action="<?= url('student/profile.php') ?>" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="panel glass">
            <div class="panel-head"><h2>Contact &amp; program</h2></div>
            <div class="form-grid">
                <div class="field span2">
                    <label for="email">Email</label>
                    <div class="input-icon"><?= icon('file-text') ?>
                        <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
                    </div>
                </div>
                <div class="field span2">
                    <label for="phone">Mobile number <span class="dim">(for SMS updates)</span></label>
                    <div class="input-icon"><?= icon('phone') ?>
                        <input type="tel" id="phone" name="phone" value="<?= e($currentPhone) ?>"
                               placeholder="09171234567" maxlength="20" inputmode="tel">
                    </div>
                    <span class="hint">Optional. We text you when your request is verified, processed or ready to claim.</span>
                </div>
                <div class="field">
                    <label for="course">Course</label>
                    <div class="input-icon"><?= icon('doc') ?>
                        <input id="course" name="course" value="<?= e($user['course']) ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="year_level">Year level</label>
                    <select id="year_level" name="year_level" required>
                        <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => '5th Year'] as $v => $label): ?>
                            <option value="<?= $v ?>" <?= $currentYear === $v ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="panel-actions" style="margin-top:16px">
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save changes</button>
            </div>
        </div>
    </form>

    <form method="post" action="<?= url('student/profile.php') ?>" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="panel glass">
            <div class="panel-head"><h2>Change password</h2></div>
            <div class="stack">
                <div class="field">
                    <label for="current_password">Current password</label>
                    <div class="input-icon"><?= icon('shield') ?>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="new_password">New password</label>
                        <div class="input-icon"><?= icon('shield') ?>
                            <input type="password" id="new_password" name="new_password" minlength="8" required>
                            <button type="button" class="pw-toggle" data-pw-toggle="#new_password" aria-label="Show password"><?= icon('eye') ?></button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="input-icon"><?= icon('shield') ?>
                            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="panel-actions" style="margin-top:16px">
                <button class="btn btn-soft" type="submit"><?= icon('shield') ?> Update password</button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>