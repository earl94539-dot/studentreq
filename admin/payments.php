<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_cashier();   // 4.1 payment transaction ledger (registrars use admin/reports.php)

$payments = db()->query(
    'SELECT p.*, p.transaction_number AS ref_no, r.ref_no AS req_ref, r.status AS req_status,
            u.full_name, u.student_no, s.name AS service_name
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     JOIN students u ON u.id = r.student_id
     JOIN services s ON s.id = r.service_id
     ORDER BY p.created_at DESC
     LIMIT 300'
)->fetchAll();

$paidRows = array_filter($payments, fn ($p) => $p['status'] === 'paid');
$totals = [
    'count'  => count($payments),
    'amount' => array_sum(array_map(fn ($p) => (float) $p['amount'], $paidRows)),
];
$byMethod = ['gcash' => 0.0, 'maya' => 0.0, 'card' => 0.0, 'bank_transfer' => 0.0, 'cash' => 0.0, 'mock' => 0.0];
foreach ($paidRows as $p) {
    if (isset($byMethod[$p['method']])) {
        $byMethod[$p['method']] += (float) $p['amount'];
    }
}
$payMethodNames = [
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'cash'          => 'Cash',
    'mock'          => 'Simulated Gateway',
];

$title = 'Payments';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Payments ledger</h1>
        <p class="muted">Every payment students recorded, newest first. Open a request to verify or decline its receipt.</p>
    </div>

    <div class="stat-cards">
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('receipt') ?></span>
            <div><div class="num"><?= $totals['count'] ?></div><div class="lbl">Payment records</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('money') ?></span>
            <div><div class="num money"><?= peso($totals['amount']) ?></div><div class="lbl">Total verified</div></div>
        </div>
        <?php foreach ($byMethod as $method => $sum): ?>
            <div class="glass stat-card">
                <span class="stat-icon"><?= icon('wallet') ?></span>
                <div><div class="num money" style="font-size:1.2rem"><?= peso($sum) ?></div><div class="lbl"><?= e($payMethodNames[$method] ?? ucfirst($method)) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="panel glass">
        <?php if ($payments): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Receipt ref</th><th>Student</th><th>Request</th><th>Document</th><th>Method</th><th>Amount</th><th>Status</th><th>Proof</th><th>Recorded</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><code><?= e($p['ref_no']) ?></code></td>
                                <td><?= e($p['full_name']) ?><span class="small dim"><?= e($p['student_no']) ?></span></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $p['request_id']) ?>"><?= e($p['req_ref']) ?></a></td>
                                <td class="small"><?= e($p['service_name']) ?></td>
                                <td><span class="pill pill-info"><?= e($payMethodNames[$p['method']] ?? ucfirst($p['method'])) ?></span></td>
                                <td class="money"><?= peso($p['amount']) ?></td>
                                <td><span class="pill <?= $p['status'] === 'paid' ? 'pill-ok' : ($p['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>"><?= ucfirst((string) $p['status']) ?></span></td>
                                <td>
                                    <?php if (!empty($p['proof_image'])): ?>
                                        <a class="btn btn-ghost btn-sm" href="<?= url(e($p['proof_image'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> View</a>
                                    <?php else: ?>
                                        <span class="dim small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= nice_date($p['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No payments recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
