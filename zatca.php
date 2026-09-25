<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$envUrls = [
    'sandbox'    => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
    'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
    'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
];
$fields = [
    'zatca_enabled', 'zatca_env', 'zatca_base_url', 'zatca_otp',
    'csr_common_name', 'csr_serial_vendor', 'csr_serial_model', 'csr_serial_number', 'csr_org_unit', 'csr_invoice_type', 'csr_location', 'csr_industry',
    'zatca_compliance_cert', 'zatca_compliance_secret', 'zatca_compliance_request_id',
    'zatca_production_cert', 'zatca_production_secret', 'zatca_private_key',
    'zatca_notes',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    $data['zatca_enabled'] = isset($_POST['zatca_enabled']) ? '1' : '0';
    if (!isset($envUrls[$data['zatca_env']])) $data['zatca_env'] = 'sandbox';
    if ($data['zatca_base_url'] === '') $data['zatca_base_url'] = $envUrls[$data['zatca_env']];
    save_settings($data);
    audit('settings.zatca_updated', ['env' => $data['zatca_env'], 'enabled' => $data['zatca_enabled']]);
    flash('ZATCA integration settings saved.');
    header('Location: zatca.php'); exit;
}
$v = [];
foreach ($fields as $f) $v[$f] = setting($f);
if ($v['zatca_env'] === '') $v['zatca_env'] = 'sandbox';
if ($v['csr_invoice_type'] === '') $v['csr_invoice_type'] = '1100';
$unit = db()->query("SELECT * FROM egs_units ORDER BY id LIMIT 1")->fetch();
$seller = seller_settings();

page_header('ZATCA Integration', 'zatca');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">ZATCA Integration</h3>
  <?= $v['zatca_enabled'] === '1' ? '<span class="badge bg-success fs-6">Enabled</span>' : '<span class="badge bg-secondary fs-6">Disabled</span>' ?>
</div>

<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>This page holds the API configuration and certificates for the Fatoora platform. Save the details here now; onboarding (CSR → CSID), cryptographic stamping and clearance/reporting calls will use these values once the integration module is enabled.
</div>

<form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>1. Connection</strong></div><div class="card-body">
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="zatca_enabled" name="zatca_enabled" <?= $v['zatca_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="zatca_enabled">Enable ZATCA submission (clearance / reporting)</label></div>
      <div class="mb-3"><label class="form-label">Environment</label><select class="form-select" name="zatca_env" id="zatca_env">
        <?php foreach ($envUrls as $k => $u): ?><option value="<?= $k ?>" data-url="<?= $u ?>" <?= $v['zatca_env'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
      <div class="mb-3"><label class="form-label">API base URL</label><input class="form-control" name="zatca_base_url" id="zatca_base_url" value="<?= h($v['zatca_base_url'] ?: $envUrls[$v['zatca_env']]) ?>"><div class="form-text">Verify against the ZATCA Developer Portal. Endpoints used: /compliance, /compliance/invoices, /production/csids, /invoices/clearance/single, /invoices/reporting/single</div></div>
      <div class="mb-0"><label class="form-label">OTP (from Fatoora portal, for onboarding / renewal)</label><input class="form-control" name="zatca_otp" value="<?= h($v['zatca_otp']) ?>" placeholder="6-digit OTP"></div>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>2. EGS unit / CSR details</strong></div><div class="card-body">
      <div class="row g-2">
        <div class="col-12"><label class="form-label">Common name (unit name / asset number)</label><input class="form-control" name="csr_common_name" value="<?= h($v['csr_common_name'] ?: $unit['name']) ?>"></div>
        <div class="col-4"><label class="form-label">Solution vendor</label><input class="form-control" name="csr_serial_vendor" value="<?= h($v['csr_serial_vendor']) ?>" placeholder="1-..."></div>
        <div class="col-4"><label class="form-label">Model / version</label><input class="form-control" name="csr_serial_model" value="<?= h($v['csr_serial_model']) ?>" placeholder="2-..."></div>
        <div class="col-4"><label class="form-label">Serial number</label><input class="form-control" name="csr_serial_number" value="<?= h($v['csr_serial_number']) ?>" placeholder="3-..."></div>
        <div class="col-6"><label class="form-label">Organization unit (branch)</label><input class="form-control" name="csr_org_unit" value="<?= h($v['csr_org_unit']) ?>"></div>
        <div class="col-6"><label class="form-label">Invoice types (TSCZ)</label><input class="form-control" name="csr_invoice_type" value="<?= h($v['csr_invoice_type']) ?>" pattern="[01]{4}"><div class="form-text">1100 = Standard + Simplified</div></div>
        <div class="col-6"><label class="form-label">Location (branch address)</label><input class="form-control" name="csr_location" value="<?= h($v['csr_location']) ?>"></div>
        <div class="col-6"><label class="form-label">Industry</label><input class="form-control" name="csr_industry" value="<?= h($v['csr_industry']) ?>"></div>
      </div>
      <div class="small text-muted mt-3">Organization name and VAT number are taken from <a href="settings.php">Company Settings</a>: <strong><?= h($seller['seller_name'] ?: '—') ?></strong> / <strong><?= h($seller['seller_vat'] ?: '—') ?></strong></div>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>3. Certificates &amp; secrets</strong></div><div class="card-body">
      <div class="mb-3"><label class="form-label">Compliance CSID (binarySecurityToken)</label><textarea class="form-control font-monospace small" rows="3" name="zatca_compliance_cert"><?= h($v['zatca_compliance_cert']) ?></textarea></div>
      <div class="row g-2 mb-3">
        <div class="col-7"><label class="form-label">Compliance secret</label><input class="form-control" type="password" name="zatca_compliance_secret" value="<?= h($v['zatca_compliance_secret']) ?>"></div>
        <div class="col-5"><label class="form-label">Compliance request ID</label><input class="form-control" name="zatca_compliance_request_id" value="<?= h($v['zatca_compliance_request_id']) ?>"></div>
      </div>
      <div class="mb-3"><label class="form-label">Production CSID (binarySecurityToken)</label><textarea class="form-control font-monospace small" rows="3" name="zatca_production_cert"><?= h($v['zatca_production_cert']) ?></textarea></div>
      <div class="mb-3"><label class="form-label">Production secret</label><input class="form-control" type="password" name="zatca_production_secret" value="<?= h($v['zatca_production_secret']) ?>"></div>
      <div class="mb-0"><label class="form-label">EGS private key (PEM, ECDSA secp256k1)</label><textarea class="form-control font-monospace small" rows="3" name="zatca_private_key" placeholder="-----BEGIN EC PRIVATE KEY-----"><?= h($v['zatca_private_key']) ?></textarea><div class="form-text text-danger">Stored server-side only. Never exported or displayed on invoices.</div></div>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>4. Onboarding actions</strong> <span class="badge bg-secondary ms-1">coming soon</span></div><div class="card-body">
      <div class="d-grid gap-2">
        <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-key me-1"></i>Generate key pair &amp; CSR</button>
        <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-1-circle me-1"></i>Request compliance CSID (OTP)</button>
        <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-2-circle me-1"></i>Run compliance checks (sample invoices)</button>
        <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-3-circle me-1"></i>Request production CSID</button>
      </div>
      <div class="small text-muted mt-3">These actions will call the API base URL above using the OTP / certificates stored here.</div>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>5. Notes</strong></div><div class="card-body">
      <textarea class="form-control" rows="3" name="zatca_notes" placeholder="Internal notes, portal login reminders, renewal date..."><?= h($v['zatca_notes']) ?></textarea>
    </div></div>
  </div>
</div>
<button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save ZATCA settings</button>
</form>

<script>
document.getElementById('zatca_env').addEventListener('change', function () {
  document.getElementById('zatca_base_url').value = this.selectedOptions[0].dataset.url;
});
</script>
<?php page_footer(); ?>
