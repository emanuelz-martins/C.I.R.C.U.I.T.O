<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function negarAcesso(int $status, string $mensagem): never
{
    http_response_code($status);
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $json = str_contains($script, '/api/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $mensagem, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensagem;
    }
    exit;
}

/** O banco é a fonte do perfil, inclusive para sessões abertas antes de uma alteração. */
function checkAccess(string|array $allowed_roles): void
{
    if (empty($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_contains($script, '/api/')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            negarAcesso(401, 'Faça login para prosseguir.');
        }
        loadEnv(dirname(__DIR__, 2) . '/.env');
        $publicPath = rtrim((string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH), '/');
        header('Location: ' . $publicPath . '/login.php');
        exit;
    }

    try {
        $stmt = db()->prepare('SELECT nome, login, tipo_perfil, bloqueado FROM Usuario WHERE id_user = :id');
        $stmt->execute(['id' => (int) ($_SESSION['auth_user']['id'] ?? 0)]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        negarAcesso(503, 'Não foi possível verificar suas permissões. Tente novamente.');
    }

    if (!$usuario || (int) $usuario['bloqueado'] !== 0
        || !in_array($usuario['tipo_perfil'], ['estudante', 'laboratorista', 'admin'], true)) {
        unset($_SESSION['auth_user'], $_SESSION['csrf_perfis']);
        negarAcesso(403, 'Acesso indisponível. Entre em contato com o administrador.');
    }

    $_SESSION['auth_user']['nome'] = (string) $usuario['nome'];
    $_SESSION['auth_user']['login'] = (string) $usuario['login'];
    $_SESSION['auth_user']['perfil'] = (string) $usuario['tipo_perfil'];

    if (!in_array($usuario['tipo_perfil'], (array) $allowed_roles, true)) {
        negarAcesso(403, 'Acesso negado. Você não tem permissão para acessar esta página.');
    }
}
