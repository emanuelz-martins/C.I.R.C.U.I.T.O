<?php
declare(strict_types=1);

/**
 * Autentica um CPF no serviço institucional do IFFar usando o modo SIGAA.
 * A senha é usada somente nesta requisição e não é persistida localmente.
 *
 * @return array{nome: ?string, email: ?string}|null
 */
function authSigaa(string $cpf, string $senha): ?array
{
    $cpfNormalizado = preg_replace('/\D/', '', $cpf);
    if (!is_string($cpfNormalizado) || strlen($cpfNormalizado) !== 11 || $senha === '') {
        return null;
    }

    loadEnv(dirname(__DIR__, 2) . '/.env');
    $url = (string) env('IFFAR_AUTH_URL', 'https://www3.fw.iffarroupilha.edu.br/auth/index.php');

    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP não está habilitada no servidor.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com o SIGAA.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'user' => $cpfNormalizado,
            'pass' => $senha,
            'tipo' => 'S',
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException('Não foi possível acessar o SIGAA no momento.');
    }

    $data = json_decode((string) $body, true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
        return null;
    }

    $status = strtolower(trim((string) ($data['status'] ?? $data['result'] ?? '')));
    $autenticado = ($data['authenticated'] ?? false) === true
        || ($data['success'] ?? false) === true
        || in_array($status, ['success', 'ok', 'authenticated'], true);

    if (!$autenticado) {
        return null;
    }

    $nome = $data['nome'] ?? $data['name'] ?? null;
    $email = $data['email'] ?? $data['mail'] ?? null;

    return [
        'nome' => is_string($nome) && trim($nome) !== '' ? trim($nome) : null,
        'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
    ];
}
