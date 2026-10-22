<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
require_once __DIR__ . '/../../src/services/notificacoes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $resumo = resumoNotificacoesNaoLidas(db(), (int) $_SESSION['auth_user']['id'], (string) $_SESSION['auth_user']['perfil']);
    echo json_encode(['ok' => true, 'resumo' => $resumo]);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
