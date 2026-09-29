<?php
/**
 * Demo data for the billing screens. Command line only.
 *
 *   php tools/seed_demo.php           add demo customers and documents
 *   php tools/seed_demo.php --reset   remove the demo data and reset the invoice counter
 *
 * Documents are built exactly like real ones (XML, hash chain, QR) but are never signed or sent to ZATCA.
 * Every demo record is marked, and --reset refuses to run once real documents exist.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only'); }
chdir(dirname(__DIR__));
require_once 'includes/db.php';
require_once 'includes/zatca.php';

const DEMO_NOTE = 'Demo data';
const DEMO_EMAIL_DOMAIN = '@demo.example';
$pdo = db();

$realDocs = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes IS NULL OR notes <> " . $pdo->quote(DEMO_NOTE))->fetchColumn();
$accepted = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE zatca_status IN ('cleared','reported')")->fetchColumn();

if (in_array('--reset', $argv, true)) {
    if ($realDocs || $accepted) exit("Refused: the database contains real documents ($realDocs) or documents accepted by ZATCA ($accepted). Nothing was deleted.\n");
    $pdo->exec("DELETE FROM invoice_lines");
    $n = $pdo->exec("DELETE FROM invoices");
    $pdo->exec("DELETE FROM zatca_logs WHERE invoice_id IS NOT NULL");
    $c = $pdo->exec("DELETE FROM customers WHERE email LIKE '%" . DEMO_EMAIL_DOMAIN . "'");
    $pdo->prepare("UPDATE egs_units SET last_icv = 0, last_hash = ?")->execute([PIH_INITIAL]);
    $pdo->exec("ALTER TABLE invoices AUTO_INCREMENT = 1");
    if (setting('demo_seller') === '1') {
        save_settings(array_fill_keys(['seller_name', 'seller_name_ar', 'seller_vat', 'seller_id_scheme', 'seller_id_value', 'street', 'building_no', 'additional_no', 'district', 'city', 'postal_code', 'province', 'phone', 'email', 'demo_seller'], ''));
        echo "Demo company details cleared.\n";
    }
    exit("Removed $n demo documents and $c demo customers. Invoice counter reset to 0.\n");
}

if ($realDocs || $accepted) exit("Refused: real documents already exist. Demo data would be mixed into your real invoice sequence.\n");
if (setting('zatca_enabled') === '1' && setting('zatca_env') === 'production') exit("Refused: the production ZATCA integration is enabled.\n");
if ((int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn() > 0) exit("Demo data is already present. Run with --reset first to start again.\n");

if (!seller_configured(seller_settings())) {
    save_settings(['seller_name' => 'Demo Trading Company', 'seller_name_ar' => 'شركة العرض التجارية', 'seller_vat' => '399999999900003', 'seller_id_scheme' => 'CRN', 'seller_id_value' => '1010010000',
        'street' => 'King Fahd Road', 'building_no' => '2322', 'additional_no' => '6789', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12211', 'province' => 'Riyadh Region', 'country' => 'SA',
        'phone' => '+966 11 000 0000', 'email' => 'billing@demo.example', 'demo_seller' => '1']);
    echo "Company settings were empty: demo company details added.\n";
}
$seller = seller_settings();

mt_srand(20260929);
$customers = [
    ['Al Noor Building Materials Co.', '310175397400003', 'CRN', '1010234567', 'Olaya Street', '7234', 'Al Olaya', 'Riyadh', '12213'],
    ['Gulf Horizon Retail LLC', '311298765400003', 'CRN', '4030112233', 'Prince Sultan Road', '3120', 'Al Rawdah', 'Jeddah', '23435'],
    ['Desert Tech Solutions', '300987654300003', 'CRN', '2050998877', 'King Saud Street', '4455', 'Al Shati', 'Dammam', '32413'],
    ['Najd Catering Services', '310456789100003', 'CRN', '1010556677', 'Takhassusi Street', '8810', 'Al Mohammadiyah', 'Riyadh', '12363'],
    ['Red Sea Logistics Est.', '311222333400003', 'CRN', '4030778899', 'Al Madinah Road', '6012', 'Al Hamra', 'Jeddah', '23323'],
    ['Eastern Province Clinics', '300111222300003', 'CRN', '2051003344', 'Dhahran Street', '2290', 'Al Khobar Al Shamalia', 'Al Khobar', '34427'],
    ['Mohammed Al Harbi', '', 'NAT', '1098765432', 'Al Urubah Road', '5501', 'Al Wurud', 'Riyadh', '12251'],
    ['Sara Al Qahtani', '', 'IQA', '2345678901', 'Tahlia Street', '1207', 'Al Andalus', 'Jeddah', '23326'],
];
$ins = $pdo->prepare("INSERT INTO customers (name, vat_number, id_scheme, id_value, street, building_no, district, city, postal_code, country, phone, email) VALUES (?,?,?,?,?,?,?,?,?,'SA',?,?)");
$customerRows = [];
foreach ($customers as $i => $c) {
    $ins->execute([$c[0], $c[1] ?: null, $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $c[8], '+966 5' . mt_rand(10000000, 99999999), 'customer' . ($i + 1) . DEMO_EMAIL_DOMAIN]);
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?"); $st->execute([$pdo->lastInsertId()]);
    $customerRows[] = $st->fetch();
}
$business = array_values(array_filter($customerRows, fn($c) => !empty($c['vat_number'])));

$catalog = [
    ['Laptop 14" business series', 3450], ['Wireless keyboard and mouse set', 189], ['27" monitor', 1150], ['Office chair, ergonomic', 780],
    ['Network switch, 24 port', 1640], ['Printer toner cartridge', 265], ['Annual software licence', 2400], ['On-site installation service', 600],
    ['Maintenance contract, monthly', 950], ['Consulting, per hour', 350], ['Coffee, cup', 14], ['Sandwich', 22], ['Bottled water, carton', 18],
    ['USB-C cable', 35], ['Phone case', 49], ['Screen protector', 29],
];
$zeroRated = [['Export freight service', 1800, 'Z', 'VATEX-SA-34-1'], ['Medicines, qualifying', 420, 'Z', 'VATEX-SA-35'], ['Export of goods', 5200, 'Z', 'VATEX-SA-32']];

// 48 documents spread over the last 45 days, oldest first so numbers, counter and hash chain follow the dates.
$plan = [];
for ($i = 0; $i < 48; $i++) $plan[] = ['day' => mt_rand(0, 45), 'time' => sprintf('%02d:%02d:%02d', mt_rand(8, 20), mt_rand(0, 59), mt_rand(0, 59))];
usort($plan, fn($a, $b) => [$b['day'], $a['time']] <=> [$a['day'], $b['time']]);

$pdo->beginTransaction();
$unit = $pdo->query("SELECT * FROM egs_units ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
$icv = (int)$unit['last_icv']; $pih = $unit['last_hash'];
$seq = ['388' => 0, '381' => 0, '383' => 0, '386' => 0];
$issued = ['01' => [], '02' => []];
$count = ['388' => 0, '381' => 0, '383' => 0];
$insLine = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_price, discount, vat_category, vat_rate, exemption_code, exemption_text, line_net, line_vat, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

foreach ($plan as $n => $p) {
    $date = date('Y-m-d', strtotime('-' . $p['day'] . ' day'));
    $sub = mt_rand(1, 100) <= 45 ? '01' : '02';
    $type = '388';
    if ($n > 8 && $issued[$sub] && mt_rand(1, 100) <= 12) $type = mt_rand(1, 100) <= 75 ? '381' : '383';
    $customer = $sub === '01' ? $business[array_rand($business)] : (mt_rand(1, 100) <= 25 ? $customerRows[mt_rand(6, 7)] : null);

    $lines = [];
    $ref = null;
    if ($type === '388') {
        $items = $sub === '01' ? array_slice($catalog, 0, 10) : array_slice($catalog, 10);
        foreach ((array)array_rand($items, mt_rand(1, 3)) as $k) {
            $qty = $sub === '01' ? mt_rand(1, 6) : mt_rand(1, 4);
            $lines[] = ['description' => $items[$k][0], 'quantity' => $qty, 'unit_price' => $items[$k][1], 'discount' => mt_rand(1, 100) <= 20 ? round($items[$k][1] * $qty * 0.05, 2) : 0,
                'vat_category' => 'S', 'vat_rate' => 15, 'exemption_code' => null, 'exemption_text' => null];
        }
        if ($sub === '01' && mt_rand(1, 100) <= 15) {
            $z = $zeroRated[array_rand($zeroRated)];
            $lines[] = ['description' => $z[0], 'quantity' => 1, 'unit_price' => $z[1], 'discount' => 0, 'vat_category' => $z[2], 'vat_rate' => 0, 'exemption_code' => $z[3], 'exemption_text' => EXEMPTION_CODES[$z[3]][1]];
        }
    } else {
        $orig = $issued[$sub][array_rand($issued[$sub])];
        $ref = $orig['number'];
        $customer = $orig['customer'];
        $lines[] = ['description' => ($type === '381' ? 'Return: ' : 'Additional charge: ') . $orig['item'], 'quantity' => 1, 'unit_price' => $orig['price'], 'discount' => 0,
            'vat_category' => 'S', 'vat_rate' => 15, 'exemption_code' => null, 'exemption_text' => null];
    }
    $docDiscount = $type === '388' && $sub === '01' && mt_rand(1, 100) <= 15 ? 50.0 : 0.0;
    $totals = compute_totals($lines, $docDiscount);

    $prefix = ['388' => 'INV', '381' => 'CRN', '383' => 'DBN'][$type];
    $inv = [
        'egs_unit_id' => $unit['id'], 'invoice_number' => sprintf('%s-%s-%06d', $prefix, substr($date, 0, 4), ++$seq[$type]), 'uuid' => uuid4(),
        'type_code' => $type, 'subtype' => $sub, 'transaction_code' => transaction_code($sub), 'customer_id' => $customer['id'] ?? null,
        'issue_date' => $date, 'issue_time' => $p['time'], 'supply_date' => $sub === '01' ? $date : null, 'payment_means' => ['10', '48', '42', '30'][mt_rand(0, 3)],
        'billing_reference' => $ref, 'note_reason' => $ref ? NOTE_REASONS[$type === '381' ? 3 : 2] : null,
        'doc_discount' => $totals['doc_discount'], 'line_total' => $totals['line_total'], 'taxable_total' => $totals['taxable_total'], 'vat_total' => $totals['vat_total'], 'grand_total' => $totals['grand_total'],
        'currency' => 'SAR', 'notes' => DEMO_NOTE, 'icv' => ++$icv, 'previous_hash' => $pih,
    ];
    $tags = qr_base_tags($inv, $seller);
    $hash = invoice_hash(build_ubl($inv, $lines, $totals, $seller, $customer, qr_tlv($tags)));
    $inv['qr_base64'] = qr_tlv($tags + [6 => $hash]);
    $inv['xml'] = build_ubl($inv, $lines, $totals, $seller, $customer, $inv['qr_base64']);
    $inv['invoice_hash'] = $hash;
    $inv['created_at'] = "$date {$p['time']}";
    $cols = array_keys($inv);
    $pdo->prepare("INSERT INTO invoices (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")")->execute($inv);
    $id = (int)$pdo->lastInsertId();
    foreach ($lines as $i => $l) $insLine->execute([$id, $i + 1, $l['description'], $l['quantity'], $l['unit_price'], $l['discount'], $l['vat_category'], $l['vat_rate'], $l['exemption_code'], $l['exemption_text'], $l['line_net'], $l['line_vat'], $l['line_total']]);
    if ($type === '388') $issued[$sub][] = ['number' => $inv['invoice_number'], 'customer' => $customer, 'item' => $lines[0]['description'], 'price' => $lines[0]['unit_price']];
    $pih = $hash;
    $count[$type]++;
}
$pdo->prepare("UPDATE egs_units SET last_icv = ?, last_hash = ? WHERE id = ?")->execute([$icv, $pih, $unit['id']]);
$pdo->commit();
audit('demo.seeded', $count);

echo "Added " . count($customerRows) . " customers and " . array_sum($count) . " documents: {$count['388']} invoices, {$count['381']} credit notes, {$count['383']} debit notes.\n";
echo "Invoice counter is now $icv. Remove everything with: php tools/seed_demo.php --reset\n";
