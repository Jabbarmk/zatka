<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$user = current_user();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $st = db()->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$user['id']]);
    if (!password_verify($current, (string)$st->fetchColumn())) $errors['current_password'] = 'The current password is not correct.';
    if (strlen($new) < 8) $errors['new_password'] = 'Use at least 8 characters.';
    elseif ($new === $current) $errors['new_password'] = 'Choose a password different from the current one.';
    if ($new !== ($_POST['confirm_password'] ?? '')) $errors['confirm_password'] = 'The two new passwords do not match.';

    if (!$errors) {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        audit('auth.password_changed', $user['username']);
        flash('Password changed.');
        header('Location: account.php'); exit;
    }
    audit('auth.password_change_failed', $user['username']);
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block" id="' . $key . '-error" role="alert">' . h($errors[$key]) . '</div>' : '';
}

page_header('My account', 'account');
?>
<h3 class="mb-4">My account</h3>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card shadow-sm" autocomplete="off" novalidate>
      <div class="card-header"><strong>Change password</strong></div>
      <div class="card-body">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php foreach (['current_password' => ['Current password', 'current-password', ''], 'new_password' => ['New password', 'new-password', 'At least 8 characters.'], 'confirm_password' => ['Repeat new password', 'new-password', '']] as $name => [$label, $auto, $help]): ?>
        <div class="mb-3">
          <label class="form-label" for="<?= $name ?>"><?= $label ?> *</label>
          <div class="input-group">
            <input class="form-control <?= isset($errors[$name]) ? 'is-invalid' : '' ?>" type="password" id="<?= $name ?>" name="<?= $name ?>" autocomplete="<?= $auto ?>" required <?= $name !== 'current_password' ? 'minlength="8"' : '' ?> <?= isset($errors[$name]) ? 'aria-describedby="' . $name . '-error"' : '' ?>>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="<?= $name ?>" aria-label="Show <?= strtolower($label) ?>"><i class="bi bi-eye"></i></button>
          </div>
          <?= field_error($errors, $name) ?>
          <?php if ($help && !isset($errors[$name])): ?><div class="form-text"><?= $help ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Change password</button>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm"><div class="card-header"><strong>Account</strong></div>
      <div class="card-body"><table class="table table-sm mb-0">
        <tr><td class="text-muted">Username</td><td class="text-end"><?= h($user['username']) ?></td></tr>
        <tr><td class="text-muted">Password storage</td><td class="text-end">One-way hash, cannot be read back</td></tr>
      </table>
      <p class="small text-muted mt-3 mb-0">If you forget the password, it cannot be recovered from this page. It has to be reset directly in the database.</p>
    </div></div>
  </div>
</div>
<script>
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    const input = document.getElementById(btn.dataset.togglePassword);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.innerHTML = '<i class="bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + '"></i>';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  });
});
<?php if ($errors): ?>document.getElementById('<?= array_key_first($errors) ?>').focus();<?php endif; ?>
</script>
<?php page_footer(); ?>
