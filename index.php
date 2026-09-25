<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();
$month = date('Y-m-01');
$stats = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(grand_total),0) t, COALESCE(SUM(vat_total),0) v FROM invoices WHERE issue_date >= '$month'")->fetch();
$total = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE zatca_status IN ('not_submitted','pending','failed')")->fetchColumn();
$customers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$recent = $pdo->query("SELECT i.*, c.name customer_name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id ORDER BY i.id DESC LIMIT 8")->fetchAll();
$unit = $pdo->query("SELECT * FROM egs_units ORDER BY id LIMIT 1")->fetch();
$seller = seller_settings();
$sellerOk = seller_configured($seller);
$zatcaEnabled = setting('zatca_enabled') === '1';
$hasCert = setting('zatca_production_cert') !== '';

page_header('Dashboard', 'dashboard');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">Dashboard</h3>
  <a href="invoice_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Invoice</a>
</div>

<?php if (!$sellerOk): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Company (seller) details are incomplete. <a href="settings.php" class="alert-link">Complete Company Settings</a> before issuing invoices.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body"><div class="text-muted small">Invoices this month</div><div class="value"><?= (int)$stats['c'] ?></div><div class="small text-muted">Total: <?= $total ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body"><div class="text-muted small">Sales this month (SAR)</div><div class="value"><?= number_format($stats['t'], 2) ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body"><div class="text-muted small">VAT this month (SAR)</div><div class="value"><?= number_format($stats['v'], 2) ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body"><div class="text-muted small">Customers</div><div class="value"><?= $customers ?></div></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card shadow-sm h-100"><div class="card-header bg-white d-flex justify-content-between"><strong>Recent invoices</strong><a href="invoices.php" class="small">View all</a></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead class="table-light"><tr><th>Number</th><th>Type</th><th>Customer</th><th>Date</th><th class="text-end">Total</th><th>ZATCA</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-4">No invoices yet.</td></tr><?php endif; ?>
      <?php foreach ($recent as $r): ?>
        <tr><td><a href="invoice_view.php?id=<?= $r['id'] ?>"><?= h($r['invoice_number']) ?></a></td>
        <td><?= h(DOC_TYPES[$r['type_code']] ?? $r['type_code']) ?> <span class="text-muted small"><?= $r['subtype'] === '02' ? 'B2C' : 'B2B' ?></span></td>
        <td><?= h($r['customer_name'] ?? '—') ?></td><td><?= h($r['issue_date']) ?></td>
        <td class="text-end"><?= number_format($r['grand_total'], 2) ?></td>
        <td><?= status_badge($r['zatca_status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-shield-check me-1"></i>ZATCA Integration</strong></div>
      <div class="card-body">
        <dl class="row small mb-3">
          <dt class="col-6">Status</dt><dd class="col-6"><?= $zatcaEnabled ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Not configured</span>' ?></dd>
          <dt class="col-6">Environment</dt><dd class="col-6"><?= h(setting('zatca_env', 'sandbox')) ?></dd>
          <dt class="col-6">Certificate (CSID)</dt><dd class="col-6"><?= $hasCert ? '<span class="text-success">Installed</span>' : '<span class="text-muted">Not installed</span>' ?></dd>
          <dt class="col-6">EGS unit</dt><dd class="col-6"><?= h($unit['name']) ?></dd>
          <dt class="col-6">Invoice counter (ICV)</dt><dd class="col-6"><?= (int)$unit['last_icv'] ?></dd>
          <dt class="col-6">Pending submissions</dt><dd class="col-6"><?= $pending ?></dd>
        </dl>
        <a href="zatca.php" class="btn btn-outline-primary w-100"><i class="bi bi-gear me-1"></i>Configure ZATCA Integration</a>
        <p class="small text-muted mt-3 mb-0">Invoices are generated with UUID, ICV, previous-hash chain, UBL XML and QR code now. Cryptographic stamping and API clearance/reporting activate once the API configuration and certificate are added.</p>
      </div>
    </div>
  </div>
</div>
<?php
page_footer();

function status_badge(string $s): string
{
    $map = ['not_submitted' => 'secondary', 'pending' => 'warning', 'cleared' => 'success', 'reported' => 'success', 'rejected' => 'danger', 'failed' => 'danger'];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . h(str_replace('_', ' ', $s)) . '</span>';
}
