<?php
/**
 * ZATCA onboarding + cryptographic stamp: key pair, CSR, CSID requests, XAdES signing, QR tags 7-9.
 */
require_once __DIR__ . '/db.php';

const ZATCA_ENV_URLS = [
    'sandbox'    => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
    'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
    'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
];
const ZATCA_CSR_TEMPLATES = ['sandbox' => 'TSTZATCA-Code-Signing', 'simulation' => 'PREZATCA-Code-Signing', 'production' => 'ZATCA-Code-Signing'];
const SECRET_SETTINGS = ['zatca_private_key', 'zatca_compliance_secret', 'zatca_production_secret'];

/* ---------- secrets at rest ---------- */

function app_key(): string
{
    $dir = __DIR__ . '/../storage';
    $file = $dir . '/app.key';
    if (!is_file($file)) {
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
        file_put_contents($file, base64_encode(random_bytes(32)));
    }
    return base64_decode(trim(file_get_contents($file)));
}

function secret_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $ct);
}

function secret_decrypt(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4));
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function secret_setting(string $key): string { return secret_decrypt(setting($key)); }

/* ---------- key pair + CSR ---------- */

function openssl_cnf(): string
{
    foreach ([getenv('OPENSSL_CONF'), 'C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf', '/usr/lib/ssl/openssl.cnf'] as $p) {
        if ($p && is_file($p)) return $p;
    }
    return '';
}

function zatca_generate_keypair(): array
{
    $cfg = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1'];
    if (openssl_cnf()) $cfg['config'] = openssl_cnf();
    $key = openssl_pkey_new($cfg);
    if (!$key || !openssl_pkey_export($key, $pem, null, $cfg)) throw new RuntimeException('Key generation failed: ' . openssl_error_string());
    return ['private' => $pem, 'public' => openssl_pkey_get_details($key)['key']];
}

/** $f: common_name, serial, vat, org_unit, org_name, country, invoice_type, location, industry */
function zatca_generate_csr(string $privatePem, array $f, string $env): string
{
    $esc = fn($v) => str_replace(["\r", "\n"], ' ', (string)$v);
    $conf = "oid_section = OIDs\n[OIDs]\ncertificateTemplateName = 1.3.6.1.4.1.311.20.2\n"
        . "[req]\ndefault_md = sha256\nprompt = no\nutf8 = yes\nstring_mask = utf8only\ndistinguished_name = dn\nreq_extensions = v3_req\n"
        . "[dn]\nC = " . $esc($f['country']) . "\nOU = " . $esc($f['org_unit']) . "\nO = " . $esc($f['org_name']) . "\nCN = " . $esc($f['common_name']) . "\n"
        // ZATCA rejects CSRs that carry any extension besides the template name and the SAN.
        . "[v3_req]\n"
        . "1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:" . ZATCA_CSR_TEMPLATES[$env] . "\nsubjectAltName = dirName:alt_names\n"
        . "[alt_names]\nSN = " . $esc($f['serial']) . "\nUID = " . $esc($f['vat']) . "\ntitle = " . $esc($f['invoice_type']) . "\nregisteredAddress = " . $esc($f['location']) . "\nbusinessCategory = " . $esc($f['industry']) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'zcsr');
    file_put_contents($tmp, $conf);
    try {
        $dn = ['countryName' => $f['country'], 'organizationalUnitName' => $f['org_unit'], 'organizationName' => $f['org_name'], 'commonName' => $f['common_name']];
        $csr = openssl_csr_new($dn, $privatePem, ['config' => $tmp, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req', 'string_mask' => 'utf8only']);
        if (!$csr || !openssl_csr_export($csr, $out)) throw new RuntimeException('CSR generation failed: ' . openssl_error_string());
        return $out;
    } finally {
        @unlink($tmp);
    }
}

/** Details of a stored CSR for display: subject fields and the public key it was made for. */
function csr_info(string $csrPem): ?array
{
    $subject = @openssl_csr_get_subject($csrPem, false);
    $key = @openssl_csr_get_public_key($csrPem);
    if (!$subject || !$key) return null;
    $d = openssl_pkey_get_details($key);
    return [
        'subject' => $subject,
        'curve' => $d['ec']['curve_name'] ?? '',
        'bits' => $d['bits'] ?? 0,
        'public_key_der' => base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $d['key'])),
    ];
}

/* ---------- HTTP ---------- */

function zatca_http(string $method, string $url, array $body, array $headers = [], ?array $auth = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json', 'Accept-Version: V2', 'Accept-Language: en'], $headers);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15];
    if ($auth) $opts[CURLOPT_USERPWD] = $auth[0] . ':' . $auth[1];
    $ca = ini_get('curl.cainfo') ?: 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $start = microtime(true);
    $resp = curl_exec($ch);
    $out = ['http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($resp) ? $resp : null, 'error' => curl_error($ch), 'ms' => (int)round((microtime(true) - $start) * 1000)];
    curl_close($ch);
    $out['json'] = $out['body'] !== null ? json_decode($out['body'], true) : null;
    return $out;
}

function zatca_messages(?array $json): array
{
    $msgs = [];
    foreach (['errorMessages' => 'ERROR', 'warningMessages' => 'WARNING'] as $k => $label) {
        foreach ($json['validationResults'][$k] ?? [] as $m) $msgs[] = $label . ' ' . ($m['code'] ?? '') . ': ' . ($m['message'] ?? '');
    }
    foreach ($json['errors'] ?? [] as $m) $msgs[] = 'ERROR ' . (is_array($m) ? (($m['code'] ?? '') . ': ' . ($m['message'] ?? json_encode($m))) : $m);
    if (!$msgs && !empty($json['message'])) $msgs[] = (string)$json['message'];
    return $msgs;
}

/* ---------- certificate helpers ---------- */

/** binarySecurityToken → base64 DER body (the string placed in ds:X509Certificate). */
function cert_body(string $token): string
{
    $token = trim($token);
    if (str_contains($token, 'BEGIN CERTIFICATE')) return preg_replace('/-----[^-]+-----|\s+/', '', $token);
    $decoded = base64_decode($token, true);
    // A token is base64(base64(DER)); a raw certificate body is base64(DER), which starts with the DER SEQUENCE byte 0x30.
    return ($decoded !== false && $decoded !== '' && $decoded[0] !== "\x30") ? preg_replace('/\s+/', '', $decoded) : preg_replace('/\s+/', '', $token);
}

function cert_pem(string $body): string
{
    return "-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----\n";
}

function hex_to_dec(string $hex): string
{
    $dec = '0';
    foreach (str_split(strtolower(ltrim($hex, '0')) ?: '0') as $c) {
        $carry = hexdec($c); $res = '';
        for ($i = strlen($dec) - 1; $i >= 0; $i--) { $v = (int)$dec[$i] * 16 + $carry; $res = ($v % 10) . $res; $carry = intdiv($v, 10); }
        while ($carry > 0) { $res = ($carry % 10) . $res; $carry = intdiv($carry, 10); }
        $dec = ltrim($res, '0') ?: '0';
    }
    return $dec;
}

function der_read(string $der, int $pos): array
{
    $len = ord($der[$pos + 1]); $hdr = 2;
    if ($len & 0x80) { $n = $len & 0x7f; $len = 0; for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($der[$pos + 2 + $i]); $hdr = 2 + $n; }
    return ['tag' => ord($der[$pos]), 'start' => $pos + $hdr, 'len' => $len, 'end' => $pos + $hdr + $len];
}

function cert_info(string $token): array
{
    $body = cert_body($token);
    $pem = cert_pem($body);
    $x = openssl_x509_parse($pem);
    if (!$x) throw new RuntimeException('The certificate (CSID) could not be parsed.');
    $parts = [];
    foreach ($x['issuer'] as $k => $v) foreach ((array)$v as $item) $parts[] = "$k=$item";
    $pubPem = openssl_pkey_get_details(openssl_pkey_get_public($pem))['key'];
    $der = base64_decode($body);
    $outer = der_read($der, 0);
    $tbs = der_read($der, $outer['start']);
    $alg = der_read($der, $tbs['end']);
    $sig = der_read($der, $alg['end']);
    return [
        'body' => $body, 'pem' => $pem,
        'issuer' => implode(', ', array_reverse($parts)),
        'serial' => hex_to_dec($x['serialNumberHex']),
        'public_key_der' => base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pubPem)),
        'signature' => substr($der, $sig['start'] + 1, $sig['len'] - 1),
        'valid_to' => date('Y-m-d H:i:s', $x['validTo_time_t']),
        'subject' => $x['name'] ?? '',
    ];
}

function key_matches_cert(string $privatePem, array $cert): bool
{
    $k = openssl_pkey_get_private($privatePem);
    if (!$k) return false;
    $pub = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($k)['key']));
    return hash_equals($cert['public_key_der'], $pub);
}

/* ---------- signing ---------- */

function ubl_extension_template(): string
{
    $i = fn(int $n) => str_repeat(' ', $n);
    return "<ext:UBLExtensions>\n"
        . $i(4) . "<ext:UBLExtension>\n"
        . $i(8) . "<ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>\n"
        . $i(8) . "<ext:ExtensionContent>\n"
        . $i(12) . "<sig:UBLDocumentSignatures xmlns:sig=\"urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2\" xmlns:sac=\"urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2\" xmlns:sbc=\"urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2\">\n"
        . $i(16) . "<sac:SignatureInformation>\n"
        . $i(20) . "<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>\n"
        . $i(20) . "<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>\n"
        . $i(20) . "<ds:Signature xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\" Id=\"signature\">\n"
        . $i(24) . "<ds:SignedInfo>\n"
        . $i(28) . "<ds:CanonicalizationMethod Algorithm=\"http://www.w3.org/2006/12/xml-c14n11\"/>\n"
        . $i(28) . "<ds:SignatureMethod Algorithm=\"http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256\"/>\n"
        . $i(28) . "<ds:Reference Id=\"invoiceSignedData\" URI=\"\">\n"
        . $i(32) . "<ds:Transforms>\n"
        . $i(36) . "<ds:Transform Algorithm=\"http://www.w3.org/TR/1999/REC-xpath-19991116\">\n" . $i(40) . "<ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>\n" . $i(36) . "</ds:Transform>\n"
        . $i(36) . "<ds:Transform Algorithm=\"http://www.w3.org/TR/1999/REC-xpath-19991116\">\n" . $i(40) . "<ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>\n" . $i(36) . "</ds:Transform>\n"
        . $i(36) . "<ds:Transform Algorithm=\"http://www.w3.org/TR/1999/REC-xpath-19991116\">\n" . $i(40) . "<ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>\n" . $i(36) . "</ds:Transform>\n"
        . $i(36) . "<ds:Transform Algorithm=\"http://www.w3.org/2006/12/xml-c14n11\"/>\n"
        . $i(32) . "</ds:Transforms>\n"
        . $i(32) . "<ds:DigestMethod Algorithm=\"http://www.w3.org/2001/04/xmlenc#sha256\"/>\n"
        . $i(32) . "<ds:DigestValue>@@INVOICE_HASH@@</ds:DigestValue>\n"
        . $i(28) . "</ds:Reference>\n"
        . $i(28) . "<ds:Reference Type=\"http://www.w3.org/2000/09/xmldsig#SignatureProperties\" URI=\"#xadesSignedProperties\">\n"
        . $i(32) . "<ds:DigestMethod Algorithm=\"http://www.w3.org/2001/04/xmlenc#sha256\"/>\n"
        . $i(32) . "<ds:DigestValue>@@SP_HASH@@</ds:DigestValue>\n"
        . $i(28) . "</ds:Reference>\n"
        . $i(24) . "</ds:SignedInfo>\n"
        . $i(24) . "<ds:SignatureValue>@@SIGNATURE@@</ds:SignatureValue>\n"
        . $i(24) . "<ds:KeyInfo>\n" . $i(28) . "<ds:X509Data>\n" . $i(32) . "<ds:X509Certificate>@@CERT@@</ds:X509Certificate>\n" . $i(28) . "</ds:X509Data>\n" . $i(24) . "</ds:KeyInfo>\n"
        . $i(24) . "<ds:Object>\n"
        . $i(28) . "<xades:QualifyingProperties xmlns:xades=\"http://uri.etsi.org/01903/v1.3.2#\" Target=\"signature\">\n"
        . $i(32) . "<xades:SignedProperties Id=\"xadesSignedProperties\">\n"
        . $i(36) . "<xades:SignedSignatureProperties>\n"
        . $i(40) . "<xades:SigningTime>@@SIGN_TIME@@</xades:SigningTime>\n"
        . $i(40) . "<xades:SigningCertificate>\n"
        . $i(44) . "<xades:Cert>\n"
        . $i(48) . "<xades:CertDigest>\n"
        . $i(52) . "<ds:DigestMethod Algorithm=\"http://www.w3.org/2001/04/xmlenc#sha256\"/>\n"
        . $i(52) . "<ds:DigestValue>@@CERT_HASH@@</ds:DigestValue>\n"
        . $i(48) . "</xades:CertDigest>\n"
        . $i(48) . "<xades:IssuerSerial>\n"
        . $i(52) . "<ds:X509IssuerName>@@ISSUER@@</ds:X509IssuerName>\n"
        . $i(52) . "<ds:X509SerialNumber>@@SERIAL@@</ds:X509SerialNumber>\n"
        . $i(48) . "</xades:IssuerSerial>\n"
        . $i(44) . "</xades:Cert>\n"
        . $i(40) . "</xades:SigningCertificate>\n"
        . $i(36) . "</xades:SignedSignatureProperties>\n"
        . $i(32) . "</xades:SignedProperties>\n"
        . $i(28) . "</xades:QualifyingProperties>\n"
        . $i(24) . "</ds:Object>\n"
        . $i(20) . "</ds:Signature>\n"
        . $i(16) . "</sac:SignatureInformation>\n"
        . $i(12) . "</sig:UBLDocumentSignatures>\n"
        . $i(8) . "</ext:ExtensionContent>\n"
        . $i(4) . "</ext:UBLExtension>\n"
        . "</ext:UBLExtensions>";
}

/**
 * Add the cryptographic stamp to an unsigned document produced by build_ubl().
 * The invoice hash is unchanged by signing. Returns ['xml', 'hash', 'qr', 'signature'].
 */
function zatca_sign_document(string $unsigned, string $certToken, string $privatePem, bool $simplified, bool $checkKey = true): array
{
    if ($privatePem === '') throw new RuntimeException('private key is missing');
    if (str_contains($unsigned, '<ds:Signature')) throw new RuntimeException('document is already signed');
    $cert = cert_info($certToken);
    if ($checkKey && !key_matches_cert($privatePem, $cert)) throw new RuntimeException('private key does not belong to the certificate');
    if (!preg_match('#(<cac:AdditionalDocumentReference>\s*<cbc:ID>QR</cbc:ID>.*?<cbc:EmbeddedDocumentBinaryObject[^>]*>)([^<]*)(</cbc:EmbeddedDocumentBinaryObject>.*?</cac:AdditionalDocumentReference>)#s', $unsigned, $m)) {
        throw new RuntimeException('QR element not found');
    }
    $qrBase = array_intersect_key(qr_decode($m[2]), [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1]);
    $sigElement = "<cac:Signature>\n    <cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID>\n    <cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod>\n  </cac:Signature>";
    $template = str_replace($m[0], $m[1] . '@@QR@@' . $m[3] . $sigElement, $unsigned);
    // Inserted elements bring no whitespace outside themselves, so the canonical form of the rest is untouched.
    $pos = strpos($template, '  <cbc:ProfileID>');
    $template = substr($template, 0, $pos) . ubl_extension_template() . substr($template, $pos);

    $hashRaw = invoice_hash_raw($unsigned);
    if (!hash_equals($hashRaw, invoice_hash_raw($template))) throw new RuntimeException('hash changed while inserting the signature');
    $hash = base64_encode($hashRaw);
    if (!openssl_sign($hashRaw, $sigRaw, $privatePem, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signing failed: ' . openssl_error_string());
    $signature = base64_encode($sigRaw);

    $xml = strtr($template, [
        '@@INVOICE_HASH@@' => $hash, '@@SIGNATURE@@' => $signature, '@@CERT@@' => $cert['body'],
        '@@SIGN_TIME@@' => date('Y-m-d\TH:i:s'), '@@CERT_HASH@@' => base64_encode(hash('sha256', $cert['body'])),
        '@@ISSUER@@' => htmlspecialchars($cert['issuer'], ENT_XML1), '@@SERIAL@@' => $cert['serial'],
    ]);

    // Signed properties digest. ZATCA hashes the element as serialized text: namespaces declared where first used,
    // <ds:DigestMethod/> self-closed, whitespace as in the document. Verified against the ZATCA sandbox:
    // plain exclusive or inclusive C14N is rejected with "signed-properties-hashing". Do not re-indent the template.
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
    $sp = $xp->query('//xades:SignedProperties')->item(0);
    $serialized = str_replace('></ds:DigestMethod>', '/>', $sp->C14N(true, false));
    $spHash = base64_encode(hash('sha256', $serialized));

    $tags = $qrBase + [6 => $hash, 7 => $signature, 8 => $cert['public_key_der']];
    ksort($tags);
    if ($simplified) $tags[9] = $cert['signature'];
    $qr = qr_tlv($tags);

    $xml = strtr($xml, ['@@SP_HASH@@' => $spHash, '@@QR@@' => $qr]);
    return ['xml' => $xml, 'hash' => $hash, 'qr' => $qr, 'signature' => $signature];
}

/* ---------- configuration helpers ---------- */

function zatca_env(): string
{
    $e = setting('zatca_env', 'sandbox');
    return isset(ZATCA_ENV_URLS[$e]) ? $e : 'sandbox';
}

function zatca_base_url(): string
{
    return rtrim(setting('zatca_base_url', ZATCA_ENV_URLS[zatca_env()]), '/');
}

/** Certificate + key used to stamp documents, or null when signing is not possible yet. */
function zatca_signing_ready(): ?array
{
    $cert = setting('zatca_production_cert');
    $key = secret_setting('zatca_private_key');
    if ($cert === '' || $key === '') return null;
    try {
        $info = cert_info($cert);
    } catch (Throwable $e) {
        return null;
    }
    $matches = key_matches_cert($key, $info);
    // The sandbox hands out a fixed demo certificate that never matches the generated key, and accepts it anyway.
    if (!$matches && zatca_env() !== 'sandbox') return null;
    return ['cert' => $cert, 'key' => $key, 'info' => $info, 'key_matches' => $matches];
}

function zatca_sign_with_settings(string $unsignedXml, bool $simplified): array
{
    $s = zatca_signing_ready();
    if (!$s) throw new RuntimeException('no valid certificate and private key are installed');
    return zatca_sign_document($unsignedXml, $s['cert'], $s['key'], $simplified, $s['key_matches']);
}

function redact_secrets(?string $body): ?string
{
    $j = $body !== null ? json_decode($body, true) : null;
    if (!is_array($j)) return $body;
    foreach (['secret'] as $k) if (isset($j[$k])) $j[$k] = '[hidden]';
    return json_encode($j, JSON_UNESCAPED_SLASHES);
}

/* ---------- onboarding ---------- */

function zatca_csr_fields(): array
{
    $seller = seller_settings();
    return [
        'common_name' => setting('csr_common_name'),
        'serial' => '1-' . setting('csr_serial_vendor') . '|2-' . setting('csr_serial_model') . '|3-' . setting('csr_serial_number'),
        'vat' => $seller['seller_vat'], 'org_unit' => setting('csr_org_unit'), 'org_name' => $seller['seller_name'], 'country' => 'SA',
        'invoice_type' => setting('csr_invoice_type', '1100'), 'location' => setting('csr_location'), 'industry' => setting('csr_industry'),
    ];
}

function zatca_onboarding_problems(): array
{
    $p = [];
    if (!seller_configured(seller_settings())) $p[] = 'Company Settings are incomplete.';
    $labels = ['csr_common_name' => 'Common name', 'csr_serial_vendor' => 'Solution vendor', 'csr_serial_model' => 'Model / version', 'csr_serial_number' => 'Serial number',
        'csr_org_unit' => 'Organization unit', 'csr_location' => 'Location', 'csr_industry' => 'Industry'];
    foreach ($labels as $k => $label) if (setting($k) === '') $p[] = "$label is required.";
    if (!preg_match('/^[01]{4}$/', setting('csr_invoice_type', '1100'))) $p[] = 'Invoice types must be 4 digits of 0/1, e.g. 1100.';
    // For VAT groups (11th digit of the VAT number is 1) the organization unit must be the member's 10-digit TIN.
    $vat = seller_settings()['seller_vat'];
    if (strlen($vat) === 15 && $vat[10] === '1' && !preg_match('/^\d{10}$/', setting('csr_org_unit'))) $p[] = 'VAT group: Organization unit must be the 10-digit TIN of the group member.';
    return $p;
}

/** Sample documents for the compliance checks, built from the real seller data. They are not stored as invoices. */
function zatca_compliance_samples(string $invoiceTypes): array
{
    $seller = seller_settings();
    $buyer = ['name' => 'Compliance Test Buyer', 'vat_number' => '399999999800003', 'id_scheme' => '', 'id_value' => '', 'street' => 'Salah Al-Din', 'building_no' => '1111', 'district' => 'Al-Murooj', 'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA'];
    $subtypes = [];
    if ($invoiceTypes[0] === '1') $subtypes[] = '01';
    if ($invoiceTypes[1] === '1') $subtypes[] = '02';
    $out = []; $pih = PIH_INITIAL; $n = 0;
    foreach ($subtypes as $sub) {
        foreach (['388', '381', '383'] as $type) {
            $lines = [['description' => 'Compliance test item', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'vat_category' => 'S', 'vat_rate' => 15, 'exemption_code' => null, 'exemption_text' => null]];
            $totals = compute_totals($lines, 0);
            $inv = ['invoice_number' => 'CHK-' . date('Ymd') . '-' . sprintf('%03d', ++$n), 'uuid' => uuid4(), 'type_code' => $type, 'subtype' => $sub, 'transaction_code' => transaction_code($sub),
                'issue_date' => date('Y-m-d'), 'issue_time' => date('H:i:s'), 'supply_date' => date('Y-m-d'), 'payment_means' => '10',
                'billing_reference' => $type === '388' ? null : 'CHK-' . date('Ymd') . '-001', 'note_reason' => $type === '388' ? null : NOTE_REASONS[3],
                'currency' => 'SAR', 'notes' => null, 'icv' => $n, 'previous_hash' => $pih] + $totals;
            $xml = build_ubl($inv, $lines, $totals, $seller, $sub === '01' ? $buyer : null, qr_tlv(qr_base_tags($inv, $seller)));
            $pih = invoice_hash($xml);
            $out[] = ['inv' => $inv, 'xml' => $xml, 'label' => ($sub === '01' ? 'Standard ' : 'Simplified ') . strtolower(DOC_TYPES[$type])];
        }
    }
    return $out;
}

/**
 * Full onboarding: key pair → CSR → compliance CSID → compliance checks → production CSID.
 * Returns a list of steps: ['step', 'ok', 'message']. Stops at the first failure.
 */
function zatca_onboard(string $otp): array
{
    $steps = [];
    $env = zatca_env();
    $base = zatca_base_url();
    $add = function (string $step, bool $ok, string $msg) use (&$steps) { $steps[] = ['step' => $step, 'ok' => $ok, 'message' => $msg]; return $ok; };
    $log = fn(string $action, string $endpoint, array $r, bool $ok, ?string $ref = null, ?array $req = null) => zatca_log([
        'invoice_number' => $ref, 'action' => $action, 'environment' => $env, 'endpoint' => $endpoint, 'http_status' => $r['http'] ?: null,
        'result' => $ok ? 'success' : 'error', 'zatca_status' => $r['json']['dispositionMessage'] ?? $r['json']['clearanceStatus'] ?? $r['json']['reportingStatus'] ?? null,
        'message' => implode("\n", zatca_messages($r['json'])) ?: ($ok ? 'OK' : ($r['error'] ?: trim(substr((string)$r['body'], 0, 300)) ?: 'HTTP ' . $r['http'])),
        'request_body' => $req ? json_encode($req, JSON_PRETTY_PRINT) : null, 'response_body' => redact_secrets($r['body']), 'duration_ms' => $r['ms'],
    ]);

    $problems = zatca_onboarding_problems();
    if (!preg_match('/^\d{6}$/', $otp)) $problems[] = 'OTP must be the 6-digit code from the Fatoora portal.';
    if ($problems) { $add('Check details', false, implode(' ', $problems)); return $steps; }

    try {
        $kp = zatca_generate_keypair();
        $csr = zatca_generate_csr($kp['private'], zatca_csr_fields(), $env);
    } catch (Throwable $e) {
        $add('Generate key and CSR', false, $e->getMessage()); return $steps;
    }
    // Kept even if a later step fails, so the request that was sent can be inspected from the dashboard.
    save_settings(['zatca_csr' => $csr, 'zatca_csr_at' => date('Y-m-d H:i:s'), 'zatca_csr_env' => $env]);
    $add('Generate key and CSR', true, 'New private key and certificate request created.');

    $url = "$base/compliance";
    $r = zatca_http('POST', $url, ['csr' => base64_encode($csr)], ['OTP: ' . $otp]);
    $ok = $r['http'] === 200 && !empty($r['json']['binarySecurityToken']);
    $log('compliance_csid', $url, $r, $ok, null, ['csr' => '[base64 CSR]']);
    if (!$add('Request compliance certificate', $ok, $ok ? 'Issued, request ID ' . $r['json']['requestID'] . '.' : 'ZATCA refused the request (HTTP ' . $r['http'] . '). Check the OTP and unit details; see the response log.')) return $steps;
    $cc = ['token' => $r['json']['binarySecurityToken'], 'secret' => $r['json']['secret'], 'request_id' => (string)$r['json']['requestID']];

    $url = "$base/compliance/invoices";
    $failed = [];
    foreach (zatca_compliance_samples(setting('csr_invoice_type', '1100')) as $s) {
        $signed = zatca_sign_document($s['xml'], $cc['token'], $kp['private'], $s['inv']['subtype'] === '02');
        $req = ['invoiceHash' => $signed['hash'], 'uuid' => $s['inv']['uuid'], 'invoice' => base64_encode($signed['xml'])];
        $r = zatca_http('POST', $url, $req, [], [$cc['token'], $cc['secret']]);
        $ok = in_array($r['http'], [200, 202], true);
        $req['invoice'] = '[base64 XML, ' . strlen($req['invoice']) . ' chars]';
        $log('compliance_check', $url, $r, $ok, $s['inv']['invoice_number'] . ' ' . $s['label'], $req);
        if (!$ok) $failed[] = $s['label'];
    }
    if (!$add('Compliance checks', !$failed, $failed ? 'Failed: ' . implode(', ', $failed) . '. See the response log for the rule codes.' : 'All sample documents passed.')) return $steps;

    $url = "$base/production/csids";
    $r = zatca_http('POST', $url, ['compliance_request_id' => $cc['request_id']], [], [$cc['token'], $cc['secret']]);
    $ok = $r['http'] === 200 && !empty($r['json']['binarySecurityToken']);
    $log('production_csid', $url, $r, $ok, null, ['compliance_request_id' => $cc['request_id']]);
    if (!$add('Request production certificate', $ok, $ok ? 'Issued.' : 'ZATCA refused the request (HTTP ' . $r['http'] . '); see the response log.')) return $steps;

    save_settings([
        'zatca_private_key' => secret_encrypt($kp['private']), 'zatca_csr' => $csr,
        'zatca_compliance_cert' => $cc['token'], 'zatca_compliance_secret' => secret_encrypt($cc['secret']), 'zatca_compliance_request_id' => $cc['request_id'],
        'zatca_production_cert' => $r['json']['binarySecurityToken'], 'zatca_production_secret' => secret_encrypt($r['json']['secret']),
        'zatca_enabled' => '1', 'zatca_otp' => '', 'zatca_onboarded_at' => date('Y-m-d H:i:s'),
    ]);
    $ready = zatca_signing_ready();
    if (!$ready) $add('Save certificate', false, 'Saved, but the certificate returned by ZATCA does not belong to the generated key, so documents cannot be signed. Run the onboarding again with a new OTP.');
    else $add('Save certificate', true, 'Certificate, secret and key saved. Integration is enabled.' . ($ready['key_matches'] ? '' : ' Note: the sandbox issues a fixed demo certificate; this is normal for testing only.'));
    return $steps;
}
