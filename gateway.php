<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_student();

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') { redirect('student/requests.php'); }

$stmt = db()->prepare(
    'SELECT p.*, r.ref_no AS req_ref, r.student_id, s.name AS service_name, u.full_name
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE p.transaction_number = ? AND r.student_id = ?'
);
$stmt->execute([$ref, $user['id']]);
$p = $stmt->fetch();

if (!$p) {
    flash_set('error', 'Payment session not found.');
    redirect('student/requests.php');
}
if ($p['status'] === 'paid') {
    flash_set('success', 'This payment is already completed.');
    redirect('student/requests.php');
}
if ($p['status'] === 'failed') {
    flash_set('error', 'This payment attempt already failed. Please start a new one.');
    redirect('student/payment.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'pay') {
        $gwRef = 'MOCK-' . strtoupper(bin2hex(random_bytes(6)));
        $upd = db()->prepare(
            'UPDATE payments
                SET status = "paid", gateway_reference = ?
              WHERE id = ? AND status = "pending"'
        );
        $upd->execute([$gwRef, $p['id']]);
        $reqUpd = db()->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status IN ("pending", "approved")');
        $reqUpd->execute([$p['request_id']]);
        flash_set('success', 'Payment successful! Your request is now being processed.');
        redirect('student/requests.php');
    }

    if ($action === 'cancel') {
        $upd = db()->prepare(
            'UPDATE payments
                SET status = "failed", failure_reason = "Cancelled by user"
              WHERE id = ? AND status = "pending"'
        );
        $upd->execute([$p['id']]);
        flash_set('error', 'Payment cancelled.');
        redirect('student/payment.php');
    }
}

$title = 'Online Payment Gateway';
require __DIR__ . '/includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Secure Payment Gateway</h1>
        <p class="muted">Simulated third-party checkout — no real charge is made.</p>
    </div>

    <div class="panel glass">
        <div class="panel-head"><h2>Order summary</h2></div>
        <dl class="kv">
            <dt>Transaction</dt><dd><code><?= e($p['transaction_number']) ?></code></dd>
            <dt>Service</dt><dd><?= e($p['service_name']) ?></dd>
            <dt>Request</dt><dd><code><?= e($p['req_ref']) ?></code></dd>
            <dt>Student</dt><dd><?= e($p['full_name']) ?></dd>
            <dt>Method</dt><dd><?= e(strtoupper($p['method'])) ?></dd>
            <dt>Amount</dt><dd class="money-lg"><?= peso($p['amount']) ?></dd>
        </dl>
    </div>

    <div class="panel glass">
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <p class="muted small">This is a demo gateway. Click below to simulate the customer's choice.</p>
            <div class="panel-actions">
                <button type="submit" name="action" value="pay" class="btn btn-primary">
                    <?= icon('check') ?> Pay <?= peso($p['amount']) ?>
                </button>
                <button type="submit" name="action" value="cancel" class="btn btn-ghost">
                    Cancel payment
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>