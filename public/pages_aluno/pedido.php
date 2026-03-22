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
    $id_usuario = $_SESSION['auth_user']['id_user'] ?? null;
    
    /* TODO: Implementar consulta ao banco para buscar pedido do usuário */
    /*
    $stmt = $pdo->prepare('
        SELECT
            p.id_pedido,
            p.numero_pedido,
            p.status_pedido,
            p.data_entrega,
            p.data_criacao
        FROM Pedido p
        WHERE p.id_pedido = :id_pedido
        AND p.id_user = :id_user
        LIMIT 1
    ');
    $stmt->execute(['id_pedido' => $id_pedido, 'id_user' => $id_usuario]);
    $pedido = $stmt->fetch();
    
    if (!$pedido) {
        $pedido = null;
    }
    
    /* Buscar itens do pedido */
    /*
    $stmt_itens = $pdo->prepare('
        SELECT
            c.id_comp,
            c.nome,
            c.descricao,
            c.imagem_url,
            cat.nome AS categoria_nome,
            bp.quantidade
        FROM BemPedido bp
        JOIN Componente c ON c.id_comp = bp.id_comp
        JOIN Categoria cat ON cat.id_cat = c.id_cat
        WHERE bp.id_pedido = :id_pedido
    ');
    $stmt_itens->execute(['id_pedido' => $id_pedido]);
    $pedido['itens'] = $stmt_itens->fetchAll();
    */
    
    /* Buscar histórico de atualizações */
    /*
    $stmt_historico = $pdo->prepare('
        SELECT
            data_atualizacao,
            status_anterior,
            status_novo
        FROM LogAuditoria
        WHERE id_pedido = :id_pedido
        ORDER BY data_atualizacao DESC
    ');
    $stmt_historico->execute(['id_pedido' => $id_pedido]);
    $pedido['historico'] = $stmt_historico->fetchAll();
    */
    
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

    /* ── Bloco de renovação ───────────────── */
    .renovacao-bloco {
        margin-bottom: 40px;
    }

    .renovacao-texto {
        font-size: 0.92rem;
        color: #cccccc;
        margin-bottom: 16px;
        line-height: 1.6;
    }

    .renovacao-texto strong {
        color: #ffffff;
    }

    .btn-renovar {
        display: block;
        width: 100%;
        padding: 14px;
        background-color: #ffffff;
        color: #141414;
        font-size: 0.95rem;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        text-align: center;
        transition: background-color 0.15s;
    }

    .btn-renovar:hover { background-color: #e0e0e0; }

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

    <!-- Renovação -->
    <?php if (($pedido['status'] ?? '') === 'em-andamento'): ?>
    <div class="renovacao-bloco">
        <p class="renovacao-texto">
            Precisa de um prazo maior? <strong>Solicite renovação e espere a confirmação do responsável</strong>.
            Você será notificado em caso de sucesso ou negação do pedido.
        </p>
        <button class="btn-renovar" onclick="solicitarRenovacao()">Solicitar renovação</button>
    </div>
    <?php endif; ?>

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
    function solicitarRenovacao() {
        // TODO: implementar lógica de renovação (AJAX ou redirect)
        alert('Pedido de renovação enviado! Aguarde a confirmação do responsável.');
    }
</script>

</body>
</html>
