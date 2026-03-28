<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_pedido  = (int) ($_POST['id_pedido'] ?? 0);
$mensagem   = trim((string) ($_POST['mensagem'] ?? ''));
$nome_admin = $_SESSION['auth_user']['nome'] ?? 'Administrador';

if (!$id_pedido || $mensagem === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos']);
    exit;
}

if (mb_strlen($mensagem) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Mensagem muito longa (máx. 2000 caracteres)']);
    exit;
}

try {
    $pdo = db();

    /* Garantir colunas necessárias */
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT \'automatica\'');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS humor VARCHAR(10) NULL DEFAULT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL');

    /* Verificar se pedido existe */
    $stmtPed = $pdo->prepare('
        SELECT p.id_pedido, p.id_user, p.id_laboratorista_responsavel, u.nome AS nome_aluno
        FROM Pedido p
        JOIN Usuario u ON u.id_user = p.id_user
        WHERE p.id_pedido = ?
    ');
    $stmtPed->execute([$id_pedido]);
    $pedido = $stmtPed->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    /* Determinar quais laboratoristas notificar */
    $labIds = [];
    if (!empty($pedido['id_laboratorista_responsavel'])) {
        $labIds[] = (int) $pedido['id_laboratorista_responsavel'];
    } else {
        $stmtLabs = $pdo->query("
            SELECT id_user FROM Usuario
            WHERE tipo_perfil = 'laboratorista' AND bloqueado = 0
        ");
        $labIds = $stmtLabs->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($labIds)) {
        echo json_encode(['ok' => true, 'aviso' => 'Nenhum laboratorista disponível para notificar']);
        exit;
    }

    $titulo      = "Notificação sobre Pedido #{$id_pedido}";
    $nomeAluno   = $pedido['nome_aluno'];
    $msgCompleta = "O administrador {$nome_admin} te enviou a seguinte notificação sobre o Pedido #{$id_pedido} do usuário {$nomeAluno}:\n\n{$mensagem}";

    $stmtIns = $pdo->prepare('
        INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor, lida, data, id_pedido)
        VALUES (:id_user, :titulo, :mensagem, \'aviso_adm\', \'neutro\', 0, NOW(), :id_pedido)
    ');

    foreach ($labIds as $labId) {
        $stmtIns->execute([
            'id_user'   => $labId,
            'titulo'    => $titulo,
            'mensagem'  => $msgCompleta,
            'id_pedido' => $id_pedido,
        ]);
    }

    echo json_encode(['ok' => true, 'notificados' => count($labIds)]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
