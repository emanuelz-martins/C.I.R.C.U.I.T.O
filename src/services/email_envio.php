<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

/** A saída local fica fora do diretório servido pelo Apache e nunca é enviada ao navegador. */
function emailDiretorioLocal(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/circuito-email-' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 16);
}

function emailTransporte(): string
{
    loadEnv(dirname(__DIR__, 2) . '/.env');
    $transporte = (string) env('MAIL_TRANSPORT', 'smtp');
    if ($transporte === 'local') {
        $host = strtolower((string) parse_url((string) env('APP_URL'), PHP_URL_HOST));
        if (env('APP_ENV') !== 'local' || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new RuntimeException('Transporte de desenvolvimento indisponível neste ambiente.');
        }
    } elseif ($transporte !== 'smtp') {
        throw new RuntimeException('Transporte de e-mail não configurado.');
    }
    return $transporte;
}

function emailValidarConfiguracao(): void
{
    $transporte = emailTransporte();
    if (!filter_var(env('MAIL_FROM_ADDRESS'), FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configure o remetente de e-mail.');
    }
    if ($transporte === 'smtp' && (env('SMTP_HOST') === null || env('SMTP_USERNAME') === null
        || env('SMTP_PASSWORD') === null || !in_array(env('SMTP_ENCRYPTION', 'tls'), ['tls', 'smtps'], true)
        || !in_array((int) env('SMTP_PORT', '587'), [465, 587], true))) {
        throw new RuntimeException('Configure o SMTP para enviar e-mails.');
    }
}

function enviarEmailConta(string $destinatario, string $assunto, string $mensagem): void
{
    emailValidarConfiguracao();
    if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $destinatario . $assunto)) {
        throw new InvalidArgumentException('Endereço de e-mail inválido.');
    }
    if (emailTransporte() === 'local') {
        $dir = emailDiretorioLocal();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar a caixa de testes local.');
        }
        $arquivo = $dir . '/' . bin2hex(random_bytes(16)) . '.json';
        $dados = json_encode(['para' => $destinatario, 'assunto' => $assunto, 'mensagem' => $mensagem,
            'criado_em' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($arquivo, $dados, LOCK_EX) === false) throw new RuntimeException('Falha na saída local.');
        @chmod($arquivo, 0600);
        return;
    }

    $mail = criarMailerConta();
    $mail->addAddress($destinatario);
    $mail->Subject = $assunto;
    $mail->Body = $mensagem;
    $mail->send();
}

/** Prepara a conexão sem enviar mensagem; também permite conferir o SMTP com segurança. */
function criarMailerConta(): \PHPMailer\PHPMailer\PHPMailer
{
    emailValidarConfiguracao();
    if (emailTransporte() !== 'smtp') throw new RuntimeException('SMTP desativado neste ambiente.');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $host = (string) env('SMTP_HOST');
    $mail->Host = $host;
    if (filter_var(env('SMTP_FORCE_IPV4', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        $ipv4 = gethostbyname($host);
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Não foi possível resolver o servidor SMTP por IPv4.');
        }
        $mail->Host = $ipv4;
        // O certificado continua sendo validado para o domínio original, nunca para um IP fixo.
        $mail->SMTPOptions = ['ssl' => ['peer_name' => $host, 'verify_peer' => true,
            'verify_peer_name' => true, 'allow_self_signed' => false, 'SNI_enabled' => true]];
    }
    $mail->Port = (int) env('SMTP_PORT', '587');
    $mail->SMTPAuth = true;
    $mail->Username = (string) env('SMTP_USERNAME');
    $mail->Password = (string) env('SMTP_PASSWORD');
    $mail->SMTPSecure = env('SMTP_ENCRYPTION', 'tls') === 'smtps'
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    // Mantém a validação TLS padrão. Nunca expõe diálogos SMTP ou credenciais ao usuário.
    $mail->SMTPDebug = 0;
    $mail->Timeout = 10;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) env('MAIL_FROM_ADDRESS'), (string) env('MAIL_FROM_NAME', 'C.I.R.C.U.I.T.O.'));
    return $mail;
}
