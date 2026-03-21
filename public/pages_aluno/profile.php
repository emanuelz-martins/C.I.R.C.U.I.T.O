<?php
$page_title = 'Perfil';
require_once '../includes/header.php';

/* ── Dados de exemplo (substituir por queries reais) ── */
$usuario_role = 'Estudante';

$pedidos = [
    [
        'numero'       => '003',
        'ultima_atualizacao' => '11/12/2025',
        'status'       => 'em-andamento',
        'status_label' => 'Em andamento',
    ],
    [
        'numero'       => '002',
        'ultima_atualizacao' => '10/09/2025',
        'status'       => 'finalizado',
        'status_label' => 'Finalizado',
    ],
    [
        'numero'       => '001',
        'ultima_atualizacao' => '31/07/2025',
        'status'       => 'cancelado',
        'status_label' => 'Cancelado',
    ],
];
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

        .profile-avatar {
            width: 110px;
            height: 110px;
            border-radius: 12px;
            background-color: #2e2e2e;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
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

        <a href="../carrinho.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            Carrinho
        </a>

        <a href="../pedidos.php" class="nav-action-btn">
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
                <a href="../notificacoes.php" role="menuitem">
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
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Card de perfil -->
    <div class="profile-card">
        <div class="profile-avatar">
            <!-- Substituir src pelo caminho real da foto do usuário quando disponível -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
            </svg>
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

    <div class="orders-list">
        <?php foreach ($pedidos as $pedido): ?>
        <div class="order-card">
            <div class="order-info">
                <p class="order-number">Pedido #<?= htmlspecialchars($pedido['numero']) ?></p>
                <p class="order-date">Data da última atualização: <?= htmlspecialchars($pedido['ultima_atualizacao']) ?></p>
            </div>
            <div class="order-actions">
                <span class="status-badge <?= htmlspecialchars($pedido['status']) ?>">
                    <?= htmlspecialchars($pedido['status_label']) ?>
                </span>
                <a href="/pedido-detalhe.php?id=<?= htmlspecialchars($pedido['numero']) ?>" class="btn-details" aria-label="Ver detalhes do pedido">
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
    </div></main>

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
</script>

</body>
</html>
