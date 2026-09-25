<?php
function page_header(string $title, string $active = ''): void
{
    $user = current_user();
    $nav = [
        'dashboard' => ['index.php', 'bi-speedometer2', 'Dashboard'],
        'invoices'  => ['invoices.php', 'bi-receipt', 'Invoices'],
        'new'       => ['invoice_form.php', 'bi-plus-circle', 'New Invoice'],
        'customers' => ['customers.php', 'bi-people', 'Customers'],
        'settings'  => ['settings.php', 'bi-building', 'Company Settings'],
        'zatca'     => ['zatca.php', 'bi-shield-check', 'ZATCA Integration'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/app.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
  <nav class="sidebar bg-dark text-white p-3 d-print-none">
    <div class="brand mb-4"><i class="bi bi-file-earmark-text me-2"></i><?= APP_NAME ?></div>
    <ul class="nav flex-column">
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
      <li class="nav-item"><a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/<?= $href ?>"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></a></li>
      <?php endforeach; ?>
    </ul>
    <hr class="border-secondary">
    <div class="small text-secondary">Signed in as <strong class="text-white"><?= h($user['username'] ?? '') ?></strong></div>
    <a class="nav-link" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
  </nav>
  <main class="content flex-grow-1 p-4">
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show"><?= h($msg) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function page_footer(): void
{
    ?>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/app.js"></script>
</body>
</html>
<?php
}
