<?php
/* ══════════════════════════════════════════════════════════
   ENDPOINT: Arquivar / desarquivar pedido (visão laboratorista)
   POST { id: int, acao: "arquivar"|"desarquivar" }
   Resposta JSON { ok: bool, erro?: string }
══════════════════════════════════════════════════════════ */

require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id   = (int) ($_POST['id']   ?? 0);
$acao = trim($_POST['acao']   ?? '');

if ($id <= 0 || !in_array($acao, ['arquivar', 'desarquivar'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Parâmetros inválidos']);
    exit;
}

try {
    $pdo = db();

    /* Garante que a coluna existe (idempotente) */
    $pdo->exec('
        ALTER TABLE Pedido
        ADD COLUMN IF NOT EXISTS arquivado_lab TINYINT(1) NOT NULL DEFAULT 0
    ');

    $valor = ($acao === 'arquivar') ? 1 : 0;

    $stmt = $pdo->prepare('
        UPDATE Pedido
        SET    arquivado_lab = :val
        WHERE  id_pedido = :id
    ');
    $stmt->execute(['val' => $valor, 'id' => $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    echo json_encode(['ok' => true]);

} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
