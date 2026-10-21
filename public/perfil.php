<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
require_once __DIR__ . '/includes/perfil_pessoal.php';
$csrfPerfil = tokenPerfilPessoal();

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/includes/pre_bloqueio_aluno.php';

$page_title = 'Perfil';
require_once __DIR__ . '/includes/header.php';

/* ── Dados do usuário: carregados da sessão ── */
$perfilAtual = $_SESSION['auth_user']['perfil'];
$ehEstudante = $perfilAtual === 'estudante';
$usuario_role = match ($perfilAtual) {
    'admin' => 'Administrador',
    'laboratorista' => 'Laboratorista',
    default => 'Aluno',
};
$painelPerfil = $basePath . match ($perfilAtual) {
    'admin' => '/pages_admin/index.php',
    'laboratorista' => '/pages_laboratorista/index.php',
    default => '/index.php',
};
$notificacoesPerfil = match ($perfilAtual) {
    'estudante' => $basePath . '/pages_aluno/notificacoes.php',
    'laboratorista' => $basePath . '/pages_laboratorista/notificacao.php',
    default => null,
};
$alunoPreBloqueado = false;

/* ── Pedidos: serão carregados do BD ── */
$pedidos = [];
$db_ok = false;

$foto_perfil      = null;
$perfil_email     = '';
$perfil_matricula = '';
$perfil_descricao = '';
$perfil_google = false;

try {
    $pdo = db();
    $id_usuario = $_SESSION['auth_user']['id'] ?? null;

    if ($id_usuario) {
        if ($ehEstudante) {
            $statusPreBloqueio = aluno_pre_bloqueio_status($pdo, (int) $id_usuario);
            $alunoPreBloqueado = ($statusPreBloqueio['pre_bloqueado'] ?? false) === true;
        }

        $stmt = $pdo->prepare('SELECT nome, foto_perfil, email, matricula, descricao, google_sub FROM Usuario WHERE id_user = :id');
        $stmt->execute(['id' => $id_usuario]);
        $row_up           = $stmt->fetch();
        $foto_perfil      = $row_up ? ($row_up['foto_perfil'] ?: null) : null;
        $perfil_email     = $row_up ? (string)($row_up['email']     ?? '') : '';
        $usuario_nome = $row_up ? (string) $row_up['nome'] : $usuario_nome;
        $perfil_matricula = $row_up ? (string) ($row_up['matricula'] ?? '') : '';
        $perfil_descricao = $row_up ? (string)($row_up['descricao'] ?? '') : '';
        $perfil_google = $row_up && $row_up['google_sub'] !== null;

        /* Sincroniza sessão */
        $_SESSION['auth_user']['foto_perfil'] = $foto_perfil;

        if ($ehEstudante) {
            $getCols = static function (PDO $pdo, string $table): array {
                $stmtCols = $pdo->prepare('
                    SELECT COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
                ');
                $stmtCols->execute(['table' => $table]);
                return $stmtCols->fetchAll(PDO::FETCH_COLUMN);
            };

            $pedidoCols = $getCols($pdo, 'Pedido');
            $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
                : (in_array('status', $pedidoCols, true) ? 'status' : null);
            $numeroCol = in_array('numero_pedido', $pedidoCols, true) ? 'numero_pedido' : null;
            $dataCriacaoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao' : null;
            $dataAtualizacaoCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;

            if ($statusCol !== null) {
                $selectNumero = ($numeroCol !== null ? 'p.' . $numeroCol : 'p.id_pedido') . ' AS numero_pedido';
                $selectStatus = 'p.' . $statusCol . ' AS status_pedido';

                if ($dataAtualizacaoCol !== null && $dataCriacaoCol !== null) {
                    $selectData = "DATE_FORMAT(COALESCE(p.{$dataAtualizacaoCol}, p.{$dataCriacaoCol}), '%d/%m/%Y') AS ultima_atualizacao";
                } elseif ($dataAtualizacaoCol !== null) {
                    $selectData = "DATE_FORMAT(p.{$dataAtualizacaoCol}, '%d/%m/%Y') AS ultima_atualizacao";
                } elseif ($dataCriacaoCol !== null) {
                    $selectData = "DATE_FORMAT(p.{$dataCriacaoCol}, '%d/%m/%Y') AS ultima_atualizacao";
                } else {
                    $selectData = 'NULL AS ultima_atualizacao';
                }

                $orderBy = $dataAtualizacaoCol !== null ? 'p.' . $dataAtualizacaoCol . ' DESC, p.id_pedido DESC' : 'p.id_pedido DESC';

                $stmt = $pdo->prepare("
                    SELECT
                        p.id_pedido,
                        {$selectNumero},
                        {$selectStatus},
                        {$selectData}
                    FROM Pedido p
                    WHERE p.id_user = :id_user
                    ORDER BY {$orderBy}
                    LIMIT 20
                ");
                $stmt->execute(['id_user' => $id_usuario]);
                $pedidosDb = $stmt->fetchAll();

                $labelMap = [
                    'pendente' => 'Enviado',
                    'enviado' => 'Enviado',
                    'em-separacao' => 'Em separação',
                    'pronto-para-retirada' => 'Pronto para retirada',
                    'em-andamento' => 'Em andamento',
                    'em-atraso' => 'Em atraso',
                    'renovacao-solicitada' => 'Renovação solicitada',
                    'finalizado' => 'Finalizado',
                    'negado' => 'Cancelado',
                    'cancelado' => 'Cancelado',
                ];

                foreach ($pedidosDb as $row) {
                    $status = (string) ($row['status_pedido'] ?? 'pendente');
                    $statusClass = in_array($status, ['em-andamento', 'finalizado', 'cancelado'], true)
                        ? $status
                        : ($status === 'negado' ? 'cancelado' : 'em-andamento');

                    $pedidos[] = [
                        'id_pedido' => (int) $row['id_pedido'],
                        'numero' => $row['numero_pedido'] ?? $row['id_pedido'],
                        'numero_pedido' => $row['numero_pedido'] ?? $row['id_pedido'],
                        'ultima_atualizacao' => $row['ultima_atualizacao'] ?? '—',
                        'status' => $statusClass,
                        'status_pedido' => $status,
                        'status_label' => $labelMap[$status] ?? ucfirst(str_replace('-', ' ', $status)),
                    ];
                }
            }
        }
    }
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
    $foto_perfil = $_SESSION['auth_user']['foto_perfil'] ?? null;
}

$usuario_role = $alunoPreBloqueado ? 'Aluno | Bloqueado para pedidos' : $usuario_role;
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
        .main {
            padding: 40px 40px 60px;
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

        .btn-back svg {
            width: 20px;
            height: 20px;
        }

        /* ── Card de perfil ───────────────────── */
        .profile-card {
            background-color: var(--bg-superficie);
            border: 1px solid var(--borda-padrao);
            border-radius: 20px;
            padding: 28px 36px;
            display: flex;
            align-items: center;
            gap: 32px;
            margin-bottom: 48px;
        }

        .profile-card.prebloqueado {
            background-color: var(--bg-erro-card);
            border-color: var(--borda-erro);
        }

        .profile-card.prebloqueado .profile-role {
            color: var(--texto-erro);
            font-weight: 700;
        }

        .profile-avatar-wrap {
            position: relative;
            width: 110px;
            height: 110px;
            flex-shrink: 0;
            cursor: pointer;
        }

        .profile-avatar {
            width: 110px;
            height: 110px;
            border-radius: 12px;
            background-color: var(--bg-controle);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: filter 0.2s;
        }

        .profile-avatar-wrap:hover .profile-avatar {
            filter: brightness(0.55);
        }

        .profile-avatar svg {
            width: 64px;
            height: 64px;
            color: var(--texto-auxiliar);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-overlay {
            position: absolute;
            inset: 0;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 600;
            text-align: center;
        }

        .profile-avatar-wrap:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay svg {
            width: 24px;
            height: 24px;
        }

        .avatar-hint {
            font-size: 0.75rem;
            color: var(--texto-auxiliar);
            margin-top: 6px;
            text-align: center;
            line-height: 1.3;
        }

        /* Toast de feedback */
        .toast {
            position: fixed;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background-color: var(--bg-superficie);
            border: 1px solid var(--borda-controle);
            color: var(--texto-principal);
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.9rem;
            box-shadow: var(--sombra-toast);
            opacity: 0;
            transition: opacity 0.25s, transform 0.25s;
            z-index: 9999;
            pointer-events: none;
            white-space: nowrap;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .toast.erro { border-color: var(--borda-erro); color: var(--texto-erro); }
        .toast.ok   { border-color: var(--borda-sucesso); color: var(--texto-sucesso); }

        .profile-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .profile-role {
            font-size: 0.9rem;
            color: var(--texto-secundario);
        }

        .profile-name {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--texto-principal);
            text-align: right;
        }

        /* Toggle tema */
        .theme-toggle {
            display: flex;
            align-items: center;
            background-color: var(--bg-controle);
            border: 1px solid var(--borda-controle);
            border-radius: 50px;
            overflow: hidden;
            margin-top: 8px;
        }

        .theme-toggle button {
            padding: 5px 18px;
            background: none;
            border: none;
            color: var(--texto-secundario);
            font-size: 0.8rem;
            cursor: pointer;
            border-radius: 50px;
            transition: background-color 0.15s, color 0.15s;
        }

        .theme-toggle button.active {
            background-color: var(--bg-acao-primaria);
            color: var(--texto-acao-primaria);
            font-weight: 600;
        }

        /* ── Histórico de pedidos ─────────────── */
        .section-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--texto-principal);
            margin-bottom: 20px;
        }

        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .order-card {
            background-color: var(--bg-superficie);
            border: 1px solid var(--borda-padrao);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .order-info {
            flex: 1;
        }

        .order-number {
            font-size: 1rem;
            font-weight: 700;
            color: var(--texto-principal);
            margin-bottom: 4px;
        }

        .order-date {
            font-size: 0.82rem;
            color: var(--texto-suave);
        }

        .order-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-badge {
            padding: 8px 24px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: default;
            white-space: nowrap;
        }

        .status-badge.em-andamento {
            background-color: var(--status-andamento-bg);
            color: var(--status-andamento-texto);
        }

        .status-badge.finalizado {
            background-color: var(--status-finalizado-bg);
            color: var(--status-finalizado-texto);
        }

        .status-badge.cancelado {
            background-color: var(--status-cancelado-bg);
            color: var(--status-cancelado-texto);
        }

        .btn-details {
            width: 42px;
            height: 42px;
            border-radius: 10px;
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

        .btn-details:hover { background-color: var(--bg-controle-hover); }

        .btn-details svg {
            width: 20px;
            height: 20px;
        }
    </style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="<?= htmlspecialchars($painelPerfil) ?>" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <form class="nav-search" action="<?= htmlspecialchars($painelPerfil) ?>" method="GET">
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

        <?php if ($ehEstudante): ?>
        <a href="<?= htmlspecialchars($basePath) ?>/pages_aluno/carrinho.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            Carrinho
        </a>

        <a href="<?= htmlspecialchars($basePath) ?>/pages_aluno/verpedidos.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            Pedidos
        </a>

        <?php endif; ?>

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
                <?php require __DIR__ . '/includes/menu_perfil.php'; ?>
                <?php if ($notificacoesPerfil !== null): ?>
                <a href="<?= htmlspecialchars($notificacoesPerfil) ?>" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    Notificações
                </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($basePath) ?>/logout.php" role="menuitem">
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
    /* Dropdown do usuário */
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
        <h1 class="page-title">Página do usuário</h1>
        <div style="display:flex;gap:10px;align-items:center;">
            <button class="btn-edit-perfil" onclick="openPerfilModal()" title="Editar meu perfil" aria-label="Editar perfil">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
            </button>
            <a href="<?= htmlspecialchars($painelPerfil) ?>" class="btn-back" aria-label="Voltar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </a>
        </div>
    </div>

    <!-- Input de upload oculto -->
    <input type="file" id="inputFoto" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <!-- Card de perfil -->
    <div class="profile-card <?= $alunoPreBloqueado ? 'prebloqueado' : '' ?>">
        <div class="profile-avatar-wrap" onclick="document.getElementById('inputFoto').click()" title="Trocar foto">
            <div class="profile-avatar" id="avatarBox">
                <?php if ($foto_perfil): ?>
                    <img src="<?= htmlspecialchars($foto_perfil) ?>" alt="Foto de perfil" id="avatarImg">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" id="avatarIcon">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div class="avatar-overlay">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Trocar foto
            </div>
        </div>
        <div class="profile-info">
            <span class="profile-role"><?= htmlspecialchars($usuario_role) ?></span>
            <span class="profile-name" id="perfilNomeResumo"><?= htmlspecialchars($usuario_nome) ?></span>

            <div class="theme-toggle" id="themeToggle">
                <button type="button" id="btnClaro" onclick="setTheme('claro')" aria-pressed="false">Claro</button>
                <button type="button" id="btnEscuro" onclick="setTheme('escuro')" class="active" aria-pressed="true">Escuro</button>
            </div>
        </div>
    </div>

    <?php if ($ehEstudante): ?>
    <!-- Histórico de pedidos -->
    <h2 class="section-title">Histórico de pedidos</h2>

    <?php if (!$db_ok): ?>
    <div style="background-color: var(--bg-superficie); border: 1px solid var(--borda-erro-sutil); border-radius: 16px; padding: 40px; text-align: center; color: var(--texto-secundario);">
        <h2 style="color: var(--cor-erro); margin-bottom: 8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar seus pedidos. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($pedidos)): ?>
    <div style="background-color: var(--bg-superficie); border: 1px solid var(--borda-padrao); border-radius: 16px; padding: 40px; text-align: center; color: var(--texto-suave);">
        <p>Você ainda não possui pedidos realizados.</p>
    </div>
    <?php else: ?>
    <div class="orders-list">
        <?php foreach ($pedidos as $pedido): ?>
        <div class="order-card">
            <div class="order-info">
                <p class="order-number">Pedido #<?= htmlspecialchars($pedido['numero'] ?? $pedido['numero_pedido'] ?? '') ?></p>
                <p class="order-date">Data da última atualização: <?= htmlspecialchars($pedido['ultima_atualizacao'] ?? $pedido['data_ultima_atualizacao'] ?? '') ?></p>
            </div>
            <div class="order-actions">
                <span class="status-badge <?= htmlspecialchars($pedido['status'] ?? $pedido['status_pedido'] ?? '') ?>">
                    <?= htmlspecialchars($pedido['status_label'] ?? '') ?>
                </span>
                <a href="<?= htmlspecialchars($basePath) ?>/pages_aluno/pedido.php?id=<?= (int) ($pedido['id_pedido'] ?? 0) ?>" class="btn-details" aria-label="Ver detalhes do pedido">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8" y1="6" x2="21" y2="6"/>
                        <line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/>
                        <line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</main>

<!-- ══════ MODAL: Editar perfil ══════ -->
<div class="perfil-overlay" id="perfilModal">
    <form class="perfil-modal" id="perfilForm" role="dialog" aria-modal="true" aria-labelledby="perfilModalTitulo" onsubmit="event.preventDefault(); savePerfilModal();">
        <div class="perfil-modal-header">
            <div>
                <h2 id="perfilModalTitulo">Editar meu perfil</h2>
                <p>Suas informações pessoais, em um só lugar.</p>
            </div>
            <button type="button" class="perfil-close" onclick="closePerfilModal()" aria-label="Fechar edição de perfil">×</button>
        </div>
        <input type="hidden" id="csrfPerfil" name="csrf_perfil" value="<?= htmlspecialchars($csrfPerfil) ?>">
        <input type="file" id="perfilFotoInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="onPerfilFoto(this)">

        <div class="perfil-foto-row">
            <div class="perfil-avatar-preview" id="perfilAvatarPreview">
                <?php if ($foto_perfil): ?>
                    <img src="<?= htmlspecialchars($foto_perfil) ?>" alt="Foto de perfil atual">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div class="perfil-foto-controls">
                <span class="perfil-foto-title">Foto de perfil</span>
                <button type="button" class="perfil-foto-btn" onclick="document.getElementById('perfilFotoInput').click()">Trocar foto</button>
                <p class="perfil-help">JPG, PNG, WEBP ou GIF. Até 5 MB.</p>
            </div>
        </div>

        <div class="perfil-fields">
            <div class="perfil-field-full">
                <label for="perfilNome">Nome completo</label>
                <input type="text" id="perfilNome" name="nome" value="<?= htmlspecialchars($usuario_nome) ?>" maxlength="150" autocomplete="name" required placeholder="Seu nome completo">
            </div>
            <div>
                <label for="perfilEmail">E-mail</label>
                <input type="email" id="perfilEmail" name="email" value="<?= htmlspecialchars($perfil_email) ?>" maxlength="150" autocomplete="email" placeholder="seu@email.com" <?= $perfil_google ? 'readonly aria-describedby="perfilGoogleHint"' : '' ?>>
                <?php if ($perfil_google): ?><p class="perfil-help" id="perfilGoogleHint">Preenchido pela sua conta Google.</p><?php endif; ?>
            </div>
            <div>
                <label for="perfilMatricula">Matrícula</label>
                <input type="text" id="perfilMatricula" name="matricula" value="<?= htmlspecialchars($perfil_matricula) ?>" maxlength="50" placeholder="Sua matrícula">
            </div>
            <div class="perfil-field-full">
                <label for="perfilDescricao">Descrição</label>
                <textarea id="perfilDescricao" name="descricao" rows="3" data-limite="<?= PERFIL_DESCRICAO_LIMITE ?>" aria-describedby="perfilDescricaoContador" placeholder="Conte um pouco sobre você..."><?= htmlspecialchars($perfil_descricao) ?></textarea>
                <div class="perfil-description-meta">
                    <span class="perfil-help">Uma breve apresentação sobre você.</span>
                    <span class="perfil-counter" id="perfilDescricaoContador" aria-live="polite"><?= mb_strlen($perfil_descricao, 'UTF-8') ?>/<?= PERFIL_DESCRICAO_LIMITE ?> caracteres</span>
                </div>
            </div>
        </div>

        <div id="perfilNotice" class="perfil-notice" role="status"></div>
        <div class="perfil-modal-actions">
            <button type="button" class="perfil-btn-cancel" onclick="closePerfilModal()">Cancelar</button>
            <button type="submit" class="perfil-btn-save" id="perfilBtnSave">Salvar alterações</button>
        </div>
    </form>
</div>

<style>
    .btn-edit-perfil {
        width: 44px; height: 44px;
        border-radius: 50%;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }
    .btn-edit-perfil:hover { background-color: var(--bg-controle-hover); }
    .btn-edit-perfil svg { width: 20px; height: 20px; }

    .perfil-foto-btn, .perfil-btn-cancel, .perfil-close {
        background: var(--bg-controle); border: 1px solid var(--borda-controle); color: var(--texto-principal);
        cursor: pointer; font: inherit; transition: background 0.15s;
    }
    .perfil-foto-btn:hover, .perfil-btn-cancel:hover, .perfil-close:hover { background: var(--bg-controle-hover); }
    .perfil-overlay { display: none; position: fixed; inset: 0; background: var(--overlay-modal); z-index: 200; align-items: center; justify-content: center; padding: 16px; }
    .perfil-overlay.open { display: flex; }
    .perfil-modal { background: var(--bg-modal); border: 1px solid var(--borda-card); border-radius: 20px; padding: 28px; width: 100%; max-width: 660px; display: flex; flex-direction: column; gap: 22px; max-height: calc(100dvh - 32px); overflow-y: auto; box-shadow: var(--sombra-modal); }
    .perfil-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
    .perfil-modal h2 { font-size: 1.5rem; font-weight: 800; color: var(--texto-principal); margin: 0 0 6px; }
    .perfil-modal-header p { font-size: .875rem; line-height: 1.5; color: var(--texto-secundario); }
    .perfil-close { width: 32px; height: 32px; border-radius: 50%; font-size: 22px; line-height: 1; flex-shrink: 0; }
    .perfil-foto-row { display: flex; align-items: center; gap: 18px; padding-bottom: 22px; border-bottom: 1px solid var(--borda-sutil); }
    .perfil-avatar-preview { width: 80px; height: 80px; border-radius: 16px; background: var(--bg-controle); border: 1px solid var(--borda-controle); overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .perfil-avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
    .perfil-avatar-preview svg { width: 38px; height: 38px; color: var(--texto-placeholder); }
    .perfil-foto-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; }
    .perfil-foto-title { font-size: .9rem; font-weight: 600; }
    .perfil-foto-controls .perfil-help { width: 100%; }
    .perfil-foto-btn { border-radius: 9px; padding: 8px 14px; font-size: .8rem; font-weight: 600; }
    .perfil-fields { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 20px 16px; }
    .perfil-fields > div { min-width: 0; }
    .perfil-field-full { grid-column: 1 / -1; }
    .perfil-modal label { display: block; font-size: .85rem; font-weight: 600; color: var(--texto-secundario); margin-bottom: 8px; }
    .perfil-modal input:not([type="hidden"]):not([type="file"]), .perfil-modal textarea {
        width: 100%; background: var(--bg-campo-interno); border: 1.5px solid var(--borda-card); border-radius: 10px;
        color: var(--texto-principal); font: .95rem/1.5 'Segoe UI', Arial, sans-serif; outline: none; padding: 10px 13px; transition: border-color .2s;
    }
    .perfil-modal textarea { resize: none; min-height: 100px; }
    .perfil-modal input::placeholder, .perfil-modal textarea::placeholder { color: var(--texto-placeholder); }
    .perfil-modal input:focus, .perfil-modal textarea:focus { border-color: var(--borda-foco); }
    .perfil-modal input[readonly] { color: var(--texto-secundario); background: var(--bg-controle); }
    .perfil-help, .perfil-counter { color: var(--texto-secundario); font-size: .75rem; line-height: 1.5; }
    .perfil-fields p.perfil-help { margin-top: 6px; }
    .perfil-description-meta { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px; }
    .perfil-counter { margin-left: auto; font-variant-numeric: tabular-nums; }
    .perfil-counter.over-limit { color: var(--cor-erro); }
    .perfil-modal-actions { display: flex; gap: 12px; justify-content: flex-end; border-top: 1px solid var(--borda-sutil); padding-top: 20px; }
    .perfil-btn-cancel, .perfil-btn-save { padding: 11px 20px; border-radius: 10px; font-size: .875rem; font-weight: 600; }
    .perfil-btn-save { background: var(--bg-acao-primaria); color: var(--texto-acao-primaria); border: 1px solid transparent; cursor: pointer; }
    .perfil-btn-save:hover { background: var(--bg-acao-primaria-hover); }
    .perfil-btn-save:disabled { opacity: .5; cursor: not-allowed; }
    .perfil-notice { padding: 10px 14px; border-radius: 8px; font-size: .85rem; display: none; }
    .perfil-notice.ok { background: var(--bg-sucesso-sutil); border: 1px solid var(--borda-sucesso); color: var(--cor-sucesso); display: block; }
    .perfil-notice.erro { background: var(--bg-erro-sutil); border: 1px solid var(--borda-erro); color: var(--cor-erro-claro); display: block; }
    @media (max-width: 640px) {
        .perfil-fields { grid-template-columns: minmax(0, 1fr); }
        .perfil-modal { padding: 22px; gap: 20px; }
        .perfil-modal h2 { font-size: 1.3rem; }
        .perfil-modal-actions { gap: 10px; }
        .perfil-btn-cancel, .perfil-btn-save { flex: 1; padding: 11px 12px; }
    }

</style>

<script>
    let toastTimer;
    let focoAntesPerfil;
    let fotoSalva = <?= json_encode($foto_perfil, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let perfilSalvo = <?= json_encode(['nome' => $usuario_nome, 'email' => $perfil_email, 'matricula' => $perfil_matricula, 'descricao' => $perfil_descricao], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const descricaoLimite = <?= PERFIL_DESCRICAO_LIMITE ?>;
    const descricaoInput = document.getElementById('perfilDescricao');

    function atualizarContadorDescricao(limitar = false) {
        const caracteres = Array.from(descricaoInput.value);
        if (limitar && caracteres.length > descricaoLimite) {
            descricaoInput.value = caracteres.slice(0, descricaoLimite).join('');
        }
        const tamanho = Array.from(descricaoInput.value).length;
        const contador = document.getElementById('perfilDescricaoContador');
        contador.textContent = tamanho + '/' + descricaoLimite + ' caracteres';
        contador.classList.toggle('over-limit', tamanho > descricaoLimite);
    }
    descricaoInput.addEventListener('input', () => atualizarContadorDescricao(true));

    function showToast(msg, tipo) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast show ' + tipo;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.className = 'toast'; }, 3500);
    }

    function mostrarFotoPreview(url) {
        const preview = document.getElementById('perfilAvatarPreview');
        if (!url) return;
        const img = document.createElement('img');
        img.src = url;
        img.alt = 'Foto de perfil';
        preview.replaceChildren(img);
    }

    function atualizarFotoSalva(url) {
        fotoSalva = url;
        const box = document.getElementById('avatarBox');
        const img = document.createElement('img');
        img.id = 'avatarImg';
        img.alt = 'Foto de perfil';
        img.src = url;
        box.replaceChildren(img);
        mostrarFotoPreview(url);
    }

    document.getElementById('inputFoto').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('foto', file);
        formData.append('csrf_perfil', document.getElementById('csrfPerfil').value);
        try {
            const res = await fetch(<?= json_encode($basePath . '/api/perfil_foto.php') ?>, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                atualizarFotoSalva(data.url);
                showToast('Foto atualizada com sucesso!', 'ok');
            } else showToast(data.erro || 'Erro ao enviar foto.', 'erro');
        } catch { showToast('Erro de conexão ao enviar foto.', 'erro'); }
        this.value = '';
    });

    function openPerfilModal() {
        focoAntesPerfil = document.activeElement;
        document.getElementById('perfilForm').reset();
        document.getElementById('perfilNome').value = perfilSalvo.nome || '';
        document.getElementById('perfilEmail').value = perfilSalvo.email || '';
        document.getElementById('perfilMatricula').value = perfilSalvo.matricula || '';
        descricaoInput.value = perfilSalvo.descricao || '';
        mostrarFotoPreview(fotoSalva);
        // Descarta a prévia de um arquivo cancelado quando ainda não há foto salva.
        if (!fotoSalva) document.getElementById('perfilAvatarPreview').replaceChildren(document.querySelector('#avatarBox svg').cloneNode(true));
        document.getElementById('perfilNotice').className = 'perfil-notice';
        atualizarContadorDescricao();
        document.getElementById('perfilModal').classList.add('open');
        document.querySelector('main').inert = true;
        document.querySelector('nav').inert = true;
        document.body.style.overflow = 'hidden';
        document.getElementById('perfilNome').focus();
    }
    function closePerfilModal() {
        if (document.getElementById('perfilBtnSave').disabled) return;
        document.getElementById('perfilModal').classList.remove('open');
        document.querySelector('main').inert = false;
        document.querySelector('nav').inert = false;
        document.body.style.overflow = '';
        focoAntesPerfil?.focus();
    }
    document.getElementById('perfilModal').addEventListener('click', function (e) {
        if (e.target === this) closePerfilModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePerfilModal();
    });

    function onPerfilFoto(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            input.value = '';
            mostrarFotoPreview(fotoSalva);
            showToast('Use uma imagem JPG, PNG, WEBP ou GIF de até 5 MB.', 'erro');
            return;
        }
        const reader = new FileReader();
        reader.onload = e => mostrarFotoPreview(e.target.result);
        reader.readAsDataURL(file);
    }

    function atualizarResumoPerfil(perfil) {
        perfilSalvo = perfil;
        document.getElementById('perfilNomeResumo').textContent = perfil.nome;
        document.querySelector('.greeting strong').textContent = perfil.nome + '!';
    }

    async function savePerfilModal() {
        const btn = document.getElementById('perfilBtnSave');
        const notice = document.getElementById('perfilNotice');
        if (btn.disabled) return;
        if (!document.getElementById('perfilForm').reportValidity()) return;
        if (Array.from(descricaoInput.value).length > descricaoLimite) {
            notice.className = 'perfil-notice erro';
            notice.textContent = 'A descrição deve ter até ' + descricaoLimite + ' caracteres.';
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Salvando…';
        notice.className = 'perfil-notice';
        const fd = new FormData(document.getElementById('perfilForm'));
        fd.set('csrf_perfil', document.getElementById('csrfPerfil').value);
        const fotoInput = document.getElementById('perfilFotoInput');
        if (fotoInput.files?.[0]) fd.set('foto', fotoInput.files[0]);
        try {
            const res = await fetch(<?= json_encode($basePath . '/api/perfil_atualizar.php') ?>, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                atualizarResumoPerfil(data.perfil);
                if (data.foto) atualizarFotoSalva(data.foto);
                btn.disabled = false;
                closePerfilModal();
                showToast('Perfil atualizado com sucesso!', 'ok');
            } else {
                notice.className = 'perfil-notice erro';
                notice.textContent = data.erro || 'Erro ao salvar.';
            }
        } catch {
            notice.className = 'perfil-notice erro';
            notice.textContent = 'Falha de comunicação. Tente novamente.';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar alterações';
        }
    }
</script>

</body>
</html>
