<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respostaEmail(array $dados, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respostaEmail(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
if (env('APP_ENV') === 'production' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    respostaEmail(['ok' => false, 'erro' => 'Use uma conexão HTTPS para continuar.'], 403);
}
$csrf = $_POST['csrf_email'] ?? '';
if (!is_string($csrf) || empty($_SESSION['csrf_email_conta']) || !hash_equals($_SESSION['csrf_email_conta'], $csrf)) {
    respostaEmail(['ok' => false, 'erro' => 'Sessão expirada. Atualize a página e tente novamente.'], 403);
}
foreach (['acao', 'login', 'senha', 'email', 'nome', 'codigo'] as $campo) {
    if (isset($_POST[$campo]) && (!is_string($_POST[$campo]) || strlen($_POST[$campo]) > 2048)) {
        respostaEmail(['ok' => false, 'erro' => 'Dados inválidos.'], 422);
    }
}
try {
    $pdo = db();
    $id = (int) $_SESSION['auth_user']['id'];
    $u = contaUsuario($pdo, $id);
    if ($u['google_sub'] !== null) throw new ErroEmailConta('O e-mail desta conta é gerenciado pelo Google.', 403);
    switch ($_POST['acao'] ?? '') {
        case 'autenticar':
            emailReautenticar($pdo, $id, $_POST['login'] ?? '', $_POST['senha'] ?? '');
            respostaEmail(['ok' => true]);
        case 'enviar':
            $concluido = emailEnviarCodigo($pdo, $id, $_POST['email'] ?? '', $_POST['nome'] ?? '');
            $atual = contaUsuario($pdo, $id);
            if ($concluido) {
                respostaEmail(['ok' => true, 'destino' => contaDestino($atual)]);
            }
            respostaEmail(['ok' => true]);
        case 'confirmar':
            $inicial = contaCadastroPendente($u, $_SESSION['auth_user']['origem'] ?? null);
            $atual = emailConfirmarCodigo($pdo, $id, trim($_POST['codigo'] ?? ''));
            $base = rtrim((string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH), '/');
            respostaEmail(['ok' => true, 'destino' => $inicial ? contaDestino($atual) : $base . '/perfil.php']);
        case 'cancelar':
            $d = emailDesafio($pdo, $id);
            if ($d && emailDesafioDaSessao($d)) {
                $pdo->prepare('DELETE FROM Email_Verificacao WHERE id_user = ? AND desafio = ?')->execute([$id, $d['desafio']]);
            }
            unset($_SESSION['email_autorizacao']);
            respostaEmail(['ok' => true]);
        default:
            throw new ErroEmailConta('Ação inválida.');
    }
} catch (ErroEmailConta $e) {
    if ($e->status === 429) header('Retry-After: 60');
    respostaEmail(['ok' => false, 'erro' => $e->getMessage(), 'reautenticar' => $e->status === 403], $e->status);
} catch (Throwable) {
    respostaEmail(['ok' => false, 'erro' => 'Não foi possível concluir a operação. O envio de e-mail pode estar indisponível; tente novamente ou fale com a equipe do laboratório.'], 503);
}
