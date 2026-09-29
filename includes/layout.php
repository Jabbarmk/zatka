<?php
function page_header(string $title, string $active = ''): void
{
    $user = current_user();
    $unsent = (int)db()->query("SELECT COUNT(*) FROM invoices WHERE zatca_status IN ('not_submitted','pending','failed','rejected')")->fetchColumn();
    $groups = [
        'Overview' => ['dashboard' => ['index.php', 'bi-grid-1x2', 'Dashboard']],
        'Sales' => [
            'invoices'  => ['invoices.php', 'bi-receipt', 'Invoices'],
            'new'       => ['invoice_form.php', 'bi-plus-circle', 'New Invoice'],
            'customers' => ['customers.php', 'bi-people', 'Customers'],
        ],
        'Compliance' => [
            'zatca' => ['zatca.php', 'bi-shield-check', 'ZATCA Integration'],
            'logs'  => ['zatca_logs.php', 'bi-journal-text', 'Response Log'],
        ],
        'Settings' => ['settings' => ['settings.php', 'bi-building', 'Company']],
    ];
    $live = setting('zatca_enabled') === '1' && setting('zatca_production_cert') !== '';
    $env = setting('zatca_env', 'sandbox');
    $initial = strtoupper(substr($user['username'] ?? 'U', 0, 1));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= APP_NAME ?></title>
<script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/app.css?v=3" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="app">
  <div class="sidebar-backdrop d-print-none" data-sidebar-close></div>
  <aside class="sidebar d-print-none" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="<?= BASE_URL ?>/index.php">
      <span class="brand-mark"><i class="bi bi-receipt-cutoff"></i></span>
      <span><span class="brand-name">Fatoora</span><span class="brand-sub">E-Invoicing</span></span>
    </a>
    <nav class="side-nav">
      <?php foreach ($groups as $group => $items): ?>
      <div class="nav-group"><?= $group ?></div>
      <?php foreach ($items as $key => [$href, $icon, $label]): ?>
      <a class="side-link <?= $active === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/<?= $href ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
        <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
        <?php if ($key === 'invoices' && $unsent): ?><span class="nav-count" title="Documents not yet accepted by ZATCA"><?= $unsent ?></span><?php endif; ?>
      </a>
      <?php endforeach; endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="env-card">
        <span class="dot <?= $live ? 'dot-on' : 'dot-off' ?>"></span>
        <div><div class="env-title"><?= $live ? 'ZATCA connected' : 'ZATCA not connected' ?></div><div class="env-sub"><?= h(ucfirst($env)) ?> environment</div></div>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar d-print-none">
      <button class="icon-btn d-lg-none" type="button" data-sidebar-open aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div class="topbar-title"><?= h($title) ?></div>
      <div class="topbar-actions">
        <a class="btn btn-primary btn-sm d-none d-sm-inline-flex align-items-center" href="<?= BASE_URL ?>/invoice_form.php"><i class="bi bi-plus-lg me-1"></i>New invoice</a>
        <button class="icon-btn" type="button" id="theme-toggle" aria-label="Switch colour theme"><i class="bi bi-moon-stars"></i></button>
        <div class="dropdown">
          <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= h($initial) ?></span><span class="d-none d-md-inline"><?= h($user['username'] ?? '') ?></span><i class="bi bi-chevron-down small"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/account.php"><i class="bi bi-person-lock me-2"></i>Change password</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings.php"><i class="bi bi-building me-2"></i>Company settings</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/zatca.php"><i class="bi bi-shield-check me-2"></i>ZATCA integration</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
          </ul>
        </div>
      </div>
    </header>
    <main class="content" id="main">
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show" role="alert"><?= h($msg) ?><button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function page_footer(): void
{
    ?>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/ui.js?v=3"></script>
<script src="<?= BASE_URL ?>/assets/app.js?v=3"></script>
</body>
</html>
<?php
}
