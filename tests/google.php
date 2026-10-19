<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/services/google.php';
require_once __DIR__ . '/../src/services/usuarios.php';
loadEnv(dirname(__DIR__) . '/.env');
$base = rtrim((string) env('APP_URL'), '/');
if (PHP_SAPI !== 'cli' || env('APP_ENV') !== 'local' || env('DB_PROFILE') !== 'xampp'
    || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || !in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Use somente o banco e a aplicação locais do XAMPP.');
$checks = 0;
$ids = [];
$pdo = db();
$originais = $pdo->query('SELECT id_user, login, nome, tipo_perfil, email, foto_perfil, google_sub FROM Usuario ORDER BY id_user')->fetchAll();
function conferir(bool $condicao, string $descricao): void {
    global $checks;
    if (!$condicao) throw new RuntimeException('FALHOU: ' . $descricao);
    $checks++;
    echo "OK: $descricao\n";
}
function rejeitar(callable $acao, string $descricao): void {
    try { $acao(); } catch (Throwable) { conferir(true, $descricao); return; }
    throw new RuntimeException('FALHOU: ' . $descricao);
}
$opcoesRsa = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if (PHP_OS_FAMILY === 'Windows') $opcoesRsa['config'] = 'C:/xampp/php/extras/openssl/openssl.cnf';
$chave = openssl_pkey_new($opcoesRsa);
if ($chave === false) throw new RuntimeException('Não foi possível gerar a chave temporária de teste.');
openssl_pkey_export($chave, $privada, null, $opcoesRsa);
$rsa = openssl_pkey_get_details($chave)['rsa'];
$base64url = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
$jwks = ['keys' => [['kid' => 'teste', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $base64url($rsa['n']), 'e' => $base64url($rsa['e'])]]];
$clientId = 'teste-offline.apps.googleusercontent.com';
$nonce = bin2hex(random_bytes(32));
$sub = (string) random_int(1000000000, 9999999999);
$claims = ['iss' => 'https://accounts.google.com', 'aud' => $clientId, 'sub' => $sub,
    'iat' => time() - 5, 'exp' => time() + 300, 'nonce' => $nonce,
    'email' => 'teste.12345@aluno.iffar.edu.br', 'email_verified' => true,
    'hd' => 'aluno.iffar.edu.br', 'name' => 'Validação Google', 'picture' => 'https://lh3.googleusercontent.com/a/exemplo'];
$validar = static function (array $valores) use ($privada, $jwks, $clientId, $nonce): array {
    $jwt = \Firebase\JWT\JWT::encode($valores, $privada, 'RS256', 'teste');
    return verificarIdentidadeGoogle($jwt, $jwks, $clientId, $nonce);
};
try {
    $perfil = $validar($claims);
    conferir($perfil['email'] === $claims['email'] && $perfil['nome'] === $claims['name'] && $perfil['foto'] === $claims['picture'], 'token assinado válido fornece nome, e-mail e foto');
    foreach (['aud' => 'outro.apps.googleusercontent.com', 'iss' => 'https://outro.example', 'nonce' => 'outro', 'exp' => time() - 30,
        'iat' => time() + 300, 'hd' => 'iffar.edu.br', 'email_verified' => false, 'sub' => '', 'name' => ''] as $campo => $valor) {
        rejeitar(fn () => $validar(array_replace($claims, [$campo => $valor])), 'rejeita claim inválida: ' . $campo);
    }
    foreach (['aluno@gmail.com', 'teste.12345@aluno.iffar.edu.br.evil.example', 'teste.12345@iffar.edu.br',
        'teste@aluno.iffar.edu.br', 'teste.1234@aluno.iffar.edu.br', 'teste.123456@aluno.iffar.edu.br',
        'teste.12345+alias@aluno.iffar.edu.br'] as $email) {
        rejeitar(fn () => $validar(array_replace($claims, ['email' => $email])), 'rejeita e-mail fora do padrão: ' . $email);
    }
    rejeitar(fn () => $validar(array_replace($claims, ['email_verified' => 'true'])), 'verificação do e-mail exige booleano verdadeiro');
    $jwtValido = \Firebase\JWT\JWT::encode($claims, $privada, 'RS256', 'teste');
    $partes = explode('.', $jwtValido);
    $partes[1] = $base64url(json_encode(array_replace($claims, ['name' => 'Alterado'])));
    rejeitar(fn () => verificarIdentidadeGoogle(implode('.', $partes), $jwks, $clientId, $nonce), 'rejeita assinatura adulterada');
    $jwtHs = \Firebase\JWT\JWT::encode($claims, str_repeat('x', 32), 'HS256', 'teste');
    rejeitar(fn () => verificarIdentidadeGoogle($jwtHs, $jwks, $clientId, $nonce), 'rejeita troca do algoritmo RSA por HMAC');
    foreach (['http://lh3.googleusercontent.com/a/x', 'https://evil.example/foto', 'data:image/svg+xml,x'] as $foto) {
        conferir($validar(array_replace($claims, ['picture' => $foto]))['foto'] === null, 'ignora foto fora do HTTPS/host Google');
    }
    conferir($validar(array_diff_key($claims, ['picture' => 1]))['foto'] === null, 'foto ausente não impede login institucional');
    $contaSigaa = sincronizarUsuario($pdo, null, '123.456.789-01', 'SIGAA de teste');
    $ids[] = (int) $contaSigaa['id_user'];
    conferir($contaSigaa['login'] === '123.456.789-01', 'SIGAA mantém CPF formatado somente no campo login');
    $legado = sincronizarUsuario($pdo, null, 'legado-' . bin2hex(random_bytes(5)), 'Legado de teste');
    $ids[] = (int) $legado['id_user'];
    $pdo->prepare('UPDATE Usuario SET email = ? WHERE id_user = ?')->execute([$perfil['email'], $legado['id_user']]);
    $googleUser = sincronizarUsuarioGoogle($pdo, $perfil);
    $ids[] = (int) $googleUser['id_user'];
    conferir($googleUser['tipo_perfil'] === 'estudante' && $googleUser['forma_login'] === 'google', 'conta Google nova começa como Aluno');
    conferir($googleUser['id_user'] !== $legado['id_user'], 'não vincula conta antiga apenas por e-mail não verificado');
    conferir($googleUser['foto_perfil'] === $perfil['foto'] && $googleUser['nome'] === $perfil['nome'], 'persiste nome, e-mail e foto no banco');
    foreach (['laboratorista', 'admin', 'estudante'] as $role) {
        $pdo->prepare('UPDATE Usuario SET tipo_perfil = ? WHERE id_user = ?')->execute([$role, $googleUser['id_user']]);
        $atualizado = sincronizarUsuarioGoogle($pdo, array_replace($perfil, ['nome' => 'Nome atualizado']));
        conferir($atualizado['id_user'] === $googleUser['id_user'] && $atualizado['tipo_perfil'] === $role, 'novo login preserva perfil ' . $role . ' e ID');
    }
    $pdo->prepare('UPDATE Usuario SET pre_bloqueado_manual = 1 WHERE id_user = ?')->execute([$googleUser['id_user']]);
    conferir((int) sincronizarUsuarioGoogle($pdo, $perfil)['pre_bloqueado_manual'] === 1, 'bloqueio de pedidos é preservado no login Google');
    $pdo->prepare('UPDATE Usuario SET bloqueado = 1 WHERE id_user = ?')->execute([$googleUser['id_user']]);
    rejeitar(fn () => sincronizarUsuarioGoogle($pdo, $perfil), 'conta banida não entra pelo Google');
    conferir(str_starts_with($googleUser['login'], 'google-') && !preg_match('/^[0-9.-]+$/D', $googleUser['login']), 'Google gera seu próprio login sem pedir ou derivar CPF');

    $http = curl_init();
    curl_setopt_array($http, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
    $pedir = static function (string $path, ?string $body = null, array $headers = []) use ($http, $base): array {
        curl_setopt($http, CURLOPT_URL, $base . $path);
        curl_setopt($http, CURLOPT_HTTPHEADER, $headers);
        if ($body === null) curl_setopt($http, CURLOPT_HTTPGET, true);
        else { curl_setopt($http, CURLOPT_POST, true); curl_setopt($http, CURLOPT_POSTFIELDS, $body); }
        $html = curl_exec($http);
        if ($html === false) throw new RuntimeException('Falha HTTP local: ' . curl_error($http));
        return [(int) curl_getinfo($http, CURLINFO_RESPONSE_CODE), $html];
    };
    [$status, $html] = $pedir('/login.php');
    conferir($status === 200 && str_contains($html, 'id="googleConfig"'), 'tela oferece configuração Google sem erros');
    preg_match('/<script type="application\/json" id="googleConfig">(.*?)<\/script>/s', $html, $configMatch);
    $config = json_decode($configMatch[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    [$status] = $pedir('/google_login.php');
    conferir($status === 405, 'endpoint Google recusa GET');
    [$status] = $pedir('/google_login.php', '{}', ['Content-Type: application/json', 'X-CSRF-Token: errado']);
    conferir($status === 403, 'endpoint Google recusa CSRF incorreto');
    [$status] = $pedir('/google_login.php', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $config['csrf']]);
    conferir($status === (googleClientId() === '' ? 503 : 400), 'endpoint exige configuração e credencial, mesmo com CSRF correto');
    $senhaTeste = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE Usuario SET bloqueado = 0, hash_senha = ? WHERE id_user = ?')->execute([password_hash($senhaTeste, PASSWORD_DEFAULT), $googleUser['id_user']]);
    [$status, $html] = $pedir('/login.php', http_build_query(['tipo_acesso' => 'teste', 'usuario' => $googleUser['login'], 'senha' => $senhaTeste]), ['Content-Type: application/x-www-form-urlencoded']);
    conferir($status === 200 && str_contains($html, 'Use Entrar com o Google'), 'conta Google não admite entrada pelo teste local');
    curl_close($http);
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($ids) {
        $pdo->prepare('DELETE FROM Usuario WHERE id_user IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
    }
}
conferir($originais === $pdo->query('SELECT id_user, login, nome, tipo_perfil, email, foto_perfil, google_sub FROM Usuario ORDER BY id_user')->fetchAll(), 'contas originais preservadas; contas de teste removidas');
echo "\n$checks verificações Google passaram (tokens e chaves sintéticos; sem autenticar no Google real).\n";
