<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

$page_title = 'Dashboard';
require_once '../includes/header.php';

/* ── Estatísticas: serão carregadas do BD ── */
$emprestimos_em_andamento = 0;
$esperando_avaliacao      = 0;
$devolucoes_em_atraso     = 0;
$db_ok = false;

try {
    $pdo = db();

    $getCols = static function (PDO $pdo, string $table): array {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
        ');
        $stmt->execute(['table' => $table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $pedidoCols = $getCols($pdo, 'Pedido');
    $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
        : (in_array('status', $pedidoCols, true) ? 'status' : null);

    if ($statusCol !== null) {
        /* Em aberto: do pendente até em-andamento (não conta finalizado/negado/cancelado) */
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM Pedido WHERE {$statusCol} IN ('pendente', 'em-separacao', 'pronto-para-retirada', 'em-andamento', 'renovacao-solicitada')");
        $stmt->execute();
        $emprestimos_em_andamento = (int) ($stmt->fetch()['total'] ?? 0);

        /* Esperando avaliação: pendente + renovação solicitada */
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM Pedido WHERE {$statusCol} IN ('pendente', 'renovacao-solicitada')");
        $stmt->execute();
        $esperando_avaliacao = (int) ($stmt->fetch()['total'] ?? 0);

        /* Devoluções em atraso: em andamento com prazo vencido */
        $dataPrazoCol = null;
        foreach (['data_devolucao_prevista', 'data_entrega', 'data_retirada_prevista'] as $candidate) {
            if (in_array($candidate, $pedidoCols, true)) {
                $dataPrazoCol = $candidate;
                break;
            }
        }

        if ($dataPrazoCol !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM Pedido WHERE {$statusCol} = 'em-andamento' AND {$dataPrazoCol} IS NOT NULL AND {$dataPrazoCol} < NOW()");
            $stmt->execute();
            $devolucoes_em_atraso = (int) ($stmt->fetch()['total'] ?? 0);
        }
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
        padding: 60px 48px 80px;
        max-width: 1300px;
        margin: 0 auto;
    }

    /* ── Seção: Pedidos em ocorrência ─────── */
    .section-label {
        font-size: 1.1rem;
        font-weight: 600;
        color: #ffffff;
        margin-bottom: 18px;
    }

    .ocorrencia-row {
        display: flex;
        gap: 16px;
        align-items: stretch;
        margin-bottom: 64px;
    }

    /* Card esquerdo — empréstimos em andamento */
    .card-emprestimos {
        position: relative;
        background-color: #1c1c1c;
        border-radius: 16px;
        padding: 28px 32px;
        min-width: 260px;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 18px;
        flex-shrink: 0;
    }

    /* Padrão de circuito no fundo */
    .card-emprestimos::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80'%3E%3Crect width='80' height='80' fill='none'/%3E%3Ccircle cx='10' cy='10' r='3' fill='none' stroke='%23333' stroke-width='1.2'/%3E%3Ccircle cx='70' cy='10' r='3' fill='none' stroke='%23333' stroke-width='1.2'/%3E%3Ccircle cx='10' cy='70' r='3' fill='none' stroke='%23333' stroke-width='1.2'/%3E%3Ccircle cx='70' cy='70' r='3' fill='none' stroke='%23333' stroke-width='1.2'/%3E%3Ccircle cx='40' cy='40' r='4' fill='none' stroke='%23333' stroke-width='1.2'/%3E%3Cline x1='13' y1='10' x2='67' y2='10' stroke='%23333' stroke-width='1'/%3E%3Cline x1='10' y1='13' x2='10' y2='67' stroke='%23333' stroke-width='1'/%3E%3Cline x1='70' y1='13' x2='70' y2='67' stroke='%23333' stroke-width='1'/%3E%3Cline x1='13' y1='70' x2='67' y2='70' stroke='%23333' stroke-width='1'/%3E%3Cline x1='40' y1='13' x2='40' y2='36' stroke='%23333' stroke-width='1'/%3E%3Cline x1='40' y1='44' x2='40' y2='67' stroke='%23333' stroke-width='1'/%3E%3Cline x1='13' y1='40' x2='36' y2='40' stroke='%23333' stroke-width='1'/%3E%3Cline x1='44' y1='40' x2='67' y2='40' stroke='%23333' stroke-width='1'/%3E%3Crect x='25' y='25' width='30' height='30' rx='2' fill='none' stroke='%23333' stroke-width='1'/%3E%3C/svg%3E");
        background-size: 80px 80px;
        opacity: 0.8;
        pointer-events: none;
    }

    .card-emprestimos-num {
        font-size: 3.2rem;
        font-weight: 900;
        color: #ffffff;
        line-height: 1;
        position: relative;
        z-index: 1;
        flex-shrink: 0;
    }

    .card-emprestimos-label {
        font-size: 1rem;
        font-weight: 600;
        color: #ffffff;
        line-height: 1.35;
        position: relative;
        z-index: 1;
    }

    /* Card direito — stats */
    .card-stats {
        flex: 1;
        background-color: #1c1c1c;
        border: 1.5px solid #333;
        border-radius: 16px;
        padding: 28px 40px;
        display: flex;
        align-items: center;
        gap: 0;
    }

    .stat-item {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 18px;
    }

    .stat-item + .stat-item {
        border-left: 1px solid #2e2e2e;
        padding-left: 40px;
    }

    .stat-num {
        font-size: 3rem;
        font-weight: 900;
        line-height: 1;
        flex-shrink: 0;
    }

    .stat-num.blue { color: #3b82f6; }
    .stat-num.red  { color: #ef4444; }

    .stat-label {
        font-size: 1rem;
        font-weight: 600;
        color: #ffffff;
        line-height: 1.35;
    }

    .stat-label.blue { color: #3b82f6; }
    .stat-label.red  { color: #ef4444; }

    /* ── Seção: Acessar funcionalidades ───── */
    .section-title {
        font-size: 2.8rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 32px;
    }

    .funcionalidades-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }

    .func-card {
        background-color: #1e1e1e;
        border-radius: 16px;
        padding: 28px 24px 36px;
        text-decoration: none;
        color: #ffffff;
        display: flex;
        flex-direction: column;
        gap: 20px;
        transition: background-color 0.18s;
        cursor: pointer;
    }

    .func-card:hover {
        background-color: #262626;
    }

    .func-icon {
        width: 48px;
        height: 48px;
        background-color: #2a2a2a;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .func-icon svg {
        width: 26px;
        height: 26px;
        color: #ffffff;
    }

    .func-label {
        font-size: 1.15rem;
        font-weight: 700;
        color: #ffffff;
        line-height: 1.3;
    }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 900px) {
        .main { padding: 40px 20px 60px; }
        .ocorrencia-row { flex-direction: column; }
        .card-stats { flex-direction: column; gap: 24px; align-items: flex-start; }
        .stat-item + .stat-item { border-left: none; padding-left: 0; border-top: 1px solid #2e2e2e; padding-top: 24px; }
        .funcionalidades-grid { grid-template-columns: repeat(2, 1fr); }
        .section-title { font-size: 2rem; }
    }

    @media (max-width: 480px) {
        .funcionalidades-grid { grid-template-columns: 1fr; }
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
        const navUser = document.getElementById('navUser');
        if (!navUser.contains(e.target)) navUser.classList.remove('open');
    });
</script>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- ── Pedidos em ocorrência ─────────────── -->
    <p class="section-label">Pedidos em ocorrência</p>

    <div class="ocorrencia-row">

        <!-- Empréstimos em andamento -->
        <div class="card-emprestimos">
            <span class="card-emprestimos-num"><?= (int) $emprestimos_em_andamento ?></span>
            <span class="card-emprestimos-label">empréstimos em<br>andamento</span>
        </div>

        <!-- Estatísticas -->
        <div class="card-stats">

            <div class="stat-item">
                <span class="stat-num blue"><?= (int) $esperando_avaliacao ?></span>
                <span class="stat-label blue">esperando<br>avaliação</span>
            </div>

            <div class="stat-item">
                <span class="stat-num red"><?= (int) $devolucoes_em_atraso ?></span>
                <span class="stat-label red">devoluções em<br>atraso</span>
            </div>

        </div>

    </div>

    <!-- ── Acessar funcionalidades ───────────── -->
    <h2 class="section-title">Acessar funcionalidades</h2>

    <div class="funcionalidades-grid">

        <!-- Acessar pedidos -->
        <a href="./acessar.php" class="func-card">
            <div class="func-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8"  y1="2" x2="8"  y2="6"/>
                    <line x1="3"  y1="10" x2="21" y2="10"/>
                    <circle cx="17" cy="17" r="3"/>
                    <line x1="17" y1="15.5" x2="17" y2="17.2"/>
                    <line x1="17" y1="17.2" x2="18.2" y2="17.2"/>
                </svg>
            </div>
            <span class="func-label">Acessar<br>pedidos</span>
        </a>

        <!-- Gerenciar usuários -->
        <a href="./gerenciar_user.php" class="func-card">
            <div class="func-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <span class="func-label">Gerenciar<br>usuários</span>
        </a>

        <!-- Gerar relatórios -->
        <a href="./relatorios.php" class="func-card">
            <div class="func-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="2" width="14" height="20" rx="2"/>
                    <line x1="9"  y1="7"  x2="15" y2="7"/>
                    <line x1="9"  y1="11" x2="15" y2="11"/>
                    <line x1="9"  y1="15" x2="13" y2="15"/>
                </svg>
            </div>
            <span class="func-label">Gerar<br>relatórios</span>
        </a>

        <!-- Acessar catálogo -->
        <a href="./catalogo.php" class="func-card">
            <div class="func-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </div>
            <span class="func-label">Acessar<br>catálogo</span>
        </a>

    </div>

</main>

</body>
</html>
