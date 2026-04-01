<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$authUser = $_SESSION['auth_user'] ?? null;
$perfil = is_array($authUser) ? (string) ($authUser['perfil'] ?? '') : '';

if (!is_array($authUser) || !in_array($perfil, ['admin', 'laboratorista'], true)) {
	header('Location: login.php');
	exit;
}

require_once __DIR__ . '/../src/views/ldap_control/ldaptest.php';
