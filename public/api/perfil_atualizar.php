<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
require_once __DIR__ . '/../includes/perfil_pessoal.php';

require_once __DIR__ . '/../../src/config/database.php';

$appUrlPath = (string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH);
$appUrlPath = rtrim($appUrlPath, '/');
$publicUrlBase = $appUrlPath !== '' ? $appUrlPath : '';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

validarCsrfPerfilPessoal();

// A conta editada vem da sessão, nunca de um ID enviado pelo navegador.
$id_usuario = (int) ($_SESSION['auth_user']['id'] ?? 0);
if (!$id_usuario) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sessão inválida.']);
    exit;
}

foreach (['nome', 'email', 'matricula', 'descricao', 'turma'] as $campo) {
    if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Dados do perfil inválidos.']);
        exit;
    }
}

try {
    $pdo = db();

    /* Verificar quais colunas existem */
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute(['t' => 'Usuario']);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sets   = [];
    $params = ['id' => $id_usuario];
    $googleStmt = $pdo->prepare('SELECT google_sub FROM Usuario WHERE id_user = ?');
    $googleStmt->execute([$id_usuario]);
    $contaGoogle = $googleStmt->fetchColumn() !== null;

    if (array_key_exists('nome', $_POST)) {
        $nome = trim($_POST['nome']);
        if ($nome === '' || mb_strlen($nome, 'UTF-8') > 150) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'erro' => 'Informe seu nome completo, com até 150 caracteres.']);
            exit;
        }
        $sets[] = 'nome = :nome';
        $params['nome'] = $nome;
    }
    if (array_key_exists('matricula', $_POST)) {
        $matricula = trim($_POST['matricula']);
        if (mb_strlen($matricula, 'UTF-8') > 50) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'erro' => 'A matrícula deve ter até 50 caracteres.']);
            exit;
        }
        $sets[] = 'matricula = :matricula';
        $params['matricula'] = $matricula ?: null;
    }
    if (!$contaGoogle && array_key_exists('email', $_POST) && in_array('email', $cols, true)) {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && (mb_strlen($email, 'UTF-8') > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'erro' => 'Informe um e-mail válido.']);
            exit;
        }
        $sets[] = 'email = :email';
        $params['email'] = $email ?: null;
    }
    if (array_key_exists('turma', $_POST) && in_array('turma', $cols, true)) {
        $sets[] = 'turma = :turma';
        $params['turma'] = trim($_POST['turma'] ?? '') ?: null;
    }
    if (array_key_exists('descricao', $_POST) && in_array('descricao', $cols, true)) {
        // Normaliza quebras de linha para contar como o campo exibido no navegador.
        $descricao = str_replace(["\r\n", "\r"], "\n", $_POST['descricao']);
        if (mb_strlen($descricao, 'UTF-8') > PERFIL_DESCRICAO_LIMITE) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'erro' => 'A descrição deve ter até ' . PERFIL_DESCRICAO_LIMITE . ' caracteres.']);
            exit;
        }
        $sets[] = 'descricao = :descricao';
        $params['descricao'] = trim($descricao) ?: null;
    }

    /* Upload de foto */
    $nova_foto = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $arquivo = $_FILES['foto'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($arquivo['tmp_name']);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            echo json_encode(['ok' => false, 'erro' => 'Formato inválido. Use JPG, PNG, WEBP ou GIF.']);
            exit;
        }
        if ($arquivo['size'] > 5 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'erro' => 'Arquivo muito grande (máx. 5 MB).']);
            exit;
        }

        $ext       = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg' };
        $nome_arq  = 'user_' . $id_usuario . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir       = __DIR__ . '/../assets/img/perfil/';
        $dest      = $dir . $nome_arq;

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (move_uploaded_file($arquivo['tmp_name'], $dest)) {
            /* Apagar foto antiga */
            if (in_array('foto_perfil', $cols, true)) {
                $old = $pdo->prepare('SELECT foto_perfil FROM Usuario WHERE id_user = :id');
                $old->execute(['id' => $id_usuario]);
                $antiga = $old->fetchColumn();
                if ($antiga) {
                    $antigaPath = ltrim((string) $antiga, '/');
                    if (substr($antigaPath, 0, 21) === 'C.I.R.C.U.I.T.O/public/') {
                        $antigaPath = substr($antigaPath, 22);
                    }
                    if (substr($antigaPath, 0, 7) === 'public/') {
                        $antigaPath = substr($antigaPath, 7);
                    }
                    $path_antiga = dirname(__DIR__) . '/' . $antigaPath;
                    if (is_file($path_antiga)) @unlink($path_antiga);
                }
            }

            $nova_foto = $publicUrlBase . '/assets/img/perfil/' . $nome_arq;
            if (in_array('foto_perfil', $cols, true)) {
                $sets[]            = 'foto_perfil = :foto_perfil';
                $params['foto_perfil'] = $nova_foto;
            }
        }
    }

    if (!empty($sets)) {
        $pdo->prepare('UPDATE Usuario SET ' . implode(', ', $sets) . ' WHERE id_user = :id')->execute($params);
    }

    /* Atualiza sessão com nova foto */
    if ($nova_foto) {
        $_SESSION['auth_user']['foto_perfil'] = $nova_foto;
    }

    $dadosStmt = $pdo->prepare('SELECT nome, email, matricula, descricao FROM Usuario WHERE id_user = ?');
    $dadosStmt->execute([$id_usuario]);
    $perfilSalvo = $dadosStmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['auth_user']['nome'] = $perfilSalvo['nome'];

    echo json_encode(['ok' => true, 'foto' => $nova_foto, 'perfil' => $perfilSalvo]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Não foi possível salvar seu perfil. Tente novamente.']);
}
