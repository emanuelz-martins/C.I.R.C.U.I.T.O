<?php
require_once '../includes/auth_check.php';
checkAccess(['admin']);
require_once '../../src/config/database.php';
require_once '../includes/pre_bloqueio_aluno.php';

/* ── Ações AJAX ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $pdo     = db();
        $id_user = (int) ($_POST['id_user'] ?? 0);
        $authUser = $_SESSION['auth_user'] ?? [];
        $atorNome = trim((string) ($authUser['nome'] ?? 'Usuário'));
        $atorPerfil = (string) ($authUser['perfil'] ?? $authUser['tipo_perfil'] ?? 'admin');
        $atorLabel = $atorPerfil === 'admin' ? 'Administrador' : 'Laboratorista';

        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueio_motivo TEXT NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_nome VARCHAR(150) NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_tipo VARCHAR(30) NULL');
        $pdo->exec('ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_em DATETIME NULL');

        if (!$id_user) {
            echo json_encode(['ok' => false, 'error' => 'ID de usuário inválido']);
            exit;
        }

        /* ── Bloquear / Desbloquear ── */
        if ($_POST['action'] === 'toggle_block') {
            $stmtUser = $pdo->prepare('SELECT nome, bloqueado, pre_bloqueado_manual, pre_bloqueado_por_nome, pre_bloqueado_por_tipo FROM Usuario WHERE id_user = :id LIMIT 1');
            $stmtUser->execute(['id' => $id_user]);
            $userRow = $stmtUser->fetch();

            if (!$userRow) {
                echo json_encode(['ok' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $nomeAluno = (string) ($userRow['nome'] ?? 'Usuário');
            $bloqueadoAtual = (int) ($userRow['bloqueado'] ?? 0);
            $preManualAtivo = (int) ($userRow['pre_bloqueado_manual'] ?? 0) === 1;
            $preManualTipoRaw = mb_strtolower(trim((string) ($userRow['pre_bloqueado_por_tipo'] ?? '')));
            $preManualTipo = $preManualTipoRaw === 'admin' ? 'Adm.' : 'Lab.';
            $preManualNome = trim((string) ($userRow['pre_bloqueado_por_nome'] ?? ''));
            if ($preManualNome === '') $preManualNome = 'Usuário';
            $preManualLabel = 'Pré-bloqueado, por ' . $preManualTipo . ': ' . $preManualNome;

            if ($bloqueadoAtual === 1) {
                $pedidoColsStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
                $pedidoColsStmt->execute(['t' => 'Pedido']);
                $pedidoCols = $pedidoColsStmt->fetchAll(PDO::FETCH_COLUMN);

                $statusCol = in_array('status_pedido', $pedidoCols, true)
                    ? 'status_pedido'
                    : (in_array('status', $pedidoCols, true) ? 'status' : null);

                if ($statusCol !== null) {
                    $stmtPendente = $pdo->prepare('SELECT 1 FROM Pedido WHERE id_user = :id_user AND ' . $statusCol . ' IN ("em-andamento", "em-atraso") LIMIT 1');
                    $stmtPendente->execute(['id_user' => $id_user]);
                    $temPacotePendente = (bool) $stmtPendente->fetchColumn();

                    if ($temPacotePendente) {
                        echo json_encode(['ok' => false, 'error' => 'Não foi possivel desbloquear, motivo: Usuario ainda não devolveu o pacote']);
                        exit;
                    }
                }

                $pdo->prepare('UPDATE Usuario SET bloqueado = 0 WHERE id_user = :id')
                    ->execute(['id' => $id_user]);

                $mensagemNotif = sprintf(
                    'Prezado, %s. Seu bloqueio foi removido pelo %s: %s.',
                    $nomeAluno,
                    $atorLabel,
                    $atorNome
                );

                $notCols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
                $notCols->execute(['t' => 'Notificacao']);
                $cols = $notCols->fetchAll(PDO::FETCH_COLUMN);

                if (in_array('humor', $cols, true)) {
                    $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor) VALUES (:u, :t, :m, :tp, :h)')
                        ->execute(['u' => $id_user, 't' => 'Desbloqueio realizado', 'm' => $mensagemNotif, 'tp' => 'aviso', 'h' => 'neutro']);
                } else {
                    $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo) VALUES (:u, :t, :m, :tp)')
                        ->execute(['u' => $id_user, 't' => 'Desbloqueio realizado', 'm' => $mensagemNotif, 'tp' => 'aviso']);
                }

                $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
                echo json_encode([
                    'ok' => true,
                    'bloqueado' => 0,
                    'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                    'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                    'pre_manual_ativo' => $preManualAtivo,
                    'pre_manual_label' => $preManualLabel,
                ]);
                exit;
            }

            $pdo->prepare('UPDATE Usuario SET bloqueado = 1 WHERE id_user = :id')
                ->execute(['id' => $id_user]);

            $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
            echo json_encode([
                'ok' => true,
                'bloqueado' => 1,
                'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                'pre_manual_ativo' => $preManualAtivo,
                'pre_manual_label' => $preManualLabel,
            ]);
            exit;
        }

        if ($_POST['action'] === 'toggle_preblock') {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));

            $stmtStatus = $pdo->prepare('SELECT nome, pre_bloqueado_manual FROM Usuario WHERE id_user = :id LIMIT 1');
            $stmtStatus->execute(['id' => $id_user]);
            $row = $stmtStatus->fetch();

            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $jaPreBloqueado = (int) ($row['pre_bloqueado_manual'] ?? 0) === 1;
            $nomeAluno = (string) ($row['nome'] ?? 'Usuário');

            if (!$jaPreBloqueado && $motivo === '') {
                echo json_encode(['ok' => false, 'error' => 'Informe o motivo do pré-bloqueio.']);
                exit;
            }

            if ($jaPreBloqueado) {
                $pdo->prepare('UPDATE Usuario SET pre_bloqueado_manual = 0, pre_bloqueio_motivo = NULL, pre_bloqueado_por_nome = NULL, pre_bloqueado_por_tipo = NULL, pre_bloqueado_em = NULL WHERE id_user = :id')
                    ->execute(['id' => $id_user]);

                $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
                echo json_encode([
                    'ok' => true,
                    'pre_bloqueado' => 0,
                    'motivo' => '',
                    'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                    'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                    'pre_manual_ativo' => false,
                    'pre_manual_label' => '',
                ]);
                exit;
            }

            $pdo->prepare('UPDATE Usuario SET pre_bloqueado_manual = 1, pre_bloqueio_motivo = :motivo, pre_bloqueado_por_nome = :ator_nome, pre_bloqueado_por_tipo = :ator_tipo, pre_bloqueado_em = NOW() WHERE id_user = :id')
                ->execute([
                    'motivo' => $motivo,
                    'ator_nome' => $atorNome,
                    'ator_tipo' => $atorPerfil,
                    'id' => $id_user,
                ]);

            $mensagemNotif = sprintf(
                'Prezado, %s. Você foi pré-bloqueado pelo %s: %s.',
                $nomeAluno,
                $atorLabel,
                $atorNome
            );
            $atorTipoLabel = $atorPerfil === 'admin' ? 'Adm.' : 'Lab.';
            $preManualLabel = 'Pré-bloqueado, por ' . $atorTipoLabel . ': ' . $atorNome;

            $notCols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $notCols->execute(['t' => 'Notificacao']);
            $cols = $notCols->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('humor', $cols, true)) {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor) VALUES (:u, :t, :m, :tp, :h)')
                    ->execute(['u' => $id_user, 't' => 'Pré-bloqueio aplicado', 'm' => $mensagemNotif, 'tp' => 'aviso', 'h' => 'neutro']);
            } else {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo) VALUES (:u, :t, :m, :tp)')
                    ->execute(['u' => $id_user, 't' => 'Pré-bloqueio aplicado', 'm' => $mensagemNotif, 'tp' => 'aviso']);
            }

            $statusAuto = aluno_pre_bloqueio_status($pdo, $id_user);
            echo json_encode([
                'ok' => true,
                'pre_bloqueado' => 1,
                'motivo' => $motivo,
                'pre_bloqueado_auto' => (($statusAuto['pre_bloqueado'] ?? false) === true),
                'motivo_auto' => (string) ($statusAuto['motivo'] ?? ''),
                'pre_manual_ativo' => true,
                'pre_manual_label' => $preManualLabel,
            ]);
            exit;
        }

        /* ── Mandar aviso ── */
        if ($_POST['action'] === 'send_notice') {
            $titulo   = trim($_POST['titulo']   ?? '');
            $mensagem = trim($_POST['mensagem'] ?? '');
            $humor    = trim($_POST['humor']    ?? '');

            if (!in_array($humor, ['feliz', 'triste', 'neutro'], true)) $humor = '';

            if ($titulo === '') $titulo = 'Aviso do Administrador';
            if ($mensagem === '') {
                echo json_encode(['ok' => false, 'error' => 'A mensagem não pode estar vazia.']);
                exit;
            }

            $remetente        = 'Admin ' . ($_SESSION['auth_user']['nome'] ?? 'Administrador');
            $mensagemFormatada = $remetente . ' te enviou: ' . $mensagem;

            /* verifica se coluna humor existe */
            $notCols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $notCols->execute(['t' => 'Notificacao']);
            $cols = $notCols->fetchAll(PDO::FETCH_COLUMN);

            if ($humor !== '' && in_array('humor', $cols, true)) {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, humor) VALUES (:u, :t, :m, :tp, :h)')
                    ->execute(['u' => $id_user, 't' => $titulo, 'm' => $mensagemFormatada, 'tp' => 'aviso', 'h' => $humor]);
            } else {
                $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo) VALUES (:u, :t, :m, :tp)')
                    ->execute(['u' => $id_user, 't' => $titulo, 'm' => $mensagemFormatada, 'tp' => 'aviso']);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        /* ── Editar usuário ── */
        if ($_POST['action'] === 'edit_user') {
            $nome      = trim($_POST['nome']      ?? '');
            $login     = trim($_POST['login']     ?? '');
            $email     = trim($_POST['email']     ?? '');
            $turma     = trim($_POST['turma']     ?? '');
            $descricao = trim($_POST['descricao'] ?? '');

            if ($nome === '' || $login === '') {
                echo json_encode(['ok' => false, 'error' => 'Nome e login são obrigatórios.']);
                exit;
            }

            /* verifica quais colunas existem em Usuario */
            $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $colStmt->execute(['t' => 'Usuario']);
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

            $appUrlPath = (string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH);
            $appUrlPath = rtrim($appUrlPath, '/');
            $publicUrlBase = $appUrlPath !== '' ? $appUrlPath : '';

            /* upload de foto */
            $novaFoto = null;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $finfo        = new finfo(FILEINFO_MIME_TYPE);
                $mime         = $finfo->file($_FILES['foto']['tmp_name']);

                if (!in_array($mime, $allowedMimes, true)) {
                    echo json_encode(['ok' => false, 'error' => 'Tipo de imagem inválido.']);
                    exit;
                }

                if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
                    echo json_encode(['ok' => false, 'error' => 'A imagem deve ter no máximo 5MB.']);
                    exit;
                }

                $extMap = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];
                $ext       = $extMap[$mime];
                $filename  = 'user_' . $id_user . '_' . uniqid() . '.' . $ext;
                $uploadDir = __DIR__ . '/../assets/img/perfil/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $destPath  = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['foto']['tmp_name'], $destPath)) {
                    echo json_encode(['ok' => false, 'error' => 'Falha ao salvar a imagem.']);
                    exit;
                }

                /* apaga foto antiga */
                if (in_array('foto_perfil', $userCols, true)) {
                    $stmtFoto = $pdo->prepare('SELECT foto_perfil FROM Usuario WHERE id_user = :id');
                    $stmtFoto->execute(['id' => $id_user]);
                    $fotoAntiga = $stmtFoto->fetchColumn();
                    if ($fotoAntiga) {
                        $fotoAntigaPath = ltrim((string) $fotoAntiga, '/');
                        if (substr($fotoAntigaPath, 0, 21) === 'C.I.R.C.U.I.T.O/public/') {
                            $fotoAntigaPath = substr($fotoAntigaPath, 22);
                        }
                        if (substr($fotoAntigaPath, 0, 7) === 'public/') {
                            $fotoAntigaPath = substr($fotoAntigaPath, 7);
                        }
                        $oldPath = dirname(__DIR__) . '/' . $fotoAntigaPath;
                        if (is_file($oldPath)) @unlink($oldPath);
                    }
                }

                $novaFoto = $publicUrlBase . '/assets/img/perfil/' . $filename;
            }

            /* monta UPDATE dinâmico */
            $setParts = ['nome = :nome', 'login = :login'];
            $params   = ['nome' => $nome, 'login' => $login, 'id' => $id_user];

            if (in_array('email', $userCols, true)) {
                $setParts[]      = 'email = :email';
                $params['email'] = $email;
            }
            if (in_array('turma', $userCols, true)) {
                $setParts[]      = 'turma = :turma';
                $params['turma'] = $turma;
            }
            if (in_array('descricao', $userCols, true)) {
                $setParts[]          = 'descricao = :descricao';
                $params['descricao'] = $descricao;
            }
            if ($novaFoto !== null && in_array('foto_perfil', $userCols, true)) {
                $setParts[]             = 'foto_perfil = :foto_perfil';
                $params['foto_perfil']  = $novaFoto;
            }

            $sql = 'UPDATE Usuario SET ' . implode(', ', $setParts) . ' WHERE id_user = :id';
            $pdo->prepare($sql)->execute($params);

            $responseData = ['ok' => true];
            if ($novaFoto !== null) $responseData['foto'] = $novaFoto;

            echo json_encode($responseData);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Ação desconhecida']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Carrega alunos ──────────────────────────────────────────── */
$usuarios = [];
$db_ok    = false;
$search   = trim($_GET['q'] ?? '');
$preBloqueioAutoMap = [];

try {
    $pdo = db();

    /* verifica quais colunas existem */
    $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $colStmt->execute(['t' => 'Usuario']);
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    $selectCols = ['id_user', 'nome', 'login', 'bloqueado', 'foto_perfil'];
    foreach (['matricula', 'email', 'turma', 'descricao', 'pre_bloqueado_manual', 'pre_bloqueio_motivo', 'pre_bloqueado_por_nome', 'pre_bloqueado_por_tipo'] as $col) {
        if (in_array($col, $userCols, true)) $selectCols[] = $col;
    }

    $sql    = 'SELECT ' . implode(', ', $selectCols) . " FROM Usuario WHERE tipo_perfil = 'estudante'";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (nome LIKE :q OR login LIKE :q';
        if (in_array('matricula', $userCols, true)) $sql .= ' OR matricula LIKE :q';
        $sql .= ')';
        $params['q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nome ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

    foreach ($usuarios as $usr) {
        $uid = (int) ($usr['id_user'] ?? 0);
        if ($uid <= 0) continue;

        try {
            $st = aluno_pre_bloqueio_status($pdo, $uid);
            $preBloqueioAutoMap[$uid] = [
                'pre_bloqueado' => (($st['pre_bloqueado'] ?? false) === true),
                'motivo' => (string) ($st['motivo'] ?? ''),
            ];
        } catch (Throwable) {
            $preBloqueioAutoMap[$uid] = [
                'pre_bloqueado' => false,
                'motivo' => '',
            ];
        }
    }

    $db_ok    = true;
} catch (Throwable) { /* BD indisponível */ }

/* ── Carrega turmas para o select ── */
$turmas_lista = [];
try {
    $pdo = db();
    $turmas_lista = $pdo->query('SELECT id_turma, nome FROM Turma ORDER BY nome')->fetchAll();
} catch (Throwable) {}

$page_title = 'Gerenciar Alunos';
require_once '../includes/header.php';
?>

<style>
    .main {
        padding: 40px 40px 80px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* ── Cabeçalho ──────────────────────────────────────── */
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

    /* ── Barra de busca + filtro ─────────────────────── */
    .toolbar {
        display: flex;
        gap: 16px;
        margin-bottom: 28px;
        align-items: center;
    }

    .search-wrap {
        flex: 1;
        position: relative;
    }

    .search-input {
        width: 100%;
        padding: 14px 50px 14px 20px;
        background-color: #1e1e1e;
        border: 1.5px solid #2e2e2e;
        border-radius: 12px;
        color: #ffffff;
        font-size: 0.95rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-input::placeholder { color: #555; }
    .search-input:focus { border-color: #555; }

    .search-btn {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #aaa;
        cursor: pointer;
        display: flex;
        align-items: center;
        padding: 0;
    }

    .search-btn svg { width: 20px; height: 20px; }

    .btn-filter {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 14px 24px;
        background-color: #ffffff;
        color: #000000;
        border: none;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filter:hover { background-color: #e5e5e5; }
    .btn-filter svg   { width: 18px; height: 18px; }

    /* ── Lista de usuários ──────────────────────────── */
    .user-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .user-card {
        background-color: #1e1e1e;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: border-color 0.15s;
    }

    .user-card.bloqueado  { border-color: #7f1d1d; }
    .user-card.incompleto { border-color: #7f1d1d; }
    .user-card.prebloqueado {
        border-color: #7f1d1d;
        background: linear-gradient(180deg, rgba(127, 29, 29, 0.18) 0%, rgba(30, 30, 30, 1) 70%);
    }

    .user-avatar {
        width: 46px;
        height: 46px;
        border-radius: 10px;
        background-color: #2a2a2a;
        flex-shrink: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-avatar svg {
        width: 26px;
        height: 26px;
        color: #555;
    }

    .user-info { flex: 1; min-width: 0; }

    .user-nome {
        font-size: 1.05rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .user-sub { font-size: 0.85rem; color: #888; }

    .user-warning {
        font-size: 0.78rem;
        color: #ef4444;
        margin-top: 3px;
    }

    .badge-bloqueado {
        font-size: 0.75rem;
        font-weight: 700;
        background-color: #7f1d1d;
        color: #fca5a5;
        padding: 3px 10px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .badge-prebloqueado {
        font-size: 0.75rem;
        font-weight: 700;
        background-color: #451a1a;
        color: #fca5a5;
        padding: 3px 10px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
        margin-right: 8px;
    }

    /* ── Botão de menu (3 riscos) ───────────────────── */
    .btn-menu {
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
        flex-shrink: 0;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: #333; }
    .btn-menu svg   { width: 20px; height: 20px; }

    /* ── Dropdown de ações ──────────────────────────── */
    .menu-wrap { position: relative; }

    .action-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 200px;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        z-index: 50;
    }

    .menu-wrap.open .action-dropdown { display: block; }

    .action-dropdown button {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 14px 18px;
        background: none;
        border: none;
        border-bottom: 1px solid #2a2a2a;
        color: #ffffff;
        font-size: 0.9rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
    }

    .action-dropdown button:last-child { border-bottom: none; }
    .action-dropdown button:hover      { background-color: #2a2a2a; }

    .action-dropdown button svg {
        width: 17px;
        height: 17px;
        flex-shrink: 0;
        color: #aaa;
    }

    .action-dropdown button.danger      { color: #ef4444; }
    .action-dropdown button.danger  svg { color: #ef4444; }
    .action-dropdown button.unblock     { color: #4ade80; }
    .action-dropdown button.unblock svg { color: #4ade80; }

    /* ── Estado vazio ───────────────────────────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
    }

    /* ══════════════════════════════════════════════════
       MODAIS
    ══════════════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.75);
        z-index: 200;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.open { display: flex; }

    .modal {
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 20px;
        padding: 36px;
        width: 100%;
        max-width: 520px;
        display: flex;
        flex-direction: column;
        gap: 20px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #ffffff;
    }

    .modal-recipient {
        font-size: 0.9rem;
        color: #888;
        margin-top: -12px;
    }

    .modal-recipient span {
        color: #ffffff;
        font-weight: 600;
    }

    .modal-sender {
        font-size: 0.85rem;
        color: #666;
        margin-top: -12px;
    }

    .modal-sender span {
        color: #aaa;
        font-weight: 600;
    }

    .modal label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #aaa;
        margin-bottom: 8px;
    }

    .modal input,
    .modal textarea {
        width: 100%;
        background-color: #141414;
        border: 1.5px solid #2e2e2e;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        padding: 12px 16px;
        transition: border-color 0.2s;
        resize: vertical;
    }

    .modal input::placeholder,
    .modal textarea::placeholder { color: #444; }

    .modal input:focus,
    .modal textarea:focus { border-color: #555; }

    .modal textarea { min-height: 120px; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancel {
        padding: 12px 24px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-cancel:hover { background-color: #333; }

    .btn-send {
        padding: 12px 24px;
        background-color: #ffffff;
        border: none;
        border-radius: 10px;
        color: #000000;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-send:hover    { background-color: #e5e5e5; }
    .btn-send:disabled { opacity: 0.5; cursor: not-allowed; }

    /* ── Toast ──────────────────────────────────────── */
    .toast {
        position: fixed;
        bottom: 28px;
        left: 50%;
        transform: translateX(-50%) translateY(60px);
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        padding: 14px 24px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        z-index: 300;
        transition: transform 0.3s ease, opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
        white-space: nowrap;
    }

    .toast.show    { transform: translateX(-50%) translateY(0); opacity: 1; }
    .toast.success { border-color: #166534; color: #4ade80; }
    .toast.error   { border-color: #7f1d1d; color: #f87171; }

    /* ── Seletor de rosto ───────────────────── */
    .face-picker {
        display: flex;
        gap: 10px;
        margin-top: 4px;
    }

    .face-btn {
        flex: 1;
        padding: 10px 6px;
        background-color: #141414;
        border: 1.5px solid #2e2e2e;
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        transition: border-color 0.15s, background-color 0.15s;
        color: #aaa;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .face-btn svg { width: 32px; height: 32px; }

    .face-btn:hover { background-color: #1e1e1e; }

    .face-btn[data-humor="feliz"].selected  { border-color: #4ade80; background-color: #0d2b16; color: #4ade80; }
    .face-btn[data-humor="triste"].selected { border-color: #ef4444; background-color: #2b0d0d; color: #ef4444; }
    .face-btn[data-humor="neutro"].selected { border-color: #f59e0b; background-color: #2b200d; color: #f59e0b; }

    /* ── Preview de foto ────────────────────── */
    .foto-preview-wrap {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .foto-preview {
        width: 60px;
        height: 60px;
        border-radius: 10px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .foto-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .foto-preview svg {
        width: 28px;
        height: 28px;
        color: #555;
    }

    .btn-foto-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.15s;
    }

    .btn-foto-label:hover { background-color: #333; }
    .btn-foto-label svg   { width: 16px; height: 16px; color: #aaa; }

    #editFotoInput { display: none; }
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
        const n = document.getElementById('navUser');
        if (!n.contains(e.target)) n.classList.remove('open');
    });
</script>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <div class="page-header">
        <h1 class="page-title">Gerenciar Alunos</h1>
        <a href="./index.php" class="btn-back" aria-label="Voltar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <!-- Barra de busca -->
    <form class="toolbar" method="GET" action="">
        <div class="search-wrap">
            <input
                class="search-input"
                type="text"
                name="q"
                placeholder="Pesquisar por aluno"
                value="<?= htmlspecialchars($search) ?>"
            >
            <button type="submit" class="search-btn" aria-label="Buscar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </button>
        </div>

        <button type="button" class="btn-filter">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
            </svg>
            Filtrar
        </button>
    </form>

    <?php if (!$db_ok): ?>
    <div style="background-color:#1c1c1c;border:1px solid #3a1a1a;border-radius:16px;padding:40px;text-align:center;color:#aaa;">
        <h2 style="color:#ef4444;margin-bottom:8px;">Banco de dados indisponível</h2>
        <p>Não foi possível carregar os alunos. Verifique a conexão com o MySQL.</p>
    </div>
    <?php elseif (empty($usuarios)): ?>
        <p class="empty-state">Nenhum aluno encontrado<?= $search !== '' ? ' para "' . htmlspecialchars($search) . '"' : '' ?>.</p>
    <?php else: ?>

    <div class="user-list">
        <?php foreach ($usuarios as $u): ?>
        <?php
            $emailVal     = $u['email']     ?? '';
            $turmaVal     = $u['turma']     ?? '';
            $descricaoVal = $u['descricao'] ?? '';
            $incompleto   = ($emailVal === '' || $emailVal === null)
                         && ($turmaVal === '' || $turmaVal === null)
                         && ($descricaoVal === '' || $descricaoVal === null);
            $cardClass = '';
            if ($u['bloqueado']) $cardClass .= ' bloqueado';
            if ($incompleto)     $cardClass .= ' incompleto';
            $preBloqueadoManual = (int) ($u['pre_bloqueado_manual'] ?? 0) === 1;
            $preAutoStatus = $preBloqueioAutoMap[(int) $u['id_user']] ?? ['pre_bloqueado' => false, 'motivo' => ''];
            $preBloqueadoAuto = (($preAutoStatus['pre_bloqueado'] ?? false) === true);
            $motivoAuto = trim((string) ($preAutoStatus['motivo'] ?? ''));
            $mostrarPreBadge = $preBloqueadoManual || $preBloqueadoAuto || (int) ($u['bloqueado'] ?? 0) === 1;
            if ($mostrarPreBadge) $cardClass .= ' prebloqueado';
            $preTipoRaw = mb_strtolower(trim((string) ($u['pre_bloqueado_por_tipo'] ?? '')));
            $preTipo = $preTipoRaw === 'admin' ? 'Adm.' : 'Lab.';
            $preNome = trim((string) ($u['pre_bloqueado_por_nome'] ?? ''));
            if ($preNome === '') $preNome = 'Usuário';
            $preBadgeText = $preBloqueadoManual
                ? ('Pré-bloqueado, por ' . $preTipo . ': ' . $preNome)
                : ($preBloqueadoAuto ? 'Pré-bloqueado, automaticamente.' : 'Pré-bloqueado');
            $preBadgeTitle = $preBloqueadoManual
                ? (string) ($u['pre_bloqueio_motivo'] ?? '')
                : ($motivoAuto !== '' ? $motivoAuto : ((int) ($u['bloqueado'] ?? 0) === 1 ? 'Usuário bloqueado' : ''));
            $mostrarOpcaoPreblock = $preBloqueadoManual || !$preBloqueadoAuto;
        ?>
        <div class="user-card <?= trim($cardClass) ?>" id="card-<?= $u['id_user'] ?>">

            <div class="user-avatar" id="avatar-<?= $u['id_user'] ?>">
                <?php if (!empty($u['foto_perfil'])): ?>
                    <img src="<?= htmlspecialchars($u['foto_perfil']) ?>" alt="Foto de <?= htmlspecialchars($u['nome']) ?>">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <p class="user-nome" id="nome-<?= $u['id_user'] ?>"><?= htmlspecialchars($u['nome']) ?></p>
                <?php if ($incompleto): ?>
                <p class="user-warning">Esse usuário tem informações incompletas</p>
                <?php endif; ?>
                <p class="user-sub" id="sub-<?= $u['id_user'] ?>">
                    Login: <?= htmlspecialchars($u['login']) ?>
                </p>
            </div>

            <span
                class="badge-bloqueado"
                id="badge-<?= $u['id_user'] ?>"
                <?= $u['bloqueado'] ? '' : 'style="display:none"' ?>
            >Bloqueado</span>

            <span
                class="badge-prebloqueado"
                id="prebadge-<?= $u['id_user'] ?>"
                <?= $mostrarPreBadge ? '' : 'style="display:none"' ?>
                title="<?= htmlspecialchars($preBadgeTitle) ?>"
            ><?= htmlspecialchars($preBadgeText) ?></span>

            <div class="menu-wrap" id="menu-<?= $u['id_user'] ?>">
                <button
                    class="btn-menu"
                    onclick="toggleMenu(<?= $u['id_user'] ?>, event)"
                    aria-label="Ações"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8"  y1="6"  x2="21" y2="6"/>
                        <line x1="8"  y1="12" x2="21" y2="12"/>
                        <line x1="8"  y1="18" x2="21" y2="18"/>
                        <line x1="3"  y1="6"  x2="3.01" y2="6"/>
                        <line x1="3"  y1="12" x2="3.01" y2="12"/>
                        <line x1="3"  y1="18" x2="3.01" y2="18"/>
                    </svg>
                </button>

                <div class="action-dropdown" role="menu">

                    <button
                        class="<?= $u['bloqueado'] ? 'unblock' : 'danger' ?>"
                        id="btn-block-<?= $u['id_user'] ?>"
                        onclick="toggleBlock(<?= $u['id_user'] ?>)"
                    >
                        <?php if ($u['bloqueado']): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                        </svg>
                        Desbloquear
                        <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Bloquear
                        <?php endif; ?>
                    </button>

                    <button onclick="openNoticeModal(<?= $u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        Mandar aviso
                    </button>

                    <button
                        class="<?= $preBloqueadoManual ? 'unblock' : 'danger' ?>"
                        id="btn-preblock-<?= $u['id_user'] ?>"
                        style="<?= $mostrarOpcaoPreblock ? '' : 'display:none' ?>"
                        onclick="togglePreBlock(<?= $u['id_user'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <?= $preBloqueadoManual ? 'Remover pré-bloqueio' : 'Pré-bloquear' ?>
                    </button>

                    <button onclick="openEditModal(
                        <?= $u['id_user'] ?>,
                        '<?= htmlspecialchars(addslashes($u['nome'])) ?>',
                        '<?= htmlspecialchars(addslashes($u['login'])) ?>',
                        '<?= htmlspecialchars(addslashes($emailVal)) ?>',
                        '<?= htmlspecialchars(addslashes($turmaVal)) ?>',
                        '<?= htmlspecialchars(addslashes($descricaoVal)) ?>',
                        '<?= htmlspecialchars(addslashes($u['foto_perfil'] ?? '')) ?>'
                    )">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Editar
                    </button>

                </div>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</main>

<!-- ══════════════════ MODAL AVISO ══════════════════ -->
<div class="modal-overlay" id="noticeModal" onclick="closeNoticeModalOnBackdrop(event)">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="noticeModalTitle">
        <h2 class="modal-title" id="noticeModalTitle">Mandar aviso</h2>
        <p class="modal-recipient">Para: <span id="noticeRecipient">—</span></p>
        <p class="modal-sender">De: <span id="noticeSender"><?= htmlspecialchars('Admin ' . ($_SESSION['auth_user']['nome'] ?? 'Administrador')) ?></span></p>

        <div>
            <label for="noticeTitulo">Título</label>
            <input
                type="text"
                id="noticeTitulo"
                placeholder="Ex: Devolução pendente"
                maxlength="150"
            >
        </div>

        <div>
            <label for="noticeMensagem">Mensagem</label>
            <textarea
                id="noticeMensagem"
                placeholder="Escreva a mensagem para o estudante…"
                maxlength="2000"
            ></textarea>
        </div>

        <div>
            <label>Rosto da notificação <span style="color:#555;font-weight:400">(opcional)</span></label>
            <div class="face-picker" id="facePicker">
                <button type="button" class="face-btn" data-humor="feliz" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <path d="M9 14c.8 1 5.2 1 6 0"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Feliz
                </button>
                <button type="button" class="face-btn" data-humor="neutro" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <line x1="9" y1="14" x2="15" y2="14"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Neutro
                </button>
                <button type="button" class="face-btn" data-humor="triste" onclick="selectFace(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="16" rx="3"/>
                        <circle cx="8" cy="10" r="1" fill="currentColor"/>
                        <circle cx="16" cy="10" r="1" fill="currentColor"/>
                        <path d="M9 15c.8-1 5.2-1 6 0"/>
                        <line x1="12" y1="19" x2="12" y2="21"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                    </svg>
                    Triste
                </button>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeNoticeModal()">Cancelar</button>
            <button class="btn-send" id="btnSend" onclick="sendNotice()">Enviar</button>
        </div>
    </div>
</div>

<!-- ══════════════════ MODAL EDITAR ══════════════════ -->
<div class="modal-overlay" id="editModal" onclick="closeEditModalOnBackdrop(event)">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <h2 class="modal-title" id="editModalTitle">Editar usuário</h2>
        <p class="modal-recipient">Para: <span id="editRecipient">—</span></p>

        <div>
            <label>Foto de perfil</label>
            <div class="foto-preview-wrap">
                <div class="foto-preview" id="fotoPreviewWrap">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" id="fotoPreviewSvg">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                    <img id="fotoPreviewImg" src="" alt="Preview" style="display:none">
                </div>
                <label class="btn-foto-label" for="editFotoInput">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Escolher foto
                </label>
                <input type="file" id="editFotoInput" accept="image/jpeg,image/png,image/webp,image/gif" onchange="onFotoChange(this)">
            </div>
        </div>

        <div>
            <label for="editNome">Nome</label>
            <input type="text" id="editNome" placeholder="Nome completo" maxlength="255" required>
        </div>

        <div>
            <label for="editLogin">Login / CPF</label>
            <input type="text" id="editLogin" placeholder="000.000.000-00" maxlength="20" oninput="maskCpf(this)">
        </div>

        <div>
            <label for="editEmail">E-mail</label>
            <input type="email" id="editEmail" placeholder="email@exemplo.com" maxlength="255">
        </div>

        <div>
            <label for="editTurma">Turma</label>
            <?php if (!empty($turmas_lista)): ?>
            <select id="editTurma">
                <option value="">— Nenhuma —</option>
                <?php foreach ($turmas_lista as $tl): ?>
                <option value="<?= htmlspecialchars($tl['nome']) ?>"><?= htmlspecialchars($tl['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" id="editTurma" placeholder="Ex: Turma 11A" maxlength="100">
            <?php endif; ?>
        </div>

        <div>
            <label for="editDescricao">Descrição</label>
            <textarea id="editDescricao" placeholder="Breve descrição do aluno…" rows="3" maxlength="1000" style="min-height:80px"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeEditModal()">Cancelar</button>
            <button class="btn-send" id="btnSave" onclick="saveEdit()">Salvar</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
/* ── Menus de ação ───────────────────────────────── */
function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap   = document.getElementById('menu-' + id);
    const isOpen = wrap.classList.contains('open');
    closeAllMenus();
    if (!isOpen) wrap.classList.add('open');
}

function closeAllMenus() {
    document.querySelectorAll('.menu-wrap.open').forEach(el => el.classList.remove('open'));
}

document.addEventListener('click', closeAllMenus);

/* ── Bloquear / Desbloquear ──────────────────────── */
function toggleBlock(id) {
    closeAllMenus();

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_block&id_user=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Erro: ' + data.error, 'error'); return; }

        const card  = document.getElementById('card-'      + id);
        const badge = document.getElementById('badge-'     + id);
        const btn   = document.getElementById('btn-block-' + id);

        const preBadge = document.getElementById('prebadge-' + id);
        const btnPre = document.getElementById('btn-preblock-' + id);
        const preManualAtivo = (data.pre_manual_ativo ?? false) === true || (data.pre_manual_ativo ?? 0) === 1 || (btnPre && btnPre.textContent.toLowerCase().includes('remover'));
        const preManualLabel = (data.pre_manual_label || '').trim();
        const preAutoAtivo = (data.pre_bloqueado_auto ?? false) === true || (data.pre_bloqueado_auto ?? 0) === 1;
        const motivoAuto = (data.motivo_auto || '').trim();

        if (data.bloqueado) {
            card.classList.add('bloqueado');
            card.classList.add('prebloqueado');
            badge.style.display = '';
            if (preBadge) {
                preBadge.style.display = '';
                preBadge.textContent = preManualAtivo ? (preManualLabel || 'Pré-bloqueado') : (preAutoAtivo ? 'Pré-bloqueado, automaticamente.' : 'Pré-bloqueado');
                if (!preManualAtivo) preBadge.title = motivoAuto || 'Usuário bloqueado';
            }
            if (btnPre && !preManualAtivo && preAutoAtivo) btnPre.style.display = 'none';
            btn.className = 'unblock';
            btn.innerHTML = svgLockOpen() + ' Desbloquear';
            showToast('Usuário bloqueado.', 'error');
        } else {
            card.classList.remove('bloqueado');
            if (!preManualAtivo && !preAutoAtivo) {
                card.classList.remove('prebloqueado');
                if (preBadge) {
                    preBadge.style.display = 'none';
                    preBadge.textContent = 'Pré-bloqueado';
                    preBadge.title = '';
                }
                if (btnPre) btnPre.style.display = '';
            } else if (!preManualAtivo && preAutoAtivo) {
                card.classList.add('prebloqueado');
                if (preBadge) {
                    preBadge.style.display = '';
                    preBadge.textContent = 'Pré-bloqueado, automaticamente.';
                    preBadge.title = motivoAuto || 'Pré-bloqueado automático';
                }
                if (btnPre) btnPre.style.display = 'none';
            } else if (preManualAtivo) {
                if (btnPre) btnPre.style.display = '';
                if (preBadge) preBadge.textContent = preManualLabel || 'Pré-bloqueado';
            }
            badge.style.display = 'none';
            btn.className = 'danger';
            btn.innerHTML = svgLockClosed() + ' Bloquear';
            showToast('Usuário desbloqueado.', 'success');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'));
}

function togglePreBlock(id, nome) {
    closeAllMenus();

    const btn = document.getElementById('btn-preblock-' + id);
    const removendo = btn && btn.textContent.toLowerCase().includes('remover');

    let motivo = '';
    if (!removendo) {
        motivo = (window.prompt('Informe o motivo do pré-bloqueio para ' + nome + ':') || '').trim();
        if (!motivo) {
            showToast('Motivo obrigatório para pré-bloquear.', 'error');
            return;
        }
    }

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'toggle_preblock',
            id_user: id,
            motivo: motivo,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            showToast('Erro: ' + data.error, 'error');
            return;
        }

        const card = document.getElementById('card-' + id);
        const badge = document.getElementById('prebadge-' + id);
        const botao = document.getElementById('btn-preblock-' + id);
        const preManualLabel = (data.pre_manual_label || '').trim();
        const preAutoAtivo = (data.pre_bloqueado_auto ?? false) === true || (data.pre_bloqueado_auto ?? 0) === 1;
        const motivoAuto = (data.motivo_auto || '').trim();

        if ((data.pre_bloqueado ?? 0) === 1) {
            card.classList.add('prebloqueado');
            if (badge) {
                badge.style.display = '';
                badge.textContent = preManualLabel || 'Pré-bloqueado';
                badge.title = data.motivo || '';
            }
            if (botao) {
                botao.style.display = '';
                botao.className = 'unblock';
                botao.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Remover pré-bloqueio`;
            }
            showToast('Usuário pré-bloqueado e notificado.', 'error');
        } else {
            const badgeBloqueado = document.getElementById('badge-' + id);
            const aindaBloqueado = badgeBloqueado && badgeBloqueado.style.display !== 'none';
            if (aindaBloqueado || preAutoAtivo) {
                card.classList.add('prebloqueado');
                if (badge) {
                    badge.style.display = '';
                    badge.textContent = preAutoAtivo ? 'Pré-bloqueado, automaticamente.' : 'Pré-bloqueado';
                    badge.title = motivoAuto || (aindaBloqueado ? 'Usuário bloqueado' : 'Pré-bloqueado automático');
                }
                if (botao && preAutoAtivo) botao.style.display = 'none';
            } else {
                card.classList.remove('prebloqueado');
                if (badge) {
                    badge.style.display = 'none';
                    badge.textContent = 'Pré-bloqueado';
                    badge.title = '';
                }
                if (botao) botao.style.display = '';
            }
            if (botao) {
                if (!(preAutoAtivo && !aindaBloqueado)) {
                    botao.className = 'danger';
                    botao.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Pré-bloquear`;
                }
            }
            showToast('Pré-bloqueio removido.', 'success');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'));
}

function svgLockOpen() {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
    </svg>`;
}

function svgLockClosed() {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </svg>`;
}

/* ── Modal de aviso ──────────────────────────────── */
let currentUserId = null;
let selectedHumor = '';

function selectFace(btn) {
    document.querySelectorAll('#facePicker .face-btn').forEach(b => b.classList.remove('selected'));
    if (selectedHumor === btn.dataset.humor) {
        selectedHumor = '';
    } else {
        btn.classList.add('selected');
        selectedHumor = btn.dataset.humor;
    }
}

function openNoticeModal(id, nome) {
    closeAllMenus();
    currentUserId = id;
    selectedHumor = '';
    document.getElementById('noticeRecipient').textContent = nome;
    document.getElementById('noticeTitulo').value          = '';
    document.getElementById('noticeMensagem').value        = '';
    document.querySelectorAll('#facePicker .face-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('noticeModal').classList.add('open');
    setTimeout(() => document.getElementById('noticeTitulo').focus(), 50);
}

function closeNoticeModal() {
    document.getElementById('noticeModal').classList.remove('open');
    currentUserId = null;
}

function closeNoticeModalOnBackdrop(e) {
    if (e.target === document.getElementById('noticeModal')) closeNoticeModal();
}

function sendNotice() {
    if (!currentUserId) return;

    const titulo   = document.getElementById('noticeTitulo').value.trim();
    const mensagem = document.getElementById('noticeMensagem').value.trim();

    if (!mensagem) {
        document.getElementById('noticeMensagem').focus();
        showToast('Escreva uma mensagem antes de enviar.', 'error');
        return;
    }

    const btn = document.getElementById('btnSend');
    btn.disabled    = true;
    btn.textContent = 'Enviando…';

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action:   'send_notice',
            id_user:  currentUserId,
            titulo:   titulo || 'Aviso do Administrador',
            mensagem: mensagem,
            humor:    selectedHumor
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeNoticeModal();
            showToast('Aviso enviado com sucesso!', 'success');
        } else {
            showToast('Erro: ' + data.error, 'error');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'))
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = 'Enviar';
    });
}

/* ── Modal de edição ─────────────────────────────── */
let editUserId = null;

function openEditModal(id, nome, login, email, turma, descricao, foto) {
    closeAllMenus();
    editUserId = id;

    document.getElementById('editRecipient').textContent = nome;
    document.getElementById('editNome').value            = nome;
    document.getElementById('editLogin').value           = login;
    document.getElementById('editEmail').value           = email;
    document.getElementById('editTurma').value           = turma;
    document.getElementById('editDescricao').value       = descricao;
    document.getElementById('editFotoInput').value       = '';

    const previewImg = document.getElementById('fotoPreviewImg');
    const previewSvg = document.getElementById('fotoPreviewSvg');

    if (foto) {
        previewImg.src           = foto;
        previewImg.style.display = '';
        previewSvg.style.display = 'none';
    } else {
        previewImg.src           = '';
        previewImg.style.display = 'none';
        previewSvg.style.display = '';
    }

    document.getElementById('editModal').classList.add('open');
    setTimeout(() => document.getElementById('editNome').focus(), 50);
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
    editUserId = null;
}

function closeEditModalOnBackdrop(e) {
    if (e.target === document.getElementById('editModal')) closeEditModal();
}

function onFotoChange(input) {
    const file = input.files[0];
    if (!file) return;

    const previewImg = document.getElementById('fotoPreviewImg');
    const previewSvg = document.getElementById('fotoPreviewSvg');

    const reader = new FileReader();
    reader.onload = function (ev) {
        previewImg.src           = ev.target.result;
        previewImg.style.display = '';
        previewSvg.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function maskCpf(input) {
    let v = input.value.replace(/\D/g, '').substring(0, 11);
    if (v.length > 9) {
        v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
    } else if (v.length > 6) {
        v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
    } else if (v.length > 3) {
        v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
    }
    input.value = v;
}

function saveEdit() {
    if (!editUserId) return;

    const nome      = document.getElementById('editNome').value.trim();
    const login     = document.getElementById('editLogin').value.trim();
    const email     = document.getElementById('editEmail').value.trim();
    const turma     = document.getElementById('editTurma').value.trim();
    const descricao = document.getElementById('editDescricao').value.trim();
    const fotoFile  = document.getElementById('editFotoInput').files[0];

    if (!nome || !login) {
        showToast('Nome e login são obrigatórios.', 'error');
        return;
    }

    const btn = document.getElementById('btnSave');
    btn.disabled    = true;
    btn.textContent = 'Salvando…';

    const fd = new FormData();
    fd.append('action',    'edit_user');
    fd.append('id_user',   editUserId);
    fd.append('nome',      nome);
    fd.append('login',     login);
    fd.append('email',     email);
    fd.append('turma',     turma);
    fd.append('descricao', descricao);
    if (fotoFile) fd.append('foto', fotoFile);

    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            /* atualiza nome e login visíveis no card */
            const nomeEl = document.getElementById('nome-' + editUserId);
            if (nomeEl) nomeEl.textContent = nome;

            const subEl = document.getElementById('sub-' + editUserId);
            if (subEl) subEl.textContent = 'Login: ' + login;

            /* atualiza avatar se nova foto retornada */
            if (data.foto) {
                const avatarWrap = document.getElementById('avatar-' + editUserId);
                if (avatarWrap) {
                    avatarWrap.innerHTML = `<img src="${data.foto}" alt="Foto">`;
                }
            }

            closeEditModal();
            showToast('Dados salvos com sucesso!', 'success');
        } else {
            showToast('Erro: ' + data.error, 'error');
        }
    })
    .catch(() => showToast('Falha de comunicação.', 'error'))
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = 'Salvar';
    });
}

/* ── Fechar modais com Escape ────────────────────── */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('noticeModal').classList.contains('open')) closeNoticeModal();
        if (document.getElementById('editModal').classList.contains('open'))   closeEditModal();
    }
});

/* ── Toast ───────────────────────────────────────── */
let toastTimer = null;

function showToast(msg, type = '') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'toast ' + type;
    void el.offsetWidth;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}
</script>

</body>
</html>
