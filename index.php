<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

function redirect_simple(string $path): never
{
    $base = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') . '/' : '/lendb/student-e-request-v/';
    $url = (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) ? $path : $base . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

function url_simple(string $path = ''): string
{
    $base = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') . '/' : '/lendb/student-e-request-v/';
    if ($path === '' || $path[0] === '#') {
        return $base;
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) {
        return $path;
    }
    return $base . ltrim($path, '/');
}

function current_user_no_header(): ?array
{
    $uid  = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['user_type'] ?? $_SESSION['role'] ?? '');

    if ($uid <= 0 || !in_array($role, ['student', 'registrar', 'cashier'], true)) {
        return null;
    }

    $map = [
        'student'   => ['table' => 'students',   'id_col' => 'student_no'],
        'registrar' => ['table' => 'registrars', 'id_col' => 'employee_no'],
        'cashier'   => ['table' => 'cashiers',   'id_col' => 'employee_no'],
    ];

    if (!isset($map[$role])) {
        return null;
    }

    $table = $map[$role]['table'];
    $idCol = $map[$role]['id_col'];

    $sql = $role === 'student'
        ? "SELECT id, student_no, full_name, email, course, year_level, created_at FROM students WHERE id = ?"
        : "SELECT id, employee_no AS student_no, full_name, email, created_at FROM $table WHERE id = ?";

    $stmt = db()->prepare($sql);
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if ($user) {
        $user['role'] = $role;
    }
    return $user ?: null;
}

// Don't redirect - landing page should be accessible to everyone
$user = current_user_no_header();

$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY fee, name')->fetchAll();
$services_subtitle = get_setting('services_subtitle', 'Official registrar documents with fixed, transparent fees.');
$stats = [
    'services' => count($services),
    'requests' => (int) db()->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
    'revenue'  => (float) db()->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "paid"')->fetchColumn(),
];

$title = 'Home';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= url_simple('assets/style.css') ?>">
<link rel="stylesheet" href="<?= url_simple('assets/glass.css') ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="#ffffff"/><path d="M6 13.5 11 18l7-11" stroke="#0a0a0a" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<style>
    body.mode-public {
        background: transparent !important;
    }
    body.mode-public::before {
        display: none !important;
    }
    body.mode-public::after {
        display: none !important;
    }
    .background-image {
        position: fixed;
        inset: 0;
        background-image: url('http://localhost/lendb/student-e-request-v/assets/background.png');
        background-size: cover;
        background-position: center -300px;
        background-repeat: no-repeat;
        z-index: -4;
    }
    .blue-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 10, 40, 0.95);
        z-index: -3;
        pointer-events: none;
    }
    .backdrop {
        z-index: -2;
    }
    .school-title {
        font-size: clamp(2.5rem, 5vw, 3.5rem);
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 12px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        white-space: nowrap;
        text-align: left;
        margin-left: -20%;
        font-family: 'Fraunces', Georgia, serif;
    }
    .hero h1 {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }
    .signin-button {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 100;
    }
    .hero-inner {
        text-align: center !important;
        margin: 0 auto !important;
    }
    .section {
        text-align: center !important;
    }
    .section-head {
        text-align: center !important;
    }
    .card-grid {
        margin: 0 auto !important;
        max-width: 1200px !important;
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)) !important;
        gap: 18px !important;
        justify-content: center !important;
    }
    .container {
        text-align: center !important;
        max-width: 1240px !important;
        margin: 0 auto !important;
        padding: 0 20px !important;
    }
</style>
</head>
<body class="mode-public">

<div class="background-image" aria-hidden="true"></div>
<div class="blue-overlay" aria-hidden="true"></div>
<div class="backdrop" aria-hidden="true">
    <div class="orb orb-a"></div><div class="orb orb-b"></div>
    <div class="orb orb-center"></div>
    <div class="orb orb-c"></div><div class="orb orb-d"></div>
    <div class="grid-overlay"></div>
</div>

<?php if ($flash): ?>
<div class="container" style="text-align: center; margin: 20px auto;">
    <div class="alert alert-<?= e($flash['type']) ?> glass" role="status" style="border-radius: var(--r-3); padding: 12px 16px;">
        <span><?= e($flash['message']) ?></span>
    </div>
</div>
<?php endif; ?>

<main class="main">

<section class="hero" style="text-align: center;">
    <div class="hero-inner" style="text-align: center; margin: 0 auto;">
        <p class="school-title">Stratford International School</p>
        <h1>Request official documents.</h1>
    </div>
</section>

<a class="btn btn-primary btn-lg signin-button" href="<?= url_simple('login.php') ?>"><?= icon('login') ?> Sign in</a>

<section class="section" id="services" style="text-align: center;">
    <div class="container" style="text-align: center; margin: 0 auto; max-width: 1240px;">
        <div class="section-head" style="text-align: center;">
            <h2>What you can request</h2>
            <p class="muted"><?= e($services_subtitle) ?></p>
        </div>

        <div class="card-grid" style="margin: 0 auto; max-width: 1200px; display: flex; flex-wrap: wrap; gap: 18px; justify-content: center;">
            <?php foreach ($services as $s): ?>
                <article class="glass card service-card" style="flex: 0 0 calc(33.333% - 12px); max-width: 340px;">
                    <span class="service-icon"><?= icon('doc', 'ic ic-lg') ?></span>
                    <h3><?= e($s['name']) ?></h3>
                    <p class="muted"><?= e($s['description'] ?: 'Official document issued by the registrar.') ?></p>
                    <div class="service-foot">
                        <span class="price"><?= peso($s['fee']) ?></span>
                        <span class="muted small">≈ <?= (int) $s['processing_days'] ?> day<?= (int) $s['processing_days'] === 1 ? '' : 's' ?></span>
                    </div>
                    <a class="btn btn-soft btn-block" href="<?= url_simple('guest-request.php?service=' . $s['id']) ?>">Request now</a>
                </article>
            <?php endforeach; ?>
            <?php if (!$services): ?>
                <div class="glass empty-note">
                    No services available yet.<br>
                    <span class="muted small">The registrar will add services from the admin panel.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

</main>
</body>
</html>