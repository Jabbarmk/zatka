<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? '';
$sql = "SELECT i.*, c.name customer_name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE 1";
$params = [];
if ($q !== '') { $sql .= " AND (i.invoice_number LIKE ? OR c.name LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($type !== '' && isset(DOC_TYPES[$type])) { $sql .= " AND i.type_code = ?"; $params[] = $type; }
$sql .= " ORDER BY i.id DESC LIMIT 200";
$st = db()->prepare($sql); $st->execute($params); $rows = $st->fetchAll();

page_header('Invoices', 'invoices');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h3 class="mb-0">Invoices</h3><a href="invoice_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Invoice</a></div>
<form class="row g-2 mb-3"><div class="col-md-4"><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search number or customer"></div>
<div class="col-md-3"><select class="form-select" name="type"><option value="">All types</option><?php foreach (DOC_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div></form>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Number</th><th>Type</th><th>Customer</th><th>Issued</th><th class="text-end">Taxable</th><th class="text-end">VAT</th><th class="text-end">Total</th><th>ICV</th><th>ZATCA</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No invoices found.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><a href="invoice_view.php?id=<?= $r['id'] ?>"><?= h($r['invoice_number']) ?></a></td>
<td><?= h(DOC_TYPES[$r['type_code']]) ?> <span class="badge bg-light text-dark border"><?= $r['subtype'] === '02' ? 'Simplified' : 'Standard' ?></span></td>
<td><?= h($r['customer_name'] ?? '—') ?></td><td><?= h($r['issue_date']) ?> <span class="text-muted small"><?= h($r['issue_time']) ?></span></td>
<td class="text-end"><?= number_format($r['taxable_total'], 2) ?></td><td class="text-end"><?= number_format($r['vat_total'], 2) ?></td><td class="text-end fw-semibold"><?= number_format($r['grand_total'], 2) ?></td>
<td><?= (int)$r['icv'] ?></td><td><span class="badge bg-secondary"><?= h(str_replace('_', ' ', $r['zatca_status'])) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_footer(); ?>
