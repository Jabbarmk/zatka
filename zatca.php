<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$plain = ['zatca_env', 'zatca_base_url', 'csr_common_name', 'csr_serial_vendor', 'csr_serial_model', 'csr_serial_number', 'csr_org_unit', 'csr_invoice_type', 'csr_location', 'csr_industry', 'zatca_notes'];
$steps = $_SESSION['onboard_steps'] ?? null;
unset($_SESSION['onboard_steps']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save' || $action === 'onboard') {
        $data = [];
        foreach ($plain as $f) $data[$f] = trim($_POST[$f] ?? '');
        if (!isset(ZATCA_ENV_URLS[$data['zatca_env']])) $data['zatca_env'] = 'sandbox';
        // Changing the environment always resets the URL to that environment's address.
        if ($data['zatca_base_url'] === '' || $data['zatca_env'] !== zatca_env()) $data['zatca_base_url'] = ZATCA_ENV_URLS[$data['zatca_env']];
        $data['zatca_enabled'] = isset($_POST['zatca_enabled']) ? '1' : '0';
        $data['zatca_auto_submit'] = isset($_POST['zatca_auto_submit']) ? '1' : '0';
        save_settings($data);
        audit('settings.zatca_updated', ['env' => $data['zatca_env'], 'enabled' => $data['zatca_enabled']]);
    }

    if ($action === 'save') {
        flash('ZATCA settings saved.');
    } elseif ($action === 'onboard') {
        set_time_limit(300);
        $steps = zatca_onboard(trim($_POST['zatca_otp'] ?? ''));
        $_SESSION['onboard_steps'] = $steps;
        $ok = end($steps)['ok'];
        audit('zatca.onboarding', ['ok' => $ok, 'env' => zatca_env()]);
        flash($ok ? 'Onboarding completed.' : 'Onboarding stopped: ' . end($steps)['step'] . ' failed.', $ok ? 'success' : 'danger');
    } elseif ($action === 'manual') {
        $errors = [];
        $cert = trim($_POST['zatca_production_cert'] ?? '');
        $secret = trim($_POST['zatca_production_secret'] ?? '');
        $key = trim($_POST['zatca_private_key'] ?? '');
        $data = [];
        if ($cert !== '') {
            try { cert_info($cert); $data['zatca_production_cert'] = $cert; } catch (Throwable $e) { $errors[] = 'The production CSID is not a valid certificate.'; }
        }
        if ($secret !== '') $data['zatca_production_secret'] = secret_encrypt($secret);
        if ($key !== '') {
            if (!openssl_pkey_get_private($key)) $errors[] = 'The private key is not a valid PEM key.';
            else $data['zatca_private_key'] = secret_encrypt($key);
        }
        if (!$errors && $data) {
            $newCert = $data['zatca_production_cert'] ?? setting('zatca_production_cert');
            $newKey = $key !== '' ? $key : secret_setting('zatca_private_key');
            if ($newCert !== '' && $newKey !== '' && zatca_env() !== 'sandbox' && !key_matches_cert($newKey, cert_info($newCert))) {
                $errors[] = 'The private key does not belong to this certificate. Nothing was saved.';
            }
        }
        if ($errors) foreach ($errors as $e) flash($e, 'danger');
        elseif (!$data) flash('Nothing to save: all three fields were empty.', 'warning');
        else { save_settings($data); audit('zatca.credentials_updated', array_keys($data)); flash('Credentials saved.'); }
    } elseif ($action === 'clear') {
        save_settings(['zatca_production_cert' => '', 'zatca_production_secret' => '', 'zatca_private_key' => '', 'zatca_compliance_cert' => '', 'zatca_compliance_secret' => '', 'zatca_compliance_request_id' => '', 'zatca_enabled' => '0']);
        audit('zatca.credentials_cleared');
        flash('Certificates, secrets and key removed. Integration disabled.', 'warning');
    }
    header('Location: zatca.php'); exit;
}

$csr = setting('zatca_csr');
if (isset($_GET['download']) && $_GET['download'] === 'csr') {
    if ($csr === '') { http_response_code(404); exit('No certificate request has been generated yet.'); }
    audit('zatca.csr_downloaded');
    header('Content-Type: application/pkcs10');
    header('Content-Disposition: attachment; filename="zatca-' . preg_replace('/[^a-z]/', '', setting('zatca_csr_env', 'request')) . '.csr"');
    echo $csr; exit;
}
$csrInfo = $csr !== '' ? csr_info($csr) : null;
$csrMatchesKey = false;
if ($csrInfo && setting('zatca_private_key') !== '') {
    $k = openssl_pkey_get_private(secret_setting('zatca_private_key'));
    if ($k) $csrMatchesKey = hash_equals($csrInfo['public_key_der'], base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($k)['key'])));
}

$v = [];
foreach ($plain as $f) $v[$f] = setting($f);
$env = zatca_env();
if ($v['csr_invoice_type'] === '') $v['csr_invoice_type'] = '1100';
$unit = db()->query("SELECT * FROM egs_units ORDER BY id LIMIT 1")->fetch();
$seller = seller_settings();
$problems = zatca_onboarding_problems();
$hasCert = setting('zatca_production_cert') !== '';
$hasSecret = setting('zatca_production_secret') !== '';
$hasKey = setting('zatca_private_key') !== '';
$certInfo = null; $certError = null;
if ($hasCert) { try { $certInfo = cert_info(setting('zatca_production_cert')); } catch (Throwable $e) { $certError = $e->getMessage(); } }
$ready = zatca_signing_ready();
$enabled = setting('zatca_enabled') === '1';
$daysLeft = $certInfo ? (int)floor((strtotime($certInfo['valid_to']) - time()) / 86400) : null;
$yes = '<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>';
$no = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i></span>';

page_header('ZATCA Integration', 'zatca');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">ZATCA Integration</h3>
  <?= $enabled && $ready ? '<span class="badge bg-success fs-6">Live: ' . h($env) . '</span>' : '<span class="badge bg-secondary fs-6">Not active</span>' ?>
</div>

<?php if ($steps): ?>
<div class="card shadow-sm mb-3 border-<?= end($steps)['ok'] ? 'success' : 'danger' ?>"><div class="card-header bg-white"><strong>Onboarding result</strong></div>
  <ul class="list-group list-group-flush">
  <?php foreach ($steps as $s): ?><li class="list-group-item"><?= $s['ok'] ? $yes : $no ?> <strong><?= h($s['step']) ?></strong> <span class="text-muted">— <?= h($s['message']) ?></span></li><?php endforeach; ?>
  </ul>
  <div class="card-footer bg-white small"><a href="zatca_logs.php">Open the response log</a> for the full ZATCA responses.</div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>Status</strong></div><div class="card-body"><div class="row small">
  <div class="col-md-6"><table class="table table-sm mb-0">
    <tr><td><?= seller_configured($seller) ? $yes : $no ?> Company settings</td><td class="text-end"><a href="settings.php"><?= h($seller['seller_vat'] ?: 'complete them') ?></a></td></tr>
    <tr><td><?= $hasCert && !$certError ? $yes : $no ?> Production certificate (CSID)</td><td class="text-end"><?= $certInfo ? 'expires ' . h(substr($certInfo['valid_to'], 0, 10)) . " ($daysLeft days)" : ($certError ? 'invalid' : 'not installed') ?></td></tr>
    <tr><td><?= $hasSecret ? $yes : $no ?> API secret</td><td class="text-end"><?= $hasSecret ? 'stored (encrypted)' : 'not installed' ?></td></tr>
    <tr><td><?= $hasKey ? $yes : $no ?> Private key</td><td class="text-end"><?= $hasKey ? 'stored (encrypted)' : 'not installed' ?></td></tr>
  </table></div>
  <div class="col-md-6"><table class="table table-sm mb-0">
    <tr><td><?= $ready ? $yes : $no ?> Key belongs to certificate</td><td class="text-end"><?= $ready ? ($ready['key_matches'] ? 'yes' : 'sandbox demo certificate') : '—' ?></td></tr>
    <tr><td><?= $enabled ? $yes : $no ?> Submission enabled</td><td class="text-end"><?= h($env) ?></td></tr>
    <tr><td>Invoice counter (ICV)</td><td class="text-end"><?= (int)$unit['last_icv'] ?></td></tr>
    <tr><td>Last onboarding</td><td class="text-end"><?= h(setting('zatca_onboarded_at', '—')) ?></td></tr>
  </table></div>
</div>
<?php if ($certInfo): ?><div class="small text-muted mt-2">Certificate subject: <?= h($certInfo['subject']) ?> · issuer: <?= h($certInfo['issuer']) ?></div><?php endif; ?>
<?php if ($daysLeft !== null && $daysLeft < 30): ?><div class="alert alert-warning mt-2 mb-0 py-2">The certificate expires in <?= $daysLeft ?> days. Run the onboarding again with a new OTP to renew it.</div><?php endif; ?>
</div></div>

<div class="row g-3">
  <div class="col-lg-6">
<form method="post" id="zform"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>1. Connection</strong></div><div class="card-body">
      <div class="mb-3"><label class="form-label">Environment</label><select class="form-select" name="zatca_env" id="zatca_env">
        <?php foreach (ZATCA_ENV_URLS as $k => $u): ?><option value="<?= $k ?>" data-url="<?= $u ?>" <?= $env === $k ? 'selected' : '' ?>><?= ucfirst($k) ?><?= ['sandbox' => ' (developer testing, OTP 123345)', 'simulation' => ' (test with your real Fatoora account)', 'production' => ' (live invoices)'][$k] ?></option><?php endforeach; ?></select></div>
      <div class="mb-3"><label class="form-label">API base URL</label><input class="form-control" name="zatca_base_url" id="zatca_base_url" value="<?= h(zatca_base_url()) ?>"></div>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="zatca_enabled" name="zatca_enabled" <?= $enabled ? 'checked' : '' ?>><label class="form-check-label" for="zatca_enabled">Enable submission to ZATCA</label></div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="zatca_auto_submit" name="zatca_auto_submit" <?= setting('zatca_auto_submit', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="zatca_auto_submit">Send each document to ZATCA automatically when it is issued</label></div>
      <div class="form-text mt-2">A certificate belongs to one environment. After changing the environment, run the onboarding again.</div>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>2. Invoicing unit details</strong> <span class="text-muted small">(go into the certificate)</span></div><div class="card-body">
      <div class="row g-2">
        <div class="col-12"><label class="form-label">Common name (unit name or asset number) *</label><input class="form-control" name="csr_common_name" value="<?= h($v['csr_common_name']) ?>" placeholder="e.g. Main-Branch-POS-1"></div>
        <div class="col-4"><label class="form-label">Solution vendor *</label><input class="form-control" name="csr_serial_vendor" value="<?= h($v['csr_serial_vendor']) ?>" placeholder="Your company"></div>
        <div class="col-4"><label class="form-label">Model / version *</label><input class="form-control" name="csr_serial_model" value="<?= h($v['csr_serial_model']) ?>" placeholder="1.0"></div>
        <div class="col-4"><label class="form-label">Serial number *</label><input class="form-control" name="csr_serial_number" value="<?= h($v['csr_serial_number']) ?>" placeholder="Unique per unit"></div>
        <div class="col-6"><label class="form-label">Organization unit (branch) *</label><input class="form-control" name="csr_org_unit" value="<?= h($v['csr_org_unit']) ?>" placeholder="Riyadh Branch"><div class="form-text">VAT group: 10-digit TIN of the member.</div></div>
        <div class="col-6"><label class="form-label">Invoice types *</label><select class="form-select" name="csr_invoice_type">
          <?php foreach (['1100' => 'Standard + Simplified', '1000' => 'Standard (B2B) only', '0100' => 'Simplified (B2C) only'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['csr_invoice_type'] === $k ? 'selected' : '' ?>><?= $k ?> — <?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Location (branch address) *</label><input class="form-control" name="csr_location" value="<?= h($v['csr_location']) ?>" placeholder="National short address, e.g. RRRD2929"></div>
        <div class="col-6"><label class="form-label">Industry *</label><input class="form-control" name="csr_industry" value="<?= h($v['csr_industry']) ?>" placeholder="e.g. Retail"></div>
      </div>
      <div class="small text-muted mt-3">Organization name and VAT number come from <a href="settings.php">Company Settings</a>: <strong><?= h($seller['seller_name'] ?: '—') ?></strong> / <strong><?= h($seller['seller_vat'] ?: '—') ?></strong></div>
    </div></div>
    <button class="btn btn-primary mb-3" name="action" value="save"><i class="bi bi-save me-1"></i>Save settings</button>
</form>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm mb-3 border-primary"><div class="card-header bg-white"><strong>3. Get certificate from ZATCA (onboarding)</strong></div><div class="card-body">
      <p class="small text-muted">Log in to the Fatoora portal, choose <em>Onboard new solution unit</em>, and generate an OTP. Enter it here. The app then creates the private key and certificate request, obtains the compliance certificate, runs ZATCA's compliance checks, and obtains the production certificate. This saves the settings on the left first.</p>
      <?php if ($problems): ?><div class="alert alert-warning py-2 small mb-3"><strong>Fill these in first:</strong><ul class="mb-0"><?php foreach ($problems as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="input-group">
        <span class="input-group-text">OTP</span>
        <input class="form-control" form="zform" name="zatca_otp" placeholder="6 digits" maxlength="6" inputmode="numeric" autocomplete="off">
        <button class="btn btn-primary" form="zform" name="action" value="onboard" onclick="return confirm('<?= $hasCert ? 'This replaces the installed certificate and key. ' : '' ?>Start onboarding in the <?= h($env) ?> environment?')"><i class="bi bi-shield-lock me-1"></i>Start onboarding</button>
      </div>
      <div class="form-text">The OTP is valid for one hour and can be used once. Onboarding takes up to a minute.</div>
    </div></div>

    <form method="post" class="card shadow-sm mb-3"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="manual">
      <div class="card-header bg-white"><strong>4. Or paste existing credentials</strong></div><div class="card-body">
      <p class="small text-muted">Only if you already have a production certificate from another tool. Empty fields keep the stored value. Stored secrets are never shown again.</p>
      <div class="mb-3"><label class="form-label">Production CSID (binarySecurityToken) <?= $hasCert ? '<span class="badge bg-success">installed</span>' : '' ?></label><textarea class="form-control font-monospace small" rows="3" name="zatca_production_cert" placeholder="<?= $hasCert ? 'Leave empty to keep the installed certificate' : 'Paste the binarySecurityToken' ?>"></textarea></div>
      <div class="mb-3"><label class="form-label">Production secret <?= $hasSecret ? '<span class="badge bg-success">stored</span>' : '' ?></label><input class="form-control" type="password" name="zatca_production_secret" autocomplete="new-password" placeholder="<?= $hasSecret ? 'Leave empty to keep' : '' ?>"></div>
      <div class="mb-3"><label class="form-label">Private key (PEM, secp256k1) <?= $hasKey ? '<span class="badge bg-success">stored</span>' : '' ?></label><textarea class="form-control font-monospace small" rows="3" name="zatca_private_key" placeholder="<?= $hasKey ? 'Leave empty to keep' : '-----BEGIN EC PRIVATE KEY-----' ?>"></textarea></div>
      <button class="btn btn-outline-primary"><i class="bi bi-key me-1"></i>Save credentials</button>
    </div></form>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>5. Certificate request (CSR)</strong>
      <?php if ($csr !== ''): ?><span class="badge bg-<?= $csrMatchesKey ? 'success' : 'secondary' ?>"><?= $csrMatchesKey ? 'Matches installed key' : 'Not the installed key' ?></span><?php endif; ?></div>
      <div class="card-body">
      <?php if ($csr === ''): ?>
        <p class="small text-muted mb-0">No request has been generated yet. The app creates it automatically when you start the onboarding, and it will appear here.</p>
      <?php else: ?>
        <p class="small text-muted">Generated <?= h(setting('zatca_csr_at', 'at the last onboarding')) ?> for the <strong><?= h(setting('zatca_csr_env', $env)) ?></strong> environment. The request contains only public information; the private key is never shown.</p>
        <?php if ($csrInfo): ?>
        <table class="table table-sm small mb-3">
          <?php foreach (['CN' => 'Common name', 'O' => 'Organization', 'OU' => 'Organization unit', 'C' => 'Country'] as $k => $label): if (isset($csrInfo['subject'][$k])): ?>
          <tr><td class="text-muted"><?= $label ?></td><td class="text-end"><?= h(is_array($csrInfo['subject'][$k]) ? implode(', ', $csrInfo['subject'][$k]) : $csrInfo['subject'][$k]) ?></td></tr>
          <?php endif; endforeach; ?>
          <tr><td class="text-muted">Key</td><td class="text-end">ECDSA <?= h($csrInfo['curve']) ?>, <?= (int)$csrInfo['bits'] ?> bit</td></tr>
        </table>
        <?php endif; ?>
        <label class="form-label" for="csr-text">Request (PEM)</label>
        <textarea class="form-control font-monospace small mb-3" id="csr-text" rows="6" readonly><?= h($csr) ?></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-outline-primary btn-sm" href="zatca.php?download=csr"><i class="bi bi-download me-1"></i>Download .csr</a>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="csr-copy"><i class="bi bi-clipboard me-1"></i>Copy</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="csr-copy-b64" title="The form ZATCA's API expects in the request body"><i class="bi bi-clipboard-data me-1"></i>Copy as Base64</button>
          <span class="small text-success align-self-center d-none" id="csr-copied" role="status">Copied</span>
        </div>
      <?php endif; ?>
    </div></div>

    <form method="post" class="card shadow-sm mb-3"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="card-header bg-white"><strong>6. Notes</strong></div><div class="card-body">
      <?php foreach ($plain as $f) if ($f !== 'zatca_notes'): ?><input type="hidden" name="<?= $f ?>" value="<?= h($f === 'zatca_base_url' ? zatca_base_url() : ($f === 'zatca_env' ? $env : $v[$f])) ?>"><?php endif; ?>
      <?php if ($enabled): ?><input type="hidden" name="zatca_enabled" value="1"><?php endif; ?>
      <?php if (setting('zatca_auto_submit', '1') === '1'): ?><input type="hidden" name="zatca_auto_submit" value="1"><?php endif; ?>
      <textarea class="form-control mb-2" rows="2" name="zatca_notes" placeholder="Internal notes, renewal reminders..."><?= h($v['zatca_notes']) ?></textarea>
      <button class="btn btn-sm btn-outline-secondary" name="action" value="save">Save notes</button>
      <?php if ($hasCert || $hasKey): ?><button class="btn btn-sm btn-outline-danger float-end" name="action" value="clear" onclick="return confirm('Remove the certificate, secret and private key? Documents cannot be sent to ZATCA until you onboard again.')">Remove credentials</button><?php endif; ?>
    </div></form>
  </div>
</div>

<script>
(function () {
  const text = document.getElementById('csr-text');
  if (!text) return;
  const done = document.getElementById('csr-copied');
  function copy(value) {
    const finish = () => { done.classList.remove('d-none'); setTimeout(() => done.classList.add('d-none'), 2500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(finish);
    else { const t = document.createElement('textarea'); t.value = value; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); finish(); }
  }
  document.getElementById('csr-copy').addEventListener('click', () => copy(text.value));
  document.getElementById('csr-copy-b64').addEventListener('click', () => copy(btoa(text.value)));
})();
document.getElementById('zatca_env').addEventListener('change', function () {
  document.getElementById('zatca_base_url').value = this.selectedOptions[0].dataset.url;
});
</script>
<?php page_footer(); ?>
