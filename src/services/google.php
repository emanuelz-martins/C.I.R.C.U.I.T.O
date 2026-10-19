<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

const GOOGLE_DOMINIO_ALUNO = 'aluno.iffar.edu.br';

function googleClientId(): string
{
    loadEnv(dirname(__DIR__, 2) . '/.env');
    $id = trim((string) env('GOOGLE_CLIENT_ID', ''));
    return preg_match('/^[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D', $id) ? $id : '';
}

function googleEmailAlunoValido(string $email): bool
{
    // Confere apenas o padrão do e-mail. Não extrai nem reconstrói CPF.
    return strlen($email) <= 150
        && preg_match('/^[a-z][a-z0-9._-]*\.[0-9]{5}@aluno\.iffar\.edu\.br$/D', $email) === 1;
}

function googleChavesPublicas(bool $renovar = false): array
{
    $pastaCache = dirname(__DIR__, 2) . '/var/cache';
    $cache = $pastaCache . '/google-jwks.json';
    if (is_file($cache)) {
        $dados = json_decode((string) @file_get_contents($cache), true);
        if (is_array($dados) && !empty($dados['jwks']['keys'])
            && ((!$renovar && ($dados['expira'] ?? 0) > time()) || ($renovar && ($dados['atualizado'] ?? 0) > time() - 30))) return $dados['jwks'];
    }
    $maxAge = 60;
    $curl = curl_init('https://www.googleapis.com/oauth2/v3/certs');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $linha) use (&$maxAge): int {
            if (stripos($linha, 'Cache-Control:') === 0 && preg_match('/max-age=(\d+)/i', $linha, $m)) $maxAge = min(300, (int) $m[1]);
            return strlen($linha);
        }]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $jwks = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($jwks) || empty($jwks['keys'])) throw new RuntimeException('Não foi possível verificar a conta Google agora. Tente novamente.');
    if (is_dir($pastaCache) || @mkdir($pastaCache, 0700, true)) {
        @file_put_contents($cache, json_encode(['atualizado' => time(), 'expira' => time() + $maxAge, 'jwks' => $jwks]), LOCK_EX);
        @chmod($cache, 0600);
    }
    return $jwks;
}

function verificarIdentidadeGoogle(string $token, array $jwks, string $clientId, string $nonce): array
{
    if ($token === '' || strlen($token) > 20000 || $clientId === '' || $nonce === '') throw new InvalidArgumentException('Identificação Google inválida.');
    $chaves = array_filter($jwks['keys'] ?? [], static fn (array $chave): bool =>
        ($chave['kty'] ?? '') === 'RSA' && ($chave['alg'] ?? 'RS256') === 'RS256' && ($chave['use'] ?? 'sig') === 'sig');
    $claims = (array) \Firebase\JWT\JWT::decode($token, \Firebase\JWT\JWK::parseKeySet(['keys' => array_values($chaves)], 'RS256'));
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
        || ($claims['aud'] ?? '') !== $clientId
        || (isset($claims['azp']) && $claims['azp'] !== $clientId)
        || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
        || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 30
        || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
        throw new InvalidArgumentException('Não foi possível validar a identificação Google. Recarregue a página.');
    }
    $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';
    if (($claims['email_verified'] ?? null) !== true
        || ($claims['hd'] ?? '') !== GOOGLE_DOMINIO_ALUNO || !googleEmailAlunoValido($email)) {
        throw new DomainException('Use uma conta institucional no formato nome.12345@aluno.iffar.edu.br.');
    }
    $sub = $claims['sub'] ?? '';
    $nome = is_string($claims['name'] ?? null) ? trim($claims['name']) : '';
    if (!is_string($sub) || !preg_match('/^[\x21-\x7E]{1,255}$/D', $sub) || $nome === '' || mb_strlen($nome) > 150) {
        throw new InvalidArgumentException('A conta Google não forneceu uma identificação e um nome válidos.');
    }
    $foto = is_string($claims['picture'] ?? null) ? $claims['picture'] : '';
    $host = strtolower((string) parse_url($foto, PHP_URL_HOST));
    if (strlen($foto) > 2048 || parse_url($foto, PHP_URL_SCHEME) !== 'https'
        || !($host === 'googleusercontent.com' || str_ends_with($host, '.googleusercontent.com') || $host === 'lh3.google.com')) $foto = '';
    return ['sub' => $sub, 'nome' => $nome, 'email' => $email, 'foto' => $foto ?: null];
}

/** Recebe somente o perfil devolvido pelo verificador de token. Nunca vincula por CPF/e-mail. */
function sincronizarUsuarioGoogle(PDO $pdo, array $perfil): array
{
    if (!is_string($perfil['sub'] ?? null) || !preg_match('/^[\x21-\x7E]{1,255}$/D', $perfil['sub'])
        || !googleEmailAlunoValido((string) ($perfil['email'] ?? '')) || trim((string) ($perfil['nome'] ?? '')) === '') {
        throw new InvalidArgumentException('Perfil institucional Google inválido.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM Usuario WHERE google_sub = ? FOR UPDATE');
        $stmt->execute([$perfil['sub']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && ((int) $user['bloqueado'] !== 0 || !in_array($user['tipo_perfil'], ['estudante', 'laboratorista', 'admin'], true))) {
            throw new DomainException('Acesso indisponível. Entre em contato com o administrador.');
        }
        if (!$user) {
            $pdo->prepare("INSERT INTO Usuario (nome, login, hash_senha, email, foto_perfil, google_sub, tipo_perfil, forma_login) VALUES (?, ?, ?, ?, ?, ?, 'estudante', 'google')")
                ->execute([$perfil['nome'], 'google-' . hash('sha256', $perfil['sub']), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $perfil['email'], $perfil['foto'], $perfil['sub']]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $user['id_user'];
            $pdo->prepare("UPDATE Usuario SET nome = ?, email = ?, foto_perfil = COALESCE(?, foto_perfil), forma_login = 'google' WHERE id_user = ?")
                ->execute([$perfil['nome'], $perfil['email'], $perfil['foto'], $id]);
        }
        $stmt = $pdo->prepare('SELECT * FROM Usuario WHERE id_user = ?');
        $stmt->execute([$id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
