<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);
require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';
$_SESSION['csrf_gerenciar_user'] ??= bin2hex(random_bytes(32));

/* ── Ações AJAX ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf_gerenciar_user'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_gerenciar_user'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solicitação inválida. Recarregue a página.']);
        exit;
    }
    if ($_POST['action'] === 'toggle_block') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Banimentos são gerenciados somente pelo administrador no painel administrativo.']);
        exit;
    }

    try {
        $pdo     = db();
        $id_user = (int) ($_POST['id_user'] ?? 0);
        $authUser = $_SESSION['auth_user'] ?? [];
        $atorNome = trim((string) ($authUser['nome'] ?? 'Usuário'));
        $atorPerfil = (string) ($authUser['perfil'] ?? $authUser['tipo_perfil'] ?? 'laboratorista');
        $atorLabel = $atorPerfil === 'admin' ? 'Administrador' : 'Laboratorista';

        // A lista mostra alunos; o mesmo limite vale para IDs enviados manualmente.
        if ($atorPerfil !== 'admin') {
            $alvo = $pdo->prepare('SELECT tipo_perfil FROM Usuario WHERE id_user = :id');
            $alvo->execute(['id' => $id_user]);
            if ($alvo->fetchColumn() !== 'estudante') {
                negarAcesso(403, 'Laboratoristas só podem gerenciar alunos.');
            }
        }

        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueio_motivo TEXT NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_nome VARCHAR(150) NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_tipo VARCHAR(30) NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_em DATETIME NULL');

        if (!$id_user) {
            echo json_encode(['ok' => false, 'error' => 'ID de usuário inválido']);
            exit;
        }

        if ($_POST['action'] === 'toggle_preblock') {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));

            $stmtStatus = $pdo->prepare('SELECT nome, pre_bloqueado_manual FROM Usuario WHERE id_user = :id LIMIT 1');
            $stmtStatus->execute(['id' => $id_user]);
            $row = $stmtStatus->fetch();

            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $jaPreBloqueado = (int) ($row['pre_bloqueado_manual'] ?? 0) === 1;
            $nomeAluno = (string) ($row['nome'] ?? 'Usuário');

            if (!$jaPreBloqueado && $motivo === '') {
                echo json_encode(['ok' => false, 'error' => 'Informe o motivo do bloqueio.']);
                exit;
            }

            if ($jaPreBloqueado) {
                $pdo->prepare('UPDATE Usuario SET pre_bloqueado_manual = 0, pre_bloqueio_motivo = NULL, pre_bloqueado_por_nome = NULL, pre_bloqueado_por_tipo = NULL, pre_bloqueado_em = NULL WHERE id_user = :id')
                    ->execute(['id' => $id_user]);

                $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
                echo json_encode([
                    'ok' => true,
                    'pre_bloqueado' => 0,
                    'motivo' => '',
                    'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                    'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                    'pre_manual_ativo' => false,
                    'pre_manual_label' => '',
                ]);
                exit;
            }

            $pdo->prepare('UPDATE Usuario SET pre_bloqueado_manual = 1, pre_bloqueio_motivo = :motivo, pre_bloqueado_por_nome = :ator_nome, pre_bloqueado_por_tipo = :ator_tipo, pre_bloqueado_em = NOW() WHERE id_user = :id')
                ->execute([
                    'motivo' => $motivo,
                    'ator_nome' => $atorNome,
                    'ator_tipo' => $atorPerfil,
                    'id' => $id_user,
                ]);

            $mensagemNotif = sprintf(
                'Prezado, %s. Você foi bloqueado pelo %s: %s.',
                $nomeAluno,
                $atorLabel,
                $atorNome
            );
            $atorTipoLabel = $atorPerfil === 'admin' ? 'Adm.' : 'Lab.';
            $preManualLabel = 'Bloqueado, por ' . $atorTipoLabel . ': ' . $atorNome;

            $notCols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $notCols->execute(['t' => 'Notificacao']);
            $cols = $notCols->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('humor', $cols, true)) {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor) VALUES (:u, :t, :m, :tp, :h)')
                    ->execute(['u' => $id_user, 't' => 'Bloqueio aplicado', 'm' => $mensagemNotif, 'tp' => 'aviso', 'h' => 'neutro']);
            } else {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo) VALUES (:u, :t, :m, :tp)')
                    ->execute(['u' => $id_user, 't' => 'Bloqueio aplicado', 'm' => $mensagemNotif, 'tp' => 'aviso']);
            }

            $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
            echo json_encode([
                'ok' => true,
                'pre_bloqueado' => 1,
                'motivo' => $motivo,
                'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                'pre_manual_ativo' => true,
                'pre_manual_label' => $preManualLabel,
            ]);
            exit;
        }

        /* ── Mandar aviso ── */
        if ($_POST['action'] === 'send_notice') {
            $titulo   = trim($_POST['titulo']   ?? '');
            $mensagem = trim($_POST['mensagem'] ?? '');
            $humor    = trim($_POST['humor']    ?? '');

            if (!in_array($humor, ['feliz', 'triste', 'neutro'], true)) $humor = '';

            if ($titulo   === '') $titulo   = 'Aviso do Laboratório';
            if ($mensagem === '') {
                echo json_encode(['ok' => false, 'error' => 'A mensagem não pode estar vazia.']);
                exit;
            }

            /* verifica se coluna humor existe */
            $notCols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $notCols->execute(['t' => 'Notificacao']);
            $cols = $notCols->fetchAll(PDO::FETCH_COLUMN);

            if ($humor !== '' && in_array('humor', $cols, true)) {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor) VALUES (:u, :t, :m, :tp, :h)')
                    ->execute(['u' => $id_user, 't' => $titulo, 'm' => $mensagem, 'tp' => 'aviso', 'h' => $humor]);
            } else {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo) VALUES (:u, :t, :m, :tp)')
                    ->execute(['u' => $id_user, 't' => $titulo, 'm' => $mensagem, 'tp' => 'aviso']);
            }

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
$preBloqueioAutoMap = [];

try {
    $pdo    = db();
    $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueio_motivo TEXT NULL');
    $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_nome VARCHAR(150) NULL');
    $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_tipo VARCHAR(30) NULL');

    $atorId = (int) ($_SESSION['auth_user']['id'] ?? 0);
    $sql    = "SELECT id_user, nome, matricula, bloqueado, foto_perfil, pre_bloqueado_manual, pre_bloqueio_motivo, pre_bloqueado_por_nome, pre_bloqueado_por_tipo
               FROM   Usuario
               WHERE  tipo_perfil = 'estudante' AND id_user <> :ator_id";
    $params = ['ator_id' => $atorId];

    if ($search !== '') {
        $sql .= ' AND (nome LIKE :q_nome OR matricula LIKE :q_matricula)';
        $params['q_nome'] = '%' . $search . '%';
        $params['q_matricula'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nome ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

    foreach ($usuarios as $usr) {
        $uid = (int) ($usr['id_user'] ?? 0);
        if ($uid <= 0) continue;

        try {
            $st = aluno_pre_bloqueio_status($pdo, $uid);
            $preBloqueioAutoMap[$uid] = [
                'pre_bloqueado' => (($st['pre_bloqueado'] ?? false) === true),
                'motivo' => (string) ($st['motivo'] ?? ''),
            ];
        } catch (Throwable) {
            $preBloqueioAutoMap[$uid] = [
                'pre_bloqueado' => false,
                'motivo' => '',
            ];
        }
    }

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
        color: var(--texto-principal);
        line-height: 1.1;
    }

    .btn-back {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-back:hover { background-color: var(--bg-controle-hover); }
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
        background-color: var(--bg-modal);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 12px;
        color: var(--texto-principal);
        font-size: 0.95rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-input::placeholder { color: var(--texto-placeholder); }
    .search-input:focus { border-color: var(--borda-foco); }

    .search-btn {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--texto-suave);
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
        background-color: var(--bg-acao-primaria);
        color: var(--texto-acao-primaria);
        border: none;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filter:hover { background-color: var(--bg-acao-primaria-hover); }
    .btn-filter svg   { width: 18px; height: 18px; }

    /* ── Lista de usuários ──────────────────────────── */
    .user-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .user-card {
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-card);
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: border-color 0.15s;
    }

    .user-card.bloqueado { border-color: var(--borda-erro); }
    .user-card.prebloqueado {
        border-color: var(--borda-erro);
        background: var(--gradiente-prebloqueado);
    }

    .user-avatar {
        width: 46px;
        height: 46px;
        border-radius: 10px;
        background-color: var(--bg-controle);
        flex-shrink: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-avatar svg {
        width: 26px;
        height: 26px;
        color: var(--texto-placeholder);
    }

    .user-info { flex: 1; min-width: 0; }

    .user-nome {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--texto-principal);
        margin-bottom: 4px;
    }

    .user-sub { font-size: 0.85rem; color: var(--texto-secundario); }

    .badge-bloqueado {
        font-size: 0.75rem;
        font-weight: 700;
        background-color: var(--bg-erro-badge);
        color: var(--texto-erro);
        padding: 3px 10px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .badge-prebloqueado {
        font-size: 0.75rem;
        font-weight: 700;
        background-color: var(--bg-erro-badge);
        color: var(--texto-erro);
        padding: 3px 10px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
        margin-right: 8px;
    }

    /* ── Botão de menu (3 riscos) ───────────────────── */
    .btn-menu {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: var(--bg-controle-hover); }
    .btn-menu svg   { width: 20px; height: 20px; }

    /* ── Dropdown de ações ──────────────────────────── */
    .menu-wrap { position: relative; }

    .action-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 12px;
        min-width: 200px;
        overflow: hidden;
        box-shadow: var(--sombra-modal);
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
        border-bottom: 1px solid var(--borda-card);
        color: var(--texto-principal);
        font-size: 0.9rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
    }

    .action-dropdown button:last-child { border-bottom: none; }
    .action-dropdown button:hover      { background-color: var(--bg-controle); }

    .action-dropdown button svg {
        width: 17px;
        height: 17px;
        flex-shrink: 0;
        color: var(--texto-suave);
    }

    .action-dropdown button.danger      { color: var(--cor-erro); }
    .action-dropdown button.danger  svg { color: var(--cor-erro); }
    .action-dropdown button.unblock     { color: var(--cor-sucesso); }
    .action-dropdown button.unblock svg { color: var(--cor-sucesso); }

    /* ── Estado vazio ───────────────────────────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: var(--texto-placeholder);
        font-size: 1rem;
    }

    /* ══════════════════════════════════════════════════
       MODAL DE AVISO
    ══════════════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: var(--overlay-modal);
        z-index: 200;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.open { display: flex; }

    .modal {
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
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
        color: var(--texto-principal);
    }

    .modal-recipient {
        font-size: 0.9rem;
        color: var(--texto-secundario);
        margin-top: -12px;
    }

    .modal-recipient span {
        color: var(--texto-principal);
        font-weight: 600;
    }

    .modal label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--texto-suave);
        margin-bottom: 8px;
    }

    .modal input,
    .modal textarea {
        width: 100%;
        background-color: var(--bg-subpainel);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 10px;
        color: var(--texto-principal);
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        padding: 12px 16px;
        transition: border-color 0.2s;
        resize: vertical;
    }

    .modal input::placeholder,
    .modal textarea::placeholder { color: var(--icone-vazio); }

    .modal input:focus,
    .modal textarea:focus { border-color: var(--borda-foco); }

    .modal textarea { min-height: 120px; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancel {
        padding: 12px 24px;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        border-radius: 10px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-cancel:hover { background-color: var(--bg-controle-hover); }

    .btn-send {
        padding: 12px 24px;
        background-color: var(--bg-acao-primaria);
        border: none;
        border-radius: 10px;
        color: var(--texto-acao-primaria);
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-send:hover    { background-color: var(--bg-acao-primaria-hover); }
    .btn-send:disabled { opacity: 0.5; cursor: not-allowed; }

    /* ── Toast ──────────────────────────────────────── */
    .toast {
        position: fixed;
        bottom: 28px;
        left: 50%;
        transform: translateX(-50%) translateY(60px);
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 12px;
        padding: 14px 24px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        font-weight: 600;
        z-index: 300;
        transition: transform 0.3s ease, opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
        white-space: nowrap;
    }

    .toast.show    { transform: translateX(-50%) translateY(0); opacity: 1; }
    .toast.success { border-color: var(--borda-sucesso); color: var(--cor-sucesso); }
    .toast.error   { border-color: var(--borda-erro); color: var(--cor-erro-claro); }

    /* ── Seletor de rosto ───────────────────── */
    .face-picker {
        display: flex;
        gap: 10px;
        margin-top: 4px;
    }

    .face-btn {
        flex: 1;
        padding: 10px 6px;
        background-color: var(--bg-subpainel);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        transition: border-color 0.15s, background-color 0.15s;
        color: var(--texto-suave);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .face-btn svg { width: 32px; height: 32px; }

    .face-btn:hover { background-color: var(--bg-modal); }

    .face-btn[data-humor="feliz"].selected  { border-color: var(--cor-sucesso); background-color: var(--bg-sucesso-sutil); color: var(--cor-sucesso); }
    .face-btn[data-humor="triste"].selected { border-color: var(--cor-erro); background-color: var(--bg-erro-sutil); color: var(--cor-erro); }
    .face-btn[data-humor="neutro"].selected { border-color: var(--cor-alerta); background-color: var(--bg-alerta-sutil); color: var(--cor-alerta); }
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
                <?php require __DIR__ . '/../includes/menu_perfil.php'; ?>
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
    <input type="hidden" id="gerenciarUserCsrf" name="csrf_gerenciar_user" value="<?= htmlspecialchars($_SESSION['csrf_gerenciar_user'], ENT_QUOTES, 'UTF-8') ?>">

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
    <div style="background-color:var(--bg-superficie);border:1px solid var(--borda-erro-sutil);border-radius:16px;padding:40px;text-align:center;color:var(--texto-suave);">
        <h2 style="color:var(--cor-erro);margin-bottom:8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar os usuários. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($usuarios)): ?>
        <p class="empty-state">Nenhum usuário encontrado<?= $search !== '' ? ' para "' . htmlspecialchars($search) . '"' : '' ?>.</p>
    <?php else: ?>

    <div class="user-list">
        <?php foreach ($usuarios as $u): ?>
        <?php
            $preBloqueadoManual = (int) ($u['pre_bloqueado_manual'] ?? 0) === 1;
            $preAutoStatus = $preBloqueioAutoMap[(int) $u['id_user']] ?? ['pre_bloqueado' => false, 'motivo' => ''];
            $preBloqueadoAuto = (($preAutoStatus['pre_bloqueado'] ?? false) === true);
            $motivoAuto = trim((string) ($preAutoStatus['motivo'] ?? ''));
            $mostrarPreBadge = $preBloqueadoManual || $preBloqueadoAuto;
            $preTipoRaw = mb_strtolower(trim((string) ($u['pre_bloqueado_por_tipo'] ?? '')));
            $preTipo = $preTipoRaw === 'admin' ? 'Adm.' : 'Lab.';
            $preNome = trim((string) ($u['pre_bloqueado_por_nome'] ?? ''));
            if ($preNome === '') $preNome = 'Usuário';
            $preBadgeText = $preBloqueadoManual
                ? ('Bloqueado, por ' . $preTipo . ': ' . $preNome)
                : ($preBloqueadoAuto ? 'Bloqueado, automaticamente.' : 'Bloqueado');
            $preBadgeTitle = $preBloqueadoManual
                ? (string) ($u['pre_bloqueio_motivo'] ?? '')
                : $motivoAuto;
            $mostrarOpcaoPreblock = $preBloqueadoManual || !$preBloqueadoAuto;
        ?>
        <div class="user-card <?= $u['bloqueado'] ? 'bloqueado' : '' ?> <?= $mostrarPreBadge ? 'prebloqueado' : '' ?>" id="card-<?= $u['id_user'] ?>">

            <div class="user-avatar">
                <?php if (!empty($u['foto_perfil'])): ?>
                    <img src="<?= htmlspecialchars($u['foto_perfil']) ?>" alt="Foto de <?= htmlspecialchars($u['nome']) ?>">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <p class="user-nome"><?= htmlspecialchars($u['nome']) ?></p>
                <p class="user-sub">
                    <?= $u['matricula'] !== null && $u['matricula'] !== ''
                        ? 'Matrícula: ' . htmlspecialchars($u['matricula'])
                        : 'Aluno' ?>
                </p>
            </div>

            <span
                class="badge-bloqueado"
                id="badge-<?= $u['id_user'] ?>"
                <?= $u['bloqueado'] ? '' : 'style="display:none"' ?>
            >Banido</span>

            <span
                class="badge-prebloqueado"
                id="prebadge-<?= $u['id_user'] ?>"
                <?= $mostrarPreBadge ? '' : 'style="display:none"' ?>
                title="<?= htmlspecialchars($preBadgeTitle) ?>"
            ><?= htmlspecialchars($preBadgeText) ?></span>

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

                    <button onclick="openNoticeModal(<?= $u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        Mandar aviso
                    </button>

                    <button
                        class="<?= $preBloqueadoManual ? 'unblock' : 'danger' ?>"
                        id="btn-preblock-<?= $u['id_user'] ?>"
                        style="<?= $mostrarOpcaoPreblock ? '' : 'display:none' ?>"
                        onclick="togglePreBlock(<?= $u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <?= $preBloqueadoManual ? 'Remover bloqueio' : 'Bloquear' ?>
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

        <div>
            <label>Rosto da notificação <span style="color:var(--texto-placeholder);font-weight:400">(opcional)</span></label>
            <div class="face-picker" id="facePicker">
                <button type="button" class="face-btn" data-humor="feliz" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <path d="M9 14c.8 1 5.2 1 6 0"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Feliz
                </button>
                <button type="button" class="face-btn" data-humor="neutro" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <line x1="9" y1="14" x2="15" y2="14"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Neutro
                </button>
                <button type="button" class="face-btn" data-humor="triste" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <path d="M9 15c.8-1 5.2-1 6 0"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Triste
                </button>
            </div>
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

const csrfGerenciarUser = document.getElementById('gerenciarUserCsrf').value;
function togglePreBlock(id, nome) {
    closeAllMenus();

    const btn = document.getElementById('btn-preblock-' + id);
    const removendo = btn && btn.textContent.toLowerCase().includes('remover');

    let motivo = '';
    if (!removendo) {
        motivo = (window.prompt('Informe o motivo do bloqueio para ' + nome + ':') || '').trim();
        if (!motivo) {
            showToast('Motivo obrigatório para bloquear.', 'error');
            return;
        }
    }

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfGerenciarUser, 'Accept': 'application/json' },
        body: new URLSearchParams({
            action: 'toggle_preblock',
            id_user: id,
            motivo: motivo,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            showToast('Erro: ' + data.error, 'error');
            return;
        }

        const card = document.getElementById('card-' + id);
        const badge = document.getElementById('prebadge-' + id);
        const botao = document.getElementById('btn-preblock-' + id);
        const preManualLabel = (data.pre_manual_label || '').trim();
        const preAutoAtivo = (data.pre_bloqueado_auto ?? false) === true || (data.pre_bloqueado_auto ?? 0) === 1;
        const motivoAuto = (data.motivo_auto || '').trim();

        if ((data.pre_bloqueado ?? 0) === 1) {
            card.classList.add('prebloqueado');
            if (badge) {
                badge.style.display = '';
                badge.textContent = preManualLabel || 'Bloqueado';
                badge.title = data.motivo || '';
            }
            if (botao) {
                botao.style.display = '';
                botao.className = 'unblock';
                botao.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Remover bloqueio`;
            }
            showToast('Usuário bloqueado e notificado.', 'error');
        } else {
            if (preAutoAtivo) {
                card.classList.add('prebloqueado');
                if (badge) {
                    badge.style.display = '';
                    badge.textContent = preAutoAtivo ? 'Bloqueado, automaticamente.' : 'Bloqueado';
                    badge.title = motivoAuto || 'Bloqueado automático';
                }
                if (botao && preAutoAtivo) botao.style.display = 'none';
            } else {
                card.classList.remove('prebloqueado');
                if (badge) {
                    badge.style.display = 'none';
                    badge.textContent = 'Bloqueado';
                    badge.title = '';
                }
                if (botao) botao.style.display = '';
            }
            if (botao) {
                if (!preAutoAtivo) {
                    botao.className = 'danger';
                    botao.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Bloquear`;
                }
            }
            showToast('Bloqueio removido.', 'success');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'));
}

/* ── Modal de aviso ──────────────────────────────── */
let currentUserId = null;
let selectedHumor = '';

function selectFace(btn) {
    document.querySelectorAll('#facePicker .face-btn').forEach(b => b.classList.remove('selected'));
    if (selectedHumor === btn.dataset.humor) {
        selectedHumor = '';
    } else {
        btn.classList.add('selected');
        selectedHumor = btn.dataset.humor;
    }
}

function openNoticeModal(id, nome) {
    closeAllMenus();
    currentUserId = id;
    selectedHumor = '';
    document.getElementById('modalRecipient').textContent  = nome;
    document.getElementById('noticeTitulo').value          = '';
    document.getElementById('noticeMensagem').value        = '';
    document.querySelectorAll('#facePicker .face-btn').forEach(b => b.classList.remove('selected'));
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
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfGerenciarUser, 'Accept': 'application/json' },
        body: new URLSearchParams({
            action:   'send_notice',
            id_user:  currentUserId,
            titulo:   titulo || 'Aviso do Laboratório',
            mensagem: mensagem,
            humor:    selectedHumor
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
