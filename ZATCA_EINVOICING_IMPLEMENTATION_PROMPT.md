# ZATCA E-Invoicing (Fatoora) Implementation Prompt

> Copy everything below the line into your AI coding agent (Claude Code, Cursor, etc.) inside your invoicing application repo.
> Fill in the `[APP CONTEXT]` block first. Everything else is derived from the four official ZATCA documents:
> - Controls, Requirements, Technical Specifications and Procedural Rules (Resolution, Annex 1 & 2)
> - Security Features Implementation Standards v1.2
> - Electronic Invoice XML Implementation Standard v1.2 (2023-05-19)
> - E-Invoice Data Dictionary v1.2 (2023-05-19)

---

## ROLE

You are a senior engineer implementing **ZATCA (Saudi Arabia) e-invoicing compliance — Phase 1 (Generation) and Phase 2 (Integration)** inside an existing invoicing application. Follow the specification below exactly. Where the spec says "verify", check against the current ZATCA Developer Portal / SDK before finalizing, and make the value configurable.

## [APP CONTEXT] — FILL IN BEFORE RUNNING

```
Stack / language:            e.g. PHP 8.x + MySQL (XAMPP)  |  Laravel  |  Node  |  .NET
Existing invoice tables:     e.g. invoices, invoice_items, customers, company_settings
Existing invoice number fmt: e.g. INV-2025-000123
Multi-branch / multi-device: yes/no  (each branch device = one EGS unit)
Document types you issue:    [ ] B2B Standard Tax Invoices   [ ] B2C Simplified Tax Invoices
                             [ ] Credit Notes   [ ] Debit Notes   [ ] Prepayment (advance) invoices
Currency:                    SAR only / multi-currency
PDF generation library:      e.g. dompdf / TCPDF / wkhtmltopdf
Where secrets are stored:    e.g. .env + encrypted DB column
```

Before writing code: explore the repo, map the existing invoice/customer/company models to the ZATCA field list in Section 6, and present a short gap list (missing fields, missing tables). Then implement in the order of Section 3.

---

## 1. WHAT ZATCA REQUIRES (executive summary)

| Concept | Requirement |
|---|---|
| Formats | XML (UBL 2.1, mandatory for generation + transmission). PDF/A-3 with embedded XML is optional for sharing with customers. |
| Document types | Tax Invoice (B2B, code 388, subtype 01), Simplified Tax Invoice (B2C, 388/02), Debit Note (383), Credit Note (381), Prepayment Invoice (386). Notes must mirror the type of the invoice they correct. |
| Standard (B2B) invoices | **Clearance**: send to ZATCA in real time *before* sharing with the buyer. ZATCA validates, stamps, and returns the cleared XML. You share the cleared XML/PDF. |
| Simplified (B2C) invoices | **Reporting**: your EGS stamps it locally (XAdES ECDSA signature), prints QR immediately, and reports the XML to ZATCA **within 24 hours**. |
| Security features (all types) | UUID, tamper-resistant Invoice Counter Value (ICV), Previous Invoice Hash (PIH) chain, QR code (TLV/Base64), cryptographic stamp (B2C only; ZATCA stamps B2B). |
| EGS unit | Each "unit" (device/branch/instance) has exactly **one** invoice sequence, one key pair, one Cryptographic Stamp Identifier (X.509 certificate = CSID). Never more than one sequence per unit. |
| Prohibited | Anonymous access / default passwords / no session mgmt; altering or deleting issued invoices or logs; inaccurate timestamps; counter reset; multiple sequences; exporting the private key; user-modifiable clock/timestamp. |
| Archival | Keep XML for the legal retention period; export offline with file name `{SellerVAT}_{YYYYMMDD}T{HHMMSS}_{IRN}.xml`. Solution must keep working offline and queue for later reporting. |
| Connectivity | TLS. OAuth2-style **HTTP Basic Auth**: username = Base64 CSID certificate (binarySecurityToken), password = secret issued at onboarding. |

Out of scope of e-invoicing: fully exempt supplies, reverse-charge purchases, imports.

---

## 2. TARGET ARCHITECTURE (build these modules)

```
/zatca
  ├─ Egs/            EGS unit registry, key generation, CSR, CSID storage, onboarding
  ├─ Ubl/            UBL 2.1 XML builder (Invoice / Credit / Debit / Prepayment)
  ├─ Rules/          Validation engine (BR-*, BR-KSA-*, calculations, rounding)
  ├─ Crypto/         Canonicalization (C14N11), SHA-256 hash, PIH, XAdES signer, QR TLV
  ├─ Api/            ZATCA client: compliance, production CSID, clearance, reporting, retry queue
  ├─ Pdf/            Human-readable invoice (AR/EN) + PDF/A-3 with embedded XML (optional)
  ├─ Storage/        Immutable XML archive, export, audit log
  └─ Admin/          Onboarding UI, EGS status, queue monitor, error viewer
```

Sequence of operations for **every** document (do this atomically per EGS unit, under a DB lock):

1. Lock the EGS unit row → read `last_icv`, `last_hash`.
2. Build UBL XML with `ICV = last_icv + 1`, `PIH = last_hash`, new `UUID`, issue date/time from **server clock** (never client-supplied).
3. Run the validation engine (Section 7). Reject if any rule fails.
4. Compute invoice hash (Section 8.1) → store as `invoice_hash`.
5. **Simplified**: sign with XAdES (Section 8.2), build QR with tags 1-9 (Section 8.3), insert both into XML.
   **Standard**: build QR with tags 1-6 only (ZATCA adds the stamp on clearance) — or omit until cleared, then use ZATCA's returned XML.
6. Persist XML + hash + ICV; update `last_icv`, `last_hash` on the EGS unit; commit.
7. **Standard**: call Clearance synchronously; store returned cleared XML; only then release the invoice to the customer.
   **Simplified**: enqueue for Reporting; print/share immediately.
8. Write an append-only audit log entry.

---

## 3. IMPLEMENTATION ORDER

1. **DB schema** (Section 4) + config.
2. **EGS onboarding** (Section 5): key pair, CSR, compliance CSID, compliance checks, production CSID.
3. **UBL builder** for all document types (Section 6).
4. **Calculation + rounding** (Section 7.1) and **validation rules** (Section 7.2).
5. **Crypto**: hash, PIH, XAdES signature, QR (Section 8).
6. **API client** with clearance/reporting + offline queue + retry (Section 9).
7. **Human-readable output** (Section 10) and archival/export (Section 11).
8. **Tests** (Section 12) — run against ZATCA sandbox / SDK validator before touching production.

---

## 4. DATABASE ADDITIONS

```sql
-- One row per EGS unit (device / branch / POS instance)
CREATE TABLE zatca_egs_units (
  id                 INT PRIMARY KEY AUTO_INCREMENT,
  branch_id          INT NULL,
  common_name        VARCHAR(255) NOT NULL,        -- CN: unit name / asset tracking number
  serial_number      VARCHAR(255) NOT NULL,        -- "1-{Vendor}|2-{Model/Version}|3-{SerialNo}"
  organization_identifier CHAR(15) NOT NULL,       -- VAT no: 15 digits, starts & ends with 3
  organization_unit  VARCHAR(255) NOT NULL,        -- branch name (or 10-digit TIN for VAT groups)
  organization_name  VARCHAR(255) NOT NULL,
  country            CHAR(2) NOT NULL DEFAULT 'SA',
  invoice_type_map   CHAR(4) NOT NULL,             -- "TSCZ" flags e.g. 1100 = standard + simplified
  location           VARCHAR(255) NOT NULL,        -- branch address (National Address short code)
  industry           VARCHAR(255) NOT NULL,
  private_key_enc    TEXT NOT NULL,                -- encrypted at rest, NEVER exportable via UI/API
  public_key_pem     TEXT NOT NULL,
  csr_pem            TEXT NULL,
  compliance_cert    TEXT NULL,  compliance_secret VARCHAR(255) NULL, compliance_request_id VARCHAR(64) NULL,
  production_cert    TEXT NULL,  production_secret VARCHAR(255) NULL,
  cert_expires_at    DATETIME NULL,
  last_icv           BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- tamper-resistant counter, monotonic, never reset
  last_hash          VARCHAR(64) NOT NULL DEFAULT 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
  status             ENUM('new','compliance','production','revoked') NOT NULL DEFAULT 'new',
  environment        ENUM('sandbox','simulation','production') NOT NULL DEFAULT 'sandbox',
  created_at DATETIME, updated_at DATETIME
);

-- One row per issued e-document (immutable after insert)
CREATE TABLE zatca_documents (
  id                 BIGINT PRIMARY KEY AUTO_INCREMENT,
  egs_unit_id        INT NOT NULL,
  invoice_id         INT NOT NULL,                 -- FK to your existing invoice
  uuid               CHAR(36) NOT NULL UNIQUE,
  irn                VARCHAR(127) NOT NULL,        -- BT-1 invoice number
  icv                BIGINT UNSIGNED NOT NULL,
  invoice_type_code  CHAR(3) NOT NULL,             -- 388 / 381 / 383 / 386
  transaction_code   CHAR(7) NOT NULL,             -- NNPNESB e.g. 0100000
  issue_date         DATE NOT NULL,
  issue_time         TIME NOT NULL,
  previous_hash      VARCHAR(64) NOT NULL,
  invoice_hash       VARCHAR(64) NOT NULL,
  xml_unsigned       LONGTEXT NOT NULL,
  xml_signed         LONGTEXT NULL,                -- simplified: locally signed; standard: ZATCA-cleared XML
  qr_base64          VARCHAR(1000) NULL,
  submission_type    ENUM('clearance','reporting') NOT NULL,
  submission_status  ENUM('pending','submitted','cleared','reported','warning','rejected','failed') NOT NULL DEFAULT 'pending',
  zatca_response     JSON NULL,
  submitted_at       DATETIME NULL,
  attempts           INT NOT NULL DEFAULT 0,
  next_retry_at      DATETIME NULL,
  created_at         DATETIME NOT NULL,
  UNIQUE KEY (egs_unit_id, icv)
);

CREATE TABLE zatca_audit_log (        -- append-only; no UPDATE/DELETE grants
  id BIGINT PRIMARY KEY AUTO_INCREMENT, egs_unit_id INT, document_id BIGINT NULL,
  event VARCHAR(64), payload JSON, user_id INT NULL, created_at DATETIME
);
```

Add to your company/seller settings (all mandatory for the seller): registration name, VAT number, additional ID (type CRN/MOM/MLS/700/SAG/OTH + value), street, building number (4 digits), additional number (4 digits, optional), district, city, postal code (5 digits), province (optional), country `SA`.

Add to customers: VAT number (15 digits, optional for B2C), other ID (type TIN/CRN/MOM/MLS/700/SAG/NAT/GCC/IQA/PAS/OTH + value), full address fields as above, country code.

Add to invoices: `invoice_type_code`, transaction flags (third_party, nominal, export, summary, self_billed), `supply_date`, `supply_end_date`, `payment_means_code`, `note_reason` (credit/debit), `billing_reference_irn(s)`, `prepaid_amount`, `rounding_amount`, document-level allowance/charge rows.

Add to invoice lines: VAT category (S/Z/E/O), VAT rate, exemption reason code + text, line allowance/charge, unit code, seller/buyer/standard item ID, gross price + price discount (optional), and for prepayment-adjustment lines: prepayment invoice refs + per-category taxable/tax amounts.

---

## 5. EGS ONBOARDING (Cryptographic Stamp Identifier)

### 5.1 Key pair
- Algorithm: **ECDSA, 256-bit**. The Security Standard's certificate profile lists P-256; ZATCA's production CA and reference SDK use **secp256k1** — make the curve configurable, default to `secp256k1`, and verify against the ZATCA SDK before go-live.
- Generate per FIPS 186; validate the public key (NIST SP 800-56A partial/full validation).
- Mark private key **non-exportable**: encrypt at rest (AES-256-GCM with a key from `.env`/HSM), never expose via UI, API, export, or logs. Use disk encryption on the host.

### 5.2 CSR (PKCS#10, signed with the private key as proof of possession)

| CSR field | OID / location | Value |
|---|---|---|
| CN | subject.commonName | Unit name or asset tracking number |
| SN (EGS serial) | subject.serialNumber | `1-{Manufacturer}\|2-{Model/Version}\|3-{SerialNumber}` |
| organizationIdentifier | 2.5.4.97 | Seller VAT number (15 digits, starts/ends with 3) |
| OU | subject.organizationalUnit | Branch name; for VAT groups: 10-digit TIN of the member |
| O | subject.organization | Taxpayer name |
| C | subject.country | `SA` |
| Invoice type | SAN ext `businessCategory` 2.5.4.15 / or subject | 4 chars `TSCZ` → `1100` = Tax + Simplified |
| Location | SAN ext `registeredAddress` 2.5.4.26 | Branch address (National Address short code preferred) |
| Industry | SAN ext `businessCategory` 2.5.4.15 | Free text |

In practice ZATCA expects the SAN extension `subjectAltName = dirName:` with `SN`, `UID`(VAT), `title`(TSCZ), `registeredAddress`, `businessCategory`. Use the ZATCA SDK's CSR config template as the authoritative layout (verify).

### 5.3 Onboarding flow (Developer-portal API — verify current paths)
1. Taxpayer logs into Fatoora portal → "Onboard new solution unit" → gets a one-time **OTP**.
2. `POST /compliance` with header `OTP: <otp>`, body `{ "csr": "<base64 CSR>" }` → returns `binarySecurityToken` (compliance CSID), `secret`, `requestID`.
3. **Compliance checks**: for each document type enabled in TSCZ, submit sample documents to `POST /compliance/invoices` (standard invoice + credit + debit; simplified invoice + credit + debit) signed with the compliance CSID. All must pass.
4. `POST /production/csids` with `{ "compliance_request_id": "<requestID>" }` (Basic auth = compliance CSID/secret) → production `binarySecurityToken` + `secret`.
5. Store production cert + secret; set unit `status = production`. Record `cert_expires_at` (up to 5 years).
6. **Renewal**: before expiry, `PATCH /production/csids` with a new CSR + OTP; a new key pair is required on renewal.
7. **Revocation**: via portal if key compromised, device decommissioned, or cert data inaccurate. Check CRL/OCSP; CRLs are valid 7 days (units may operate offline up to 7 days).

Environments (verify URLs on the Developer Portal):
- Sandbox `.../developer-portal`, Simulation `.../simulation`, Production `.../core`.
- Headers on all calls: `Accept-Version: V2`, `Accept-Language: en`, `Content-Type: application/json`, and for clearance `Clearance-Status: 1`.

---

## 6. UBL 2.1 XML — FIELD SPECIFICATION

Root: `<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="...CommonAggregateComponents-2" xmlns:cbc="...CommonBasicComponents-2" xmlns:ext="...CommonExtensionComponents-2">`. The UBL message type is `Invoice` for **all** document types (including credit notes). Element order must follow the UBL 2.1 schema sequence. **No empty elements anywhere** (BR-KSA-F-03).

Legend: M = mandatory, C = conditional, O = optional. Columns: Tax Inv / Tax CN-DN / Simplified / Simplified CN-DN.

### 6.1 Header

| Term | UBL path | Rules | T | TN | S | SN |
|---|---|---|---|---|---|---|
| BT-23 Profile | `cbc:ProfileID` | fixed `reporting:1.0` | M | M | M | M |
| BT-1 Invoice number (IRN) | `cbc:ID` | unique sequential, 1-127 chars | M | M | M | M |
| KSA-1 UUID | `cbc:UUID` | v4 UUID, letters/digits/dashes | M | M | M | M |
| BT-2 Issue date | `cbc:IssueDate` | `YYYY-MM-DD`, ≤ today, no TZ | M | M | M | M |
| KSA-25 Issue time | `cbc:IssueTime` | `HH:mm:ss` (AST) or `HH:mm:ssZ` (UTC) | M | M | M | M |
| BT-3 Type code | `cbc:InvoiceTypeCode` | 388 / 381 / 383 / 386 | M | M | M | M |
| KSA-2 Transaction code | `cbc:InvoiceTypeCode/@name` | 7 chars `NNPNESB` (see 6.7) | M | M | M | M |
| BT-22 Note | `cbc:Note` (0..n) | ≤1000 chars | O | O | O | O |
| BT-5 Currency | `cbc:DocumentCurrencyCode` | ISO 4217 | M | M | M | M |
| BT-6 Tax currency | `cbc:TaxCurrencyCode` | must be `SAR` | M | M | M | M |
| BT-13 PO ID | `cac:OrderReference/cbc:ID` | | O | O | O | O |
| BT-25 Billing reference | `cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID` (1..n) | IRN of original invoice(s) | – | M | – | M |
| BT-12 Contract ID | `cac:ContractDocumentReference/cbc:ID` | | O | O | O | O |
| KSA-16 ICV | `cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID` | digits only, monotonic | M | M | M | M |
| KSA-13 PIH | `cac:AdditionalDocumentReference[cbc:ID='PIH']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject[@mimeCode='text/plain']` | base64(SHA-256) of previous doc | M | M | M | M |
| KSA-14 QR | `cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject[@mimeCode='text/plain']` | base64 TLV | M | M | M | M |
| KSA-15 Signature ref | `cac:Signature/cbc:ID` = `urn:oasis:names:specification:ubl:signature:Invoice`; `cbc:SignatureMethod` = `urn:oasis:names:specification:ubl:dsig:enveloped:xades` | | (after clearance) | | M | M |
| Cryptographic stamp | `ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/sig:UBLDocumentSignatures/...` | see 8.2 | (ZATCA) | | M | M |

Order in XML: `ext:UBLExtensions` first (when present), then `cbc:ProfileID`, `cbc:ID`, `cbc:UUID`, `cbc:IssueDate`, `cbc:IssueTime`, `cbc:InvoiceTypeCode`, `cbc:Note`, `cbc:DocumentCurrencyCode`, `cbc:TaxCurrencyCode`, `cac:OrderReference`, `cac:BillingReference`, `cac:ContractDocumentReference`, `cac:AdditionalDocumentReference` (ICV, PIH, QR), `cac:Signature`, `cac:AccountingSupplierParty`, `cac:AccountingCustomerParty`, `cac:Delivery`, `cac:PaymentMeans`, `cac:AllowanceCharge`, `cac:TaxTotal`, `cac:LegalMonetaryTotal`, `cac:InvoiceLine`.

### 6.2 Seller (`cac:AccountingSupplierParty/cac:Party`) — all M for every type

| Term | Path | Rule |
|---|---|---|
| BT-29 Other seller ID | `cac:PartyIdentification/cbc:ID[@schemeID]` | exactly one of CRN, MOM, MLS, 700, SAG, OTH (in that priority) |
| BT-35 Street | `cac:PostalAddress/cbc:StreetName` | 1-1000 |
| BT-36 Additional street | `cbc:AdditionalStreetName` | O |
| KSA-17 Building no. | `cbc:BuildingNumber` | exactly 4 digits |
| KSA-23 Additional no. | `cbc:PlotIdentification` | O, 4 digits |
| KSA-3 District | `cbc:CitySubdivisionName` | 1-127 |
| BT-37 City | `cbc:CityName` | |
| BT-38 Postal code | `cbc:PostalZone` | exactly 5 digits |
| BT-39 Province | `cbc:CountrySubentity` | O |
| BT-40 Country | `cac:Country/cbc:IdentificationCode` | `SA` |
| BT-31 VAT number | `cac:PartyTaxScheme/cbc:CompanyID` + `cac:TaxScheme/cbc:ID=VAT` | 15 digits, first & last = 3 |
| BT-27 Name | `cac:PartyLegalEntity/cbc:RegistrationName` | 1-1000 |

### 6.3 Buyer (`cac:AccountingCustomerParty/cac:Party`)

| Term | Path | T / TN | S / SN |
|---|---|---|---|
| BT-46 Other buyer ID | `cac:PartyIdentification/cbc:ID[@schemeID]` (TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS, OTH) | **M if buyer VAT number absent** (BR-KSA-81); must be `NAT` for VATEX-SA-EDU / VATEX-SA-HEA | C (NAT for EDU/HEA) |
| Address block | `cac:PostalAddress/...` (street, building no., district, city, postal code, country) | M (postal 5 digits & building 4 digits when country=SA) | O |
| BT-48 VAT number | `cac:PartyTaxScheme/cbc:CompanyID` + TaxScheme VAT | C: 15 digits starting/ending 3; **must be absent on export invoices** | O |
| BT-44 Name | `cac:PartyLegalEntity/cbc:RegistrationName` | M | C: mandatory for summary invoices and for EDU/HEA exemptions |

### 6.4 Delivery, payment, notes

| Term | Path | Rule |
|---|---|---|
| KSA-5 Supply date | `cac:Delivery/cbc:ActualDeliveryDate` | **M for standard tax invoice (388/01)**; M for simplified summary invoices; for notes = original supply date |
| KSA-24 Supply end date | `cac:Delivery/cbc:LatestDeliveryDate` | continuous supplies / summary; ≥ supply date |
| BT-81 Payment means | `cac:PaymentMeans/cbc:PaymentMeansCode` | UNTDID 4461 subset: 10 cash, 30 credit, 42 bank account, 48 bank card, 1 other |
| KSA-10 Note reason | `cac:PaymentMeans/cbc:InstructionNote` | **M for 381/383**. Allowed texts: cancellation/suspension of supply; essential change in supply changing VAT; pre-agreed amendment of value; refund of goods/services; change in seller/buyer info |
| KSA-22 Payment terms | `cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:PaymentNote` | O |
| BT-84 IBAN | `cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID` | O |

### 6.5 Document-level allowance / charge (`cac:AllowanceCharge`, 0..n)

```xml
<cac:AllowanceCharge>
  <cbc:ChargeIndicator>false</cbc:ChargeIndicator>          <!-- false = discount, true = charge -->
  <cbc:AllowanceChargeReasonCode>95</cbc:AllowanceChargeReasonCode>  <!-- discount: UNTDID 5189 (O); charge: UNTDID 7161 (M) -->
  <cbc:AllowanceChargeReason>Discount</cbc:AllowanceChargeReason>    <!-- M for charges -->
  <cbc:MultiplierFactorNumeric>10</cbc:MultiplierFactorNumeric>      <!-- O; if present BaseAmount required and vice versa -->
  <cbc:Amount currencyID="SAR">200.00</cbc:Amount>                   <!-- = Base × pct/100 when both given -->
  <cbc:BaseAmount currencyID="SAR">2000.00</cbc:BaseAmount>
  <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>15</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>
</cac:AllowanceCharge>
```

### 6.6 Tax totals & monetary totals

```xml
<cac:TaxTotal>                                   <!-- exactly ONE TaxTotal with subtotals -->
  <cbc:TaxAmount currencyID="SAR">885.00</cbc:TaxAmount>            <!-- BT-110 = Σ BT-117 -->
  <cac:TaxSubtotal>                              <!-- one per distinct (category, rate) -->
    <cbc:TaxableAmount currencyID="SAR">5900.00</cbc:TaxableAmount>  <!-- BT-116 -->
    <cbc:TaxAmount currencyID="SAR">885.00</cbc:TaxAmount>           <!-- BT-117 -->
    <cac:TaxCategory>
      <cbc:ID>S</cbc:ID>                          <!-- S | Z | E | O -->
      <cbc:Percent>15.00</cbc:Percent>
      <!-- for Z/E/O: -->
      <cbc:TaxExemptionReasonCode>VATEX-SA-32</cbc:TaxExemptionReasonCode>
      <cbc:TaxExemptionReason>Export of goods</cbc:TaxExemptionReason>
      <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
    </cac:TaxCategory>
  </cac:TaxSubtotal>
</cac:TaxTotal>
<cac:TaxTotal>                                   <!-- SECOND TaxTotal (no subtotals): VAT in SAR (BT-111) — include when DocumentCurrency ≠ SAR; safe to always include -->
  <cbc:TaxAmount currencyID="SAR">885.00</cbc:TaxAmount>
</cac:TaxTotal>
<cac:LegalMonetaryTotal>
  <cbc:LineExtensionAmount currencyID="SAR">6000.00</cbc:LineExtensionAmount>  <!-- BT-106 = Σ line net -->
  <cbc:TaxExclusiveAmount currencyID="SAR">5900.00</cbc:TaxExclusiveAmount>    <!-- BT-109 = BT-106 − BT-107 + BT-108 -->
  <cbc:TaxInclusiveAmount currencyID="SAR">6785.00</cbc:TaxInclusiveAmount>    <!-- BT-112 = BT-109 + BT-110 -->
  <cbc:AllowanceTotalAmount currencyID="SAR">100.00</cbc:AllowanceTotalAmount> <!-- BT-107 (C) -->
  <cbc:ChargeTotalAmount currencyID="SAR">0.00</cbc:ChargeTotalAmount>         <!-- BT-108 (C) -->
  <cbc:PrepaidAmount currencyID="SAR">0.00</cbc:PrepaidAmount>                 <!-- BT-113 (O) -->
  <cbc:PayableRoundingAmount currencyID="SAR">0.00</cbc:PayableRoundingAmount> <!-- BT-114 (O) -->
  <cbc:PayableAmount currencyID="SAR">6785.00</cbc:PayableAmount>              <!-- BT-115 = BT-112 − BT-113 + BT-114 -->
</cac:LegalMonetaryTotal>
```

### 6.7 Invoice line (`cac:InvoiceLine`, 1..n)

```xml
<cac:InvoiceLine>
  <cbc:ID>1</cbc:ID>                                                    <!-- numeric 1..999999 -->
  <cbc:InvoicedQuantity unitCode="PCE">10</cbc:InvoicedQuantity>         <!-- BT-129, unitCode UN/ECE Rec 20 (O) -->
  <cbc:LineExtensionAmount currencyID="SAR">925.00</cbc:LineExtensionAmount>   <!-- BT-131 -->
  <cac:AllowanceCharge> ...line discount (false) / charge (true)... </cac:AllowanceCharge>   <!-- 0..n -->
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="SAR">138.75</cbc:TaxAmount>               <!-- KSA-11 line VAT (M for tax inv) -->
    <cbc:RoundingAmount currencyID="SAR">1063.75</cbc:RoundingAmount>    <!-- KSA-12 line total incl. VAT (M) -->
  </cac:TaxTotal>
  <cac:Item>
    <cbc:Name>Item name</cbc:Name>                                        <!-- BT-153 -->
    <cac:BuyersItemIdentification><cbc:ID>..</cbc:ID></cac:BuyersItemIdentification>     <!-- O -->
    <cac:SellersItemIdentification><cbc:ID>..</cbc:ID></cac:SellersItemIdentification>   <!-- O -->
    <cac:StandardItemIdentification><cbc:ID>..</cbc:ID></cac:StandardItemIdentification> <!-- O: GTIN/HS -->
    <cac:ClassifiedTaxCategory>
      <cbc:ID>S</cbc:ID><cbc:Percent>15.00</cbc:Percent>                <!-- BT-151, BT-152 -->
      <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
    </cac:ClassifiedTaxCategory>
  </cac:Item>
  <cac:Price>
    <cbc:PriceAmount currencyID="SAR">100.00</cbc:PriceAmount>            <!-- BT-146 net unit price -->
    <cbc:BaseQuantity unitCode="PCE">1</cbc:BaseQuantity>                 <!-- BT-149 (O, > 0) -->
    <cac:AllowanceCharge>                                                  <!-- O: price-level discount -->
      <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
      <cbc:Amount currencyID="SAR">10.00</cbc:Amount>                     <!-- BT-147 -->
      <cbc:BaseAmount currencyID="SAR">110.00</cbc:BaseAmount>            <!-- BT-148 gross; net = gross − discount -->
    </cac:AllowanceCharge>
  </cac:Price>
</cac:InvoiceLine>
```

### 6.8 Prepayment (advance payment) handling
- The advance invoice itself uses `InvoiceTypeCode = 386` (subtype 01 or 02) and is a normal invoice otherwise.
- When a later final invoice **adjusts** a prepayment: set `PrepaidAmount` (BT-113) and add **one extra InvoiceLine per prepayment invoice per VAT category** with quantity 0, `LineExtensionAmount` 0, `PriceAmount` 0, line `TaxAmount` 0, `RoundingAmount` 0, and:

```xml
<cac:DocumentReference>
  <cbc:ID>{prepayment IRN}</cbc:ID> <cbc:UUID>{optional}</cbc:UUID>
  <cbc:IssueDate>YYYY-MM-DD</cbc:IssueDate> <cbc:IssueTime>HH:mm:ss</cbc:IssueTime>
  <cbc:DocumentTypeCode>386</cbc:DocumentTypeCode>
</cac:DocumentReference>
<cac:TaxTotal>
  <cbc:TaxAmount currencyID="SAR">0.00</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">0.00</cbc:RoundingAmount>
  <cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="SAR">86.96</cbc:TaxableAmount>   <!-- KSA-31 -->
    <cbc:TaxAmount currencyID="SAR">13.04</cbc:TaxAmount>           <!-- KSA-32 = KSA-31 × rate/100 -->
    <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>15.00</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>
  </cac:TaxSubtotal>
</cac:TaxTotal>
```
- Rule: `BT-113 = Σ KSA-31 + Σ KSA-32`.

### 6.9 Transaction code `NNPNESB` (KSA-2, `@name` on InvoiceTypeCode)

| Pos | Meaning | Values |
|---|---|---|
| 1-2 | Subtype | `01` tax invoice (B2B), `02` simplified (B2C) |
| 3 | Third-party billed | 0/1 |
| 4 | Nominal supply | 0/1 |
| 5 | Export | 0/1 (not allowed with simplified; buyer VAT must be absent; self-billed not allowed) |
| 6 | Summary | 0/1 (simplified summary → buyer name + supply date + end date mandatory) |
| 7 | Self-billed | 0/1 (B2B only, both parties VAT registered; title must show "Self-billed Tax Invoice") |

Simplified documents may only set positions 3, 4, 6. Examples: `0100000` standard, `0200000` simplified, `0100100` export tax invoice.

---

## 7. CALCULATIONS AND VALIDATION

### 7.1 Formulas (all amounts 2 dp, half-up rounding, applied on final results only)

```
BT-146 item net price          = BT-148 gross price − BT-147 price discount            (if gross given)
BT-131 line net amount         = round2( BT-146 / BT-149 × BT-129 ) − Σ line allowances + Σ line charges
KSA-11 line VAT                = round2( BT-131 × BT-152 / 100 )
KSA-12 line total incl. VAT    = BT-131 + KSA-11
Allowance/charge amount        = BaseAmount × pct / 100                                  (when both given)
BT-106 Σ line net              = Σ BT-131
BT-107 Σ doc allowances        = Σ AllowanceCharge[false]/Amount
BT-108 Σ doc charges           = Σ AllowanceCharge[true]/Amount
BT-109 total excl. VAT         = BT-106 − BT-107 + BT-108
BT-116 taxable per (cat,rate)  = Σ BT-131[cat,rate] − Σ doc allowance[cat,rate] + Σ doc charge[cat,rate]
BT-117 VAT per (cat,rate)      = round2( BT-116 × rate / 100 )     -- computed at document level, NOT Σ of line VAT
BT-110 total VAT               = Σ BT-117
BT-112 total incl. VAT         = BT-109 + BT-110
BT-115 payable                 = BT-112 − BT-113 prepaid + BT-114 rounding
```
- One `TaxSubtotal` per distinct (category, rate); `15` and `15.00` are the same rate.
- Categories Z, E, O: rate = 0, VAT amount = 0, exemption reason code **and** text required.
- All amounts and quantities must be **positive** (credit notes carry positive amounts; the type code 381 defines the direction).
- Decimal limits: amounts 2 dp; percentages 0.00-100.00 with 2 dp; unit price & quantity unrestricted decimals.

### 7.2 Validation engine — implement each as a named rule returning `{rule, message, xpath}`

**Structure / integrity:** BR-02..BR-16 (ID, IssueDate, type code, currency, seller name/address/country, buyer address (not for 02), totals, ≥1 line); BR-21..26 (line ID, qty, net amount, item name, price); BR-31/32/36/37/41/43 (allowance/charge amount + category); BR-45..48 (VAT breakdown fields); BR-49 (payment means code if PaymentMeans present); BR-53; BR-55; BR-CO-04, -10..-18 (all formulas above).

**VAT category rules:** BR-S-06..10, BR-Z-01/05/06/07/08/09, BR-E-01/05..09, BR-O-01/08/09/13, BR-KSA-11/12/13 (O rate 0 if present), BR-KSA-18 (only S/Z/E/O), BR-KSA-23/24/69 + BR-KSA-CL-04 (exemption code for E/O/Z), BR-KSA-83 (exemption text from official list).

**KSA-specific:** BR-KSA-03 (UUID), -04 (issue date ≤ today), -05 (type code set), -06/-07/-31 (transaction code structure & constraints), -08 (seller ID scheme), -09 (seller address completeness), -10/-63/-67 (buyer address for 01 and for SA buyers), -14/-49/-81 (buyer ID), -15 (supply date on 388/01), -16 (payment code list), -17 (note reason on 381/383), -19..-22 (charge reason + code, doc & line), -25/-42/-71 (buyer name), -26 (PIH format), -27 (QR present), -28/-29/-30 (signature IDs/method), -33/-34 (ICV digits), -35/-36 (supply end date), -37 (building 4 digits), -39/-40 (seller VAT format), -44/-46 (buyer VAT format / absent on export), -51/-52/-53 (line VAT & total on 01), -56 (billing reference on notes), -60 (stamp on 02), -61 (PIH present), -66 (seller postal 5 digits), -68 (TaxCurrencyCode present), -70 (issue time format), -72 (summary dates), -73..-80/-82 (prepayment rules), BR-KSA-DEC-01..06, BR-KSA-CL-01/02 (all currencyID = document currency except BT-111), -03 (mimeCode), -06 (charge codes UNTDID 7161), BR-KSA-EN16931-01 (`reporting:1.0`), -02 (`SAR`), -03/-04/-05 (base/percentage pairing), -07 (net = gross − discount), -08/-09 (one TaxTotal with subtotals, one without), -11 (line net formula), -12 (base qty > 0), BR-KSA-F-01 (date format), -02 (indicator literal `false`/`true`), -03 (no empty elements), -04 (positive amounts), -05 (prepayment time format), -06 (char limits from Section 6).

**Code lists to embed:**
- VAT categories: `S` standard, `Z` zero, `E` exempt, `O` out of scope.
- Exemption codes (BT-121) with exact texts (BT-120):
  - E: `VATEX-SA-29` Financial services mentioned in Article 29 of the VAT Regulations; `VATEX-SA-29-7` Life insurance services mentioned in Article 29 of the VAT Regulations; `VATEX-SA-30` Real estate transactions mentioned in Article 30 of the VAT Regulations
  - Z: `VATEX-SA-32` Export of goods; `VATEX-SA-33` Export of services; `VATEX-SA-34-1` The international transport of Goods; `VATEX-SA-34-2` international transport of passengers; `VATEX-SA-34-3` services directly connected and incidental to a Supply of international passenger transport; `VATEX-SA-34-4` Supply of a qualifying means of transport; `VATEX-SA-34-5` Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations; `VATEX-SA-35` Medicines and medical equipment; `VATEX-SA-36` Qualifying metals; `VATEX-SA-EDU` Private education to citizen; `VATEX-SA-HEA` Private healthcare to citizen; `VATEX-SA-MLTRY` supply of qualified military goods
  - O: `VATEX-SA-OOS` — reason text is free text supplied by the taxpayer
- Seller ID schemes: CRN, MOM, MLS, 700, SAG, OTH. Buyer ID schemes: TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS, OTH.
- Payment means: 10, 30, 42, 48, 1. Allowance reason: UNTDID 5189 (e.g. 95). Charge reason: UNTDID 7161 (e.g. CG). Units: UN/ECE Rec 20 (e.g. PCE).

---

## 8. SECURITY FEATURES

### 8.1 Invoice hash and Previous Invoice Hash (PIH)
```
1. Take the UBL XML WITHOUT: <ext:UBLExtensions>, <cac:Signature>, <cac:AdditionalDocumentReference> where cbc:ID='QR'
2. Canonicalize with XML C14N 1.1  (http://www.w3.org/2006/12/xml-c14n11)
3. SHA-256 → 32 raw bytes
4. invoice_hash (KSA-13 of the NEXT invoice, and ds:DigestValue) = base64(raw bytes)
```
- First document of every EGS unit: `PIH = "NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ=="` (= base64 of SHA-256 hex of `"0"`).
- The chain is per EGS unit and covers **all** document types in issue order.

### 8.2 Cryptographic stamp (XAdES B-B, enveloped, ECDSA-SHA256) — simplified docs only
Insert into `ext:UBLExtensions` (first child of root) exactly this structure; fill the placeholders:

```xml
<ext:UBLExtensions>
 <ext:UBLExtension>
  <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
  <ext:ExtensionContent>
   <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"
        xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"
        xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
    <sac:SignatureInformation>
     <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
     <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
     <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
      <ds:SignedInfo>
       <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
       <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
       <ds:Reference Id="invoiceSignedData" URI="">
        <ds:Transforms>
         <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath></ds:Transform>
         <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath></ds:Transform>
         <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath></ds:Transform>
         <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
        </ds:Transforms>
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>{INVOICE_HASH base64}</ds:DigestValue>
       </ds:Reference>
       <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>{base64( SHA-256( C14N11( <xades:SignedProperties> element ) ) )}</ds:DigestValue>
       </ds:Reference>
      </ds:SignedInfo>
      <ds:SignatureValue>{base64( ECDSA-SHA256 signature over C14N11(ds:SignedInfo) using EGS private key )}</ds:SignatureValue>
      <ds:KeyInfo><ds:X509Data><ds:X509Certificate>{base64 DER of CSID certificate (PEM body)}</ds:X509Certificate></ds:X509Data></ds:KeyInfo>
      <ds:Object>
       <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
        <xades:SignedProperties Id="xadesSignedProperties">
         <xades:SignedSignatureProperties>
          <xades:SigningTime>{YYYY-MM-DDTHH:mm:ssZ, EGS clock}</xades:SigningTime>
          <xades:SigningCertificate>
           <xades:Cert>
            <xades:CertDigest>
             <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
             <ds:DigestValue>{base64( SHA-256 hex-string of the DER certificate )  — follow ZATCA SDK: base64 of the lowercase hex digest}</ds:DigestValue>
            </xades:CertDigest>
            <xades:IssuerSerial>
             <ds:X509IssuerName>{certificate issuer DN}</ds:X509IssuerName>
             <ds:X509SerialNumber>{certificate serial as decimal}</ds:X509SerialNumber>
            </xades:IssuerSerial>
           </xades:Cert>
          </xades:SigningCertificate>
         </xades:SignedSignatureProperties>
        </xades:SignedProperties>
       </xades:QualifyingProperties>
      </ds:Object>
     </ds:Signature>
    </sac:SignatureInformation>
   </sig:UBLDocumentSignatures>
  </ext:ExtensionContent>
 </ext:UBLExtension>
</ext:UBLExtensions>
```
Plus in the body: `<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>`.

Notes: signature level B-B (no timestamps/OCSP); include full cert chain if ZATCA provides intermediates; check the CSID against CRL/OCSP before use (allow 7-day offline grace). Signing time comes from the EGS clock; never allow the user to change it.

### 8.3 QR code (TLV → Base64, ≤ 700 chars)
```
for each tag in order:  byte(tag) + byte(len(valueBytes)) + valueBytes   (value UTF-8; len max 255)
qr_base64 = base64( concat(all TLV) )
```

| Tag | Value | Standard (B2B) | Simplified (B2C) |
|---|---|---|---|
| 1 | Seller name (BT-27) | ✓ | ✓ |
| 2 | Seller VAT number (BT-31) | ✓ | ✓ |
| 3 | Timestamp ISO 8601 `YYYY-MM-DDTHH:mm:ssZ` (issue date+time) | ✓ | ✓ |
| 4 | Invoice total with VAT (BT-112), e.g. `1150.00` | ✓ | ✓ |
| 5 | VAT total (BT-110) | ✓ | ✓ |
| 6 | Invoice hash (base64 string from 8.1) | ✓ | ✓ |
| 7 | ECDSA signature (raw bytes of `ds:SignatureValue`, base64-decoded) | ZATCA fills | ✓ |
| 8 | EGS public key (raw DER bytes of SubjectPublicKeyInfo) | optional | ✓ |
| 9 | ZATCA CA's signature over the EGS certificate (raw bytes; extract the signature value from the CSID certificate) | – | ✓ |

Store `qr_base64` in the XML (`AdditionalDocumentReference[ID='QR']`) and render it as a QR image on every printout. Note the QR element is excluded from the hash/signature, so it can be inserted after signing.

### 8.4 ICV / UUID / tamper resistance
- ICV: `BIGINT`, increment inside the same DB transaction as the document insert, `UNIQUE(egs_unit_id, icv)`, no API/UI to reset. Include in XML as `AdditionalDocumentReference[ID='ICV']/cbc:UUID`.
- UUID v4 per document.
- Issued documents are **immutable**: no UPDATE/DELETE on `zatca_documents.xml_*`, `invoice_hash`, `icv`; corrections only via credit/debit notes. Enforce with DB grants/triggers and application guards.
- Timestamps come from the server (NTP-synced); reject client-supplied issue date/time.
- Every user action goes through authentication + session management; no default credentials.

---

## 9. ZATCA API CLIENT (Phase 2)

Verify exact base URLs and paths on the Developer Portal; treat these as the known shape:

| Purpose | Method / path | Auth | Body |
|---|---|---|---|
| Compliance CSID | `POST /compliance` | header `OTP` | `{ "csr": base64 }` |
| Compliance check | `POST /compliance/invoices` | Basic(compliance cert, secret) | see below |
| Production CSID | `POST /production/csids` | Basic(compliance cert, secret) | `{ "compliance_request_id": "..." }` |
| Renew CSID | `PATCH /production/csids` | Basic(production) + `OTP` | `{ "csr": base64 }` |
| Clearance (B2B) | `POST /invoices/clearance/single` | Basic(production) + `Clearance-Status: 1` | see below |
| Reporting (B2C) | `POST /invoices/reporting/single` | Basic(production) | see below |

Body for check/clearance/reporting:
```json
{ "invoiceHash": "<base64 hash>", "uuid": "<uuid>", "invoice": "<base64 of full signed XML>" }
```
Basic auth username = the `binarySecurityToken` string as returned (already base64), password = `secret`.

Response handling:
- `200` → `clearanceStatus: CLEARED` / `reportingStatus: REPORTED` → store; for clearance store `clearedInvoice` (base64 XML) as `xml_signed` and use it for PDF/QR.
- `202` with warnings → accepted; store warnings, surface in admin.
- `400` (validation errors) → `rejected`; show `validationResults.errorMessages`; do **not** retry automatically; the ICV/hash already consumed stays in the chain — issue a corrected document.
- `401/403` → certificate/secret problem → alert admin, pause queue for that unit.
- Network/5xx → `failed`, exponential backoff, keep retrying; simplified must reach ZATCA within **24 h** of issue — alert when a document is > 20 h old and unreported.
- Clearance failures for B2B: invoice must **not** be delivered to the buyer until cleared; keep it in `pending` and let the user retry / correct.

Offline mode: generation, signing, QR, printing continue with no internet; a background worker drains the queue in ICV order per unit.

Compliance-check phase: generate one sample of each enabled type (invoice/credit/debit × standard/simplified), signed with the compliance CSID, and submit to `/compliance/invoices`; all must return `PASS` before requesting the production CSID.

---

## 10. HUMAN-READABLE INVOICE (PDF / print)

Must show (per Annex 2 visibility rules), in Arabic (mandatory) with English optional:
- Title: "Tax Invoice / فاتورة ضريبية", "Simplified Tax Invoice / فاتورة ضريبية مبسطة", "Credit Note / إشعار دائن", "Debit Note / إشعار مدين"; prefix "Self-billed" when flag set.
- IRN, issue date (and time), supply date when present, QR code image.
- Seller: name, address, VAT number, other ID. Buyer (standard): name, address, VAT/other ID.
- Lines: description, unit price, quantity, discount amount, subtotal excl. VAT, VAT rate, VAT amount, subtotal incl. VAT (simplified: "inclusive of VAT" statement).
- Totals: discount, taxable amount, VAT total, gross total with "Amount includes VAT / المبلغ يشمل ضريبة القيمة المضافة"; payable when prepaid/rounding used.
- Notes: original invoice reference + reason. Special tax treatment narration when not standard rate.
- Arabic numerals `0-9` in the XML; Hindi-Arabic numerals allowed only on the visual copy.
- Optional PDF/A-3: embed the signed XML as an attachment (ISO 19005-3); if signing the PDF, use PAdES B-B with `SubFilter = ETSI.CAdES.detached`.

---

## 11. STORAGE, EXPORT, LOGGING

- Store signed/cleared XML immutably; keep for the VAT record-retention period.
- Export function: zip of XML files named `{BT-31}_{YYYYMMDD}T{HHMMSS}_{IRN-with-non-alphanumerics-replaced-by-dash}.xml`, e.g. `310122393500003_20210526T132400_INV-2021-000123.xml`.
- Append-only audit log of: onboarding events, every issued document (uuid, icv, hash), every submission attempt + response, every certificate change, every failed validation.
- Logs and issued documents must be non-editable; sequential log IDs.

---

## 12. TEST PLAN / ACCEPTANCE

1. Unit tests for: TLV encoder (compare against a known QR), C14N11 + SHA-256 hash of a fixture XML, PIH chain across 3 documents, first-invoice PIH constant, XAdES signature verifies with the public key, all calculation formulas including rounding edge cases (`123.4949 → 123.49`, `123.4951 → 123.50`).
2. Validation engine tests: one failing fixture per rule group (missing exemption code, export with buyer VAT, simplified with export flag, seller building number 3 digits, empty element, negative amount, mismatched currencyID, prepayment sum mismatch, note without billing reference, etc.).
3. Golden XML fixtures: standard invoice, standard credit note, standard debit note, simplified invoice, simplified credit note, simplified debit note, prepayment invoice, final invoice adjusting two prepayments, invoice with document-level discount + charge, invoice with mixed S/Z/E/O lines.
4. Run every fixture through the **ZATCA SDK validator** (`fatoora -validate`) and the **sandbox compliance endpoint**; all must PASS with no errors.
5. End-to-end in sandbox: onboard a unit → compliance checks → production CSID → clear a B2B invoice → report a B2C invoice → simulate offline → confirm queue drains in ICV order.
6. Security review: private key not retrievable via any endpoint/export; ICV cannot be reset; issued XML cannot be modified; clock not user-adjustable; all endpoints authenticated.

Deliver: code, migrations, config template, admin screens, the fixtures, and a short RUNBOOK.md (onboarding steps, renewal, what to do on rejection, 24-hour reporting monitor).
