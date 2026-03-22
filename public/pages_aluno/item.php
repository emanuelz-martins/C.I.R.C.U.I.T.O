<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

/* ══════════════════════════════════════════════════════════
   Busca o componente pelo id_comp passado via GET (?id=X)
   Tabelas: Componente (JOIN Categoria)
══════════════════════════════════════════════════════════ */
$id_comp = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id_comp || $id_comp <= 0) {
    header('Location: ../index.php');
    exit;
}

$item  = null;
$db_ok = false;

try {
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT
            c.id_comp,
            c.nome,
            c.descricao,
            c.qtd_disponivel,
            c.qtd_max_user,
            c.status_atual,
            c.imagem_url,
            cat.nome AS categoria_nome
        FROM Componente c
        JOIN Categoria cat ON cat.id_cat = c.id_cat
        WHERE c.id_comp = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id_comp]);
    $item  = $stmt->fetch();
    $db_ok = true;
} catch (Throwable) {
    /* BD indisponível – a página exibe mensagem de erro adequada */
}

if ($db_ok && !$item) {
    /* Componente não encontrado */
    header('Location: ../index.php');
    exit;
}

$page_title = $item ? htmlspecialchars($item['nome']) : 'Item';
require_once '../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO
    ══════════════════════════════════════════ */
    .main {
        padding: 40px 48px 80px;
        max-width: 1200px;
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
        flex-shrink: 0;
        transition: background-color 0.15s;
    }

    .btn-back:hover { background-color: #333; }
    .btn-back svg { width: 20px; height: 20px; }

    /* ── Card principal do item ───────────── */
    .item-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 20px;
        padding: 28px;
        display: grid;
        grid-template-columns: 420px 1fr;
        gap: 48px;
        align-items: start;
    }

    /* ── Bloco de imagem ──────────────────── */
    .item-img-wrap {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .item-img {
        width: 100%;
        aspect-ratio: 4/3;
        object-fit: cover;
        border-radius: 12px;
        background-color: #111;
        display: block;
    }

    .item-img-placeholder {
        width: 100%;
        aspect-ratio: 4/3;
        background-color: #111;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #333;
    }

    /* ── Descrição completa (abaixo da imagem) */
    .descricao-completa h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 14px;
    }

    .descricao-cols {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0 32px;
    }

    .descricao-bloco p {
        font-size: 0.82rem;
        color: #aaa;
        margin-bottom: 6px;
        font-weight: 600;
    }

    .descricao-bloco ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .descricao-bloco ul li {
        font-size: 0.82rem;
        color: #ccc;
        padding: 2px 0 2px 12px;
        position: relative;
        line-height: 1.6;
    }

    .descricao-bloco ul li::before {
        content: '·';
        position: absolute;
        left: 0;
        color: #666;
    }

    /* ── Bloco de informações (lado direito) ─ */
    .item-info {
        display: flex;
        flex-direction: column;
        padding-top: 8px;
    }

    .item-categoria {
        font-size: 0.85rem;
        color: #aaa;
        margin-bottom: 8px;
        text-align: right;
    }

    .item-nome {
        font-size: 2.2rem;
        font-weight: 800;
        color: #ffffff;
        line-height: 1.15;
        text-align: right;
        margin-bottom: 10px;
    }

    .item-desc-curta {
        font-size: 0.95rem;
        color: #aaa;
        text-align: right;
        margin-bottom: 20px;
        line-height: 1.5;
    }

    .item-estoque {
        font-size: 0.9rem;
        color: #aaa;
        text-align: right;
        margin-bottom: 20px;
    }

    .item-estoque strong {
        color: #ffffff;
    }

    /* ── Seletor de quantidade ────────────── */
    .qty-row {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 0;
        margin-bottom: 20px;
    }

    .qty-btn {
        width: 38px;
        height: 38px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        font-size: 1.1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
        user-select: none;
    }

    .qty-btn.minus { border-radius: 8px 0 0 8px; }
    .qty-btn.plus  { border-radius: 0 8px 8px 0; }
    .qty-btn:hover { background-color: #3a3a3a; }

    .qty-input {
        width: 52px;
        height: 38px;
        background-color: #2a2a2a;
        border-top: 1px solid #3a3a3a;
        border-bottom: 1px solid #3a3a3a;
        border-left: none;
        border-right: none;
        color: #ffffff;
        font-size: 1rem;
        font-weight: 600;
        text-align: center;
        outline: none;
    }

    /* Remove setas do input number */
    .qty-input::-webkit-outer-spin-button,
    .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .qty-input[type=number] { -moz-appearance: textfield; }

    /* ── Botão adicionar ao carrinho ──────── */
    .btn-cart {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        width: 100%;
        padding: 16px;
        background-color: #ffffff;
        border: none;
        border-radius: 10px;
        color: #111111;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: background-color 0.15s, opacity 0.15s;
        margin-top: auto;
    }

    .btn-cart:hover { background-color: #e5e5e5; }

    .btn-cart:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .btn-cart svg { width: 20px; height: 20px; flex-shrink: 0; }

    /* ── Badge de indisponível ────────────── */
    .badge-indisponivel {
        display: inline-block;
        background-color: #7f1d1d;
        color: #fca5a5;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: 20px;
        text-align: right;
        align-self: flex-end;
        margin-bottom: 16px;
    }

    /* ── Erro de BD ───────────────────────── */
    .db-error {
        background-color: #1c1c1c;
        border: 1px solid #3a1a1a;
        border-radius: 16px;
        padding: 48px;
        text-align: center;
        color: #aaa;
    }

    .db-error h2 { color: #ef4444; margin-bottom: 8px; }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 900px) {
        .main { padding: 28px 20px 60px; }
        .item-card { grid-template-columns: 1fr; gap: 28px; }
        .item-categoria, .item-nome, .item-desc-curta, .item-estoque { text-align: left; }
        .qty-row { justify-content: flex-start; }
        .page-title { font-size: 2rem; }
        .descricao-cols { grid-template-columns: 1fr; gap: 16px; }
    }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="../index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <form class="nav-search" action="../index.php" method="GET">
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
        <h1 class="page-title">Detalhamento do item</h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <?php if (!$db_ok): ?>
    <!-- Erro de conexão com o banco -->
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar os dados do componente. Verifique a conexão com o MySQL.</p>
    </div>

    <?php else: ?>
    <!-- Card do item -->
    <div class="item-card">

        <!-- Coluna esquerda: imagem + descrição completa -->
        <div class="item-img-wrap">

            <?php if (!empty($item['imagem_url'])): ?>
                <img
                    class="item-img"
                    src="<?= htmlspecialchars($item['imagem_url']) ?>"
                    alt="<?= htmlspecialchars($item['nome']) ?>"
                >
            <?php else: ?>
                <div class="item-img-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1" width="72" height="72">
                        <rect x="7" y="7" width="10" height="10" rx="1"/>
                        <path d="M9 7V4M12 7V4M15 7V4
                                 M9 20v-3M12 20v-3M15 20v-3
                                 M4 9h3M4 12h3M4 15h3
                                 M17 9h3M17 12h3M17 15h3"/>
                    </svg>
                </div>
            <?php endif; ?>

            <!-- Descrição completa (abaixo da imagem) -->
            <?php if (!empty($item['descricao'])): ?>
            <div class="descricao-completa">
                <h3>Descrição completa</h3>
                <div class="descricao-cols">
                    <div class="descricao-bloco">
                        <p>Especificações Técnicas</p>
                        <ul>
                            <?php
                            /* Divide a descrição em linhas para exibir como lista.
                               Quando o BD retornar texto estruturado (ex: "Cor: Vermelho\nDiâmetro: 5mm"),
                               cada linha vira um item. Caso seja parágrafo único, exibe como item único. */
                            $linhas = array_filter(
                                array_map('trim', explode("\n", $item['descricao']))
                            );
                            /* As primeiras metade das linhas vão para especificações */
                            $total   = count($linhas);
                            $metade  = (int) ceil($total / 2);
                            $specs   = array_slice($linhas, 0, $metade);
                            $alertas = array_slice($linhas, $metade);

                            foreach ($specs as $linha): ?>
                                <li><?= htmlspecialchars($linha) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if (!empty($alertas)): ?>
                    <div class="descricao-bloco">
                        <p>Alertas / Observações</p>
                        <ul>
                            <?php foreach ($alertas as $linha): ?>
                                <li><?= htmlspecialchars($linha) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Coluna direita: informações e ação -->
        <div class="item-info">

            <p class="item-categoria"><?= htmlspecialchars($item['categoria_nome']) ?></p>
            <h2 class="item-nome"><?= htmlspecialchars($item['nome']) ?></h2>

            <?php if (!empty($item['descricao'])): ?>
                <?php $primeira_linha = explode("\n", trim($item['descricao']))[0]; ?>
                <p class="item-desc-curta"><?= htmlspecialchars($primeira_linha) ?></p>
            <?php endif; ?>

            <?php
            $disponivel = $item['status_atual'] === 'disponivel' && (int) $item['qtd_disponivel'] > 0;
            $qtd_max    = max(1, min((int) $item['qtd_max_user'], (int) $item['qtd_disponivel']));
            ?>

            <?php if (!$disponivel): ?>
                <span class="badge-indisponivel">Indisponível</span>
            <?php endif; ?>

            <p class="item-estoque">
                Quantidade em estoque: <strong><?= (int) $item['qtd_disponivel'] ?></strong>
            </p>

            <!-- Seletor de quantidade -->
            <div class="qty-row">
                <button class="qty-btn minus" type="button"
                        onclick="changeQty(-1, <?= $qtd_max ?>)"
                        aria-label="Diminuir quantidade"
                        <?= !$disponivel ? 'disabled' : '' ?>>–</button>
                <input
                    id="qty"
                    class="qty-input"
                    type="number"
                    min="1"
                    max="<?= $qtd_max ?>"
                    value="1"
                    readonly
                    aria-label="Quantidade"
                >
                <button class="qty-btn plus" type="button"
                        onclick="changeQty(1, <?= $qtd_max ?>)"
                        aria-label="Aumentar quantidade"
                        <?= !$disponivel ? 'disabled' : '' ?>>+</button>
            </div>

            <!-- Botão adicionar ao carrinho -->
            <button
                class="btn-cart"
                id="btnCart"
                onclick="addToCart(<?= (int) $item['id_comp'] ?>)"
                <?= !$disponivel ? 'disabled' : '' ?>
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                Adicionar ao carrinho
            </button>

        </div>
    </div>
    <?php endif; ?>

</main>

</body>

<script>
    /* ── Controle de quantidade ─────────────── */
    function changeQty(delta, max) {
        const input = document.getElementById('qty');
        let val = parseInt(input.value, 10) + delta;
        if (val < 1)   val = 1;
        if (val > max) val = max;
        input.value = val;
    }

    /* ── Adicionar ao carrinho ──────────────── */
    function addToCart(idComp) {
        const qty = parseInt(document.getElementById('qty').value, 10);
        const btn = document.getElementById('btnCart');

        btn.disabled = true;
        btn.textContent = 'Adicionando…';

        fetch('../api/carrinho_add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_comp: idComp, quantidade: qty }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                         width="20" height="20">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Adicionado!`;
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             width="20" height="20">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        Adicionar ao carrinho`;
                }, 2000);
            } else {
                alert(data.mensagem ?? 'Não foi possível adicionar ao carrinho.');
                btn.disabled = false;
                btn.textContent = 'Adicionar ao carrinho';
            }
        })
        .catch(() => {
            alert('Erro de comunicação. Tente novamente.');
            btn.disabled = false;
            btn.textContent = 'Adicionar ao carrinho';
        });
    }
</script>
</html>
