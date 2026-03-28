<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_user = (int) ($_GET['id_user'] ?? 0);

if (!$id_user) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

try {
    $pdo = db();

    $stmtUser = $pdo->prepare('SELECT id_user, nome, tipo_perfil FROM Usuario WHERE id_user = ?');
    $stmtUser->execute([$id_user]);
    $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Usuário não encontrado']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT p.id_pedido, p.status, p.data_criacao,
               p.data_devolucao_prevista, p.data_devolucao_real,
               p.nome_laboratorista_responsavel,
               COUNT(ip.id_item) AS total_itens
        FROM Pedido p
        LEFT JOIN Item_Pedido ip ON ip.id_pedido = p.id_pedido
        WHERE p.id_user = ?
        GROUP BY p.id_pedido
        ORDER BY p.data_criacao DESC
    ');
    $stmt->execute([$id_user]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Verificar se tabela Relatorio existe e tem coluna id_pedido */
    $relCols = [];
    try {
        $stmtCols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Relatorio'
        ");
        $relCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {}

    $temIdPedidoCol = in_array('id_pedido', $relCols, true);

    foreach ($pedidos as &$ped) {
        $ped['tem_relatorio'] = false;
        if ($temIdPedidoCol) {
            try {
                $stmtRel = $pdo->prepare('SELECT COUNT(*) FROM Relatorio WHERE id_pedido = ?');
                $stmtRel->execute([$ped['id_pedido']]);
                $ped['tem_relatorio'] = (int) $stmtRel->fetchColumn() > 0;
            } catch (Throwable) {}
        }
    }
    unset($ped);

    echo json_encode(['ok' => true, 'usuario' => $usuario, 'pedidos' => $pedidos]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
