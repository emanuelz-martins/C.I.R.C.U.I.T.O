<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/services/sessao.php';
require_once __DIR__ . '/../src/services/google.php';
iniciarSessaoSegura();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erroLoginGoogle(int $status, string $mensagem): never
{
    http_response_code($status);
    $_SESSION['erro_google'] = $mensagem;
    echo json_encode(['ok' => false, 'error' => $mensagem, 'redirect' => 'login.php']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    erroLoginGoogle(405, 'Método não permitido.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) erroLoginGoogle(413, 'Solicitação inválida.');
$fluxo = $_SESSION['google_login'] ?? [];
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($fluxo['csrf'] ?? null) || !hash_equals($fluxo['csrf'], $csrf)
    || ($fluxo['criado'] ?? 0) < time() - 600) erroLoginGoogle(403, 'A solicitação expirou. Recarregue a página.');
$clientId = googleClientId();
if ($clientId === '') erroLoginGoogle(503, 'O acesso Google ainda está em configuração.');
$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados) || !is_string($dados['credential'] ?? null)) erroLoginGoogle(400, 'Identificação Google inválida.');
try {
    $jwks = googleChavesPublicas();
    try {
        $perfil = verificarIdentidadeGoogle($dados['credential'], $jwks, $clientId, (string) $fluxo['nonce']);
    } catch (UnexpectedValueException $e) {
        if (!str_contains($e->getMessage(), '"kid" invalid')) throw $e;
        // Uma chave nova pode aparecer durante a rotação de certificados do Google.
        $perfil = verificarIdentidadeGoogle($dados['credential'], googleChavesPublicas(true), $clientId, (string) $fluxo['nonce']);
    }
    unset($_SESSION['google_login']);
    $user = sincronizarUsuarioGoogle(db(), $perfil);
    $destino = criarSessaoUsuario($user, 'google');
    echo json_encode(['ok' => true, 'redirect' => $destino]);
} catch (DomainException $e) {
    erroLoginGoogle(403, $e->getMessage());
} catch (InvalidArgumentException | UnexpectedValueException $e) {
    erroLoginGoogle(403, 'Não foi possível validar essa conta Google. Recarregue a página e tente novamente.');
} catch (Throwable $e) {
    error_log('Falha de login Google: ' . get_class($e));
    erroLoginGoogle(503, 'Não foi possível concluir o acesso Google agora. Tente novamente.');
}
