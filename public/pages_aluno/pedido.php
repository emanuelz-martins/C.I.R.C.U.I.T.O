<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

/* ── Dados do pedido: serão carregados do BD ── */
$id_pedido = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id_pedido = ($id_pedido && $id_pedido > 0) ? $id_pedido : null;

$page_title = 'Pedido';
require_once '../includes/header.php';

$pedido = null;
$db_ok = false;

try {
    $pdo = db();
    $id_usuario = (int) ($_SESSION['auth_user']['id'] ?? $_SESSION['auth_user']['id_user'] ?? 0);

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

    if ($id_usuario > 0 && $id_pedido !== null) {
        $stmtBloqueio = $pdo->prepare('
            SELECT 1
            FROM Pedido_Atraso_Nota pan
            WHERE pan.id_pedido = :id_pedido
              AND pan.id_user = :id_user
              AND pan.obrigatoria = 1
              AND pan.status = "aguardando-aluno"
            LIMIT 1
        ');
        $stmtBloqueio->execute(['id_pedido' => $id_pedido, 'id_user' => $id_usuario]);
        if ($stmtBloqueio->fetch()) {
            header('Location: ./notificacoes.php?erro=resposta_atraso');
            exit;
        }
    }

    if ($id_usuario > 0) {
        $getCols = static function (PDO $pdo, string $table): array {
            $stmt = $pdo->prepare('
                SELECT COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ');
            $stmt->execute(['table' => $table]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };

        $statusLabel = static function (string $status): string {
            $map = [
                'pendente' => 'Enviado',
                'enviado' => 'Enviado',
                'em-separacao' => 'Em separação',
                'pronto-para-retirada' => 'Pronto para retirada',
                'em-andamento' => 'Em andamento',
                'em-atraso' => 'Em atraso',
                'renovacao-solicitada' => 'Renovação solicitada',
                'negado' => 'Negado',
                'cancelado' => 'Cancelado',
                'finalizado' => 'Finalizado',
            ];
            return $map[$status] ?? ucfirst(str_replace('-', ' ', $status));
        };

        $pedidoCols = $getCols($pdo, 'Pedido');
        if (!empty($pedidoCols)) {
            $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
                : (in_array('status', $pedidoCols, true) ? 'status' : null);
            $numeroCol = in_array('numero_pedido', $pedidoCols, true) ? 'numero_pedido' : null;
            $dataCriacaoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao' : null;
            $dataAtualizacaoCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;

            $dataEntregaCol = null;
            foreach (['data_entrega', 'data_devolucao_prevista', 'data_retirada_prevista'] as $candidate) {
                if (in_array($candidate, $pedidoCols, true)) {
                    $dataEntregaCol = $candidate;
                    break;
                }
            }

            if ($statusCol !== null) {
                $select = [
                    'p.id_pedido',
                    ($numeroCol !== null ? "p.{$numeroCol}" : 'p.id_pedido') . ' AS numero_pedido',
                    "p.{$statusCol} AS status_pedido",
                    ($dataEntregaCol !== null ? "DATE_FORMAT(p.{$dataEntregaCol}, '%d/%m/%Y')" : 'NULL') . ' AS data_entrega',
                    ($dataCriacaoCol !== null ? "DATE_FORMAT(p.{$dataCriacaoCol}, '%d/%m/%Y')" : 'NULL') . ' AS data_criacao_fmt',
                    ($dataAtualizacaoCol !== null ? "DATE_FORMAT(p.{$dataAtualizacaoCol}, '%d/%m/%Y')" : 'NULL') . ' AS data_atualizacao_fmt',
                ];

                $where = ['p.id_user = :id_user'];
                $params = ['id_user' => $id_usuario];
                if ($id_pedido !== null) {
                    $where[] = 'p.id_pedido = :id_pedido';
                    $params['id_pedido'] = $id_pedido;
                }

                $orderBy = $dataAtualizacaoCol !== null ? "p.{$dataAtualizacaoCol} DESC" : 'p.id_pedido DESC';
                $sql = '
                    SELECT ' . implode(",\n                           ", $select) . '
                    FROM Pedido p
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY ' . $orderBy . ', p.id_pedido DESC
                    LIMIT 1
                ';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();

                if ($row) {
                    $rawStatus = (string) ($row['status_pedido'] ?? 'pendente');
                    $statusUi = $rawStatus === 'pendente' ? 'enviado' : $rawStatus;

                    $pedido = [
                        'id_pedido' => (int) $row['id_pedido'],
                        'numero' => $row['numero_pedido'] ?? $row['id_pedido'],
                        'numero_pedido' => $row['numero_pedido'] ?? $row['id_pedido'],
                        'status' => $statusUi,
                        'status_pedido' => $rawStatus,
                        'data_entrega' => $row['data_entrega'] ?? 'N/A',
                        'itens' => [],
                        'historico' => [],
                    ];

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

                    if ($itemTable !== null) {
                        $itemPedidoCol = in_array('id_pedido', $itemCols, true) ? 'id_pedido' : null;
                        $itemCompCol = in_array('id_comp', $itemCols, true) ? 'id_comp'
                            : (in_array('id_componente', $itemCols, true) ? 'id_componente' : null);
                        $itemQtdCol = in_array('qtd_solicitada', $itemCols, true) ? 'qtd_solicitada'
                            : (in_array('quantidade', $itemCols, true) ? 'quantidade' : null);

                        if ($itemPedidoCol !== null && $itemCompCol !== null && $itemQtdCol !== null) {
                            $stmtItens = $pdo->prepare('
                                SELECT
                                    c.id_comp,
                                    c.nome,
                                    c.descricao,
                                    c.imagem_url AS imagem,
                                    cat.nome AS categoria,
                                    i.' . $itemQtdCol . ' AS quantidade
                                FROM ' . $itemTable . ' i
                                JOIN Componente c ON c.id_comp = i.' . $itemCompCol . '
                                LEFT JOIN Categoria cat ON cat.id_cat = c.id_cat
                                WHERE i.' . $itemPedidoCol . ' = :id_pedido
                                ORDER BY c.nome ASC
                            ');
                            $stmtItens->execute(['id_pedido' => $pedido['id_pedido']]);
                            $pedido['itens'] = $stmtItens->fetchAll();
                        }
                    }

                    $pedido['historico'][] = [
                        'data' => $row['data_criacao_fmt'] ?? '—',
                        'status' => 'enviado',
                        'status_label' => $statusLabel('enviado'),
                    ];

                    if ($statusUi !== 'enviado') {
                        $pedido['historico'][] = [
                            'data' => $row['data_atualizacao_fmt'] ?? ($row['data_criacao_fmt'] ?? '—'),
                            'status' => $statusUi,
                            'status_label' => $statusLabel($statusUi),
                        ];
                    }
                }
            }
        }
    }
    
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
}

$etapas = [
    ['key' => 'enviado',             'label' => 'Enviado'],
    ['key' => 'em-separacao',        'label' => 'Em separação'],
    ['key' => 'pronto-para-retirada','label' => 'Pronto para retirada'],
    ['key' => 'em-andamento',        'label' => 'Em andamento'],
    ['key' => 'finalizado',          'label' => 'Finalizado'],
];
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 40px 40px 80px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho da página ──────────────── */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 36px;
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

    /* ── Seção de estado ──────────────────── */
    .section-title {
        font-size: 1.8rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 16px;
    }

    .estado-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }

    .data-entrega {
        font-size: 1.5rem;
        font-weight: 800;
        color: #ffffff;
        white-space: nowrap;
    }

    /* ── Barra de progresso de status ──────── */
    .status-bar {
        display: flex;
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 50px;
        overflow: hidden;
        margin-bottom: 28px;
    }

    .status-step {
        flex: 1;
        padding: 10px 4px;
        text-align: center;
        font-size: 0.82rem;
        color: #888;
        border-radius: 50px;
        cursor: default;
        white-space: nowrap;
        transition: background-color 0.2s, color 0.2s;
    }

    .status-step.active {
        background-color: #ffffff;
        color: #141414;
        font-weight: 700;
    }

    /* ── Chat de acompanhamento ───────────── */
    .chat-bloco {
        margin-bottom: 40px;
        background-color: #1b1b1b;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        overflow: hidden;
    }

    .chat-header {
        padding: 14px 18px;
        border-bottom: 1px solid #2a2a2a;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .chat-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #ffffff;
    }

    .chat-status {
        font-size: 0.78rem;
        color: #a3a3a3;
    }

    .chat-status.atrasado {
        color: #fca5a5;
        font-weight: 700;
    }

    .chat-mensagens {
        max-height: 260px;
        overflow-y: auto;
        padding: 14px 18px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        border-bottom: 1px solid #2a2a2a;
    }

    .chat-vazio {
        color: #777;
        text-align: center;
        padding: 10px 0;
        font-size: 0.84rem;
    }

    .chat-baloon {
        max-width: 85%;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 0.84rem;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .chat-baloon.aluno {
        align-self: flex-end;
        background-color: #1f2937;
        border: 1px solid #374151;
        color: #e5e7eb;
    }

    .chat-baloon.laboratorista {
        align-self: flex-start;
        background-color: #2a1a1a;
        border: 1px solid #7f1d1d;
        color: #fecaca;
    }

    .chat-baloon.sistema {
        align-self: center;
        background-color: #202020;
        border: 1px solid #333;
        color: #d4d4d4;
    }

    .chat-acoes {
        padding: 12px 18px 0;
        display: flex;
        gap: 10px;
    }

    .btn-renovar-chat {
        border: none;
        border-radius: 10px;
        padding: 10px 12px;
        background-color: #f5f5f5;
        color: #141414;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
    }

    .chat-form {
        padding: 12px 18px 16px;
    }

    .chat-textarea {
        width: 100%;
        min-height: 90px;
        border-radius: 10px;
        border: 1.5px solid #333;
        background-color: #111;
        color: #fff;
        font-size: 0.88rem;
        padding: 10px 12px;
        resize: vertical;
        outline: none;
        font-family: inherit;
    }

    .chat-erro {
        margin-top: 8px;
        color: #f87171;
        font-size: 0.8rem;
        min-height: 16px;
    }

    .chat-form-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 10px;
    }

    .btn-chat-enviar {
        border: none;
        border-radius: 10px;
        padding: 10px 14px;
        background-color: #7f1d1d;
        color: #fff;
        font-size: 0.84rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
    }

    /* ── Itens do pedido ──────────────────── */
    .item-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 40px;
    }

    .item-img {
        width: 90px;
        height: 90px;
        border-radius: 10px;
        background-color: #2a2a2a;
        object-fit: cover;
        flex-shrink: 0;
    }

    .item-img-placeholder {
        width: 90px;
        height: 90px;
        border-radius: 10px;
        background-color: #2a2a2a;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #555;
    }

    .item-info {
        flex: 1;
    }

    .item-categoria {
        font-size: 0.78rem;
        color: #888;
        margin-bottom: 4px;
    }

    .item-nome {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .item-descricao {
        font-size: 0.82rem;
        color: #aaa;
    }

    .item-quantidade {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
        white-space: nowrap;
        flex-shrink: 0;
    }

    /* ── Histórico de atualizações ────────── */
    .historico-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .historico-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 14px;
        padding: 18px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .historico-data {
        font-size: 0.95rem;
        font-weight: 600;
        color: #ffffff;
    }

    .historico-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .status-badge {
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 700;
        white-space: nowrap;
        text-align: center;
    }

    .status-badge.em-andamento       { background-color: #1a56db; color: #ffffff; }
    .status-badge.pronto-para-retirada { background-color: #b45309; color: #ffffff; }
    .status-badge.em-separacao       { background-color: #7e22ce; color: #ffffff; }
    .status-badge.enviado            { background-color: #374151; color: #ffffff; }
    .status-badge.finalizado         { background-color: #166534; color: #ffffff; }
    .status-badge.cancelado          { background-color: #b91c1c; color: #ffffff; }

    .btn-details {
        width: 40px;
        height: 40px;
        border-radius: 10px;
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

    .btn-details:hover { background-color: #333; }

    .btn-details svg {
        width: 18px;
        height: 18px;
    }
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

        <a href="./carrinho.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            Carrinho
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

    <!-- Cabeçalho -->
    <?php if (!$db_ok || !$pedido): ?>
    <div style="background-color: #1c1c1c; border: 1px solid #3a1a1a; border-radius: 16px; padding: 40px; text-align: center; color: #aaa;">
        <h2 style="color: #ef4444; margin-bottom: 8px;">Pedido não encontrado</h2>
        <p>Não foi possível carregar os dados deste pedido. Verifique a conexão com o MySQL ou volte à página anterior.</p>
    </div>
    <?php else: ?>
    <div class="page-header">
        <h1 class="page-title">Pedido #<?= htmlspecialchars($pedido['numero'] ?? $pedido['numero_pedido'] ?? '') ?></h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Estado atual -->
    <div class="estado-header">
        <h2 class="section-title">Estado atual do pedido</h2>
        <span class="data-entrega">Data de entrega: <?= htmlspecialchars($pedido['data_entrega'] ?? 'N/A') ?></span>
    </div>

    <div class="status-bar">
        <?php foreach ($etapas as $etapa): ?>
            <div class="status-step <?= ($pedido['status'] ?? '') === $etapa['key'] ? 'active' : '' ?>">
                <?= htmlspecialchars($etapa['label']) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Chat de acompanhamento -->
    <h2 class="section-title">Chat de acompanhamento</h2>
    <div class="chat-bloco">
        <div class="chat-header">
            <span class="chat-title">Conversa do pedido #<?= htmlspecialchars($pedido['numero'] ?? $pedido['numero_pedido'] ?? '') ?></span>
            <span class="chat-status" id="pedidoChatStatus">Carregando status...</span>
        </div>

        <div class="chat-mensagens" id="pedidoChatMensagens">
            <p class="chat-vazio">Carregando conversa...</p>
        </div>

        <div class="chat-acoes" id="pedidoChatAcoes" style="display:none">
            <button type="button" class="btn-renovar-chat" onclick="solicitarRenovacaoChat()">Solicitar renovação pelo chat</button>
        </div>

        <form class="chat-form" id="pedidoChatForm">
            <textarea class="chat-textarea" id="pedidoChatMensagem" maxlength="2000" placeholder="Escreva sua mensagem para o laboratorista..."></textarea>
            <p class="chat-erro" id="pedidoChatErro"></p>
            <div class="chat-form-actions">
                <button type="submit" class="btn-chat-enviar">Enviar mensagem</button>
            </div>
        </form>
    </div>

    <!-- Itens do pedido -->
    <h2 class="section-title">Itens do pedido</h2>

    <?php if (isset($pedido['itens']) && !empty($pedido['itens'])): ?>
    <?php foreach ($pedido['itens'] as $item): ?>
    <div class="item-card">
        <?php if (!empty($item['imagem']) && file_exists(__DIR__ . '/' . $item['imagem'])): ?>
            <img src="<?= htmlspecialchars($item['imagem']) ?>" alt="<?= htmlspecialchars($item['nome']) ?>" class="item-img">
        <?php else: ?>
            <div class="item-img-placeholder">
                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
            </div>
        <?php endif; ?>

        <div class="item-info">
            <p class="item-categoria"><?= htmlspecialchars($item['categoria']) ?></p>
            <p class="item-nome"><?= htmlspecialchars($item['nome']) ?></p>
            <p class="item-descricao"><?= htmlspecialchars($item['descricao']) ?></p>
        </div>

        <span class="item-quantidade">Quantidade: <?= (int)$item['quantidade'] ?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p style="color: #888; text-align: center; padding: 20px;">Nenhum item neste pedido.</p>
    <?php endif; ?>

    <!-- Histórico de atualizações -->
    <h2 class="section-title">Histórico de atualizações</h2>

    <?php if (isset($pedido['historico']) && !empty($pedido['historico'])): ?>
    <div class="historico-list">
        <?php foreach ($pedido['historico'] as $entrada): ?>
        <div class="historico-card">
            <span class="historico-data">Data: <?= htmlspecialchars($entrada['data']) ?></span>
            <div class="historico-actions">
                <span class="status-badge <?= htmlspecialchars($entrada['status']) ?>">
                    <?= htmlspecialchars($entrada['status_label']) ?>
                </span>
                <button class="btn-details" aria-label="Ver detalhes da atualização">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8" y1="6" x2="21" y2="6"/>
                        <line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/>
                        <line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color: #888; text-align: center; padding: 20px;">Nenhuma atualização registrada ainda.</p>
    <?php endif; ?>
    <?php endif; ?>

</main>

<script>
    const pedidoChatId = <?= (int) ($pedido['id_pedido'] ?? 0) ?>;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function carregarPedidoChat() {
        if (!pedidoChatId) return;

        const statusEl = document.getElementById('pedidoChatStatus');
        const listEl = document.getElementById('pedidoChatMensagens');
        const erroEl = document.getElementById('pedidoChatErro');
        const acoesEl = document.getElementById('pedidoChatAcoes');

        erroEl.textContent = '';

        try {
            const params = new URLSearchParams({ acao: 'listar', id_pedido: String(pedidoChatId) });
            const res = await fetch('../api/pedido_chat.php?' + params.toString());
            const data = await res.json();

            if (!data.ok) {
                listEl.innerHTML = '<p class="chat-vazio">Não foi possível carregar o chat.</p>';
                statusEl.textContent = data.erro || 'Erro no carregamento';
                return;
            }

            const status = (data.pedido && data.pedido.status) ? data.pedido.status : '—';
            const atrasado = Boolean(data.pedido && data.pedido.atrasado);
            const dias = Number((data.pedido && data.pedido.dias_atraso) || 0);
            statusEl.classList.toggle('atrasado', atrasado);
            statusEl.textContent = atrasado ? `Atrasado há ${dias} dia(s)` : `Status atual: ${status}`;

            const mensagens = Array.isArray(data.mensagens) ? data.mensagens : [];
            if (mensagens.length === 0) {
                listEl.innerHTML = '<p class="chat-vazio">Sem mensagens ainda.</p>';
            } else {
                listEl.innerHTML = mensagens.map((m) => {
                    const tipo = m.autor_tipo || 'sistema';
                    return `<div class="chat-baloon ${escapeHtml(tipo)}">${escapeHtml(m.mensagem || '')}</div>`;
                }).join('');
                listEl.scrollTop = listEl.scrollHeight;
            }

            const podeRenovar = Boolean(data.chat && data.chat.pode_solicitar_renovacao);
            acoesEl.style.display = podeRenovar ? 'flex' : 'none';
        } catch (_) {
            listEl.innerHTML = '<p class="chat-vazio">Falha de comunicação com o servidor.</p>';
        }
    }

    async function solicitarRenovacaoChat() {
        const erroEl = document.getElementById('pedidoChatErro');
        erroEl.textContent = '';

        const texto = (window.prompt('Descreva rapidamente o motivo da renovação:') || '').trim();

        const fd = new FormData();
        fd.append('acao', 'solicitar_renovacao');
        fd.append('id_pedido', String(pedidoChatId));
        if (texto) fd.append('mensagem', texto);

        try {
            const res = await fetch('../api/pedido_chat.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
                erroEl.textContent = data.erro || 'Não foi possível solicitar renovação.';
                return;
            }
            await carregarPedidoChat();
        } catch (_) {
            erroEl.textContent = 'Falha de comunicação com o servidor.';
        }
    }

    const pedidoChatForm = document.getElementById('pedidoChatForm');
    if (pedidoChatForm) {
    pedidoChatForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const erroEl = document.getElementById('pedidoChatErro');
        const textarea = document.getElementById('pedidoChatMensagem');
        const mensagem = textarea.value.trim();

        erroEl.textContent = '';
        if (!mensagem) {
            erroEl.textContent = 'Digite uma mensagem antes de enviar.';
            textarea.focus();
            return;
        }

        const fd = new FormData();
        fd.append('acao', 'enviar_mensagem');
        fd.append('id_pedido', String(pedidoChatId));
        fd.append('mensagem', mensagem);

        try {
            const res = await fetch('../api/pedido_chat.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
                erroEl.textContent = data.erro || 'Não foi possível enviar a mensagem.';
                return;
            }
            textarea.value = '';
            await carregarPedidoChat();
        } catch (_) {
            erroEl.textContent = 'Falha de comunicação com o servidor.';
        }
    });
    }

    if (pedidoChatId > 0) {
        carregarPedidoChat();
    }
</script>

</body>
</html>
