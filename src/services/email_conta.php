<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sigaa.php';
require_once __DIR__ . '/email_envio.php';

const EMAIL_CODIGO_TTL = 600;
const EMAIL_AUTORIZACAO_TTL = 900;
const EMAIL_TENTATIVAS = 5;

final class ErroEmailConta extends RuntimeException
{
    public function __construct(string $mensagem, public readonly int $status = 422) { parent::__construct($mensagem); }
}

function contaUsuario(PDO $pdo, int $id, bool $bloquear = false): array
{
    $q = $pdo->prepare('SELECT id_user, nome, login, hash_senha, tipo_perfil, bloqueado, forma_login,
        google_sub, email, email_verificado_em, nome_confirmado_em, email_versao, cadastro_sigaa
        FROM Usuario WHERE id_user = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $q->execute([$id]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int) $u['bloqueado'] !== 0) throw new ErroEmailConta('Sua conta não está disponível.', 403);
    return $u;
}

function contaNomeValido(string $nome): bool
{
    $nome = trim($nome);
    return $nome !== '' && mb_strlen($nome, 'UTF-8') <= 150
        && !preg_match('/[\x00-\x1F\x7F]/u', $nome)
        && mb_strtolower($nome, 'UTF-8') !== 'estudante sigaa';
}

function contaCadastroPendente(array $u, ?string $origem = null): bool
{
    if ($u['google_sub'] !== null || (!(bool) ($u['cadastro_sigaa'] ?? false)
        && !in_array('sigaa', [$u['forma_login'] ?? null, $origem], true))) return false;
    return empty($u['email_verificado_em']) || empty($u['email'])
        || empty($u['nome_confirmado_em']) || !contaNomeValido((string) $u['nome']);
}

function contaDestino(array $u): string
{
    $base = rtrim((string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH), '/');
    return $base . match ($u['tipo_perfil']) {
        'admin' => '/pages_admin/index.php', 'laboratorista' => '/pages_laboratorista/index.php', default => '/index.php',
    };
}

function emailChave(): string
{
    loadEnv(dirname(__DIR__, 2) . '/.env');
    $chave = (string) env('EMAIL_VERIFICATION_KEY');
    if (!preg_match('/^[a-fA-F0-9]{64,}$/D', $chave)) throw new RuntimeException('Configure a chave de verificação de e-mail.');
    return $chave;
}

function emailTokenCsrf(): string
{
    return $_SESSION['csrf_email_conta'] ??= bin2hex(random_bytes(32));
}

/** Autorização curta emitida somente após autenticação bem-sucedida da própria conta. */
function emailAutorizar(array $u): void
{
    if ($u['google_sub'] !== null) throw new ErroEmailConta('O e-mail desta conta é gerenciado pelo Google.', 403);
    $_SESSION['email_autorizacao'] = ['id' => (int) $u['id_user'], 'versao' => (int) $u['email_versao'],
        'anterior' => $u['email'], 'token' => bin2hex(random_bytes(32)), 'expira' => time() + EMAIL_AUTORIZACAO_TTL];
}

function emailAutorizacaoValida(array $u): bool
{
    $a = $_SESSION['email_autorizacao'] ?? [];
    return $u['google_sub'] === null && (int) ($a['id'] ?? 0) === (int) $u['id_user']
        && ($a['expira'] ?? 0) > time() && (int) ($a['versao'] ?? -1) === (int) $u['email_versao']
        && ($a['anterior'] ?? null) === $u['email'] && is_string($a['token'] ?? null);
}

function emailExigirAutorizacao(array $u): void
{
    if (!emailAutorizacaoValida($u)) {
        unset($_SESSION['email_autorizacao']);
        throw new ErroEmailConta('Confirme novamente sua identidade para continuar.', 403);
    }
}

/** Limites persistentes sobrevivem a logout, troca de sessão e reenvio de códigos. Sem confiar em X-Forwarded-For. */
function emailLimitar(PDO $pdo, string $acao, int $id, int $porUsuario, int $porIp, int $segundos): void
{
    $janela = intdiv(time(), $segundos);
    $chaves = [hash_hmac('sha256', "$acao|usuario|$id", emailChave()) => $porUsuario,
        hash_hmac('sha256', "$acao|ip|" . (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), emailChave()) => $porIp];
    ksort($chaves);
    $pdo->beginTransaction();
    try {
        $excedeu = false;
        foreach ($chaves as $chave => $limite) {
            $pdo->prepare('INSERT INTO Email_Limite (chave, janela, usos, atualizado_em) VALUES (?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE usos = IF(janela = VALUES(janela), LEAST(usos + 1, 65535), 1),
                    janela = VALUES(janela), atualizado_em = VALUES(atualizado_em)')->execute([$chave, $janela, time()]);
            $q = $pdo->prepare('SELECT usos FROM Email_Limite WHERE chave = ?');
            $q->execute([$chave]);
            $excedeu = $excedeu || (int) $q->fetchColumn() > $limite;
        }
        $pdo->prepare('DELETE FROM Email_Limite WHERE atualizado_em < ?')->execute([time() - 172800]);
        $pdo->commit();
        if ($excedeu) throw new ErroEmailConta('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function emailReautenticar(PDO $pdo, int $id, string $login, string $senha): void
{
    $u = contaUsuario($pdo, $id);
    if ($u['google_sub'] !== null) throw new ErroEmailConta('O e-mail desta conta é gerenciado pelo Google.', 403);
    emailLimitar($pdo, 'identidade', $id, 5, 100, 900);
    $sigaa = (bool) $u['cadastro_sigaa'] || ($_SESSION['auth_user']['origem'] ?? '') === 'sigaa' || $u['forma_login'] === 'sigaa';
    $esperado = $sigaa ? preg_replace('/\D/', '', $u['login']) : $u['login'];
    $informado = $sigaa ? preg_replace('/\D/', '', $login) : trim($login);
    $ok = false;
    if ($senha !== '' && strlen($senha) <= 1024 && hash_equals($esperado, $informado)) {
        $ok = $sigaa ? authSigaa($informado, $senha) !== null : password_verify($senha, $u['hash_senha']);
    }
    if (!$ok) throw new ErroEmailConta('Login ou senha inválidos para esta conta.');
    // Relê a versão após a chamada externa; mudanças em outra sessão invalidam o fluxo anterior.
    $u = contaUsuario($pdo, $id);
    session_regenerate_id(true);
    emailAutorizar($u);
}

function emailDesafio(PDO $pdo, int $id, bool $bloquear = false): ?array
{
    $q = $pdo->prepare('SELECT * FROM Email_Verificacao WHERE id_user = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function emailDesafioDaSessao(array $d): bool
{
    $token = $_SESSION['email_autorizacao']['token'] ?? '';
    return $token !== '' && hash_equals($d['sessao_hash'], hash('sha256', $token));
}

function emailHashCodigo(array $d, string $codigo): string
{
    return hash_hmac('sha256', $d['desafio'] . '|' . $d['id_user'] . '|' . $d['email_novo'] . '|' . $codigo, emailChave());
}

function emailEnviarCodigo(PDO $pdo, int $id, string $email, string $nome): bool
{
    $email = strtolower(trim($email));
    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
        throw new ErroEmailConta('Informe um endereço de e-mail válido.');
    }
    $u = contaUsuario($pdo, $id);
    emailExigirAutorizacao($u);
    $inicial = contaCadastroPendente($u, $_SESSION['auth_user']['origem'] ?? null);
    if ($inicial && !contaNomeValido($nome)) throw new ErroEmailConta('Informe seu nome completo para continuar.');
    if (!empty($u['email_verificado_em']) && strcasecmp((string) $u['email'], $email) === 0) {
        // Nome também pode ser confirmado sem reenviar um e-mail que já foi verificado.
        if ($inicial) {
            $pdo->beginTransaction();
            try {
                $atual = contaUsuario($pdo, $id, true);
                emailExigirAutorizacao($atual);
                $pdo->prepare('UPDATE Usuario SET nome = ?, nome_confirmado_em = UTC_TIMESTAMP() WHERE id_user = ?')->execute([trim($nome), $id]);
                $pdo->prepare('DELETE FROM Email_Verificacao WHERE id_user = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            unset($_SESSION['email_autorizacao'], $_SESSION['csrf_email_conta']);
            $_SESSION['auth_user']['nome'] = trim($nome);
            return true;
        }
        throw new ErroEmailConta('Esse e-mail já está confirmado na sua conta.');
    }
    emailValidarConfiguracao();
    emailLimitar($pdo, 'envio', $id, 5, 200, 3600);
    $pdo->beginTransaction();
    try {
        $u = contaUsuario($pdo, $id, true);
        emailExigirAutorizacao($u);
        $anterior = emailDesafio($pdo, $id, true);
        if ($anterior && (int) $anterior['criado_em'] > time() - 60) {
            throw new ErroEmailConta('Aguarde 60 segundos entre os envios de código.', 429);
        }
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $d = ['id_user' => $id, 'desafio' => bin2hex(random_bytes(32)), 'email_novo' => $email];
        $hash = emailHashCodigo($d, $codigo);
        $expira = min(time() + EMAIL_CODIGO_TTL, $_SESSION['email_autorizacao']['expira']);
        $pdo->prepare('INSERT INTO Email_Verificacao
            (id_user, desafio, sessao_hash, email_anterior, versao_anterior, email_novo, nome_confirmado, codigo_hash, expira_em, tentativas, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE desafio=VALUES(desafio), sessao_hash=VALUES(sessao_hash), email_anterior=VALUES(email_anterior),
                versao_anterior=VALUES(versao_anterior), email_novo=VALUES(email_novo), nome_confirmado=VALUES(nome_confirmado),
                codigo_hash=VALUES(codigo_hash), expira_em=VALUES(expira_em), tentativas=0, criado_em=VALUES(criado_em)')
            ->execute([$id, $d['desafio'], hash('sha256', $_SESSION['email_autorizacao']['token']), $u['email'], $u['email_versao'],
                $email, $inicial ? trim($nome) : null, $hash, $expira, time()]);
        // Mantém o bloqueio por usuário durante o envio: outra sessão não substitui o desafio no meio da operação.
        enviarEmailConta($email, 'Confirme seu e-mail no C.I.R.C.U.I.T.O.',
            "Seu código de confirmação é: $codigo\n\nEle vale por até 10 minutos e pode ser usado uma única vez.\n"
            . "Não compartilhe este código. Se você não solicitou esta confirmação, ignore a mensagem.\n\nC.I.R.C.U.I.T.O.");
        $pdo->commit();
        return false;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function emailConfirmarCodigo(PDO $pdo, int $id, string $codigo): array
{
    $u = contaUsuario($pdo, $id);
    emailExigirAutorizacao($u);
    emailLimitar($pdo, 'codigo', $id, 25, 1000, 3600);
    $pdo->beginTransaction();
    try {
        $u = contaUsuario($pdo, $id, true);
        emailExigirAutorizacao($u);
        $d = emailDesafio($pdo, $id, true);
        if (!$d || !emailDesafioDaSessao($d) || (int) $d['expira_em'] <= time()
            || (int) $d['versao_anterior'] !== (int) $u['email_versao'] || $d['email_anterior'] !== $u['email']
            || (int) $d['tentativas'] >= EMAIL_TENTATIVAS) {
            throw new ErroEmailConta('Código expirado ou indisponível. Solicite um novo código.');
        }
        if (!preg_match('/^[0-9]{6}$/D', $codigo) || !hash_equals($d['codigo_hash'], emailHashCodigo($d, $codigo))) {
            $pdo->prepare('UPDATE Email_Verificacao SET tentativas = tentativas + 1 WHERE id_user = ?')->execute([$id]);
            $pdo->commit();
            throw new ErroEmailConta((int) $d['tentativas'] + 1 >= EMAIL_TENTATIVAS
                ? 'Limite de tentativas atingido. Solicite um novo código.' : 'Código incorreto. Confira os seis números recebidos.');
        }
        $novoNome = $d['nome_confirmado'] ?? $u['nome'];
        $pdo->prepare('UPDATE Usuario SET email = ?, email_verificado_em = UTC_TIMESTAMP(), email_versao = email_versao + 1,
            nome = ?, nome_confirmado_em = IF(? IS NOT NULL, UTC_TIMESTAMP(), nome_confirmado_em) WHERE id_user = ?')
            ->execute([$d['email_novo'], $novoNome, $d['nome_confirmado'], $id]);
        $pdo->prepare('DELETE FROM Email_Verificacao WHERE id_user = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO Log_Auditoria(id_user, origem_ip, acao, tabela_afetada)
            VALUES (?, ?, 'email_confirmado', 'Usuario')")->execute([$id, $_SERVER['REMOTE_ADDR'] ?? null]);
        $resultado = contaUsuario($pdo, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    unset($_SESSION['email_autorizacao'], $_SESSION['csrf_email_conta']);
    $_SESSION['auth_user']['email'] = $resultado['email'];
    $_SESSION['auth_user']['nome'] = $resultado['nome'];
    session_regenerate_id(true);
    if (!empty($u['email_verificado_em']) && $u['email'] !== $resultado['email']) {
        try {
            enviarEmailConta($u['email'], 'O e-mail da sua conta C.I.R.C.U.I.T.O. foi alterado',
                "O endereço de contato da sua conta foi alterado após confirmação de identidade e do novo e-mail.\n"
                . "Se não foi você, entre em contato com a equipe do laboratório imediatamente.\nSeu login institucional continua o mesmo.");
        } catch (Throwable) {
            // Não registra e-mails, códigos, senha SIGAA nem respostas SMTP nos logs.
            error_log('CIRCUITO: falha ao enviar aviso de alteração ao endereço anterior.');
        }
    }
    return $resultado;
}
