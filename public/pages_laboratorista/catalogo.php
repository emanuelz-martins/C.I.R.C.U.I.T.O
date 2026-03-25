<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

$upload_dir = __DIR__ . '/../assets/img/componentes/';
$upload_url_base = '/C.I.R.C.U.I.T.O/public/assets/img/componentes/';

/* ══════════════════════════════════════════
   HANDLER DE AÇÕES (POST — PRG pattern)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $id_comp = (int) ($_POST['id_comp'] ?? 0);

    if ($id_comp > 0) {
        try {
            $pdo = db();

            if ($action === 'editar') {
                $nome      = trim($_POST['nome']      ?? '');
                $descricao = trim($_POST['descricao'] ?? '');
                $qtd_disponivel = (int) ($_POST['qtd_disponivel'] ?? 0);
                $imagem_url_nova = null;

                $file = $_FILES['imagem'] ?? null;
                if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ((int)$file['error'] === UPLOAD_ERR_OK && (int)($file['size'] ?? 0) <= 5 * 1024 * 1024) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file((string)($file['tmp_name'] ?? ''));
                        $extPorMime = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
                            'image/webp' => 'webp',
                        ];

                        if (isset($extPorMime[$mime])) {
                            if (!is_dir($upload_dir)) {
                                @mkdir($upload_dir, 0755, true);
                            }

                            if (is_dir($upload_dir) && is_writable($upload_dir)) {
                                $fname = 'comp_' . uniqid('', true) . '.' . $extPorMime[$mime];
                                $destino = $upload_dir . $fname;

                                if (move_uploaded_file((string)$file['tmp_name'], $destino)) {
                                    $imagem_url_nova = $upload_url_base . $fname;
                                }
                            }
                        }
                    }
                }

                if ($nome !== '') {
                    $sql = '
                        UPDATE Componente
                        SET nome = :nome, descricao = :descricao, qtd_disponivel = :qtd_disponivel';

                    $params = [
                        'nome' => $nome,
                        'descricao' => $descricao,
                        'qtd_disponivel' => max(0, $qtd_disponivel),
                        'id' => $id_comp
                    ];

                    if ($imagem_url_nova !== null) {
                        $sql .= ', imagem_url = :imagem_url';
                        $params['imagem_url'] = $imagem_url_nova;
                    }

                    $sql .= ' WHERE id_comp = :id';

                    $pdo->prepare($sql)->execute($params);
                }
            }

            elseif ($action === 'deletar') {
                $pdo->prepare('
                    DELETE FROM Componente
                    WHERE id_comp = :id
                ')->execute(['id' => $id_comp]);
            }

        } catch (Throwable) {
            /* BD indisponível — falha silenciosa */
        }
    }

    header('Location: ./catalogo.php');
    exit;
}

/* ══════════════════════════════════════════
   BUSCA COMPONENTES DO BD
   (mesma estrutura do index.php, sem filtro
    de status_atual — laboratorista vê tudo)
══════════════════════════════════════════ */
$page_title = 'Gerenciar itens';
require_once '../includes/header.php';

$itens  = [];
$db_ok  = false;
$busca    = trim($_GET['q'] ?? '');
$cadastro = $_GET['cadastro'] ?? '';

try {
    $pdo = db();

    $params = [];
    $where  = '';

    if ($busca !== '') {
        $where            = 'WHERE (c.nome LIKE :busca OR cat.nome LIKE :busca2)';
        $params['busca']  = '%' . $busca . '%';
        $params['busca2'] = '%' . $busca . '%';
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id_comp,
            c.nome,
            c.descricao,
            c.qtd_disponivel,
            c.imagem_url,
            c.status_atual,
            cat.nome AS cat_nome
        FROM Componente c
        JOIN Categoria cat ON cat.id_cat = c.id_cat
        {$where}
        ORDER BY cat.nome, c.nome
    ");
    $stmt->execute($params);
    $itens = $stmt->fetchAll();
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
        padding: 48px 48px 80px;
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
    .btn-back svg   { width: 20px; height: 20px; }

    /* ── Toolbar: busca + botão cadastrar ─── */
    .toolbar {
        display: flex;
        gap: 16px;
        align-items: center;
        margin-bottom: 36px;
    }

    .search-wrap {
        flex: 1;
        position: relative;
        max-width: 560px;
    }

    .search-wrap input {
        width: 100%;
        padding: 12px 48px 12px 20px;
        background-color: #1e1e1e;
        border: 1.5px solid #333;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-wrap input::placeholder { color: #666; }
    .search-wrap input:focus        { border-color: #555; }

    .search-wrap button {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #aaa;
        cursor: pointer;
        display: flex;
        align-items: center;
    }

    .btn-cadastrar {
        padding: 12px 28px;
        background-color: #ffffff;
        border: none;
        border-radius: 50px;
        color: #141414;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background-color 0.15s;
    }

    .btn-cadastrar:hover { background-color: #e0e0e0; }

    .btn-cadastrar-sec {
        padding: 12px 28px;
        background-color: transparent;
        border: 1.5px solid #555;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: border-color 0.15s, background-color 0.15s;
    }

    .btn-cadastrar-sec:hover { border-color: #888; background-color: #1e1e1e; }

    /* ── Lista de itens ───────────────────── */
    .itens-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .item-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 16px 24px 16px 16px;
        display: flex;
        align-items: center;
        gap: 20px;
        transition: border-color 0.15s;
    }

    .item-card:hover { border-color: #3a3a3a; }

    /* Thumbnail */
    .item-thumb {
        width: 90px;
        height: 90px;
        border-radius: 10px;
        background-color: #111;
        object-fit: cover;
        flex-shrink: 0;
        display: block;
    }

    .item-thumb-placeholder {
        width: 90px;
        height: 90px;
        border-radius: 10px;
        background-color: #111;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #333;
    }

    /* Info */
    .item-info {
        flex: 1;
        min-width: 0;
    }

    .item-cat {
        font-size: 0.75rem;
        color: #666;
        margin-bottom: 3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .item-nome {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .item-desc {
        font-size: 0.82rem;
        color: #888;
        line-height: 1.5;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* Estoque + status */
    .item-right {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-shrink: 0;
    }

    .item-estoque {
        font-size: 0.9rem;
        font-weight: 600;
        color: #cccccc;
        white-space: nowrap;
    }

    .item-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .item-status-dot.disponivel  { background-color: #4ade80; }
    .item-status-dot.indisponivel { background-color: #f87171; }

    /* Menu de 3 risquinhos */
    .menu-wrap {
        position: relative;
    }

    .btn-menu {
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
        flex-shrink: 0;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: #333; }
    .btn-menu svg   { width: 18px; height: 18px; pointer-events: none; }

    .menu-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 180px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6);
        z-index: 50;
    }

    .menu-dropdown.open { display: block; }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 13px 18px;
        background: none;
        border: none;
        border-bottom: 1px solid #2a2a2a;
        color: #ffffff;
        font-size: 0.88rem;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
        transition: background-color 0.15s;
    }

    .menu-item:last-child { border-bottom: none; }
    .menu-item:hover      { background-color: #2a2a2a; }
    .menu-item svg        { width: 15px; height: 15px; flex-shrink: 0; color: #aaa; }

    /* ── Estados vazios / erro BD ─────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
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
       MODAL — EDITAR ITEM
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

    .modal-field {
        margin-bottom: 18px;
    }

    .modal-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #cccccc;
        margin-bottom: 8px;
    }

    .modal-input,
    .modal-textarea {
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

    .modal-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .modal-input:focus,
    .modal-textarea:focus { border-color: #555; }

    .modal-image-preview {
        width: 100%;
        height: 140px;
        border-radius: 10px;
        border: 1px solid #333;
        background-color: #141414;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .modal-image-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: none;
    }

    .modal-image-preview span {
        color: #777;
        font-size: 0.82rem;
    }

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
    @media (max-width: 860px) {
        .main    { padding: 32px 20px 60px; }
        .toolbar { flex-wrap: wrap; }
        .search-wrap { max-width: 100%; }
        .item-card { flex-wrap: wrap; }
        .item-right { flex-wrap: wrap; }
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

<!-- ══════════════════ MODAL — EDITAR ITEM ══════════════════ -->
<div class="modal-overlay" id="modalEditar" role="dialog" aria-modal="true" aria-labelledby="modalEditarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalEditarTitulo">Editar item</h2>

        <form method="POST" action="./catalogo.php" id="formEditar" enctype="multipart/form-data">
            <input type="hidden" name="action"  value="editar">
            <input type="hidden" name="id_comp" id="editarId" value="">

            <div class="modal-field">
                <label class="modal-label" for="editarNome">Nome do item *</label>
                <input
                    type="text"
                    class="modal-input"
                    id="editarNome"
                    name="nome"
                    maxlength="200"
                    placeholder="Ex.: LED 5mm Vermelho"
                >
                <p class="modal-hint-error" id="editarNomeErro">Nome obrigatório.</p>
            </div>

            <div class="modal-field">
                <label class="modal-label" for="editarDescricao">Descrição</label>
                <textarea
                    class="modal-textarea"
                    id="editarDescricao"
                    name="descricao"
                    maxlength="1000"
                    placeholder="Breve descrição do componente..."
                ></textarea>
            </div>

            <div class="modal-field">
                <label class="modal-label" for="editarQtdDisponivel">Quantidade em estoque</label>
                <input
                    type="number"
                    class="modal-input"
                    id="editarQtdDisponivel"
                    name="qtd_disponivel"
                    min="0"
                    placeholder="Ex.: 15"
                    value="0"
                >
            </div>

            <div class="modal-field">
                <label class="modal-label" for="editarImagem">Foto do item</label>
                <div class="modal-image-preview">
                    <img id="editarImagemPreview" src="" alt="Pré-visualização da imagem">
                    <span id="editarImagemSemPreview">Sem imagem atual</span>
                </div>
                <input
                    type="file"
                    class="modal-input"
                    id="editarImagem"
                    name="imagem"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    onchange="previewImagemEdicao(this)"
                >
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancelar-modal" onclick="fecharModalEditar()">Cancelar</button>
                <button type="submit" class="btn-salvar">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════ MODAL — DELETAR ITEM ══════════════════ -->
<div class="modal-overlay" id="modalDeletar" role="dialog" aria-modal="true" aria-labelledby="modalDeletarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalDeletarTitulo">Deletar item</h2>
        <p style="color: #aaa; margin-bottom: 24px;">
            Tem certeza que deseja remover <strong id="deletarNomeItem" style="color: #fff;"></strong>? Essa ação não pode ser desfeita.
        </p>

        <form method="POST" action="./catalogo.php">
            <input type="hidden" name="action"  value="deletar">
            <input type="hidden" name="id_comp" id="deletarId" value="">

            <div class="modal-actions">
                <button type="button" class="btn-cancelar-modal" onclick="fecharModalDeletar()">Cancelar</button>
                <button type="submit" class="btn-salvar" style="background-color: #f87171; color: #fff;">
                    Confirmar exclusão
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Gerenciar itens</h1>
        <a href="./index.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Toolbar -->
    <form method="GET" action="./catalogo.php">
        <div class="toolbar">
            <div class="search-wrap">
                <input
                    type="text"
                    name="q"
                    placeholder="Pesquisar por categoria, nome do item, etc."
                    value="<?= htmlspecialchars($busca) ?>"
                    autocomplete="off"
                >
                <button type="submit" aria-label="Buscar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </button>
            </div>

            <!-- Botões de cadastro -->
            <a href="./cadastrar_categoria.php" class="btn-cadastrar-sec">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Cadastrar categoria
            </a>
            <a href="./cadastrar_catalogo.php" class="btn-cadastrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Cadastrar item
            </a>
        </div>
    </form>

    <!-- Flash: item cadastrado com sucesso -->
    <?php if ($cadastro === 'ok'): ?>
    <div style="background-color:#0d2018;border:1px solid #166534;border-radius:12px;padding:14px 20px;margin-bottom:24px;color:#bbf7d0;font-size:0.9rem;">
        Item cadastrado com sucesso e adicionado ao catálogo.
    </div>
    <?php endif; ?>

    <!-- Lista de itens -->
    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar o catálogo. Verifique a conexão com o MySQL.</p>
    </div>

    <?php elseif (empty($itens)): ?>
    <p class="empty-state">
        <?= $busca !== '' ? 'Nenhum item encontrado para "' . htmlspecialchars($busca) . '".' : 'Nenhum item cadastrado no catálogo ainda.' ?>
    </p>

    <?php else: ?>
    <div class="itens-list">
        <?php foreach ($itens as $item): ?>
        <div class="item-card">

            <!-- Thumbnail -->
            <?php if (!empty($item['imagem_url'])): ?>
                <img
                    class="item-thumb"
                    src="<?= htmlspecialchars($item['imagem_url']) ?>"
                    alt="<?= htmlspecialchars($item['nome']) ?>"
                >
            <?php else: ?>
                <div class="item-thumb-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1" width="36" height="36">
                        <rect x="7" y="7" width="10" height="10" rx="1"/>
                        <path d="M9 7V4M12 7V4M15 7V4M9 20v-3M12 20v-3M15 20v-3
                                 M4 9h3M4 12h3M4 15h3M17 9h3M17 12h3M17 15h3"/>
                    </svg>
                </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="item-info">
                <p class="item-cat"><?= htmlspecialchars($item['cat_nome']) ?></p>
                <p class="item-nome"><?= htmlspecialchars($item['nome']) ?></p>
            </div>

            <!-- Direita: estoque + status + menu -->
            <div class="item-right">

                <!-- Indicador de disponibilidade -->
                <span
                    class="item-status-dot <?= ($item['status_atual'] ?? '') === 'disponivel' ? 'disponivel' : 'indisponivel' ?>"
                    title="<?= ($item['status_atual'] ?? '') === 'disponivel' ? 'Disponível' : 'Indisponível' ?>"
                ></span>

                <span class="item-estoque">
                    Quantidade em estoque: <?= (int) ($item['qtd_disponivel'] ?? 0) ?>
                </span>

                <!-- Menu de 3 risquinhos -->
                <div class="menu-wrap" id="wrap-<?= (int) $item['id_comp'] ?>">
                    <button
                        class="btn-menu"
                        onclick="toggleMenu(<?= (int) $item['id_comp'] ?>, event)"
                        aria-label="Ações do item"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6"  x2="21" y2="6"/>
                            <line x1="8" y1="12" x2="21" y2="12"/>
                            <line x1="8" y1="18" x2="21" y2="18"/>
                            <line x1="3" y1="6"  x2="3.01" y2="6"/>
                            <line x1="3" y1="12" x2="3.01" y2="12"/>
                            <line x1="3" y1="18" x2="3.01" y2="18"/>
                        </svg>
                    </button>

                    <div class="menu-dropdown" id="menu-<?= (int) $item['id_comp'] ?>">
                        <button
                            type="button"
                            class="menu-item"
                            onclick="abrirModalEditar(
                                <?= (int) $item['id_comp'] ?>,
                                <?= htmlspecialchars(json_encode($item['nome']), ENT_QUOTES) ?>,
                                <?= htmlspecialchars(json_encode($item['descricao'] ?? ''), ENT_QUOTES) ?>,
                                <?= (int) ($item['qtd_disponivel'] ?? 0) ?>,
                                <?= htmlspecialchars(json_encode($item['imagem_url'] ?? ''), ENT_QUOTES) ?>
                            )"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Editar item
                        </button>
                        <button
                            type="button"
                            class="menu-item"
                            onclick="abrirModalDeletar(<?= (int) $item['id_comp'] ?>, <?= htmlspecialchars(json_encode($item['nome']), ENT_QUOTES) ?>)"
                            style="color: #f87171;"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 style="color: #f87171;">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                            </svg>
                            Deletar item
                        </button>
                    </div>
                </div>

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

    /* ── Menus de 3 risquinhos ───────────── */
    let menuAberto = null;

    function toggleMenu(id, e) {
        e.stopPropagation();
        const menu = document.getElementById('menu-' + id);
        if (!menu) return;

        const jaAberto = menu.classList.contains('open');

        if (menuAberto && menuAberto !== menu) {
            menuAberto.classList.remove('open');
        }

        menu.classList.toggle('open', !jaAberto);
        menuAberto = jaAberto ? null : menu;
    }

    document.addEventListener('click', function () {
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });
        menuAberto = null;
    });

    /* ── Modal de edição ─────────────────── */
    function abrirModalEditar(id, nome, descricao, qtd_disponivel, imagem_url) {
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });

        document.getElementById('editarId').value         = id;
        document.getElementById('editarNome').value       = nome;
        document.getElementById('editarDescricao').value  = descricao;
        document.getElementById('editarQtdDisponivel').value = qtd_disponivel;
        document.getElementById('editarImagem').value = '';

        const previewImg = document.getElementById('editarImagemPreview');
        const previewText = document.getElementById('editarImagemSemPreview');
        if (imagem_url) {
            previewImg.src = imagem_url;
            previewImg.style.display = 'block';
            previewText.style.display = 'none';
        } else {
            previewImg.src = '';
            previewImg.style.display = 'none';
            previewText.style.display = 'block';
        }

        document.getElementById('editarNomeErro').style.display = 'none';
        document.getElementById('modalEditar').classList.add('open');
        setTimeout(function () { document.getElementById('editarNome').focus(); }, 100);
    }

    function previewImagemEdicao(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const reader = new FileReader();

        reader.onload = function (e) {
            const previewImg = document.getElementById('editarImagemPreview');
            const previewText = document.getElementById('editarImagemSemPreview');
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            previewText.style.display = 'none';
        };

        reader.readAsDataURL(file);
    }

    function fecharModalEditar() {
        document.getElementById('modalEditar').classList.remove('open');
    }

    function abrirModalDeletar(id, nome) {
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });

        document.getElementById('deletarId').value      = id;
        document.getElementById('deletarNomeItem').textContent = nome;
        document.getElementById('modalDeletar').classList.add('open');
    }

    function fecharModalDeletar() {
        document.getElementById('modalDeletar').classList.remove('open');
    }

    /* Fecha ao clicar no overlay */
    document.getElementById('modalEditar').addEventListener('click', function (e) {
        if (e.target === this) fecharModalEditar();
    });

    document.getElementById('modalDeletar').addEventListener('click', function (e) {
        if (e.target === this) fecharModalDeletar();
    });

    /* Fecha com Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            fecharModalEditar();
            fecharModalDeletar();
        }
    });

    /* Validação client-side */
    document.getElementById('formEditar').addEventListener('submit', function (e) {
        const nome = document.getElementById('editarNome').value.trim();
        const erroEl = document.getElementById('editarNomeErro');
        if (nome === '') {
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
