<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

$page_title = 'Pedidos arquivados';
require_once '../includes/header.php';

$pedidos = [];
$db_ok   = false;

try {
    $pdo = db();

    $getCols = static function (PDO $pdo, string $table): array {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME
            FROM   information_schema.COLUMNS
            WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
        ');
        $stmt->execute(['table' => $table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $pedidoCols = $getCols($pdo, 'Pedido');

    if (!empty($pedidoCols) && in_array('arquivado_lab', $pedidoCols, true)) {
        $statusCol          = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
            : (in_array('status', $pedidoCols, true) ? 'status' : null);
        $numeroCol          = in_array('numero_pedido',    $pedidoCols, true) ? 'numero_pedido'    : null;
        $dataCriacaoCol     = in_array('data_criacao',     $pedidoCols, true) ? 'data_criacao'     : null;
        $dataAtualizacaoCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;

        $usuarioCols   = $getCols($pdo, 'Usuario');
        $fotoPerfilSql = in_array('foto_perfil', $usuarioCols, true) ? 'u.foto_perfil' : 'NULL';

        if ($statusCol !== null) {
            $selectNumero = ($numeroCol !== null ? 'p.' . $numeroCol : 'p.id_pedido') . ' AS numero_pedido';
            $selectStatus = 'p.' . $statusCol . ' AS status_pedido';

            if ($dataAtualizacaoCol !== null && $dataCriacaoCol !== null) {
                $selectData = "DATE_FORMAT(COALESCE(p.{$dataAtualizacaoCol}, p.{$dataCriacaoCol}), '%d/%m/%Y') AS data_fmt";
            } elseif ($dataAtualizacaoCol !== null) {
                $selectData = "DATE_FORMAT(p.{$dataAtualizacaoCol}, '%d/%m/%Y') AS data_fmt";
            } elseif ($dataCriacaoCol !== null) {
                $selectData = "DATE_FORMAT(p.{$dataCriacaoCol}, '%d/%m/%Y') AS data_fmt";
            } else {
                $selectData = 'NULL AS data_fmt';
            }

            $orderBy = $dataAtualizacaoCol !== null
                ? 'p.' . $dataAtualizacaoCol . ' DESC, p.id_pedido DESC'
                : 'p.id_pedido DESC';

            $stmt = $pdo->prepare("
                SELECT
                    p.id_pedido,
                    {$selectNumero},
                    {$selectStatus},
                    {$selectData},
                    u.nome AS nome_estudante,
                    {$fotoPerfilSql} AS foto_perfil_estudante
                FROM Pedido p
                JOIN Usuario u ON u.id_user = p.id_user
                WHERE p.arquivado_lab = 1
                ORDER BY {$orderBy}
            ");
            $stmt->execute();
            $pedidos = $stmt->fetchAll();
        }
    }

    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível */
}

$status_map = [
    'pendente'             => ['label' => 'Pendente',             'class' => 'pendente'],
    'em-separacao'         => ['label' => 'Em separação',         'class' => 'em-separacao'],
    'pronto-para-retirada' => ['label' => 'Pronto para retirada', 'class' => 'pronto-para-retirada'],
    'em-andamento'         => ['label' => 'Em andamento',         'class' => 'em-andamento'],
    'negado'               => ['label' => 'Negado',               'class' => 'negado'],
    'cancelado'            => ['label' => 'Cancelado',            'class' => 'cancelado'],
    'finalizado'           => ['label' => 'Finalizado',           'class' => 'finalizado'],
    'renovacao-solicitada' => ['label' => 'Renovação solicitada', 'class' => 'renovacao-solicitada'],
];
?>

<style>
    .main {
        padding: 48px 48px 80px;
        max-width: 1100px;
        margin: 0 auto;
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 30px;
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

    .page-subtitle {
        font-size: 0.9rem;
        color: #6b7280;
        margin-bottom: 28px;
    }

    .pedidos-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .pedido-card {
        background-color: #161616;
        border: 1px solid #252525;
        border-radius: 16px;
        padding: 22px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        opacity: 0.75;
    }

    .pedido-info { flex: 1; min-width: 0; }

    .pedido-numero {
        font-size: 1.1rem;
        font-weight: 700;
        color: #d1d5db;
        margin-bottom: 5px;
    }

    .pedido-meta {
        font-size: 0.84rem;
        color: #6b7280;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .pedido-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .status-badge {
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-badge.pendente             { background-color: #374151; color: #e5e7eb; }
    .status-badge.em-separacao         { background-color: #7e22ce; color: #ede9fe; }
    .status-badge.pronto-para-retirada { background-color: #92400e; color: #fed7aa; }
    .status-badge.em-andamento         { background-color: #1d4ed8; color: #dbeafe; }
    .status-badge.finalizado           { background-color: #166534; color: #bbf7d0; }
    .status-badge.negado,
    .status-badge.cancelado            { background-color: #991b1b; color: #fecaca; }
    .status-badge.renovacao-solicitada { background-color: #92400e; color: #fed7aa; }

    /* Botão restaurar */
    .btn-restaurar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        background-color: #2a2a2a;
        color: #9ca3af;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s, color 0.15s, border-color 0.15s;
    }
    .btn-restaurar svg   { width: 18px; height: 18px; pointer-events: none; }
    .btn-restaurar:hover { background-color: #1a2a3a; border-color: #3b82f6; color: #3b82f6; }

    .pedido-card.restaurando {
        opacity: 0;
        transform: translateX(-20px);
        transition: opacity 0.3s ease, transform 0.3s ease;
    }

    .empty-state {
        text-align: center;
        padding: 64px 0;
        color: #666;
        font-size: 1rem;
    }

    .db-error {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 16px;
        padding: 36px;
        text-align: center;
        color: #aaa;
    }
    .db-error h2 { color: #ef4444; margin-bottom: 8px; }

    @media (max-width: 720px) {
        .main          { padding: 30px 18px 60px; }
        .pedido-card   { flex-direction: column; align-items: flex-start; }
        .pedido-actions { width: 100%; justify-content: space-between; }
        .page-title    { font-size: 2.1rem; }
    }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">
    <a href="./index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <div class="nav-actions">
        <div class="nav-user" id="navUser">
            <button class="nav-user-btn" onclick="toggleUserDropdown()" aria-haspopup="true">
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
    function toggleUserDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }
    document.addEventListener('click', function (e) {
        const navUser = document.getElementById('navUser');
        if (navUser && !navUser.contains(e.target)) navUser.classList.remove('open');
    });
</script>

<main class="main">
    <div class="page-header">
        <h1 class="page-title">Pedidos arquivados</h1>
        <a href="./acessar.php" class="btn-back" aria-label="Voltar aos pedidos">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <p class="page-subtitle">Pedidos arquivados pelo laboratório. Clique no ícone de restaurar para movê-los de volta à lista principal.</p>

    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar os pedidos arquivados no momento.</p>
    </div>

    <?php elseif (empty($pedidos)): ?>
    <p class="empty-state">Nenhum pedido arquivado.</p>

    <?php else: ?>
    <div class="pedidos-list">
        <?php foreach ($pedidos as $p):
            $status     = (string) ($p['status_pedido'] ?? 'pendente');
            $statusInfo = $status_map[$status] ?? ['label' => ucfirst($status), 'class' => 'pendente'];
            $numFmt     = sprintf('%03d', (int) ($p['numero_pedido'] ?? 0));
        ?>
        <div class="pedido-card" id="card-<?= (int) $p['id_pedido'] ?>">
            <div class="pedido-info">
                <p class="pedido-numero">Pedido #<?= htmlspecialchars($numFmt) ?></p>
                <div class="pedido-meta">
                    <span>Pedido feito por: <strong style="color:#ccc"><?= htmlspecialchars($p['nome_estudante'] ?? '—') ?></strong></span>
                    <span>Última atualização: <?= htmlspecialchars($p['data_fmt'] ?? '—') ?></span>
                </div>
            </div>

            <div class="pedido-actions">
                <span class="status-badge <?= htmlspecialchars($statusInfo['class']) ?>">
                    <?= htmlspecialchars($statusInfo['label']) ?>
                </span>

                <button class="btn-restaurar"
                        title="Restaurar pedido"
                        onclick="restaurarPedido(this, <?= (int) $p['id_pedido'] ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="21 8 21 21 3 21 3 8"/>
                        <rect x="1" y="3" width="22" height="5"/>
                        <polyline points="10 14 12 12 14 14"/>
                        <line x1="12" y1="12" x2="12" y2="17"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<script>
    async function restaurarPedido(btn, id) {
        btn.disabled = true;

        const fd = new FormData();
        fd.append('id', id);
        fd.append('acao', 'desarquivar');

        try {
            const res  = await fetch('../api/pedido_arquivar_lab.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.ok) { btn.disabled = false; return; }

            const card = document.getElementById('card-' + id);
            if (card) {
                card.classList.add('restaurando');
                card.addEventListener('transitionend', function () { card.remove(); }, { once: true });
            }
        } catch (_) {
            btn.disabled = false;
        }
    }
</script>
</body>
</html>
