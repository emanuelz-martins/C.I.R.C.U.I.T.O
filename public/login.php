<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/services/sessao.php';
require_once __DIR__ . '/../src/services/google.php';
require_once __DIR__ . '/../src/config/sigaa.php';
iniciarSessaoSegura();
header('Cache-Control: no-store');
$error = (string) ($_SESSION['erro_google'] ?? '');
unset($_SESSION['erro_google']);
$accessType = 'sigaa';
$googleClient = googleClientId();
$_SESSION['google_login'] = ['csrf' => bin2hex(random_bytes(32)), 'nonce' => bin2hex(random_bytes(32)), 'criado' => time()];
$googlePagina = ['clientId' => $googleClient, 'csrf' => $_SESSION['google_login']['csrf'], 'nonce' => $_SESSION['google_login']['nonce'], 'domain' => GOOGLE_DOMINIO_ALUNO];

function normalizarLogin(string $login): string
{
    $login = trim($login);
    if (preg_match('/^[0-9]{3}[.]?[0-9]{3}[.]?[0-9]{3}-?[0-9]{2}$/D', $login)) {
        $digitos = preg_replace('/\D/', '', $login);
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digitos);
    }
    return $login;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = (string) ($_POST['tipo_acesso'] ?? 'sigaa');
    $tipoValido = in_array($tipo, ['sigaa', 'teste'], true);
    $accessType = $tipoValido ? $tipo : 'sigaa';
    $loginInput = trim((string) ($_POST['usuario'] ?? $_POST['cpf'] ?? ''));
    $senhaInput = (string) ($_POST['senha'] ?? '');
    $cpf = preg_replace('/\D/', '', $loginInput);
    if (!$tipoValido) {
        http_response_code(400);
        $error = 'Esse tipo de acesso ainda não está disponível.';
    } elseif ($loginInput === '' || strlen($loginInput) > 100 || $senhaInput === '') {
        $error = 'Informe seu login e sua senha.';
    } elseif ($accessType === 'sigaa' && !preg_match('/^[0-9]{3}[.]?[0-9]{3}[.]?[0-9]{3}-?[0-9]{2}$/D', $loginInput)) {
        $error = 'Informe um CPF com 11 dígitos para acessar pelo SIGAA.';
    } else {
        try {
            $stmt = db()->prepare('SELECT * FROM Usuario WHERE login = ?');
            $login = normalizarLogin($loginInput);
            $stmt->execute([$login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && (int) $user['bloqueado'] === 1) {
                $error = 'Este usuário está bloqueado.';
            } elseif ($user && $user['google_sub'] !== null) {
                $error = 'Use Entrar com o Google para acessar essa conta.';
            } elseif ($accessType === 'teste') {
                if (!$user || !password_verify($senhaInput, (string) $user['hash_senha'])) {
                    $error = 'Usuário ou senha inválidos no ambiente de teste.';
                } else {
                    $destino = criarSessaoUsuario($user, 'teste');
                    header('Location: ' . $destino);
                    exit;
                }
            } else {
                $dadosSigaa = authSigaa($cpf, $senhaInput);
                if ($dadosSigaa === null) {
                    $error = 'CPF ou senha SIGAA inválidos.';
                } else {
                    $user = sincronizarUsuario(db(), $user ?: null, $login, $dadosSigaa['nome'] ?? ($user['nome'] ?? 'Estudante SIGAA'));
                    $destino = criarSessaoUsuario($user, 'sigaa');
                    header('Location: ' . $destino);
                    exit;
                }
            }
        } catch (Throwable) {
            $error = 'Não foi possível concluir o acesso. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" sizes="any" href="assets/img/sempre/circuito.svg?v=2">
    <title>C.I.R.C.U.I.T.O. — Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root { color-scheme: dark; }
        body { min-height: 100vh; background: #141414; color: #fff; font-family: 'Segoe UI', Arial, sans-serif; display: flex; align-items: center; justify-content: center; padding: 24px; }
        body::before { content: ''; position: fixed; inset: -100px; background: linear-gradient(rgba(20,20,20,.45), rgba(20,20,20,.75)), url('assets/img/sempre/login-fundo.svg') center / cover no-repeat; filter: blur(28px); pointer-events: none; }
        .container { position: relative; width: min(900px, 100%); min-height: 560px; background: #141414; border: 1px solid #2a2a2a; display: flex; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 80px rgba(0,0,0,.45); }
        .login-area { width: 52%; padding: 48px; display: flex; flex-direction: column; justify-content: center; }
        .login-area h1 { font-size: 38px; line-height: 1.08; color: #fff; margin-bottom: 12px; letter-spacing: -1.5px; }
        .subtitle { font-size: 14px; color: #999; line-height: 1.5; margin-bottom: 26px; }
        .account-selector { min-width: 0; border: 0; margin-bottom: 24px; }
        .account-selector legend { font-size: 13px; font-weight: 600; color: #ccc; margin-bottom: 10px; }
        .account-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .account-option { position: relative; cursor: pointer; }
        .account-option input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .account-card { display: flex; flex-direction: column; justify-content: center; gap: 7px; height: 88px; padding: 13px; background: #1e1e1e; border: 1px solid #333; border-radius: 12px; transition: background-color .15s, border-color .15s; }
        .account-heading { display: flex; align-items: center; gap: 8px; color: #bdbdbd; font-size: 14px; font-weight: 600; }
        .account-heading svg { width: 19px; height: 19px; flex-shrink: 0; }
        .account-description { color: #999; font-size: 11px; line-height: 1.4; }
        .account-check { width: 11px; height: 11px; border: 1px solid #666; border-radius: 50%; margin-left: auto; flex-shrink: 0; }
        .account-option:hover .account-card { background: #252525; border-color: #666; }
        .account-option input:checked + .account-card { background: #282828; border-color: #888; }
        .account-option input:checked + .account-card .account-heading { color: #fff; }
        .account-option input:checked + .account-card .account-check { border-color: #fff; background: #fff; box-shadow: inset 0 0 0 2px #282828; }
        .account-option input:focus-visible + .account-card { outline: 2px solid #fff; outline-offset: 3px; }
        .account-option:has(input:disabled) { cursor: not-allowed; }
        .account-option input:disabled + .account-card { opacity: .45; }
        .google-access { margin: 28px 0; padding-top: 22px; border-top: 1px solid #ddd; }
        .google-access h2 { font-size: 18px; margin-bottom: 8px; }
        .google-access p { font-size: 12px; line-height: 1.5; margin-bottom: 14px; }
        .google-placeholder { width: 100%; height: 44px; display: flex; align-items: center; justify-content: center; gap: 10px; border: 1px solid #747775; border-radius: 4px; background: #fff; color: #1f1f1f; font: 500 14px Arial,sans-serif; }
        .google-placeholder img { width: 20px; height: 20px; }
        .google-access .google-note { margin: 10px 0 0; color: #555; }
        .google-access .google-error { color: #9f2525; margin: 10px 0 0; }
        .google-access [hidden] { display: none; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #ccc; margin-bottom: 8px; }
        .input-wrapper { position: relative; }
        .input-wrapper input { width: 100%; height: 48px; border: 1px solid #333; border-radius: 10px; background: #1e1e1e; padding: 0 14px; font: inherit; font-size: 14px; color: #fff; outline: none; transition: border-color .15s, background-color .15s; }
        .input-wrapper input::placeholder { color: #858585; opacity: 1; }
        .input-wrapper input:focus { border-color: #aaa; background: #242424; box-shadow: 0 0 0 2px rgba(255,255,255,.06); }
        .input-wrapper input:-webkit-autofill { -webkit-text-fill-color: #fff; -webkit-box-shadow: 0 0 0 1000px #242424 inset; caret-color: #fff; }
        .password-wrapper input { padding-right: 42px; }
        .toggle-password { position: absolute; right: 0; top: 0; width: 44px; height: 48px; cursor: pointer; border: 0; border-radius: 10px; background: transparent; color: #aaa; font-size: 16px; line-height: 1; }
        .toggle-password:focus-visible, .login-button:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
        .login-button { width: 100%; height: 48px; border: 0; border-radius: 10px; background: #fff; color: #141414; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 8px; transition: background-color .15s; }
        .login-button:hover { background: #e5e5e5; }
        .help { margin-top: 18px; min-height: 36px; font-size: 12px; line-height: 1.5; color: #999; }
        .help strong { color: #ccc; }
        .error-msg { background: #321c1c; border: 1px solid #713838; color: #fca5a5; border-radius: 10px; padding: 11px 13px; margin-bottom: 18px; font-size: 13px; line-height: 1.5; }
        .info-area { width: 48%; margin: 14px 14px 14px 0; border: 1px solid #e5e5e5; border-radius: 18px; background: #fff; color: #141414; padding: 38px 30px; display: flex; flex-direction: column; justify-content: space-between; }
        .brand { margin-top: 5px; }
        .brand-small { font-size: 10px; color: #141414; margin-bottom: 3px; }
        .brand-name { font-size: 25px; font-weight: 700; letter-spacing: 2px; }
        .brand-name span { font-weight: 700; }
        .description { margin-top: 20px; font-size: 10px; line-height: 1.5; color: #141414; max-width: 260px; }
        .features { margin-top: 18px; list-style: none; }
        .features li { font-size: 10px; color: #141414; margin-bottom: 6px; }
        .features li::before { content: '•'; margin-right: 7px; color: #141414; }
        .bottom-text { margin-bottom: 8px; }
        .bottom-text h2 { font-size: 22px; line-height: 1.05; margin-bottom: 13px; }
        .bottom-text p { font-size: 10px; color: #141414; line-height: 1.5; max-width: 230px; }
        @media (max-width: 800px) { body { padding: 16px; } .container { max-width: 500px; min-height: auto; flex-direction: column; } .login-area { width: 100%; padding: 40px 35px; } .info-area { width: calc(100% - 28px); min-height: 300px; gap: 40px; margin: 0 14px 14px; } }
        @media (max-width: 450px) { body { padding: 12px; } .login-area { padding: 32px 24px; } .login-area h1 { font-size: 32px; } .info-area { padding: 28px 24px; } .account-card { padding: 12px 10px; } }
    </style>
</head>
<body>
    <main class="container">
        <section class="login-area">
            <h1>Bem vindo<br>de volta!</h1>
            <p class="subtitle">Entre para acessar o C.I.R.C.U.I.T.O.</p>

            <?php if ($error !== ''): ?>
                <div class="error-msg" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on">
                <fieldset class="account-selector" aria-describedby="accessHelp">
                    <legend>Outras formas de acesso</legend>
                    <div class="account-options">
                        <label class="account-option">
                            <input type="radio" name="tipo_acesso" value="sigaa" <?= $accessType === 'sigaa' ? 'checked' : '' ?> required>
                            <span class="account-card">
                                <span class="account-heading">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11.2V17c3 2 7 2 10 0v-5.8M21 9v7"/></svg>
                                    <span>SIGAA</span><span class="account-check" aria-hidden="true"></span>
                                </span>
                                <span class="account-description">Conta institucional</span>
                            </span>
                        </label>
                        <label class="account-option">
                            <input type="radio" name="tipo_acesso" value="teste" <?= $accessType === 'teste' ? 'checked' : '' ?> required>
                            <span class="account-card">
                                <span class="account-heading">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="3"/><circle cx="12" cy="9" r="2.5"/><path d="M8 17c0-4 8-4 8 0"/></svg>
                                    <span>Teste</span><span class="account-check" aria-hidden="true"></span>
                                </span>
                                <span class="account-description">Conta de teste</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <div class="form-group">
                    <label for="usuario" id="labelUsuario"><?= $accessType === 'sigaa' ? 'CPF:' : 'Login:' ?></label>
                    <div class="input-wrapper">
                        <input type="text" id="usuario" name="usuario" placeholder="<?= $accessType === 'sigaa' ? 'Ex.: 111.111.111-11' : 'Login da conta de teste' ?>" maxlength="<?= $accessType === 'sigaa' ? '14' : '100' ?>" autocomplete="username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="senha" name="senha" placeholder="Ex.: ••••••••••••" autocomplete="current-password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Mostrar ou ocultar senha" id="eye">◉</button>
                    </div>
                </div>

                <button type="submit" class="login-button">Entrar</button>
            </form>

            <p class="help" id="accessHelp"><?= $accessType === 'sigaa' ? '<strong>SIGAA:</strong> use o CPF e a senha da sua conta institucional.' : '<strong>Teste:</strong> use o login e a senha da sua conta de teste.' ?></p>
        </section>

        <section class="info-area">
            <div>
                <div class="brand">
                    <div class="brand-small">Conheça o</div>
                    <div class="brand-name">C.I.R.C.U.I.<span>T.O.</span></div>
                </div>
                <p class="description">O sistema eficiente de Laboratório de Informática do Instituto Federal Farroupilha foi desenvolvido para organizar, facilitar e otimizar o gerenciamento de componentes tecnológicos.</p>
                <ul class="features">
                    <li>Controle de solicitações</li>
                    <li>Catálogo de materiais</li>
                    <li>Reserva rápida</li>
                    <li>Gerenciamento eficiente</li>
                </ul>
            </div>
            <div class="google-access">
                <h2>Entre com sua conta de estudante</h2>
                <p>Use seu e-mail institucional <strong>@aluno.iffar.edu.br</strong>.</p>
                <button type="button" class="google-placeholder" id="googlePlaceholder" disabled><img src="assets/img/sempre/google-g.png" alt="">Entrar com o Google</button>
                <div id="googleButton" hidden></div>
                <p class="google-note" id="googleNote"><?= $googleClient === '' ? 'Acesso Google em configuração.' : 'Carregando acesso Google…' ?></p>
                <p class="google-error" id="googleError" role="alert" hidden></p>
            </div>
            <div class="bottom-text">
                <h2>Reserve seus<br>componentes de forma<br>rápida e segura</h2>
                <p>Encontre o item que procura, veja a disponibilidade em tempo real e faça seu pedido de forma simples.</p>
            </div>
        </section>
    </main>

    <script>
        const campoLogin = document.getElementById('usuario');
        function atualizarTipoConta() {
            const sigaa = document.querySelector('input[name="tipo_acesso"]:checked').value === 'sigaa';
            document.getElementById('labelUsuario').textContent = sigaa ? 'CPF:' : 'Login:';
            campoLogin.placeholder = sigaa ? 'Ex.: 111.111.111-11' : 'Login da conta de teste';
            campoLogin.maxLength = sigaa ? 14 : 100;
            campoLogin.inputMode = sigaa ? 'numeric' : 'text';
            document.getElementById('accessHelp').innerHTML = sigaa
                ? '<strong>SIGAA:</strong> use o CPF e a senha da sua conta institucional.'
                : '<strong>Teste:</strong> use o login e a senha da sua conta de teste.';
        }
        campoLogin.addEventListener('input', function () {
            if (document.querySelector('input[name="tipo_acesso"]:checked').value !== 'sigaa') return;
            let valor = this.value.replace(/\D/g, '').slice(0, 11);
            if (valor.length > 9) valor = valor.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
            else if (valor.length > 6) valor = valor.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
            else if (valor.length > 3) valor = valor.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
            this.value = valor;
        });
        document.querySelectorAll('input[name="tipo_acesso"]').forEach(opcao => opcao.addEventListener('change', atualizarTipoConta));
        window.addEventListener('pageshow', atualizarTipoConta);
        atualizarTipoConta();

        function togglePassword() {
            const senha = document.getElementById('senha');
            const eye = document.getElementById('eye');
            const oculto = senha.type === 'password';
            senha.type = oculto ? 'text' : 'password';
            eye.textContent = oculto ? '◉' : '○';
            eye.setAttribute('aria-label', oculto ? 'Ocultar senha' : 'Mostrar senha');
        }

    </script>
    <script type="application/json" id="googleConfig"><?= json_encode($googlePagina, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="assets/js/google-login.js" defer></script>
    <?php if ($googleClient !== ''): ?><script src="https://accounts.google.com/gsi/client" async defer onload="window.iniciarGoogleLogin?.()" onerror="window.falhaCarregamentoGoogle?.()"></script><?php endif; ?>
</body>
</html>
