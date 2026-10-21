<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
loadEnv(dirname(__DIR__, 2) . '/.env');
$publicPath = rtrim((string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH), '/');
header('Location: ' . $publicPath . '/perfil.php');
exit;
