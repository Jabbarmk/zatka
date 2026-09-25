<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$st = db()->prepare("SELECT invoice_number, issue_date, issue_time, xml FROM invoices WHERE id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$inv = $st->fetch();
if (!$inv) { http_response_code(404); exit('Not found'); }

// File name per XML Implementation Standard §14: {SellerVAT}_{YYYYMMDD}T{HHMMSS}_{IRN}.xml
$name = setting('seller_vat') . '_' . str_replace('-', '', $inv['issue_date']) . 'T' . str_replace(':', '', $inv['issue_time']) . '_' . preg_replace('/[^A-Za-z0-9]/', '-', $inv['invoice_number']) . '.xml';
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
echo $inv['xml'];
