<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$page  = max(1, (int) ($_GET['page'] ?? 1));
$per   = 25;
$total = (int) db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$stmt = db()->prepare(
    'SELECT a.*,
            COALESCE(st.full_name, c.full_name, r.full_name, "System") AS actor_name,
            a.actor_type AS actor_role
     FROM audit_logs a
     LEFT JOIN students   st ON a.actor_type = "student"   AND a.student_id = st.id
     LEFT JOIN cashiers   c  ON a.actor_type = "cashier"   AND a.cashier_id = c.id
     LEFT JOIN registrars r  ON a.actor_type = "registrar" AND a.registrar_id = r.id
     ORDER BY a.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $per, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$title = 'Audit log';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Audit log</h1>
        <p class="muted">Every administrative action is recorded for accountability.</p>
    </div>

    <div class="panel glass">
        <?php if ($logs): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="muted small" style="white-space:nowrap"><?= nice_date($log['created_at']) ?></td>
                                <td>
                                    <?= e($log['actor_name'] ?? 'System') ?>
                                    <span class="small dim"><?= e($log['actor_role'] ?? '') ?></span>
                                </td>
                                <td><span class="pill pill-info"><?= e(str_replace('_', ' ', $log['action'])) ?></span></td>
                                <td class="small"><?= e($log['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <div class="pager">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/audit.php?page=' . ($page - 1)) ?>">← Newer</a>
                    <?php endif; ?>
                    <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/audit.php?page=' . ($page + 1)) ?>">Older →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-note">No audit entries yet — actions will appear here as they happen.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
