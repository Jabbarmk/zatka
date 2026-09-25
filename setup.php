<?php
require_once __DIR__ . '/includes/auth.php';
if (users_exist()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (strlen($u) < 3 || strlen($p) < 8) {
        $error = 'Username must be at least 3 characters and password at least 8 characters.';
    } elseif ($p !== ($_POST['password2'] ?? '')) {
        $error = 'Passwords do not match.';
    } else {
        db()->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)")->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        audit('setup.admin_created', $u);
        flash('Administrator account created. Please sign in.');
        header('Location: ' . BASE_URL . '/login.php'); exit;
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Setup - <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container" style="max-width:460px;margin-top:8vh">
<div class="card shadow-sm"><div class="card-body p-4">
<h4 class="mb-1">Initial setup</h4><p class="text-muted small mb-3">Create the administrator account. ZATCA prohibits default passwords, so choose a strong one.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
<div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" minlength="8" required></div>
<div class="mb-3"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="password2" required></div>
<button class="btn btn-primary w-100">Create account</button></form>
</div></div></div></body></html>
