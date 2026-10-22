<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$stats = [
    'students'        => (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'pending'         => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "pending"')->fetchColumn(),
    'approved'        => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "approved"')->fetchColumn(),
    'rejected'        => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "rejected" AND (rejected_by = "cashier" OR rejected_by IS NULL)')->fetchColumn(),
    'processing'      => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "processing"')->fetchColumn(),
    'ready'           => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "ready"')->fetchColumn(),
    'revenue'         => (float) db()->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "paid"')->fetchColumn(),
];

// Cashier: separate pending vs approved vs rejected pipelines
$pendingPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status = "pending"
     ORDER BY r.created_at DESC
     LIMIT 12'
)->fetchAll();

$approvedPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status IN ("approved", "processing", "ready", "released")
     ORDER BY r.updated_at DESC
     LIMIT 12'
)->fetchAll();

$recentPayments = db()->query(
    'SELECT p.*, u.full_name, r.ref_no AS req_ref
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     JOIN students u ON u.id = r.student_id
     WHERE p.status = "paid"
     ORDER BY p.paid_at DESC, p.created_at DESC
     LIMIT 10'
)->fetchAll();

$rejectedPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status = "rejected" AND (r.rejected_by = "cashier" OR r.rejected_by IS NULL)
     ORDER BY r.updated_at DESC
     LIMIT 12'
)->fetchAll();

// Registrar queries
$regPendingPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status IN ("approved", "processing")
     ORDER BY r.updated_at DESC
     LIMIT 12'
)->fetchAll();

$regReadyPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status = "ready"
     ORDER BY r.updated_at DESC
     LIMIT 12'
)->fetchAll();

$regRejectedPipeline = db()->query(
    'SELECT r.*, s.name AS service_name, s.processing_days, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.status = "rejected" AND r.rejected_by = "registrar"
     ORDER BY r.updated_at DESC
     LIMIT 12'
)->fetchAll();

$regPendingCount  = (int) db()->query('SELECT COUNT(*) FROM requests WHERE status IN ("approved", "processing")')->fetchColumn();
$regReadyCount    = (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "ready"')->fetchColumn();
$regRejectedCount = (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "rejected" AND rejected_by = "registrar"')->fetchColumn();

$isCashierView = $admin['role'] === 'cashier';

// ── CASHIER VIEW ─────────────────────────────────────────────────────────────
if ($isCashierView): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Cashier Overview · <?= e(APP_NAME) ?></title>
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
    .cashier-body {
        padding-top: 92px;
        min-height: 100vh;
    }
    /* Approved pipeline accent */
    .pipeline-approved .panel-head h2 {
        color: #86efac;
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
<div class="page">
    <div class="page-head">
        <h1>Cashier Overview</h1>
    </div>

    <?php $flash = flash_take(); if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> glass" style="margin-bottom:20px" role="status">
        <?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?>
        <span><?= e($flash['message']) ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- ── Stat cards ─────────────────────────────────────────────────────── -->
    <div class="stat-cards">

        <!-- 1. Awaiting verification (pending) -->
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('clock') ?></span>
            <div>
                <div class="num"><?= $stats['pending'] ?></div>
                <div class="lbl">Awaiting verification</div>
            </div>
        </div>

        <!-- 2. Approved — Sent to Registrar -->
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('check') ?></span>
            <div>
                <div class="num"><?= $stats['approved'] ?></div>
                <div class="lbl">Approved — Sent to Registrar</div>
            </div>
        </div>

        <!-- 3. Rejected -->
        <div class="glass stat-card">
            <span class="stat-icon" style="color:#fca5a5"><?= icon('x') ?></span>
            <div>
                <div class="num" style="color:#fca5a5"><?= $stats['rejected'] ?></div>
                <div class="lbl">Rejected</div>
            </div>
        </div>

        <!-- 4. Collected payments -->
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('money') ?></span>
            <div>
                <div class="num money"><?= peso($stats['revenue']) ?></div>
                <div class="lbl">Collected payments</div>
            </div>
        </div>

    </div>

    <!-- ── Pending Pipeline (cashier's inbox) ─────────────────────────────── -->
    <div class="panel glass">
        <div class="panel-head">
            <h2><?= icon('clock') ?> Pending — Awaiting Cashier Approval</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/requests.php?status=pending') ?>">View all</a>
        </div>
        <?php if ($pendingPipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Date Requested</th>
                            <th>Expected Release</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingPipeline as $r):
                            $createdTs  = strtotime($r['created_at']);
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
                                <td class="muted small"><?= date('M j, Y', $createdTs) ?></td>
                                <td class="small"><?= date('M j, Y', $expectedTs) ?></td>
                                <td style="white-space:nowrap">
                                    <a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int)$r['id']) ?>"><?= icon('eye') ?> Review</a>
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cashier_approve">
                                        <button class="btn btn-ok btn-sm" type="submit"
                                                data-confirm="Approve this request and forward it to the Registrar?"
                                                data-confirm-yes="Yes, approve"
                                                data-confirm-no="Cancel">
                                            <?= icon('check') ?> Approve
                                        </button>
                                    </form>
                                    <button class="btn btn-danger btn-sm" type="button"
                                            onclick="openRejectModal(<?= (int)$r['id'] ?>, 'cashier', '<?= e($r['ref_no']) ?>')">
                                        <?= icon('x') ?> Reject
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No pending requests. All clear!</div>
        <?php endif; ?>
    </div>

    <!-- ── Approved Pipeline (forwarded to Registrar) ─────────────────────── -->
    <div class="panel glass pipeline-approved">
        <div class="panel-head">
            <h2><?= icon('doc') ?> Approved — Sent to Registrar</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/requests.php?status=approved') ?>">View all</a>
        </div>
        <?php if ($approvedPipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Approved On</th>
                            <th>Expected Release</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvedPipeline as $r):
                            $approvedTs = strtotime($r['updated_at']);
                            $expectedTs = strtotime($r['created_at']) + ((int)$r['processing_days'] * 86400);
                        ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td class="muted small"><?= date('M j, Y', $approvedTs) ?></td>
                                <td class="small"><?= date('M j, Y', $expectedTs) ?></td>
                                <td>
                                    <a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int)$r['id']) ?>"><?= icon('eye') ?> View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No approved requests yet. Approved requests will appear here after cashier approval.</div>
        <?php endif; ?>
    </div>

    <!-- ── Rejected Pipeline ─────────────────────────────────────────────── -->
    <div class="panel glass pipeline-rejected">
        <div class="panel-head">
            <h2 style="color:#fca5a5"><?= icon('x') ?> Rejected Requests</h2>
            <span class="small muted"><?= count($rejectedPipeline) ?> request<?= count($rejectedPipeline) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($rejectedPipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Rejected On</th>
                            <th>Reason / Note</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rejectedPipeline as $r):
                            $rejectedTs = strtotime($r['updated_at']);
                        ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td class="muted small"><?= date('M j, Y g:i a', $rejectedTs) ?></td>
                                <td class="small muted"><?= e($r['admin_note'] ?: 'Declined by cashier') ?></td>
                                <td style="white-space:nowrap">
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="restore_request">
                                        <button class="btn btn-soft btn-sm" type="submit"
                                                data-confirm="Restore this request back to pending status?"
                                                data-confirm-yes="Yes, restore"
                                                data-confirm-no="Cancel">
                                            <?= icon('trend') ?> Restore
                                        </button>
                                    </form>
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_request">
                                        <button class="btn btn-danger btn-sm" type="submit"
                                                data-confirm="Permanently delete request <?= e($r['ref_no']) ?>? This action cannot be undone."
                                                data-confirm-yes="Yes, permanently delete"
                                                data-confirm-no="Cancel">
                                            <?= icon('trash') ?> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No rejected requests.</div>
        <?php endif; ?>
    </div>

    <!-- ── Collected Payments ─────────────────────────────────────────────── -->
    <div class="panel glass">
        <div class="panel-head">
            <h2>Collected Payments</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/payments.php') ?>">All payments</a>
        </div>
        <?php if ($recentPayments): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>For request</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPayments as $p): ?>
                            <tr>
                                <td><code><?= e($p['ref_no'] ?? $p['transaction_number']) ?></code></td>
                                <td><?= e($p['full_name']) ?></td>
                                <td class="small"><?= e($p['req_ref'] ?? 'N/A') ?></td>
                                <td class="muted"><?= e(ucfirst($p['method'])) ?></td>
                                <td class="money"><?= peso($p['amount']) ?></td>
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
</main>

<script src="<?= url('assets/glass.js') ?>" defer></script>
</body>
</html>

<?php else:
// ── REGISTRAR VIEW ───────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Registrar Overview · <?= e(APP_NAME) ?></title>
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
    .cashier-body {
        padding-top: 92px;
        min-height: 100vh;
    }
    .pipeline-ready .panel-head h2 {
        color: #86efac;
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
            <span class="cashier-tag">Registrar Portal</span>
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
        <h1>Registrar Overview</h1>
    </div>

    <?php $flash = flash_take(); if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> glass" style="margin-bottom:20px" role="status">
        <?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?>
        <span><?= e($flash['message']) ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- ── Stat cards ─────────────────────────────────────────────────────── -->
    <div class="stat-cards">

        <!-- 1. Requests Pending -->
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('clock') ?></span>
            <div>
                <div class="num"><?= $regPendingCount ?></div>
                <div class="lbl">Requests Pending</div>
            </div>
        </div>

        <!-- 2. Ready to be get by the user -->
        <div class="glass stat-card">
            <span class="stat-icon" style="color:#86efac"><?= icon('check') ?></span>
            <div>
                <div class="num" style="color:#86efac"><?= $regReadyCount ?></div>
                <div class="lbl">Ready to be get by the user</div>
            </div>
        </div>

        <!-- 3. Rejected -->
        <div class="glass stat-card">
            <span class="stat-icon" style="color:#fca5a5"><?= icon('x') ?></span>
            <div>
                <div class="num" style="color:#fca5a5"><?= $regRejectedCount ?></div>
                <div class="lbl">Rejected</div>
            </div>
        </div>

    </div>

    <!-- ── 1. Requests Pending Pipeline ─────────────────────────────────── -->
    <div class="panel glass">
        <div class="panel-head">
            <h2><?= icon('clock') ?> Requests Pending — Ready to Process</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/requests.php?status=approved') ?>">View all</a>
        </div>
        <?php if ($regPendingPipeline): ?>
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
                        <?php foreach ($regPendingPipeline as $r):
                            $createdTs  = strtotime($r['created_at']);
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
                                    <?php if ($r['status'] === 'approved'): ?>
                                        <span class="pill pill-info"><?= icon('clock') ?> Verified</span>
                                    <?php elseif ($r['status'] === 'processing'): ?>
                                        <span class="pill pill-warn"><?= icon('settings') ?> Processing</span>
                                    <?php else: ?>
                                        <?= status_pill($r['status']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= date('M j, Y', $createdTs) ?></td>
                                <td class="small"><?= date('M j, Y', $expectedTs) ?></td>
                                <td style="white-space:nowrap">
                                    <a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int)$r['id']) ?>"><?= icon('eye') ?> Review</a>
                                    <?php if ($r['status'] === 'approved'): ?>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="registrar_process">
                                            <button class="btn btn-soft btn-sm" type="submit"
                                                    data-confirm="Start processing document for <?= e($r['full_name']) ?>?"
                                                    data-confirm-yes="Yes, start processing"
                                                    data-confirm-no="Cancel">
                                                <?= icon('settings') ?> Process
                                            </button>
                                        </form>
                                    <?php elseif ($r['status'] === 'processing'): ?>
                                        <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="registrar_ready">
                                            <button class="btn btn-ok btn-sm" type="submit"
                                                    data-confirm="Mark this document as Ready for student pickup?"
                                                    data-confirm-yes="Yes, mark ready"
                                                    data-confirm-no="Cancel">
                                                <?= icon('check') ?> Mark Ready
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm" type="button"
                                            onclick="openRejectModal(<?= (int)$r['id'] ?>, 'registrar', '<?= e($r['ref_no']) ?>')">
                                        <?= icon('x') ?> Reject
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No pending requests waiting for processing.</div>
        <?php endif; ?>
    </div>

    <!-- ── 2. Ready to be get by user Pipeline ───────────────────────────── -->
    <div class="panel glass pipeline-ready">
        <div class="panel-head">
            <h2><?= icon('check') ?> Ready to be get by the user</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/requests.php?status=ready') ?>">View all</a>
        </div>
        <?php if ($regReadyPipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Ready Since</th>
                            <th>Expected Release</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regReadyPipeline as $r):
                            $readyTs    = strtotime($r['updated_at']);
                            $expectedTs = strtotime($r['created_at']) + ((int)$r['processing_days'] * 86400);
                        ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td class="muted small"><?= date('M j, Y g:i a', $readyTs) ?></td>
                                <td class="small"><?= date('M j, Y', $expectedTs) ?></td>
                                <td style="white-space:nowrap">
                                    <a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int)$r['id']) ?>"><?= icon('eye') ?> Review</a>
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="registrar_release">
                                        <button class="btn btn-primary btn-sm" type="submit"
                                                data-confirm="Mark this document as officially Released / Given to student?"
                                                data-confirm-yes="Yes, release"
                                                data-confirm-no="Cancel">
                                            <?= icon('check') ?> Release
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No documents currently ready for pickup.</div>
        <?php endif; ?>
    </div>

    <!-- ── 3. Rejected Pipeline ─────────────────────────────────────────── -->
    <div class="panel glass pipeline-rejected">
        <div class="panel-head">
            <h2 style="color:#fca5a5"><?= icon('x') ?> Rejected Requests</h2>
            <span class="small muted"><?= count($regRejectedPipeline) ?> request<?= count($regRejectedPipeline) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($regRejectedPipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Fee</th>
                            <th>Rejected On</th>
                            <th>Reason / Note</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regRejectedPipeline as $r):
                            $rejectedTs = strtotime($r['updated_at']);
                        ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td class="muted small"><?= date('M j, Y g:i a', $rejectedTs) ?></td>
                                <td class="small muted"><?= e($r['admin_note'] ?: 'Declined') ?></td>
                                <td style="white-space:nowrap">
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="restore_request">
                                        <button class="btn btn-soft btn-sm" type="submit"
                                                data-confirm="Restore this request back to pending status?"
                                                data-confirm-yes="Yes, restore"
                                                data-confirm-no="Cancel">
                                            <?= icon('trend') ?> Restore
                                        </button>
                                    </form>
                                    <form method="post" action="<?= url('admin/process.php?id=' . (int)$r['id']) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_request">
                                        <button class="btn btn-danger btn-sm" type="submit"
                                                data-confirm="Permanently delete request <?= e($r['ref_no']) ?>? This action cannot be undone."
                                                data-confirm-yes="Yes, permanently delete"
                                                data-confirm-no="Cancel">
                                            <?= icon('trash') ?> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No rejected requests.</div>
        <?php endif; ?>
    </div>

</div>
</main>

<!-- Rejection Reason Modal -->
<div id="rejectModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:rgba(30,41,59,0.95);border:1px solid rgba(255,255,255,0.2);border-radius:16px;padding:32px;max-width:500px;width:90%;box-shadow:0 25px 50px rgba(0,0,0,0.5);">
        <h2 style="color:#fca5a5;margin:0 0 16px 0;"><?= icon('x') ?> Reject Request</h2>
        <p style="color:rgba(255,255,255,0.7);margin:0 0 20px 0;">Please provide a reason for rejecting this request:</p>
        <form method="post" id="rejectForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="rejectAction" value="">
            <input type="hidden" name="request_id" id="rejectRequestId" value="">
            <div style="margin-bottom:20px;">
                <label style="display:block;margin-bottom:8px;color:rgba(255,255,255,0.9);">Reference: <span id="rejectRefNo" style="color:#86efac;"></span></label>
                <textarea name="admin_note" id="rejectReason" rows="4" required
                    style="width:100%;padding:12px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:#ffffff;font-size:14px;resize:vertical;"
                    placeholder="Enter the reason for rejection..."></textarea>
            </div>
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <button type="button" onclick="closeRejectModal()"
                    style="padding:10px 20px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:#ffffff;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                    style="padding:10px 20px;background:#ef4444;border:none;border-radius:8px;color:#ffffff;cursor:pointer;font-weight:600;">
                    Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(requestId, role, refNo) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectAction').value = role + '_reject';
    document.getElementById('rejectRefNo').textContent = refNo;
    document.getElementById('rejectForm').action = '<?= url('admin/process.php') ?>?id=' + requestId;
    document.getElementById('rejectModal').style.display = 'flex';
    document.getElementById('rejectReason').focus();
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.getElementById('rejectReason').value = '';
}

// Close modal when clicking outside
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRejectModal();
    }
});
</script>

<script src="<?= url('assets/glass.js') ?>" defer></script>
</body>
</html>
<?php endif; ?>
