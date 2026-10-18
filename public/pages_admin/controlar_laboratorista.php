<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['admin']);
header('Location: perfis.php?perfil=laboratorista');
exit;
