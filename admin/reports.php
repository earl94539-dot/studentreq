<?php
/**
 * Payments and receipt reports  (DFD 6.0 -> registrar)
 *
 * Reads verified payments (D:7) together with the receipts issued for them (D:8)
 * and gives the registrar totals plus a CSV export. Registrar only.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_registrar();

$validDate = fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if ($from !== '' && !$validDate($from)) $from = '';
if ($to !== ''   && !$validDate($to))   $to = '';

$sql = 'SELECT p.id, p.method, p.amount, p.transaction_number, p.paid_at,
               rc.receipt_number, rc.issued_at,
               r.ref_no AS req_ref, s.name AS service_name,
               u.full_name, u.student_no
        FROM payments p
        JOIN requests r ON r.id = p.request_id
        JOIN services s ON s.id = r.service_id
        JOIN students u ON u.id = r.student_id
        LEFT JOIN receipts rc ON rc.payment_id = p.id
        WHERE p.status = "paid"';
$params = [];
if ($from !== '') { $sql .= ' AND DATE(p.paid_at) >= ?'; $params[] = $from; }
if ($to !== '')   { $sql .= ' AND DATE(p.paid_at) <= ?'; $params[] = $to; }
$sql .= ' ORDER BY p.paid_at DESC, p.id DESC LIMIT 1000';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$methodNames = [
    'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card',
    'bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'mock' => 'Simulated Gateway',
];

if (($_GET['export'] ?? '') === 'csv') {
    audit_log($admin['id'], 'report_export', 'Exported payments & receipts report' . ($from || $to ? " ($from to $to)" : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-receipts-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
    fputcsv($out, ['Receipt no.', 'Paid at', 'Request', 'Student no.', 'Student', 'Document', 'Method', 'Transaction', 'Amount (PHP)']);
    foreach ($rows as $row) {
        // Prefix cells that start with = + - @ so spreadsheets do not run them as formulas.
        $safe = fn ($v) => (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
        fputcsv($out, [
            $row['receipt_number'] ?: '', $row['paid_at'], $row['req_ref'], $row['student_no'],
            $safe($row['full_name']), $safe($row['service_name']),
            $methodNames[$row['method']] ?? $row['method'], $row['transaction_number'],
            number_format((float) $row['amount'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

$total    = array_sum(array_map(fn ($x) => (float) $x['amount'], $rows));
$byMethod = [];
foreach ($rows as $row) {
    $byMethod[$row['method']] = ($byMethod[$row['method']] ?? 0.0) + (float) $row['amount'];
}
arsort($byMethod);
$noReceipt = count(array_filter($rows, fn ($x) => empty($x['receipt_number'])));
$qs = http_build_query(array_filter(['from' => $from, 'to' => $to]));

$title = 'Payments & receipt reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page">
    <div class="page-head">
        <h1>Payments &amp; receipt reports</h1>
        <p class="muted">Verified payments and the official receipts issued for them.</p>
    </div>

    <form method="get" action="<?= url('admin/reports.php') ?>" class="filter-bar glass">
        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="panel-actions">
            <button class="btn btn-soft btn-sm" type="submit"><?= icon('search') ?> Apply</button>
            <a class="btn btn-ghost btn-sm" href="<?= url('admin/reports.php') ?>">Reset</a>
            <a class="btn btn-primary btn-sm" href="<?= url('admin/reports.php?export=csv' . ($qs ? '&' . $qs : '')) ?>"><?= icon('download') ?> Export CSV</a>
        </div>
    </form>

    <div class="stat-cards">
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('receipt') ?></span>
            <div><div class="num"><?= count($rows) ?></div><div class="lbl">Verified payments</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('money') ?></span>
            <div><div class="num money"><?= peso($total) ?></div><div class="lbl">Total collected</div></div>
        </div>
        <?php foreach ($byMethod as $method => $sum): ?>
            <div class="glass stat-card">
                <span class="stat-icon"><?= icon('wallet') ?></span>
                <div><div class="num money" style="font-size:1.2rem"><?= peso($sum) ?></div><div class="lbl"><?= e($methodNames[$method] ?? ucfirst($method)) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($noReceipt > 0): ?>
        <div class="alert alert-error glass" role="alert">
            <?= icon('alert') ?><span><?= $noReceipt ?> verified payment<?= $noReceipt === 1 ? ' has' : 's have' ?> no receipt on record. Check that the database triggers from database.sql are installed.</span>
        </div>
    <?php endif; ?>

    <div class="panel glass">
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Receipt no.</th><th>Paid</th><th>Student</th><th>Request</th><th>Document</th><th>Method</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><code><?= e($row['receipt_number'] ?: '—') ?></code></td>
                                <td class="muted small"><?= nice_date($row['paid_at']) ?></td>
                                <td><?= e($row['full_name']) ?><span class="small dim"><?= e($row['student_no']) ?></span></td>
                                <td class="small"><?= e($row['req_ref']) ?></td>
                                <td class="small"><?= e($row['service_name']) ?></td>
                                <td><span class="pill pill-info"><?= e($methodNames[$row['method']] ?? ucfirst($row['method'])) ?></span></td>
                                <td class="money"><?= peso($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No verified payments in this period.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
