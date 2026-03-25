<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

$page_title = 'Notificações';
require_once '../includes/header.php';

/* ── Notificações: serão carregadas do BD ── */
$notificacoes = [];
$db_ok = false;

try {
    $pdo = db();
    $id_usuario = $_SESSION['auth_user']['id'] ?? null;
    
    if ($id_usuario) {
        $stmt = $pdo->prepare('
            SELECT id_not, titulo, mensagem, lida, data
            FROM   Notificacao
            WHERE  id_user = :id_user
            ORDER  BY data DESC
        ');
        $stmt->execute(['id_user' => $id_usuario]);
        $notificacoes = $stmt->fetchAll();
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

    /* ── Subtítulo de seção ───────────────── */
    .section-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 20px;
    }

    /* ── Lista de notificações ────────────── */
    .notif-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .notif-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    /* ── Ícone do robô ────────────────────── */
    .notif-icon {
        width: 60px;
        height: 60px;
        border: 2px solid #3a3a3a;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #ffffff;
    }

    .notif-icon svg {
        width: 36px;
        height: 36px;
    }

    /* ── Texto da notificação ─────────────── */
    .notif-body {
        flex: 1;
        min-width: 0;
    }

    .notif-titulo {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .notif-mensagem {
        font-size: 0.85rem;
        color: #aaa;
        margin-bottom: 4px;
        line-height: 1.5;
    }

    .notif-mensagem strong { color: #ffffff; }
    .notif-mensagem em     { font-style: italic; color: #cccccc; }

    .notif-data {
        font-size: 0.78rem;
        color: #666;
    }

    /* ── Ações ────────────────────────────── */
    .notif-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .status-badge {
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 700;
        white-space: nowrap;
        text-align: center;
        min-width: 110px;
    }

    .status-badge.pronto-para-retirada { background-color: #92400e; color: #ffffff; }
    .status-badge.em-separacao         { background-color: #7e22ce; color: #ffffff; }
    .status-badge.enviado              { background-color: #854d0e; color: #ffffff; }
    .status-badge.negado               { background-color: #b91c1c; color: #ffffff; }
    .status-badge.em-andamento         { background-color: #1a56db; color: #ffffff; }
    .status-badge.finalizado           { background-color: #166534; color: #ffffff; }

    .btn-details {
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
        text-decoration: none;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-details:hover { background-color: #333; }

    .btn-details svg {
        width: 20px;
        height: 20px;
    }

    /* ── Vazio ────────────────────────────── */
    .notif-empty {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
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

        <a href="./pedido.php" class="nav-action-btn">
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

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Notificações</h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <h2 class="section-title">Suas notificações</h2>

    <?php if (!$db_ok): ?>
    <div style="background-color: #1c1c1c; border: 1px solid #3a1a1a; border-radius: 16px; padding: 40px; text-align: center; color: #aaa;">
        <h2 style="color: #ef4444; margin-bottom: 8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar as notificações. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($notificacoes)): ?>
        <p class="notif-empty">Nenhuma notificação por enquanto.</p>
    <?php else: ?>
    <div class="notif-list">
        <?php foreach ($notificacoes as $n): ?>
        <div class="notif-card" style="<?= $n['lida'] ? 'opacity:0.7' : '' ?>">

            <!-- Ícone robô -->
            <div class="notif-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="16" rx="3"/>
                    <circle cx="8"  cy="10" r="1" fill="currentColor"/>
                    <circle cx="16" cy="10" r="1" fill="currentColor"/>
                    <path d="M9 14c.8 1 5.2 1 6 0"/>
                    <line x1="12" y1="19" x2="12" y2="21"/>
                    <line x1="8"  y1="21" x2="16" y2="21"/>
                </svg>
            </div>

            <!-- Corpo -->
            <div class="notif-body">
                <p class="notif-titulo">
                    <?= htmlspecialchars($n['titulo']) ?>
                    <?php if (!$n['lida']): ?>
                    <span style="display:inline-block;width:8px;height:8px;background:#3b82f6;border-radius:50%;margin-left:6px;vertical-align:middle;"></span>
                    <?php endif; ?>
                </p>
                <p class="notif-mensagem"><?= nl2br(htmlspecialchars($n['mensagem'])) ?></p>
                <p class="notif-data"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($n['data']))) ?></p>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

</body>
</html>
