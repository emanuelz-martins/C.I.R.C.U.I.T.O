<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

$page_title = 'Relatórios';
require_once '../includes/header.php';

$usuario_perfil = $_SESSION['auth_user']['perfil'] ?? 'laboratorista';
$is_admin = ($usuario_perfil === 'admin');
?>

<style>
    .main {
        padding: 60px 48px 80px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho da página ── */
    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        margin-bottom: 40px;
    }

    .page-title {
        font-size: 3rem;
        font-weight: 900;
        color: #ffffff;
        line-height: 1;
    }

    .back-btn {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #1e1e1e;
        border: 1.5px solid #333;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background 0.15s;
        flex-shrink: 0;
    }

    .back-btn:hover { background: #2a2a2a; }
    .back-btn svg { width: 20px; height: 20px; }

    /* ── Cards de relatório ── */
    .relatorio-card {
        background: #1e1e1e;
        border-radius: 16px;
        padding: 28px 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        margin-bottom: 16px;
    }

    .relatorio-info h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 6px;
    }

    .relatorio-info p {
        font-size: 0.875rem;
        color: #888;
        line-height: 1.5;
        max-width: 420px;
    }

    /* Botão dos 3 pontos */
    .relatorio-menu-wrapper {
        position: relative;
        flex-shrink: 0;
    }

    .tres-pontos-btn {
        width: 46px;
        height: 46px;
        background: #2a2a2a;
        border: 1.5px solid #3a3a3a;
        border-radius: 10px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.15s;
    }

    .tres-pontos-btn:hover { background: #333; }
    .tres-pontos-btn svg { width: 22px; height: 22px; }

    /* Dropdown do menu */
    .relatorio-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 210px;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(0,0,0,0.6);
        z-index: 50;
    }

    .relatorio-menu-wrapper.open .relatorio-dropdown { display: block; }

    .relatorio-dropdown a,
    .relatorio-dropdown button {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        padding: 14px 18px;
        color: #ffffff;
        text-decoration: none;
        font-size: 0.9rem;
        background: none;
        border: none;
        border-bottom: 1px solid #2a2a2a;
        cursor: pointer;
        text-align: left;
        transition: background 0.15s;
    }

    .relatorio-dropdown a:last-child,
    .relatorio-dropdown button:last-child { border-bottom: none; }
    .relatorio-dropdown a:hover,
    .relatorio-dropdown button:hover { background: #2a2a2a; }
    .relatorio-dropdown svg { width: 16px; height: 16px; color: #aaa; flex-shrink: 0; }

    /* ── Seção "Pedir informações" ── */
    .admin-section {
        margin-top: 48px;
        border-top: 1px solid #222;
        padding-top: 40px;
    }

    .pedir-info-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: #1e1e1e;
        border: 1.5px solid #333;
        border-radius: 12px;
        padding: 16px 24px;
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
        text-align: left;
    }

    .pedir-info-btn:hover { background: #2a2a2a; border-color: #444; }
    .pedir-info-btn svg { width: 20px; height: 20px; color: #aaa; flex-shrink: 0; }

    /* ══════════════════════════════════════════
       MODAIS
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.72);
        z-index: 200;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .modal-overlay.open { display: flex; }

    .modal-box {
        background: #1a1a1a;
        border: 1px solid #2e2e2e;
        border-radius: 20px;
        padding: 36px 40px;
        max-width: 520px;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7);
        animation: modalIn .18s ease;
    }

    @keyframes modalIn {
        from { opacity: 0; transform: scale(.95) translateY(8px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    .modal-box h2 {
        font-size: 1.25rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 16px;
    }

    .modal-box p {
        font-size: 0.9rem;
        color: #aaa;
        line-height: 1.65;
        margin-bottom: 28px;
    }

    .modal-aviso-icon {
        width: 48px;
        height: 48px;
        background: #2a1f00;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }

    .modal-aviso-icon svg { width: 26px; height: 26px; color: #f59e0b; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }

    .btn-cancel {
        padding: 11px 22px;
        border-radius: 10px;
        border: 1.5px solid #333;
        background: none;
        color: #aaa;
        font-size: 0.9rem;
        cursor: pointer;
        transition: background 0.15s;
    }

    .btn-cancel:hover { background: #222; color: #fff; }

    .btn-confirm {
        padding: 11px 22px;
        border-radius: 10px;
        border: none;
        background: #ffffff;
        color: #000000;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s;
    }

    .btn-confirm:hover { background: #e0e0e0; }

    /* ── Modal de seleção de laboratorista ── */
    .modal-box.modal-lab {
        max-width: 600px;
    }

    .modal-search {
        display: flex;
        align-items: center;
        background: #242424;
        border: 1.5px solid #333;
        border-radius: 10px;
        padding: 10px 16px;
        gap: 10px;
        margin-bottom: 20px;
    }

    .modal-search svg { width: 18px; height: 18px; color: #666; flex-shrink: 0; }

    .modal-search input {
        flex: 1;
        background: none;
        border: none;
        outline: none;
        color: #fff;
        font-size: 0.9rem;
    }

    .modal-search input::placeholder { color: #555; }

    .lab-list {
        max-height: 340px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .lab-list::-webkit-scrollbar { width: 6px; }
    .lab-list::-webkit-scrollbar-track { background: #1a1a1a; }
    .lab-list::-webkit-scrollbar-thumb { background: #333; border-radius: 3px; }

    .lab-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        background: #242424;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        cursor: pointer;
        text-decoration: none;
        color: #ffffff;
        transition: background 0.15s, border-color 0.15s;
    }

    .lab-item:hover { background: #2e2e2e; border-color: #3a3a3a; }

    .lab-avatar {
        width: 40px;
        height: 40px;
        background: #333;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .lab-avatar svg { width: 20px; height: 20px; color: #aaa; }

    .lab-info-name {
        font-size: 0.95rem;
        font-weight: 600;
        color: #ffffff;
    }

    .lab-info-email {
        font-size: 0.8rem;
        color: #666;
        margin-top: 2px;
    }

    .lab-empty {
        text-align: center;
        color: #555;
        font-size: 0.9rem;
        padding: 32px 0;
    }

    /* ── Footer ── */
    .site-footer {
        background: #111;
        border-top: 1px solid #1e1e1e;
        padding: 56px 48px;
        display: flex;
        align-items: flex-start;
        gap: 80px;
        margin-top: 80px;
    }

    .footer-brand {
        flex-shrink: 0;
    }

    .footer-brand p {
        font-size: 0.9rem;
        color: #555;
        margin-bottom: 8px;
    }

    .footer-brand-name {
        font-family: 'Courier New', monospace;
        font-size: 1.6rem;
        font-weight: 900;
        letter-spacing: 0.06em;
        color: #ffffff;
    }

    .footer-text {
        flex: 1;
        font-size: 0.83rem;
        color: #555;
        line-height: 1.7;
    }

    .footer-text ul {
        list-style: disc;
        padding-left: 18px;
        margin-top: 8px;
    }

    @media (max-width: 900px) {
        .main { padding: 40px 20px 60px; }
        .page-title { font-size: 2.2rem; }
        .site-footer { flex-direction: column; gap: 32px; padding: 40px 20px; }
        .relatorio-card { flex-direction: column; align-items: flex-start; }
    }
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

    <div class="page-header">
        <h1 class="page-title">Relatórios</h1>
        <a href="./index.php" class="back-btn" title="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Card: Relatório de estoque -->
    <div class="relatorio-card">
        <div class="relatorio-info">
            <h3>Emitir relatório de estoque</h3>
            <p>Lista todos os itens, seus códigos, suas categorias e quantos estão sendo emprestados e quantos estão em estoque.</p>
        </div>
        <div class="relatorio-menu-wrapper" id="menuEstoque">
            <button class="tres-pontos-btn" onclick="toggleMenu('menuEstoque')" title="Opções">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
            </button>
            <div class="relatorio-dropdown">
                <a href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Emitir
                </a>
                <a href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    Ver relatórios gerados
                </a>
            </div>
        </div>
    </div>

    <!-- Card: Relatório de empréstimos -->
    <div class="relatorio-card">
        <div class="relatorio-info">
            <h3>Emitir relatório de empréstimos</h3>
            <p>Lista todos os empréstimos, seus usuários, estado do pedido, data da última atualização e data de entrega.</p>
        </div>
        <div class="relatorio-menu-wrapper" id="menuEmprestimos">
            <button class="tres-pontos-btn" onclick="toggleMenu('menuEmprestimos')" title="Opções">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
            </button>
            <div class="relatorio-dropdown">
                <a href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Emitir
                </a>
                <a href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    Ver relatórios gerados
                </a>
            </div>
        </div>
    </div>

    <!-- ── Seção: Pedir informações ── -->
    <div class="admin-section">
        <button class="pedir-info-btn" onclick="abrirModalAviso()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            Pedir informações ao laboratorista
        </button>
    </div>

</main>

<!-- ══════════════════ FOOTER ══════════════════ -->
<footer class="site-footer">
    <div class="footer-brand">
        <p>Conheça o</p>
        <div class="footer-brand-name">C.I.R.C.U.I.T.O.</div>
    </div>
    <div class="footer-text">
        <p>O sistema oficial do Laboratório de Hardware do Instituto Federal Farroupilha / Campus Frederico
        Westphalen para gerenciamento de componentes. Aqui você encontra um catálogo organizado, realiza
        reservas com datas definidas e acompanha todo o processo de empréstimo de forma simples, segura e
        transparente.</p>
        <p style="margin-top:12px;">O projeto foi realizado pelos estudantes:</p>
        <ul>
            <li>Davi Cadoná Marion;</li>
            <li>Emanuel Ziegler Martins;</li>
            <li>Luiz Fernando Schwanz;</li>
            <li>Pedro Henrique Toazza;</li>
            <li>Victor Borba de Moura e Silva.</li>
        </ul>
    </div>
</footer>

<!-- ══════════════════ MODAL: AVISO ══════════════════ -->
<div class="modal-overlay" id="modalAviso">
    <div class="modal-box">
        <div class="modal-aviso-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
        </div>
        <h2>Atenção antes de continuar</h2>
        <p>Utilize essa opção apenas se faltar alguma informação no relatório e vai ter que mudar algumas opções manualmente.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="fecharModalAviso()">Cancelar</button>
            <button class="btn-confirm" onclick="confirmarAviso()">Li e aceito e concordo com o aviso.</button>
        </div>
    </div>
</div>

<!-- ══════════════════ MODAL: SELECIONAR LABORATORISTA ══════════════════ -->
<div class="modal-overlay" id="modalLab">
    <div class="modal-box modal-lab">
        <h2>Selecionar laboratorista</h2>

        <div class="modal-search">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="searchLab" placeholder="Pesquisar laboratorista..." oninput="filtrarLabs()" autocomplete="off">
        </div>

        <div class="lab-list" id="labList">
            <?php
            /* Tenta carregar laboratoristas do BD */
            $labs = [];
            try {
                require_once '../../src/config/database.php';
                $pdo = db();
                $stmt = $pdo->prepare("
                    SELECT id_user, nome, email
                    FROM Usuario
                    WHERE perfil IN ('laboratorista', 'admin')
                    ORDER BY nome ASC
                ");
                $stmt->execute();
                $labs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                /* BD indisponível — lista ficará vazia */
            }

            if (empty($labs)):
            ?>
            <div class="lab-empty" id="labEmpty">Nenhum laboratorista encontrado.</div>
            <?php else: ?>
            <?php foreach ($labs as $lab): ?>
            <a class="lab-item"
               href="./ajuda.php?id_user=<?= (int) $lab['id_user'] ?>"
               data-nome="<?= htmlspecialchars(mb_strtolower($lab['nome'])) ?>">
                <div class="lab-avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
                <div>
                    <div class="lab-info-name"><?= htmlspecialchars($lab['nome']) ?></div>
                    <?php if (!empty($lab['email'])): ?>
                    <div class="lab-info-email"><?= htmlspecialchars($lab['email']) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <div class="lab-empty" id="labEmpty" style="display:none;">Nenhum laboratorista encontrado.</div>
            <?php endif; ?>
        </div>

        <div class="modal-actions" style="margin-top:24px;">
            <button class="btn-cancel" onclick="fecharModalLab()">Fechar</button>
        </div>
    </div>
</div>

<script>
    /* ── Menus dos cards ── */
    function toggleMenu(id) {
        const wrapper = document.getElementById(id);
        const isOpen = wrapper.classList.contains('open');
        // Fecha todos
        document.querySelectorAll('.relatorio-menu-wrapper').forEach(w => w.classList.remove('open'));
        if (!isOpen) wrapper.classList.add('open');
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.relatorio-menu-wrapper')) {
            document.querySelectorAll('.relatorio-menu-wrapper').forEach(w => w.classList.remove('open'));
        }
    });

    /* ── Modal aviso ── */
    function abrirModalAviso() {
        document.getElementById('modalAviso').classList.add('open');
    }

    function fecharModalAviso() {
        document.getElementById('modalAviso').classList.remove('open');
    }

    function confirmarAviso() {
        fecharModalAviso();
        document.getElementById('searchLab').value = '';
        filtrarLabs();
        document.getElementById('modalLab').classList.add('open');
    }

    /* ── Modal laboratoristas ── */
    function fecharModalLab() {
        document.getElementById('modalLab').classList.remove('open');
    }

    function filtrarLabs() {
        const query = document.getElementById('searchLab').value.toLowerCase().trim();
        const items = document.querySelectorAll('#labList .lab-item');
        const empty = document.getElementById('labEmpty');
        let visiveis = 0;

        items.forEach(function(item) {
            const nome = item.dataset.nome || '';
            if (nome.includes(query)) {
                item.style.display = 'flex';
                visiveis++;
            } else {
                item.style.display = 'none';
            }
        });

        if (empty) empty.style.display = visiveis === 0 ? 'block' : 'none';
    }

    /* Fecha modal clicando no overlay */
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    /* Fecha com ESC */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(function(o) {
                o.classList.remove('open');
            });
        }
    });
</script>

</body>
</html>
