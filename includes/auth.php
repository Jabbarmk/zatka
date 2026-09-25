<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function users_exist(): bool
{
    return (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
}

function require_login(): void
{
    if (!users_exist()) {
        header('Location: ' . BASE_URL . '/setup.php');
        exit;
    }
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare("SELECT id, username FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
