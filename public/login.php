<?php
declare(strict_types=1);
//http://localhost/C.I.R.C.U.I.T.O/src/views/ldap_control/ldaptest.php (pagina adm para criar acesso)
session_start();

require_once __DIR__ . '/../src/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim((string) ($_POST['cpf'] ?? ''));
    $senhaInput = (string) ($_POST['senha'] ?? '');

    if ($loginInput === '' || $senhaInput === '') {
        $error = 'Preencha login/CPF e senha.';
    } else {
        try {
            // Remover caracteres não numéricos e formatar CPF
            $cpf_limpo = preg_replace('/\D/', '', $loginInput);
            if (mb_strlen($cpf_limpo) !== 11) {
                $error = 'CPF inválido. Deve conter exatamente 11 dígitos.';
            } else {
                // Formatar CPF para busca no banco
                $cpf_formatado = preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf_limpo);

                $pdo = db();

                $stmt = $pdo->prepare('SELECT id_user, nome, login, hash_senha, tipo_perfil, bloqueado FROM Usuario WHERE login = :login LIMIT 1');
                $stmt->execute(['login' => $cpf_formatado]);
                $user = $stmt->fetch();

                if (!$user || (int) $user['bloqueado'] === 1) {
                    $error = 'Usuário não encontrado ou bloqueado.';
                } elseif (!password_verify($senhaInput, (string) $user['hash_senha'])) {
                    $error = 'Senha inválida.';
                } else {
                    $_SESSION['auth_user'] = [
                        'id' => (int) $user['id_user'],
                        'nome' => (string) $user['nome'],
                        'login' => (string) $user['login'],
                        'perfil' => (string) $user['tipo_perfil'],
                        'origem' => 'local_dev',
                    ];

                    // Redirecionar com base no tipo de perfil
                    $perfil = (string) $user['tipo_perfil'];
                    if ($perfil === 'laboratorista') {
                        header('Location: pages_laboratorista/index.php');
                    } else {
                        // Estudante e Admin vão para index.php (admin será tratado depois)
                        header('Location: index.php');
                    }
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'Erro ao conectar no banco. Verifique o .env e o MySQL do XAMPP.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — C.I.R.C.U.I.T.O.</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #141414;
            color: #ffffff;
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
        }

        /* ── Layout principal ─────────────────────────────── */
        .login-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ── Lado esquerdo — formulário ───────────────────── */
        .login-left {
            flex: 0 0 48%;
            display: flex;
            align-items: center;
            padding: 60px 80px;
        }

        .login-form-container {
            width: 100%;
            max-width: 420px;
        }

        .login-title {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 48px;
            color: #ffffff;
        }

        .field-label {
            display: block;
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 8px;
            color: #ffffff;
        }

        .field-wrapper {
            position: relative;
            margin-bottom: 28px;
        }

        .field-wrapper input {
            width: 100%;
            padding: 16px 20px;
            background-color: #2a2a2a;
            border: 1.5px solid #3a3a3a;
            border-radius: 50px;
            color: #888;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .field-wrapper input:focus {
            border-color: #666;
            color: #ffffff;
        }

        .field-wrapper input::placeholder {
            color: #666;
        }

        .toggle-password {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 1.1rem;
            user-select: none;
        }

        .btn-login {
            display: block;
            width: 100%;
            padding: 18px;
            margin-top: 16px;
            background-color: #ffffff;
            color: #111111;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            letter-spacing: 0.02em;
        }

        .btn-login:hover {
            background-color: #e8e8e8;
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .forgot-text {
            margin-top: 28px;
            font-size: 0.85rem;
            color: #777;
        }

        .forgot-text a {
            display: block;
            color: #ffffff;
            font-weight: 600;
            text-decoration: none;
            margin-top: 2px;
        }

        .forgot-text a:hover {
            text-decoration: underline;
        }

        /* Mensagem de erro */
        .error-msg {
            background-color: #3a1a1a;
            border: 1px solid #7a3a3a;
            color: #ff8888;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 0.875rem;
            margin-bottom: 24px;
        }

        /* ── Lado direito — painel informativo ────────────── */
        .login-right {
            flex: 0 0 52%;
            background-color: #1c1c1c;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 24px;
            padding: 60px 56px;
        }

        /* Padrão de fundo — ícones de circuito repetidos */
        .login-right::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 60 60'%3E%3Crect x='18' y='26' width='24' height='8' rx='2' fill='none' stroke='%23333' stroke-width='1.5'/%3E%3Cline x1='0' y1='30' x2='18' y2='30' stroke='%23333' stroke-width='1.5'/%3E%3Cline x1='42' y1='30' x2='60' y2='30' stroke='%23333' stroke-width='1.5'/%3E%3C/svg%3E");
            background-size: 60px 60px;
            opacity: 0.6;
            pointer-events: none;
        }

        /* Cards do lado direito */
        .info-card {
            position: relative;
            z-index: 1;
            background-color: rgba(30, 30, 30, 0.85);
            border: 1px solid #2e2e2e;
            border-radius: 16px;
            padding: 32px 36px;
        }

        /* Card do nome do sistema */
        .card-brand .card-eyebrow {
            font-size: 0.9rem;
            color: #aaaaaa;
            margin-bottom: 10px;
        }

        .brand-name {
            font-size: 2.6rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            color: #ffffff;
            font-family: 'Courier New', monospace;
            background-color: #ffffff;
            color: #111111;
            display: inline-block;
            padding: 4px 20px 4px 10px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .card-brand p {
            font-size: 0.875rem;
            color: #aaaaaa;
            line-height: 1.65;
            margin-bottom: 16px;
        }

        .card-brand ul {
            list-style: none;
            padding: 0;
        }

        .card-brand ul li {
            font-size: 0.875rem;
            color: #aaaaaa;
            line-height: 1.8;
        }

        .card-brand ul li::before {
            content: '· ';
            color: #666;
        }

        /* Card do CTA */
        .card-cta .cta-title {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 12px;
            color: #ffffff;
        }

        .card-cta p {
            font-size: 0.9rem;
            color: #aaaaaa;
            line-height: 1.6;
        }

        /* ── Responsivo ───────────────────────────────────── */
        @media (max-width: 900px) {
            .login-wrapper {
                flex-direction: column;
            }

            .login-left,
            .login-right {
                flex: none;
                width: 100%;
                padding: 48px 32px;
            }

            .login-right {
                padding-top: 48px;
            }

            .login-title {
                font-size: 2.2rem;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">

    <!-- ── Lado esquerdo: formulário ── -->
    <div class="login-left">
        <div class="login-form-container">

            <h1 class="login-title">Bem vindo<br>de volta!</h1>

            <?php if (!empty($error)): ?>
                <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off">

                <label class="field-label" for="cpf">CPF:</label>
                <div class="field-wrapper">
                    <input
                        type="text"
                        id="cpf"
                        name="cpf"
                        placeholder="Ex: 111.111.111-11"
                        maxlength="14"
                        required
                    >
                </div>

                <label class="field-label" for="senha">Senha:</label>
                <div class="field-wrapper">
                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        placeholder="Ex: ••••••••••••"
                        required
                    >
                    <span class="toggle-password" onclick="togglePassword()" title="Mostrar/ocultar senha">
                        🔒
                    </span>
                </div>

                <button type="submit" class="btn-login">Log-in</button>

            </form>

            <div class="forgot-text">
                Esqueceu suas informações de login?<br>
                <a href="#">Converse com um responsável na CTI</a>
            </div>

        </div>
    </div>

    <!-- ── Lado direito: painel informativo ── -->
    <div class="login-right">

        <div class="info-card card-brand">
            <p class="card-eyebrow">Conheça o</p>
            <div class="brand-name">C.I.R.C.U.I.T.O.</div>
            <p>
                O sistema oficial do Laboratório de Hardware do Instituto Federal
                Farroupilha / Campus Frederico Westphalen para gerenciamento de
                componentes. Aqui você encontra um catálogo organizado, realiza
                reservas com datas definidas e acompanha todo o processo de
                empréstimo de forma simples, segura e transparente.
            </p>
            <p>O projeto foi realizado pelos estudantes:</p>
            <ul>
                <li>Davi Cadoná Marion;</li>
                <li>Emanuel Ziegler Martins;</li>
                <li>Luiz Fernando Schwanz;</li>
                <li>Pedro Henrique Toazza;</li>
                <li>Victor Borba de Moura e Silva.</li>
            </ul>
        </div>

        <div class="info-card card-cta">
            <h2 class="cta-title">Reserve seus componentes de forma rápida e segura</h2>
            <p>Encontre o item que procura, veja a disponibilidade em tempo real e faça seu pedido de forma simples.</p>
        </div>

    </div>

</div>

<script>
    function togglePassword() {
        const input = document.getElementById('senha');
        const icon  = document.querySelector('.toggle-password');
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = '🔓';
        } else {
            input.type = 'password';
            icon.textContent = '🔒';
        }
    }

    // Máscara de CPF
    document.getElementById('cpf').addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
        this.value = v;
    });
</script>

</body>
</html>
