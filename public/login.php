<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/config/sigaa.php';
require_once __DIR__ . '/../src/config/ldap.php';

$error = '';
$accessType = 'sigaa';

function normalizarLogin(string $login): string
{
    $digits = preg_replace('/\D/', '', $login);
    if (is_string($digits) && strlen($digits) === 11) {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) ?? $login;
    }

    return trim($login);
}

function iniciarSessaoUsuario(array $user, string $origem): never
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id_user'],
        'nome' => (string) $user['nome'],
        'login' => (string) $user['login'],
        'perfil' => (string) $user['tipo_perfil'],
        'origem' => $origem,
    ];

    $destinos = [
        'laboratorista' => 'pages_laboratorista/index.php',
        'admin' => 'pages_admin/index.php',
        'estudante' => 'index.php',
    ];
    header('Location: ' . ($destinos[$user['tipo_perfil']] ?? 'index.php'));
    exit;
}

function buscarUsuario(PDO $pdo, string $login): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id_user, nome, login, hash_senha, tipo_perfil, bloqueado
         FROM Usuario
         WHERE login = :login
         LIMIT 1'
    );
    $stmt->execute(['login' => $login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($usuario) ? $usuario : null;
}

function sincronizarUsuario(PDO $pdo, ?array $usuario, string $login, string $nome, string $perfil): array
{
    if ($usuario) {
        $atualizar = $pdo->prepare(
            'UPDATE Usuario
             SET nome = :nome, tipo_perfil = :perfil
             WHERE id_user = :id'
        );
        $atualizar->execute([
            'nome' => $nome,
            'perfil' => $perfil,
            'id' => $usuario['id_user'],
        ]);

        $usuario['nome'] = $nome;
        $usuario['tipo_perfil'] = $perfil;
        return $usuario;
    }

    $hashPlaceholder = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $criar = $pdo->prepare(
        'INSERT INTO Usuario (nome, login, hash_senha, matricula, tipo_perfil, bloqueado, preferencias_notific)
         VALUES (:nome, :login, :hash_senha, NULL, :perfil, 0, NULL)'
    );
    $criar->execute([
        'nome' => $nome,
        'login' => $login,
        'hash_senha' => $hashPlaceholder,
        'perfil' => $perfil,
    ]);

    return [
        'id_user' => (int) $pdo->lastInsertId(),
        'nome' => $nome,
        'login' => $login,
        'tipo_perfil' => $perfil,
        'bloqueado' => 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accessType = (string) ($_POST['tipo_acesso'] ?? 'sigaa');
    $loginInput = trim((string) ($_POST['cpf'] ?? ''));
    $senhaInput = (string) ($_POST['senha'] ?? '');
    $cpf = preg_replace('/\D/', '', $loginInput);

    if (!in_array($accessType, ['sigaa', 'ldap', 'teste'], true)) {
        $accessType = 'sigaa';
    }

    if ($loginInput === '' || $senhaInput === '') {
        $error = 'Informe seu CPF e sua senha.';
    } elseif (!is_string($cpf) || strlen($cpf) !== 11) {
        $error = 'Informe um CPF com 11 dígitos.';
    } else {
        try {
            $login = normalizarLogin($cpf);
            $pdo = db();
            $user = buscarUsuario($pdo, $login);

            if ($user && (int) $user['bloqueado'] === 1) {
                $error = 'Este usuário está bloqueado.';
            } elseif ($accessType === 'teste') {
                if (!$user || !password_verify($senhaInput, (string) $user['hash_senha'])) {
                    $error = 'CPF ou senha inválidos no ambiente de teste.';
                } else {
                    iniciarSessaoUsuario($user, 'teste_local');
                }
            } elseif ($accessType === 'sigaa') {
                $dadosSigaa = authSigaa($cpf, $senhaInput);
                if ($dadosSigaa === null) {
                    $error = 'CPF ou senha SIGAA inválidos.';
                } else {
                    $nome = $dadosSigaa['nome'] ?? ($user['nome'] ?? 'Estudante SIGAA');
                    $user = sincronizarUsuario($pdo, $user, $login, $nome, 'estudante');
                    iniciarSessaoUsuario($user, 'sigaa');
                }
            } else {
                if (!authLdap($loginInput, $senhaInput)) {
                    $error = 'CPF ou senha LDAP inválidos, ou servidor LDAP indisponível.';
                } else {
                    $perfil = ($user['tipo_perfil'] ?? '') === 'admin' ? 'admin' : 'laboratorista';
                    $nome = $user['nome'] ?? 'Laboratorista LDAP';
                    $user = sincronizarUsuario($pdo, $user, $login, $nome, $perfil);
                    iniciarSessaoUsuario($user, 'ldap');
                }
            }
        } catch (Throwable) {
            if ($error === '') {
                $error = 'Não foi possível concluir o acesso. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C.I.R.C.U.I.T.O. — Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; }
        body { min-height: 100vh; background: #f7f7f7; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .container { width: min(900px, 100%); min-height: 560px; background: #fff; display: flex; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 40px rgba(0, 0, 0, .08); }
        .login-area { width: 52%; padding: 64px 60px; display: flex; flex-direction: column; justify-content: center; }
        .login-area h1 { font-size: 38px; line-height: 1.05; color: #202020; margin-bottom: 12px; letter-spacing: -1.5px; }
        .subtitle { font-size: 14px; color: #777; margin-bottom: 30px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #303030; margin-bottom: 8px; }
        .input-wrapper { position: relative; }
        .input-wrapper input, .input-wrapper select { width: 100%; height: 42px; border: 1px solid #e5e5e5; border-radius: 8px; background: #fafafa; padding: 0 13px; font-size: 12px; color: #333; outline: none; transition: .2s; }
        .input-wrapper input:focus, .input-wrapper select:focus { border-color: #222; background: #fff; }
        .password-wrapper input { padding-right: 42px; }
        .toggle-password { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); cursor: pointer; border: 0; background: transparent; color: #555; font-size: 16px; line-height: 1; }
        .login-button { width: 100%; height: 42px; border: 0; border-radius: 8px; background: #191919; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; margin-top: 8px; transition: .2s; }
        .login-button:hover { background: #333; transform: translateY(-1px); }
        .help { margin-top: 18px; font-size: 10px; line-height: 1.5; color: #999; }
        .help strong { color: #666; }
        .error-msg { background: #fff1f1; border: 1px solid #efb4b4; color: #9f2525; border-radius: 8px; padding: 11px 13px; margin-bottom: 18px; font-size: 12px; line-height: 1.35; }
        .info-area { width: 48%; margin: 14px 14px 14px 0; border-radius: 22px; background: linear-gradient(rgba(20,20,20,.90), rgba(20,20,20,.90)), repeating-linear-gradient(45deg, #252525 0, #252525 2px, #1b1b1b 2px, #1b1b1b 5px); color: #fff; padding: 38px 30px; display: flex; flex-direction: column; justify-content: space-between; }
        .brand { margin-top: 5px; }
        .brand-small { font-size: 10px; color: #cfcfcf; margin-bottom: 3px; }
        .brand-name { font-size: 25px; font-weight: 300; letter-spacing: 2px; }
        .brand-name span { font-weight: 700; }
        .description { margin-top: 20px; font-size: 10px; line-height: 1.5; color: #bdbdbd; max-width: 260px; }
        .features { margin-top: 18px; list-style: none; }
        .features li { font-size: 10px; color: #d2d2d2; margin-bottom: 6px; }
        .features li::before { content: '•'; margin-right: 7px; color: #fff; }
        .bottom-text { margin-bottom: 8px; }
        .bottom-text h2 { font-size: 22px; line-height: 1.05; margin-bottom: 13px; }
        .bottom-text p { font-size: 10px; color: #bdbdbd; line-height: 1.5; max-width: 230px; }
        @media (max-width: 800px) { body { padding: 16px; } .container { max-width: 500px; min-height: auto; flex-direction: column; } .login-area { width: 100%; padding: 48px 35px; } .info-area { width: calc(100% - 28px); min-height: 320px; margin: 0 14px 14px; } }
        @media (max-width: 450px) { .login-area { padding: 40px 25px; } .login-area h1 { font-size: 32px; } .info-area { padding: 30px 25px; } }
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
                <div class="form-group">
                    <label for="cpf">CPF:</label>
                    <div class="input-wrapper">
                        <input type="text" id="cpf" name="cpf" placeholder="Ex.: 111.111.111-11" maxlength="14" autocomplete="username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="senha" name="senha" placeholder="Ex.: ••••••••••••" autocomplete="current-password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Mostrar ou ocultar senha" id="eye">◉</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="tipo_acesso">Tipo de acesso:</label>
                    <div class="input-wrapper">
                        <select name="tipo_acesso" id="tipo_acesso" required>
                            <option value="sigaa" <?= $accessType === 'sigaa' ? 'selected' : '' ?>>SIGAA</option>
                            <option value="ldap" <?= $accessType === 'ldap' ? 'selected' : '' ?>>LDAP institucional</option>
                            <option value="teste" <?= $accessType === 'teste' ? 'selected' : '' ?>>Teste (banco local)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="login-button">Entrar</button>
            </form>

            <p class="help" id="accessHelp"><strong>SIGAA:</strong> contas autenticadas por este método entram sempre como estudantes.</p>
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
            <div class="bottom-text">
                <h2>Reserve seus<br>componentes de forma<br>rápida e segura</h2>
                <p>Encontre o item que procura, veja a disponibilidade em tempo real e faça seu pedido de forma simples.</p>
            </div>
        </section>
    </main>

    <script>
        const cpf = document.getElementById('cpf');
        const tipoAcesso = document.getElementById('tipo_acesso');
        const accessHelp = document.getElementById('accessHelp');

        function togglePassword() {
            const senha = document.getElementById('senha');
            const eye = document.getElementById('eye');
            const oculto = senha.type === 'password';
            senha.type = oculto ? 'text' : 'password';
            eye.textContent = oculto ? '◉' : '○';
        }

        function atualizarAjudaAcesso() {
            const tipo = tipoAcesso.value;
            accessHelp.innerHTML = tipo === 'sigaa'
                ? '<strong>SIGAA:</strong> contas autenticadas por este método entram sempre como estudantes.'
                : tipo === 'ldap'
                    ? '<strong>LDAP:</strong> administradores permanecem admin; os demais acessam como laboratoristas.'
                    : '<strong>Teste:</strong> valida o CPF e a senha salvos no banco local.';
        }

        cpf.addEventListener('input', function () {
            let valor = this.value.replace(/\D/g, '').slice(0, 11);
            if (valor.length > 9) valor = valor.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
            else if (valor.length > 6) valor = valor.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
            else if (valor.length > 3) valor = valor.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
            this.value = valor;
        });

        tipoAcesso.addEventListener('change', atualizarAjudaAcesso);
        atualizarAjudaAcesso();
    </script>
</body>
</html>
