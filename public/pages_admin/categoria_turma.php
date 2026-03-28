<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);

require_once '../../src/config/database.php';

/* ══════════════════════════════════════════
   HANDLER DE AÇÕES (POST — PRG pattern)
══════════════════════════════════════════ */
$erros = [];
$form  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = db();

        /* ── Cadastrar nova turma ── */
        if ($action === 'cadastrar') {
            $nome      = trim($_POST['nome']      ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $form      = compact('nome', 'descricao');

            if ($nome === '') {
                $erros[] = 'Nome da turma é obrigatório.';
            }

            if (empty($erros)) {
                $pdo->prepare('
                    INSERT INTO Turma (nome, descricao) VALUES (:nome, :descricao)
                ')->execute(['nome' => $nome, 'descricao' => ($descricao !== '' ? $descricao : null)]);

                header('Location: ./categoria_turma.php?cadastro=ok');
                exit;
            }

        /* ── Editar turma existente ── */
        } elseif ($action === 'editar') {
            $id_turma  = (int)  ($_POST['id_turma']  ?? 0);
            $nome      = trim($_POST['nome']          ?? '');
            $descricao = trim($_POST['descricao']     ?? '');

            if ($id_turma > 0 && $nome !== '') {
                /* Atualiza também Usuario.turma para manter consistência */
                $old = $pdo->prepare('SELECT nome FROM Turma WHERE id_turma = :id');
                $old->execute(['id' => $id_turma]);
                $nome_antigo = $old->fetchColumn();

                $pdo->prepare('
                    UPDATE Turma SET nome = :nome, descricao = :descricao WHERE id_turma = :id
                ')->execute(['nome' => $nome, 'descricao' => ($descricao !== '' ? $descricao : null), 'id' => $id_turma]);

                if ($nome_antigo && $nome_antigo !== $nome) {
                    $pdo->prepare('UPDATE Usuario SET turma = :novo WHERE turma = :antigo')
                        ->execute(['novo' => $nome, 'antigo' => $nome_antigo]);
                }
            }
            header('Location: ./categoria_turma.php?editado=ok');
            exit;

        /* ── Excluir turma ── */
        } elseif ($action === 'excluir') {
            $id_turma = (int) ($_POST['id_turma'] ?? 0);

            if ($id_turma > 0) {
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) FROM Usuario u
                    JOIN Turma t ON u.turma = t.nome
                    WHERE t.id_turma = :id
                ');
                $stmt->execute(['id' => $id_turma]);
                $total_alunos = (int) $stmt->fetchColumn();

                if ($total_alunos > 0) {
                    header('Location: ./categoria_turma.php?erro=vinculada&n=' . $total_alunos);
                    exit;
                }

                $pdo->prepare('DELETE FROM Turma WHERE id_turma = :id')->execute(['id' => $id_turma]);
            }
            header('Location: ./categoria_turma.php?excluido=ok');
            exit;
        }

    } catch (Throwable $e) {
        $erros[] = 'Erro no banco de dados. Verifique a conexão com o MySQL.';
    }
}

/* ══════════════════════════════════════════
   CARREGA TURMAS DO BD
══════════════════════════════════════════ */
$turmas = [];
$db_ok  = false;

try {
    $pdo = db();

    $turmas = $pdo->query("
        SELECT
            t.id_turma,
            t.nome,
            t.descricao,
            COUNT(u.id_user) AS total_alunos
        FROM Turma t
        LEFT JOIN Usuario u ON u.turma = t.nome AND u.tipo_perfil = 'estudante'
        GROUP BY t.id_turma, t.nome, t.descricao
        ORDER BY t.nome
    ")->fetchAll();

    $db_ok = true;
} catch (Throwable) { /* BD indisponível */ }

$page_title = 'Categorias de turmas';
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

    /* ── Lista de turmas ──────────────────── */
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
       MODAL — EDITAR TURMA
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

<!-- ══════════════════ MODAL — EDITAR TURMA ══════════════════ -->
<div class="modal-overlay" id="modalEditar" role="dialog" aria-modal="true" aria-labelledby="modalEditarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalEditarTitulo">Editar turma</h2>

        <form method="POST" action="./categoria_turma.php" id="formEditar">
            <input type="hidden" name="action"   value="editar">
            <input type="hidden" name="id_turma" id="editarId"  value="">

            <div class="modal-field">
                <label class="modal-label" for="editarNome">Nome da turma *</label>
                <input
                    type="text"
                    class="modal-input"
                    id="editarNome"
                    name="nome"
                    maxlength="100"
                    placeholder="Ex: Turma 11A"
                >
                <p class="modal-hint-error" id="editarNomeErro">Nome obrigatório.</p>
            </div>

            <div class="modal-field">
                <label class="modal-label" for="editarDescricao">Descrição</label>
                <input
                    type="text"
                    class="modal-input"
                    id="editarDescricao"
                    name="descricao"
                    maxlength="300"
                    placeholder="Ex: Turma do período noturno de 2025."
                >
            </div>

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
        <h1 class="page-title">Categorias de turmas</h1>
        <a href="./index.php" class="btn-back" aria-label="Voltar">
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
    <div class="alert-sucesso">Turma cadastrada com sucesso.</div>
    <?php elseif ($flash === 'ok' && isset($_GET['editado'])): ?>
    <div class="alert-sucesso">Turma editada com sucesso.</div>
    <?php elseif ($flash === 'ok' && isset($_GET['excluido'])): ?>
    <div class="alert-sucesso">Turma excluída com sucesso.</div>
    <?php elseif ($flash === 'vinculada'): ?>
    <div class="alert-aviso">
        Não é possível excluir: esta turma possui <?= (int)($_GET['n'] ?? 0) ?> aluno(s) vinculado(s). Remova os alunos desta turma antes de excluí-la.
    </div>
    <?php endif; ?>

    <!-- Formulário de cadastro -->
    <form method="POST" action="./categoria_turma.php" id="formCadastro" novalidate>
        <input type="hidden" name="action" value="cadastrar">

        <div class="form-group">
            <label class="form-label" for="inputNome">Nome da turma</label>
            <input
                type="text"
                class="form-input"
                id="inputNome"
                name="nome"
                placeholder="Ex: Turma 11A"
                value="<?= htmlspecialchars($form['nome'] ?? '') ?>"
                maxlength="100"
                autocomplete="off"
            >
        </div>

        <div class="form-group">
            <label class="form-label" for="inputDescricao">Descrição</label>
            <input
                type="text"
                class="form-input"
                id="inputDescricao"
                name="descricao"
                placeholder="Ex: Turma do período noturno de 2025."
                value="<?= htmlspecialchars($form['descricao'] ?? '') ?>"
                maxlength="300"
            >
        </div>

        <button type="submit" class="btn-cadastrar">Cadastrar turma</button>
    </form>

    <!-- ══ Lista de turmas existentes ══ -->
    <hr class="section-divider">
    <h2 class="section-title">Turmas cadastradas</h2>

    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar as turmas. Verifique a conexão com o MySQL.</p>
    </div>

    <?php elseif (empty($turmas)): ?>
    <p class="empty-state">Nenhuma turma cadastrada ainda.</p>

    <?php else: ?>
    <div class="cat-list">
        <?php foreach ($turmas as $t): ?>
        <div class="cat-card">

            <!-- Ícone -->
            <div class="cat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" width="20" height="20">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>

            <!-- Info -->
            <div class="cat-info">
                <p class="cat-nome"><?= htmlspecialchars($t['nome']) ?></p>
                <?php if (!empty($t['descricao'])): ?>
                <p class="cat-desc"><?= htmlspecialchars($t['descricao']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Total de alunos -->
            <span class="cat-badge">
                <?= (int) $t['total_alunos'] ?> aluno<?= (int) $t['total_alunos'] !== 1 ? 's' : '' ?>
            </span>

            <!-- Ações -->
            <div class="cat-actions">
                <!-- Editar -->
                <button
                    type="button"
                    class="btn-acao"
                    title="Editar turma"
                    onclick="abrirModalEditar(
                        <?= (int) $t['id_turma'] ?>,
                        <?= htmlspecialchars(json_encode($t['nome']), ENT_QUOTES) ?>,
                        <?= htmlspecialchars(json_encode($t['descricao'] ?? ''), ENT_QUOTES) ?>
                    )"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>

                <!-- Excluir -->
                <form method="POST" action="./categoria_turma.php"
                      onsubmit="return confirmarExclusao(<?= (int) $t['total_alunos'] ?>, '<?= htmlspecialchars(addslashes($t['nome'])) ?>')">
                    <input type="hidden" name="action"   value="excluir">
                    <input type="hidden" name="id_turma" value="<?= (int) $t['id_turma'] ?>">
                    <button type="submit" class="btn-acao btn-excluir" title="Excluir turma">
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
    function confirmarExclusao(totalAlunos, nome) {
        if (totalAlunos > 0) {
            alert('Não é possível excluir "' + nome + '": possui ' + totalAlunos + ' aluno(s) vinculado(s).');
            return false;
        }
        return confirm('Excluir a turma "' + nome + '"? Esta ação não pode ser desfeita.');
    }

    /* ── Modal de edição ─────────────────── */
    function abrirModalEditar(id, nome, descricao) {
        document.getElementById('editarId').value        = id;
        document.getElementById('editarNome').value      = nome;
        document.getElementById('editarDescricao').value = descricao || '';
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
            alert('Nome da turma é obrigatório.');
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
