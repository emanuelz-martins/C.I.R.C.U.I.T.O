<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';
require_once '../../src/services/notificacoes.php';

$page_title = 'Notificações';
require_once '../includes/header.php';

$notificacoes = [];
$db_ok        = false;
$id_usuario   = $_SESSION['auth_user']['id'] ?? null;

try {
    $pdo = db();

    $pdo->exec("ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'automatica'");
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS humor VARCHAR(10) NULL DEFAULT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL');

    if ($id_usuario) {
        $stmt = $pdo->prepare("
            SELECT id_not, titulo, mensagem, tipo, humor, lida, data, id_pedido
            FROM Notificacao
            WHERE id_user = :id_user AND tipo IN ('aviso_adm', 'aviso')
            ORDER BY data DESC
        ");
        $stmt->execute(['id_user' => $id_usuario]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $db_ok = true;
} catch (Throwable) {}
?>

<style>
    .main {
        padding: 48px 48px 80px;
        max-width: 860px;
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

    .notif-list { display: flex; flex-direction: column; gap: 12px; }

    .notif-card {
        background: var(--bg-superficie-tabela);
        border: 1.5px solid var(--borda-card);
        border-radius: 16px;
        padding: 20px 22px;
        display: flex;
        gap: 16px;
        align-items: flex-start;
        transition: border-color .2s, opacity .3s;
    }
    .notif-card.nao-lida { border-color: var(--cor-primaria); }

    .notif-icon {
        flex-shrink: 0;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--bg-info-icone);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .notif-icon svg { width: 22px; height: 22px; color: var(--texto-info-icone); }

    .notif-body { flex: 1; min-width: 0; }

    .notif-top {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 6px;
    }

    .notif-titulo { font-size: 0.95rem; font-weight: 700; color: var(--texto-principal); flex: 1; }

    .notif-unread-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--cor-primaria);
        flex-shrink: 0;
        margin-top: 6px;
    }

    .notif-remetente {
        margin: 0 0 6px;
        font-size: 0.82rem;
        color: var(--texto-secundario);
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .notif-msg {
        margin: 0;
        font-size: 0.88rem;
        color: var(--texto-suave);
        line-height: 1.6;
        white-space: pre-line;
        word-break: break-word;
    }

    .notif-pedido-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 12px;
        padding: 6px 14px;
        background: var(--bg-info-badge);
        border-radius: 8px;
        color: var(--texto-info);
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: background .15s;
    }
    .notif-pedido-link:hover { background: var(--cor-primaria-hover); color: #ffffff; }
    .notif-pedido-link svg { width: 14px; height: 14px; }

    .notif-footer {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 14px;
        flex-wrap: wrap;
    }

    .notif-data { font-size: 0.78rem; color: var(--texto-placeholder); flex: 1; }

    .notif-action-btn {
        background: none;
        border: 1px solid var(--borda-padrao);
        border-radius: 8px;
        color: var(--texto-secundario);
        font-size: 0.8rem;
        padding: 5px 12px;
        cursor: pointer;
        transition: border-color .15s, color .15s;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .notif-action-btn:hover { border-color: var(--borda-forte); color: var(--texto-principal); }
    .notif-action-btn svg { width: 13px; height: 13px; }
    .notif-action-btn.danger:hover { border-color: var(--cor-erro-forte); color: var(--cor-erro-claro); }

    .empty-state {
        text-align: center;
        padding: 80px 20px;
        color: var(--texto-placeholder);
    }
    .empty-state svg { width: 56px; height: 56px; margin-bottom: 18px; color: var(--icone-vazio); display: block; margin-left: auto; margin-right: auto; }
    .empty-state p { font-size: 1rem; }

    .error-banner {
        background: var(--bg-erro-sutil);
        border: 1px solid var(--borda-erro);
        border-radius: 12px;
        padding: 16px 20px;
        color: var(--cor-erro-claro);
        font-size: 0.9rem;
    }

    @media (max-width: 600px) {
        .main { padding: 32px 16px 60px; }
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
                <?php require __DIR__ . '/../includes/menu_perfil.php'; ?>
                <a href="./notificacao.php" role="menuitem">
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
        <span>Notificações</span>
    </nav>

    <h1 class="page-title">Avisos do Administrador</h1>

    <?php if (!$db_ok): ?>
        <div class="error-banner">Banco de dados indisponível no momento.</div>
    <?php elseif (empty($notificacoes)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <p>Nenhuma notificação do administrador por enquanto.</p>
        </div>
    <?php else: ?>
        <div class="notif-list" id="notifList">

        <?php foreach ($notificacoes as $n): ?>
        <?php
            $lida     = (int) $n['lida'];
            $idNot    = (int) $n['id_not'];
            $idPedido = (int) ($n['id_pedido'] ?? 0);
            $data     = $n['data'] ? date('d/m/Y \à\s H:i', strtotime($n['data'])) : '';
            $texto    = separarRemetenteNotificacao((string) $n['mensagem']);
        ?>
        <div class="notif-card <?= $lida ? '' : 'nao-lida' ?>" id="notif-<?= $idNot ?>">
            <div class="notif-icon">
                <?php if (!empty($n['humor']) && in_array($n['humor'], ['feliz', 'neutro', 'triste'], true)):
                    [$corHumor, $bocaHumor] = match ($n['humor']) {
                        'feliz'  => ['var(--cor-sucesso)', '<path d="M9 14c.8 1 5.2 1 6 0"/>'],
                        'triste' => ['var(--cor-erro)', '<path d="M9 15c.8-1 5.2-1 6 0"/>'],
                        default  => ['var(--cor-alerta)', '<line x1="9" y1="14" x2="15" y2="14"/>'],
                    };
                ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="<?= $corHumor ?>" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color:<?= $corHumor ?>">
                    <rect x="2" y="3" width="20" height="16" rx="3"/>
                    <circle cx="8" cy="10" r="1" fill="<?= $corHumor ?>"/>
                    <circle cx="16" cy="10" r="1" fill="<?= $corHumor ?>"/>
                    <?= $bocaHumor ?>
                    <line x1="12" y1="19" x2="12" y2="21"/>
                    <line x1="8" y1="21" x2="16" y2="21"/>
                </svg>
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php endif; ?>
            </div>

            <div class="notif-body">
                <div class="notif-top">
                    <span class="notif-titulo"><?= htmlspecialchars($n['titulo']) ?></span>
                    <?php if (!$lida): ?>
                        <span class="notif-unread-dot" title="Não lida"></span>
                    <?php endif; ?>
                </div>

                <?php if ($texto['remetente'] !== ''): ?>
                <p class="notif-remetente"><?= htmlspecialchars($texto['remetente'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <p class="notif-msg"><?= htmlspecialchars(trim($texto['mensagem']), ENT_QUOTES, 'UTF-8') ?></p>

                <?php if ($idPedido): ?>
                <a class="notif-pedido-link" href="./acessar.php?pedido=<?= $idPedido ?>" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8"  y1="2" x2="8"  y2="6"/>
                        <line x1="3"  y1="10" x2="21" y2="10"/>
                    </svg>
                    Clique aqui para ver o Pedido #<?= $idPedido ?>
                </a>
                <?php endif; ?>

                <div class="notif-footer">
                    <span class="notif-data"><?= htmlspecialchars($data) ?></span>

                    <?php if (!$lida): ?>
                    <button class="notif-action-btn" onclick="marcarLida(this, <?= $idNot ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        Marcar como lida
                    </button>
                    <?php endif; ?>

                    <button class="notif-action-btn danger" onclick="excluirNotif(this, <?= $idNot ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14H6L5 6"/>
                            <path d="M10 11v6"/><path d="M14 11v6"/>
                        </svg>
                        Remover
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        </div>
    <?php endif; ?>

</main>

<script>
    function marcarLida(btn, id) {
        btn.disabled = true;
        const fd = new FormData();
        fd.append('acao', 'marcar_lida');
        fd.append('id', id);

        fetch('../api/notificacao_lab_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const card = document.getElementById('notif-' + id);
                    card.classList.remove('nao-lida');
                    const dot = card.querySelector('.notif-unread-dot');
                    if (dot) dot.remove();
                    btn.remove();
                }
            });
    }

    function excluirNotif(btn, id) {
        if (!confirm('Remover esta notificação?')) return;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('acao', 'excluir');
        fd.append('id', id);

        fetch('../api/notificacao_lab_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const card = document.getElementById('notif-' + id);
                    card.style.opacity = '0';
                    setTimeout(() => {
                        card.remove();
                        const list = document.getElementById('notifList');
                        if (list && !list.querySelector('.notif-card')) {
                            list.innerHTML = '<div class="empty-state"><p>Nenhuma notificação.</p></div>';
                        }
                    }, 300);
                }
            });
    }
</script>

</body>
</html>
