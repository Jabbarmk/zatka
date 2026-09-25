<?php
require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(64) PRIMARY KEY,
        `value` TEXT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        vat_number VARCHAR(15) NULL,
        id_scheme VARCHAR(3) NULL,
        id_value VARCHAR(64) NULL,
        street VARCHAR(255) NULL,
        building_no VARCHAR(4) NULL,
        district VARCHAR(127) NULL,
        city VARCHAR(127) NULL,
        postal_code VARCHAR(5) NULL,
        country CHAR(2) NOT NULL DEFAULT 'SA',
        phone VARCHAR(32) NULL,
        email VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egs_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        last_icv BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_hash VARCHAR(128) NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        egs_unit_id INT NOT NULL,
        invoice_number VARCHAR(64) NOT NULL UNIQUE,
        uuid CHAR(36) NOT NULL UNIQUE,
        type_code CHAR(3) NOT NULL,
        subtype CHAR(2) NOT NULL,
        transaction_code CHAR(7) NOT NULL,
        customer_id INT NULL,
        issue_date DATE NOT NULL,
        issue_time TIME NOT NULL,
        supply_date DATE NULL,
        payment_means CHAR(2) NOT NULL DEFAULT '10',
        billing_reference VARCHAR(127) NULL,
        note_reason VARCHAR(255) NULL,
        doc_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        taxable_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'SAR',
        notes TEXT NULL,
        icv BIGINT UNSIGNED NOT NULL,
        previous_hash VARCHAR(128) NOT NULL,
        invoice_hash VARCHAR(128) NOT NULL,
        qr_base64 TEXT NULL,
        xml LONGTEXT NULL,
        zatca_status ENUM('not_submitted','pending','cleared','reported','rejected','failed') NOT NULL DEFAULT 'not_submitted',
        zatca_response TEXT NULL,
        submitted_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_unit_icv (egs_unit_id, icv)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        line_no INT NOT NULL,
        description VARCHAR(1000) NOT NULL,
        quantity DECIMAL(14,4) NOT NULL,
        unit_price DECIMAL(14,4) NOT NULL,
        discount DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_category CHAR(1) NOT NULL DEFAULT 'S',
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
        exemption_code VARCHAR(32) NULL,
        exemption_text VARCHAR(255) NULL,
        line_net DECIMAL(14,2) NOT NULL,
        line_vat DECIMAL(14,2) NOT NULL,
        line_total DECIMAL(14,2) NOT NULL,
        INDEX (invoice_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(64) NOT NULL,
        details TEXT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    if ((int)$pdo->query("SELECT COUNT(*) FROM egs_units")->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO egs_units (name, last_hash) VALUES ('Main Unit', ?)")
            ->execute(['NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==']);
    }
}

function setting(string $key, $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT `key`, `value` FROM settings") as $r) $cache[$r['key']] = (string)$r['value'];
    }
    return $cache[$key] ?? (string)$default;
}

function save_settings(array $pairs): void
{
    $st = db()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    foreach ($pairs as $k => $v) $st->execute([$k, $v]);
}

function audit(string $event, $details = null): void
{
    db()->prepare("INSERT INTO audit_log (event, details, user_id) VALUES (?, ?, ?)")
        ->execute([$event, is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE), $_SESSION['user_id'] ?? null]);
}
