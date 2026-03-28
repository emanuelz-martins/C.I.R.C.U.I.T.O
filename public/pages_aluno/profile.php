<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';

$page_title = 'Perfil';
require_once '../includes/header.php';

/* ── Dados do usuário: carregados da sessão ── */
$usuario_role = ucfirst($_SESSION['auth_user']['perfil'] ?? 'Usuário');
$alunoPreBloqueado = false;

/* ── Pedidos: serão carregados do BD ── */
$pedidos = [];
$db_ok = false;

$foto_perfil      = null;
$perfil_email     = '';
$perfil_turma     = '';
$perfil_descricao = '';
$turmas_lista     = [];

try {
    $pdo = db();
    $id_usuario = $_SESSION['auth_user']['id'] ?? null;

    /* Carrega turmas disponíveis */
    try {
        $turmas_lista = $pdo->query('SELECT id_turma, nome FROM Turma ORDER BY nome')->fetchAll();
    } catch (Throwable) {}

    if ($id_usuario) {
        $statusPreBloqueio = aluno_pre_bloqueio_status($pdo, (int) $id_usuario);
        $alunoPreBloqueado = ($statusPreBloqueio['pre_bloqueado'] ?? false) === true;

        $stmt = $pdo->prepare('SELECT foto_perfil, email, turma, descricao FROM Usuario WHERE id_user = :id');
        $stmt->execute(['id' => $id_usuario]);
        $row_up           = $stmt->fetch();
        $foto_perfil      = $row_up ? ($row_up['foto_perfil'] ?: null) : null;
        $perfil_email     = $row_up ? (string)($row_up['email']     ?? '') : '';
        $perfil_turma     = $row_up ? (string)($row_up['turma']     ?? '') : '';
        $perfil_descricao = $row_up ? (string)($row_up['descricao'] ?? '') : '';

        /* Sincroniza sessão */
        $_SESSION['auth_user']['foto_perfil'] = $foto_perfil;

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
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
    $foto_perfil = $_SESSION['auth_user']['foto_perfil'] ?? null;
}

$usuario_role = $alunoPreBloqueado ? 'Estudante | Pré-Bloqueado' : $usuario_role;
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

        /* ── Card de perfil ───────────────────── */
        .profile-card {
            background-color: #1c1c1c;
            border: 1px solid #2a2a2a;
            border-radius: 20px;
            padding: 28px 36px;
            display: flex;
            align-items: center;
            gap: 32px;
            margin-bottom: 48px;
        }

        .profile-card.prebloqueado {
            background-color: #2a1212;
            border-color: #7f1d1d;
        }

        .profile-card.prebloqueado .profile-role {
            color: #fca5a5;
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
            background-color: #2e2e2e;
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
            color: #666;
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
            color: #666;
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
            background-color: #1c1c1c;
            border: 1px solid #3a3a3a;
            color: #fff;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.9rem;
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

        .toast.erro { border-color: #b91c1c; color: #fca5a5; }
        .toast.ok   { border-color: #1a7a34; color: #86efac; }

        .profile-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .profile-role {
            font-size: 0.9rem;
            color: #aaa;
        }

        .profile-name {
            font-size: 2.2rem;
            font-weight: 800;
            color: #ffffff;
            text-align: right;
        }

        /* Toggle tema */
        .theme-toggle {
            display: flex;
            align-items: center;
            background-color: #2a2a2a;
            border: 1px solid #3a3a3a;
            border-radius: 50px;
            overflow: hidden;
            margin-top: 8px;
        }

        .theme-toggle button {
            padding: 5px 18px;
            background: none;
            border: none;
            color: #aaa;
            font-size: 0.8rem;
            cursor: pointer;
            border-radius: 50px;
            transition: background-color 0.15s, color 0.15s;
        }

        .theme-toggle button.active {
            background-color: #ffffff;
            color: #141414;
            font-weight: 600;
        }

        /* ── Histórico de pedidos ─────────────── */
        .section-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 20px;
        }

        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .order-card {
            background-color: #1c1c1c;
            border: 1px solid #2a2a2a;
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
            color: #ffffff;
            margin-bottom: 4px;
        }

        .order-date {
            font-size: 0.82rem;
            color: #888;
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
            background-color: #1a56db;
            color: #ffffff;
        }

        .status-badge.finalizado {
            background-color: #1a7a34;
            color: #ffffff;
        }

        .status-badge.cancelado {
            background-color: #b91c1c;
            color: #ffffff;
        }

        .btn-details {
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
            text-decoration: none;
            transition: background-color 0.15s;
            flex-shrink: 0;
        }

        .btn-details:hover { background-color: #333; }

        .btn-details svg {
            width: 20px;
            height: 20px;
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
            <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
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
            <span class="profile-name"><?= htmlspecialchars($usuario_nome) ?></span>

            <div class="theme-toggle" id="themeToggle">
                <button id="btnClaro" onclick="setTheme('claro')">Claro</button>
                <button id="btnEscuro" onclick="setTheme('escuro')" class="active">Escuro</button>
            </div>
        </div>
    </div>

    <!-- Histórico de pedidos -->
    <h2 class="section-title">Histórico de pedidos</h2>

    <?php if (!$db_ok): ?>
    <div style="background-color: #1c1c1c; border: 1px solid #3a1a1a; border-radius: 16px; padding: 40px; text-align: center; color: #aaa;">
        <h2 style="color: #ef4444; margin-bottom: 8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar seus pedidos. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($pedidos)): ?>
    <div style="background-color: #1c1c1c; border: 1px solid #2a2a2a; border-radius: 16px; padding: 40px; text-align: center; color: #888;">
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
                <a href="./pedido.php?id=<?= (int) ($pedido['id_pedido'] ?? 0) ?>" class="btn-details" aria-label="Ver detalhes do pedido">
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

</main>

<!-- ══════ MODAL: Editar perfil ══════ -->
<div class="perfil-overlay" id="perfilModal">
    <div class="perfil-modal" role="dialog" aria-modal="true">
        <h2>Editar meu perfil</h2>

        <input type="file" id="perfilFotoInput" accept="image/*" style="display:none" onchange="onPerfilFoto(this)">

        <div>
            <label>Foto de perfil</label>
            <div class="perfil-foto-row">
                <div class="perfil-avatar-preview" id="perfilAvatarPreview">
                    <?php if ($foto_perfil): ?>
                        <img src="<?= htmlspecialchars($foto_perfil) ?>" alt="">
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <button type="button" class="perfil-foto-btn" onclick="document.getElementById('perfilFotoInput').click()">
                    Trocar foto
                </button>
            </div>
        </div>

        <div>
            <label for="perfilEmail">E-mail</label>
            <input type="email" id="perfilEmail" value="<?= htmlspecialchars($perfil_email) ?>" placeholder="seu@email.com">
        </div>

        <div>
            <label for="perfilTurma">Turma</label>
            <?php if (!empty($turmas_lista)): ?>
            <select id="perfilTurma">
                <option value="">— Nenhuma —</option>
                <?php foreach ($turmas_lista as $t): ?>
                <option value="<?= htmlspecialchars($t['nome']) ?>"
                    <?= ($perfil_turma === $t['nome']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" id="perfilTurma" value="<?= htmlspecialchars($perfil_turma) ?>" placeholder="Ex: Turma 11A">
            <?php endif; ?>
        </div>

        <div>
            <label for="perfilDescricao">Descrição</label>
            <textarea id="perfilDescricao" placeholder="Conte um pouco sobre você..."><?= htmlspecialchars($perfil_descricao) ?></textarea>
        </div>

        <div id="perfilNotice" class="perfil-notice"></div>

        <div class="perfil-modal-actions">
            <button class="perfil-btn-cancel" onclick="closePerfilModal()">Cancelar</button>
            <button class="perfil-btn-save" id="perfilBtnSave" onclick="savePerfilModal()">Salvar</button>
        </div>
    </div>
</div>

<style>
    .btn-edit-perfil {
        width: 44px; height: 44px;
        border-radius: 50%;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }
    .btn-edit-perfil:hover { background-color: #333; }
    .btn-edit-perfil svg { width: 20px; height: 20px; }

    .perfil-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.75); z-index: 200;
        align-items: center; justify-content: center;
    }
    .perfil-overlay.open { display: flex; }
    .perfil-modal {
        background: #1e1e1e; border: 1px solid #2e2e2e;
        border-radius: 20px; padding: 36px;
        width: 100%; max-width: 480px;
        display: flex; flex-direction: column; gap: 18px;
        max-height: 90vh; overflow-y: auto;
    }
    .perfil-modal h2 { font-size: 1.4rem; font-weight: 800; color: #fff; margin: 0; }
    .perfil-modal label { display: block; font-size: 0.85rem; font-weight: 600; color: #aaa; margin-bottom: 6px; }
    .perfil-modal input, .perfil-modal textarea, .perfil-modal select {
        width: 100%; background: #141414; border: 1.5px solid #2e2e2e;
        border-radius: 10px; color: #fff; font-size: 0.95rem; font-family: inherit;
        outline: none; padding: 11px 14px; transition: border-color 0.2s; resize: vertical;
    }
    .perfil-modal select option { background: #1e1e1e; }
    .perfil-modal input::placeholder, .perfil-modal textarea::placeholder { color: #444; }
    .perfil-modal input:focus, .perfil-modal textarea:focus, .perfil-modal select:focus { border-color: #555; }
    .perfil-modal textarea { min-height: 80px; }
    .perfil-foto-row { display: flex; align-items: center; gap: 14px; }
    .perfil-avatar-preview {
        width: 56px; height: 56px; border-radius: 12px;
        background: #2a2a2a; border: 1px solid #3a3a3a;
        overflow: hidden; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .perfil-avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
    .perfil-avatar-preview svg { width: 28px; height: 28px; color: #555; }
    .perfil-foto-btn {
        padding: 9px 16px; background: #2a2a2a; border: 1px solid #3a3a3a;
        border-radius: 9px; color: #fff; font-size: 0.85rem; cursor: pointer;
        transition: background 0.15s;
    }
    .perfil-foto-btn:hover { background: #333; }
    .perfil-modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
    .perfil-btn-cancel {
        padding: 11px 22px; background: #2a2a2a; border: 1px solid #3a3a3a;
        border-radius: 10px; color: #fff; font-size: 0.9rem; font-weight: 600; cursor: pointer;
        transition: background 0.15s;
    }
    .perfil-btn-cancel:hover { background: #333; }
    .perfil-btn-save {
        padding: 11px 22px; background: #fff; border: none;
        border-radius: 10px; color: #000; font-size: 0.9rem; font-weight: 700; cursor: pointer;
        transition: background 0.15s;
    }
    .perfil-btn-save:hover { background: #e5e5e5; }
    .perfil-btn-save:disabled { opacity: 0.5; cursor: not-allowed; }
    .perfil-notice { padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; display: none; }
    .perfil-notice.ok   { background: #0d2b16; border: 1px solid #166534; color: #4ade80; display: block; }
    .perfil-notice.erro { background: #2b0d0d; border: 1px solid #7f1d1d; color: #f87171; display: block; }
</style>

<script>
    /* ── Toggle de tema ── */
    function setTheme(tema) {
        const btnClaro  = document.getElementById('btnClaro');
        const btnEscuro = document.getElementById('btnEscuro');

        if (tema === 'claro') {
            btnClaro.classList.add('active');
            btnEscuro.classList.remove('active');
            document.body.style.backgroundColor = '#f5f5f5';
            document.body.style.color = '#141414';
        } else {
            btnEscuro.classList.add('active');
            btnClaro.classList.remove('active');
            document.body.style.backgroundColor = '#141414';
            document.body.style.color = '#ffffff';
        }

        localStorage.setItem('tema', tema);
    }

    /* Restaura tema salvo */
    (function () {
        const temaSalvo = localStorage.getItem('tema');
        if (temaSalvo === 'claro') setTheme('claro');
    })();

    /* ── Upload de foto de perfil ── */
    let toastTimer;

    function showToast(msg, tipo) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast show ' + tipo;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.className = 'toast'; }, 3500);
    }

    document.getElementById('inputFoto').addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('foto', file);

        fetch('./upload_foto.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const box = document.getElementById('avatarBox');

                    /* Remove ícone SVG padrão se existir */
                    const icon = document.getElementById('avatarIcon');
                    if (icon) icon.remove();

                    /* Atualiza ou cria a tag <img> */
                    let img = document.getElementById('avatarImg');
                    if (!img) {
                        img = document.createElement('img');
                        img.id = 'avatarImg';
                        img.alt = 'Foto de perfil';
                        box.prepend(img);
                    }
                    img.src = data.url + '?t=' + Date.now();
                    showToast('Foto atualizada com sucesso!', 'ok');
                } else {
                    showToast(data.erro || 'Erro ao enviar foto.', 'erro');
                }
            })
            .catch(() => showToast('Erro de conexão ao enviar foto.', 'erro'));

        /* Limpa o input para permitir reenvio do mesmo arquivo */
        this.value = '';
    });

    /* ── Modal editar perfil ── */
    function openPerfilModal()  { document.getElementById('perfilModal').classList.add('open'); }
    function closePerfilModal() { document.getElementById('perfilModal').classList.remove('open'); }

    document.getElementById('perfilModal').addEventListener('click', function (e) {
        if (e.target === this) closePerfilModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePerfilModal();
    });

    function onPerfilFoto(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const preview = document.getElementById('perfilAvatarPreview');
            preview.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover">`;
        };
        reader.readAsDataURL(input.files[0]);
    }

    async function savePerfilModal() {
        const btn    = document.getElementById('perfilBtnSave');
        const notice = document.getElementById('perfilNotice');
        btn.disabled    = true;
        btn.textContent = 'Salvando…';
        notice.className   = 'perfil-notice';
        notice.textContent = '';

        const fd = new FormData();
        fd.append('email',     document.getElementById('perfilEmail').value.trim());
        fd.append('turma',     document.getElementById('perfilTurma').value.trim());
        fd.append('descricao', document.getElementById('perfilDescricao').value.trim());

        const fotoInput = document.getElementById('perfilFotoInput');
        if (fotoInput.files && fotoInput.files[0]) {
            fd.append('foto', fotoInput.files[0]);
        }

        try {
            const res  = await fetch('./update_perfil.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                notice.className   = 'perfil-notice ok';
                notice.textContent = 'Perfil atualizado com sucesso!';
                if (data.foto) {
                    /* Atualiza avatar no card de perfil também */
                    const box = document.getElementById('avatarBox');
                    if (box) {
                        const icon = document.getElementById('avatarIcon');
                        if (icon) icon.remove();
                        let img = document.getElementById('avatarImg');
                        if (!img) { img = document.createElement('img'); img.id='avatarImg'; img.alt='Foto de perfil'; box.prepend(img); }
                        img.src = data.foto + '?t=' + Date.now();
                    }
                }
                setTimeout(closePerfilModal, 1400);
            } else {
                notice.className   = 'perfil-notice erro';
                notice.textContent = data.erro || 'Erro ao salvar.';
            }
        } catch {
            notice.className   = 'perfil-notice erro';
            notice.textContent = 'Falha de comunicação.';
        }

        btn.disabled    = false;
        btn.textContent = 'Salvar';
    }
</script>

</body>
</html>
