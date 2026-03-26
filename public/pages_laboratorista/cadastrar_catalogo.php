<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

/* ── Diretório de upload ─────────────────────── */
$upload_dir      = __DIR__ . '/../assets/img/componentes/';
$upload_url_base = '/C.I.R.C.U.I.T.O/public/assets/img/componentes/';
$upload_bootstrap_error = null;

if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
    $upload_bootstrap_error = 'Diretório de upload indisponível no servidor.';
    error_log('[catalogo-upload] Falha ao criar diretório: ' . $upload_dir);
} elseif (is_dir($upload_dir) && !is_writable($upload_dir)) {
    $upload_bootstrap_error = 'Diretório de upload sem permissão de escrita no servidor.';
    error_log('[catalogo-upload] Diretório sem escrita: ' . $upload_dir);
}

function mensagemErroUpload(int $codigo): string
{
    return match ($codigo) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Imagem muito grande para upload.',
        UPLOAD_ERR_PARTIAL => 'Upload incompleto. Tente novamente.',
        UPLOAD_ERR_NO_TMP_DIR => 'Servidor sem diretório temporário para upload.',
        UPLOAD_ERR_CANT_WRITE => 'Servidor sem permissão para gravar o arquivo.',
        UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão do PHP.',
        default => 'Erro ao receber o arquivo de imagem (código ' . $codigo . ').',
    };
}

/* ══════════════════════════════════════════
   HANDLER DE CADASTRO (POST)
══════════════════════════════════════════ */
$erros = [];
$form  = [];   /* preserva valores do form em caso de erro */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome               = trim($_POST['nome']               ?? '');
    $id_cat             = (int) ($_POST['id_cat']           ?? 0);
    $descricao_curta    = trim($_POST['descricao_curta']    ?? '');
    $descricao_completa = trim($_POST['descricao_completa'] ?? '');
    $qtd_disponivel     = (int) ($_POST['qtd_disponivel']   ?? 0);
    $qtd_max_user       = (int) ($_POST['qtd_max_user']     ?? 1);
    $qtd_minima         = (int) ($_POST['qtd_minima']       ?? 0);

    /* Preserva para repopular o form */
    $form = compact('nome', 'id_cat', 'descricao_curta', 'descricao_completa', 'qtd_disponivel', 'qtd_max_user', 'qtd_minima');

    /* ── Validações ── */
    if ($nome === '')      $erros[] = 'Nome do item é obrigatório.';
    if ($id_cat <= 0)      $erros[] = 'Selecione uma categoria.';
    if ($qtd_disponivel < 0) $erros[] = 'Quantidade em estoque não pode ser negativa.';
    if ($qtd_max_user < 0) $erros[] = 'Quantidade máxima por usuário não pode ser negativa.';
    if ($qtd_minima   < 0) $erros[] = 'Quantidade mínima em estoque não pode ser negativa.';
    if (mb_strlen($descricao_curta) > 500) {
        $erros[] = 'Especificações Técnicas devem ter no máximo 500 caracteres.';
    }
    if (mb_strlen($descricao_completa) > 500) {
        $erros[] = 'Descrição completa deve ter no máximo 500 caracteres.';
    }

    /* ── Upload de imagem ── */
    $imagem_url = null;
    $file = $_FILES['imagem'] ?? null;

    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($upload_bootstrap_error !== null) {
            $erros[] = $upload_bootstrap_error;
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $erros[] = mensagemErroUpload((int)$file['error']);
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $erros[] = 'Imagem muito grande. Tamanho máximo: 5 MB.';
        } else {
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $mime          = $finfo->file($file['tmp_name']);
            $mimes_aceitos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mime, $mimes_aceitos, true)) {
                $erros[] = 'Formato inválido. Use JPG, PNG, GIF ou WebP.';
            } else {
                $extPorMime = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                $ext     = $extPorMime[$mime] ?? 'bin';
                $fname   = 'comp_' . uniqid('', true) . '.' . $ext;
                $destino = $upload_dir . $fname;

                if (move_uploaded_file($file['tmp_name'], $destino)) {
                    $imagem_url = $upload_url_base . $fname;
                } else {
                    $erros[] = 'Não foi possível salvar a imagem no servidor. Verifique permissões da pasta de upload.';
                    error_log(sprintf(
                        '[catalogo-upload] move_uploaded_file falhou | tmp="%s" destino="%s" is_uploaded=%s dir_writable=%s',
                        (string)($file['tmp_name'] ?? ''),
                        $destino,
                        is_uploaded_file((string)($file['tmp_name'] ?? '')) ? 'yes' : 'no',
                        is_writable($upload_dir) ? 'yes' : 'no'
                    ));
                }
            }
        }
    }

    /* ── INSERT no BD ── */
    if (empty($erros)) {
        /*
         * O campo `descricao` da tabela Componente serve tanto como
         * descrição curta (primeira linha) quanto como especificações
         * completas (linhas seguintes), conforme lido por item.php.
         * Formato: "[descricao_curta]\n[descricao_completa]"
         */
        $descricao_bd = $descricao_curta;
        if ($descricao_completa !== '') {
            $descricao_bd .= "\n" . str_replace("\r\n", "\n", $descricao_completa);
        }

        try {
            $pdo = db();

            $colsStmt = $pdo->prepare('
                SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ');
            $colsStmt->execute(['table' => 'Componente']);
            $compMetaRows = $colsStmt->fetchAll();
            $compMeta = [];
            foreach ($compMetaRows as $metaRow) {
                $compMeta[$metaRow['COLUMN_NAME']] = $metaRow;
            }
            $compCols = array_keys($compMeta);

            $requiredCols = ['id_cat', 'nome', 'descricao', 'qtd_disponivel'];
            foreach ($requiredCols as $required) {
                if (!in_array($required, $compCols, true)) {
                    throw new RuntimeException('Coluna obrigatória ausente em Componente: ' . $required);
                }
            }

            if (isset($compMeta['descricao'])) {
                $descDataType = strtolower((string) ($compMeta['descricao']['DATA_TYPE'] ?? ''));
                $descMaxLen = $compMeta['descricao']['CHARACTER_MAXIMUM_LENGTH'];
                $descMaxLen = $descMaxLen !== null ? (int) $descMaxLen : null;

                if (in_array($descDataType, ['varchar', 'char'], true)
                    && $descMaxLen !== null
                    && mb_strlen($descricao_bd) > $descMaxLen) {
                    try {
                        $pdo->exec('ALTER TABLE Componente MODIFY COLUMN descricao TEXT NULL');
                    } catch (Throwable) {
                        throw new RuntimeException('Texto excede limite da coluna descrição no banco. Ajuste a coluna Componente.descricao para TEXT.');
                    }
                }
            }

            $fields = ['id_cat', 'nome', 'descricao', 'qtd_disponivel'];
            $values = [':id_cat', ':nome', ':descricao', ':qtd_disponivel'];
            $params = [
                'id_cat' => $id_cat,
                'nome' => $nome,
                'descricao' => $descricao_bd,
                'qtd_disponivel' => $qtd_disponivel,
            ];

            if (in_array('qtd_max_user', $compCols, true)) {
                $fields[] = 'qtd_max_user';
                $values[] = ':qtd_max_user';
                $params['qtd_max_user'] = $qtd_max_user;
            }
            if (in_array('nivel_minimo', $compCols, true)) {
                $fields[] = 'nivel_minimo';
                $values[] = ':nivel_minimo';
                $params['nivel_minimo'] = $qtd_minima;
            }
            if (in_array('imagem_url', $compCols, true)) {
                $fields[] = 'imagem_url';
                $values[] = ':imagem_url';
                $params['imagem_url'] = $imagem_url;
            }
            if (in_array('status_atual', $compCols, true)) {
                $fields[] = 'status_atual';
                $values[] = ':status_atual';
                $params['status_atual'] = $qtd_disponivel > 0 ? 'disponivel' : 'indisponivel';
            }

            $sql = sprintf(
                'INSERT INTO Componente (%s) VALUES (%s)',
                implode(', ', $fields),
                implode(', ', $values)
            );

            $pdo->prepare($sql)->execute($params);

            header('Location: ./catalogo.php?cadastro=ok');
            exit;

        } catch (Throwable $e) {
            error_log('[cadastrar_catalogo] Falha ao inserir componente: ' . $e->getMessage());
            $erros[] = 'Erro ao salvar item no banco de dados. Confira os campos e tente novamente.';
        }
    }
}

/* ── Carrega categorias para o <select> ── */
$categorias = [];
try {
    $pdo = db();
    $categorias = $pdo->query('SELECT id_cat, nome FROM Categoria ORDER BY nome')->fetchAll();
} catch (Throwable) { /* sem categorias */ }

$page_title = 'Cadastrar item';
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

    /* ── Alertas de erro ──────────────────── */
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

    /* ── Aviso sem categorias ─────────────── */
    .aviso-sem-cat {
        background-color: #1c1a08;
        border: 1px solid #713f12;
        border-radius: 10px;
        padding: 12px 18px;
        color: #fde68a;
        font-size: 0.85rem;
        margin-bottom: 28px;
    }

    /* ── Área de upload de imagem ─────────── */
    .upload-area {
        border: 1.5px dashed #3a3a3a;
        border-radius: 16px;
        padding: 48px 20px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s, background-color 0.2s;
        margin-bottom: 28px;
        background-color: #1a1a1a;
        user-select: none;
    }

    .upload-area:hover,
    .upload-area.dragover {
        border-color: #666;
        background-color: #1e1e1e;
    }

    .upload-icon { color: #ffffff; margin-bottom: 12px; }
    .upload-icon svg { width: 36px; height: 36px; }

    .upload-label {
        font-size: 0.95rem;
        font-weight: 600;
        color: #ffffff;
    }

    .upload-hint {
        font-size: 0.78rem;
        color: #666;
        margin-top: 4px;
    }

    /* Preview após selecionar imagem */
    .upload-preview {
        display: none;
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }

    .upload-preview img {
        max-height: 160px;
        max-width: 100%;
        border-radius: 10px;
        object-fit: contain;
    }

    .upload-preview-nome {
        font-size: 0.82rem;
        color: #aaa;
    }

    /* ── Campos do formulário ─────────────── */
    .form-group { margin-bottom: 24px; }

    .form-label {
        display: block;
        font-size: 1rem;
        font-weight: 600;
        color: #ffffff;
        margin-bottom: 10px;
    }

    .form-label .label-hint {
        font-size: 0.78rem;
        font-weight: 400;
        color: #8b8b8b;
        margin-left: 8px;
    }

    .form-input,
    .form-select,
    .form-textarea {
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

    .form-input::placeholder,
    .form-textarea::placeholder { color: #555; }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus { border-color: #555; }

    /* Select com seta customizada */
    .form-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 16px center;
        cursor: pointer;
    }

    .form-select option { background-color: #1e1e1e; }

    .form-textarea {
        min-height: 130px;
        resize: vertical;
        line-height: 1.6;
    }

    /* ── Linha de dois campos ─────────────── */
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }

    .form-row .form-group { margin-bottom: 0; }

    /* ── Botão de cadastro ────────────────── */
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
        margin-top: 36px;
    }

    .btn-cadastrar:hover    { background-color: #e0e0e0; }
    .btn-cadastrar:disabled { opacity: 0.45; cursor: not-allowed; }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 640px) {
        .main       { padding: 32px 20px 80px; }
        .form-row   { grid-template-columns: 1fr; }
        .page-title { font-size: 2.2rem; }
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

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Cadastrar item</h1>
        <a href="./catalogo.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Alertas de erro -->
    <?php if (!empty($erros)): ?>
    <div class="alert-erros" role="alert">
        <?php foreach ($erros as $e): ?>
            <p>• <?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Aviso sem categorias -->
    <?php if (empty($categorias)): ?>
    <div class="aviso-sem-cat">
        Nenhuma categoria encontrada no banco de dados. Cadastre categorias antes de adicionar itens.
    </div>
    <?php endif; ?>

    <!-- Formulário -->
    <form
        method="POST"
        action="./cadastrar_catalogo.php"
        enctype="multipart/form-data"
        id="formCadastro"
        novalidate
    >

        <!-- Upload de imagem -->
        <div class="upload-area" id="uploadArea">
            <input
                type="file"
                name="imagem"
                id="inputImagem"
                accept="image/jpeg,image/png,image/gif,image/webp"
                style="display:none"
                onchange="previewImagem(this)"
            >

            <!-- Estado padrão -->
            <div id="uploadDefault" onclick="document.getElementById('inputImagem').click()">
                <div class="upload-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 16 12 12 8 16"/>
                        <line x1="12" y1="12" x2="12" y2="21"/>
                        <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                    </svg>
                </div>
                <p class="upload-label">Insira uma imagem</p>
                <p class="upload-hint">JPG, PNG, GIF ou WebP · Máximo 5 MB · Clique ou arraste aqui</p>
            </div>

            <!-- Preview após seleção -->
            <div id="uploadPreview" class="upload-preview">
                <img id="previewImg" src="" alt="Preview da imagem">
                <span class="upload-preview-nome" id="previewNome"></span>
                <button
                    type="button"
                    style="background:none;border:none;color:#aaa;font-size:0.82rem;cursor:pointer;margin-top:4px"
                    onclick="limparImagem(event)"
                >Trocar imagem</button>
            </div>
        </div>

        <!-- Nome do item -->
        <div class="form-group">
            <label class="form-label" for="inputNome">Nome do item</label>
            <input
                type="text"
                class="form-input"
                id="inputNome"
                name="nome"
                placeholder="Ex: LED 5mm Vermelho"
                value="<?= htmlspecialchars($form['nome'] ?? '') ?>"
                maxlength="200"
            >
        </div>

        <!-- Categoria -->
        <div class="form-group">
            <label class="form-label" for="inputCat">Categoria</label>
            <select class="form-select" id="inputCat" name="id_cat">
                <option value="" disabled <?= empty($form['id_cat']) ? 'selected' : '' ?>>Selecione a categoria</option>
                <?php foreach ($categorias as $cat): ?>
                    <option
                        value="<?= (int) $cat['id_cat'] ?>"
                        <?= (int)($form['id_cat'] ?? 0) === (int)$cat['id_cat'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($cat['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Descrição curta -->
        <div class="form-group">
            <label class="form-label" for="inputDescCurta">Especificações Técnicas</label>
            <input
                type="text"
                class="form-input"
                id="inputDescCurta"
                name="descricao_curta"
                placeholder="Ex: Diodo emissor de luz para sinalização"
                value="<?= htmlspecialchars($form['descricao_curta'] ?? '') ?>"
                maxlength="500"
            >
        </div>

        <!-- Quantidades lado a lado -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="inputQtdDisponivel">
                    Quantidade em estoque
                    <span class="label-hint">(total atual do item)</span>
                </label>
                <input
                    type="number"
                    class="form-input"
                    id="inputQtdDisponivel"
                    name="qtd_disponivel"
                    min="0"
                    value="<?= (int)($form['qtd_disponivel'] ?? 0) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="inputQtdMax">Quantidade máxima por usuário</label>
                <input
                    type="number"
                    class="form-input"
                    id="inputQtdMax"
                    name="qtd_max_user"
                    placeholder="Ex:"
                    min="0"
                    value="<?= (int)($form['qtd_max_user'] ?? 1) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="inputQtdMin">
                    Quantidade mínima em estoque
                    <span class="label-hint">(limite para aviso de reposição)</span>
                </label>
                <input
                    type="number"
                    class="form-input"
                    id="inputQtdMin"
                    name="qtd_minima"
                    min="0"
                    value="<?= (int)($form['qtd_minima'] ?? 0) ?>"
                >
            </div>
        </div>

        <!-- Descrição completa -->
        <div class="form-group">
            <label class="form-label" for="inputDescCompleta">Descrição completa: Alertas / Observações</label>
            <textarea
                class="form-textarea"
                id="inputDescCompleta"
                name="descricao_completa"
                placeholder="Descreva as especificações do item"
                maxlength="500"
            ><?= htmlspecialchars($form['descricao_completa'] ?? '') ?></textarea>
        </div>

        <!-- Botão de cadastro -->
        <button
            type="submit"
            class="btn-cadastrar"
            id="btnCadastrar"
            <?= empty($categorias) ? 'disabled' : '' ?>
        >
            Cadastrar item
        </button>

    </form>

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

    /* ── Preview de imagem ───────────────── */
    function previewImagem(input) {
        if (!input.files || !input.files[0]) return;
        const file   = input.files[0];
        const reader = new FileReader();

        reader.onload = function (e) {
            document.getElementById('previewImg').src       = e.target.result;
            document.getElementById('previewNome').textContent =
                file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
            document.getElementById('uploadDefault').style.display = 'none';
            document.getElementById('uploadPreview').style.display = 'flex';
        };

        reader.readAsDataURL(file);
    }

    function limparImagem(e) {
        e.stopPropagation();
        const input = document.getElementById('inputImagem');
        input.value = '';
        document.getElementById('uploadDefault').style.display = '';
        document.getElementById('uploadPreview').style.display = 'none';
    }

    /* ── Drag & drop ─────────────────────── */
    (function () {
        const area = document.getElementById('uploadArea');

        area.addEventListener('dragover', function (e) {
            e.preventDefault();
            area.classList.add('dragover');
        });

        area.addEventListener('dragleave', function () {
            area.classList.remove('dragover');
        });

        area.addEventListener('drop', function (e) {
            e.preventDefault();
            area.classList.remove('dragover');
            const dt    = e.dataTransfer;
            const files = dt.files;
            if (!files.length) return;
            const input = document.getElementById('inputImagem');
            /* Cria um DataTransfer para atribuir ao input */
            const newDt = new DataTransfer();
            newDt.items.add(files[0]);
            input.files = newDt.files;
            previewImagem(input);
        });
    })();

    /* ── Validação client-side ───────────── */
    document.getElementById('formCadastro').addEventListener('submit', function (e) {
        const nome   = document.getElementById('inputNome').value.trim();
        const id_cat = document.getElementById('inputCat').value;
        const descCurta = document.getElementById('inputDescCurta').value.trim();
        const descCompleta = document.getElementById('inputDescCompleta').value.trim();
        const erros  = [];

        if (!nome)   erros.push('Nome do item é obrigatório.');
        if (!id_cat) erros.push('Selecione uma categoria.');
        if (descCurta.length > 500) erros.push('Especificações Técnicas devem ter no máximo 500 caracteres.');
        if (descCompleta.length > 500) erros.push('Descrição completa deve ter no máximo 500 caracteres.');

        if (erros.length) {
            e.preventDefault();
            alert(erros.join('\n'));
        }
    });
</script>

</body>
</html>
