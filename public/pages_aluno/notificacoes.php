<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';
require_once '../../src/services/notificacoes.php';

$page_title = 'Notificações';
require_once '../includes/header.php';

/* ── Notificações: serão carregadas do BD ── */
$notificacoes = ['avisos' => [], 'automaticas' => []];
$db_ok = false;
$erroFluxo = trim((string) ($_GET['erro'] ?? ''));
$alunoPreBloqueado = false;

try {
    $pdo = db();
    $id_usuario = $_SESSION['auth_user']['id'] ?? null;

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

    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_nota_atraso INT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS requer_resposta TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS resposta_pendente TINYINT(1) NOT NULL DEFAULT 0');
    
    if ($id_usuario) {
        $statusPreBloqueio = aluno_pre_bloqueio_status($pdo, (int) $id_usuario);
        $alunoPreBloqueado = ($statusPreBloqueio['pre_bloqueado'] ?? false) === true;

        $stmt = $pdo->prepare('
            SELECT id_not, titulo, mensagem, tipo, humor, lida, data, id_pedido, id_nota_atraso, requer_resposta, resposta_pendente
            FROM   Notificacao
            WHERE  id_user = :id_user
            ORDER  BY data DESC
        ');
        $stmt->execute(['id_user' => $id_usuario]);
        $todas = $stmt->fetchAll();

        foreach ($todas as $n) {
            if (in_array($n['tipo'], ['aviso', 'aviso_adm'], true)) {
                $notificacoes['avisos'][]     = $n;
            } else {
                $notificacoes['automaticas'][] = $n;
            }
        }
    }
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
}

/**
 * Retorna o SVG do robô com a expressão correta.
 * Se humor for null/vazio, detecta pelo conteúdo da mensagem como fallback.
 */
function robotFaceSvg(?string $humor, string $mensagem = '', string $tipo = ''): string {
    /* Fallback: detecta pelo conteúdo se humor não veio do BD */
    if (!$humor) {
        $m = mb_strtolower($mensagem);
        if (str_contains($m, 'aprovado') || str_contains($m, 'pronto para retirada')) {
            $humor = 'feliz';
        } elseif (str_contains($m, 'negado') || str_contains($m, 'atrasado') || str_contains($m, 'atraso')) {
            $humor = 'triste';
        } elseif (str_contains($m, 'falta') && (str_contains($m, 'dia') || str_contains($m, 'prazo'))) {
            $humor = 'neutro';
        }
    }

    [$color, $mouth] = match($humor) {
        'feliz'  => ['var(--cor-sucesso)', '<path d="M9 14c.8 1 5.2 1 6 0"/>'],
        'triste' => ['var(--cor-erro)', '<path d="M9 15c.8-1 5.2-1 6 0"/>'],
        'neutro' => ['var(--cor-alerta)', '<line x1="9" y1="14" x2="15" y2="14"/>'],
        default  => ['currentColor', '<path d="M9 14c.8 1 5.2 1 6 0"/>'],
    };

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="%s" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="20" height="16" rx="3"/>
            <circle cx="8" cy="10" r="1" fill="%s"/>
            <circle cx="16" cy="10" r="1" fill="%s"/>
            %s
            <line x1="12" y1="19" x2="12" y2="21"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
        </svg>',
        $color, $color, $color, $mouth
    );
}

function formatarMensagemNotificacao(string $mensagem): string {
    $safe = htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8');
    $safe = preg_replace_callback(
        '/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i',
        static function (array $m): string {
            $email = $m[1];
            $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            return '<a href="mailto:' . $safeEmail . '" style="color:var(--link-cor);text-decoration:underline;">clicando aqui</a> (' . $safeEmail . ')';
        },
        $safe
    );

    return nl2br($safe);
}
?>

<style>
    .main { padding:40px 40px 80px; max-width:1100px; margin:0 auto; }
    .page-header { display:flex; align-items:center; justify-content:space-between; gap:20px; margin-bottom:32px; }
    .page-title { font-size:3rem; font-weight:800; color:var(--texto-principal); line-height:1.1; }
    .btn-back { width:44px; height:44px; display:grid; place-items:center; border-radius:50%; background:var(--bg-controle); border:1px solid var(--borda-controle); color:var(--texto-principal); flex-shrink:0; }
    .btn-back svg { width:20px; height:20px; }
    .notif-tabs { display:flex; gap:8px; padding:6px; margin-bottom:24px; border:1px solid var(--borda-card); border-radius:14px; background:var(--bg-superficie); width:fit-content; max-width:100%; }
    .notif-tab { display:flex; align-items:center; justify-content:center; gap:10px; border:1px solid transparent; border-radius:9px; padding:12px 18px; background:transparent; color:var(--texto-secundario); font:inherit; font-size:.9rem; font-weight:600; cursor:pointer; }
    .notif-tab[aria-selected="true"] { background:var(--bg-controle); border-color:var(--borda-controle); color:var(--texto-principal); }
    .count-badge { display:inline-grid; place-items:center; min-width:22px; height:22px; padding:0 6px; border-radius:20px; background:var(--cor-primaria); color:#fff; font-size:.72rem; }
    .notif-panel[hidden], .count-badge[hidden], .notif-empty[hidden] { display:none; }
    .notif-list { display:grid; gap:14px; }
    .notif-card { display:grid; grid-template-columns:48px minmax(0,1fr) auto; align-items:start; gap:20px; padding:24px; border:1px solid var(--borda-card); border-left:3px solid var(--cor-primaria); border-radius:16px; background:var(--bg-superficie); }
    .notif-card.lida { border-left-color:var(--borda-card); }
    .notif-icon { width:48px; height:48px; display:grid; place-items:center; border-radius:12px; background:var(--bg-controle); color:var(--texto-secundario); }
    .notif-icon svg { width:30px; height:30px; }
    .notif-body { min-width:0; }
    .notif-titulo { display:flex; align-items:center; gap:10px; margin:0 0 10px; color:var(--texto-principal); font-size:1rem; font-weight:700; overflow-wrap:anywhere; }
    .dot-new { width:7px; height:7px; flex-shrink:0; border-radius:50%; background:var(--cor-primaria); }
    .notif-remetente { margin:0 0 8px; color:var(--texto-secundario); font-size:.8rem; line-height:1.5; overflow-wrap:anywhere; }
    .notif-mensagem { margin:0; color:var(--texto-principal); font-size:.9rem; line-height:1.7; overflow-wrap:anywhere; }
    .notif-data { display:block; margin-top:14px; color:var(--texto-secundario); font-size:.75rem; }
    .notif-actions { display:flex; gap:8px; }
    .btn-notif { display:grid; place-items:center; width:36px; height:36px; border-radius:10px; border:1px solid var(--borda-controle); background:var(--bg-controle); color:var(--texto-secundario); cursor:pointer; }
    .btn-notif svg { width:18px; height:18px; }
    .btn-notif:hover { color:var(--texto-principal); }
    .btn-notif.active { color:var(--cor-primaria); }
    .btn-excluir:hover { color:var(--cor-erro); }
    .btn-notif:disabled { cursor:default; }
    .btn-notif:focus-visible, .notif-tab:focus-visible { outline:2px solid var(--cor-primaria); outline-offset:3px; }
    .notif-empty { padding:48px 24px; text-align:center; color:var(--texto-secundario); background:var(--bg-superficie); border:1px solid var(--borda-card); border-radius:16px; font-size:.9rem; }
    .alert-fluxo, .notif-error { padding:16px 20px; margin-bottom:24px; border-radius:12px; border:1px solid var(--borda-erro-sutil); color:var(--cor-erro); background:var(--bg-superficie); }
    .notif-error:empty { display:none; }
    @media (max-width:640px) {
        .main { padding:28px 20px 56px; }
        .page-title { font-size:2rem; }
        .notif-tabs { width:100%; gap:4px; }
        .notif-tab { flex:1; padding:10px 8px; gap:6px; font-size:.8rem; }
        .notif-card { grid-template-columns:40px minmax(0,1fr); gap:12px; padding:18px; }
        .notif-icon { width:40px; height:40px; }
        .notif-actions { grid-column:2; justify-content:flex-end; }
    }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="../index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <form class="nav-search" action="<?= htmlspecialchars($basePath) ?>/index.php" method="GET">
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
                <?php require __DIR__ . '/../includes/menu_perfil.php'; ?>
                <?php require __DIR__ . '/../includes/menu_notificacoes.php'; ?>
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
<main class="main" data-notificacoes-page data-action-url="../api/notificacao_action.php">
    <div class="page-header">
        <h1 class="page-title">Notificações</h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
    </div>
    <?php if ($erroFluxo === 'resposta_atraso'): ?>
    <div class="alert-fluxo">Você possui um pedido em atraso. Abra o aviso para consultar as orientações do laboratorista.</div>
    <?php endif; ?>
    <p class="notif-error" role="alert" data-notif-error></p>
    <?php if (!$db_ok): ?>
    <div class="notif-empty">Não foi possível carregar as notificações. Tente novamente.</div>
    <?php else:
        $grupos = ['avisos' => 'Avisos do laboratório', 'automaticas' => 'Pedidos'];
        $grupoInicial = empty($notificacoes['avisos']) && !empty($notificacoes['automaticas']) ? 'automaticas' : 'avisos';
    ?>
    <div class="notif-tabs" role="tablist" aria-label="Tipos de notificação">
        <?php foreach ($grupos as $grupo => $rotulo):
            $naoLidas = count(array_filter($notificacoes[$grupo], static fn($n) => !$n['lida']));
        ?>
        <button type="button" class="notif-tab" role="tab" id="tab-<?= $grupo ?>" aria-controls="painel-<?= $grupo ?>" aria-selected="<?= $grupo === $grupoInicial ? 'true' : 'false' ?>" tabindex="<?= $grupo === $grupoInicial ? '0' : '-1' ?>">
            <?= $rotulo ?><span class="count-badge" data-notificacoes-grupo="<?= $grupo === 'avisos' ? 'avisos' : 'pedidos' ?>" aria-label="<?= $naoLidas ?> não lidas" <?= $naoLidas ? '' : 'hidden' ?>><?= $naoLidas ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($grupos as $grupo => $rotulo): ?>
    <section class="notif-panel" id="painel-<?= $grupo ?>" role="tabpanel" aria-labelledby="tab-<?= $grupo ?>" tabindex="0" <?= $grupo === $grupoInicial ? '' : 'hidden' ?>>
        <div class="notif-list">
        <?php foreach ($notificacoes[$grupo] as $n):
            $texto = separarRemetenteNotificacao((string) ($n['mensagem'] ?? ''));
            $idNot = (int) $n['id_not'];
        ?>
            <article class="notif-card <?= $n['lida'] ? 'lida' : '' ?>" id="notif-<?= $idNot ?>" aria-labelledby="titulo-<?= $idNot ?>">
                <div class="notif-icon" aria-hidden="true"><?= robotFaceSvg($n['humor'] ?: null, (string) $n['mensagem'], (string) $n['tipo']) ?></div>
                <div class="notif-body">
                    <h2 class="notif-titulo" id="titulo-<?= $idNot ?>"><?= htmlspecialchars($n['titulo'], ENT_QUOTES, 'UTF-8') ?><?php if (!$n['lida']): ?><span class="dot-new" role="img" aria-label="Não lida"></span><?php endif; ?></h2>
                    <?php if ($texto['remetente'] !== ''): ?><p class="notif-remetente"><?= htmlspecialchars($texto['remetente'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <p class="notif-mensagem"><?= formatarMensagemNotificacao($texto['mensagem']) ?></p>
                    <time class="notif-data" datetime="<?= date('c', strtotime($n['data'])) ?>"><?= date('d/m/Y', strtotime($n['data'])) ?> às <?= date('H:i', strtotime($n['data'])) ?></time>
                </div>
                <div class="notif-actions">
                    <button type="button" class="btn-notif btn-lida <?= $n['lida'] ? 'active' : '' ?>" data-acao="marcar_lida" data-id="<?= $idNot ?>" title="<?= $n['lida'] ? 'Lida' : 'Marcar como lida' ?>" aria-label="<?= $n['lida'] ? 'Lida' : 'Marcar como lida' ?>" <?= $n['lida'] ? 'disabled' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <button type="button" class="btn-notif btn-excluir" data-acao="excluir" data-id="<?= $idNot ?>" title="Excluir notificação" aria-label="Excluir notificação">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6l-1 14H6L5 6M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <p class="notif-empty" <?= empty($notificacoes[$grupo]) ? '' : 'hidden' ?>><?= $grupo === 'avisos' ? 'Nenhum aviso do laboratório por enquanto.' : 'Nenhuma notificação de pedido por enquanto.' ?></p>
    </section>
    <?php endforeach; endif; ?>
</main>
<script src="<?= htmlspecialchars($basePath) ?>/assets/js/notificacoes.js" defer></script>
</body>
</html>
