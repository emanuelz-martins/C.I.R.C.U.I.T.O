<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';
checkAccess(['estudante', 'laboratorista', 'admin']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (env('APP_ENV') === 'production' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    negarAcesso(403, 'Use uma conexão HTTPS para confirmar seu e-mail.');
}
$u = contaUsuario(db(), (int) $_SESSION['auth_user']['id']);
$base = rtrim((string) parse_url((string) env('APP_URL', '/'), PHP_URL_PATH), '/');
if ($u['google_sub'] !== null) { header('Location: ' . $base . '/perfil.php'); exit; }
$inicial = contaCadastroPendente($u, $_SESSION['auth_user']['origem'] ?? null);
$autorizado = emailAutorizacaoValida($u);
$d = $autorizado ? emailDesafio(db(), (int) $u['id_user']) : null;
if ($d && (!emailDesafioDaSessao($d) || (int) $d['expira_em'] <= time() || (int) $d['tentativas'] >= EMAIL_TENTATIVAS)) $d = null;
$etapa = !$autorizado ? 'identidade' : ($d ? 'codigo' : 'endereco');
$sigaa = (bool) $u['cadastro_sigaa'] || ($_SESSION['auth_user']['origem'] ?? '') === 'sigaa';
$mostrarPopup = $inicial && empty($_SESSION['aviso_cadastro_email']);
if ($mostrarPopup) $_SESSION['aviso_cadastro_email'] = true;
$nome = $d['nome_confirmado'] ?? (contaNomeValido((string) $u['nome']) ? (string) $u['nome'] : '');
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$config = ['etapa' => $etapa, 'endpoint' => $base . '/api/email_conta.php', 'csrf' => emailTokenCsrf(),
    'popup' => $mostrarPopup, 'inicial' => $inicial, 'email' => $d['email_novo'] ?? '',
    'reenvioEm' => $d ? max(0, (int) $d['criado_em'] + 60 - time()) : 0];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $inicial ? 'Complete sua conta' : 'E-mail da conta' ?> – C.I.R.C.U.I.T.O.</title>
    <script src="<?= $esc($base) ?>/assets/js/tema.js"></script>
    <link rel="stylesheet" href="<?= $esc($base) ?>/assets/css/tema.css">
    <link rel="stylesheet" href="<?= $esc($base) ?>/assets/css/conta-email.css">
    <link rel="icon" type="image/svg+xml" href="<?= $esc($base) ?>/assets/img/sempre/circuito.svg">
    <script id="contaEmailConfig" type="application/json"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="<?= $esc($base) ?>/assets/js/conta-email.js" defer></script>
</head>
<body>
<?php if ($inicial): ?>
<a class="conta-voltar" href="<?= $esc($base) ?>/logout.php" aria-label="Voltar para o login" title="Voltar">
<?php else: ?>
<button type="button" id="cancelarEmail" class="conta-voltar" data-destino="<?= $esc($base) ?>/perfil.php" aria-label="Voltar ao perfil" title="Voltar">
<?php endif; ?>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>
<?php if ($inicial): ?></a><?php else: ?></button><?php endif; ?>
<main class="conta-card">
    <p class="conta-legenda"><?= $inicial ? 'PRIMEIRO ACESSO' : 'MINHA CONTA' ?></p>
    <h1><?= $inicial ? 'Vamos completar sua conta' : 'E-mail da conta' ?></h1>
    <p class="conta-intro"><?= $inicial ? 'Informe seu nome e confirme um e-mail para receber atualizações dos seus pedidos.' : 'Confirme sua identidade e o novo endereço antes de alterar seu e-mail.' ?></p>
    <ol class="conta-etapas" aria-label="Etapas da confirmação">
        <li data-etapa="identidade">1 <span>Identidade</span></li>
        <li data-etapa="endereco">2 <span>E-mail</span></li>
        <li data-etapa="codigo">3 <span>Confirmação</span></li>
    </ol>
    <div id="contaErro" class="conta-erro" role="alert" hidden></div>
    <section id="etapaIdentidade" <?= $etapa !== 'identidade' ? 'hidden' : '' ?>>
        <h2>Confirme que é você</h2>
        <p class="conta-ajuda"><?= $sigaa ? 'Use o CPF e a senha SIGAA da conta que está conectada.' : 'Use o login e a senha da conta local que está conectada.' ?></p>
        <form id="formIdentidade">
            <label for="contaLogin"><?= $sigaa ? 'CPF' : 'Login' ?></label>
            <input id="contaLogin" name="login" autocomplete="username" value="<?= $esc($u['login']) ?>" maxlength="100" <?= $sigaa ? 'inputmode="numeric" data-cpf' : '' ?> required>
            <label for="contaSenha">Senha <?= $sigaa ? 'SIGAA' : 'da conta' ?></label>
            <input id="contaSenha" name="senha" type="password" autocomplete="current-password" maxlength="1024" required>
            <button type="submit" class="conta-primario">Confirmar identidade</button>
        </form>
    </section>
    <section id="etapaEndereco" <?= $etapa !== 'endereco' ? 'hidden' : '' ?>>
        <h2><?= $inicial ? 'Como podemos chamar você?' : 'Informe o novo e-mail' ?></h2>
        <form id="formEndereco">
            <?php if ($inicial): ?>
                <label for="contaNome">Nome completo <span>obrigatório</span></label>
                <input id="contaNome" name="nome" autocomplete="name" maxlength="150" value="<?= $esc($nome) ?>" required>
            <?php endif; ?>
            <?php if (!$inicial && $u['email']): ?><p class="conta-atual">E-mail atual <strong><?= $esc($u['email']) ?></strong></p><?php endif; ?>
            <label for="contaEmail"><?= $inicial ? 'Seu e-mail' : 'Novo e-mail' ?></label>
            <input id="contaEmail" name="email" type="email" autocomplete="email" maxlength="150" value="<?= $esc($d['email_novo'] ?? ($inicial ? (string) ($u['email'] ?? '') : '')) ?>" placeholder="seu@email.com" required>
            <p class="conta-ajuda">Enviaremos um código para confirmar que esse endereço é seu.</p>
            <?php if ($inicial): ?><p class="conta-opcional">Matrícula, foto e descrição são opcionais. Você pode adicioná-las depois em <strong>Perfil → +</strong>.</p><?php endif; ?>
            <button type="submit" class="conta-primario">Enviar código</button>
        </form>
    </section>
    <section id="etapaCodigo" <?= $etapa !== 'codigo' ? 'hidden' : '' ?>>
        <h2>Confira seu e-mail</h2>
        <p class="conta-ajuda">Enviamos um código para <strong id="emailDestino"><?= $esc($d['email_novo'] ?? '') ?></strong>. Confira também a pasta de spam.</p>
        <form id="formCodigo">
            <label for="contaCodigo">Código de confirmação</label>
            <input id="contaCodigo" name="codigo" class="conta-codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required>
            <p class="conta-ajuda">Válido por até 10 minutos. Não compartilhe o código.</p>
            <button type="submit" class="conta-primario"><?= $inicial ? 'Confirmar e entrar' : 'Confirmar novo e-mail' ?></button>
        </form>
        <div class="conta-acoes"><button type="button" id="reenviarCodigo">Reenviar código</button><button type="button" id="trocarEndereco">Usar outro e-mail</button></div>
    </section>
</main>
<dialog id="avisoCadastro" aria-labelledby="avisoTitulo">
    <h2 id="avisoTitulo">Antes de começar</h2>
    <p>O SIGAA confirmou seu acesso. Agora precisamos do seu nome para identificar você nos pedidos e de um e-mail confirmado para avisar sobre aprovação, retirada e devolução.</p>
    <p>Além do e-mail confirmado, apenas o nome é obrigatório. Matrícula, foto e descrição são opcionais e podem ser preenchidas depois no botão <strong>+ do perfil</strong>.</p>
    <p>Seu acesso será liberado assim que concluir esta confirmação.</p>
    <button type="button" id="entendiCadastro" class="conta-primario">Entendi, continuar</button>
</dialog>
</body>
</html>
