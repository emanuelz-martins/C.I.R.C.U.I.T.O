<?php
/* ══════════════════════════════════════════════════════════
   ENDPOINT: Adicionar item ao carrinho (via AJAX)
   
   Esperado: POST com JSON
   {
       "id_comp": 123,
       "quantidade": 5
   }
   
   Resposta JSON:
   {
       "ok": true/false,
       "mensagem": "string"
   }
══════════════════════════════════════════════════════════ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../../src/config/database.php';

header('Content-Type: application/json');

/* ── Validação de autentificação ──────────────────── */
if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensagem' => 'Acesso negado. Faça login para prosseguir.']);
    exit;
}

/* ── Recebe dados JSON ────────────────────────────── */
$input = json_decode(file_get_contents('php://input'), true);
$id_comp = (int) ($input['id_comp'] ?? 0);
$quantidade = (int) ($input['quantidade'] ?? 1);

/* ── Validações ───────────────────────────────────── */
if ($id_comp <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensagem' => 'ID do item inválido.']);
    exit;
}

if ($quantidade < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensagem' => 'Quantidade deve ser pelo menos 1.']);
    exit;
}

/* ── Verifica se o item existe e está disponível ─── */
try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id_comp, qtd_disponivel, qtd_max_user, status_atual
        FROM Componente
        WHERE id_comp = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id_comp]);
    $item = $stmt->fetch();
    
    if (!$item) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado.']);
        exit;
    }
    
    if ($item['status_atual'] !== 'disponivel') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensagem' => 'Este item não está disponível para empréstimo.']);
        exit;
    }
    
    if ((int)$item['qtd_disponivel'] <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensagem' => 'Sem estoque suficiente no momento.']);
        exit;
    }
    
    /* Valida quantidade máxima por usuário */
    $qtd_max = (int)$item['qtd_max_user'];
    if ($qtd_max > 0 && $quantidade > $qtd_max) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensagem' => 'Quantidade máxima por usuário: ' . $qtd_max . '.']);
        exit;
    }
    
    /* Valida disponibilidade */
    if ($quantidade > (int)$item['qtd_disponivel']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensagem' => 'Apenas ' . (int)$item['qtd_disponivel'] . ' unidade(s) disponível(is).']);
        exit;
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensagem' => 'Erro ao acessar o banco de dados.']);
    exit;
}

/* ── Inicializa carrinho na sessão ────────────────── */
if (!isset($_SESSION['carrinho'])) {
    $_SESSION['carrinho'] = [];
}

/* ── Adiciona ou atualiza item no carrinho ───────── */
$carrinho = &$_SESSION['carrinho'];
$encontrou = false;

foreach ($carrinho as &$item_carrinho) {
    if ($item_carrinho['id'] === $id_comp) {
        $item_carrinho['quantidade'] += $quantidade;
        $encontrou = true;
        break;
    }
}

if (!$encontrou) {
    $carrinho[] = [
        'id'         => $id_comp,
        'quantidade' => $quantidade,
    ];
}

/* ── Retorna sucesso ───────────────────────────────── */
http_response_code(200);
echo json_encode([
    'ok'        => true,
    'mensagem'  => 'Item adicionado ao carrinho com sucesso!',
]);
exit;
?>
