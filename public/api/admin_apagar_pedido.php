<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$id_pedido = (int) ($_POST['id_pedido'] ?? 0);

if (!$id_pedido) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id_pedido FROM Pedido WHERE id_pedido = ?');
    $stmt->execute([$id_pedido]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    /* Remover notificações vinculadas */
    try {
        $pdo->prepare('DELETE FROM Notificacao WHERE id_pedido = ?')->execute([$id_pedido]);
    } catch (Throwable) {}

    /* Remover chat vinculado */
    try {
        $stmtChat = $pdo->prepare('SELECT id_chat FROM Pedido_Chat WHERE id_pedido = ?');
        $stmtChat->execute([$id_pedido]);
        $chats = $stmtChat->fetchAll(PDO::FETCH_COLUMN);
        foreach ($chats as $chatId) {
            $pdo->prepare('DELETE FROM Pedido_Chat_Mensagem WHERE id_chat = ?')->execute([$chatId]);
        }
        $pdo->prepare('DELETE FROM Pedido_Chat WHERE id_pedido = ?')->execute([$id_pedido]);
    } catch (Throwable) {}

    /* Remover notas de atraso vinculadas */
    try {
        $stmtNotas = $pdo->prepare('SELECT id_nota FROM Pedido_Atraso_Nota WHERE id_pedido = ?');
        $stmtNotas->execute([$id_pedido]);
        $notas = $stmtNotas->fetchAll(PDO::FETCH_COLUMN);
        foreach ($notas as $notaId) {
            $pdo->prepare('DELETE FROM Pedido_Atraso_Mensagem WHERE id_nota = ?')->execute([$notaId]);
        }
        $pdo->prepare('DELETE FROM Pedido_Atraso_Nota WHERE id_pedido = ?')->execute([$id_pedido]);
    } catch (Throwable) {}

    /* Apagar pedido (cascata: Item_Pedido, Ocorrencia, Renovacao) */
    $pdo->prepare('DELETE FROM Pedido WHERE id_pedido = ?')->execute([$id_pedido]);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno ao apagar pedido']);
}
