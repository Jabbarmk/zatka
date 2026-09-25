<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();
$seller = seller_settings();
$customers = $pdo->query("SELECT id, name, vat_number FROM customers ORDER BY name")->fetchAll();
$errors = [];
$prefill = ['type_code' => $_GET['type'] ?? '388', 'subtype' => $_GET['subtype'] ?? '01', 'billing_reference' => $_GET['ref'] ?? '', 'customer_id' => (int)($_GET['customer'] ?? 0)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!seller_configured($seller)) $errors[] = 'Company settings are incomplete. Fill them in before issuing invoices.';
    $type = $_POST['type_code'] ?? '388';
    $subtype = $_POST['subtype'] === '02' ? '02' : '01';
    if (!isset(DOC_TYPES[$type])) $errors[] = 'Invalid document type.';
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customer = null;
    if ($customerId) { $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?"); $st->execute([$customerId]); $customer = $st->fetch() ?: null; }
    if ($subtype === '01') {
        if (!$customer) $errors[] = 'Standard (B2B) invoices require a customer.';
        elseif (empty($customer['vat_number']) && empty($customer['id_value'])) $errors[] = 'B2B customer must have a VAT number or another ID.';
        elseif (empty($customer['street']) || empty($customer['city'])) $errors[] = 'B2B customer must have street and city.';
    }
    $supply = trim($_POST['supply_date'] ?? '');
    if ($subtype === '01' && $type === '388' && $supply === '') $errors[] = 'Supply date is required for standard tax invoices.';
    $billingRef = trim($_POST['billing_reference'] ?? '');
    $noteReason = trim($_POST['note_reason'] ?? '');
    if (in_array($type, ['381', '383'])) {
        if ($billingRef === '') $errors[] = 'Credit/debit notes must reference the original invoice number.';
        if ($noteReason === '') $errors[] = 'Credit/debit notes must have a reason.';
    }
    $flags = ['third_party', 'nominal', 'export', 'summary', 'self_billed'];
    $f = []; foreach ($flags as $k) $f[$k] = !empty($_POST[$k]);
    if ($subtype === '02' && ($f['export'] || $f['self_billed'])) $errors[] = 'Export and self-billed flags are not allowed on simplified invoices.';
    if ($f['export'] && $f['self_billed']) $errors[] = 'Self-billing is not allowed on export invoices.';
    if ($f['export'] && $customer && !empty($customer['vat_number'])) $errors[] = 'Export invoices must not carry a buyer VAT number.';

    $lines = [];
    foreach ($_POST['line'] ?? [] as $l) {
        if (trim($l['description'] ?? '') === '') continue;
        $cat = in_array($l['vat_category'] ?? 'S', ['S', 'Z', 'E', 'O']) ? $l['vat_category'] : 'S';
        $code = $cat === 'S' ? null : ($l['exemption_code'] ?? null);
        if ($cat !== 'S' && (!$code || (EXEMPTION_CODES[$code][0] ?? '') !== $cat)) $errors[] = 'Line "' . $l['description'] . '": exemption reason required for category ' . $cat . '.';
        $lines[] = [
            'description' => trim($l['description']), 'quantity' => (float)($l['quantity'] ?? 0), 'unit_price' => (float)($l['unit_price'] ?? 0),
            'discount' => (float)($l['discount'] ?? 0), 'vat_category' => $cat, 'vat_rate' => (float)($l['vat_rate'] ?? DEFAULT_VAT_RATE),
            'exemption_code' => $code, 'exemption_text' => $code ? (EXEMPTION_CODES[$code][1] ?? '') : null,
        ];
    }
    if (!$lines) $errors[] = 'At least one line item is required.';
    foreach ($lines as $l) if ($l['quantity'] <= 0 || $l['unit_price'] < 0 || $l['discount'] < 0) $errors[] = 'Quantities must be positive; prices and discounts cannot be negative.';
    $docDiscount = max(0, (float)($_POST['doc_discount'] ?? 0));

    if (!$errors) {
        $totals = compute_totals($lines, $docDiscount);
        if ($totals['taxable_total'] < 0) $errors[] = 'Document discount exceeds the line total.';
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $unit = $pdo->query("SELECT * FROM egs_units ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
            $icv = (int)$unit['last_icv'] + 1;
            $prefix = ['388' => 'INV', '381' => 'CRN', '383' => 'DBN', '386' => 'ADV'][$type];
            $year = date('Y');
            $seq = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE type_code = '$type' AND invoice_number LIKE '$prefix-$year-%'")->fetchColumn() + 1;
            $inv = [
                'egs_unit_id' => $unit['id'], 'invoice_number' => sprintf('%s-%s-%06d', $prefix, $year, $seq), 'uuid' => uuid4(),
                'type_code' => $type, 'subtype' => $subtype,
                'transaction_code' => transaction_code($subtype, $f['third_party'], $f['nominal'], $f['export'], $f['summary'], $f['self_billed']),
                'customer_id' => $customer['id'] ?? null, 'issue_date' => date('Y-m-d'), 'issue_time' => date('H:i:s'),
                'supply_date' => $supply ?: null, 'payment_means' => isset(PAYMENT_MEANS[$_POST['payment_means'] ?? '']) ? $_POST['payment_means'] : '10',
                'billing_reference' => $billingRef ?: null, 'note_reason' => $noteReason ?: null,
                'doc_discount' => $totals['doc_discount'], 'line_total' => $totals['line_total'], 'taxable_total' => $totals['taxable_total'],
                'vat_total' => $totals['vat_total'], 'grand_total' => $totals['grand_total'], 'currency' => 'SAR',
                'notes' => trim($_POST['notes'] ?? '') ?: null, 'icv' => $icv, 'previous_hash' => $unit['last_hash'],
            ];
            $xmlNoQr = build_ubl($inv, $lines, $totals, $seller, $customer, null);
            $hash = invoice_hash($xmlNoQr);
            $qr = build_qr($inv, $seller, $hash);
            $xml = build_ubl($inv, $lines, $totals, $seller, $customer, $qr);
            $inv['invoice_hash'] = $hash; $inv['qr_base64'] = $qr; $inv['xml'] = $xml; $inv['created_by'] = $_SESSION['user_id'];
            $cols = array_keys($inv);
            $pdo->prepare("INSERT INTO invoices (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")")->execute($inv);
            $invoiceId = (int)$pdo->lastInsertId();
            $ls = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_price, discount, vat_category, vat_rate, exemption_code, exemption_text, line_net, line_vat, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($lines as $i => $l) $ls->execute([$invoiceId, $i + 1, $l['description'], $l['quantity'], $l['unit_price'], $l['discount'], $l['vat_category'], $l['vat_rate'], $l['exemption_code'], $l['exemption_text'], $l['line_net'], $l['line_vat'], $l['line_total']]);
            $pdo->prepare("UPDATE egs_units SET last_icv = ?, last_hash = ? WHERE id = ?")->execute([$icv, $hash, $unit['id']]);
            $pdo->commit();
            audit('invoice.issued', ['id' => $invoiceId, 'number' => $inv['invoice_number'], 'icv' => $icv, 'hash' => $hash]);
            flash('Invoice ' . $inv['invoice_number'] . ' issued.');
            header('Location: invoice_view.php?id=' . $invoiceId); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not issue invoice: ' . $e->getMessage();
        }
    }
    $prefill = ['type_code' => $type, 'subtype' => $subtype, 'billing_reference' => $billingRef, 'customer_id' => $customerId];
}

page_header('New Invoice', 'new');
?>
<h3 class="mb-4">New Document</h3>
<?php if (!seller_configured($seller)): ?><div class="alert alert-warning">Company settings are incomplete — <a href="settings.php" class="alert-link">complete them</a> before issuing invoices.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
<form method="post" id="invoice-form"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" id="default-rate" value="<?= DEFAULT_VAT_RATE ?>">
<script type="application/json" id="exemption-data"><?= json_encode(EXEMPTION_CODES) ?></script>
<div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-3">
  <div class="col-md-3"><label class="form-label">Document type</label><select class="form-select" name="type_code" id="type_code"><?php foreach (DOC_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $prefill['type_code'] === $k ? 'selected' : '' ?>><?= $v ?> (<?= $k ?>)</option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Invoice subtype</label><select class="form-select" name="subtype" id="subtype"><option value="01" <?= $prefill['subtype'] === '01' ? 'selected' : '' ?>>Standard (B2B) — clearance</option><option value="02" <?= $prefill['subtype'] === '02' ? 'selected' : '' ?>>Simplified (B2C) — reporting</option></select></div>
  <div class="col-md-4"><label class="form-label">Customer</label><select class="form-select" name="customer_id" id="customer_id"><option value="">— Walk-in / none (B2C only) —</option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= $prefill['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['vat_number'] ? ' (' . h($c['vat_number']) . ')' : '' ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Payment means</label><select class="form-select" name="payment_means"><?php foreach (PAYMENT_MEANS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Supply date</label><input type="date" class="form-control" name="supply_date" id="supply_date" value="<?= date('Y-m-d') ?>"></div>
  <div class="col-md-3 note-only"><label class="form-label">Original invoice number</label><input class="form-control" name="billing_reference" value="<?= h($prefill['billing_reference']) ?>"></div>
  <div class="col-md-6 note-only"><label class="form-label">Reason for credit/debit note</label><select class="form-select" name="note_reason"><?php foreach (NOTE_REASONS as $r): ?><option><?= h($r) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><div class="d-flex flex-wrap gap-3 small">
    <?php foreach (['third_party' => 'Third-party billed', 'nominal' => 'Nominal supply', 'export' => 'Export', 'summary' => 'Summary invoice', 'self_billed' => 'Self-billed'] as $k => $v): ?>
    <label class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1"> <?= $v ?></label><?php endforeach; ?></div></div>
</div></div></div>

<div class="card shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead class="table-light"><tr><th style="min-width:220px">Description</th><th style="width:90px">Qty</th><th style="width:110px">Unit price</th><th style="width:100px">Discount</th><th style="width:130px">VAT cat.</th><th style="width:80px">Rate %</th><th style="min-width:200px">Exemption reason</th><th class="text-end">Net</th><th class="text-end">VAT</th><th class="text-end">Total</th><th></th></tr></thead>
<tbody id="lines"></tbody></table></div>
<div class="p-2"><button type="button" class="btn btn-sm btn-outline-primary" id="add-line"><i class="bi bi-plus"></i> Add line</button></div></div></div>
<template id="line-template"><td><input class="form-control" name="line[__N__][description]" required></td>
<td><input class="form-control qty" type="number" step="any" min="0.0001" name="line[__N__][quantity]" value="1"></td>
<td><input class="form-control price" type="number" step="0.01" min="0" name="line[__N__][unit_price]" value="0.00"></td>
<td><input class="form-control disc" type="number" step="0.01" min="0" name="line[__N__][discount]" value="0.00"></td>
<td><select class="form-select vat-cat" name="line[__N__][vat_category]"><?php foreach (VAT_CATEGORIES as $k => $v): ?><option value="<?= $k ?>"><?= $k ?> - <?= $v ?></option><?php endforeach; ?></select></td>
<td><input class="form-control vat-rate" type="number" step="0.01" name="line[__N__][vat_rate]" value="<?= DEFAULT_VAT_RATE ?>"></td>
<td><select class="form-select exemption" name="line[__N__][exemption_code]"></select></td>
<td class="text-end line-net">0.00</td><td class="text-end line-vat">0.00</td><td class="text-end line-total">0.00</td>
<td><button type="button" class="btn btn-sm btn-link text-danger remove-line"><i class="bi bi-x-lg"></i></button></td></template>

<div class="row g-3 mb-3">
  <div class="col-md-7"><div class="card shadow-sm h-100"><div class="card-body"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3"></textarea></div></div></div>
  <div class="col-md-5"><div class="card shadow-sm h-100"><div class="card-body">
    <table class="table table-sm mb-0"><tr><td>Line total (net)</td><td class="text-end" id="t-net">0.00</td></tr>
    <tr><td>Document discount</td><td class="text-end"><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="doc_discount" id="doc_discount" value="0.00" style="width:120px;display:inline-block"></td></tr>
    <tr><td>Taxable amount</td><td class="text-end" id="t-taxable">0.00</td></tr>
    <tr><td>VAT</td><td class="text-end" id="t-vat">0.00</td></tr>
    <tr class="fw-bold"><td>Total (incl. VAT)</td><td class="text-end" id="t-total">0.00</td></tr></table>
  </div></div></div>
</div>
<button class="btn btn-primary btn-lg"><i class="bi bi-check2-circle me-1"></i>Issue document</button>
<div class="form-text mt-2">Issued documents are immutable (ZATCA requirement). Corrections must be made with a credit or debit note.</div>
</form>
<?php page_footer(); ?>
