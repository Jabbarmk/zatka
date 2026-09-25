<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];
$c = ['name' => '', 'vat_number' => '', 'id_scheme' => 'NAT', 'id_value' => '', 'street' => '', 'building_no' => '', 'district' => '', 'city' => '', 'postal_code' => '', 'country' => 'SA', 'phone' => '', 'email' => ''];

if ($action === 'edit' && $id) {
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?"); $st->execute([$id]);
    $c = $st->fetch() ?: $c;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($c) as $k) if ($k !== 'id') $c[$k] = trim($_POST[$k] ?? '');
    if ($c['name'] === '') $errors[] = 'Name is required.';
    if ($c['vat_number'] !== '' && !preg_match('/^3\d{13}3$/', $c['vat_number'])) $errors[] = 'Buyer VAT number must be 15 digits starting and ending with 3.';
    if ($c['country'] === 'SA' && $c['postal_code'] !== '' && !preg_match('/^\d{5}$/', $c['postal_code'])) $errors[] = 'Postal code must be 5 digits.';
    if ($c['building_no'] !== '' && !preg_match('/^\d{4}$/', $c['building_no'])) $errors[] = 'Building number must be 4 digits.';
    if (!$errors) {
        $cols = ['name', 'vat_number', 'id_scheme', 'id_value', 'street', 'building_no', 'district', 'city', 'postal_code', 'country', 'phone', 'email'];
        $vals = array_map(fn($k) => $c[$k] === '' ? null : $c[$k], $cols);
        if ($id) {
            $pdo->prepare("UPDATE customers SET " . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . " WHERE id = ?")->execute([...$vals, $id]);
        } else {
            $pdo->prepare("INSERT INTO customers (" . implode(', ', $cols) . ") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute($vals);
        }
        flash('Customer saved.');
        header('Location: customers.php'); exit;
    }
    $action = $id ? 'edit' : 'new';
}

page_header('Customers', 'customers');
if ($action === 'list'):
    $rows = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h3 class="mb-0">Customers</h3><a href="customers.php?action=new" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Customer</a></div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Name</th><th>VAT number</th><th>Other ID</th><th>City</th><th>Phone</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No customers yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><?= h($r['name']) ?></td><td><?= h($r['vat_number'] ?? '—') ?></td><td><?= $r['id_value'] ? h($r['id_scheme'] . ' ' . $r['id_value']) : '—' ?></td><td><?= h($r['city'] ?? '') ?></td><td><?= h($r['phone'] ?? '') ?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="customers.php?action=edit&id=<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php else: ?>
<h3 class="mb-4"><?= $id ? 'Edit' : 'New' ?> Customer</h3>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
<form method="post" class="card shadow-sm"><div class="card-body"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="row g-3">
  <div class="col-md-6"><label class="form-label">Name *</label><input class="form-control" name="name" value="<?= h($c['name']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">VAT number (B2B)</label><input class="form-control" name="vat_number" value="<?= h($c['vat_number']) ?>" maxlength="15"><div class="form-text">Leave empty for consumers / non-registered buyers; then provide an Other ID.</div></div>
  <div class="col-md-3"><label class="form-label">Other ID type</label><select class="form-select" name="id_scheme"><?php foreach (BUYER_ID_SCHEMES as $k => $vv): ?><option value="<?= $k ?>" <?= ($c['id_scheme'] ?? '') === $k ? 'selected' : '' ?>><?= $k ?> - <?= $vv ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Other ID number</label><input class="form-control" name="id_value" value="<?= h($c['id_value']) ?>"></div>
  <div class="col-md-3"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h($c['phone']) ?>"></div>
  <div class="col-md-3"><label class="form-label">Email</label><input class="form-control" name="email" value="<?= h($c['email']) ?>"></div>
  <div class="col-md-6"><label class="form-label">Street</label><input class="form-control" name="street" value="<?= h($c['street']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Building no.</label><input class="form-control" name="building_no" value="<?= h($c['building_no']) ?>" maxlength="4"></div>
  <div class="col-md-4"><label class="form-label">District</label><input class="form-control" name="district" value="<?= h($c['district']) ?>"></div>
  <div class="col-md-4"><label class="form-label">City</label><input class="form-control" name="city" value="<?= h($c['city']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Postal code</label><input class="form-control" name="postal_code" value="<?= h($c['postal_code']) ?>" maxlength="5"></div>
  <div class="col-md-2"><label class="form-label">Country</label><input class="form-control" name="country" value="<?= h($c['country'] ?: 'SA') ?>" maxlength="2"></div>
</div>
<div class="mt-4"><button class="btn btn-primary">Save</button> <a href="customers.php" class="btn btn-link">Cancel</a></div>
</div></form>
<?php endif; page_footer(); ?>
