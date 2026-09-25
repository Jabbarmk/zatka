<?php
require_once __DIR__ . '/includes/auth.php';
if (!users_exist()) { header('Location: ' . BASE_URL . '/setup.php'); exit; }
if (!empty($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([trim($_POST['username'] ?? '')]);
    $user = $st->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        audit('auth.login', $user['username']);
        header('Location: ' . BASE_URL . '/index.php'); exit;
    }
    $error = 'Invalid username or password.';
    audit('auth.login_failed', $_POST['username'] ?? '');
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Sign in - <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container" style="max-width:420px;margin-top:10vh">
<div class="card shadow-sm"><div class="card-body p-4">
<h4 class="mb-3"><?= APP_NAME ?></h4>
<?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?><div class="alert alert-<?= h($t) ?>"><?= h($m) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" autofocus required></div>
<div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
<button class="btn btn-primary w-100">Sign in</button></form>
</div></div></div></body></html>
