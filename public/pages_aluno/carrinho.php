<?php
$page_title = 'Carrinho';
require_once '../includes/header.php';

/* ── Dados de exemplo (substituir por queries reais) ── */
$itens = [
    [
        'id'        => 1,
        'categoria' => 'Componentes eletrônicos básicos',
        'nome'      => 'LED 5mm Vermelho',
        'descricao' => 'Diodo emissor de luz para sinalização.',
        'imagem'    => '',
        'quantidade'=> 1,
    ],
    [
        'id'        => 2,
        'categoria' => 'Ferramentas',
        'nome'      => 'Alicate de corte',
        'descricao' => 'Serve para cortar fios e terminais com precisão.',
        'imagem'    => '',
        'quantidade'=> 1,
    ],
];
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 40px 40px 120px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho da página ──────────────── */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 40px;
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

    /* ── Lista de itens ───────────────────── */
    .cart-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 40px;
    }

    .cart-item {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .item-img {
        width: 100px;
        height: 100px;
        border-radius: 10px;
        object-fit: cover;
        flex-shrink: 0;
        background-color: #111;
    }

    .item-img-placeholder {
        width: 100px;
        height: 100px;
        border-radius: 10px;
        background-color: #111;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #444;
    }

    .item-info {
        flex: 1;
        min-width: 0;
    }

    .item-categoria {
        font-size: 0.78rem;
        color: #888;
        margin-bottom: 4px;
    }

    .item-nome {
        font-size: 1.05rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .item-descricao {
        font-size: 0.82rem;
        color: #aaa;
        line-height: 1.4;
    }

    /* ── Controles de quantidade ──────────── */
    .item-controls {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .qty-control {
        display: flex;
        align-items: center;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        overflow: hidden;
    }

    .qty-btn {
        width: 40px;
        height: 40px;
        background: none;
        border: none;
        color: #ffffff;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
    }

    .qty-btn:hover { background-color: #2a2a2a; }

    .qty-value {
        width: 44px;
        height: 40px;
        background-color: #2a2a2a;
        border: none;
        border-left: 1px solid #3a3a3a;
        border-right: 1px solid #3a3a3a;
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 600;
        text-align: center;
        outline: none;
        -moz-appearance: textfield;
    }

    .qty-value::-webkit-inner-spin-button,
    .qty-value::-webkit-outer-spin-button { -webkit-appearance: none; }

    .btn-delete {
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
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-delete:hover { background-color: #3a1a1a; border-color: #6b2222; }

    .btn-delete svg {
        width: 18px;
        height: 18px;
    }

    /* ── Carrinho vazio ───────────────────── */
    .cart-empty {
        text-align: center;
        padding: 60px 0;
        color: #555;
    }

    .cart-empty svg {
        width: 64px;
        height: 64px;
        margin-bottom: 16px;
        color: #333;
    }

    .cart-empty p {
        font-size: 1.1rem;
    }

    /* ── Botão enviar pedido ──────────────── */
    .btn-enviar {
        display: block;
        width: 100%;
        padding: 18px;
        background-color: #ffffff;
        color: #141414;
        font-size: 1.1rem;
        font-weight: 700;
        border: none;
        border-radius: 14px;
        cursor: pointer;
        text-align: center;
        transition: background-color 0.15s;
        text-decoration: none;
    }

    .btn-enviar:hover { background-color: #e0e0e0; }

    .btn-enviar:disabled {
        opacity: 0.4;
        cursor: not-allowed;
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
        <h1 class="page-title">Seu carrinho de itens</h1>
        <a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Lista de itens -->
    <?php if (empty($itens)): ?>
    <div class="cart-empty">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <p>Seu carrinho está vazio.</p>
    </div>
    <?php else: ?>
    <div class="cart-list" id="cartList">
        <?php foreach ($itens as $item): ?>
        <div class="cart-item" id="item-<?= (int)$item['id'] ?>">

            <?php if (!empty($item['imagem'])): ?>
                <img src="<?= htmlspecialchars($item['imagem']) ?>" alt="<?= htmlspecialchars($item['nome']) ?>" class="item-img">
            <?php else: ?>
                <div class="item-img-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                </div>
            <?php endif; ?>

            <div class="item-info">
                <p class="item-categoria"><?= htmlspecialchars($item['categoria']) ?></p>
                <p class="item-nome"><?= htmlspecialchars($item['nome']) ?></p>
                <p class="item-descricao"><?= htmlspecialchars($item['descricao']) ?></p>
            </div>

            <div class="item-controls">
                <div class="qty-control">
                    <button class="qty-btn" onclick="alterarQtd(<?= (int)$item['id'] ?>, -1)" aria-label="Diminuir">-</button>
                    <input
                        type="number"
                        class="qty-value"
                        id="qty-<?= (int)$item['id'] ?>"
                        value="<?= (int)$item['quantidade'] ?>"
                        min="1"
                        onchange="validarQtd(<?= (int)$item['id'] ?>)"
                        aria-label="Quantidade"
                    >
                    <button class="qty-btn" onclick="alterarQtd(<?= (int)$item['id'] ?>, +1)" aria-label="Aumentar">+</button>
                </div>

                <button class="btn-delete" onclick="removerItem(<?= (int)$item['id'] ?>)" aria-label="Remover item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                </button>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- Botão enviar -->
    <button class="btn-enviar" id="btnEnviar" onclick="enviarPedido()">
        Enviar pedido
    </button>
    <?php endif; ?>

</main>

<script>
    function alterarQtd(id, delta) {
        const input = document.getElementById('qty-' + id);
        const novoValor = Math.max(1, (parseInt(input.value) || 1) + delta);
        input.value = novoValor;
    }

    function validarQtd(id) {
        const input = document.getElementById('qty-' + id);
        if (!input.value || parseInt(input.value) < 1) input.value = 1;
    }

    function removerItem(id) {
        const el = document.getElementById('item-' + id);
        if (el) {
            el.style.transition = 'opacity 0.2s';
            el.style.opacity = '0';
            setTimeout(() => {
                el.remove();
                verificarCarrinhoVazio();
            }, 200);
        }
    }

    function verificarCarrinhoVazio() {
        const lista = document.getElementById('cartList');
        const btnEnviar = document.getElementById('btnEnviar');
        if (lista && lista.children.length === 0 && btnEnviar) {
            btnEnviar.disabled = true;
        }
    }

    function enviarPedido() {
        // TODO: implementar envio real (AJAX ou submit de formulário)
        alert('Pedido enviado com sucesso!');
    }
</script>

</body>
</html>
