<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/usuarios.php';

function iniciarSessaoSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function criarSessaoUsuario(array $user, string $origem): string
{
    iniciarSessaoSegura();
    registrarFormaLogin(db(), (int) $user['id_user'], $origem);
    session_regenerate_id(true);
    unset($_SESSION['csrf_perfis'], $_SESSION['csrf_avisos'], $_SESSION['csrf_gerenciar_user'], $_SESSION['google_login'], $_SESSION['admin_visualizacao'], $_SESSION['csrf_visualizacao_admin']);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id_user'], 'nome' => (string) $user['nome'],
        'login' => (string) $user['login'], 'perfil' => (string) $user['tipo_perfil'],
        'email' => (string) ($user['email'] ?? ''),
        'foto_perfil' => $user['foto_perfil'] ?? null, 'origem' => $origem,
    ];
    return match ($user['tipo_perfil']) {
        'admin' => 'pages_admin/index.php',
        'laboratorista' => 'pages_laboratorista/index.php',
        default => 'index.php',
    };
}
