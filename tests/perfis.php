<?php
declare(strict_types=1);

/**
 * Teste de integração local: php tests/perfis.php
 * Usa contas temporárias no banco local e remove todas elas no finally.
 * Requer a aplicação com estes mesmos arquivos servida no APP_URL do .env.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/services/usuarios.php';
require_once __DIR__ . '/../src/services/avisos_fixados.php';
loadEnv(dirname(__DIR__) . '/.env');
$base = rtrim((string) env('APP_URL'), '/');
if (env('APP_ENV') !== 'local' || env('DB_PROFILE') !== 'xampp'
    || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || !in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Execute apenas com a aplicação e o banco locais configurados no .env.');
}

$pdo = db();
$ids = [];
$previewCategoria = null;
$previewComponente = null;
$clients = [];
$checks = 0;
$password = bin2hex(random_bytes(16));
$prefix = 'Teste perfis ' . bin2hex(random_bytes(5));
$originalUsers = $pdo->query('SELECT id_user, tipo_perfil, forma_login FROM Usuario ORDER BY id_user')->fetchAll();

function verificar(bool $ok, string $descricao): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FALHOU: ' . $descricao);
    }
    $checks++;
    echo "OK: {$descricao}\n";
}

function cliente(): CurlHandle
{
    global $clients;
    $c = curl_init();
    curl_setopt_array($c, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
    $clients[] = $c;
    return $c;
}

function requisicao(CurlHandle $c, string $path, ?array $post = null, bool $json = false, bool $corpoJson = false): array
{
    global $base;
    $headers = [];
    curl_setopt($c, CURLOPT_URL, $base . $path);
    $requestHeaders = $json ? ['Accept: application/json'] : [];
    if ($corpoJson) { $requestHeaders[] = 'Content-Type: application/json'; }
    curl_setopt($c, CURLOPT_HTTPHEADER, $requestHeaders);
    curl_setopt($c, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$headers): int {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
        return strlen($line);
    });
    if ($post === null) {
        curl_setopt($c, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $corpoJson ? json_encode($post, JSON_THROW_ON_ERROR) : http_build_query($post));
    }
    $body = curl_exec($c);
    if ($body === false) {
        throw new RuntimeException(curl_error($c));
    }
    verificar(!preg_match('/<(?:b|br)[^>]*>\s*(?:Warning|Fatal error|Notice)|<b>(?:Warning|Fatal error|Notice)/', $body), 'resposta sem erros PHP: ' . $path);
    return ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => $body, 'headers' => $headers];
}

function perfil(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT tipo_perfil FROM Usuario WHERE id_user = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
}

function formaLogin(PDO $pdo, int $id): ?string
{
    $stmt = $pdo->prepare('SELECT forma_login FROM Usuario WHERE id_user = ?');
    $stmt->execute([$id]);
    $forma = $stmt->fetchColumn();
    return is_string($forma) ? $forma : null;
}

try {
    $users = [];
    foreach (['admin', 'outro_admin', 'estudante', 'laboratorista', 'bloqueado', 'invalido'] as $nome) {
        do {
            $digits = '9' . (string) random_int(1000000000, 9999999999);
            $login = preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits);
            $exists = $pdo->prepare('SELECT 1 FROM Usuario WHERE login = ?');
            $exists->execute([$login]);
        } while ($exists->fetchColumn());
        $user = sincronizarUsuario($pdo, null, $login, $prefix . ' ' . $nome);
        $id = (int) $user['id_user'];
        $ids[] = $id;
        verificar(perfil($pdo, $id) === 'estudante', 'novo usuário começa como aluno: ' . $nome);
        verificar(formaLogin($pdo, $id) === null, 'conta sem login não recebe método presumido');
        $role = match ($nome) { 'admin', 'outro_admin' => 'admin', 'laboratorista' => 'laboratorista', 'invalido' => 'desconhecido', default => 'estudante' };
        $pdo->prepare('UPDATE Usuario SET tipo_perfil = ?, hash_senha = ?, bloqueado = ? WHERE id_user = ?')
            ->execute([$role, password_hash($password, PASSWORD_DEFAULT), $nome === 'bloqueado' ? 1 : 0, $id]);
        $users[$nome] = ['id' => $id, 'login' => $login, 'client' => cliente()];
        $r = requisicao($users[$nome]['client'], '/login.php', ['tipo_acesso' => 'teste', 'cpf' => $digits, 'senha' => $password]);
        verificar($r['status'] === ($nome === 'bloqueado' ? 200 : 302), 'login local: ' . $nome);
        verificar(formaLogin($pdo, $id) === ($nome === 'bloqueado' ? null : 'teste'), 'apenas login local bem-sucedido registra Teste');
    }

    $anonimo = cliente();
    $r = requisicao($anonimo, '/pages_admin/perfis.php');
    $loginPath = rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/login.php';
    verificar($r['status'] === 302 && $r['headers']['location'] === $loginPath, 'sem sessão redireciona para login');
    verificar(requisicao($anonimo, '/api/carrinho_add.php')['status'] === 401, 'API sem sessão retorna 401');

    $student = $users['estudante']['client'];
    $lab = $users['laboratorista']['client'];
    $admin = $users['admin']['client'];
    foreach (['google', 'sigaa', 'teste'] as $forma) {
        registrarFormaLogin($pdo, $users['laboratorista']['id'], $forma);
        verificar(formaLogin($pdo, $users['laboratorista']['id']) === $forma && perfil($pdo, $users['laboratorista']['id']) === 'laboratorista', 'registro do método ' . $forma . ' preserva o perfil');
    }
    registrarFormaLogin($pdo, $users['laboratorista']['id'], 'sigaa');
    $r = requisicao(cliente(), '/login.php', ['tipo_acesso' => 'teste', 'cpf' => $users['laboratorista']['login'], 'senha' => $password . '-invalida']);
    verificar($r['status'] === 200 && !isset($r['headers']['location']) && formaLogin($pdo, $users['laboratorista']['id']) === 'sigaa', 'senha incorreta não altera a forma do último login');
    foreach (['/pages_admin/index.php', '/pages_admin/perfis.php', '/pages_laboratorista/index.php', '/api/admin_pedidos_usuario.php', '/api/pedido_arquivar_lab.php'] as $path) {
        verificar(requisicao($student, $path)['status'] === 403, 'aluno não acessa: ' . $path);
    }
    foreach (['/pages_admin/index.php', '/pages_admin/perfis.php', '/pages_aluno/profile.php', '/api/carrinho_add.php'] as $path) {
        verificar(requisicao($lab, $path)['status'] === 403, 'laboratorista não acessa: ' . $path);
    }
    verificar(requisicao($lab, '/pages_laboratorista/index.php')['status'] === 200, 'laboratorista acessa seu painel');
    verificar(requisicao($student, '/index.php')['status'] === 200, 'aluno acessa seu painel');
    verificar(requisicao($admin, '/pages_admin/index.php')['status'] === 200, 'administrador acessa seu painel');
    verificar(requisicao($users['invalido']['client'], '/index.php')['status'] === 403, 'perfil desconhecido não obtém acesso');

    $list = requisicao($admin, '/pages_admin/perfis.php?q=' . rawurlencode($prefix));
    verificar($list['status'] === 200 && str_contains($list['body'], $prefix), 'busca por nome funciona com parâmetros preparados');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $list['body'], $m);
    $token = $m[1] ?? '';
    verificar(strlen($token) === 64, 'formulário fornece token CSRF');
    $cpfSemMascara = preg_replace('/\D/', '', $users['estudante']['login']);
    $r = requisicao($admin, '/pages_admin/perfis.php?q=' . $cpfSemMascara);
    verificar($r['status'] === 200 && str_contains($r['body'], $users['estudante']['login']) && str_contains($r['body'], '1 usuário(s)'), 'busca CPF sem pontuação');
    $r = requisicao($admin, '/pages_admin/perfis.php?q=' . rawurlencode($prefix) . '&perfil=laboratorista');
    verificar($r['status'] === 200 && str_contains($r['body'], '1 usuário(s)') && str_contains($r['body'], $users['laboratorista']['login']), 'combina busca com filtro de perfil');
    $post = ['csrf_token' => $token, 'id_user' => $users['estudante']['id'], 'tipo_perfil' => 'laboratorista'];
    foreach (['', 'invalido'] as $bad) {
        verificar(requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['csrf_token' => $bad]))['status'] === 403, 'rejeita CSRF ausente ou inválido');
        verificar(perfil($pdo, $users['estudante']['id']) === 'estudante', 'CSRF não altera perfil');
    }
    verificar(requisicao($lab, '/pages_admin/perfis.php', $post)['status'] === 403, 'laboratorista não altera perfis com POST forjado');
    verificar(requisicao($student, '/pages_admin/perfis.php', $post)['status'] === 403, 'aluno não altera perfis com POST forjado');
    verificar(requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['tipo_perfil' => 'superadmin']))['status'] === 422, 'rejeita perfil inválido');
    verificar(requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['id_user' => 0]))['status'] === 422, 'rejeita usuário inexistente');
    verificar(requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['id_user' => $users['admin']['id']]))['status'] === 422, 'impede alteração do próprio perfil');

    verificar(requisicao($admin, '/pages_admin/perfis.php', $post)['status'] === 303, 'administrador promove aluno a laboratorista');
    verificar(requisicao($student, '/pages_laboratorista/index.php')['status'] === 200, 'sessão já aberta recebe novo acesso');
    verificar(requisicao($student, '/pages_aluno/profile.php')['status'] === 403, 'perfil promovido perde recursos exclusivos de aluno');

    foreach (['laboratorista', 'admin', 'estudante'] as $role) {
        $r = requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['tipo_perfil' => $role]));
        verificar($r['status'] === 303 && perfil($pdo, $users['estudante']['id']) === $role, 'salva perfil ' . $role);
        $stmt = $pdo->prepare('SELECT * FROM Usuario WHERE id_user = ?');
        $stmt->execute([$users['estudante']['id']]);
        $synced = sincronizarUsuario($pdo, $stmt->fetch(), $users['estudante']['login'], $prefix . ' sincronizado');
        verificar($synced['tipo_perfil'] === $role && perfil($pdo, $users['estudante']['id']) === $role, 'sincronização institucional preserva perfil ' . $role);
        verificar(formaLogin($pdo, $users['estudante']['id']) === 'teste', 'alteração de perfil não muda a forma de login');
        verificar(requisicao($student, '/pages_admin/index.php')['status'] === ($role === 'admin' ? 200 : 403), 'permissão administrativa atualizada sem novo login: ' . $role);
    }

    $pdo->prepare('UPDATE Usuario SET matricula = ?, email = ? WHERE id_user = ?')
        ->execute(['20260001', 'aluno@example.invalid', $users['estudante']['id']]);
    $r = requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['tipo_perfil' => 'estudante']), true);
    $json = json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR);
    verificar($r['status'] === 200 && $json['ok'] && !isset($r['headers']['location']), 'salva via AJAX sem redirecionar a página');
    verificar((int) $json['usuario']['id_user'] === $users['estudante']['id'] && $json['usuario']['matricula'] === '20260001' && $json['usuario']['email'] === 'aluno@example.invalid' && $json['usuario']['forma_login'] === 'teste' && $json['usuario']['tipo_perfil'] === 'estudante', 'retorna dados, forma de login e perfil do usuário selecionado');
    $r = requisicao($admin, '/pages_admin/perfis.php', array_replace($post, ['csrf_token' => 'invalido']), true);
    verificar($r['status'] === 403 && json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'] === false, 'AJAX também exige CSRF válido');

    $audit = $pdo->prepare("SELECT detalhes_json FROM Log_Auditoria WHERE id_user = ? AND acao = 'alterar_perfil' ORDER BY id_log");
    $audit->execute([$users['admin']['id']]);
    $events = $audit->fetchAll(PDO::FETCH_COLUMN);
    verificar(count($events) === 3, 'auditoria registra apenas as três mudanças reais');
    $first = json_decode($events[0], true, 512, JSON_THROW_ON_ERROR);
    verificar($first['id_usuario'] === $users['estudante']['id'] && $first['perfil_anterior'] === 'estudante' && $first['perfil_novo'] === 'laboratorista', 'auditoria registra alvo e perfis anterior e novo');

    $labPagina = '/pages_laboratorista/gerenciar_user.php';
    $listaLab = requisicao($lab, $labPagina . '?q=' . rawurlencode($prefix));
    preg_match('/name="csrf_gerenciar_user" value="([a-f0-9]+)"/', $listaLab['body'], $m);
    $labToken = $m[1] ?? '';
    verificar(strlen($labToken) === 64, 'gestão do laboratorista fornece CSRF');
    verificar(!str_contains($listaLab['body'], 'Login:') && !str_contains($listaLab['body'], $users['estudante']['login']), 'lista do laboratorista não revela o login');
    verificar(!str_contains($listaLab['body'], 'Pré-bloquear') && !str_contains($listaLab['body'], 'Remover pré-bloqueio') && !str_contains($listaLab['body'], 'onclick="toggleBlock('), 'menu do laboratorista elimina nomes antigos e ação de banimento');
    verificar(str_contains($listaLab['body'], 'Matrícula: 20260001'), 'matrícula continua disponível ao laboratorista');
    $labPost = ['csrf_gerenciar_user' => $labToken, 'action' => 'toggle_preblock', 'id_user' => $users['estudante']['id'], 'motivo' => 'Bloqueio temporário de teste'];
    verificar(requisicao($lab, $labPagina, array_replace($labPost, ['csrf_gerenciar_user' => 'invalido']), true)['status'] === 403, 'bloqueio pelo laboratorista exige CSRF');
    $r = requisicao($lab, $labPagina, array_replace($labPost, ['id_user' => $users['admin']['id']]), true);
    verificar($r['status'] === 403, 'laboratorista não administra conta de administrador');
    verificar(requisicao($lab, $labPagina, array_replace($labPost, ['action' => 'toggle_block']), true)['status'] === 403, 'laboratorista não pode banir aluno com POST forjado');
    $r = requisicao($lab, $labPagina, $labPost, true);
    verificar($r['status'] === 200 && json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'], 'laboratorista bloqueia apenas pedidos do aluno');
    $estadoLab = $pdo->query('SELECT bloqueado, pre_bloqueado_manual FROM Usuario WHERE id_user = ' . $users['estudante']['id'])->fetch();
    verificar((int) $estadoLab['bloqueado'] === 0 && (int) $estadoLab['pre_bloqueado_manual'] === 1, 'bloqueio do laboratorista mantém acesso à conta');
    verificar(requisicao($student, '/index.php')['status'] === 200, 'aluno bloqueado pelo laboratorista ainda consulta catálogo');
    verificar(requisicao($student, '/api/carrinho_add.php', ['id_comp' => 1, 'quantidade' => 1], true, true)['status'] === 403, 'aluno bloqueado pelo laboratorista não adiciona itens');
    $listaLabBloqueado = requisicao($lab, $labPagina . '?q=' . rawurlencode($prefix));
    verificar(str_contains($listaLabBloqueado['body'], 'Remover bloqueio'), 'bloqueado oferece Remover bloqueio');
    $r = requisicao($lab, $labPagina, $labPost, true);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'], 'laboratorista remove bloqueio de pedidos');
    verificar((int) $pdo->query('SELECT pre_bloqueado_manual FROM Usuario WHERE id_user = ' . $users['estudante']['id'])->fetchColumn() === 0, 'remoção limpa o bloqueio manual');
    $r = requisicao($lab, $labPagina, array_replace($labPost, ['action' => 'toggle_block', 'id_user' => $users['bloqueado']['id']]), true);
    verificar($r['status'] === 403 && (int) $pdo->query('SELECT bloqueado FROM Usuario WHERE id_user = ' . $users['bloqueado']['id'])->fetchColumn() === 1, 'laboratorista também não remove banimento');
    $r = requisicao($lab, $labPagina, array_replace($labPost, ['action' => 'send_notice', 'titulo' => $prefix . ' laboratório', 'mensagem' => 'Aviso temporário do laboratorista']), true);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'], 'envio de avisos do laboratorista continua funcionando');

    foreach (['/ldaptest.php', '/../src/views/ldap_control/ldaptest.php'] as $path) {
        verificar(requisicao($admin, $path)['status'] === 404, 'diagnóstico removido não permite acesso pela URL antiga');
    }
    $r = requisicao(cliente(), '/login.php', ['tipo_acesso' => 'ldap', 'cpf' => '00000000000', 'senha' => 'nao-enviar']);
    verificar($r['status'] === 400 && !isset($r['headers']['location']), 'método removido é rejeitado sem autenticação nem redirecionamento');

    // Gerenciar Avisos: acesso, avisos e progressão pré-bloqueio -> banimento.
    foreach ([$student, $lab] as $semPermissao) {
        verificar(requisicao($semPermissao, '/pages_admin/controlar_aluno.php')['status'] === 403, 'somente administrador gerencia avisos');
    }
    $listaAvisos = requisicao($admin, '/pages_admin/controlar_aluno.php?q=' . rawurlencode($prefix));
    verificar($listaAvisos['status'] === 200 && !str_contains($listaAvisos['body'], 'Banco de dados indisponível'), 'lista de avisos carrega usuários dos três perfis com busca');
    foreach (['admin', 'laboratorista', 'estudante'] as $grupo) {
        verificar(str_contains($listaAvisos['body'], 'id="card-' . $users[$grupo]['id'] . '"'), 'avisos inclui perfil ' . $grupo);
    }
    $buscaAvisos = '/pages_admin/controlar_aluno.php?q=' . rawurlencode($prefix);
    $r = requisicao($admin, $buscaAvisos . '&perfis[]=estudante');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"')
        && !str_contains($r['body'], 'id="card-' . $users['laboratorista']['id'] . '"')
        && !str_contains($r['body'], 'id="card-' . $users['admin']['id'] . '"'), 'filtro Aluno mantém somente o perfil selecionado');
    $r = requisicao($admin, $buscaAvisos . '&perfis[]=estudante&perfis[]=laboratorista');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"')
        && str_contains($r['body'], 'id="card-' . $users['laboratorista']['id'] . '"')
        && !str_contains($r['body'], 'id="card-' . $users['admin']['id'] . '"'), 'filtro permite selecionar vários perfis');
    preg_match('/name="csrf_avisos" value="([a-f0-9]+)"/', $listaAvisos['body'], $m);
    $avisosToken = $m[1] ?? '';
    verificar(strlen($avisosToken) === 64, 'gestão de avisos fornece proteção CSRF');
    $pinPost = ['csrf_avisos' => $avisosToken, 'action' => 'set_pin', 'fixado' => '1', 'id_user' => $users['estudante']['id']];
    verificar(requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['csrf_avisos' => '']))['status'] === 403, 'fixação exige CSRF');
    foreach ([$student, $lab] as $semPermissao) {
        verificar(requisicao($semPermissao, '/pages_admin/controlar_aluno.php', $pinPost)['status'] === 403, 'outros perfis não fixam usuários');
    }
    verificar(requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['fixado' => 'invalido']))['status'] === 422, 'fixação rejeita estado inválido');
    verificar(requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['id_user' => 2147483647]))['status'] === 422, 'fixação rejeita usuário inexistente');
    foreach (['estudante', 'laboratorista', 'outro_admin'] as $indice => $alvo) {
        $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['id_user' => $users[$alvo]['id']]));
        $dadosPin = json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR);
        verificar($r['status'] === 200 && $dadosPin['ok'] && count($dadosPin['fixados']) === $indice + 1, 'fixa usuário do perfil ' . $alvo);
    }
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $pinPost);
    verificar(count(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['fixados']) === 3, 'repetir fixação não duplica nem ocupa vaga');
    $r = requisicao($admin, $buscaAvisos, array_replace($pinPost, ['id_user' => $users['admin']['id']]));
    verificar($r['status'] === 422 && count(usuariosFixadosAvisos($pdo, $users['admin']['id'])) === 3, 'servidor rejeita quarto fixado mesmo com busca ativa');
    verificar(usuariosFixadosAvisos($pdo, $users['outro_admin']['id']) === [], 'fixações são individuais por administrador');
    $r = requisicao($admin, $buscaAvisos);
    verificar(strpos($r['body'], 'id="card-' . $users['estudante']['id'] . '"') < strpos($r['body'], 'id="card-' . $users['admin']['id'] . '"'), 'fixado persiste no topo ao recarregar');
    $r = requisicao($admin, $buscaAvisos . '&perfis[]=laboratorista');
    verificar(str_contains($r['body'], 'id="card-' . $users['laboratorista']['id'] . '"') && !str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"'), 'fixações respeitam filtros sem remover fixados ocultos');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['fixado' => '0']));
    verificar(count(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['fixados']) === 2, 'desafixar libera vaga');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($pinPost, ['id_user' => $users['admin']['id']]));
    verificar(count(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['fixados']) === 3, 'nova fixação ocupa vaga liberada');
    foreach (usuariosFixadosAvisos($pdo, $users['admin']['id']) as $fixadoId) {
        definirUsuarioFixadoAvisos($pdo, $users['admin']['id'], $fixadoId, false);
    }
    verificar(usuariosFixadosAvisos($pdo, $users['admin']['id']) === [], 'remove fixações temporárias');
    $avisosPost = ['csrf_avisos' => $avisosToken, 'id_user' => $users['estudante']['id'], 'action' => 'toggle_block'];
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($avisosPost, ['csrf_avisos' => '']));
    verificar($r['status'] === 403, 'rejeita alteração de avisos sem CSRF');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    verificar($r['status'] === 422 && !json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'], 'servidor impede banir quem ainda não está pré-bloqueado');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($avisosPost, ['id_user' => $users['admin']['id']]));
    verificar($r['status'] === 422, 'administrador não pode banir a própria conta');

    $prePost = array_replace($avisosPost, ['action' => 'toggle_preblock', 'motivo' => 'Pré-bloqueio temporário da suíte local']);
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $prePost);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'] === true, 'administrador pré-bloqueia aluno');
    $r = requisicao($admin, $buscaAvisos . '&situacoes[]=bloqueados');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"')
        && !str_contains($r['body'], 'id="card-' . $users['bloqueado']['id'] . '"'), 'Bloqueados inclui bloqueio manual e separa banidos');
    $r = requisicao($admin, $buscaAvisos . '&perfis[]=laboratorista&situacoes[]=bloqueados');
    verificar(!str_contains($r['body'], 'id="card-'), 'combina perfil e situação sem incluir outros perfis');
    $r = requisicao($admin, $buscaAvisos . '&situacoes[]=bloqueados&situacoes[]=banidos');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"')
        && str_contains($r['body'], 'id="card-' . $users['bloqueado']['id'] . '"'), 'seleção conjunta inclui bloqueados e banidos');
    $preClient = cliente();
    $r = requisicao($preClient, '/login.php', ['tipo_acesso' => 'teste', 'cpf' => $users['estudante']['login'], 'senha' => $password]);
    verificar($r['status'] === 302, 'pré-bloqueado continua podendo entrar');
    verificar(requisicao($preClient, '/index.php')['status'] === 200, 'pré-bloqueado pode consultar o catálogo');
    verificar(requisicao($preClient, '/pages_aluno/carrinho.php')['status'] === 200, 'pré-bloqueado pode consultar o carrinho');
    $r = requisicao($preClient, '/api/carrinho_add.php', ['id_comp' => 1, 'quantidade' => 1], true, true);
    verificar($r['status'] === 403, 'pré-bloqueado não adiciona itens ao carrinho');
    $r = requisicao($preClient, '/pages_aluno/carrinho.php', ['action' => 'finalizar-pedido', 'cart_payload' => '[{"id":1,"quantidade":1}]', 'prazo_devolucao_opcao' => '1-3']);
    verificar($r['status'] === 403, 'pré-bloqueado não contorna a restrição enviando pedido diretamente');

    foreach (['estudante', 'laboratorista', 'outro_admin'] as $destinatario) {
        $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($avisosPost, ['action' => 'send_notice', 'id_user' => $users[$destinatario]['id'], 'titulo' => $prefix, 'mensagem' => 'Aviso temporário de validação', 'humor' => 'neutro']));
        verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'] === true, 'envia aviso para ' . $destinatario);
    }
    $notificacoes = $pdo->prepare('SELECT COUNT(*) FROM Notificacao WHERE titulo = ?');
    $notificacoes->execute([$prefix]);
    verificar((int) $notificacoes->fetchColumn() === 3, 'avisos persistem para os três destinatários');

    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['bloqueado'] === 1, 'banimento é permitido após pré-bloqueio');
    $r = requisicao($admin, $buscaAvisos . '&perfis[]=estudante&situacoes[]=banidos');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"')
        && !str_contains($r['body'], 'id="card-' . $users['laboratorista']['id'] . '"'), 'Banidos combina corretamente com perfil Aluno');
    $r = requisicao($admin, $buscaAvisos . '&situacoes[]=bloqueados');
    verificar(!str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"'), 'usuário banido deixa o filtro Bloqueados');
    verificar(requisicao($preClient, '/index.php')['status'] === 403, 'banido perde acesso na sessão já aberta');
    $r = requisicao(cliente(), '/login.php', ['tipo_acesso' => 'teste', 'cpf' => $users['estudante']['login'], 'senha' => $password]);
    verificar($r['status'] === 200 && !isset($r['headers']['location']), 'banido não consegue entrar novamente');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['bloqueado'] === 0, 'administrador pode remover banimento');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $prePost);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['pre_bloqueado'] === 0, 'pré-bloqueio pode ser removido separadamente');
    verificar(requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost)['status'] === 422, 'Banir volta a ser indisponível após remover o pré-bloqueio');

    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($prePost, ['id_user' => $users['laboratorista']['id']]));
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'] === true, 'pré-bloqueio também funciona para laboratorista');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($avisosPost, ['id_user' => $users['laboratorista']['id']]));
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['bloqueado'] === 1, 'banimento também funciona para laboratorista');
    verificar(requisicao($lab, '/pages_laboratorista/index.php')['status'] === 403, 'laboratorista banido perde acesso');
    requisicao($admin, '/pages_admin/controlar_aluno.php', array_replace($avisosPost, ['id_user' => $users['laboratorista']['id']]));
    requisicao($lab, '/login.php', ['tipo_acesso' => 'teste', 'cpf' => $users['laboratorista']['login'], 'senha' => $password]);

    $pdo->prepare('INSERT INTO Pedido (id_user, status, data_criacao) VALUES (?, ?, CURDATE())')->execute([$users['estudante']['id'], 'em-atraso']);
    $pedidoTemporario = (int) $pdo->lastInsertId();
    $r = requisicao($admin, $buscaAvisos . '&situacoes[]=bloqueados');
    verificar(str_contains($r['body'], 'id="card-' . $users['estudante']['id'] . '"'), 'filtro Bloqueados inclui bloqueio automático por atraso');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    verificar(json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['bloqueado'] === 1, 'pré-bloqueio automático também permite banimento');
    $r = requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    verificar(!json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ok'], 'mantém a regra de devolução pendente para remover banimento');
    $pdo->prepare('UPDATE Pedido SET status = ? WHERE id_pedido = ?')->execute(['finalizado', $pedidoTemporario]);
    requisicao($admin, '/pages_admin/controlar_aluno.php', $avisosPost);
    $pdo->prepare('DELETE FROM Pedido WHERE id_pedido = ?')->execute([$pedidoTemporario]);

    $homeAdmin = requisicao($admin, '/pages_admin/index.php');
    verificar(str_contains($homeAdmin['body'], 'Entrar como Estudante') && str_contains($homeAdmin['body'], 'Entrar como Laboratorista'), 'rodapé administrativo oferece as duas visualizações');
    foreach (['Davi Cadoná Marion;', 'Emanuel Ziegler Martins;', 'Luiz Fernando Schwanz;', 'Pedro Henrique Toazza;', 'Victor Borba de Moura e Silva.'] as $autor) {
        verificar(str_contains($homeAdmin['body'], $autor), 'rodapé preserva o crédito: ' . $autor);
    }
    $homeLab = requisicao($lab, '/pages_laboratorista/index.php');
    verificar(str_contains($homeLab['body'], 'O sistema oficial do Laboratório de Hardware') && str_contains($homeLab['body'], 'Victor Borba de Moura e Silva.'), 'painel do laboratorista recebe o texto original');
    preg_match('/name="csrf_visualizacao_admin" value="([a-f0-9]+)"/', $homeAdmin['body'], $m);
    $previewPost = ['csrf_visualizacao_admin' => $m[1] ?? '', 'visualizacao' => 'estudante'];
    verificar(requisicao($admin, '/pages_admin/visualizar.php')['status'] === 405, 'troca de visualização exige POST');
    verificar(requisicao($admin, '/pages_admin/visualizar.php', array_replace($previewPost, ['csrf_visualizacao_admin' => 'errado']))['status'] === 403, 'troca de visualização exige CSRF');
    verificar(requisicao($student, '/pages_admin/visualizar.php', $previewPost)['status'] === 403, 'aluno não pode ativar visualização administrativa');
    verificar(requisicao($lab, '/pages_admin/visualizar.php', $previewPost)['status'] === 403, 'laboratorista não pode ativar visualização administrativa');
    $r = requisicao($admin, '/pages_admin/visualizar.php', array_replace($previewPost, ['visualizacao' => 'admin']));
    verificar($r['status'] === 422, 'visualização desconhecida é recusada');
    $r = requisicao($admin, '/pages_admin/visualizar.php', $previewPost);
    verificar($r['status'] === 303 && $r['headers']['location'] === '../index.php', 'administrador abre visualização de aluno');
    $r = requisicao($admin, '/index.php');
    verificar($r['status'] === 200 && str_contains($r['body'], 'adminPreviewConfig'), 'catálogo do aluno abre para administrador sem redirecionar ao painel');
    verificar(perfil($pdo, $users['admin']['id']) === 'admin', 'visualização não altera o perfil no banco');
    $pdo->prepare('INSERT INTO Categoria (nome) VALUES (?)')->execute([$prefix . ' catálogo temporário']);
    $previewCategoria = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO Componente (id_cat, nome, qtd_disponivel) VALUES (?, ?, 3)')->execute([$previewCategoria, $prefix . ' componente temporário']);
    $previewComponente = (int) $pdo->lastInsertId();
    $r = requisicao($admin, '/api/carrinho_add.php', ['id_comp' => $previewComponente, 'quantidade' => 1], true, true);
    verificar($r['status'] === 200 && json_decode($r['body'], true)['ok'], 'administrador pode adicionar item ao carrinho de demonstração');
    $r = requisicao($admin, '/pages_aluno/carrinho.php');
    verificar($r['status'] === 200 && str_contains($r['body'], $prefix . ' componente temporário'), 'carrinho mostra o item adicionado');
    $totalPedidosAntes = (int) $pdo->query('SELECT COUNT(*) FROM Pedido')->fetchColumn();
    $r = requisicao($admin, '/pages_aluno/carrinho.php', ['action' => 'finalizar-pedido', 'cart_payload' => json_encode([['id' => $previewComponente, 'quantidade' => 1]]), 'prazo_devolucao_opcao' => '1-3'], true);
    verificar($r['status'] === 403 && str_contains(json_decode($r['body'], true)['mensagem'], 'Consulte diretamente um laboratorista'), 'envio de pedido do administrador é recusado no servidor');
    verificar((int) $pdo->query('SELECT COUNT(*) FROM Pedido')->fetchColumn() === $totalPedidosAntes, 'visualização não cria pedidos');
    verificar((int) $pdo->query('SELECT qtd_disponivel FROM Componente WHERE id_comp = ' . $previewComponente)->fetchColumn() === 3, 'tentativa de envio não altera estoque');
    $r = requisicao($admin, '/pages_admin/visualizar.php', array_replace($previewPost, ['visualizacao' => 'laboratorista']));
    verificar($r['status'] === 303 && $r['headers']['location'] === '../pages_laboratorista/index.php', 'administrador abre visualização de laboratorista');
    foreach (['acessar.php', 'gerenciar_user.php', 'relatório_geral.php', 'catalogo.php', 'cadastrar_catalogo.php', 'cadastrar_categoria.php'] as $paginaPreview) {
        verificar(requisicao($admin, '/pages_laboratorista/' . $paginaPreview)['status'] === 200, 'administrador consulta a tela: ' . $paginaPreview);
    }
    foreach (['/pages_laboratorista/acessar.php', '/pages_laboratorista/gerenciar_user.php', '/pages_laboratorista/relatório_geral.php', '/api/pedido_arquivar_lab.php', '/api/pedido_atraso_nota.php', '/api/pedido_arquivar.php', '/api/admin_apagar_pedido.php'] as $rotaRestrita) {
        $r = requisicao($admin, $rotaRestrita, ['action' => 'toggle_preblock', 'id_user' => $users['estudante']['id']], true);
        verificar($r['status'] === 403 && str_contains(json_decode($r['body'], true)['mensagem'], 'Consulte diretamente um laboratorista'), 'requisição forjada é recusada: ' . $rotaRestrita);
    }
    verificar(requisicao($admin, '/api/pedido_atraso_nota.php?acao=buscar&id_pedido=1', null, true)['status'] === 403, 'GET que cria nota de pedido também é recusado');
    verificar(requisicao($admin, '/pages_laboratorista/relatório_geral.php?action=emitir', null, true)['status'] === 403, 'emissão por parâmetro GET não contorna a regra');
    $r = requisicao($admin, '/pages_laboratorista/catalogo.php', ['action' => 'editar', 'id_comp' => $previewComponente, 'nome' => $prefix . ' catálogo editado', 'descricao' => 'Teste temporário', 'qtd_disponivel' => 5]);
    verificar($r['status'] === 302 && $pdo->query('SELECT nome FROM Componente WHERE id_comp = ' . $previewComponente)->fetchColumn() === $prefix . ' catálogo editado', 'administrador mantém permissão de editar o catálogo');
    requisicao($admin, '/pages_admin/index.php');
    $r = requisicao($admin, '/index.php');
    verificar($r['status'] === 302 && $r['headers']['location'] === 'pages_admin/index.php', 'voltar ao administrador encerra a visualização');

    $post['id_user'] = $users['outro_admin']['id'];
    $post['tipo_perfil'] = 'estudante';
    verificar(requisicao($admin, '/pages_admin/perfis.php', $post)['status'] === 303, 'administrador pode rebaixar outro administrador');
    verificar(requisicao($users['outro_admin']['client'], '/pages_admin/perfis.php')['status'] === 403, 'administrador rebaixado perde permissão na sessão existente');
    $autorRejeitado = false;
    try {
        alterarPerfilUsuario($pdo, $users['outro_admin']['id'], $users['estudante']['id'], 'admin', '127.0.0.1');
    } catch (RuntimeException) {
        $autorRejeitado = true;
    }
    verificar($autorRejeitado, 'serviço revalida o autor dentro da transação');

    $pdo->prepare('UPDATE Usuario SET bloqueado = 1 WHERE id_user = ?')->execute([$users['laboratorista']['id']]);
    verificar(requisicao($lab, '/pages_laboratorista/index.php')['status'] === 403, 'bloqueio invalida acesso já aberto');
    $pdo->prepare('DELETE FROM Usuario WHERE id_user = ?')->execute([$users['estudante']['id']]);
    verificar(requisicao($student, '/index.php')['status'] === 403, 'conta removida perde acesso');
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($previewComponente !== null) $pdo->prepare('DELETE FROM Componente WHERE id_comp = ?')->execute([$previewComponente]);
    if ($previewCategoria !== null) $pdo->prepare('DELETE FROM Categoria WHERE id_cat = ?')->execute([$previewCategoria]);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM Notificacao WHERE id_user IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM Pedido WHERE id_user IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM Log_Auditoria WHERE id_user IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM Usuario WHERE id_user IN ({$placeholders})")->execute($ids);
    }
    foreach ($clients as $c) { curl_close($c); }
}
verificar($pdo->query('SELECT id_user, tipo_perfil, forma_login FROM Usuario ORDER BY id_user')->fetchAll() === $originalUsers, 'contas, perfis e métodos originais preservados; dados temporários removidos');
echo "\n{$checks} verificações passaram.\n";
