<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_usuario = $_SESSION['auth_user']['id'] ?? null;
$acao       = $_POST['acao'] ?? '';
$id         = (int) ($_POST['id'] ?? 0);

if (!$id_usuario || !$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = db();

    $stmtNot = $pdo->prepare('
        SELECT id_not FROM Notificacao
        WHERE id_not = ? AND id_user = ? AND tipo = \'aviso_adm\'
    ');
    $stmtNot->execute([$id, $id_usuario]);

    if (!$stmtNot->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Notificação não encontrada']);
        exit;
    }

    if ($acao === 'marcar_lida') {
        $pdo->prepare('UPDATE Notificacao SET lida = 1 WHERE id_not = ? AND id_user = ?')
            ->execute([$id, $id_usuario]);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'excluir') {
        $pdo->prepare('DELETE FROM Notificacao WHERE id_not = ? AND id_user = ?')
            ->execute([$id, $id_usuario]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
