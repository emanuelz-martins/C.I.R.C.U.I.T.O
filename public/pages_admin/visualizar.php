<?php
declare(strict_types=1);
require_once '../includes/auth_check.php';
checkAccess(['admin']);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    negarAcesso(405, 'Método não permitido.');
}
if (!isset($_SESSION['csrf_visualizacao_admin']) || !hash_equals($_SESSION['csrf_visualizacao_admin'], (string) ($_POST['csrf_visualizacao_admin'] ?? ''))) negarAcesso(403, 'Solicitação inválida. Recarregue a página.');
$modo = (string) ($_POST['visualizacao'] ?? '');
if (!in_array($modo, ['estudante', 'laboratorista'], true)) negarAcesso(422, 'Visualização inválida.');
$_SESSION['admin_visualizacao'] = $modo;
header('Location: ' . ($modo === 'estudante' ? '../index.php' : '../pages_laboratorista/index.php'), true, 303);
exit;
