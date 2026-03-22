<?php
/**
 * Arquivo de proteção de acesso
 * Valida se o usuário tem permissão para acessar a página
 *
 * Uso:
 * require_once 'includes/auth_check.php';
 * checkAccess('laboratorista'); // Apenas laboratoristas podem acessar
 * checkAccess('estudante');     // Apenas estudantes podem acessar
 * checkAccess(['laboratorista', 'admin']); // Laboratoristas e admins podem acessar
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function checkAccess($allowed_roles) {
    // Garantir que sea um array
    if (is_string($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    // Verificar se há sessão de usuário
    if (empty($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        header('Location: /C.I.R.C.U.I.T.O/public/login.php');
        exit;
    }

    $user_perfil = (string) ($_SESSION['auth_user']['perfil'] ?? 'desconhecido');

    // Verificar se o perfil do usuário está na lista de permitidos
    if (!in_array($user_perfil, $allowed_roles, true)) {
        http_response_code(403);
        exit('Acesso negado. Você não tem permissão para acessar esta página.');
    }
}
?>
