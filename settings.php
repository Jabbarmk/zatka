<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$fields = ['seller_name', 'seller_name_ar', 'seller_vat', 'seller_id_scheme', 'seller_id_value', 'street', 'building_no', 'additional_no', 'district', 'city', 'postal_code', 'province', 'country', 'phone', 'email'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    if ($data['seller_name'] === '') $errors[] = 'Seller name is required.';
    if (!preg_match('/^3\d{13}3$/', $data['seller_vat'])) $errors[] = 'VAT number must be 15 digits, starting and ending with 3.';
    if ($data['seller_id_value'] === '') $errors[] = 'Additional seller ID (e.g. CR number) is required.';
    if (!preg_match('/^\d{4}$/', $data['building_no'])) $errors[] = 'Building number must be exactly 4 digits.';
    if ($data['additional_no'] !== '' && !preg_match('/^\d{4}$/', $data['additional_no'])) $errors[] = 'Additional number must be 4 digits.';
    if (!preg_match('/^\d{5}$/', $data['postal_code'])) $errors[] = 'Postal code must be exactly 5 digits.';
    foreach (['street' => 'Street', 'district' => 'District', 'city' => 'City'] as $k => $label) if ($data[$k] === '') $errors[] = "$label is required.";
    if (!$errors) {
        save_settings($data);
        audit('settings.company_updated');
        flash('Company settings saved.');
        header('Location: settings.php'); exit;
    }
    $s = $data;
} else {
    $s = seller_settings();
}
page_header('Company Settings', 'settings');
?>
<h3 class="mb-4">Company (Seller) Settings</h3>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
<form method="post" class="card shadow-sm"><div class="card-body">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<h6 class="text-muted mb-3">Identification</h6>
<div class="row g-3 mb-4">
  <div class="col-md-6"><label class="form-label">Registered name (English) *</label><input class="form-control" name="seller_name" value="<?= h($s['seller_name']) ?>" required></div>
  <div class="col-md-6"><label class="form-label">Registered name (Arabic)</label><input class="form-control text-ar" name="seller_name_ar" value="<?= h($s['seller_name_ar']) ?>"></div>
  <div class="col-md-4"><label class="form-label">VAT registration number *</label><input class="form-control" name="seller_vat" value="<?= h($s['seller_vat']) ?>" pattern="3\d{13}3" maxlength="15" required><div class="form-text">15 digits, starts and ends with 3</div></div>
  <div class="col-md-4"><label class="form-label">Additional ID type *</label><select class="form-select" name="seller_id_scheme">
    <?php foreach (SELLER_ID_SCHEMES as $k => $v): ?><option value="<?= $k ?>" <?= ($s['seller_id_scheme'] ?: 'CRN') === $k ? 'selected' : '' ?>><?= $k ?> - <?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Additional ID number *</label><input class="form-control" name="seller_id_value" value="<?= h($s['seller_id_value']) ?>" required></div>
</div>
<h6 class="text-muted mb-3">National address (must be in Saudi Arabia)</h6>
<div class="row g-3 mb-4">
  <div class="col-md-6"><label class="form-label">Street *</label><input class="form-control" name="street" value="<?= h($s['street']) ?>" required></div>
  <div class="col-md-3"><label class="form-label">Building number *</label><input class="form-control" name="building_no" value="<?= h($s['building_no']) ?>" pattern="\d{4}" maxlength="4" required></div>
  <div class="col-md-3"><label class="form-label">Additional number</label><input class="form-control" name="additional_no" value="<?= h($s['additional_no']) ?>" pattern="\d{4}" maxlength="4"></div>
  <div class="col-md-4"><label class="form-label">District *</label><input class="form-control" name="district" value="<?= h($s['district']) ?>" required></div>
  <div class="col-md-4"><label class="form-label">City *</label><input class="form-control" name="city" value="<?= h($s['city']) ?>" required></div>
  <div class="col-md-2"><label class="form-label">Postal code *</label><input class="form-control" name="postal_code" value="<?= h($s['postal_code']) ?>" pattern="\d{5}" maxlength="5" required></div>
  <div class="col-md-2"><label class="form-label">Country</label><input class="form-control" name="country" value="SA" readonly></div>
  <div class="col-md-4"><label class="form-label">Province / Region</label><input class="form-control" name="province" value="<?= h($s['province']) ?>"></div>
  <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h($s['phone']) ?>"></div>
  <div class="col-md-4"><label class="form-label">Email</label><input class="form-control" name="email" value="<?= h($s['email']) ?>"></div>
</div>
<button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
</div></form>
<?php page_footer(); ?>
