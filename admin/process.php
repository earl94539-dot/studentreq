<?php
/**
 * Request review  (DFD 4.0 request verification, 4.1 payment transaction, 7.0 request processing)
 *
 *   Registrar : verifies / declines a pending request (4.0), then moves a paid
 *               request processing -> ready -> released (7.0).
 *   Cashier   : verifies the student's payment receipt, declines it, or records a
 *               cash payment (4.1). A verified payment generates the receipt (5.0).
 *
 * Every action writes to the audit log (D:5) and notifies the student (D:9 / 8.0).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/sms.php';
$admin       = require_admin();
$isRegistrar = $admin['role'] === 'registrar';
$isCashier   = $admin['role'] === 'cashier';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT r.*, s.name AS service_name, s.processing_days,
            u.full_name, u.student_no, u.email, u.course, u.year_level
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$r = $stmt->fetch();

if (!$r) {
    flash_set('error', 'Request not found.');
    redirect('admin/requests.php');
}

$errors = [];

/** Status changes the registrar may make (4.0 verification, 7.0 processing). */
const REGISTRAR_TRANSITIONS = [
    'pending'    => ['approved', 'rejected'],
    'approved'   => ['processing', 'ready', 'rejected'],
    'processing' => ['ready', 'rejected'],
    'ready'      => ['released'],
];

function process_back(int $id, string $type, string $message): never
{
    flash_set($type, $message);
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer && (str_contains($referer, 'dashboard.php') || str_contains($referer, 'requests.php'))) {
        header('Location: ' . $referer);
        exit;
    }
    redirect('admin/process.php?id=' . $id);
}

/** Email the student about a request status change. */
function email_student_status(array $r, string $status, string $note): void
{
    $ref  = $r['ref_no'];
    $name = e($r['full_name']);
    $svc  = e($r['service_name']);
    $titles = [
        'approved'   => "Request Approved: $ref",
        'processing' => "Request is Now Processing: $ref",
        'ready'      => "Your Document is Ready for Pickup: $ref",
        'released'   => "Document Released: $ref",
        'rejected'   => "Update Regarding Your Request: $ref",
    ];
    $bodies = [
        'approved'   => "<p>Hello <strong>$name</strong>,</p><p>Good news! Your document request for <strong>$svc</strong> (Reference: <code>$ref</code>) has been verified and <strong>approved</strong> by the registrar.</p><p>Please log in to the portal to pay and upload your payment receipt.</p>",
        'processing' => "<p>Hello <strong>$name</strong>,</p><p>Your request for <strong>$svc</strong> (Reference: <code>$ref</code>) is now being <strong>processed</strong> by the registrar's office.</p>",
        'ready'      => "<p>Hello <strong>$name</strong>,</p><p>Your requested document, <strong>$svc</strong> (Reference: <code>$ref</code>), is now <strong>ready for claiming</strong> at the Registrar's Office. Please bring your student ID and reference number.</p>",
        'released'   => "<p>Hello <strong>$name</strong>,</p><p>Your requested document <strong>$svc</strong> (Reference: <code>$ref</code>) has been marked as officially <strong>released</strong>.</p>",
        'rejected'   => "<p>Hello <strong>$name</strong>,</p><p>Your request for <strong>$svc</strong> (Reference: <code>$ref</code>) was declined.</p>" . ($note !== '' ? '<p><strong>Reason provided:</strong> ' . e($note) . '</p>' : ''),
    ];
    if (isset($titles[$status])) {
        @send_mail($r['email'], $titles[$status], $bodies[$status]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        if (!$isRegistrar) {
            process_back($id, 'error', 'Only the registrar can change the request status.');
        }
        $status  = strtolower(trim((string) ($_POST['status'] ?? '')));
        $note    = trim((string) ($_POST['admin_note'] ?? ''));
        $current = (string) $r['status'];
        $allowed = in_array($current, ['rejected', 'released'], true)
            ? []
            : array_merge([$current], REGISTRAR_TRANSITIONS[$current] ?? []);

        if (!in_array($status, $allowed, true)) {
            $errors[] = 'That status change is not allowed from "' . $current . '".';
        } elseif (mb_strlen($note) > 500) {
            $errors[] = 'Note is limited to 500 characters.';
        } elseif ($status === 'rejected' && $note === '') {
            $errors[] = 'Please provide a reason for rejecting this request.';
        }

        if (!$errors) {
            $stmt = db()->prepare('UPDATE requests SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $stmt->execute([$status, $note !== '' ? $note : null, $id, $current]);
            if ($stmt->rowCount() === 0 && $status !== $current) {
                process_back($id, 'error', 'This request was changed by someone else. Please review it again.');
            }

            if ($status === $current) {
                audit_log($admin['id'], 'request_note', 'Updated note on ' . $r['ref_no']);
                process_back($id, 'success', 'Note saved.');
            }

            $logAction = $status === 'approved' ? 'request_verified'
                : ($status === 'rejected' ? 'request_rejected' : 'request_status');
            audit_log($admin['id'], $logAction, 'Set ' . $r['ref_no'] . ' to "' . $status . '"' . ($note !== '' ? ' — note: ' . $note : ''));

            email_student_status($r, $status, $note);
            notify_request_status($r, $status);

            process_back($id, 'success', $r['ref_no'] . ' is now marked "' . $status . '".');
        }
    } elseif ($action === 'cashier_approve') {
        // ---- Cashier: first-step approval — forwards request to the registrar ----
        if (!$isCashier) {
            process_back($id, 'error', 'Only the cashier can approve requests at this stage.');
        }
        if ($r['status'] !== 'pending') {
            process_back($id, 'error', 'Only pending requests can be approved by the cashier.');
        }
        $stmt = db()->prepare(
            'UPDATE requests SET status = "approved", updated_at = NOW() WHERE id = ? AND status = "pending"'
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            process_back($id, 'error', 'Could not approve this request. It may have already been updated.');
        }

        // Automatically mark the request's payment as paid so it is collected immediately
        $pdo = db();
        $updPay = $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW() WHERE request_id = ? AND status = "pending"');
        $updPay->execute([$id]);

        // If no payment record existed, create one as paid
        $chkPaid = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE request_id = ? AND status = "paid"');
        $chkPaid->execute([$id]);
        if ((int)$chkPaid->fetchColumn() === 0) {
            $txn = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $ins = $pdo->prepare('INSERT INTO payments (request_id, method, amount, currency, transaction_number, status) VALUES (?, "cash", ?, "PHP", ?, "pending")');
            $ins->execute([$id, $r['fee'], $txn]);
            $newPid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW() WHERE id = ?')->execute([$newPid]);
        }

        audit_log(
            $admin['id'],
            'cashier_approved',
            'Cashier approved ' . $r['ref_no'] . ' (payment of ' . peso($r['fee']) . ' collected) — forwarded to Registrar',
            'cashier'
        );
        // Notify student that request is verified and forwarded
        notify_request_status($r, 'approved');
        @send_mail(
            $r['email'],
            'Request Approved: ' . $r['ref_no'],
            '<p>Hello <strong>' . e($r['full_name']) . '</strong>,</p>'
            . '<p>Your request for <strong>' . e($r['service_name']) . '</strong> '
            . '(Reference: <code>' . e($r['ref_no']) . '</code>) has been approved and payment verified by the cashier '
            . 'and has been forwarded to the Registrar for processing.</p>'
            . '<p>Please wait while the Registrar prepares your document.</p>'
        );
        process_back($id, 'success', $r['ref_no'] . ' approved — ' . peso($r['fee']) . ' collected and forwarded to the Registrar.');
    } elseif ($action === 'cashier_reject' || $action === 'registrar_reject') {
        if (!$isCashier && !$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $rejectedBy = ($admin['role'] === 'registrar') ? 'registrar' : 'cashier';
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if ($note === '') {
            process_back($id, 'error', 'Please provide a reason for rejection.');
        }
        $stmt = db()->prepare('UPDATE requests SET status = "rejected", rejected_by = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$rejectedBy, $note, $id]);
        audit_log($admin['id'], 'request_rejected', ucfirst($rejectedBy) . ' rejected ' . $r['ref_no'] . ': ' . $note, $admin['role']);
        // Add the rejection reason to the request data for SMS notification
        $r['admin_note'] = $note;
        notify_request_status($r, 'rejected');
        @send_mail(
            $r['email'],
            'Request Declined: ' . $r['ref_no'],
            '<p>Hello <strong>' . e($r['full_name']) . '</strong>,</p>'
            . '<p>Your request for <strong>' . e($r['service_name']) . '</strong> (Reference: <code>' . e($r['ref_no']) . '</code>) has been rejected.</p>'
            . '<p><strong>Reason:</strong> ' . e($note) . '</p>'
            . '<p>Please contact the Registrar if you have any questions.</p>'
        );
        process_back($id, 'success', $r['ref_no'] . ' has been marked as rejected.');
    } elseif ($action === 'registrar_process') {
        if (!$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $stmt = db()->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status = "approved"');
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            audit_log($admin['id'], 'request_processing', 'Started processing ' . $r['ref_no'], 'registrar');
            email_student_status($r, 'processing', '');
            notify_request_status($r, 'processing');
            process_back($id, 'success', $r['ref_no'] . ' is now Processing.');
        }
        process_back($id, 'error', 'Could not update request to processing.');
    } elseif ($action === 'restore_request') {
        if (!$isCashier && !$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $targetStatus = ($admin['role'] === 'registrar') ? 'approved' : 'pending';
        $stmt = db()->prepare('UPDATE requests SET status = ?, rejected_by = NULL, admin_note = NULL, updated_at = NOW() WHERE id = ? AND status = "rejected"');
        $stmt->execute([$targetStatus, $id]);
        if ($stmt->rowCount() > 0) {
            audit_log($admin['id'], 'request_restored', 'Restored ' . $r['ref_no'] . ' back to ' . $targetStatus, $admin['role']);
            notify_request_status($r, $targetStatus);
            process_back($id, 'success', $r['ref_no'] . ' has been restored back to ' . $targetStatus . '.');
        }
        process_back($id, 'error', 'Could not restore this request.');
    } elseif ($action === 'delete_request') {
        if (!$isCashier && !$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $refNo = $r['ref_no'];
        $stmt = db()->prepare('DELETE FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        audit_log($admin['id'], 'request_deleted', 'Permanently deleted request ' . $refNo, $admin['role']);
        flash_set('success', 'Request ' . $refNo . ' has been permanently deleted.');
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer && (str_contains($referer, 'dashboard.php') || str_contains($referer, 'requests.php'))) {
            header('Location: ' . $referer);
            exit;
        }
        redirect('admin/dashboard.php');
    } elseif ($action === 'registrar_ready') {
        if (!$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $stmt = db()->prepare('UPDATE requests SET status = "ready", updated_at = NOW() WHERE id = ? AND status IN ("approved", "processing")');
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            audit_log($admin['id'], 'request_ready', 'Marked ' . $r['ref_no'] . ' as ready for pickup', 'registrar');
            email_student_status($r, 'ready', '');
            notify_request_status($r, 'ready');
            process_back($id, 'success', $r['ref_no'] . ' is now Ready for pickup.');
        }
        process_back($id, 'error', 'Could not update request.');
    } elseif ($action === 'registrar_release') {
        if (!$isRegistrar) {
            process_back($id, 'error', 'Permission denied.');
        }
        $stmt = db()->prepare('UPDATE requests SET status = "released", updated_at = NOW() WHERE id = ? AND status = "ready"');
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            audit_log($admin['id'], 'request_released', 'Released ' . $r['ref_no'] . ' to student', 'registrar');
            email_student_status($r, 'released', '');
            notify_request_status($r, 'released');
            process_back($id, 'success', $r['ref_no'] . ' has been marked as officially Released.');
        }
        process_back($id, 'error', 'Could not release request.');
    } elseif ($action === 'resend_sms') {
        if (!$isRegistrar) {
            process_back($id, 'error', 'Only the registrar can resend notifications.');
        }
        $nid   = (int) ($_POST['notification_id'] ?? 0);
        $phone = student_phone((int) $r['student_id']);
        if ($phone === null) {
            process_back($id, 'error', 'This student has no mobile number saved yet. Ask them to add it in their profile.');
        }
        $stmt = db()->prepare(
            'UPDATE sms_notifications
             SET phone = ?, status = "queued", provider_response = NULL
             WHERE id = ? AND request_id = ? AND status IN ("no_number", "failed")'
        );
        $stmt->execute([$phone, $nid, $id]);
        if ($stmt->rowCount() === 0) {
            process_back($id, 'error', 'That notification cannot be resent.');
        }
        $ok = dispatch_sms($nid);
        audit_log($admin['id'], 'sms_resend', 'Resent notification #' . $nid . ' on ' . $r['ref_no'] . ($ok ? '' : ' (failed)'));
        process_back($id, $ok ? 'success' : 'error', $ok ? 'Notification sent to ' . $phone . '.' : 'The SMS could not be sent. Check the SMS settings.');
    } elseif ($action === 'verify_receipt') {
        if (!$isCashier) {
            process_back($id, 'error', 'Only the cashier can verify payments.');
        }
        if ($r['status'] !== 'approved') {
            process_back($id, 'error', 'The registrar must verify this request before its payment can be verified.');
        }
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND request_id = ?');
        $stmt->execute([$pid, $id]);
        $payment = $stmt->fetch();
        if (!$payment || $payment['status'] !== 'pending' || !$payment['proof_image']) {
            process_back($id, 'error', 'Could not verify receipt.');
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW() WHERE id = ? AND status = "pending"')->execute([$pid]);
            $pdo->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status = "approved"')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('verify_receipt failed: ' . $e->getMessage());
            process_back($id, 'error', 'Could not verify receipt. Please try again.');
        }

        audit_log($admin['id'], 'payment_verified', 'Verified payment #' . $pid . ' (' . peso($payment['amount']) . ') on ' . $r['ref_no']);
        @send_mail(
            $r['email'],
            'Payment Verified: ' . $r['ref_no'],
            '<p>Hello <strong>' . e($r['full_name']) . '</strong>,</p><p>Your payment for <strong>' . e($r['service_name']) . '</strong> (Reference: <code>' . e($r['ref_no']) . '</code>) has been verified by the cashier and your official receipt is available in the portal. Your request is now proceeding to document processing.</p>'
        );
        notify_request_status($r, 'processing');
        process_back($id, 'success', 'Payment verified — receipt issued and request is now processing.');
    } elseif ($action === 'decline_receipt') {
        if (!$isCashier) {
            process_back($id, 'error', 'Only the cashier can decline payments.');
        }
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $reason = trim((string) ($_POST['decline_reason'] ?? ''));
        $customReason = trim((string) ($_POST['decline_reason_custom'] ?? ''));
        if ($customReason !== '' && ($reason === 'Other' || $reason === '')) {
            $reason = $customReason;
        }
        if ($reason === '' || $reason === 'Other') {
            $errors[] = 'Please provide a reason for declining the receipt.';
        } else {
            $reason = mb_substr($reason, 0, 250);
            $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND request_id = ?');
            $stmt->execute([$pid, $id]);
            $payment = $stmt->fetch();
            if ($payment && $payment['status'] === 'pending' && $payment['proof_image']) {
                $stmt = db()->prepare('UPDATE payments SET status = "failed", failure_reason = ? WHERE id = ? AND status = "pending"');
                $stmt->execute([$reason, $pid]);
                audit_log($admin['id'], 'payment_declined', 'Declined payment #' . $pid . ' on ' . $r['ref_no'] . ': ' . $reason);

                @send_mail(
                    $r['email'],
                    'Payment Receipt Action Required: ' . $r['ref_no'],
                    '<p>Hello <strong>' . e($r['full_name']) . '</strong>,</p><p>Your payment receipt for request <strong>' . e($r['service_name']) . '</strong> (Reference: <code>' . e($r['ref_no']) . '</code>) could not be verified.</p><p><strong>Reason:</strong> ' . e($reason) . '</p><p>Please log in to the portal and upload a valid receipt.</p>'
                );
                notify_request_status($r, 'payment_declined');
                process_back($id, 'success', 'Receipt declined. The student was notified to upload a new receipt.');
            }
            process_back($id, 'error', 'Could not decline receipt.');
        }
    } elseif ($action === 'record_cash') {
        if (!$isCashier) {
            process_back($id, 'error', 'Only the cashier can record payments.');
        }
        if ($r['status'] !== 'approved') {
            process_back($id, 'error', 'The registrar must verify this request before payment can be recorded.');
        }
        $paid = db()->prepare('SELECT COUNT(*) FROM payments WHERE request_id = ? AND status = "paid"');
        $paid->execute([$id]);
        if ((int) $paid->fetchColumn() > 0) {
            process_back($id, 'error', 'This request is already paid.');
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();
            $open = $pdo->prepare('SELECT id FROM payments WHERE request_id = ? AND status IN ("pending","failed") ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $open->execute([$id]);
            $openId = (int) $open->fetchColumn();
            if ($openId > 0) {
                $pdo->prepare('UPDATE payments SET method = "cash", status = "paid", failure_reason = NULL, paid_at = NOW() WHERE id = ?')->execute([$openId]);
                $pid = $openId;
            } else {
                $txn = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $pdo->prepare('INSERT INTO payments (request_id, method, amount, currency, transaction_number, status) VALUES (?, "cash", ?, "PHP", ?, "pending")')
                    ->execute([$id, $r['fee'], $txn]);
                $pid = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW() WHERE id = ?')->execute([$pid]);
            }
            $pdo->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status = "approved"')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('record_cash failed: ' . $e->getMessage());
            process_back($id, 'error', 'Could not record the cash payment. If this keeps happening, run migrations/dfd_alignment.sql (or open setup.php).');
        }

        audit_log($admin['id'], 'payment_cash', 'Recorded cash payment #' . $pid . ' (' . peso($r['fee']) . ') on ' . $r['ref_no']);
        @send_mail(
            $r['email'],
            'Payment Received: ' . $r['ref_no'],
            '<p>Hello <strong>' . e($r['full_name']) . '</strong>,</p><p>Your cash payment for <strong>' . e($r['service_name']) . '</strong> (Reference: <code>' . e($r['ref_no']) . '</code>) was received by the cashier. Your official receipt is available in the portal and your request is now being processed.</p>'
        );
        notify_request_status($r, 'processing');
        process_back($id, 'success', 'Cash payment recorded — receipt issued and request is now processing.');
    }
}

$stmt = db()->prepare('SELECT * FROM payments WHERE request_id = ? AND status != "failed" ORDER BY created_at DESC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$payMethodNames = [
    'cash'          => 'Cash',
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank'          => 'Bank transfer',
    'bank_transfer' => 'Bank Transfer',
    'mock'          => 'Simulated Gateway',
];

$payStatusNames = [
    'pending' => 'Pending',
    'paid'    => 'Paid',
    'failed'  => 'Declined',
];

// Compute dates
$createdTs  = strtotime($r['created_at']);
$expectedTs = $createdTs + ((int)$r['processing_days'] * 86400);

// ── CASHIER: own minimal layout (no shared navbar) ────────────────────────────
if ($isCashier):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Review <?= e($r['ref_no']) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= url('assets/style.css') ?>">
<link rel="stylesheet" href="<?= url('assets/glass.css') ?>">
<style>
    .cashier-topbar {
        position: fixed;
        top: 0; right: 0; left: 0;
        z-index: 200;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 28px;
        height: 70px;
        background: rgba(255,255,255,0.06);
        backdrop-filter: blur(18px);
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .cashier-topbar .brand {
        display: flex;
        align-items: center;
        gap: 14px;
        color: #fff;
        text-decoration: none;
    }
    .cashier-topbar .brand img {
        width: 48px;
        height: 48px;
        object-fit: contain;
        border-radius: 8px;
    }
    .cashier-topbar .brand-text-wrap {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
    }
    .cashier-topbar .school-name {
        font-weight: 700;
        font-size: 20px;
        letter-spacing: -0.01em;
        color: #ffffff;
    }
    .cashier-topbar .cashier-tag {
        font-weight: 500;
        font-size: 13px;
        color: rgba(255,255,255,0.65);
    }
    .cashier-topbar .logout-btn {
        display: flex; align-items: center; gap: 6px;
        padding: 8px 18px;
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 8px; color: rgba(255,255,255,0.85);
        font-size: 14px; font-weight: 500; text-decoration: none;
        transition: background 0.2s, border-color 0.2s;
    }
    .cashier-topbar .logout-btn:hover {
        background: rgba(239,68,68,0.25);
        border-color: rgba(239,68,68,0.5); color: #fca5a5;
    }
    .cashier-body { padding-top: 92px; min-height: 100vh; }
    .proof-inline {
        width: 100%;
        max-height: 420px;
        object-fit: contain;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.15);
        background: rgba(0,0,0,0.3);
        margin-top: 12px;
        cursor: zoom-in;
    }
    .proof-inline.zoomed {
        max-height: none;
        cursor: zoom-out;
    }
</style>
</head>
<body class="mode-app">
<div class="backdrop" aria-hidden="true">
    <div class="orb orb-a"></div><div class="orb orb-b"></div>
    <div class="orb orb-center"></div>
    <div class="orb orb-c"></div><div class="orb orb-d"></div>
    <div class="grid-overlay"></div>
</div>

<div class="cashier-topbar">
    <a class="brand" href="<?= url('admin/dashboard.php') ?>">
        <img src="<?= url('assets/logo (2).png') ?>" alt="Logo">
        <div class="brand-text-wrap">
            <span class="school-name">Stratford International School</span>
            <span class="cashier-tag">Cashier Portal</span>
        </div>
    </a>
    <a class="logout-btn"
       href="<?= url('logout.php') ?>"
       data-confirm-title="Sign out?"
       data-confirm="Are you sure you want to sign out?"
       data-confirm-yes="Yes, sign out"
       data-confirm-no="No, stay">
        <?= icon('logout') ?> Sign out
    </a>
</div>

<main class="main cashier-body">
<div class="page page-narrow">
    <div class="page-head">
        <h1>Request Review</h1>
        <p class="muted">Reference <code><?= e($r['ref_no']) ?></code></p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <!-- Student + request info -->
    <div class="panel glass">
        <div class="panel-head">
            <h2><?= e($r['service_name']) ?></h2>
            <?php if ($r['status'] === 'approved' && (!$payments || $payments[0]['status'] !== 'paid')): ?>
                <?= status_pill('approved', 'Approved – awaiting payment') ?>
            <?php else: ?>
                <?= status_pill($r['status']) ?>
            <?php endif; ?>
        </div>
        <dl class="kv">
            <dt>Student</dt>
            <dd>
                <strong><?= e($r['full_name']) ?></strong>
                <span class="small muted" style="margin-left:8px"><?= e($r['student_no']) ?></span>
            </dd>
            <dt>Date Requested</dt>
            <dd><?= date('M j, Y', $createdTs) ?></dd>
            <dt>Expected Release</dt>
            <dd><strong><?= date('M j, Y', $expectedTs) ?></strong>
                <span class="small muted">(<?= (int)$r['processing_days'] ?> working day<?= (int)$r['processing_days'] === 1 ? '' : 's' ?> from request)</span>
            </dd>
            <dt>Fee</dt>
            <dd class="money"><?= peso($r['fee']) ?></dd>
        </dl>
    </div>

    <!-- Payments panel with inline proof image -->
    <div class="panel glass">
        <div class="panel-head"><h2>Payments (<?= count($payments) ?>)</h2></div>
        <?php if ($payments): ?>
            <div class="stack">
                <?php foreach ($payments as $p): ?>
                    <div class="payment-row glass">
                        <div>
                            <strong class="money"><?= peso($p['amount']) ?></strong>
                            <span class="pill pill-info"><?= e($payMethodNames[$p['method']] ?? ucfirst($p['method'])) ?></span>
                            <span class="small muted">ref <code><?= e($p['transaction_number']) ?></code>
                                · <span class="pill <?= $p['status'] === 'paid' ? 'pill-ok' : ($p['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>">
                                    <?= e($payStatusNames[$p['status']] ?? ucfirst($p['status'])) ?>
                                  </span>
                                · <?= nice_date($p['created_at']) ?>
                            </span>
                        </div>

                        <?php if ($p['proof_image']): ?>
                            <!-- Inline proof image — click to zoom -->
                            <img src="<?= url($p['proof_image']) ?>"
                                 alt="Payment proof"
                                 class="proof-inline"
                                 onclick="this.classList.toggle('zoomed')"
                                 title="Click to zoom">
                        <?php endif; ?>

                        <div class="row-actions" style="margin-top:12px">
                            <?php if ($isCashier && $r['status'] === 'approved' && $p['status'] === 'pending' && $p['proof_image']): ?>
                                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="verify_receipt">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn btn-ok btn-sm" type="submit"
                                            data-confirm="Verify this receipt and mark payment as paid?">
                                        <?= icon('check') ?> Verify
                                    </button>
                                </form>
                                <button class="btn btn-danger btn-sm" type="button"
                                        onclick="document.getElementById('decline-<?= (int) $p['id'] ?>').style.display='block'">
                                    <?= icon('x') ?> Decline
                                </button>
                                <div id="decline-<?= (int) $p['id'] ?>" style="display:none;margin-top:10px;padding:10px;background:var(--glass-bg);border-radius:8px;">
                                    <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decline_receipt">
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                        <div class="field">
                                            <label>Reason for decline</label>
                                            <select name="decline_reason" required>
                                                <option value="">Select a reason...</option>
                                                <option value="Invalid receipt - image unclear">Invalid receipt – image unclear</option>
                                                <option value="Invalid receipt - wrong reference number">Invalid receipt – wrong reference number</option>
                                                <option value="Invalid receipt - amount does not match">Invalid receipt – amount does not match</option>
                                                <option value="Invalid receipt - expired">Invalid receipt – expired</option>
                                                <option value="Invalid receipt - not from GCash/Maya/Bank">Invalid receipt – not from GCash/Maya/Bank</option>
                                                <option value="Other">Other (specify below)</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <input type="text" name="decline_reason_custom" placeholder="Or type custom reason..." style="width:100%">
                                        </div>
                                        <button class="btn btn-danger btn-sm" type="submit">Submit decline</button>
                                        <button class="btn btn-ghost btn-sm" type="button"
                                                onclick="document.getElementById('decline-<?= (int) $p['id'] ?>').style.display='none'">Cancel</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        $hasPaid = false;
        foreach ($payments as $p) { if ($p['status'] === 'paid') { $hasPaid = true; break; } }
        ?>
        <?php if ($isCashier && $r['status'] === 'approved' && !$hasPaid): ?>
            <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_cash">
                <button class="btn btn-ok" type="submit"
                        data-confirm="Record a cash payment of <?= e(peso($r['fee'])) ?> and issue the receipt?">
                    <?= icon('money') ?> Record cash payment (walk-in)
                </button>
                <span class="hint">Use this when the student pays in cash at the cashier window.</span>
            </form>
        <?php elseif ($isCashier && $r['status'] === 'pending'): ?>
            <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cashier_approve">
                    <button class="btn btn-ok" type="submit"
                            data-confirm="Approve this request, collect <?= e(peso($r['fee'])) ?>, and forward to Registrar?">
                        <?= icon('check') ?> Approve Request & Collect Payment (<?= peso($r['fee']) ?>)
                    </button>
                </form>
                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cashier_reject">
                    <button class="btn btn-danger" type="submit"
                            data-confirm="Are you sure you want to reject this request?">
                        <?= icon('x') ?> Reject Request
                    </button>
                </form>
            </div>
        <?php elseif ($isCashier && $r['status'] === 'rejected'): ?>
            <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore_request">
                    <button class="btn btn-soft" type="submit"
                            data-confirm="Restore this request back to pending?">
                        <?= icon('trend') ?> Restore to Pending
                    </button>
                </form>
                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_request">
                    <button class="btn btn-danger" type="submit"
                            data-confirm="Permanently delete this request? This cannot be undone.">
                        <?= icon('trash') ?> Delete Request
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:12px">
        <a class="btn btn-ghost" href="<?= url('admin/requests.php') ?>"><?= icon('list') ?> Back to list</a>
    </div>
</div>
</main>

<script src="<?= url('assets/glass.js') ?>" defer></script>
</body>
</html>

<?php else:
// ── REGISTRAR VIEW (unchanged) ────────────────────────────────────────────────
$title = 'Review ' . $r['ref_no'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Request review</h1>
        <p class="muted">Reference <code><?= e($r['ref_no']) ?></code></p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <div class="two-col">
        <div class="panel glass">
            <div class="panel-head">
                <h2><?= e($r['service_name']) ?></h2>
                <?php if ($r['status'] === 'approved' && (!$payments || $payments[0]['status'] !== 'paid')): ?>
                    <?= status_pill('approved', 'Approved - awaiting payment') ?>
                <?php else: ?>
                    <?= status_pill($r['status']) ?>
                <?php endif; ?>
            </div>
            <dl class="kv">
                <dt>Student</dt><dd><strong><?= e($r['full_name']) ?></strong></dd>
                <dt>Student No.</dt><dd><code><?= e($r['student_no']) ?></code></dd>
                <dt>Email</dt><dd><?= e($r['email']) ?></dd>
                <?php $studentMobile = student_phone((int) $r['student_id']); ?>
                <dt>Mobile</dt><dd><?= $studentMobile ? e($studentMobile) : '<span class="muted">Not set — the student has not added a mobile number</span>' ?></dd>
                <dt>Program</dt><dd><?= e(($r['course'] ?: '—') . ($r['year_level'] ? ' · Year ' . (int) $r['year_level'] : '')) ?></dd>
                <dt>Fee</dt><dd class="money"><?= peso($r['fee']) ?></dd>
                <dt>Processing</dt><dd>≈ <?= (int) $r['processing_days'] ?> day<?= (int) $r['processing_days'] === 1 ? '' : 's' ?></dd>
                <dt>Last update</dt><dd><?= nice_date($r['updated_at']) ?></dd>
            </dl>
            <?php if ($r['notes']): ?>
                <div class="alert glass" style="margin-top:14px">
                    <?= icon('info') ?><span><strong>Student notes:</strong> <?= e($r['notes']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel glass">
            <div class="panel-head"><h2>Update status</h2></div>
            <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <div class="field">
                    <label for="status">Pipeline status</label>
                    <?php
                    $stLabels = [
                        'pending'    => 'Pending verification',
                        'approved'   => 'Verified (awaiting payment)',
                        'processing' => 'Processing',
                        'ready'      => 'Ready to receive',
                        'released'   => 'Released (student received)',
                        'rejected'   => 'Declined',
                    ];
                    $stOptions = array_merge([$r['status']], REGISTRAR_TRANSITIONS[$r['status']] ?? []);
                    $stOptions = in_array($r['status'], ['rejected', 'released'], true) ? [] : array_unique($stOptions);
                    $stHints = [
                        'pending'    => 'Verify the request to let the student pay, or decline it with a reason.',
                        'approved'   => 'Waiting for the student to pay. The cashier verifies the payment, which moves this to Processing.',
                        'processing' => 'Payment verified. Mark Ready when the document has been prepared.',
                        'ready'      => 'Mark as Released when the student receives the document.',
                    ];
                    ?>
                    <?php if ($stOptions): ?>
                        <select id="status" name="status">
                            <?php foreach ($stOptions as $st): ?>
                                <?php
                                $optLabel = $stLabels[$st] ?? ucfirst($st);
                                if ($st === 'approved' && $r['status'] === 'pending') $optLabel = 'Verify / approve';
                                if ($st === 'rejected') $optLabel = 'Decline';
                                ?>
                                <option value="<?= e($st) ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint"><?= e($stHints[$r['status']] ?? '') ?></span>
                    <?php else: ?>
                        <div><?= status_pill($r['status']) ?></div>
                        <span class="hint">This request is closed. Its status can no longer be changed.</span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="admin_note">Registrar note <span class="dim">(shown to the student)</span></label>
                    <textarea id="admin_note" name="admin_note" maxlength="500" <?= $stOptions ? '' : 'readonly' ?>
                              placeholder="e.g. Please bring valid ID when claiming. Required if rejecting request."><?= e($r['admin_note']) ?></textarea>
                    <?php if ($r['status'] === 'rejected'): ?>
                        <span class="hint">This note explains to the student why the request was rejected.</span>
                    <?php endif; ?>
                </div>
                <div class="panel-actions">
                    <?php if ($stOptions): ?>
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save update</button>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="<?= url('admin/requests.php') ?>">Back to list</a>
                </div>
            </form>
        </div>
    </div>

    <div class="panel glass">
        <div class="panel-head"><h2>Payments (<?= count($payments) ?>)</h2></div>
        <?php if ($payments): ?>
            <div class="stack">
                <?php foreach ($payments as $p): ?>
                    <div class="payment-row glass">
                        <div>
                            <strong class="money"><?= peso($p['amount']) ?></strong>
                            <span class="pill pill-info"><?= e($payMethodNames[$p['method']] ?? ucfirst($p['method'])) ?></span>
                            <span class="small muted">ref <code><?= e($p['transaction_number']) ?></code> · <span class="pill <?= $p['status'] === 'paid' ? 'pill-ok' : ($p['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>"><?= e($payStatusNames[$p['status']] ?? ucfirst($p['status'])) ?></span> · <?= nice_date($p['created_at']) ?></span>
                        </div>
                        <div class="row-actions">
                            <?php if ($p['proof_image']): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= url('admin/view-proof.php?img=' . urlencode($p['proof_image'])) ?>">
                                    <?= icon('eye') ?> View proof
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isRegistrar):
        $notifs = [];
        try {
            $ns = db()->prepare('SELECT * FROM sms_notifications WHERE request_id = ? ORDER BY id DESC LIMIT 10');
            $ns->execute([$id]);
            $notifs = $ns->fetchAll();
        } catch (Throwable) {
            $notifs = [];
        }
    ?>
        <div class="panel glass">
            <div class="panel-head"><h2>Student notifications (<?= count($notifs) ?>)</h2></div>
            <?php if ($notifs): ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>When</th><th>Status</th><th>Sent to</th><th>Message</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($notifs as $n): ?>
                            <tr>
                                <td class="muted small" style="white-space:nowrap"><?= nice_date($n['created_at']) ?></td>
                                <td><span class="pill <?= $n['status'] === 'sent' ? 'pill-ok' : ($n['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>"><?= e($n['status'] === 'no_number' ? 'no mobile number' : $n['status']) ?></span></td>
                                <td class="small"><?= e($n['phone'] ?: '—') ?></td>
                                <td class="small"><?= e($n['message']) ?></td>
                                <td>
                                    <?php if (in_array($n['status'], ['no_number', 'failed'], true)): ?>
                                        <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="resend_sms">
                                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                            <button class="btn btn-soft btn-sm" type="submit">Resend</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-note">No notifications sent for this request yet.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
