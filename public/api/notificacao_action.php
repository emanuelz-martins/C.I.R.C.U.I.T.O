<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';

header('Content-Type: application/json');

$id_usuario = $_SESSION['auth_user']['id'] ?? null;
if (!$id_usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado']);
    exit;
}

$acao = $_POST['acao'] ?? '';
$id = (int) ($_POST['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS Pedido_Atraso_Mensagem (
        id_msg INT NOT NULL AUTO_INCREMENT,
        id_nota INT NOT NULL,
        autor_tipo VARCHAR(20) NOT NULL,
        mensagem TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_msg),
        KEY idx_pam_nota_data (id_nota, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_nota_atraso INT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS resposta_pendente TINYINT(1) NOT NULL DEFAULT 0');

    $stmtNot = $pdo->prepare('
        SELECT id_not, id_nota_atraso, resposta_pendente
        FROM Notificacao
        WHERE id_not = :id AND id_user = :uid
        LIMIT 1
    ');
    $stmtNot->execute(['id' => $id, 'uid' => $id_usuario]);
    $notif = $stmtNot->fetch();

    if (!$notif) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Notificação não encontrada']);
        exit;
    }

    $idNotaAtraso = (int) ($notif['id_nota_atraso'] ?? 0);

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

    } elseif ($acao === 'responder_atraso') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Canal de resposta desativado. O contato agora é feito por e-mail.']);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida']);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
