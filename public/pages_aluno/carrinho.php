<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';

/* ── Finalizar pedido (POST) ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalizar-pedido') {
    $id_usuario = (int) ($_SESSION['auth_user']['id'] ?? 0);
    $payloadRaw = (string) ($_POST['cart_payload'] ?? '');
    $payloadArr = json_decode($payloadRaw, true);
    $prazoOpcao = trim((string) ($_POST['prazo_devolucao_opcao'] ?? ''));
    $prazoDetalhe = trim((string) ($_POST['prazo_devolucao_detalhe'] ?? ''));

    if ($id_usuario <= 0) {
        header('Location: ./carrinho.php?erro=usuario');
        exit;
    }

    if (!is_array($payloadArr) || empty($payloadArr)) {
        header('Location: ./carrinho.php?erro=itens');
        exit;
    }

    if (!in_array($prazoOpcao, ['1-3', '3-5', '3-7', '7+', 'teste'], true)) {
        header('Location: ./carrinho.php?erro=prazo');
        exit;
    }

    if ($prazoOpcao === '7+' && $prazoDetalhe === '') {
        header('Location: ./carrinho.php?erro=prazo_detalhe');
        exit;
    }

    if (mb_strlen($prazoDetalhe) > 1500) {
        $prazoDetalhe = mb_substr($prazoDetalhe, 0, 1500);
    }

    $itensPayload = [];
    foreach ($payloadArr as $row) {
        $id_comp = (int) ($row['id'] ?? 0);
        $quantidade = (int) ($row['quantidade'] ?? 0);
        if ($id_comp > 0 && $quantidade > 0) {
            if (!isset($itensPayload[$id_comp])) {
                $itensPayload[$id_comp] = ['id' => $id_comp, 'quantidade' => 0];
            }
            $itensPayload[$id_comp]['quantidade'] += $quantidade;
        }
    }

    if (empty($itensPayload)) {
        header('Location: ./carrinho.php?erro=itens');
        exit;
    }

    $itensPayload = array_values($itensPayload);

    try {
        $pdo = db();

        $getCols = static function (PDO $pdo, string $table): array {
            $stmt = $pdo->prepare('
                SELECT COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ');
            $stmt->execute(['table' => $table]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };
        $statusPreBloqueio = aluno_pre_bloqueio_status($pdo, $id_usuario);
        if (($statusPreBloqueio['pre_bloqueado'] ?? false) === true) {
            header('Location: ./carrinho.php?erro=prebloqueado');
            exit;
        }

        $pedidoCols = $getCols($pdo, 'Pedido');
        if (empty($pedidoCols)) {
            throw new RuntimeException('Tabela Pedido não encontrada.');
        }

        $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
            : (in_array('status', $pedidoCols, true) ? 'status' : null);
        $createdCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao'
            : (in_array('data', $pedidoCols, true) ? 'data' : null);
        $updatedCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;
        $numeroCol = in_array('numero_pedido', $pedidoCols, true) ? 'numero_pedido' : null;
        $devolucaoPrevistaCol = in_array('data_devolucao_prevista', $pedidoCols, true) ? 'data_devolucao_prevista' : null;
        $obsLaboratoristaCol = in_array('obs_laboratorista', $pedidoCols, true) ? 'obs_laboratorista' : null;

        if ($statusCol === null || $createdCol === null) {
            throw new RuntimeException('Colunas essenciais de Pedido não encontradas.');
        }

        $itemTable = null;
        $itemCols = [];

        foreach (['Item_Pedido', 'BemPedido'] as $candidate) {
            $cols = $getCols($pdo, $candidate);
            if (!empty($cols)) {
                $itemTable = $candidate;
                $itemCols = $cols;
                break;
            }
        }

        if ($itemTable === null) {
            throw new RuntimeException('Tabela de itens do pedido não encontrada.');
        }

        $itemPedidoCol = in_array('id_pedido', $itemCols, true) ? 'id_pedido' : null;
        $itemCompCol = in_array('id_comp', $itemCols, true) ? 'id_comp'
            : (in_array('id_componente', $itemCols, true) ? 'id_componente' : null);
        $itemQtdCol = in_array('qtd_solicitada', $itemCols, true) ? 'qtd_solicitada'
            : (in_array('quantidade', $itemCols, true) ? 'quantidade' : null);

        if ($itemPedidoCol === null || $itemCompCol === null || $itemQtdCol === null) {
            throw new RuntimeException('Colunas de itens do pedido incompatíveis.');
        }

        $ids = array_map(static fn(array $item): int => (int) $item['id'], $itensPayload);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if (empty($ids)) {
            header('Location: ./carrinho.php?erro=itens');
            exit;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmtLimites = $pdo->prepare("SELECT id_comp, qtd_disponivel, qtd_max_user, status_atual FROM Componente WHERE id_comp IN ({$ph})");
        $stmtLimites->execute($ids);
        $rows = $stmtLimites->fetchAll();
        $componentes = [];
        foreach ($rows as $r) {
            $componentes[(int) $r['id_comp']] = $r;
        }

        foreach ($itensPayload as $item) {
            $idComp = (int) $item['id'];
            $qtd = (int) $item['quantidade'];
            $comp = $componentes[$idComp] ?? null;

            if (!$comp) {
                header('Location: ./carrinho.php?erro=itens');
                exit;
            }

            if (($comp['status_atual'] ?? 'indisponivel') !== 'disponivel') {
                header('Location: ./carrinho.php?erro=indisponivel');
                exit;
            }

            $estoque = (int) ($comp['qtd_disponivel'] ?? 0);
            $maxUser = (int) ($comp['qtd_max_user'] ?? 0);
            $qtdMaxPermitida = $maxUser > 0 ? min($maxUser, $estoque) : $estoque;

            if ($qtd <= 0 || $estoque <= 0 || $qtd > $qtdMaxPermitida) {
                header('Location: ./carrinho.php?erro=limite');
                exit;
            }
        }

        $pdo->beginTransaction();

        $nextNumero = null;
        if ($numeroCol !== null) {
            $stmtNumero = $pdo->query("SELECT COALESCE(MAX({$numeroCol}), 0) + 1 AS prox FROM Pedido");
            $nextNumero = (int) ($stmtNumero->fetch()['prox'] ?? 1);
        }

        $pedidoFields = ['id_user', $statusCol, $createdCol];
        $pedidoValues = [':id_user', ':status', 'NOW()'];
        $paramsPedido = [
            'id_user' => $id_usuario,
            'status' => 'pendente',
        ];

        $prazoLabel = [
            '1-3' => '1 a 3 dias',
            '3-5' => '3 a 5 dias',
            '3-7' => '3 a 7 dias',
            '7+' => '7+ dias',
            'teste' => 'Teste (forçar atraso)',
        ][$prazoOpcao] ?? 'Não informado';

        $dataDevolucaoPrevista = null;
        if ($prazoOpcao === '1-3') {
            $dataDevolucaoPrevista = (new DateTimeImmutable('today +3 days'))->format('Y-m-d');
        } elseif ($prazoOpcao === '3-5') {
            $dataDevolucaoPrevista = (new DateTimeImmutable('today +5 days'))->format('Y-m-d');
        } elseif ($prazoOpcao === '3-7') {
            $dataDevolucaoPrevista = (new DateTimeImmutable('today +7 days'))->format('Y-m-d');
        } elseif ($prazoOpcao === 'teste') {
            $dataDevolucaoPrevista = (new DateTimeImmutable('today -1 days'))->format('Y-m-d');
        }

        $obsPrazo = 'Prazo solicitado pelo estudante: ' . $prazoLabel;
        if ($prazoOpcao === '7+' && $prazoDetalhe !== '') {
            $obsPrazo .= '. Detalhes: ' . $prazoDetalhe;
        }

        if ($numeroCol !== null) {
            $pedidoFields[] = $numeroCol;
            $pedidoValues[] = ':numero_pedido';
            $paramsPedido['numero_pedido'] = $nextNumero;
        }
        if ($updatedCol !== null) {
            $pedidoFields[] = $updatedCol;
            $pedidoValues[] = 'NOW()';
        }
        if ($devolucaoPrevistaCol !== null && $dataDevolucaoPrevista !== null) {
            $pedidoFields[] = $devolucaoPrevistaCol;
            $pedidoValues[] = ':data_devolucao_prevista';
            $paramsPedido['data_devolucao_prevista'] = $dataDevolucaoPrevista;
        }
        if ($obsLaboratoristaCol !== null) {
            $pedidoFields[] = $obsLaboratoristaCol;
            $pedidoValues[] = ':obs_laboratorista';
            $paramsPedido['obs_laboratorista'] = $obsPrazo;
        }

        $sqlPedido = sprintf(
            'INSERT INTO Pedido (%s) VALUES (%s)',
            implode(', ', $pedidoFields),
            implode(', ', $pedidoValues)
        );

        $stmtPedido = $pdo->prepare($sqlPedido);
        $stmtPedido->execute($paramsPedido);

        $id_pedido = (int) $pdo->lastInsertId();
        if ($id_pedido <= 0) {
            throw new RuntimeException('Falha ao criar pedido.');
        }

        $sqlItem = sprintf(
            'INSERT INTO %s (%s, %s, %s) VALUES (:id_pedido, :id_comp, :qtd)',
            $itemTable,
            $itemPedidoCol,
            $itemCompCol,
            $itemQtdCol
        );
        $stmtItem = $pdo->prepare($sqlItem);

        foreach ($itensPayload as $item) {
            $stmtItem->execute([
                'id_pedido' => $id_pedido,
                'id_comp' => (int) $item['id'],
                'qtd' => (int) $item['quantidade'],
            ]);
        }

        $pdo->commit();

        $_SESSION['carrinho'] = [];
        header('Location: ./carrinho.php?sucesso=1');
        exit;

    } catch (Throwable) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: ./carrinho.php?erro=finalizar');
        exit;
    }
}

$page_title = 'Carrinho';
require_once '../includes/header.php';

/* ── Itens do carrinho: carregados da sessão ── */
$itens = [];
$db_ok = false;
$alunoPreBloqueado = false;
$erro = (string) ($_GET['erro'] ?? '');
$sucesso = (string) ($_GET['sucesso'] ?? '');

try {
    $pdo = db();
    $id_usuario = (int) ($_SESSION['auth_user']['id'] ?? 0);
    $statusPreBloqueio = aluno_pre_bloqueio_status($pdo, $id_usuario);
    $alunoPreBloqueado = ($statusPreBloqueio['pre_bloqueado'] ?? false) === true;
    
    /* Obtém itens da sessão (carrinho armazenado localmente) */
    $carrinho_sessao = $_SESSION['carrinho'] ?? [];
    
    if (!empty($carrinho_sessao)) {
        /* Busca dados completos dos componentes no BD */
        foreach ($carrinho_sessao as $item_carrinho) {
            $id_comp = (int) $item_carrinho['id'];
            $quantidade = (int) ($item_carrinho['quantidade'] ?? 1);
            
            $stmt = $pdo->prepare('
                SELECT
                    c.id_comp,
                    c.nome,
                    c.descricao,
                    c.imagem_url,
                    c.qtd_disponivel,
                    c.qtd_max_user,
                    cat.nome AS categoria_nome
                FROM Componente c
                JOIN Categoria cat ON cat.id_cat = c.id_cat
                WHERE c.id_comp = :id
                LIMIT 1
            ');
            $stmt->execute(['id' => $id_comp]);
            $comp = $stmt->fetch();
            
            if ($comp) {
                $qtd_estoque = (int) ($comp['qtd_disponivel'] ?? 0);
                $qtd_max_user = (int) ($comp['qtd_max_user'] ?? 0);
                $qtd_max = $qtd_max_user > 0 ? min($qtd_max_user, $qtd_estoque) : $qtd_estoque;

                $itens[] = [
                    'id'         => $comp['id_comp'],
                    'nome'       => $comp['nome'],
                    'descricao'  => substr($comp['descricao'] ?? '', 0, 80),
                    'imagem'     => $comp['imagem_url'],
                    'categoria'  => $comp['categoria_nome'],
                    'quantidade' => max(1, min($quantidade, max(1, $qtd_max))),
                    'qtd_max'    => max(1, $qtd_max),
                ];
            }
        }
    }
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
}
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 40px 40px 120px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho da página ──────────────── */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 40px;
    }

    .page-title {
        font-size: 3rem;
        font-weight: 800;
        color: #ffffff;
        line-height: 1.1;
    }

    .btn-back {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-back:hover { background-color: #333; }

    .btn-back svg {
        width: 20px;
        height: 20px;
    }

    /* ── Lista de itens ───────────────────── */
    .cart-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 40px;
    }

    .cart-item {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .item-img {
        width: 100px;
        height: 100px;
        border-radius: 10px;
        object-fit: cover;
        flex-shrink: 0;
        background-color: #111;
    }

    .item-img-placeholder {
        width: 100px;
        height: 100px;
        border-radius: 10px;
        background-color: #111;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #444;
    }

    .item-info {
        flex: 1;
        min-width: 0;
    }

    .item-categoria {
        font-size: 0.78rem;
        color: #888;
        margin-bottom: 4px;
    }

    .item-nome {
        font-size: 1.05rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .item-descricao {
        font-size: 0.82rem;
        color: #aaa;
        line-height: 1.4;
    }

    /* ── Controles de quantidade ──────────── */
    .item-controls {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .qty-control {
        display: flex;
        align-items: center;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        overflow: hidden;
    }

    .qty-btn {
        width: 40px;
        height: 40px;
        background: none;
        border: none;
        color: #ffffff;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
    }

    .qty-btn:hover { background-color: #2a2a2a; }

    .qty-value {
        width: 44px;
        height: 40px;
        background-color: #2a2a2a;
        border: none;
        border-left: 1px solid #3a3a3a;
        border-right: 1px solid #3a3a3a;
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 600;
        text-align: center;
        outline: none;
        -moz-appearance: textfield;
    }

    .qty-value::-webkit-inner-spin-button,
    .qty-value::-webkit-outer-spin-button { -webkit-appearance: none; }

    .btn-delete {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-delete:hover { background-color: #3a1a1a; border-color: #6b2222; }

    .btn-delete svg {
        width: 18px;
        height: 18px;
    }

    /* ── Carrinho vazio ───────────────────── */
    .cart-empty {
        text-align: center;
        padding: 60px 0;
        color: #555;
    }

    .cart-empty svg {
        width: 64px;
        height: 64px;
        margin-bottom: 16px;
        color: #333;
    }

    .cart-empty p {
        font-size: 1.1rem;
    }

    /* ── Botão enviar pedido ──────────────── */
    .btn-enviar {
        display: block;
        width: 100%;
        padding: 18px;
        background-color: #ffffff;
        color: #141414;
        font-size: 1.1rem;
        font-weight: 700;
        border: none;
        border-radius: 14px;
        cursor: pointer;
        text-align: center;
        transition: background-color 0.15s;
        text-decoration: none;
    }

    .btn-enviar:hover { background-color: #e0e0e0; }

    .btn-enviar:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .confirm-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 220;
        background-color: rgba(0, 0, 0, 0.75);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .confirm-overlay.open { display: flex; }

    .confirm-box {
        width: 100%;
        max-width: 540px;
        background-color: #1e1e1e;
        border: 1px solid #333;
        border-radius: 16px;
        padding: 28px 26px;
    }

    .confirm-title {
        font-size: 1.4rem;
        color: #fff;
        margin-bottom: 6px;
    }

    .confirm-desc {
        color: #b1b1b1;
        font-size: 0.9rem;
        margin-bottom: 16px;
    }

    .confirm-label {
        display: block;
        font-size: 0.84rem;
        color: #d4d4d4;
        margin-bottom: 8px;
        font-weight: 600;
    }

    .confirm-select,
    .confirm-textarea {
        width: 100%;
        background-color: #141414;
        color: #fff;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        padding: 11px 12px;
        font-family: inherit;
        font-size: 0.9rem;
        outline: none;
    }

    .confirm-select:focus,
    .confirm-textarea:focus { border-color: #5a5a5a; }

    .confirm-textarea {
        min-height: 96px;
        resize: vertical;
    }

    .confirm-hint {
        margin-top: 10px;
        font-size: 0.8rem;
        color: #8c8c8c;
    }

    .confirm-hint.error { color: #fca5a5; }

    .confirm-actions {
        margin-top: 18px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .btn-confirm-cancel,
    .btn-confirm-submit {
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.88rem;
        font-weight: 700;
        padding: 10px 16px;
        cursor: pointer;
    }

    .btn-confirm-cancel {
        background: transparent;
        color: #bdbdbd;
        border: 1px solid #444;
    }

    .btn-confirm-cancel:hover { border-color: #777; color: #fff; }

    .btn-confirm-submit {
        background: #fff;
        color: #141414;
        border: none;
    }

    .btn-confirm-submit:hover { background: #e6e6e6; }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="../index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <form class="nav-search" action="/index.php" method="GET">
        <input
            type="text"
            name="q"
            placeholder="Pesquise por categoria, nome do item, etc."
            value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
        >
        <button type="submit" aria-label="Buscar">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
    </form>

    <div class="nav-actions">

        <a href="./verpedidos.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            Pedidos
        </a>

        <!-- Usuário + dropdown -->
        <div class="nav-user" id="navUser">
            <button class="nav-user-btn" onclick="toggleDropdown()" aria-haspopup="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <div class="greeting">
                    <span>Bem vindo,</span>
                    <strong><?= htmlspecialchars($usuario_nome) ?>!</strong>
                </div>
            </button>

            <div class="dropdown" role="menu">
                <a href="./profile.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Acessar perfil
                </a>
                <a href="./notificacoes.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    Notificações
                </a>
                <a href="../logout.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Logout
                </a>
            </div>
        </div>

    </div>
</nav>

<script>
    function toggleDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }

    document.addEventListener('click', function (e) {
        const navUser = document.getElementById('navUser');
        if (!navUser.contains(e.target)) navUser.classList.remove('open');
    });
</script>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <?php if ($sucesso === '1'): ?>
    <div style="background-color:#0f2e1d;border:1px solid #166534;border-radius:12px;padding:14px 18px;color:#86efac;margin-bottom:18px;">
        Pedido enviado com sucesso! O laboratorista já pode visualizar e avaliar sua solicitação.
    </div>
    <?php endif; ?>

    <?php if ($erro !== ''): ?>
    <div style="background-color:#2a1111;border:1px solid #7f1d1d;border-radius:12px;padding:14px 18px;color:#fca5a5;margin-bottom:18px;">
        <?php
            if ($erro === 'usuario') {
                echo 'Não foi possível identificar o usuário logado.';
            } elseif ($erro === 'itens') {
                echo 'Seu carrinho não possui itens válidos para envio.';
            } elseif ($erro === 'limite') {
                echo 'Um ou mais itens ultrapassam o limite permitido por usuário ou o estoque disponível.';
            } elseif ($erro === 'indisponivel') {
                echo 'Um ou mais itens ficaram indisponíveis para empréstimo.';
            } elseif ($erro === 'prazo') {
                echo 'Selecione um prazo de devolução válido antes de enviar o pedido.';
            } elseif ($erro === 'prazo_detalhe') {
                echo 'Para prazo 7+ dias, informe quantos dias/meses pretende ficar e o motivo.';
            } elseif ($erro === 'prebloqueado') {
                echo 'Você está pré-bloqueado, resolva sua situação com um superior, abra suas notificações e entenda mais...';
            } else {
                echo 'Não foi possível finalizar o pedido agora. Tente novamente em instantes.';
            }
        ?>
    </div>
    <?php endif; ?>

    <?php if ($alunoPreBloqueado): ?>
    <div class="alert-error" style="margin-bottom:18px;">
        Você está pré-bloqueado, resolva sua situação com um superior, abra suas notificações e entenda mais...
    </div>
    <?php endif; ?>

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Seu carrinho de itens</h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Lista de itens -->
    <?php if (!$db_ok): ?>
    <div style="background-color: #1c1c1c; border: 1px solid #3a1a1a; border-radius: 16px; padding: 40px; text-align: center; color: #aaa;">
        <h2 style="color: #ef4444; margin-bottom: 8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar o carrinho. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($itens)): ?>
    <div class="cart-empty">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <p>Seu carrinho está vazio.</p>
    </div>
    <?php else: ?>
    <div class="cart-list" id="cartList">
        <?php foreach ($itens as $item): ?>
        <div class="cart-item" id="item-<?= (int)$item['id'] ?>">

            <?php if (!empty($item['imagem'])): ?>
                <img src="<?= htmlspecialchars($item['imagem']) ?>" alt="<?= htmlspecialchars($item['nome']) ?>" class="item-img">
            <?php else: ?>
                <div class="item-img-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                </div>
            <?php endif; ?>

            <div class="item-info">
                <p class="item-categoria"><?= htmlspecialchars($item['categoria']) ?></p>
                <p class="item-nome"><?= htmlspecialchars($item['nome']) ?></p>
                <p class="item-descricao"><?= htmlspecialchars($item['descricao']) ?></p>
            </div>

            <div class="item-controls">
                <div class="qty-control">
                    <button class="qty-btn" onclick="alterarQtd(<?= (int)$item['id'] ?>, -1)" aria-label="Diminuir">-</button>
                    <input
                        type="number"
                        class="qty-value"
                        id="qty-<?= (int)$item['id'] ?>"
                        value="<?= (int)$item['quantidade'] ?>"
                        min="1"
                        max="<?= (int)$item['qtd_max'] ?>"
                        onchange="validarQtd(<?= (int)$item['id'] ?>)"
                        aria-label="Quantidade"
                    >
                    <button class="qty-btn" onclick="alterarQtd(<?= (int)$item['id'] ?>, +1)" aria-label="Aumentar">+</button>
                </div>

                <button class="btn-delete" onclick="removerItem(<?= (int)$item['id'] ?>)" aria-label="Remover item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                </button>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- Botão enviar -->
    <button class="btn-enviar" id="btnEnviar" onclick="enviarPedido()" <?= $alunoPreBloqueado ? 'disabled' : '' ?>>
        Enviar pedido
    </button>

    <form method="POST" action="./carrinho.php" id="formEnviarPedido" style="display:none;">
        <input type="hidden" name="action" value="finalizar-pedido">
        <input type="hidden" name="cart_payload" id="cartPayloadInput" value="">
        <input type="hidden" name="prazo_devolucao_opcao" id="prazoDevolucaoInput" value="">
        <input type="hidden" name="prazo_devolucao_detalhe" id="prazoDetalheInput" value="">
    </form>
    <?php endif; ?>

    <div class="confirm-overlay" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitulo">
        <div class="confirm-box">
            <h2 class="confirm-title" id="confirmTitulo">Você tem certeza?</h2>
            <p class="confirm-desc">Antes de prosseguir, preencha mais algumas informações:</p>

            <label for="prazoSelect" class="confirm-label">Data/prazo para devolução *</label>
            <select id="prazoSelect" class="confirm-select">
                <option value="">Selecione uma opção</option>
                <option value="1-3">1 a 3 dias</option>
                <option value="3-5">3 a 5 dias</option>
                <option value="7+">7+ dias</option>
                <option value="teste">Teste (forçar atraso)</option>
            </select>

            <div id="detalhePrazoWrap" style="display:none; margin-top:12px;">
                <label for="prazoDetalhe" class="confirm-label">Informe quantos dias/meses pretende ficar e por quê *</label>
                <textarea id="prazoDetalhe" class="confirm-textarea" maxlength="1500" placeholder="Ex.: 2 meses, pois vou usar no TCC e nas aulas práticas de eletrônica."></textarea>
            </div>

            <p class="confirm-hint" id="confirmHint">Essa informação ajuda o laboratorista a organizar os pacotes.</p>

            <div class="confirm-actions">
                <button type="button" class="btn-confirm-cancel" onclick="fecharModalConfirmacao()">Cancelar</button>
                <button type="button" class="btn-confirm-submit" onclick="confirmarEnvioPedido()">Confirmar envio</button>
            </div>
        </div>
    </div>

</main>

<script>
    const alunoPreBloqueado = <?= $alunoPreBloqueado ? 'true' : 'false' ?>;

    function alterarQtd(id, delta) {
        const input = document.getElementById('qty-' + id);
        const max = Math.max(1, parseInt(input.max || '1', 10) || 1);
        const novoValor = Math.min(max, Math.max(1, (parseInt(input.value) || 1) + delta));
        input.value = novoValor;
    }

    function validarQtd(id) {
        const input = document.getElementById('qty-' + id);
        const max = Math.max(1, parseInt(input.max || '1', 10) || 1);
        const valor = parseInt(input.value || '1', 10) || 1;
        input.value = Math.min(max, Math.max(1, valor));
    }

    function removerItem(id) {
        const el = document.getElementById('item-' + id);
        if (el) {
            el.style.transition = 'opacity 0.2s';
            el.style.opacity = '0';
            setTimeout(() => {
                el.remove();
                verificarCarrinhoVazio();
            }, 200);
        }
    }

    function verificarCarrinhoVazio() {
        const lista = document.getElementById('cartList');
        const btnEnviar = document.getElementById('btnEnviar');
        if (lista && lista.children.length === 0 && btnEnviar) {
            btnEnviar.disabled = true;
        }
    }

    function coletarPayloadCarrinho() {
        const lista = document.getElementById('cartList');
        if (!lista) return [];

        const payload = [];
        lista.querySelectorAll('.cart-item').forEach(function (itemEl) {
            const idStr = itemEl.id.replace('item-', '');
            const id = parseInt(idStr, 10);
            const qtyInput = document.getElementById('qty-' + id);
            const max = Math.max(1, parseInt(qtyInput ? (qtyInput.max || '1') : '1', 10) || 1);
            const quantidade = Math.min(max, Math.max(1, parseInt(qtyInput ? qtyInput.value : '1', 10) || 1));

            if (Number.isInteger(id) && id > 0) {
                payload.push({ id: id, quantidade: quantidade });
            }
        });

        return payload;
    }

    function enviarPedido() {
        if (alunoPreBloqueado) {
            alert('Você está pré-bloqueado, resolva sua situação com um superior, abra suas notificações e entenda mais...');
            return;
        }

        const payload = coletarPayloadCarrinho();

        if (payload.length === 0) {
            alert('Seu carrinho está vazio.');
            return;
        }

        document.getElementById('confirmHint').textContent = 'Essa informação ajuda o laboratorista a organizar os pacotes.';
        document.getElementById('confirmHint').classList.remove('error');
        document.getElementById('prazoSelect').value = '';
        document.getElementById('prazoDetalhe').value = '';
        document.getElementById('detalhePrazoWrap').style.display = 'none';
        document.getElementById('confirmModal').classList.add('open');
    }

    function fecharModalConfirmacao() {
        document.getElementById('confirmModal').classList.remove('open');
    }

    document.getElementById('prazoSelect').addEventListener('change', function () {
        const wrap = document.getElementById('detalhePrazoWrap');
        if (this.value === '7+') {
            wrap.style.display = 'block';
        } else {
            wrap.style.display = 'none';
        }
    });

    function confirmarEnvioPedido() {
        const payload = coletarPayloadCarrinho();
        if (payload.length === 0) {
            alert('Seu carrinho está vazio.');
            return;
        }

        const prazo = (document.getElementById('prazoSelect').value || '').trim();
        const detalhe = (document.getElementById('prazoDetalhe').value || '').trim();
        const hint = document.getElementById('confirmHint');

        if (!['1-3', '3-5', '3-7', '7+', 'teste'].includes(prazo)) {
            hint.textContent = 'Selecione um prazo de devolução para continuar.';
            hint.classList.add('error');
            return;
        }

        if (prazo === '7+' && detalhe === '') {
            hint.textContent = 'Para 7+ dias, informe quantos dias/meses pretende ficar e o motivo.';
            hint.classList.add('error');
            document.getElementById('prazoDetalhe').focus();
            return;
        }

        hint.classList.remove('error');

        document.getElementById('cartPayloadInput').value = JSON.stringify(payload);
        document.getElementById('prazoDevolucaoInput').value = prazo;
        document.getElementById('prazoDetalheInput').value = detalhe;
        document.getElementById('formEnviarPedido').submit();
    }

    document.getElementById('confirmModal').addEventListener('click', function (e) {
        if (e.target === this) fecharModalConfirmacao();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fecharModalConfirmacao();
    });
</script>

</body>
</html>
