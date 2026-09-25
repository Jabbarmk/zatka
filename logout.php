<?php
require_once __DIR__ . '/includes/auth.php';
audit('auth.logout');
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
