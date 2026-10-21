<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);

require_once '../../src/config/database.php';

$page_title = 'Dados de Pedidos';
require_once '../includes/header.php';

$usuarios   = [];
$db_ok      = false;
$nome_admin = $_SESSION['auth_user']['nome'] ?? 'Administrador';

try {
    $pdo = db();

    $pdo->exec("ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'automatica'");
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS humor VARCHAR(10) NULL DEFAULT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL');

    $stmt = $pdo->query("
        SELECT id_user, nome, tipo_perfil, bloqueado
        FROM Usuario
        WHERE tipo_perfil IN ('estudante','laboratorista')
        ORDER BY nome ASC
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $db_ok = true;
} catch (Throwable) {}
?>

<style>
    .main {
        padding: 48px 48px 80px;
        max-width: 1200px;
        margin: 0 auto;
    }

    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 28px;
        font-size: 0.85rem;
        color: var(--texto-secundario);
    }
    .breadcrumb a { color: var(--texto-secundario); text-decoration: none; transition: color .15s; }
    .breadcrumb a:hover { color: var(--texto-principal); }
    .breadcrumb span { color: var(--texto-placeholder); }

    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: var(--texto-principal);
        margin-bottom: 32px;
    }

    .search-bar { position: relative; margin-bottom: 20px; }
    .search-bar input {
        width: 100%;
        padding: 12px 44px 12px 18px;
        background: var(--bg-modal);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 10px;
        color: var(--texto-principal);
        font-size: 0.95rem;
        outline: none;
        transition: border-color .2s;
    }
    .search-bar input::placeholder { color: var(--texto-placeholder); }
    .search-bar input:focus { border-color: var(--borda-forte); }
    .search-bar svg {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--texto-placeholder);
        width: 18px; height: 18px;
        pointer-events: none;
    }

    .table-card {
        background: var(--bg-superficie-tabela);
        border: 1.5px solid var(--borda-card);
        border-radius: 16px;
        overflow: visible;
    }

    .table-header {
        display: grid;
        grid-template-columns: 1fr 160px 60px;
        padding: 12px 20px;
        background: var(--bg-subpainel);
        border-bottom: 1px solid var(--borda-card);
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--texto-auxiliar);
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .user-row {
        display: grid;
        grid-template-columns: 1fr 160px 60px;
        align-items: center;
        padding: 14px 20px;
        border-bottom: 1px solid var(--borda-sutil);
        transition: background .15s;
    }
    .user-row:last-child { border-bottom: none; }
    .user-row:hover { background: var(--bg-cabecalho-tabela); }

    .user-name { font-size: 0.95rem; font-weight: 600; color: var(--texto-principal); }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .badge-estudante     { background: var(--bg-info-badge); color: var(--texto-info); }
    .badge-laboratorista { background: var(--bg-sucesso-badge); color: var(--texto-sucesso); }
    .badge-bloqueado     { background: var(--bg-erro-sutil); color: var(--cor-erro-claro); }

    .dot-menu-wrap { position: relative; display: flex; justify-content: flex-end; z-index: 120; }
    .dot-btn {
        background: none;
        border: none;
        color: var(--texto-auxiliar);
        cursor: pointer;
        padding: 6px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        transition: background .15s, color .15s;
    }
    .dot-btn:hover { background: var(--bg-controle); color: var(--texto-principal); }
    .dot-btn svg { width: 18px; height: 18px; }

    .dot-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 4px);
        right: 0;
        background: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 10px;
        min-width: 200px;
        box-shadow: var(--sombra-dropdown);
        z-index: 999;
        overflow: hidden;
    }
    .dot-dropdown.open { display: block; }
    .dot-dropdown button {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 12px 16px;
        background: none;
        border: none;
        color: var(--texto-principal);
        font-size: 0.9rem;
        cursor: pointer;
        text-align: left;
        transition: background .15s;
        border-bottom: 1px solid var(--borda-card);
    }
    .dot-dropdown button:last-child { border-bottom: none; }
    .dot-dropdown button:hover { background: var(--bg-controle); }
    .dot-dropdown button svg { width: 16px; height: 16px; color: var(--texto-suave); flex-shrink: 0; }
    .dot-dropdown button.danger { color: var(--cor-erro-claro); }
    .dot-dropdown button.danger svg { color: var(--cor-erro-claro); }

    /* Painel de pedidos */
    #pedidosPanel { display: none; margin-top: 32px; }
    #pedidosPanel.open { display: block; }

    .panel-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }
    .panel-back-btn {
        background: none;
        border: none;
        color: var(--texto-secundario);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.88rem;
        padding: 6px 10px;
        border-radius: 8px;
        transition: background .15s, color .15s;
    }
    .panel-back-btn:hover { background: var(--bg-modal); color: var(--texto-principal); }
    .panel-back-btn svg { width: 16px; height: 16px; }

    .panel-title { font-size: 1.3rem; font-weight: 700; color: var(--texto-principal); }

    .pedidos-header {
        display: grid;
        grid-template-columns: 80px 160px 120px 180px 100px 60px;
        padding: 12px 20px;
        background: var(--bg-subpainel);
        border-bottom: 1px solid var(--borda-card);
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--texto-auxiliar);
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .pedido-row {
        display: grid;
        grid-template-columns: 80px 160px 120px 180px 100px 60px;
        align-items: center;
        padding: 14px 20px;
        border-bottom: 1px solid var(--borda-sutil);
        transition: background .15s;
    }
    .pedido-row:last-child { border-bottom: none; }
    .pedido-row:hover { background: var(--bg-hover-linha); }

    .pedido-id { font-size: 0.88rem; font-weight: 700; color: var(--texto-suave); }
    .pedido-status {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-pendente     { background:var(--bg-alerta-badge); color:var(--cor-alerta); }
    .status-separacao    { background:var(--bg-info-icone); color:var(--texto-info-icone); }
    .status-pronto       { background:var(--bg-sucesso-badge); color:var(--cor-sucesso); }
    .status-andamento    { background:var(--bg-sucesso-badge); color:var(--texto-sucesso); }
    .status-finalizado   { background:var(--bg-cabecalho-tabela);    color:var(--texto-secundario); }
    .status-negado       { background:var(--bg-erro-sutil); color:var(--cor-erro-claro); }
    .status-cancelado    { background:var(--bg-erro-badge); color:var(--cor-erro-claro); }
    .status-default      { background:var(--bg-cabecalho-tabela);    color:var(--texto-suave); }

    .pedido-data { font-size: 0.85rem; color: var(--texto-secundario); }

    .relatorio-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.78rem;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 20px;
    }
    .relatorio-sim { background:var(--bg-sucesso-badge); color:var(--cor-sucesso); }
    .relatorio-nao { background:var(--bg-alerta-badge); color:var(--cor-laranja); }

    .loading-row { text-align:center; padding:40px; color:var(--texto-placeholder); font-size:.9rem; }

    .empty-state {
        text-align:center;
        padding:60px 20px;
        color:var(--texto-placeholder);
        font-size:.95rem;
    }
    .empty-state svg { width:48px; height:48px; margin-bottom:16px; color:var(--icone-vazio); display:block; margin-left:auto; margin-right:auto; }

    /* Modais */
    .overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: var(--overlay-modal);
        z-index: 300;
        align-items: center;
        justify-content: center;
    }
    .overlay.open { display: flex; }
    .modal {
        background: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 20px;
        padding: 36px;
        width: 100%;
        max-width: 500px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .modal-title { font-size: 1.3rem; font-weight: 800; color: var(--texto-principal); }
    .modal-sub   { font-size: 0.88rem; color: var(--texto-secundario); margin-top: -10px; }

    .modal textarea {
        width: 100%;
        padding: 12px 16px;
        background: var(--bg-subpainel);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 10px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        resize: vertical;
        min-height: 120px;
        outline: none;
        transition: border-color .2s;
        font-family: inherit;
    }
    .modal textarea:focus { border-color: var(--borda-forte); }
    .modal textarea::placeholder { color: var(--texto-placeholder); }

    .modal-info {
        background: var(--bg-subpainel);
        border: 1px solid var(--borda-card);
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 0.88rem;
        line-height: 1.6;
        color: var(--texto-medio);
    }
    .modal-info strong { color: var(--texto-principal); }
    .info-ok   { color: var(--cor-sucesso); }
    .info-warn { color: var(--cor-laranja); }

    .char-counter { font-size:.78rem; color:var(--texto-placeholder); text-align:right; margin-top:-10px; }

    .modal-btns { display:flex; gap:12px; justify-content:flex-end; }
    .btn-cancel {
        padding:10px 22px;
        background:none;
        border:1.5px solid var(--borda-padrao);
        border-radius:10px;
        color:var(--texto-suave);
        font-size:.9rem;
        cursor:pointer;
        transition:border-color .15s,color .15s;
    }
    .btn-cancel:hover { border-color:var(--borda-forte); color:var(--texto-principal); }
    .btn-primary {
        padding:10px 22px;
        background:var(--cor-primaria);
        border:none;
        border-radius:10px;
        color:#ffffff;
        font-size:.9rem;
        font-weight:600;
        cursor:pointer;
        transition:background .15s;
    }
    .btn-primary:hover { background:var(--cor-primaria-hover); }
    .btn-primary:disabled { background:var(--cor-primaria-desabilitada); cursor:not-allowed; opacity:.6; }
    .btn-danger {
        padding:10px 22px;
        background:var(--cor-erro-forte);
        border:none;
        border-radius:10px;
        color:#ffffff;
        font-size:.9rem;
        font-weight:600;
        cursor:pointer;
        transition:background .15s;
    }
    .btn-danger:hover { background:var(--cor-erro-hover); }
    .btn-danger:disabled { opacity:.6; cursor:not-allowed; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none} }

    @media(max-width:900px) {
        .main { padding:32px 20px 60px; }
        .pedidos-header,
        .pedido-row { grid-template-columns:70px 140px 100px 1fr 80px 50px; }
    }
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar">
    <a href="./index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>
    <div class="nav-actions">
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
    function toggleDropdown() { document.getElementById('navUser').classList.toggle('open'); }
    document.addEventListener('click', e => {
        const n = document.getElementById('navUser');
        if (!n.contains(e.target)) n.classList.remove('open');
    });
</script>

<!-- ══ CONTEÚDO ══ -->
<main class="main">

    <nav class="breadcrumb">
        <a href="./index.php">Dashboard</a>
        <span>›</span>
        <span>Controlar Dados</span>
        <span>›</span>
        <span>Dados de Pedidos</span>
    </nav>

    <h1 class="page-title">Dados de Pedidos</h1>

    <?php if (!$db_ok): ?>
        <div style="background:var(--bg-erro-sutil);border:1px solid var(--borda-erro);border-radius:12px;padding:16px 20px;color:var(--cor-erro-claro);font-size:.9rem;">
            Banco de dados indisponível no momento.
        </div>
    <?php else: ?>

    <!-- Busca -->
    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="Buscar usuário pelo nome…" oninput="filtrarUsuarios(this.value)">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </div>

    <!-- Lista de usuários -->
    <div class="table-card" id="usersTable">
        <div class="table-header">
            <span>Nome</span>
            <span>Tipo</span>
            <span></span>
        </div>

        <?php foreach ($usuarios as $u): ?>
        <?php
            $tipo = $u['tipo_perfil'];
            $bloq = (int) $u['bloqueado'];
            if ($bloq) {
                $badge = '<span class="badge badge-bloqueado">Bloqueado</span>';
            } elseif ($tipo === 'estudante') {
                $badge = '<span class="badge badge-estudante">Estudante</span>';
            } else {
                $badge = '<span class="badge badge-laboratorista">Laboratorista</span>';
            }
        ?>
        <div class="user-row" data-nome="<?= strtolower(htmlspecialchars($u['nome'])) ?>">
            <span class="user-name"><?= htmlspecialchars($u['nome']) ?></span>
            <span><?= $badge ?></span>
            <div class="dot-menu-wrap">
                <button class="dot-btn" onclick="toggleUserMenu(event, <?= (int)$u['id_user'] ?>)" title="Opções">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/>
                        <circle cx="12" cy="19" r="1"/>
                    </svg>
                </button>
                <div class="dot-dropdown" id="userMenu-<?= (int)$u['id_user'] ?>">
                    <button onclick="abrirPedidos(<?= (int)$u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8"  y1="2" x2="8"  y2="6"/>
                            <line x1="3"  y1="10" x2="21" y2="10"/>
                        </svg>
                        Consultar Pedidos
                    </button>
                    <button onclick="abrirMensagens(<?= (int)$u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        Consultar Mensagens
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($usuarios)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <p>Nenhum usuário cadastrado.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Painel de pedidos -->
    <div id="pedidosPanel">
        <div class="panel-header">
            <button class="panel-back-btn" onclick="fecharPainel()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Voltar
            </button>
            <h2 class="panel-title" id="panelTitle">Pedidos</h2>
        </div>

        <div class="table-card">
            <div class="pedidos-header">
                <span>ID</span>
                <span>Status</span>
                <span>Criado em</span>
                <span>Lab. responsável</span>
                <span>Relatório</span>
                <span></span>
            </div>
            <div id="pedidosBody">
                <div class="loading-row">Carregando…</div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>

<!-- ══ MODAL: Notificar Lab ══ -->
<div class="overlay" id="modalNotif" onclick="if(event.target===this)fecharModalNotif()">
    <div class="modal" role="dialog" aria-modal="true">
        <h2 class="modal-title">Notificar Laboratorista</h2>
        <p class="modal-sub">Sobre o Pedido #<span id="notifPedidoId"></span></p>
        <textarea id="notifMsg" placeholder="Escreva a notificação para o laboratorista…" maxlength="2000"></textarea>
        <p class="char-counter"><span id="notifCount">0</span>/2000</p>
        <div class="modal-btns">
            <button class="btn-cancel" onclick="fecharModalNotif()">Cancelar</button>
            <button class="btn-primary" id="btnEnviarNotif" onclick="enviarNotif()">Enviar</button>
        </div>
    </div>
</div>

<!-- ══ MODAL: Confirmar Apagar ══ -->
<div class="overlay" id="modalApagar" onclick="if(event.target===this)fecharModalApagar()">
    <div class="modal" role="dialog" aria-modal="true">
        <h2 class="modal-title">Apagar Pedido do Banco de Dados</h2>
        <div class="modal-info">
            <p>Tem certeza que quer apagar este pedido do Banco de Dados?</p>
            <br>
            <p><strong>Pedido #<span id="apagarPedidoId"></span></strong></p>
            <br>
            <p>Verifique se já foi realizado o relatório desse pedido ao lado:</p>
            <p id="apagarRelatorio" style="margin-top:8px;font-weight:600;"></p>
        </div>
        <div class="modal-btns">
            <button class="btn-cancel" onclick="fecharModalApagar()">Cancelar</button>
            <button class="btn-danger" id="btnConfirmarApagar" onclick="confirmarApagar()">Sim, apagar</button>
        </div>
    </div>
</div>

<script>
    let pedidoAtual      = null;
    let usuarioAtualId   = null;
    let usuarioAtualNome = null;

    /* Fechar menus ao clicar fora */
    document.addEventListener('click', e => {
        document.querySelectorAll('.dot-dropdown.open').forEach(d => {
            if (!d.parentElement.contains(e.target)) d.classList.remove('open');
        });
    });

    /* Menu 3 pontos do usuário */
    function toggleUserMenu(e, userId) {
        e.stopPropagation();
        const menu = document.getElementById('userMenu-' + userId);
        const isOpen = menu.classList.contains('open');
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        if (!isOpen) menu.classList.add('open');
    }

    /* Filtrar usuários */
    function filtrarUsuarios(q) {
        const q2 = q.toLowerCase().trim();
        document.querySelectorAll('#usersTable .user-row').forEach(row => {
            const nome = row.dataset.nome || '';
            row.style.display = nome.includes(q2) ? '' : 'none';
        });
    }

    /* Abrir painel de pedidos */
    function abrirPedidos(userId, nome) {
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        usuarioAtualId   = userId;
        usuarioAtualNome = nome;

        document.getElementById('panelTitle').textContent = 'Pedidos de ' + nome;
        document.getElementById('pedidosBody').innerHTML  = '<div class="loading-row">Carregando…</div>';
        document.getElementById('pedidosPanel').style.display = 'block';
        document.getElementById('usersTable').style.opacity = '0.4';
        document.getElementById('searchInput') && (document.getElementById('searchInput').style.opacity = '0.4');

        fetch('../api/admin_pedidos_usuario.php?id_user=' + userId)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { renderErro('Erro ao carregar pedidos.'); return; }
                renderPedidos(data.pedidos);
            })
            .catch(() => renderErro('Erro de comunicação.'));
    }

    function renderErro(msg) {
        document.getElementById('pedidosBody').innerHTML =
            '<div class="empty-state"><p>' + msg + '</p></div>';
    }

    function statusCssClass(s) {
        const m = {
            'pendente':'status-pendente',
            'em-separacao':'status-separacao',
            'pronto-para-retirada':'status-pronto',
            'em-andamento':'status-andamento',
            'finalizado':'status-finalizado',
            'negado':'status-negado',
            'cancelado':'status-cancelado',
            'renovacao-solicitada':'status-pendente'
        };
        return m[s] || 'status-default';
    }

    function formatStatus(s) {
        const m = {
            'pendente':'Pendente',
            'em-separacao':'Em Separação',
            'pronto-para-retirada':'Pronto p/ Retirada',
            'em-andamento':'Em Andamento',
            'finalizado':'Finalizado',
            'negado':'Negado',
            'cancelado':'Cancelado',
            'renovacao-solicitada':'Renovação Solicitada'
        };
        return m[s] || (s || '—');
    }

    function renderPedidos(pedidos) {
        if (!pedidos || pedidos.length === 0) {
            document.getElementById('pedidosBody').innerHTML =
                '<div class="empty-state"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 12px;width:40px;height:40px;color:var(--icone-vazio)"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg><p>Nenhum pedido encontrado para este usuário.</p></div>';
            return;
        }

        const html = pedidos.map(p => {
            const sc  = statusCssClass(p.status);
            const sl  = formatStatus(p.status);
            const rel = p.tem_relatorio
                ? '<span class="relatorio-badge relatorio-sim">✓ Sim</span>'
                : '<span class="relatorio-badge relatorio-nao">✗ Não</span>';
            const lab  = p.nome_laboratorista_responsavel || '—';
            const data = (p.data_criacao || '').substring(0, 10) || '—';
            const temRel = p.tem_relatorio ? 'true' : 'false';

            return `<div class="pedido-row">
                <span class="pedido-id">#${p.id_pedido}</span>
                <span><span class="pedido-status ${sc}">${sl}</span></span>
                <span class="pedido-data">${data}</span>
                <span class="pedido-data" style="font-size:.82rem;color:var(--texto-auxiliar);">${lab}</span>
                <span>${rel}</span>
                <div class="dot-menu-wrap">
                    <button class="dot-btn" onclick="togglePedMenu(event,${p.id_pedido})">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/>
                            <circle cx="12" cy="19" r="1"/>
                        </svg>
                    </button>
                    <div class="dot-dropdown" id="pedMenu-${p.id_pedido}">
                        <button onclick="abrirModalNotif(${p.id_pedido})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                            Notificar Lab.
                        </button>
                        <button class="danger" onclick="abrirModalApagar(${p.id_pedido},${temRel})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                <path d="M9 6V4h6v2"/>
                            </svg>
                            Apagar Pedido
                        </button>
                    </div>
                </div>
            </div>`;
        }).join('');

        document.getElementById('pedidosBody').innerHTML = html;
    }

    function togglePedMenu(e, id) {
        e.stopPropagation();
        const m = document.getElementById('pedMenu-' + id);
        const wasOpen = m.classList.contains('open');
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        if (!wasOpen) m.classList.add('open');
    }

    function fecharPainel() {
        document.getElementById('pedidosPanel').style.display = 'none';
        document.getElementById('usersTable').style.opacity = '';
        const si = document.getElementById('searchInput');
        if (si) si.style.opacity = '';
        usuarioAtualId = usuarioAtualNome = null;
    }

    function abrirMensagens(userId, nome) {
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        alert('Consulta de mensagens de ' + nome + ' (em desenvolvimento).');
    }

    /* Modal Notificar Lab */
    function abrirModalNotif(pedidoId) {
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        pedidoAtual = pedidoId;
        document.getElementById('notifPedidoId').textContent = pedidoId;
        document.getElementById('notifMsg').value = '';
        document.getElementById('notifCount').textContent = '0';
        const btn = document.getElementById('btnEnviarNotif');
        btn.disabled = false;
        btn.textContent = 'Enviar';
        document.getElementById('modalNotif').classList.add('open');
        setTimeout(() => document.getElementById('notifMsg').focus(), 100);
    }

    document.getElementById('notifMsg').addEventListener('input', function() {
        document.getElementById('notifCount').textContent = this.value.length;
    });

    function fecharModalNotif() {
        document.getElementById('modalNotif').classList.remove('open');
        pedidoAtual = null;
    }

    function enviarNotif() {
        const msg = document.getElementById('notifMsg').value.trim();
        if (!msg) { alert('Escreva uma mensagem antes de enviar.'); return; }
        if (!pedidoAtual) return;

        const btn = document.getElementById('btnEnviarNotif');
        btn.disabled = true;
        btn.textContent = 'Enviando…';

        const fd = new FormData();
        fd.append('id_pedido', pedidoAtual);
        fd.append('mensagem', msg);

        fetch('../api/admin_notificar_lab.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    fecharModalNotif();
                    toast('Notificação enviada ao laboratorista!', 'ok');
                } else {
                    alert('Erro: ' + (data.erro || 'Erro desconhecido'));
                    btn.disabled = false;
                    btn.textContent = 'Enviar';
                }
            })
            .catch(() => {
                alert('Erro de comunicação.');
                btn.disabled = false;
                btn.textContent = 'Enviar';
            });
    }

    /* Modal Apagar */
    function abrirModalApagar(pedidoId, temRelatorio) {
        document.querySelectorAll('.dot-dropdown.open').forEach(d => d.classList.remove('open'));
        pedidoAtual = pedidoId;
        document.getElementById('apagarPedidoId').textContent = pedidoId;

        const el = document.getElementById('apagarRelatorio');
        if (temRelatorio) {
            el.innerHTML = '<span class="info-ok">✓ Este pedido possui relatório gerado.</span>';
        } else {
            el.innerHTML = '<span class="info-warn">⚠ Este pedido NÃO possui relatório. Verifique antes de apagar!</span>';
        }

        const btn = document.getElementById('btnConfirmarApagar');
        btn.disabled = false;
        btn.textContent = 'Sim, apagar';
        document.getElementById('modalApagar').classList.add('open');
    }

    function fecharModalApagar() {
        document.getElementById('modalApagar').classList.remove('open');
        pedidoAtual = null;
    }

    function confirmarApagar() {
        if (!pedidoAtual) return;
        const btn = document.getElementById('btnConfirmarApagar');
        btn.disabled = true;
        btn.textContent = 'Apagando…';

        const fd = new FormData();
        fd.append('id_pedido', pedidoAtual);

        fetch('../api/admin_apagar_pedido.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    fecharModalApagar();
                    toast('Pedido apagado com sucesso.', 'ok');
                    if (usuarioAtualId) abrirPedidos(usuarioAtualId, usuarioAtualNome);
                } else {
                    alert('Erro: ' + (data.erro || 'Erro desconhecido'));
                    btn.disabled = false;
                    btn.textContent = 'Sim, apagar';
                }
            })
            .catch(() => {
                alert('Erro de comunicação.');
                btn.disabled = false;
                btn.textContent = 'Sim, apagar';
            });
    }

    /* Toast */
    function toast(msg, tipo) {
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = `position:fixed;bottom:28px;right:28px;background:${tipo==='ok'?'#16a34a':'#dc2626'};color:#fff;padding:12px 22px;border-radius:10px;font-size:.9rem;font-weight:600;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.4);animation:fadeUp .3s ease;`;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3500);
    }

    /* Esc fecha modais */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { fecharModalNotif(); fecharModalApagar(); }
    });
</script>

</body>
</html>
