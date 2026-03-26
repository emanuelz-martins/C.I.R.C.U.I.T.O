<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_usuario = $_SESSION['auth_user']['id'] ?? null;
if (!$id_usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado']);
    exit;
}

$acao  = $_POST['acao']   ?? '';
$id    = (int)($_POST['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

try {
    $pdo = db();

    if ($acao === 'marcar_lida') {
        $stmt = $pdo->prepare('
            UPDATE Notificacao SET lida = 1
            WHERE id_not = :id AND id_user = :uid
        ');
        $stmt->execute(['id' => $id, 'uid' => $id_usuario]);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'excluir') {
        $stmt = $pdo->prepare('
            DELETE FROM Notificacao
            WHERE id_not = :id AND id_user = :uid
        ');
        $stmt->execute(['id' => $id, 'uid' => $id_usuario]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
