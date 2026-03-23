<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

/* ══════════════════════════════════════════
   HANDLER DE AÇÕES (POST — PRG pattern)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = $_POST['action']        ?? '';
    $id_pedido     = (int) ($_POST['id_pedido'] ?? 0);
    $justificativa = trim($_POST['justificativa'] ?? '');

    if ($id_pedido > 0) {
        try {
            $pdo = db();

            /* Busca id_user do pedido para criar notificação */
            $getUserId = static function (PDO $pdo, int $id): ?int {
                $s = $pdo->prepare('SELECT id_user FROM Pedido WHERE id_pedido = :id');
                $s->execute(['id' => $id]);
                $row = $s->fetch();
                return $row ? (int) $row['id_user'] : null;
            };

            /* Cria notificação para o estudante */
            $notify = static function (PDO $pdo, int $id_pedido, int $id_user, string $mensagem, string $tipo): void {
                $pdo->prepare('
                    INSERT INTO Notificacao (id_pedido, id_user, mensagem, tipo_notif, data_criacao)
                    VALUES (:p, :u, :m, :t, NOW())
                ')->execute(['p' => $id_pedido, 'u' => $id_user, 'm' => $mensagem, 't' => $tipo]);
            };

            switch ($action) {

                case 'aprovar':
                    /* Aprovação → muda status para em-separacao e notifica o estudante */
                    $pdo->prepare('
                        UPDATE Pedido
                        SET status_pedido = "em-separacao", data_atualizacao = NOW()
                        WHERE id_pedido = :id
                    ')->execute(['id' => $id_pedido]);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid, 'Pedido aprovado!', 'aprovado');
                    }
                    break;

                case 'negar':
                    /* Negação → justificativa obrigatória */
                    if ($justificativa === '') {
                        header('Location: ./acessar.php?erro=justificativa');
                        exit;
                    }
                    $pdo->prepare('
                        UPDATE Pedido
                        SET status_pedido = "negado", motivo_negacao = :motivo, data_atualizacao = NOW()
                        WHERE id_pedido = :id
                    ')->execute(['motivo' => $justificativa, 'id' => $id_pedido]);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid,
                            'Pedido negado. Justificativa: ' . $justificativa, 'negado');
                    }
                    break;

                case 'pronto-para-retirada':
                    /* Pacote preparado fisicamente → notifica estudante para buscar */
                    $pdo->prepare('
                        UPDATE Pedido
                        SET status_pedido = "pronto-para-retirada", data_atualizacao = NOW()
                        WHERE id_pedido = :id
                    ')->execute(['id' => $id_pedido]);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid,
                            'Seu pedido está pronto para retirada! Dirija-se ao laboratório para buscar.',
                            'pronto-para-retirada');
                    }
                    break;

                case 'em-andamento':
                    /* Estudante retirou o pacote — prazo de 1 semana para devolução */
                    $pdo->prepare('
                        UPDATE Pedido
                        SET status_pedido = "em-andamento", data_atualizacao = NOW()
                        WHERE id_pedido = :id
                    ')->execute(['id' => $id_pedido]);
                    break;

                case 'finalizar':
                    $pdo->prepare('
                        UPDATE Pedido
                        SET status_pedido = "finalizado", data_atualizacao = NOW()
                        WHERE id_pedido = :id
                    ')->execute(['id' => $id_pedido]);
                    break;
            }

        } catch (Throwable) {
            /* BD indisponível — falha silenciosa */
        }
    }

    header('Location: ./acessar.php');
    exit;
}

/* ══════════════════════════════════════════
   BUSCA PEDIDOS DO BD
══════════════════════════════════════════ */
$page_title = 'Pedidos';
require_once '../includes/header.php';

$pedidos = [];
$db_ok   = false;
$busca   = trim($_GET['q'] ?? '');
$filtro  = trim($_GET['status'] ?? '');
$erro    = $_GET['erro'] ?? '';

try {
    $pdo = db();

    $where  = [];
    $params = [];

    if ($busca !== '') {
        $where[]         = 'u.nome LIKE :busca';
        $params['busca'] = '%' . $busca . '%';
    }
    if ($filtro !== '') {
        $where[]          = 'p.status_pedido = :status';
        $params['status'] = $filtro;
    }

    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT
            p.id_pedido,
            p.numero_pedido,
            p.status_pedido,
            DATE_FORMAT(COALESCE(p.data_atualizacao, p.data_criacao), '%d/%m/%Y') AS data_fmt,
            u.nome AS nome_estudante
        FROM Pedido p
        JOIN Usuario u ON u.id_user = p.id_user
        {$w}
        ORDER BY p.data_atualizacao DESC, p.id_pedido DESC
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();
    $db_ok   = true;

} catch (Throwable) {
    /* BD indisponível */
}

/* ── Mapa de status → label + classe CSS ─ */
$status_map = [
    'pendente'             => ['label' => 'Pendente',             'class' => 'pendente'],
    'em-separacao'         => ['label' => 'Em separação',         'class' => 'em-separacao'],
    'pronto-para-retirada' => ['label' => 'Pronto para retirada', 'class' => 'pronto-para-retirada'],
    'em-andamento'         => ['label' => 'Em andamento',         'class' => 'em-andamento'],
    'negado'               => ['label' => 'Negado',               'class' => 'negado'],
    'cancelado'            => ['label' => 'Cancelado',            'class' => 'cancelado'],
    'finalizado'           => ['label' => 'Finalizado',           'class' => 'finalizado'],
    'renovacao-solicitada' => ['label' => 'Renovação solicitada', 'class' => 'renovacao-solicitada'],
];
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 48px 48px 80px;
        max-width: 1300px;
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
    .btn-back svg { width: 20px; height: 20px; }

    /* ── Barra de busca + filtro ──────────── */
    .toolbar {
        display: flex;
        gap: 16px;
        align-items: center;
        margin-bottom: 40px;
    }

    .search-wrap {
        flex: 1;
        position: relative;
        max-width: 560px;
    }

    .search-wrap input {
        width: 100%;
        padding: 12px 48px 12px 20px;
        background-color: #1e1e1e;
        border: 1.5px solid #333;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-wrap input::placeholder { color: #666; }
    .search-wrap input:focus        { border-color: #555; }

    .search-wrap button {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #aaa;
        cursor: pointer;
        display: flex;
        align-items: center;
    }

    .btn-filtrar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background-color: transparent;
        border: 1.5px solid #ffffff;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filtrar:hover { background-color: #1e1e1e; }

    /* Painel de filtros */
    .filtros-panel {
        display: none;
        background-color: #1c1c1c;
        border: 1px solid #2e2e2e;
        border-radius: 16px;
        padding: 20px 24px;
        margin-bottom: 28px;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }

    .filtros-panel.open { display: flex; }

    .filtros-panel label {
        font-size: 0.85rem;
        color: #aaa;
        margin-right: 4px;
    }

    .filtros-panel select {
        padding: 8px 14px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        border-radius: 8px;
        color: #ffffff;
        font-size: 0.85rem;
        cursor: pointer;
        outline: none;
    }

    .filtros-panel .btn-limpar {
        padding: 8px 18px;
        background: none;
        border: 1px solid #444;
        border-radius: 8px;
        color: #aaa;
        font-size: 0.85rem;
        cursor: pointer;
        text-decoration: none;
        transition: border-color 0.15s, color 0.15s;
    }

    .filtros-panel .btn-limpar:hover { border-color: #888; color: #fff; }

    /* ── Alerta de erro ───────────────────── */
    .alert-erro {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 12px;
        padding: 14px 20px;
        color: #fca5a5;
        font-size: 0.9rem;
        margin-bottom: 24px;
    }

    /* ── Subtítulo seção ──────────────────── */
    .section-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 20px;
    }

    /* ── Cards de pedido ──────────────────── */
    .pedidos-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .pedido-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 22px 28px;
        display: flex;
        align-items: center;
        gap: 20px;
        transition: border-color 0.15s;
    }

    .pedido-card:hover { border-color: #3a3a3a; }

    .pedido-info {
        flex: 1;
        min-width: 0;
    }

    .pedido-numero {
        font-size: 1.1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .pedido-meta {
        font-size: 0.82rem;
        color: #888;
        line-height: 1.7;
    }

    .pedido-meta span { display: block; }

    /* ── Ações do card ────────────────────── */
    .pedido-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    /* ── Status badges ────────────────────── */
    .status-badge {
        padding: 9px 18px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
        text-align: center;
        min-width: 110px;
    }

    .status-badge.pendente             { background-color: #78350f; color: #fde68a; }
    .status-badge.em-separacao         { background-color: #4c1d95; color: #ddd6fe; }
    .status-badge.pronto-para-retirada { background-color: #78350f; color: #fed7aa; }
    .status-badge.em-andamento         { background-color: #1e3a8a; color: #bfdbfe; }
    .status-badge.negado               { background-color: #7f1d1d; color: #fecaca; }
    .status-badge.cancelado            { background-color: #7f1d1d; color: #fecaca; }
    .status-badge.finalizado           { background-color: #14532d; color: #bbf7d0; }
    .status-badge.renovacao-solicitada { background-color: #713f12; color: #fef08a; }

    /* ── Botão de menu (3 risquinhos) ──────── */
    .btn-menu {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        position: relative;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: #333; }
    .btn-menu svg   { width: 18px; height: 18px; pointer-events: none; }

    /* ── Dropdown de ações ────────────────── */
    .menu-wrap {
        position: relative;
    }

    .menu-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 210px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6);
        z-index: 50;
    }

    .menu-dropdown.open { display: block; }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 13px 18px;
        background: none;
        border: none;
        border-bottom: 1px solid #2a2a2a;
        color: #ffffff;
        font-size: 0.88rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
        font-family: inherit;
    }

    .menu-item:last-child { border-bottom: none; }
    .menu-item:hover      { background-color: #2a2a2a; }

    .menu-item svg { width: 16px; height: 16px; flex-shrink: 0; }

    .menu-item.danger       { color: #f87171; }
    .menu-item.danger:hover { background-color: #2a1414; }

    .menu-item.success       { color: #4ade80; }
    .menu-item.success:hover { background-color: #0d2018; }

    /* ── Estado vazio / erro BD ───────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
    }

    .db-error {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        color: #aaa;
    }

    .db-error h2 { color: #ef4444; margin-bottom: 8px; }

    /* ══════════════════════════════════════════
       MODAL (negação com justificativa)
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.75);
        z-index: 200;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.open { display: flex; }

    .modal-box {
        background-color: #1e1e1e;
        border: 1px solid #333;
        border-radius: 20px;
        padding: 36px 40px;
        width: 100%;
        max-width: 480px;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 8px;
    }

    .modal-desc {
        font-size: 0.88rem;
        color: #aaa;
        margin-bottom: 24px;
        line-height: 1.6;
    }

    .modal-desc strong { color: #ffffff; }

    .modal-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #cccccc;
        margin-bottom: 8px;
    }

    .modal-textarea {
        width: 100%;
        min-height: 110px;
        padding: 12px 16px;
        background-color: #141414;
        border: 1.5px solid #333;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.9rem;
        font-family: inherit;
        resize: vertical;
        outline: none;
        transition: border-color 0.2s;
        margin-bottom: 6px;
    }

    .modal-textarea:focus { border-color: #555; }

    .modal-hint {
        font-size: 0.78rem;
        color: #666;
        margin-bottom: 24px;
    }

    .modal-hint.error { color: #f87171; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancelar-modal {
        padding: 11px 24px;
        background: none;
        border: 1px solid #444;
        border-radius: 10px;
        color: #aaa;
        font-size: 0.88rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }

    .btn-cancelar-modal:hover { border-color: #888; color: #fff; }

    .btn-confirmar-negar {
        padding: 11px 24px;
        background-color: #7f1d1d;
        border: none;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background-color 0.15s;
    }

    .btn-confirmar-negar:hover { background-color: #991b1b; }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 860px) {
        .main { padding: 32px 20px 60px; }
        .pedido-card { flex-wrap: wrap; }
        .toolbar { flex-wrap: wrap; }
        .search-wrap { max-width: 100%; }
    }

    @media (max-width: 520px) {
        .pedido-actions { flex-direction: column; align-items: flex-end; }
    }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="./index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <div class="nav-actions">

        <!-- Usuário + dropdown -->
        <div class="nav-user" id="navUser">
            <button class="nav-user-btn" onclick="toggleUserDropdown()" aria-haspopup="true">
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

<!-- ══════════════════ MODAL — NEGAR PEDIDO ══════════════════ -->
<div class="modal-overlay" id="modalNegar" role="dialog" aria-modal="true" aria-labelledby="modalNegarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalNegarTitulo">Negar pedido</h2>
        <p class="modal-desc">
            Informe o motivo da negação. <strong>O estudante será notificado</strong> com a justificativa.
        </p>

        <form method="POST" action="./acessar.php" id="formNegar">
            <input type="hidden" name="action" value="negar">
            <input type="hidden" name="id_pedido" id="modalNegarId" value="">

            <label class="modal-label" for="justificativaInput">Justificativa *</label>
            <textarea
                class="modal-textarea"
                id="justificativaInput"
                name="justificativa"
                placeholder="Ex.: Item indisponível no estoque no momento..."
                maxlength="1000"
            ></textarea>
            <p class="modal-hint" id="modalHint">Obrigatório para prosseguir.</p>

            <div class="modal-actions">
                <button type="button" class="btn-cancelar-modal" onclick="fecharModalNegar()">Cancelar</button>
                <button type="submit" class="btn-confirmar-negar">Confirmar negação</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Pedidos</h1>
        <a href="./index.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Alerta de erro: justificativa ausente -->
    <?php if ($erro === 'justificativa'): ?>
    <div class="alert-erro">
        Justificativa obrigatória ao negar um pedido. Por favor, preencha o campo antes de confirmar.
    </div>
    <?php endif; ?>

    <!-- Barra de busca + filtro -->
    <form method="GET" action="./acessar.php">
        <div class="toolbar">
            <div class="search-wrap">
                <input
                    type="text"
                    name="q"
                    placeholder="Pesquisar por usuário"
                    value="<?= htmlspecialchars($busca) ?>"
                    autocomplete="off"
                >
                <button type="submit" aria-label="Buscar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </button>
            </div>

            <button type="button" class="btn-filtrar" onclick="toggleFiltros()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="6"  x2="20" y2="6"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                    <line x1="12" y1="18" x2="12" y2="18"/>
                </svg>
                Filtrar
            </button>
        </div>

        <!-- Painel de filtros -->
        <div class="filtros-panel <?= $filtro !== '' ? 'open' : '' ?>" id="filtrosPanel">
            <label for="filtroStatus">Status:</label>
            <select name="status" id="filtroStatus" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($status_map as $key => $info): ?>
                    <option value="<?= htmlspecialchars($key) ?>"
                            <?= $filtro === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="./acessar.php" class="btn-limpar">Limpar filtros</a>
        </div>
    </form>

    <!-- Lista de pedidos -->
    <h2 class="section-title">Histórico de pedidos</h2>

    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar os pedidos. Verifique a conexão com o MySQL.</p>
    </div>

    <?php elseif (empty($pedidos)): ?>
    <p class="empty-state">Nenhum pedido encontrado<?= $busca !== '' || $filtro !== '' ? ' para os filtros aplicados' : '' ?>.</p>

    <?php else: ?>
    <div class="pedidos-list">
        <?php foreach ($pedidos as $p):
            $status     = $p['status_pedido'] ?? 'pendente';
            $statusInfo = $status_map[$status] ?? ['label' => ucfirst($status), 'class' => 'pendente'];
            $numFmt     = sprintf('%03d', (int) ($p['numero_pedido'] ?? 0));
        ?>
        <div class="pedido-card">

            <!-- Info -->
            <div class="pedido-info">
                <p class="pedido-numero">Pedido #<?= htmlspecialchars($numFmt) ?></p>
                <div class="pedido-meta">
                    <span>Pedido feito por: <strong style="color:#ccc"><?= htmlspecialchars($p['nome_estudante']) ?></strong></span>
                    <span>Data da última atualização: <?= htmlspecialchars($p['data_fmt'] ?? '—') ?></span>
                </div>
            </div>

            <!-- Ações -->
            <div class="pedido-actions">

                <!-- Badge de status -->
                <span class="status-badge <?= htmlspecialchars($statusInfo['class']) ?>">
                    <?= htmlspecialchars($statusInfo['label']) ?>
                </span>

                <!-- Menu de 3 risquinhos -->
                <?php
                /* Define quais ações são exibidas conforme o status atual */
                $acoes = [];
                if ($status === 'pendente') {
                    $acoes = [
                        ['action' => 'aprovar', 'label' => 'Aprovar pedido',  'type' => 'success', 'icon' => 'check'],
                        ['action' => 'negar',   'label' => 'Negar pedido',    'type' => 'danger',  'icon' => 'x'],
                    ];
                } elseif ($status === 'em-separacao') {
                    $acoes = [
                        ['action' => 'pronto-para-retirada', 'label' => 'Pronto para retirada', 'type' => 'normal', 'icon' => 'package'],
                    ];
                } elseif ($status === 'pronto-para-retirada') {
                    $acoes = [
                        ['action' => 'em-andamento', 'label' => 'Em andamento', 'type' => 'normal', 'icon' => 'truck'],
                    ];
                } elseif ($status === 'em-andamento') {
                    $acoes = [
                        ['action' => 'finalizar', 'label' => 'Finalizar pedido', 'type' => 'success', 'icon' => 'check'],
                    ];
                } elseif ($status === 'renovacao-solicitada') {
                    $acoes = [
                        ['action' => 'aprovar', 'label' => 'Aprovar renovação', 'type' => 'success', 'icon' => 'check'],
                        ['action' => 'negar',   'label' => 'Negar renovação',   'type' => 'danger',  'icon' => 'x'],
                    ];
                }
                ?>

                <?php if (!empty($acoes)): ?>
                <div class="menu-wrap" id="wrap-<?= (int) $p['id_pedido'] ?>">
                    <button
                        class="btn-menu"
                        onclick="toggleMenu(<?= (int) $p['id_pedido'] ?>, event)"
                        aria-label="Ações do pedido"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6"  x2="21" y2="6"/>
                            <line x1="8" y1="12" x2="21" y2="12"/>
                            <line x1="8" y1="18" x2="21" y2="18"/>
                            <line x1="3" y1="6"  x2="3.01" y2="6"/>
                            <line x1="3" y1="12" x2="3.01" y2="12"/>
                            <line x1="3" y1="18" x2="3.01" y2="18"/>
                        </svg>
                    </button>

                    <div class="menu-dropdown" id="menu-<?= (int) $p['id_pedido'] ?>">
                        <?php foreach ($acoes as $acao): ?>
                            <?php if ($acao['action'] === 'negar'): ?>
                            <!-- Negar → abre modal com justificativa -->
                            <button
                                type="button"
                                class="menu-item danger"
                                onclick="abrirModalNegar(<?= (int) $p['id_pedido'] ?>)"
                            >
                                <?= svgIcon($acao['icon']) ?>
                                <?= htmlspecialchars($acao['label']) ?>
                            </button>

                            <?php else: ?>
                            <!-- Outras ações → POST direto -->
                            <form method="POST" action="./acessar.php" style="margin:0">
                                <input type="hidden" name="action"    value="<?= htmlspecialchars($acao['action']) ?>">
                                <input type="hidden" name="id_pedido" value="<?= (int) $p['id_pedido'] ?>">
                                <button
                                    type="submit"
                                    class="menu-item <?= htmlspecialchars($acao['type']) ?>"
                                >
                                    <?= svgIcon($acao['icon']) ?>
                                    <?= htmlspecialchars($acao['label']) ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<?php
/* Helper: retorna SVG inline por nome de ícone */
function svgIcon(string $name): string {
    $icons = [
        'check'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'x'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'package' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'truck'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    ];
    return $icons[$name] ?? '';
}
?>

<script>
    /* ── User dropdown ───────────────────── */
    function toggleUserDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }

    document.addEventListener('click', function (e) {
        const navUser = document.getElementById('navUser');
        if (navUser && !navUser.contains(e.target)) navUser.classList.remove('open');
    });

    /* ── Painel de filtros ───────────────── */
    function toggleFiltros() {
        document.getElementById('filtrosPanel').classList.toggle('open');
    }

    /* ── Menus de 3 risquinhos ───────────── */
    let menuAberto = null;

    function toggleMenu(id, e) {
        e.stopPropagation();
        const menu = document.getElementById('menu-' + id);
        if (!menu) return;

        const jaAberto = menu.classList.contains('open');

        /* Fecha qualquer menu aberto */
        if (menuAberto && menuAberto !== menu) {
            menuAberto.classList.remove('open');
        }

        menu.classList.toggle('open', !jaAberto);
        menuAberto = jaAberto ? null : menu;
    }

    /* Clique fora fecha o menu */
    document.addEventListener('click', function () {
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });
        menuAberto = null;
    });

    /* ── Modal de negação ────────────────── */
    function abrirModalNegar(id_pedido) {
        /* Fecha o dropdown antes de abrir o modal */
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });

        document.getElementById('modalNegarId').value = id_pedido;
        document.getElementById('justificativaInput').value = '';
        document.getElementById('modalHint').textContent = 'Obrigatório para prosseguir.';
        document.getElementById('modalHint').classList.remove('error');
        document.getElementById('modalNegar').classList.add('open');
        setTimeout(function () { document.getElementById('justificativaInput').focus(); }, 100);
    }

    function fecharModalNegar() {
        document.getElementById('modalNegar').classList.remove('open');
    }

    /* Fecha modal ao clicar no overlay */
    document.getElementById('modalNegar').addEventListener('click', function (e) {
        if (e.target === this) fecharModalNegar();
    });

    /* Fecha modal com Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fecharModalNegar();
    });

    /* Validação client-side antes de submeter */
    document.getElementById('formNegar').addEventListener('submit', function (e) {
        const val = document.getElementById('justificativaInput').value.trim();
        if (val === '') {
            e.preventDefault();
            const hint = document.getElementById('modalHint');
            hint.textContent = 'Por favor, preencha a justificativa antes de confirmar.';
            hint.classList.add('error');
            document.getElementById('justificativaInput').focus();
        }
    });
</script>

</body>
</html>
