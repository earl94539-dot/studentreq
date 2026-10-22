<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$specificRequestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;

if ($specificRequestId) {
    $stmt = db()->prepare(
        'SELECT r.*, s.name AS service_name,
                p.id      AS payment_id,
                p.status  AS payment_status,
                p.transaction_number,
                p.method  AS payment_method,
                p.proof_image,
                p.failure_reason
         FROM requests r
         JOIN services s ON s.id = r.service_id
         LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
         WHERE r.id = ? AND r.student_id = ?'
    );
    $stmt->execute([$specificRequestId, $user['id']]);
    $request = $stmt->fetch();
    if (!$request) {
        flash_set('error', 'Request not found.');
        redirect('student/requests.php');
    }
} else {
    $stmt = db()->prepare(
        'SELECT r.*, s.name AS service_name,
                p.id      AS payment_id,
                p.status  AS payment_status,
                p.transaction_number,
                p.method  AS payment_method,
                p.proof_image
         FROM requests r
         JOIN services s ON s.id = r.service_id
         LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
         WHERE r.student_id = ? AND r.status NOT IN ("released","rejected")
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$user['id']]);
    $requests = $stmt->fetchAll();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'start_payment') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $method    = $_POST['method'] ?? 'gcash';

        if (!in_array($method, ['gcash','maya','card','bank_transfer'], true)) {
            $errors[] = 'Invalid payment method.';
        } else {
            $conn = db();
            try {
                $stmt = $conn->prepare('CALL sp_start_payment(?, ?, ?, @pid, @txn)');
                $stmt->execute([$requestId, $user['id'], $method]);
                $out = $conn->query('SELECT @pid AS pid, @txn AS txn')->fetch();

                if (!empty($out['pid'])) {
                    flash_set('success', 'Payment initiated. Please upload your receipt.');
                    redirect('student/payment.php?request_id=' . $requestId);
                }
                $errors[] = 'Could not start a payment session.';
            } catch (PDOException $e) {
                error_log('Payment session error: ' . $e->getMessage());
                if ($e->getCode() === '45000') {
                    $errors[] = $e->getMessage();
                } else {
                    $errors[] = 'Unable to initiate payment session. Please try again.';
                }
            }
        }
    } elseif ($action === 'upload_receipt') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please select a receipt image to upload.';
        } else {
            // Security: verify this payment belongs to the currently logged-in student
            $chk = db()->prepare(
                'SELECT p.id FROM payments p
                 JOIN requests r ON r.id = p.request_id
                 WHERE p.id = ? AND r.student_id = ? LIMIT 1'
            );
            $chk->execute([$paymentId, $user['id']]);
            if (!$chk->fetch()) {
                flash_set('error', 'Payment not found or does not belong to your account.');
                redirect('student/requests.php');
            }

            $proofPath = save_receipt_upload($_FILES['receipt'], $user['id']);
            if (!$proofPath) {
                $errors[] = 'Invalid image file. Please upload a JPG, PNG, or WebP image (max 4MB).';
            } else {
                // A re-upload after a declined receipt puts the payment back in the cashier's queue.
                $stmt = db()->prepare(
                    'UPDATE payments p JOIN requests r ON r.id = p.request_id
                     SET p.proof_image = ?, p.status = "pending", p.failure_reason = NULL
                     WHERE p.id = ? AND r.student_id = ? AND r.status = "approved" AND p.status IN ("pending","failed")'
                );
                $stmt->execute([$proofPath, $paymentId, $user['id']]);
                if ($stmt->rowCount() === 0) {
                    @unlink(UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($proofPath));
                    flash_set('error', 'This payment can no longer accept a receipt.');
                    redirect('student/requests.php');
                }
                audit_log($user['id'], 'receipt_upload', 'Uploaded receipt for payment #' . $paymentId);
                flash_set('success', 'Receipt uploaded. Waiting for cashier verification.');
                redirect('student/requests.php');
            }
        }
    }
}

$title = 'Upload Payment Receipt';
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Upload Payment Receipt</h1>
        <p class="muted">Pay via GCash, Maya, or Bank Transfer then upload your receipt for verification.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <?php if ($specificRequestId && $request): ?>
        <div class="panel glass">
            <div class="panel-head">
                <h2><?= e($request['service_name']) ?></h2>
                <span class="muted small"><code><?= e($request['ref_no']) ?></code></span>
            </div>
            <dl class="kv">
                <dt>Fee</dt><dd class="money-lg"><?= peso($request['fee']) ?></dd>
                <dt>Status</dt><dd><?= status_pill($request['status']) ?></dd>
            </dl>

            <?php if ($request['payment_status'] === 'paid'): ?>
                <div class="alert alert-ok glass">
                    <?= icon('check') ?><span>Payment verified! Your request is being processed.</span>
                </div>
                <a class="btn btn-primary" href="<?= url('student/requests.php') ?>">View your requests</a>
            <?php elseif ($request['payment_status'] === 'failed'): ?>
                <div class="alert alert-error glass">
                    <?= icon('x') ?><span><strong>Receipt declined:</strong> <?= e($request['failure_reason'] ?? 'Please upload a valid receipt.') ?></span>
                </div>
                <form method="post" enctype="multipart/form-data" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="hidden" name="payment_id" value="<?= (int) $request['payment_id'] ?>">
                    <div class="field">
                        <label>Upload a new receipt</label>
                        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <span class="hint">JPG, PNG, or WebP (max 4MB). Make sure the transaction details are clearly visible.</span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('upload') ?> Upload new receipt</button>
                    <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Cancel</a>
                </form>
            <?php elseif ($request['proof_image']): ?>
                <div class="alert alert-info glass">
                    <?= icon('clock') ?><span>Receipt uploaded. Waiting for cashier verification.</span>
                </div>
                <a class="btn btn-primary" href="<?= url('student/requests.php') ?>">View your requests</a>
            <?php elseif ($request['payment_id']): 
                $method = $request['payment_method'];
                $gcashNum = get_setting('payment_gcash_number', '0917-123-4567');
                $gcashNm  = get_setting('payment_gcash_name', 'Registrar Trust Fund');
                $mayaNum  = get_setting('payment_maya_number', '0918-987-6543');
                $mayaNm   = get_setting('payment_maya_name', 'Registrar Collection');
                $bankNm   = get_setting('payment_bank_name', 'Land Bank of the Philippines');
                $bankAcc  = get_setting('payment_bank_account', '1234-5678-90');
                $bankTit  = get_setting('payment_bank_account_name', 'University Trust Account');
                $payInstr = get_setting('payment_instructions', '');
            ?>
                <div class="panel glass neu-inset" style="padding:1.1rem 1.25rem;margin-bottom:1.5rem;border-radius:12px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);">
                    <div style="font-size:.92rem;font-weight:600;color:var(--accent-0,#38bdf8);margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                        <?= icon('wallet') ?> Official Payment Channel: <?= ucfirst(str_replace('_',' ',$method)) ?>
                    </div>
                    <?php if ($method === 'gcash'): ?>
                        <div style="font-size:1.05rem;">Account Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($gcashNum) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($gcashNm) ?></div>
                    <?php elseif ($method === 'maya'): ?>
                        <div style="font-size:1.05rem;">Account Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($mayaNum) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($mayaNm) ?></div>
                    <?php elseif ($method === 'bank_transfer'): ?>
                        <div style="font-size:1.05rem;">Bank: <strong style="color:var(--ink-0);"><?= e($bankNm) ?></strong></div>
                        <div>Account Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($bankAcc) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($bankTit) ?></div>
                    <?php else: ?>
                        <div>Card or Online Checkout: Complete the transaction and upload your confirmation or receipt.</div>
                    <?php endif; ?>
                    <div class="muted small" style="margin-top:.6rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.06);">
                        Reference to note: <code><?= e($request['ref_no']) ?></code> &bull; Total Amount: <strong><?= peso($request['fee']) ?></strong>
                    </div>
                    <?php if ($payInstr): ?>
                        <div class="muted small" style="margin-top:.4rem;"><?= nl2br(e($payInstr)) ?></div>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="hidden" name="payment_id" value="<?= (int) $request['payment_id'] ?>">
                    <div class="field">
                        <label>Payment method</label>
                        <select disabled>
                            <option value="gcash" <?= $request['payment_method'] === 'gcash' ? 'selected' : '' ?>>GCash</option>
                            <option value="maya" <?= $request['payment_method'] === 'maya' ? 'selected' : '' ?>>Maya</option>
                            <option value="card" <?= $request['payment_method'] === 'card' ? 'selected' : '' ?>>Debit / Credit Card</option>
                            <option value="bank_transfer" <?= $request['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Upload receipt</label>
                        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <span class="hint">JPG, PNG, or WebP (max 4MB). Make sure the transaction details are clearly visible.</span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('upload') ?> Upload receipt</button>
                    <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Cancel</a>
                </form>
            <?php else: ?>
                <form method="post" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="start_payment">
                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                    <div class="field">
                        <label>Payment method</label>
                        <select name="method" required>
                            <option value="gcash">GCash</option>
                            <option value="maya">Maya</option>
                            <option value="card">Debit / Credit Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('wallet') ?> Continue to upload receipt</button>
                    <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif (!$requests): ?>
        <div class="panel glass empty-note">
            You have no outstanding payments.<br><br>
            <a class="btn btn-primary" href="<?= url('student/services.php') ?>"><?= icon('plus') ?> Request a document first</a>
        </div>
    <?php else: ?>
        <div class="stack">
        <?php foreach ($requests as $r): ?>
            <div class="panel glass">
                <div class="panel-head">
                    <div>
                        <h2><?= e($r['service_name']) ?></h2>
                        <span class="muted small"><code><?= e($r['ref_no']) ?></code> · <?= nice_date($r['created_at']) ?></span>
                    </div>
                    <div class="panel-actions">
                        <?= status_pill($r['status']) ?>
                        <span class="money"><?= peso($r['fee']) ?></span>
                    </div>
                </div>

                <?php if ($r['payment_status'] === 'paid'): ?>
                    <p class="muted small">Payment completed.
                        <a href="<?= url('student/receipt.php?id=' . (int) $r['id']) ?>">View official receipt →</a>
                    </p>
                <?php elseif ($r['status'] === 'pending'): ?>
                    <p class="muted small">Waiting for the registrar to verify your request. You can pay once it is approved.</p>
                <?php elseif ($r['status'] === 'approved'): ?>
                    <?php if ($r['payment_status'] === 'failed'): ?>
                        <a class="btn btn-primary" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                            <?= icon('upload') ?> Upload a new receipt
                        </a>
                    <?php elseif ($r['proof_image']): ?>
                        <div class="alert alert-info glass">
                            <?= icon('clock') ?><span>Receipt uploaded. Waiting for cashier verification.</span>
                        </div>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                            <?= icon('upload') ?> Upload payment receipt
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted small">No action needed — this request is already being processed.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>