<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['admin']);
require_once __DIR__ . '/../../src/services/usuarios.php';

$_SESSION['csrf_perfis'] ??= bin2hex(random_bytes(32));
$perfis = ['estudante' => 'Aluno', 'laboratorista' => 'Laboratorista', 'admin' => 'Administrador'];
$busca = trim((string) ($_GET['q'] ?? ''));
$filtro = (string) ($_GET['perfil'] ?? '');
if (!isset($perfis[$filtro])) {
    $filtro = '';
}
$erro = '';
$sucesso = (string) ($_SESSION['perfis_sucesso'] ?? '');
unset($_SESSION['perfis_sucesso']);
$atorId = (int) $_SESSION['auth_user']['id'];
$pdo = db();
$respostaJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_perfis'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $erro = 'Sua solicitação expirou. Recarregue a página e tente novamente.';
    } else {
        try {
            alterarPerfilUsuario(
                $pdo,
                $atorId,
                (int) ($_POST['id_user'] ?? 0),
                (string) ($_POST['tipo_perfil'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? '')
            );
            if ($respostaJson) {
                $stmt = $pdo->prepare('SELECT id_user, nome, login, matricula, email, forma_login, tipo_perfil, bloqueado FROM Usuario WHERE id_user = :id');
                $stmt->execute(['id' => (int) ($_POST['id_user'] ?? 0)]);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'usuario' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                exit;
            }
            $_SESSION['perfis_sucesso'] = 'Perfil salvo.';
            $query = http_build_query(array_filter(['q' => $busca, 'perfil' => $filtro], static fn ($v) => $v !== ''));
            header('Location: perfis.php' . ($query !== '' ? '?' . $query : ''), true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $erro = $e->getMessage();
        } catch (Throwable $e) {
            http_response_code(500);
            error_log('Falha ao alterar perfil: ' . $e->getMessage());
            $erro = 'Não foi possível salvar o perfil. Tente novamente.';
        }
    }
    if ($respostaJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $erro], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$usuarios = [];
try {
    $where = [];
    $params = [];
    if ($busca !== '') {
        $digitos = preg_replace('/\D/', '', $busca);
        $loginNumerico = is_string($digitos) && strlen($digitos) === 11;
        $where[] = '(nome LIKE :nome OR login LIKE :login OR email LIKE :email'
            . ($loginNumerico ? " OR REPLACE(REPLACE(login, '.', ''), '-', '') = :login_numerico" : '') . ')';
        $params['nome'] = '%' . $busca . '%';
        $params['login'] = '%' . $busca . '%';
        $params['email'] = '%' . $busca . '%';
        if ($loginNumerico) $params['login_numerico'] = $digitos;
    }
    if ($filtro !== '') {
        $where[] = 'tipo_perfil = :perfil';
        $params['perfil'] = $filtro;
    }
    $stmt = $pdo->prepare(
        'SELECT id_user, nome, login, matricula, email, forma_login, tipo_perfil, bloqueado FROM Usuario'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY nome, id_user'
    );
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Falha ao listar perfis: ' . $e->getMessage());
    $erro = 'Não foi possível carregar os usuários. Tente novamente.';
}

$page_title = 'Gerenciar perfis';
require_once __DIR__ . '/../includes/header.php';
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="../assets/css/admin-perfis.css">
</head>
<body>
<!-- ══ NAVBAR ══ -->
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
    function toggleDropdown() { document.getElementById('navUser').classList.toggle('open'); }
    document.addEventListener('click', e => {
        const n = document.getElementById('navUser');
        if (!n.contains(e.target)) n.classList.remove('open');
    });
</script>

<main class="main">
    <nav class="breadcrumb" aria-label="Navegação estrutural">
        <a href="./index.php">Dashboard</a><span>›</span><span>Gerenciar perfis de acesso</span>
    </nav>
    <h1 class="page-title">Gerenciar perfis de acesso</h1>

    <div id="pageFeedback" class="feedback <?= $erro !== '' ? 'error' : 'success' ?>" role="status"><?= $esc($erro !== '' ? $erro : $sucesso) ?></div>

    <div class="search-bar">
        <input type="text" id="searchInput" aria-label="Buscar usuário pelo nome, e-mail ou identificador" placeholder="Buscar usuário pelo nome…" value="<?= $esc($busca) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </div>

    <div class="table-card" id="usersTable" aria-label="Usuários e perfis">
        <div class="table-header"><span>Nome</span><span>Tipo</span><span></span></div>
        <?php foreach ($usuarios as $usuario):
            $id = (int) $usuario['id_user'];
            $tipo = (string) $usuario['tipo_perfil'];
            $badgeTipo = isset($perfis[$tipo]) ? $tipo : 'desconhecido';
        ?>
        <div class="user-row" data-usuario-id="<?= $id ?>" data-login="<?= $esc((string) $usuario['login']) ?>">
            <button type="button" class="user-name user-open" id="abrirPerfil-<?= $id ?>" aria-controls="perfilPanel" aria-expanded="false"><?= $esc((string) $usuario['nome']) ?></button>
            <span><span class="badge badge-<?= $badgeTipo ?>" data-role-badge><?= $esc($perfis[$tipo] ?? 'Não reconhecido') ?></span></span>
            <div class="dot-menu-wrap">
                <button type="button" class="dot-btn" aria-label="Ver dados de <?= $esc((string) $usuario['nome']) ?>" aria-controls="perfilPanel" aria-expanded="false">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="empty-state" id="emptyUsers" <?= $usuarios ? 'hidden' : '' ?>>Nenhum usuário encontrado.</div>
    </div>
    <span id="userCount" class="sr-only" role="status"><?= count($usuarios) ?> usuário(s) encontrado(s)</span>

    <section id="perfilPanel" aria-labelledby="panelTitle" hidden>
        <div class="panel-header">
            <button type="button" class="panel-back-btn" id="closePanel">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                </svg>Voltar
            </button>
            <h2 class="panel-title" id="panelTitle" tabindex="-1">Dados do usuário</h2>
        </div>
        <div class="table-card">
            <div class="perfil-table" role="table" aria-label="Dados do usuário selecionado">
                <div class="perfil-header" role="row">
                    <span role="columnheader">ID</span><span role="columnheader">Nome</span><span role="columnheader">Matrícula</span><span role="columnheader">E-mail</span><span role="columnheader">Forma de Login</span><span role="columnheader">Perfil de acesso</span>
                </div>
                <div class="perfil-row" role="row">
                    <span class="perfil-id" id="detailId" role="cell"></span>
                    <span id="detailName" role="cell"></span>
                    <span id="detailMatricula" role="cell"></span>
                    <span id="detailEmail" role="cell"></span>
                    <span id="detailLoginMethod" role="cell"></span>
                    <span role="cell"><strong class="badge" id="detailRole"></strong></span>
                </div>
            </div>
            <form method="post" id="perfilForm" class="perfil-edit">
                <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_perfis']) ?>">
                <input type="hidden" name="id_user" id="selectedUserId" value="">
                <span class="perfil-note" id="profileNote"></span>
                <label for="newRole">Alterar perfil</label>
                <select name="tipo_perfil" id="newRole" disabled>
                    <option value="" disabled>Selecione um perfil</option>
                    <?php foreach ($perfis as $valor => $rotulo): ?><option value="<?= $valor ?>"><?= $rotulo ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary" id="saveProfile" disabled>Salvar perfil</button>
            </form>
        </div>
        <div class="feedback" id="profileFeedback" role="status"></div>
    </section>
</main>
<script type="application/json" id="perfisData"><?= json_encode(['atorId' => $atorId, 'perfis' => $perfis, 'usuarios' => $usuarios], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="../assets/js/admin-perfis.js" defer></script>
</body>
</html>
