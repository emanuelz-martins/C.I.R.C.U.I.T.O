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

$id_usuario = $_SESSION['auth_user']['id'] ?? null;
if (!$id_usuario) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sessão inválida.']);
    exit;
}

if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    $erros = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo muito grande (limite do servidor).',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo muito grande.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
    ];
    $codigo_erro = $_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $erros[$codigo_erro] ?? 'Erro no upload.';
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => $msg]);
    exit;
}

$arquivo = $_FILES['foto'];

/* Validar tipo MIME real */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($arquivo['tmp_name']);
$tipos_permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if (!in_array($mime, $tipos_permitidos, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Formato inválido. Use JPG, PNG, WEBP ou GIF.']);
    exit;
}

/* Limite de 5 MB */
if ($arquivo['size'] > 5 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'A imagem deve ter no máximo 5 MB.']);
    exit;
}

/* Extensão segura baseada no MIME */
$ext_map = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
$ext = $ext_map[$mime];

$dir_upload = __DIR__ . '/../assets/img/perfil/';
if (!is_dir($dir_upload)) mkdir($dir_upload, 0755, true);

$nome_arquivo = 'user_' . $id_usuario . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$caminho_destino = $dir_upload . $nome_arquivo;

if (!move_uploaded_file($arquivo['tmp_name'], $caminho_destino)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Não foi possível salvar a imagem.']);
    exit;
}

$caminho_bd = '/C.I.R.C.U.I.T.O/public/assets/img/perfil/' . $nome_arquivo;

try {
    $pdo = db();

    /* Buscar foto antiga para deletar */
    $stmt = $pdo->prepare('SELECT foto_perfil FROM Usuario WHERE id_user = :id');
    $stmt->execute(['id' => $id_usuario]);
    $foto_antiga = $stmt->fetchColumn();

    /* Atualizar no banco */
    $stmt = $pdo->prepare('UPDATE Usuario SET foto_perfil = :foto WHERE id_user = :id');
    $stmt->execute(['foto' => $caminho_bd, 'id' => $id_usuario]);

    /* Atualizar sessão */
    $_SESSION['auth_user']['foto_perfil'] = $caminho_bd;

    /* Remover foto antiga do disco se existir */
    if ($foto_antiga) {
        $caminho_antigo = '/opt/lampp/htdocs' . '/' . ltrim($foto_antiga, '/');
        /* Compatibilidade com caminhos relativos antigos (ex: uploads/fotos_perfil/...) */
        if (!str_starts_with($foto_antiga, '/')) {
            $caminho_antigo = '/opt/lampp/htdocs/C.I.R.C.U.I.T.O/public/' . $foto_antiga;
        }
        if (is_file($caminho_antigo)) {
            unlink($caminho_antigo);
        }
    }

    echo json_encode(['ok' => true, 'url' => $caminho_bd]);
} catch (Throwable $e) {
    /* Limpar arquivo recém-salvo em caso de erro no BD */
    if (is_file($caminho_destino)) {
        unlink($caminho_destino);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao salvar no banco de dados.']);
}
