<?php
/**
 * ZATCA helpers: transaction code, QR (TLV), UBL 2.1 XML builder, invoice hash.
 * Cryptographic stamping (XAdES) and API submission are implemented later once
 * the CSID certificate is issued — see zatca.php (integration settings).
 */

const PIH_INITIAL = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

const VAT_CATEGORIES = ['S' => 'Standard rate', 'Z' => 'Zero rated', 'E' => 'Exempt', 'O' => 'Out of scope'];

const EXEMPTION_CODES = [
    'VATEX-SA-29'    => ['E', 'Financial services mentioned in Article 29 of the VAT Regulations'],
    'VATEX-SA-29-7'  => ['E', 'Life insurance services mentioned in Article 29 of the VAT Regulations'],
    'VATEX-SA-30'    => ['E', 'Real estate transactions mentioned in Article 30 of the VAT Regulations'],
    'VATEX-SA-32'    => ['Z', 'Export of goods'],
    'VATEX-SA-33'    => ['Z', 'Export of services'],
    'VATEX-SA-34-1'  => ['Z', 'The international transport of Goods'],
    'VATEX-SA-34-2'  => ['Z', 'international transport of passengers'],
    'VATEX-SA-34-3'  => ['Z', 'services directly connected and incidental to a Supply of international passenger transport'],
    'VATEX-SA-34-4'  => ['Z', 'Supply of a qualifying means of transport'],
    'VATEX-SA-34-5'  => ['Z', 'Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations'],
    'VATEX-SA-35'    => ['Z', 'Medicines and medical equipment'],
    'VATEX-SA-36'    => ['Z', 'Qualifying metals'],
    'VATEX-SA-EDU'   => ['Z', 'Private education to citizen'],
    'VATEX-SA-HEA'   => ['Z', 'Private healthcare to citizen'],
    'VATEX-SA-MLTRY' => ['Z', 'supply of qualified military goods'],
    'VATEX-SA-OOS'   => ['O', 'Not subject to VAT'],
];

const PAYMENT_MEANS = ['10' => 'Cash', '30' => 'Credit', '42' => 'Bank transfer', '48' => 'Bank card', '1' => 'Other'];

const NOTE_REASONS = [
    'Cancellation or suspension of the supplies after its occurrence either wholly or partially',
    'In case of essential change or amendment in the supply, which leads to the change of the VAT due',
    'Amendment of the supply value which is pre-agreed upon between the supplier and consumer',
    'In case of goods or services refund',
    'In case of change in Seller\'s or Buyer\'s information',
];

const SELLER_ID_SCHEMES = ['CRN' => 'Commercial Registration', 'MOM' => 'MOMRAH license', 'MLS' => 'MHRSD license', '700' => '700 Number', 'SAG' => 'MISA license', 'OTH' => 'Other'];
const BUYER_ID_SCHEMES  = ['TIN' => 'Tax Identification Number', 'CRN' => 'Commercial Registration', 'MOM' => 'MOMRAH license', 'MLS' => 'MHRSD license', '700' => '700 Number', 'SAG' => 'MISA license', 'NAT' => 'National ID', 'GCC' => 'GCC ID', 'IQA' => 'Iqama', 'PAS' => 'Passport', 'OTH' => 'Other'];

const DOC_TYPES = ['388' => 'Tax Invoice', '381' => 'Credit Note', '383' => 'Debit Note', '386' => 'Prepayment Invoice'];

function money($v): string { return number_format((float)$v, 2, '.', ''); }
function round2($v): float { return round((float)$v, 2, PHP_ROUND_HALF_UP); }
function xe($v): string { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function transaction_code(string $subtype, bool $thirdParty = false, bool $nominal = false, bool $export = false, bool $summary = false, bool $selfBilled = false): string
{
    return $subtype . ($thirdParty ? '1' : '0') . ($nominal ? '1' : '0') . ($export ? '1' : '0') . ($summary ? '1' : '0') . ($selfBilled ? '1' : '0');
}

function doc_title(array $inv): string
{
    $base = $inv['subtype'] === '02' ? 'Simplified Tax Invoice' : 'Tax Invoice';
    return match ($inv['type_code']) {
        '381' => $base . ' - Credit Note',
        '383' => $base . ' - Debit Note',
        '386' => 'Prepayment ' . $base,
        default => $base,
    };
}

/** Compute line + document totals. Lines: quantity, unit_price, discount, vat_rate. */
function compute_totals(array &$lines, float $docDiscount = 0.0): array
{
    $lineTotal = 0; $groups = [];
    foreach ($lines as &$l) {
        $l['vat_rate'] = $l['vat_category'] === 'S' ? (float)$l['vat_rate'] : 0.0;
        $net = round2(round2($l['quantity'] * $l['unit_price']) - (float)$l['discount']);
        $vat = round2($net * $l['vat_rate'] / 100);
        $l['line_net'] = $net; $l['line_vat'] = $vat; $l['line_total'] = round2($net + $vat);
        $lineTotal += $net;
        $k = $l['vat_category'] . '|' . money($l['vat_rate']);
        $groups[$k] = ($groups[$k] ?? 0) + $net;
    }
    unset($l);
    // Document-level discount applies to the standard-rated group (or first group if none).
    if ($docDiscount > 0) {
        $k = isset($groups['S|15.00']) ? 'S|15.00' : array_key_first($groups);
        $groups[$k] = round2($groups[$k] - $docDiscount);
    }
    $subtotals = []; $vatTotal = 0;
    foreach ($groups as $k => $taxable) {
        [$cat, $rate] = explode('|', $k);
        $vat = round2($taxable * (float)$rate / 100);
        $vatTotal += $vat;
        $subtotals[] = ['category' => $cat, 'rate' => (float)$rate, 'taxable' => round2($taxable), 'vat' => $vat];
    }
    $taxable = round2($lineTotal - $docDiscount);
    return [
        'line_total' => round2($lineTotal), 'doc_discount' => round2($docDiscount),
        'taxable_total' => $taxable, 'vat_total' => round2($vatTotal),
        'grand_total' => round2($taxable + $vatTotal), 'subtotals' => $subtotals,
    ];
}

/** TLV encode tags => base64 string (Security Features Standard §4). */
function qr_tlv(array $tags): string
{
    $out = '';
    foreach ($tags as $tag => $value) {
        $bytes = (string)$value;
        $out .= chr($tag) . chr(strlen($bytes)) . $bytes;
    }
    return base64_encode($out);
}

function build_qr(array $inv, array $seller, string $invoiceHash): string
{
    return qr_tlv([
        1 => $seller['seller_name'],
        2 => $seller['seller_vat'],
        3 => $inv['issue_date'] . 'T' . $inv['issue_time'] . 'Z',
        4 => money($inv['grand_total']),
        5 => money($inv['vat_total']),
        6 => $invoiceHash,
        // Tags 7-9 (signature, public key, CA stamp) are added once the CSID certificate is installed.
    ]);
}

/** Hash per BR-KSA-26: strip UBLExtensions, cac:Signature, QR reference → C14N → SHA-256 → base64. */
function invoice_hash(string $xml): string
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
    foreach (['//ext:UBLExtensions', '//cac:Signature', "//cac:AdditionalDocumentReference[cbc:ID='QR']"] as $q) {
        foreach ($xp->query($q) as $n) $n->parentNode->removeChild($n);
    }
    // NOTE: PHP's DOM offers C14N 1.0; ZATCA specifies C14N 1.1. They are equivalent for this document
    // shape (no xml:base / relative namespaces). Swap in a C14N11 implementation when signing is added.
    $canon = $dom->C14N(false, false);
    return base64_encode(hash('sha256', $canon, true));
}

/** Build UBL 2.1 Invoice XML. $qr = null → no QR element (used for hashing). */
function build_ubl(array $inv, array $lines, array $totals, array $seller, ?array $customer, ?string $qr): string
{
    $cur = $inv['currency'];
    $isStandard = $inv['subtype'] === '01';
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">' . "\n";
    $x .= "  <cbc:ProfileID>reporting:1.0</cbc:ProfileID>\n";
    $x .= "  <cbc:ID>" . xe($inv['invoice_number']) . "</cbc:ID>\n";
    $x .= "  <cbc:UUID>" . xe($inv['uuid']) . "</cbc:UUID>\n";
    $x .= "  <cbc:IssueDate>" . xe($inv['issue_date']) . "</cbc:IssueDate>\n";
    $x .= "  <cbc:IssueTime>" . xe($inv['issue_time']) . "</cbc:IssueTime>\n";
    $x .= "  <cbc:InvoiceTypeCode name=\"" . xe($inv['transaction_code']) . "\">" . xe($inv['type_code']) . "</cbc:InvoiceTypeCode>\n";
    if (!empty($inv['notes'])) $x .= "  <cbc:Note>" . xe($inv['notes']) . "</cbc:Note>\n";
    $x .= "  <cbc:DocumentCurrencyCode>" . xe($cur) . "</cbc:DocumentCurrencyCode>\n";
    $x .= "  <cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>\n";
    if (in_array($inv['type_code'], ['381', '383']) && !empty($inv['billing_reference'])) {
        $x .= "  <cac:BillingReference>\n    <cac:InvoiceDocumentReference>\n      <cbc:ID>" . xe($inv['billing_reference']) . "</cbc:ID>\n    </cac:InvoiceDocumentReference>\n  </cac:BillingReference>\n";
    }
    $x .= "  <cac:AdditionalDocumentReference>\n    <cbc:ID>ICV</cbc:ID>\n    <cbc:UUID>" . (int)$inv['icv'] . "</cbc:UUID>\n  </cac:AdditionalDocumentReference>\n";
    $x .= "  <cac:AdditionalDocumentReference>\n    <cbc:ID>PIH</cbc:ID>\n    <cac:Attachment>\n      <cbc:EmbeddedDocumentBinaryObject mimeCode=\"text/plain\">" . xe($inv['previous_hash']) . "</cbc:EmbeddedDocumentBinaryObject>\n    </cac:Attachment>\n  </cac:AdditionalDocumentReference>\n";
    if ($qr !== null) {
        $x .= "  <cac:AdditionalDocumentReference>\n    <cbc:ID>QR</cbc:ID>\n    <cac:Attachment>\n      <cbc:EmbeddedDocumentBinaryObject mimeCode=\"text/plain\">" . xe($qr) . "</cbc:EmbeddedDocumentBinaryObject>\n    </cac:Attachment>\n  </cac:AdditionalDocumentReference>\n";
    }
    // Seller
    $x .= "  <cac:AccountingSupplierParty>\n    <cac:Party>\n";
    $x .= "      <cac:PartyIdentification>\n        <cbc:ID schemeID=\"" . xe($seller['seller_id_scheme'] ?: 'CRN') . "\">" . xe($seller['seller_id_value']) . "</cbc:ID>\n      </cac:PartyIdentification>\n";
    $x .= address_xml($seller['street'], $seller['building_no'], $seller['district'], $seller['city'], $seller['postal_code'], $seller['country'] ?: 'SA', $seller['additional_no'] ?? '', $seller['province'] ?? '');
    $x .= "      <cac:PartyTaxScheme>\n        <cbc:CompanyID>" . xe($seller['seller_vat']) . "</cbc:CompanyID>\n        <cac:TaxScheme>\n          <cbc:ID>VAT</cbc:ID>\n        </cac:TaxScheme>\n      </cac:PartyTaxScheme>\n";
    $x .= "      <cac:PartyLegalEntity>\n        <cbc:RegistrationName>" . xe($seller['seller_name']) . "</cbc:RegistrationName>\n      </cac:PartyLegalEntity>\n";
    $x .= "    </cac:Party>\n  </cac:AccountingSupplierParty>\n";
    // Buyer
    $x .= "  <cac:AccountingCustomerParty>\n    <cac:Party>\n";
    if ($customer) {
        if (!empty($customer['id_value'])) {
            $x .= "      <cac:PartyIdentification>\n        <cbc:ID schemeID=\"" . xe($customer['id_scheme'] ?: 'OTH') . "\">" . xe($customer['id_value']) . "</cbc:ID>\n      </cac:PartyIdentification>\n";
        }
        if ($isStandard || !empty($customer['street'])) {
            $x .= address_xml($customer['street'], $customer['building_no'], $customer['district'], $customer['city'], $customer['postal_code'], $customer['country'] ?: 'SA');
        }
        if (!empty($customer['vat_number'])) {
            $x .= "      <cac:PartyTaxScheme>\n        <cbc:CompanyID>" . xe($customer['vat_number']) . "</cbc:CompanyID>\n        <cac:TaxScheme>\n          <cbc:ID>VAT</cbc:ID>\n        </cac:TaxScheme>\n      </cac:PartyTaxScheme>\n";
        }
        $x .= "      <cac:PartyLegalEntity>\n        <cbc:RegistrationName>" . xe($customer['name']) . "</cbc:RegistrationName>\n      </cac:PartyLegalEntity>\n";
    }
    $x .= "    </cac:Party>\n  </cac:AccountingCustomerParty>\n";
    if (!empty($inv['supply_date'])) {
        $x .= "  <cac:Delivery>\n    <cbc:ActualDeliveryDate>" . xe($inv['supply_date']) . "</cbc:ActualDeliveryDate>\n  </cac:Delivery>\n";
    }
    $x .= "  <cac:PaymentMeans>\n    <cbc:PaymentMeansCode>" . xe($inv['payment_means']) . "</cbc:PaymentMeansCode>\n";
    if (in_array($inv['type_code'], ['381', '383'])) $x .= "    <cbc:InstructionNote>" . xe($inv['note_reason']) . "</cbc:InstructionNote>\n";
    $x .= "  </cac:PaymentMeans>\n";
    if ($totals['doc_discount'] > 0) {
        $s = $totals['subtotals'][0];
        foreach ($totals['subtotals'] as $st) if ($st['category'] === 'S') { $s = $st; break; }
        $x .= "  <cac:AllowanceCharge>\n    <cbc:ChargeIndicator>false</cbc:ChargeIndicator>\n    <cbc:AllowanceChargeReasonCode>95</cbc:AllowanceChargeReasonCode>\n    <cbc:AllowanceChargeReason>Discount</cbc:AllowanceChargeReason>\n    <cbc:Amount currencyID=\"$cur\">" . money($totals['doc_discount']) . "</cbc:Amount>\n";
        $x .= "    <cac:TaxCategory>\n      <cbc:ID>" . $s['category'] . "</cbc:ID>\n      <cbc:Percent>" . money($s['rate']) . "</cbc:Percent>\n      <cac:TaxScheme>\n        <cbc:ID>VAT</cbc:ID>\n      </cac:TaxScheme>\n    </cac:TaxCategory>\n  </cac:AllowanceCharge>\n";
    }
    // Tax totals
    $x .= "  <cac:TaxTotal>\n    <cbc:TaxAmount currencyID=\"$cur\">" . money($totals['vat_total']) . "</cbc:TaxAmount>\n";
    foreach ($totals['subtotals'] as $s) {
        $x .= "    <cac:TaxSubtotal>\n      <cbc:TaxableAmount currencyID=\"$cur\">" . money($s['taxable']) . "</cbc:TaxableAmount>\n      <cbc:TaxAmount currencyID=\"$cur\">" . money($s['vat']) . "</cbc:TaxAmount>\n      <cac:TaxCategory>\n        <cbc:ID>" . $s['category'] . "</cbc:ID>\n        <cbc:Percent>" . money($s['rate']) . "</cbc:Percent>\n";
        if ($s['category'] !== 'S') {
            $code = ''; $text = '';
            foreach ($lines as $l) if ($l['vat_category'] === $s['category'] && !empty($l['exemption_code'])) { $code = $l['exemption_code']; $text = $l['exemption_text'] ?: (EXEMPTION_CODES[$code][1] ?? ''); break; }
            if ($code) $x .= "        <cbc:TaxExemptionReasonCode>" . xe($code) . "</cbc:TaxExemptionReasonCode>\n        <cbc:TaxExemptionReason>" . xe($text) . "</cbc:TaxExemptionReason>\n";
        }
        $x .= "        <cac:TaxScheme>\n          <cbc:ID>VAT</cbc:ID>\n        </cac:TaxScheme>\n      </cac:TaxCategory>\n    </cac:TaxSubtotal>\n";
    }
    $x .= "  </cac:TaxTotal>\n";
    $x .= "  <cac:TaxTotal>\n    <cbc:TaxAmount currencyID=\"SAR\">" . money($totals['vat_total']) . "</cbc:TaxAmount>\n  </cac:TaxTotal>\n";
    $x .= "  <cac:LegalMonetaryTotal>\n";
    $x .= "    <cbc:LineExtensionAmount currencyID=\"$cur\">" . money($totals['line_total']) . "</cbc:LineExtensionAmount>\n";
    $x .= "    <cbc:TaxExclusiveAmount currencyID=\"$cur\">" . money($totals['taxable_total']) . "</cbc:TaxExclusiveAmount>\n";
    $x .= "    <cbc:TaxInclusiveAmount currencyID=\"$cur\">" . money($totals['grand_total']) . "</cbc:TaxInclusiveAmount>\n";
    if ($totals['doc_discount'] > 0) $x .= "    <cbc:AllowanceTotalAmount currencyID=\"$cur\">" . money($totals['doc_discount']) . "</cbc:AllowanceTotalAmount>\n";
    $x .= "    <cbc:PrepaidAmount currencyID=\"$cur\">0.00</cbc:PrepaidAmount>\n";
    $x .= "    <cbc:PayableAmount currencyID=\"$cur\">" . money($totals['grand_total']) . "</cbc:PayableAmount>\n";
    $x .= "  </cac:LegalMonetaryTotal>\n";
    // Lines
    foreach ($lines as $i => $l) {
        $x .= "  <cac:InvoiceLine>\n    <cbc:ID>" . ($i + 1) . "</cbc:ID>\n";
        $x .= "    <cbc:InvoicedQuantity unitCode=\"PCE\">" . rtrim(rtrim(number_format((float)$l['quantity'], 4, '.', ''), '0'), '.') . "</cbc:InvoicedQuantity>\n";
        $x .= "    <cbc:LineExtensionAmount currencyID=\"$cur\">" . money($l['line_net']) . "</cbc:LineExtensionAmount>\n";
        if ((float)$l['discount'] > 0) {
            $x .= "    <cac:AllowanceCharge>\n      <cbc:ChargeIndicator>false</cbc:ChargeIndicator>\n      <cbc:AllowanceChargeReasonCode>95</cbc:AllowanceChargeReasonCode>\n      <cbc:AllowanceChargeReason>Discount</cbc:AllowanceChargeReason>\n      <cbc:Amount currencyID=\"$cur\">" . money($l['discount']) . "</cbc:Amount>\n    </cac:AllowanceCharge>\n";
        }
        $x .= "    <cac:TaxTotal>\n      <cbc:TaxAmount currencyID=\"$cur\">" . money($l['line_vat']) . "</cbc:TaxAmount>\n      <cbc:RoundingAmount currencyID=\"$cur\">" . money($l['line_total']) . "</cbc:RoundingAmount>\n    </cac:TaxTotal>\n";
        $x .= "    <cac:Item>\n      <cbc:Name>" . xe($l['description']) . "</cbc:Name>\n      <cac:ClassifiedTaxCategory>\n        <cbc:ID>" . $l['vat_category'] . "</cbc:ID>\n        <cbc:Percent>" . money($l['vat_rate']) . "</cbc:Percent>\n        <cac:TaxScheme>\n          <cbc:ID>VAT</cbc:ID>\n        </cac:TaxScheme>\n      </cac:ClassifiedTaxCategory>\n    </cac:Item>\n";
        $x .= "    <cac:Price>\n      <cbc:PriceAmount currencyID=\"$cur\">" . number_format((float)$l['unit_price'], 2, '.', '') . "</cbc:PriceAmount>\n    </cac:Price>\n";
        $x .= "  </cac:InvoiceLine>\n";
    }
    $x .= "</Invoice>\n";
    return $x;
}

function address_xml($street, $building, $district, $city, $postal, $country, $additional = '', $province = ''): string
{
    $x = "      <cac:PostalAddress>\n";
    if ($street !== '' && $street !== null) $x .= "        <cbc:StreetName>" . xe($street) . "</cbc:StreetName>\n";
    if ($building) $x .= "        <cbc:BuildingNumber>" . xe($building) . "</cbc:BuildingNumber>\n";
    if ($additional) $x .= "        <cbc:PlotIdentification>" . xe($additional) . "</cbc:PlotIdentification>\n";
    if ($district) $x .= "        <cbc:CitySubdivisionName>" . xe($district) . "</cbc:CitySubdivisionName>\n";
    if ($city) $x .= "        <cbc:CityName>" . xe($city) . "</cbc:CityName>\n";
    if ($postal) $x .= "        <cbc:PostalZone>" . xe($postal) . "</cbc:PostalZone>\n";
    if ($province) $x .= "        <cbc:CountrySubentity>" . xe($province) . "</cbc:CountrySubentity>\n";
    $x .= "        <cac:Country>\n          <cbc:IdentificationCode>" . xe($country) . "</cbc:IdentificationCode>\n        </cac:Country>\n      </cac:PostalAddress>\n";
    return $x;
}

function seller_settings(): array
{
    $keys = ['seller_name', 'seller_name_ar', 'seller_vat', 'seller_id_scheme', 'seller_id_value', 'street', 'building_no', 'additional_no', 'district', 'city', 'postal_code', 'province', 'country', 'phone', 'email'];
    $out = [];
    foreach ($keys as $k) $out[$k] = setting($k);
    if ($out['country'] === '') $out['country'] = 'SA';
    return $out;
}

function seller_configured(array $s): bool
{
    return $s['seller_name'] !== '' && preg_match('/^3\d{13}3$/', $s['seller_vat']) && $s['seller_id_value'] !== ''
        && $s['street'] !== '' && preg_match('/^\d{4}$/', $s['building_no']) && $s['district'] !== '' && $s['city'] !== '' && preg_match('/^\d{5}$/', $s['postal_code']);
}
