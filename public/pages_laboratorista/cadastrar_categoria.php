<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

/* ══════════════════════════════════════════
   Verifica se a coluna `descricao` existe na
   tabela Categoria (pode não ter sido criada)
══════════════════════════════════════════ */
$has_descricao_col = false;
try {
    $pdo = db();
    $cols = $pdo->query("SHOW COLUMNS FROM Categoria LIKE 'descricao'")->fetchAll();
    $has_descricao_col = !empty($cols);
} catch (Throwable) { /* BD indisponível */ }

/* ══════════════════════════════════════════
   HANDLER DE AÇÕES (POST — PRG pattern)
══════════════════════════════════════════ */
$erros = [];
$form  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = db();

        /* ── Cadastrar nova categoria ── */
        if ($action === 'cadastrar') {
            $nome      = trim($_POST['nome']      ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $form      = compact('nome', 'descricao');

            if ($nome === '') {
                $erros[] = 'Nome da categoria é obrigatório.';
            }

            if (empty($erros)) {
                if ($has_descricao_col) {
                    $pdo->prepare('
                        INSERT INTO Categoria (nome, descricao) VALUES (:nome, :descricao)
                    ')->execute(['nome' => $nome, 'descricao' => ($descricao !== '' ? $descricao : null)]);
                } else {
                    $pdo->prepare('
                        INSERT INTO Categoria (nome) VALUES (:nome)
                    ')->execute(['nome' => $nome]);
                }
                header('Location: ./cadastrar_categoria.php?cadastro=ok');
                exit;
            }

        /* ── Editar categoria existente ── */
        } elseif ($action === 'editar') {
            $id_cat    = (int)  ($_POST['id_cat']    ?? 0);
            $nome      = trim($_POST['nome']          ?? '');
            $descricao = trim($_POST['descricao']     ?? '');

            if ($id_cat > 0 && $nome !== '') {
                if ($has_descricao_col) {
                    $pdo->prepare('
                        UPDATE Categoria SET nome = :nome, descricao = :descricao WHERE id_cat = :id
                    ')->execute(['nome' => $nome, 'descricao' => ($descricao !== '' ? $descricao : null), 'id' => $id_cat]);
                } else {
                    $pdo->prepare('
                        UPDATE Categoria SET nome = :nome WHERE id_cat = :id
                    ')->execute(['nome' => $nome, 'id' => $id_cat]);
                }
            }
            header('Location: ./cadastrar_categoria.php?editado=ok');
            exit;

        /* ── Excluir categoria ── */
        } elseif ($action === 'excluir') {
            $id_cat = (int) ($_POST['id_cat'] ?? 0);

            if ($id_cat > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM Componente WHERE id_cat = :id');
                $stmt->execute(['id' => $id_cat]);
                $total_itens = (int) $stmt->fetchColumn();

                if ($total_itens > 0) {
                    header('Location: ./cadastrar_categoria.php?erro=vinculada&n=' . $total_itens);
                    exit;
                }

                $pdo->prepare('DELETE FROM Categoria WHERE id_cat = :id')->execute(['id' => $id_cat]);
            }
            header('Location: ./cadastrar_categoria.php?excluido=ok');
            exit;
        }

    } catch (Throwable) {
        $erros[] = 'Erro no banco de dados. Verifique a conexão com o MySQL.';
    }
}

/* ══════════════════════════════════════════
   CARREGA CATEGORIAS DO BD
══════════════════════════════════════════ */
$categorias = [];
$db_ok      = false;

try {
    $pdo = db();

    $select_descricao = $has_descricao_col ? 'cat.descricao,' : "'' AS descricao,";

    $categorias = $pdo->query("
        SELECT
            cat.id_cat,
            cat.nome,
            {$select_descricao}
            COUNT(comp.id_comp) AS total_itens
        FROM Categoria cat
        LEFT JOIN Componente comp ON comp.id_cat = cat.id_cat
        GROUP BY cat.id_cat, cat.nome" . ($has_descricao_col ? ', cat.descricao' : '') . "
        ORDER BY cat.nome
    ")->fetchAll();

    $db_ok = true;
} catch (Throwable) { /* BD indisponível */ }

$page_title = 'Cadastrar categoria';
require_once '../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 48px 48px 100px;
        max-width: 900px;
        margin: 0 auto;
    }

    /* ── Cabeçalho ────────────────────────── */
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
    .btn-back svg   { width: 20px; height: 20px; }

    /* ── Alertas ──────────────────────────── */
    .alert-erros {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 28px;
    }

    .alert-erros p {
        color: #fca5a5;
        font-size: 0.88rem;
        line-height: 1.8;
    }

    .alert-sucesso {
        background-color: #0d2018;
        border: 1px solid #166534;
        border-radius: 12px;
        padding: 14px 20px;
        margin-bottom: 24px;
        color: #bbf7d0;
        font-size: 0.9rem;
    }

    .alert-aviso {
        background-color: #1c1a08;
        border: 1px solid #713f12;
        border-radius: 12px;
        padding: 14px 20px;
        margin-bottom: 24px;
        color: #fde68a;
        font-size: 0.9rem;
    }

    /* ── Campos do formulário ─────────────── */
    .form-group { margin-bottom: 28px; }

    .form-label {
        display: block;
        font-size: 1rem;
        font-weight: 600;
        color: #ffffff;
        margin-bottom: 10px;
    }

    .form-input {
        width: 100%;
        padding: 14px 18px;
        background-color: #1e1e1e;
        border: 1.5px solid #2e2e2e;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s;
    }

    .form-input::placeholder { color: #555; }
    .form-input:focus        { border-color: #555; }

    /* ── Botão principal ──────────────────── */
    .btn-cadastrar {
        display: block;
        width: 100%;
        padding: 18px;
        background-color: #ffffff;
        border: none;
        border-radius: 10px;
        color: #141414;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background-color 0.15s;
        margin-top: 8px;
    }

    .btn-cadastrar:hover { background-color: #e0e0e0; }

    /* ── Divisor ──────────────────────────── */
    .section-divider {
        border: none;
        border-top: 1px solid #2a2a2a;
        margin: 48px 0 36px;
    }

    .section-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 20px;
    }

    /* ── Lista de categorias ──────────────── */
    .cat-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .cat-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 14px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: border-color 0.15s;
    }

    .cat-card:hover { border-color: #3a3a3a; }

    .cat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background-color: #222;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #555;
    }

    .cat-info {
        flex: 1;
        min-width: 0;
    }

    .cat-nome {
        font-size: 0.95rem;
        font-weight: 700;
        color: #ffffff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cat-desc {
        font-size: 0.8rem;
        color: #666;
        margin-top: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cat-badge {
        font-size: 0.78rem;
        color: #777;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .cat-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    .btn-acao {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #aaa;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s, color 0.15s;
    }

    .btn-acao:hover            { background-color: #333; color: #fff; }
    .btn-acao.btn-excluir:hover { background-color: #3d1010; border-color: #7f1d1d; color: #f87171; }
    .btn-acao svg              { width: 15px; height: 15px; }

    /* ── Estado vazio ─────────────────────── */
    .empty-state {
        text-align: center;
        padding: 40px 0;
        color: #555;
        font-size: 0.95rem;
    }

    .db-error {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        color: #aaa;
    }

    .db-error h2 { color: #ef4444; margin-bottom: 8px; }

    /* ══════════════════════════════════════════
       MODAL — EDITAR CATEGORIA
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.75);
        z-index: 200;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.open { display: flex; }

    .modal-box {
        background-color: #1e1e1e;
        border: 1px solid #333;
        border-radius: 20px;
        padding: 36px 40px;
        width: 100%;
        max-width: 500px;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 24px;
    }

    .modal-field { margin-bottom: 18px; }

    .modal-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #cccccc;
        margin-bottom: 8px;
    }

    .modal-input {
        width: 100%;
        padding: 11px 16px;
        background-color: #141414;
        border: 1.5px solid #333;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.9rem;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s;
    }

    .modal-input:focus { border-color: #555; }

    .modal-hint-error {
        font-size: 0.78rem;
        color: #f87171;
        margin-top: 4px;
        display: none;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 28px;
    }

    .btn-cancelar-modal {
        padding: 11px 24px;
        background: none;
        border: 1px solid #444;
        border-radius: 10px;
        color: #aaa;
        font-size: 0.88rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }

    .btn-cancelar-modal:hover { border-color: #888; color: #fff; }

    .btn-salvar {
        padding: 11px 28px;
        background-color: #ffffff;
        border: none;
        border-radius: 10px;
        color: #141414;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background-color 0.15s;
    }

    .btn-salvar:hover { background-color: #e0e0e0; }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 640px) {
        .main       { padding: 32px 20px 80px; }
        .page-title { font-size: 2.2rem; }
        .cat-card   { flex-wrap: wrap; }
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

<!-- ══════════════════ MODAL — EDITAR CATEGORIA ══════════════════ -->
<div class="modal-overlay" id="modalEditar" role="dialog" aria-modal="true" aria-labelledby="modalEditarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalEditarTitulo">Editar categoria</h2>

        <form method="POST" action="./cadastrar_categoria.php" id="formEditar">
            <input type="hidden" name="action"  value="editar">
            <input type="hidden" name="id_cat"  id="editarId"  value="">

            <div class="modal-field">
                <label class="modal-label" for="editarNome">Nome da categoria *</label>
                <input
                    type="text"
                    class="modal-input"
                    id="editarNome"
                    name="nome"
                    maxlength="200"
                    placeholder="Ex: Diodos"
                >
                <p class="modal-hint-error" id="editarNomeErro">Nome obrigatório.</p>
            </div>

            <?php if ($has_descricao_col): ?>
            <div class="modal-field">
                <label class="modal-label" for="editarDescricao">Descrição curta</label>
                <input
                    type="text"
                    class="modal-input"
                    id="editarDescricao"
                    name="descricao"
                    maxlength="300"
                    placeholder="Ex: Permitem a passagem de corrente em apenas um sentido."
                >
            </div>
            <?php endif; ?>

            <div class="modal-actions">
                <button type="button" class="btn-cancelar-modal" onclick="fecharModalEditar()">Cancelar</button>
                <button type="submit" class="btn-salvar">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Cadastrar categoria</h1>
        <a href="./catalogo.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Alertas de feedback -->
    <?php if (!empty($erros)): ?>
    <div class="alert-erros" role="alert">
        <?php foreach ($erros as $e): ?>
            <p>• <?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    $flash = $_GET['cadastro'] ?? $_GET['editado'] ?? $_GET['excluido'] ?? $_GET['erro'] ?? '';
    if ($flash === 'ok' && isset($_GET['cadastro'])): ?>
    <div class="alert-sucesso">Categoria cadastrada com sucesso.</div>
    <?php elseif ($flash === 'ok' && isset($_GET['editado'])): ?>
    <div class="alert-sucesso">Categoria editada com sucesso.</div>
    <?php elseif ($flash === 'ok' && isset($_GET['excluido'])): ?>
    <div class="alert-sucesso">Categoria excluída com sucesso.</div>
    <?php elseif ($flash === 'vinculada'): ?>
    <div class="alert-aviso">
        Não é possível excluir: esta categoria possui <?= (int)($_GET['n'] ?? 0) ?> item(s) vinculado(s). Remova ou mova os itens antes de excluir a categoria.
    </div>
    <?php endif; ?>

    <!-- Formulário de cadastro -->
    <form method="POST" action="./cadastrar_categoria.php" id="formCadastro" novalidate>
        <input type="hidden" name="action" value="cadastrar">

        <div class="form-group">
            <label class="form-label" for="inputNome">Nome da categoria</label>
            <input
                type="text"
                class="form-input"
                id="inputNome"
                name="nome"
                placeholder="Ex: Diodos"
                value="<?= htmlspecialchars($form['nome'] ?? '') ?>"
                maxlength="200"
                autocomplete="off"
            >
        </div>

        <?php if ($has_descricao_col): ?>
        <div class="form-group">
            <label class="form-label" for="inputDescricao">Descrição curta</label>
            <input
                type="text"
                class="form-input"
                id="inputDescricao"
                name="descricao"
                placeholder="Ex: Permitem a passagem de corrente em apenas um sentido."
                value="<?= htmlspecialchars($form['descricao'] ?? '') ?>"
                maxlength="300"
            >
        </div>
        <?php else: ?>
        <!-- Coluna `descricao` não encontrada na tabela Categoria.
             Para habilitá-la, execute no MySQL:
             ALTER TABLE Categoria ADD COLUMN descricao VARCHAR(300) NULL; -->
        <input type="hidden" name="descricao" value="">
        <?php endif; ?>

        <button type="submit" class="btn-cadastrar">Cadastrar categoria</button>
    </form>

    <!-- ══ Lista de categorias existentes ══ -->
    <hr class="section-divider">
    <h2 class="section-title">Categorias cadastradas</h2>

    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar as categorias. Verifique a conexão com o MySQL.</p>
    </div>

    <?php elseif (empty($categorias)): ?>
    <p class="empty-state">Nenhuma categoria cadastrada ainda.</p>

    <?php else: ?>
    <div class="cat-list">
        <?php foreach ($categorias as $cat): ?>
        <div class="cat-card">

            <!-- Ícone -->
            <div class="cat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" width="20" height="20">
                    <rect x="2" y="7" width="20" height="14" rx="2"/>
                    <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                </svg>
            </div>

            <!-- Info -->
            <div class="cat-info">
                <p class="cat-nome"><?= htmlspecialchars($cat['nome']) ?></p>
                <?php if (!empty($cat['descricao'])): ?>
                <p class="cat-desc"><?= htmlspecialchars($cat['descricao']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Total de itens -->
            <span class="cat-badge">
                <?= (int) $cat['total_itens'] ?> item<?= (int) $cat['total_itens'] !== 1 ? 's' : '' ?>
            </span>

            <!-- Ações -->
            <div class="cat-actions">
                <!-- Editar -->
                <button
                    type="button"
                    class="btn-acao"
                    title="Editar categoria"
                    onclick="abrirModalEditar(
                        <?= (int) $cat['id_cat'] ?>,
                        <?= htmlspecialchars(json_encode($cat['nome']), ENT_QUOTES) ?>,
                        <?= htmlspecialchars(json_encode($cat['descricao'] ?? ''), ENT_QUOTES) ?>
                    )"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>

                <!-- Excluir -->
                <form method="POST" action="./cadastrar_categoria.php"
                      onsubmit="return confirmarExclusao(<?= (int) $cat['total_itens'] ?>, '<?= htmlspecialchars(addslashes($cat['nome'])) ?>')">
                    <input type="hidden" name="action"  value="excluir">
                    <input type="hidden" name="id_cat"  value="<?= (int) $cat['id_cat'] ?>">
                    <button type="submit" class="btn-acao btn-excluir" title="Excluir categoria">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6M14 11v6"/>
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                        </svg>
                    </button>
                </form>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<script>
    /* ── User dropdown ───────────────────── */
    function toggleUserDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }

    document.addEventListener('click', function (e) {
        const navUser = document.getElementById('navUser');
        if (navUser && !navUser.contains(e.target)) navUser.classList.remove('open');
    });

    /* ── Confirmação de exclusão ─────────── */
    function confirmarExclusao(totalItens, nome) {
        if (totalItens > 0) {
            alert('Não é possível excluir "' + nome + '": possui ' + totalItens + ' item(s) vinculado(s).');
            return false;
        }
        return confirm('Excluir a categoria "' + nome + '"? Esta ação não pode ser desfeita.');
    }

    /* ── Modal de edição ─────────────────── */
    function abrirModalEditar(id, nome, descricao) {
        document.getElementById('editarId').value   = id;
        document.getElementById('editarNome').value = nome;

        const editarDesc = document.getElementById('editarDescricao');
        if (editarDesc) editarDesc.value = descricao;

        document.getElementById('editarNomeErro').style.display = 'none';
        document.getElementById('modalEditar').classList.add('open');
        setTimeout(function () { document.getElementById('editarNome').focus(); }, 100);
    }

    function fecharModalEditar() {
        document.getElementById('modalEditar').classList.remove('open');
    }

    /* Fecha ao clicar no overlay */
    document.getElementById('modalEditar').addEventListener('click', function (e) {
        if (e.target === this) fecharModalEditar();
    });

    /* Fecha com Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fecharModalEditar();
    });

    /* Validação client-side — cadastro */
    document.getElementById('formCadastro').addEventListener('submit', function (e) {
        const nome = document.getElementById('inputNome').value.trim();
        if (!nome) {
            e.preventDefault();
            document.getElementById('inputNome').focus();
            alert('Nome da categoria é obrigatório.');
        }
    });

    /* Validação client-side — editar */
    document.getElementById('formEditar').addEventListener('submit', function (e) {
        const nome   = document.getElementById('editarNome').value.trim();
        const erroEl = document.getElementById('editarNomeErro');
        if (!nome) {
            e.preventDefault();
            erroEl.style.display = 'block';
            document.getElementById('editarNome').focus();
        } else {
            erroEl.style.display = 'none';
        }
    });
</script>

</body>
</html>
