<?php
declare(strict_types=1);

/** Integração local com contas temporárias; não altera contas reais. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/services/notificacoes.php';
loadEnv(dirname(__DIR__) . '/.env');
$base = rtrim((string) env('APP_URL'), '/');
if (env('APP_ENV') !== 'local' || env('DB_PROFILE') !== 'xampp'
    || !in_array(env('DB_HOST'), ['localhost', '127.0.0.1'], true)
    || !in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Execute somente com a aplicação e o banco locais.');
}
$pdo = db(); $usuarios = []; $clientes = []; $checks = 0;
function conferir(bool $ok, string $descricao): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FALHOU: ' . $descricao);
    $checks++;
}
function pedir(CurlHandle $cliente, string $caminho, ?array $post = null): array {
    global $base;
    curl_setopt_array($cliente, [CURLOPT_URL => $base . $caminho, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    if ($post === null) curl_setopt($cliente, CURLOPT_HTTPGET, true);
    else curl_setopt_array($cliente, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $corpo = curl_exec($cliente);
    if ($corpo === false) throw new RuntimeException('Falha na conexão local.');
    return [curl_getinfo($cliente, CURLINFO_RESPONSE_CODE), json_decode($corpo, true), $corpo];
}
try {
    foreach (['estudante', 'estudante', 'laboratorista'] as $perfil) {
        $login = 'teste-notificacoes-' . bin2hex(random_bytes(8));
        $senha = bin2hex(random_bytes(12));
        $pdo->prepare('INSERT INTO Usuario (nome, login, hash_senha, tipo_perfil) VALUES (?, ?, ?, ?)')
            ->execute(['Verificação de notificações', $login, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
        $usuarios[] = (int) $pdo->lastInsertId();
        $c = curl_init(); $clientes[] = $c;
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
        [$status] = pedir($c, '/login.php', ['tipo_acesso' => 'teste', 'usuario' => $login, 'senha' => $senha]);
        conferir($status === 302, 'Login temporário');
    }
    $inserir = function(int $usuario, string $tipo, bool $lida = false, string $mensagem = 'Mensagem de teste') use ($pdo): int {
        $pdo->prepare('INSERT INTO Notificacao (id_user, titulo, mensagem, tipo, lida) VALUES (?, ?, ?, ?, ?)')
            ->execute([$usuario, 'Notificação de verificação', $mensagem, $tipo, (int) $lida]);
        return (int) $pdo->lastInsertId();
    };
    $aviso = $inserir($usuarios[0], 'aviso', false, 'Admin Nome de teste te enviou: Olá!');
    $pedido = $inserir($usuarios[0], 'pedido_aprovado');
    $inserir($usuarios[0], 'aviso', true);
    $outro = $inserir($usuarios[1], 'aviso_adm');
    $inserir($usuarios[2], 'aviso_adm');
    $inserir($usuarios[2], 'pedido_aprovado');
    [$status, $data] = pedir($clientes[0], '/api/notificacoes_resumo.php');
    conferir($status === 200 && $data['resumo'] === ['total' => 2, 'avisos' => 1, 'pedidos' => 1], 'Conta somente notificações próprias não lidas');
    [$status, $data] = pedir($clientes[2], '/api/notificacoes_resumo.php');
    conferir($status === 200 && $data['resumo'] === ['total' => 1, 'avisos' => 1, 'pedidos' => 0], 'Laboratorista conta somente avisos da sua tela');
    [$status] = pedir($clientes[0], '/api/notificacoes_resumo.php', []);
    conferir($status === 405, 'Resumo permite somente leitura');
    foreach (['marcar_lida', 'excluir'] as $acao) {
        [$status] = pedir($clientes[0], '/api/notificacao_action.php', ['acao' => $acao, 'id' => $outro]);
        conferir($status === 404, 'Impede ação em notificação de outra conta');
    }
    [$status, $data] = pedir($clientes[0], '/api/notificacao_action.php', ['acao' => 'marcar_lida', 'id' => $aviso]);
    conferir($status === 200 && $data['resumo'] === ['total' => 1, 'avisos' => 0, 'pedidos' => 1], 'Leitura reduz contagem sem apagar mensagem');
    [$status, $data] = pedir($clientes[0], '/api/notificacao_action.php', ['acao' => 'marcar_lida', 'id' => $aviso]);
    conferir($status === 200 && $data['resumo']['total'] === 1, 'Leitura repetida não reduz contagem novamente');
    [$status, $data] = pedir($clientes[0], '/api/notificacao_action.php', ['acao' => 'excluir', 'id' => $pedido]);
    conferir($status === 200 && $data['resumo']['total'] === 0, 'Exclusão de não lida atualiza contagem');
    conferir((int) $pdo->query('SELECT lida FROM Notificacao WHERE id_not = ' . $outro)->fetchColumn() === 0, 'Outra conta permanece intacta');
    $antiga = separarRemetenteNotificacao('Admin Nome completo te enviou: Olá, estudante!');
    conferir($antiga === ['remetente' => 'Admin Nome completo te enviou:', 'mensagem' => 'Olá, estudante!'], 'Separa remetente de avisos antigos');
    $nova = separarRemetenteNotificacao("Laboratorista Nome completo te enviou:\n\nOlá!\nSegunda linha.");
    conferir($nova['mensagem'] === "Olá!\nSegunda linha.", 'Preserva quebras na mensagem');
    conferir(separarRemetenteNotificacao('Seu pedido foi aprovado.')['remetente'] === '', 'Preserva mensagens automáticas');
    // Renderização real de todas as entradas que agora mostram o contador.
    foreach (['/index.php', '/perfil.php', '/pages_aluno/notificacoes.php', '/pages_aluno/carrinho.php', '/pages_aluno/verpedidos.php', '/pages_aluno/arquivados.php'] as $url) {
        [$status, , $html] = pedir($clientes[0], $url);
        conferir($status === 200 && str_contains($html, 'data-notificacoes-link') && !str_contains($html, 'Fatal error'), 'Renderiza menu de notificações em ' . $url);
    }
    echo "OK: {$checks} verificações de notificações.\n";
} finally {
    foreach ($usuarios as $id) {
        $pdo->prepare('DELETE FROM Notificacao WHERE id_user = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM Log_Auditoria WHERE id_user = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM Usuario WHERE id_user = ?')->execute([$id]);
    }
    foreach ($clientes as $c) curl_close($c);
}
