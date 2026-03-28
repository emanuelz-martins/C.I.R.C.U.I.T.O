<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$id_usuario = (int) ($_SESSION['auth_user']['id'] ?? 0);
if (!$id_usuario) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sessão inválida.']);
    exit;
}

try {
    $pdo = db();

    /* Verificar quais colunas existem */
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute(['t' => 'Usuario']);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sets   = [];
    $params = ['id' => $id_usuario];

    if (in_array('email', $cols, true)) {
        $sets[] = 'email = :email';
        $params['email'] = trim($_POST['email'] ?? '') ?: null;
    }
    if (in_array('turma', $cols, true)) {
        $sets[] = 'turma = :turma';
        $params['turma'] = trim($_POST['turma'] ?? '') ?: null;
    }
    if (in_array('descricao', $cols, true)) {
        $sets[] = 'descricao = :descricao';
        $params['descricao'] = trim($_POST['descricao'] ?? '') ?: null;
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
        $dir       = __DIR__ . '/../uploads/fotos_perfil/';
        $dest      = $dir . $nome_arq;

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (move_uploaded_file($arquivo['tmp_name'], $dest)) {
            /* Apagar foto antiga */
            if (in_array('foto_perfil', $cols, true)) {
                $old = $pdo->prepare('SELECT foto_perfil FROM Usuario WHERE id_user = :id');
                $old->execute(['id' => $id_usuario]);
                $antiga = $old->fetchColumn();
                if ($antiga) {
                    $path_antiga = __DIR__ . '/../' . ltrim($antiga, '/');
                    if (file_exists($path_antiga)) @unlink($path_antiga);
                }
            }

            $nova_foto = '/C.I.R.C.U.I.T.O/public/uploads/fotos_perfil/' . $nome_arq;
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

    echo json_encode(['ok' => true, 'foto' => $nova_foto]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
