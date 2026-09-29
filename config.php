<?php
// Application configuration.
// For a live server, create config.local.php next to this file (it is not committed to git) and define
// DB_HOST, DB_NAME, DB_USER and DB_PASS there. Anything defined there overrides the defaults below.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', getenv('ZATCA_DB_NAME') ?: 'zatca_einvoice');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('APP_NAME') || define('APP_NAME', 'ZATCA E-Invoicing');
// Folder the app is served from, e.g. "/zatka" locally or "" on its own domain. Detected automatically.
defined('BASE_URL') || define('BASE_URL', PHP_SAPI === 'cli' ? '' : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));
defined('DEFAULT_VAT_RATE') || define('DEFAULT_VAT_RATE', 15.00);
date_default_timezone_set('Asia/Riyadh');
