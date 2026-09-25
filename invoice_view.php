<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT i.*, c.name customer_name, c.vat_number customer_vat, c.id_scheme, c.id_value, c.street c_street, c.building_no c_building, c.district c_district, c.city c_city, c.postal_code c_postal, c.country c_country FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = ?");
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) { http_response_code(404); exit('Invoice not found'); }
$lines = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_no"); $lines->execute([$id]); $lines = $lines->fetchAll();
$seller = seller_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    csrf_check();
    if (setting('zatca_enabled') !== '1' || setting('zatca_production_cert') === '') {
        flash('ZATCA integration is not configured yet. Add the API configuration and certificate under ZATCA Integration.', 'warning');
    } else {
        flash('Submission module is not activated yet. The document is queued as pending.', 'info');
        $pdo->prepare("UPDATE invoices SET zatca_status = 'pending' WHERE id = ?")->execute([$id]);
        audit('invoice.queued', $inv['invoice_number']);
    }
    header("Location: invoice_view.php?id=$id"); exit;
}

$isSimplified = $inv['subtype'] === '02';
$subtotals = [];
foreach ($lines as $l) { $k = $l['vat_category'] . '|' . money($l['vat_rate']); $subtotals[$k] = ($subtotals[$k] ?? 0) + $l['line_net']; }

page_header($inv['invoice_number'], 'invoices');
?>
<div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
  <h3 class="mb-0"><?= h($inv['invoice_number']) ?> <span class="badge bg-secondary fs-6"><?= h(str_replace('_', ' ', $inv['zatca_status'])) ?></span></h3>
  <div class="btn-group">
    <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    <a class="btn btn-outline-secondary" href="invoice_xml.php?id=<?= $id ?>"><i class="bi bi-filetype-xml me-1"></i>Download XML</a>
    <?php if ($inv['type_code'] === '388'): ?>
    <a class="btn btn-outline-secondary" href="invoice_form.php?type=381&subtype=<?= $inv['subtype'] ?>&ref=<?= urlencode($inv['invoice_number']) ?>&customer=<?= (int)$inv['customer_id'] ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Credit note</a>
    <a class="btn btn-outline-secondary" href="invoice_form.php?type=383&subtype=<?= $inv['subtype'] ?>&ref=<?= urlencode($inv['invoice_number']) ?>&customer=<?= (int)$inv['customer_id'] ?>"><i class="bi bi-arrow-clockwise me-1"></i>Debit note</a>
    <?php endif; ?>
    <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="submit">
      <button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i><?= $isSimplified ? 'Report to ZATCA' : 'Clear with ZATCA' ?></button></form>
  </div>
</div>

<div class="invoice-doc shadow-sm mx-auto">
  <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
    <div>
      <h4><?= h(doc_title($inv)) ?></h4>
      <div class="text-ar fs-5"><?= $isSimplified ? 'فاتورة ضريبية مبسطة' : 'فاتورة ضريبية' ?><?= $inv['type_code'] === '381' ? ' - إشعار دائن' : ($inv['type_code'] === '383' ? ' - إشعار مدين' : '') ?></div>
      <?php if (substr($inv['transaction_code'], 6, 1) === '1'): ?><div class="fw-semibold">Self-billed Tax Invoice</div><?php endif; ?>
      <div class="mt-2 small">
        <div><strong>Invoice No:</strong> <?= h($inv['invoice_number']) ?></div>
        <div><strong>Issue date/time:</strong> <?= h($inv['issue_date']) ?> <?= h($inv['issue_time']) ?></div>
        <?php if ($inv['supply_date']): ?><div><strong>Supply date:</strong> <?= h($inv['supply_date']) ?></div><?php endif; ?>
        <?php if ($inv['billing_reference']): ?><div><strong>Original invoice:</strong> <?= h($inv['billing_reference']) ?> — <?= h($inv['note_reason']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="text-center"><div id="qrcode"></div><div class="small text-muted mt-1">Scan to verify</div></div>
  </div>

  <div class="row mb-3 small">
    <div class="col-6">
      <div class="text-muted">Seller / البائع</div>
      <div class="fw-semibold"><?= h($seller['seller_name']) ?></div>
      <?php if ($seller['seller_name_ar']): ?><div class="text-ar"><?= h($seller['seller_name_ar']) ?></div><?php endif; ?>
      <div><?= h($seller['building_no']) ?> <?= h($seller['street']) ?>, <?= h($seller['district']) ?>, <?= h($seller['city']) ?> <?= h($seller['postal_code']) ?>, <?= h($seller['country']) ?></div>
      <div>VAT No: <?= h($seller['seller_vat']) ?> &nbsp; <?= h($seller['seller_id_scheme'] ?: 'CRN') ?>: <?= h($seller['seller_id_value']) ?></div>
    </div>
    <div class="col-6">
      <div class="text-muted">Buyer / المشتري</div>
      <?php if ($inv['customer_name']): ?>
        <div class="fw-semibold"><?= h($inv['customer_name']) ?></div>
        <?php if ($inv['c_street']): ?><div><?= h($inv['c_building']) ?> <?= h($inv['c_street']) ?>, <?= h($inv['c_district']) ?>, <?= h($inv['c_city']) ?> <?= h($inv['c_postal']) ?>, <?= h($inv['c_country']) ?></div><?php endif; ?>
        <?php if ($inv['customer_vat']): ?><div>VAT No: <?= h($inv['customer_vat']) ?></div><?php endif; ?>
        <?php if ($inv['id_value']): ?><div><?= h($inv['id_scheme']) ?>: <?= h($inv['id_value']) ?></div><?php endif; ?>
      <?php else: ?><div class="text-muted">Walk-in customer</div><?php endif; ?>
    </div>
  </div>

  <table class="table table-bordered table-sm">
    <thead class="table-light"><tr><th>#</th><th>Description / الوصف</th><th class="text-end">Unit price</th><th class="text-end">Qty</th><th class="text-end">Discount</th><th class="text-end">Taxable</th><th class="text-end">VAT %</th><th class="text-end">VAT</th><th class="text-end">Total incl. VAT</th></tr></thead>
    <tbody><?php foreach ($lines as $l): ?>
      <tr><td><?= $l['line_no'] ?></td><td><?= h($l['description']) ?><?= $l['exemption_code'] ? '<div class="small text-muted">' . h($l['exemption_code']) . ' - ' . h($l['exemption_text']) . '</div>' : '' ?></td>
      <td class="text-end"><?= number_format($l['unit_price'], 2) ?></td><td class="text-end"><?= rtrim(rtrim(number_format($l['quantity'], 4), '0'), '.') ?></td><td class="text-end"><?= number_format($l['discount'], 2) ?></td>
      <td class="text-end"><?= number_format($l['line_net'], 2) ?></td><td class="text-end"><?= number_format($l['vat_rate'], 0) ?>% <?= $l['vat_category'] ?></td><td class="text-end"><?= number_format($l['line_vat'], 2) ?></td><td class="text-end"><?= number_format($l['line_total'], 2) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>

  <div class="row">
    <div class="col-7 small">
      <?php if ($inv['notes']): ?><div class="mb-2"><strong>Notes:</strong> <?= nl2br(h($inv['notes'])) ?></div><?php endif; ?>
      <div><strong>Payment:</strong> <?= h(PAYMENT_MEANS[$inv['payment_means']] ?? $inv['payment_means']) ?></div>
      <?php foreach ($subtotals as $k => $amt): [$cat, $rate] = explode('|', $k); if ($cat !== 'S'): ?>
        <div class="text-muted">Tax treatment: <?= h(VAT_CATEGORIES[$cat]) ?> (<?= $cat ?>) applied to SAR <?= number_format($amt, 2) ?></div>
      <?php endif; endforeach; ?>
    </div>
    <div class="col-5">
      <table class="table table-sm mb-0">
        <tr><td>Total (excl. VAT) / الإجمالي</td><td class="text-end"><?= number_format($inv['line_total'], 2) ?></td></tr>
        <?php if ($inv['doc_discount'] > 0): ?><tr><td>Discount / الخصم</td><td class="text-end">-<?= number_format($inv['doc_discount'], 2) ?></td></tr><?php endif; ?>
        <tr><td>Taxable amount / المبلغ الخاضع للضريبة</td><td class="text-end"><?= number_format($inv['taxable_total'], 2) ?></td></tr>
        <tr><td>Total VAT / ضريبة القيمة المضافة</td><td class="text-end"><?= number_format($inv['vat_total'], 2) ?></td></tr>
        <tr class="fw-bold table-light"><td>Total incl. VAT / الإجمالي شامل الضريبة</td><td class="text-end">SAR <?= number_format($inv['grand_total'], 2) ?></td></tr>
      </table>
      <div class="small text-muted text-end">Amount includes VAT / المبلغ يشمل ضريبة القيمة المضافة</div>
    </div>
  </div>

  <div class="mt-4 pt-2 border-top small text-muted d-print-none">
    <div>UUID: <code><?= h($inv['uuid']) ?></code> &nbsp; ICV: <code><?= (int)$inv['icv'] ?></code> &nbsp; Transaction code: <code><?= h($inv['transaction_code']) ?></code></div>
    <div>Invoice hash: <code class="text-break"><?= h($inv['invoice_hash']) ?></code></div>
    <div>Previous hash: <code class="text-break"><?= h($inv['previous_hash']) ?></code></div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qrcode'), { text: <?= json_encode($inv['qr_base64']) ?>, width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M });</script>
<?php page_footer(); ?>
