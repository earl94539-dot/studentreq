<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$isCashier   = $admin['role'] === 'cashier';
$isRegistrar = $admin['role'] === 'registrar';

$filter = $_GET['status'] ?? 'all';

// Cashier sees pending, approved, and rejected; registrar sees approved and beyond
if ($isCashier) {
    $valid = ['all', 'pending', 'approved', 'rejected'];
} else {
    $valid = ['all', 'approved', 'processing', 'ready', 'released', 'rejected'];
}
if (!in_array($filter, $valid, true)) {
    $filter = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no,
            p.status AS pay_status
        FROM requests r
        JOIN services s ON s.id = r.service_id
        JOIN students u ON u.id = r.student_id
        LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
        WHERE 1 = 1';
$params = [];

if ($isCashier) {
    // Cashier manages the initial intake: pending, approved, and rejected
    $sql .= ' AND r.status IN ("pending", "approved", "rejected")';
} else {
    // Registrar only sees requests the cashier has already approved
    $sql .= ' AND r.status IN ("approved", "processing", "ready", "released", "rejected")';
}

if ($filter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $sql .= ' AND (r.ref_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ? OR s.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY FIELD(r.status, "pending", "approved", "processing", "ready", "released", "rejected"), r.created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$countBy = [];
foreach (db()->query('SELECT status, COUNT(*) AS c FROM requests GROUP BY status') as $row) {
    $countBy[$row['status']] = (int) $row['c'];
}

// ── CASHIER: render its own layout (no shared navbar) ────────────────────────
if ($isCashier):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Student Requests · <?= e(APP_NAME) ?></title>
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
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 8px;
        color: rgba(255,255,255,0.85);
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: background 0.2s, border-color 0.2s;
    }
    .cashier-topbar .logout-btn:hover {
        background: rgba(239,68,68,0.25);
        border-color: rgba(239,68,68,0.5);
        color: #fca5a5;
    }
    .cashier-body { padding-top: 92px; min-height: 100vh; }
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
<div class="page">
    <div class="page-head">
        <h1>Student requests</h1>
    </div>

    <?php $flash = flash_take(); if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> glass" style="margin-bottom:20px" role="status">
        <?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?>
        <span><?= e($flash['message']) ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <div class="filter-bar glass">
        <div class="filter-tabs">
            <a class="ftab <?= $filter === 'all' ? 'active' : '' ?>" href="<?= url('admin/requests.php') ?>">
                All <span class="ftab-count"><?= ($countBy['pending'] ?? 0) + ($countBy['approved'] ?? 0) + ($countBy['rejected'] ?? 0) ?></span>
            </a>
            <a class="ftab <?= $filter === 'pending' ? 'active' : '' ?>"
               href="<?= url('admin/requests.php?status=pending' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                Awaiting approval <span class="ftab-count"><?= $countBy['pending'] ?? 0 ?></span>
            </a>
            <a class="ftab <?= $filter === 'approved' ? 'active' : '' ?>"
               href="<?= url('admin/requests.php?status=approved' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                Sent to Registrar <span class="ftab-count"><?= $countBy['approved'] ?? 0 ?></span>
            </a>
            <a class="ftab <?= $filter === 'rejected' ? 'active' : '' ?>"
               href="<?= url('admin/requests.php?status=rejected' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                Rejected <span class="ftab-count"><?= $countBy['rejected'] ?? 0 ?></span>
            </a>
        </div>
        <form method="get" action="<?= url('admin/requests.php') ?>" class="filter-search">
            <input type="hidden" name="status" value="<?= e($filter) ?>">
            <div class="input-icon">
                <?= icon('search') ?>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search ref no, student, document…">
            </div>
            <button class="btn btn-soft btn-sm" type="submit">Search</button>
        </form>
    </div>

    <div class="panel glass">
        <?php if ($requests): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Status</th>
                            <th>Date Requested</th>
                            <th>Expected Release</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <?php
                            $createdTs = strtotime($r['created_at']);
                            $expectedTs = $createdTs + ((int)$r['processing_days'] * 86400);
                            ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <?= status_pill('pending', 'Awaiting approval') ?>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <?= status_pill('approved', 'Sent to Registrar') ?>
                                    <?php elseif ($r['status'] === 'rejected'): ?>
                                        <?= status_pill('rejected', 'Rejected') ?>
                                    <?php else: ?>
                                        <?= status_pill($r['status']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= date('M j, Y', $createdTs) ?></td>
                                <td class="small"><?= date('M j, Y', $expectedTs) ?></td>
                                <td style="white-space:nowrap">
                                    <a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $r['id']) ?>"><?= icon('eye') ?> Review</a>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int) $r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="cashier_approve">
                                            <button class="btn btn-ok btn-sm" type="submit"
                                                    data-confirm="Approve this request and forward it to the Registrar?"
                                                    data-confirm-yes="Yes, approve"
                                                    data-confirm-no="Cancel">
                                                <?= icon('check') ?> Approve
                                            </button>
                                        </form>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int) $r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="cashier_reject">
                                            <button class="btn btn-danger btn-sm" type="submit"
                                                    data-confirm="Are you sure you want to reject this request?"
                                                    data-confirm-yes="Yes, reject"
                                                    data-confirm-no="Cancel">
                                                <?= icon('x') ?> Reject
                                            </button>
                                        </form>
                                    <?php elseif ($r['status'] === 'rejected'): ?>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int) $r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="restore_request">
                                            <button class="btn btn-soft btn-sm" type="submit"
                                                    data-confirm="Restore this request back to pending status?"
                                                    data-confirm-yes="Yes, restore"
                                                    data-confirm-no="Cancel">
                                                <?= icon('trend') ?> Restore
                                            </button>
                                        </form>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int) $r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_request">
                                            <button class="btn btn-danger btn-sm" type="submit"
                                                    data-confirm="Permanently delete request <?= e($r['ref_no']) ?>? This action cannot be undone."
                                                    data-confirm-yes="Yes, delete permanently"
                                                    data-confirm-no="Cancel">
                                                <?= icon('trash') ?> Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No requests match this view.</div>
        <?php endif; ?>
    </div>
</div>
</main>

<script src="<?= url('assets/glass.js') ?>" defer></script>
</body>
</html>

<?php else:
// ── REGISTRAR VIEW (unchanged) ───────────────────────────────────────────────
$title = 'Requests';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Student requests</h1>
        <p class="muted">Review, verify and move requests through the pipeline.</p>
    </div>

    <div class="filter-bar glass">
        <div class="filter-tabs">
            <a class="ftab <?= $filter === 'all' ? 'active' : '' ?>" href="<?= url('admin/requests.php') ?>">
                All <span class="ftab-count"><?= ($countBy['approved'] ?? 0) + ($countBy['processing'] ?? 0) + ($countBy['ready'] ?? 0) + ($countBy['released'] ?? 0) + ($countBy['rejected'] ?? 0) ?></span>
            </a>
            <?php
            // Registrar only sees cashier-approved requests and beyond — never raw 'pending'
            $statusTabs = ['approved', 'processing', 'ready', 'released', 'rejected'];
            foreach ($statusTabs as $st):
                $tabLabel = ucfirst($st);
                if ($st === 'approved') $tabLabel = 'Cashier Approved';
            ?>
                <a class="ftab <?= $filter === $st ? 'active' : '' ?>"
                   href="<?= url('admin/requests.php?status=' . $st . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                    <?= $tabLabel ?> <span class="ftab-count"><?= $countBy[$st] ?? 0 ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="<?= url('admin/requests.php') ?>" class="filter-search">
            <input type="hidden" name="status" value="<?= e($filter) ?>">
            <div class="input-icon">
                <?= icon('search') ?>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search ref no, student, document…">
            </div>
            <button class="btn btn-soft btn-sm" type="submit">Search</button>
        </form>
    </div>

    <div class="panel glass">
        <?php if ($requests): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Reference</th><th>Student</th><th>Document</th><th>Fee</th><th>Status</th><th>Requested</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <?= status_pill('pending', 'Pending') ?>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <?php if ($r['pay_status'] === null): ?>
                                            <?= status_pill('approved', 'Awaiting payment') ?>
                                        <?php elseif ($r['pay_status'] === 'paid'): ?>
                                            <?= status_pill('approved', 'Paid') ?>
                                        <?php elseif ($r['pay_status'] === 'failed'): ?>
                                            <?= status_pill('rejected', 'Declined') ?>
                                        <?php else: ?>
                                            <?= status_pill('approved', 'Awaiting payment') ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= status_pill($r['status']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= nice_date($r['created_at']) ?></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $r['id']) ?>"><?= icon('eye') ?> Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No requests match this view.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
