<?php
$page_title = 'C.I.R.C.U.I.T.O';
require_once 'includes/header.php';
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
        .main {
            padding: 32px 40px 60px;
        }

        /* ── Card "Como usar a plataforma?" ──────── */
        .how-to-card {
            background-color: #1c1c1c;
            border: 1px solid #2a2a2a;
            border-radius: 20px;
            padding: 48px 56px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            margin-bottom: 48px;
            align-items: start;
        }

        .how-to-title {
            font-size: 2.6rem;
            font-weight: 800;
            line-height: 1.15;
            color: #ffffff;
            align-self: center;
        }

        .how-to-steps {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .step h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 3px;
        }

        .step p {
            font-size: 0.85rem;
            color: #999;
            line-height: 1.55;
        }

        .step p em {
            color: #ccc;
            font-style: italic;
        }

        /* ── Seções de categorias ─────────────────── */
        .category-section {
            margin-bottom: 48px;
        }

        .category-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 20px;
        }

        /* Carrossel */
        .carousel-wrapper {
            position: relative;
        }

        .carousel-track {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            scroll-behavior: smooth;
            scrollbar-width: none;
            padding-bottom: 4px;
        }

        .carousel-track::-webkit-scrollbar { display: none; }

        .carousel-btn {
            position: absolute;
            top: 40%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #2a2a2a;
            border: 1px solid #3a3a3a;
            color: #ffffff;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            transition: background-color 0.15s;
        }

        .carousel-btn:hover { background-color: #3a3a3a; }
        .carousel-btn.prev { left: -20px; }
        .carousel-btn.next { right: -20px; }

        /* ── Cards de componentes ─────────────────── */
        .component-card {
            flex: 0 0 240px;
            background-color: #1e1e1e;
            border: 1px solid #2a2a2a;
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, transform 0.15s;
            cursor: pointer;
        }

        .component-card:hover {
            border-color: #444;
            transform: translateY(-2px);
        }

        .card-img {
            width: 100%;
            aspect-ratio: 4/3;
            object-fit: cover;
            background-color: #111;
            display: block;
        }

        .card-img-placeholder {
            width: 100%;
            aspect-ratio: 4/3;
            background-color: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }

        .card-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .card-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .card-desc {
            font-size: 0.8rem;
            color: #888;
            line-height: 1.5;
            flex: 1;
            margin-bottom: 10px;
        }

        .card-stock {
            font-size: 0.78rem;
            color: #666;
            margin-bottom: 14px;
        }

        .btn-add-cart {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px;
            background-color: #2a2a2a;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s;
        }

        .btn-add-cart:hover { background-color: #383838; }

        .btn-add-cart svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
        }

        /* ══════════════════════════════════════════
           RESPONSIVO
        ══════════════════════════════════════════ */
        @media (max-width: 860px) {
            .navbar { padding: 0 20px; gap: 12px; }
            .main   { padding: 24px 20px 48px; }

            .how-to-card {
                grid-template-columns: 1fr;
                gap: 32px;
                padding: 32px 28px;
            }

            .how-to-title { font-size: 1.9rem; }
        }

        .site-footer {
            border-top: 1px solid #222;
            padding: 18px 40px 22px;
            color: #7a7a7a;
            font-size: 0.85rem;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .site-footer .github-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #7a7a7a;
            transition: color 0.15s;
        }

        .site-footer .github-link:hover {
            color: #a0a0a0;
        }

        .site-footer .github-link svg {
            width: 17px;
            height: 17px;
        }

        @media (max-width: 860px) {
            .site-footer { padding: 16px 20px 20px; }
        }
    </style>
</head>
<body>

<?php
/* ── Dados de exemplo (substituir por queries reais) ── */
$usuario_nome = 'Emanuel Ziegler';
$usuario_tipo_conta = 'Aluno (developer/test)';

$categorias = [
    [
        'id'    => 'eletronicos',
        'nome'  => 'Componentes eletrônicos básicos',
        'itens' => [
            ['nome' => 'Resistor 220Ω – 1/4W',            'descricao' => 'Peça usada para limitar corrente em circuitos.',    'estoque' => 150, 'img' => null],
            ['nome' => 'Capacitor Eletrolítico 100µF 25V', 'descricao' => 'Armazena carga elétrica.',                         'estoque' => 60,  'img' => null],
            ['nome' => 'LED 5mm Vermelho',                 'descricao' => 'Diodo emissor de luz para sinalização.',           'estoque' => 120, 'img' => null],
            ['nome' => 'Transistor NPN BC547',             'descricao' => 'Usado para amplificação e chaveamento de sinais.', 'estoque' => 45,  'img' => null],
            ['nome' => 'Diodo 1N4007',                     'descricao' => 'Retificador de uso geral.',                       'estoque' => 200, 'img' => null],
        ],
    ],
    [
        'id'    => 'ferramentas',
        'nome'  => 'Ferramentas',
        'itens' => [
            ['nome' => 'Ferro de Solda 30W',    'descricao' => 'Para soldagem de componentes eletrônicos.',  'estoque' => 8,  'img' => null],
            ['nome' => 'Alicate de Corte',      'descricao' => 'Serve para cortar fios e terminais com precisão.', 'estoque' => 12, 'img' => null],
            ['nome' => 'Multímetro Digital',    'descricao' => 'Mede tensão, corrente e resistência.',      'estoque' => 6,  'img' => null],
            ['nome' => 'Protoboard 830 pontos', 'descricao' => 'Base para montagem de circuitos sem solda.','estoque' => 20, 'img' => null],
        ],
    ],
];
?>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="/index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

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

        <a href="pages_aluno/carrinho.php" class="nav-action-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            Carrinho
        </a>

        <a href="pages_aluno/pedido.php" class="nav-action-btn">
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
                <a href="pages_aluno/profile.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Acessar perfil
                </a>
                <a href="pages_aluno/notificacoes.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    Notificações
                </a>
                <a href="/logout.php" role="menuitem">
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

    <!-- Card "Como usar a plataforma?" -->
    <section class="how-to-card">
        <h2 class="how-to-title">Como usar<br>a plataforma?</h2>

        <div class="how-to-steps">
            <div class="step">
                <h4>Explore o catálogo</h4>
                <p>Navegue pelos itens disponíveis e verifique suas especificações e quantidades em estoque.</p>
            </div>
            <div class="step">
                <h4>Adicione itens ao pedido</h4>
                <p>Escolha os itens que precisa e monte sua lista de empréstimo com as datas desejadas.</p>
            </div>
            <div class="step">
                <h4>Envie sua solicitação</h4>
                <p>Finalize o pedido e aguarde a análise do responsável pelo laboratório. Você será notificado quando for aprovado.</p>
            </div>
            <div class="step">
                <h4>Retire no laboratório</h4>
                <p>Vá até o Laboratório de Hardware para pegar seus componentes quando indicado <em>"Pronto para retirada"</em>.</p>
            </div>
            <div class="step">
                <h4>Acompanhe e devolva</h4>
                <p>Veja o status dos seus empréstimos, datas de devolução e pendências diretamente no painel.</p>
            </div>
        </div>
    </section>

    <!-- Seções de categorias -->
    <?php foreach ($categorias as $categoria): ?>
    <section class="category-section">
        <h3 class="category-title"><?= htmlspecialchars($categoria['nome']) ?></h3>

        <div class="carousel-wrapper">
            <button class="carousel-btn prev"
                    onclick="scrollCarousel('carousel-<?= $categoria['id'] ?>', -1)"
                    aria-label="Anterior">&#8249;</button>

            <div class="carousel-track" id="carousel-<?= $categoria['id'] ?>">
                <?php foreach ($categoria['itens'] as $item): ?>
                <div class="component-card">

                    <?php if (!empty($item['img'])): ?>
                        <img class="card-img"
                             src="<?= htmlspecialchars($item['img']) ?>"
                             alt="<?= htmlspecialchars($item['nome']) ?>">
                    <?php else: ?>
                        <div class="card-img-placeholder">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1" width="48" height="48">
                                <rect x="7" y="7" width="10" height="10" rx="1"/>
                                <path d="M9 7V4M12 7V4M15 7V4M9 20v-3M12 20v-3M15 20v-3
                                         M4 9h3M4 12h3M4 15h3M17 9h3M17 12h3M17 15h3"/>
                            </svg>
                        </div>
                    <?php endif; ?>

                    <div class="card-body">
                        <div class="card-name"><?= htmlspecialchars($item['nome']) ?></div>
                        <div class="card-desc"><?= htmlspecialchars($item['descricao']) ?></div>
                        <div class="card-stock"><?= $item['estoque'] ?> unidades em estoque</div>
                        <a href="#" class="btn-add-cart">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                            </svg>
                            Adicionar ao carrinho
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button class="carousel-btn next"
                    onclick="scrollCarousel('carousel-<?= $categoria['id'] ?>', 1)"
                    aria-label="Próximo">&#8250;</button>
        </div>
    </section>
    <?php endforeach; ?>

</main>

<footer class="site-footer">
    <span>C.I.R.C.U.I.T.O.</span>
    <span style="color: #666; font-size: 0.8rem;">• <?= htmlspecialchars($usuario_tipo_conta) ?></span>
    <a class="github-link" href="https://github.com/" target="_blank" rel="noopener noreferrer" aria-label="GitHub">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2C6.48 2 2 6.58 2 12.23c0 4.52 2.87 8.35 6.84 9.71.5.1.68-.22.68-.5 0-.24-.01-1.03-.01-1.87-2.78.62-3.37-1.2-3.37-1.2-.45-1.18-1.11-1.49-1.11-1.49-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.9 1.58 2.36 1.12 2.94.86.09-.67.35-1.12.64-1.38-2.22-.26-4.56-1.14-4.56-5.09 0-1.13.39-2.06 1.03-2.79-.1-.26-.45-1.31.1-2.74 0 0 .84-.28 2.75 1.07A9.3 9.3 0 0 1 12 6.98c.85 0 1.71.12 2.51.35 1.9-1.35 2.74-1.07 2.74-1.07.55 1.43.2 2.48.1 2.74.64.73 1.03 1.66 1.03 2.79 0 3.96-2.34 4.82-4.57 5.08.36.32.69.95.69 1.93 0 1.39-.01 2.5-.01 2.85 0 .27.18.6.69.5A10.24 10.24 0 0 0 22 12.23C22 6.58 17.52 2 12 2Z"/>
        </svg>
    </a>
</footer>

<script>
    /* Scroll do carrossel */
    function scrollCarousel(id, direction) {
        document.getElementById(id).scrollBy({ left: direction * 272, behavior: 'smooth' });
    }
</script>

</body>
</html>
