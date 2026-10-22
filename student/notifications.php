<?php
/**
 * Student notifications  (DFD 8.0 document update notification -> student)
 * Shows everything stored in sms_notifications (D:9) for this student,
 * whether or not a mobile number was on file when it was sent.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sms.php';
$user = require_student();

$notifs = [];
$loadError = false;
try {
    $stmt = db()->prepare(
        'SELECT n.*, r.ref_no
         FROM sms_notifications n
         LEFT JOIN requests r ON r.id = n.request_id
         WHERE n.student_id = ?
         ORDER BY n.id DESC
         LIMIT 100'
    );
    $stmt->execute([$user['id']]);
    $notifs = $stmt->fetchAll();

    // Opening the inbox marks everything as read (the rows above still show as "new" this once).
    db()->prepare('UPDATE sms_notifications SET read_at = NOW() WHERE student_id = ? AND read_at IS NULL')
        ->execute([$user['id']]);
} catch (Throwable $e) {
    error_log('notifications load failed: ' . $e->getMessage());
    $loadError = true;
}

$hasPhone = student_phone((int) $user['id']) !== null;

$title = 'Notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="page page-narrow">
    <div class="page-head">
        <h1>Notifications</h1>
        <p class="muted">Updates about your document requests and payments.</p>
    </div>

    <?php if (!$hasPhone): ?>
        <div class="alert glass" role="status">
            <?= icon('phone') ?>
            <span>Add your mobile number in your <a href="<?= url('student/profile.php') ?>">profile</a> to also get these updates by SMS.</span>
        </div>
    <?php endif; ?>

    <?php if ($loadError): ?>
        <div class="alert alert-error glass"><?= icon('alert') ?><span>Notifications are unavailable. Ask the administrator to run the database upgrade (open setup.php).</span></div>
    <?php elseif (!$notifs): ?>
        <div class="panel glass empty-note">No notifications yet. You will see an update here every time the status of a request changes.</div>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($notifs as $n): ?>
                <article class="panel glass">
                    <div class="panel-head">
                        <div>
                            <?php if ($n['read_at'] === null): ?><span class="pill pill-info">New</span><?php endif; ?>
                            <?php if ($n['ref_no']): ?><code><?= e($n['ref_no']) ?></code><?php endif; ?>
                        </div>
                        <span class="muted small"><?= nice_date($n['created_at']) ?></span>
                    </div>
                    <p style="margin:0"><?= e($n['message']) ?></p>
                    <p class="dim small" style="margin:8px 0 0">
                        <?php if ($n['status'] === 'sent'): ?>
                            SMS sent to <?= e($n['phone']) ?>
                        <?php elseif ($n['status'] === 'failed'): ?>
                            SMS could not be delivered
                        <?php elseif ($n['status'] === 'no_number'): ?>
                            No mobile number on file — shown here only
                        <?php else: ?>
                            SMS pending
                        <?php endif; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
