<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);
require_once '../../src/config/database.php';

/* ── Ações AJAX ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $pdo     = db();
        $id_user = (int) ($_POST['id_user'] ?? 0);

        if (!$id_user) {
            echo json_encode(['ok' => false, 'error' => 'ID de usuário inválido']);
            exit;
        }

        /* ── Bloquear / Desbloquear ── */
        if ($_POST['action'] === 'toggle_block') {
            $pdo->prepare('UPDATE Usuario SET bloqueado = NOT bloqueado WHERE id_user = :id')
                ->execute(['id' => $id_user]);

            $stmt = $pdo->prepare('SELECT bloqueado FROM Usuario WHERE id_user = :id');
            $stmt->execute(['id' => $id_user]);
            $bloqueado = (int) $stmt->fetchColumn();

            echo json_encode(['ok' => true, 'bloqueado' => $bloqueado]);
            exit;
        }

        /* ── Mandar aviso ── */
        if ($_POST['action'] === 'send_notice') {
            $titulo   = trim($_POST['titulo']   ?? '');
            $mensagem = trim($_POST['mensagem'] ?? '');

            if ($titulo   === '') $titulo   = 'Aviso do Laboratório';
            if ($mensagem === '') {
                echo json_encode(['ok' => false, 'error' => 'A mensagem não pode estar vazia.']);
                exit;
            }

            $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem) VALUES (:u, :t, :m)')
                ->execute(['u' => $id_user, 't' => $titulo, 'm' => $mensagem]);

            echo json_encode(['ok' => true]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Ação desconhecida']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Carrega usuários ────────────────────────────────────────── */
$usuarios = [];
$db_ok    = false;
$search   = trim($_GET['q'] ?? '');

try {
    $pdo    = db();
    $sql    = "SELECT id_user, nome, login, matricula, bloqueado
               FROM   Usuario
               WHERE  tipo_perfil = 'estudante'";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (nome LIKE :q OR matricula LIKE :q OR login LIKE :q)';
        $params['q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nome ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
    $db_ok    = true;
} catch (Throwable) { /* BD indisponível */ }

$page_title = 'Usuários';
require_once '../includes/header.php';
?>

<style>
    .main {
        padding: 40px 40px 80px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho ──────────────────────────────────────── */
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
    .btn-back svg   { width: 20px; height: 20px; }

    /* ── Barra de busca + filtro ─────────────────────── */
    .toolbar {
        display: flex;
        gap: 16px;
        margin-bottom: 28px;
        align-items: center;
    }

    .search-wrap {
        flex: 1;
        position: relative;
    }

    .search-input {
        width: 100%;
        padding: 14px 50px 14px 20px;
        background-color: #1e1e1e;
        border: 1.5px solid #2e2e2e;
        border-radius: 12px;
        color: #ffffff;
        font-size: 0.95rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-input::placeholder { color: #555; }
    .search-input:focus { border-color: #555; }

    .search-btn {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #aaa;
        cursor: pointer;
        display: flex;
        align-items: center;
        padding: 0;
    }

    .search-btn svg { width: 20px; height: 20px; }

    .btn-filter {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 14px 24px;
        background-color: #ffffff;
        color: #000000;
        border: none;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filter:hover { background-color: #e5e5e5; }
    .btn-filter svg   { width: 18px; height: 18px; }

    /* ── Lista de usuários ──────────────────────────── */
    .user-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .user-card {
        background-color: #1e1e1e;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: border-color 0.15s;
    }

    .user-card.bloqueado { border-color: #7f1d1d; }

    .user-info { flex: 1; min-width: 0; }

    .user-nome {
        font-size: 1.05rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .user-sub { font-size: 0.85rem; color: #888; }

    .badge-bloqueado {
        font-size: 0.75rem;
        font-weight: 700;
        background-color: #7f1d1d;
        color: #fca5a5;
        padding: 3px 10px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    /* ── Botão de menu (3 riscos) ───────────────────── */
    .btn-menu {
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
        flex-shrink: 0;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: #333; }
    .btn-menu svg   { width: 20px; height: 20px; }

    /* ── Dropdown de ações ──────────────────────────── */
    .menu-wrap { position: relative; }

    .action-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 200px;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        z-index: 50;
    }

    .menu-wrap.open .action-dropdown { display: block; }

    .action-dropdown button {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 14px 18px;
        background: none;
        border: none;
        border-bottom: 1px solid #2a2a2a;
        color: #ffffff;
        font-size: 0.9rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
    }

    .action-dropdown button:last-child { border-bottom: none; }
    .action-dropdown button:hover      { background-color: #2a2a2a; }

    .action-dropdown button svg {
        width: 17px;
        height: 17px;
        flex-shrink: 0;
        color: #aaa;
    }

    .action-dropdown button.danger      { color: #ef4444; }
    .action-dropdown button.danger  svg { color: #ef4444; }
    .action-dropdown button.unblock     { color: #4ade80; }
    .action-dropdown button.unblock svg { color: #4ade80; }

    /* ── Estado vazio ───────────────────────────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
    }

    /* ══════════════════════════════════════════════════
       MODAL DE AVISO
    ══════════════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.75);
        z-index: 200;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.open { display: flex; }

    .modal {
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 20px;
        padding: 36px;
        width: 100%;
        max-width: 520px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #ffffff;
    }

    .modal-recipient {
        font-size: 0.9rem;
        color: #888;
        margin-top: -12px;
    }

    .modal-recipient span {
        color: #ffffff;
        font-weight: 600;
    }

    .modal label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #aaa;
        margin-bottom: 8px;
    }

    .modal input,
    .modal textarea {
        width: 100%;
        background-color: #141414;
        border: 1.5px solid #2e2e2e;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        padding: 12px 16px;
        transition: border-color 0.2s;
        resize: vertical;
    }

    .modal input::placeholder,
    .modal textarea::placeholder { color: #444; }

    .modal input:focus,
    .modal textarea:focus { border-color: #555; }

    .modal textarea { min-height: 120px; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancel {
        padding: 12px 24px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-cancel:hover { background-color: #333; }

    .btn-send {
        padding: 12px 24px;
        background-color: #ffffff;
        border: none;
        border-radius: 10px;
        color: #000000;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-send:hover    { background-color: #e5e5e5; }
    .btn-send:disabled { opacity: 0.5; cursor: not-allowed; }

    /* ── Toast ──────────────────────────────────────── */
    .toast {
        position: fixed;
        bottom: 28px;
        left: 50%;
        transform: translateX(-50%) translateY(60px);
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        padding: 14px 24px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        z-index: 300;
        transition: transform 0.3s ease, opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
        white-space: nowrap;
    }

    .toast.show    { transform: translateX(-50%) translateY(0); opacity: 1; }
    .toast.success { border-color: #166534; color: #4ade80; }
    .toast.error   { border-color: #7f1d1d; color: #f87171; }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
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
    function toggleDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }
    document.addEventListener('click', function (e) {
        const n = document.getElementById('navUser');
        if (!n.contains(e.target)) n.classList.remove('open');
    });
</script>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <div class="page-header">
        <h1 class="page-title">Usuários</h1>
        <a href="./index.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Barra de busca -->
    <form class="toolbar" method="GET" action="">
        <div class="search-wrap">
            <input
                class="search-input"
                type="text"
                name="q"
                placeholder="Pesquisar por usuário"
                value="<?= htmlspecialchars($search) ?>"
            >
            <button type="submit" class="search-btn" aria-label="Buscar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </button>
        </div>

        <button type="button" class="btn-filter">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
            </svg>
            Filtrar
        </button>
    </form>

    <?php if (!$db_ok): ?>
    <div style="background-color:#1c1c1c;border:1px solid #3a1a1a;border-radius:16px;padding:40px;text-align:center;color:#aaa;">
        <h2 style="color:#ef4444;margin-bottom:8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar os usuários. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($usuarios)): ?>
        <p class="empty-state">Nenhum usuário encontrado<?= $search !== '' ? ' para "' . htmlspecialchars($search) . '"' : '' ?>.</p>
    <?php else: ?>

    <div class="user-list">
        <?php foreach ($usuarios as $u): ?>
        <div class="user-card <?= $u['bloqueado'] ? 'bloqueado' : '' ?>" id="card-<?= $u['id_user'] ?>">

            <div class="user-info">
                <p class="user-nome"><?= htmlspecialchars($u['nome']) ?></p>
                <p class="user-sub">
                    <?= $u['matricula'] !== null && $u['matricula'] !== ''
                        ? 'Matrícula: ' . htmlspecialchars($u['matricula'])
                        : 'Login: '     . htmlspecialchars($u['login']) ?>
                </p>
            </div>

            <span
                class="badge-bloqueado"
                id="badge-<?= $u['id_user'] ?>"
                <?= $u['bloqueado'] ? '' : 'style="display:none"' ?>
            >Bloqueado</span>

            <div class="menu-wrap" id="menu-<?= $u['id_user'] ?>">
                <button
                    class="btn-menu"
                    onclick="toggleMenu(<?= $u['id_user'] ?>, event)"
                    aria-label="Ações"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8"  y1="6"  x2="21" y2="6"/>
                        <line x1="8"  y1="12" x2="21" y2="12"/>
                        <line x1="8"  y1="18" x2="21" y2="18"/>
                        <line x1="3"  y1="6"  x2="3.01" y2="6"/>
                        <line x1="3"  y1="12" x2="3.01" y2="12"/>
                        <line x1="3"  y1="18" x2="3.01" y2="18"/>
                    </svg>
                </button>

                <div class="action-dropdown" role="menu">

                    <button
                        class="<?= $u['bloqueado'] ? 'unblock' : 'danger' ?>"
                        id="btn-block-<?= $u['id_user'] ?>"
                        onclick="toggleBlock(<?= $u['id_user'] ?>)"
                    >
                        <?php if ($u['bloqueado']): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                        </svg>
                        Desbloquear
                        <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Bloquear
                        <?php endif; ?>
                    </button>

                    <button onclick="openNoticeModal(<?= $u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        Mandar aviso
                    </button>

                </div>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</main>

<!-- ══════════════════ MODAL ══════════════════ -->
<div class="modal-overlay" id="noticeModal" onclick="closeModalOnBackdrop(event)">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <h2 class="modal-title" id="modalTitle">Mandar aviso</h2>
        <p class="modal-recipient">Para: <span id="modalRecipient">—</span></p>

        <div>
            <label for="noticeTitulo">Título</label>
            <input
                type="text"
                id="noticeTitulo"
                placeholder="Ex: Devolução pendente"
                maxlength="150"
            >
        </div>

        <div>
            <label for="noticeMensagem">Mensagem</label>
            <textarea
                id="noticeMensagem"
                placeholder="Escreva a mensagem para o estudante…"
                maxlength="2000"
            ></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeNoticeModal()">Cancelar</button>
            <button class="btn-send" id="btnSend" onclick="sendNotice()">Enviar</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
/* ── Menus de ação ───────────────────────────────── */
function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap   = document.getElementById('menu-' + id);
    const isOpen = wrap.classList.contains('open');
    closeAllMenus();
    if (!isOpen) wrap.classList.add('open');
}

function closeAllMenus() {
    document.querySelectorAll('.menu-wrap.open').forEach(el => el.classList.remove('open'));
}

document.addEventListener('click', closeAllMenus);

/* ── Bloquear / Desbloquear ──────────────────────── */
function toggleBlock(id) {
    closeAllMenus();

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_block&id_user=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Erro: ' + data.error, 'error'); return; }

        const card  = document.getElementById('card-'      + id);
        const badge = document.getElementById('badge-'     + id);
        const btn   = document.getElementById('btn-block-' + id);

        if (data.bloqueado) {
            card.classList.add('bloqueado');
            badge.style.display = '';
            btn.className = 'unblock';
            btn.innerHTML = svgLockOpen() + ' Desbloquear';
            showToast('Usuário bloqueado.', 'error');
        } else {
            card.classList.remove('bloqueado');
            badge.style.display = 'none';
            btn.className = 'danger';
            btn.innerHTML = svgLockClosed() + ' Bloquear';
            showToast('Usuário desbloqueado.', 'success');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'));
}

function svgLockOpen() {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
    </svg>`;
}

function svgLockClosed() {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </svg>`;
}

/* ── Modal de aviso ──────────────────────────────── */
let currentUserId = null;

function openNoticeModal(id, nome) {
    closeAllMenus();
    currentUserId = id;
    document.getElementById('modalRecipient').textContent  = nome;
    document.getElementById('noticeTitulo').value          = '';
    document.getElementById('noticeMensagem').value        = '';
    document.getElementById('noticeModal').classList.add('open');
    setTimeout(() => document.getElementById('noticeTitulo').focus(), 50);
}

function closeNoticeModal() {
    document.getElementById('noticeModal').classList.remove('open');
    currentUserId = null;
}

function closeModalOnBackdrop(e) {
    if (e.target === document.getElementById('noticeModal')) closeNoticeModal();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNoticeModal(); });

function sendNotice() {
    if (!currentUserId) return;

    const titulo   = document.getElementById('noticeTitulo').value.trim();
    const mensagem = document.getElementById('noticeMensagem').value.trim();

    if (!mensagem) {
        document.getElementById('noticeMensagem').focus();
        showToast('Escreva uma mensagem antes de enviar.', 'error');
        return;
    }

    const btn = document.getElementById('btnSend');
    btn.disabled    = true;
    btn.textContent = 'Enviando…';

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action:   'send_notice',
            id_user:  currentUserId,
            titulo:   titulo || 'Aviso do Laboratório',
            mensagem: mensagem
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeNoticeModal();
            showToast('Aviso enviado com sucesso!', 'success');
        } else {
            showToast('Erro: ' + data.error, 'error');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'))
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = 'Enviar';
    });
}

/* ── Toast ───────────────────────────────────────── */
let toastTimer = null;

function showToast(msg, type = '') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'toast ' + type;
    void el.offsetWidth;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}
</script>

</body>
</html>
