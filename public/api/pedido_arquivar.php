<?php
/* ══════════════════════════════════════════════════════════
   ENDPOINT: Arquivar / desarquivar pedido
   POST { id: int, acao: "arquivar"|"desarquivar" }
   Resposta JSON { ok: bool, erro?: string }
══════════════════════════════════════════════════════════ */

require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_usuario = (int) ($_SESSION['auth_user']['id'] ?? 0);
if ($id_usuario <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado']);
    exit;
}

$id   = (int) ($_POST['id']   ?? 0);
$acao = trim($_POST['acao']   ?? '');

if ($id <= 0 || !in_array($acao, ['arquivar', 'desarquivar'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Parâmetros inválidos']);
    exit;
}

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS Pedido_Atraso_Nota (
        id_nota INT NOT NULL AUTO_INCREMENT,
        id_pedido INT NOT NULL,
        id_user INT NOT NULL,
        id_laboratorista INT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'aguardando-aluno',
        obrigatoria TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id_nota),
        KEY idx_pan_pedido_status (id_pedido, status),
        KEY idx_pan_user_status (id_user, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Garante que a coluna existe (executa uma vez, idempotente) */
    $pdo->exec('
        ALTER TABLE Pedido
        ADD COLUMN IF NOT EXISTS arquivado TINYINT(1) NOT NULL DEFAULT 0
    ');

    $valor = ($acao === 'arquivar') ? 1 : 0;

    if ($acao === 'arquivar') {
        $stmtBloqueio = $pdo->prepare('
            SELECT 1
            FROM Pedido_Atraso_Nota pan
            WHERE pan.id_pedido = :id
              AND pan.id_user = :uid
              AND pan.obrigatoria = 1
              AND pan.status = "aguardando-aluno"
            LIMIT 1
        ');
        $stmtBloqueio->execute(['id' => $id, 'uid' => $id_usuario]);

        if ($stmtBloqueio->fetch()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Responda a nota de atraso antes de arquivar o pedido']);
            exit;
        }
    }

    $stmt = $pdo->prepare('
        UPDATE Pedido
        SET    arquivado = :val
        WHERE  id_pedido = :id
          AND  id_user   = :uid
    ');
    $stmt->execute(['val' => $valor, 'id' => $id, 'uid' => $id_usuario]);

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
