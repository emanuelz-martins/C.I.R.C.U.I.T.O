<?php
//ISSO É APENAS UM TESTE PARA CONSEGUIR LOGAR NO SERVIDOR LDAP, quadno nos migrar pro original vamos apagar esse arquivo mudar a prioridade para o ldap puxar as informações de login do usuario que tem no servidor do iff dos aluno
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/ldap.php';
require_once __DIR__ . '/../../config/database.php';

$mensagem = null;
$tipoMensagem = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$acao = (string) ($_POST['acao'] ?? '');

	try {
		if ($acao === 'ldap_test') {
			$usuario = trim((string) ($_POST['usuario_ldap'] ?? ''));
			$senha = (string) ($_POST['senha_ldap'] ?? '');
			$tipo_conta = (string) ($_POST['tipo_conta_ldap'] ?? 'estudante');

			$ok = authLdap($usuario, $senha);

			if ($ok) {
				$dominio = match($tipo_conta) {
					'admin' => 'ADMIN - Acesso total (alunos, laboratoristas e bloqueios)',
					'laboratorista' => 'LABORATORISTA - Painel de controle do laboratório',
					default => 'ESTUDANTE - Painel de reservas e empréstimos',
				};
				$mensagem = "LDAP OK: usuário e senha válidos no servidor institucional.\nDomínio: {$dominio}";
				$tipoMensagem = 'ok';
			} else {
				$mensagem = 'LDAP falhou: usuário/senha inválidos ou configuração LDAP indisponível.';
				$tipoMensagem = 'erro';
			}
		}

		if ($acao === 'local_register') {
			$nome = trim((string) ($_POST['nome_local'] ?? ''));
			$cpf = trim((string) ($_POST['cpf_local'] ?? ''));
			$senha = (string) ($_POST['senha_local'] ?? '');
			$tipo_perfil = (string) ($_POST['tipo_perfil_local'] ?? 'estudante');

			if ($nome === '' || $cpf === '' || $senha === '') {
				throw new RuntimeException('Preencha nome, CPF e senha para cadastro local.');
			}

			// Validar formato do CPF (XXX.XXX.XXX-XX ou XXXXXXXXXXX)
			$cpf_limpo = preg_replace('/\D/', '', $cpf);
			if (mb_strlen($cpf_limpo) !== 11) {
				throw new RuntimeException('CPF deve conter exatamente 11 dígitos.');
			}

			// Formatar CPF para armazenar como XXX.XXX.XXX-XX
			$cpf_formatado = preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf_limpo);

			if (mb_strlen($senha) < 6) {
				throw new RuntimeException('Senha local deve ter pelo menos 6 caracteres.');
			}

			$pdo = db();

			$stmt = $pdo->prepare('SELECT id_user FROM Usuario WHERE login = :login LIMIT 1');
			$stmt->execute(['login' => $cpf_formatado]);

			if ($stmt->fetch()) {
				throw new RuntimeException('Já existe um usuário local com esse CPF.');
			}

			$hash = password_hash($senha, PASSWORD_DEFAULT);

			$insert = $pdo->prepare(
				'INSERT INTO Usuario (nome, login, hash_senha, matricula, tipo_perfil, bloqueado, preferencias_notific)
				 VALUES (:nome, :login, :hash_senha, NULL, :tipo_perfil, 0, NULL)'
			);

			$insert->execute([
				'nome' => $nome,
				'login' => $cpf_formatado,
				'hash_senha' => $hash,
				'tipo_perfil' => $tipo_perfil,
			]);

			$dominio = match($tipo_perfil) {
				'admin' => 'ADMIN - Acesso total (alunos, laboratoristas e bloqueios)',
				'laboratorista' => 'LABORATORISTA - Painel de controle do laboratório',
				default => 'ESTUDANTE - Painel de reservas e empréstimos',
			};

			$mensagem = "Usuário local criado com sucesso!\nDomínio: {$dominio}\nCPF: {$cpf_formatado}\nAgora use o login abaixo.";
			$tipoMensagem = 'ok';
		}

		if ($acao === 'local_login') {
			$login = trim((string) ($_POST['login_local_login'] ?? ''));
			$senha = (string) ($_POST['senha_local_login'] ?? '');

			if ($login === '' || $senha === '') {
				throw new RuntimeException('Informe login e senha no formulário de login local.');
			}

			$pdo = db();
			$stmt = $pdo->prepare('SELECT id_user, nome, login, hash_senha, tipo_perfil, bloqueado FROM Usuario WHERE login = :login LIMIT 1');
			$stmt->execute(['login' => $login]);
			$user = $stmt->fetch();

			if (!$user || (int) $user['bloqueado'] === 1) {
				throw new RuntimeException('Usuário local não encontrado ou bloqueado.');
			}

			if (!password_verify($senha, (string) $user['hash_senha'])) {
				throw new RuntimeException('Senha local inválida.');
			}

			$_SESSION['auth_user'] = [
				'id' => (int) $user['id_user'],
				'nome' => (string) $user['nome'],
				'login' => (string) $user['login'],
				'perfil' => (string) $user['tipo_perfil'],
				'origem' => 'local_dev',
			];

			$mensagem = 'Login local realizado com sucesso. Sessão criada para testes.';
			$tipoMensagem = 'ok';
		}

		if ($acao === 'local_logout') {
			unset($_SESSION['auth_user']);
			$mensagem = 'Sessão local encerrada.';
			$tipoMensagem = 'ok';
		}
	} catch (Throwable $e) {
		$mensagem = $e->getMessage();
		$tipoMensagem = 'erro';
	}
}

$usuarioSessao = $_SESSION['auth_user'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Painel de ADMIN temporario</title>
	<style>
		body { font-family: Arial, sans-serif; background: #111; color: #eee; padding: 24px; }
		.wrap { max-width: 980px; margin: 0 auto; display: grid; gap: 16px; grid-template-columns: 1fr 1fr; }
		.card { background: #1d1d1d; border: 1px solid #333; border-radius: 10px; padding: 20px; }
		.full { grid-column: 1 / -1; }
		h1, h2 { margin-top: 0; }
		label { display: block; margin: 12px 0 6px; }
		input { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #121212; color: #fff; }
		button { margin-top: 14px; padding: 10px 14px; border-radius: 6px; border: 0; cursor: pointer; }
		.ok { margin-top: 14px; padding: 10px; background: #12361f; border: 1px solid #1f6a39; border-radius: 6px; }
		.erro { margin-top: 14px; padding: 10px; background: #3a1616; border: 1px solid #7a2d2d; border-radius: 6px; }
		.muted { color: #aaa; font-size: 0.92rem; }
		code { color: #9cdcfe; }
		@media (max-width: 900px) { .wrap { grid-template-columns: 1fr; } }
	</style>
</head>
<body>
	<div class="wrap">
		<div class="card full">
			<h1>Painel de testes (LDAP + login local)</h1>
			<p class="muted">
				Basicamente vai simular o ldap lá do iff criando a conta local ai no teu computador. Consegue gerenciar o tipo da conta: Admin, Aluno ou Laboratorista.
			</p>
			<?php if ($mensagem !== null): ?>
				<div class="<?= $tipoMensagem === 'ok' ? 'ok' : 'erro' ?>">
					<?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2>1) Teste LDAP</h2>
			<p class="muted">Valida credenciais com o servidor LDAP institucional. (vai funcionar apenas quanto estiver conectado ao LDAP do IFFar)</p>
			<form method="post">
				<input type="hidden" name="acao" value="ldap_test">
				<label for="usuario_ldap">Usuário institucional</label>
				<input id="usuario_ldap" name="usuario_ldap" type="text" required>

				<label for="tipo_conta_ldap">Tipo de Conta</label>
				<select id="tipo_conta_ldap" name="tipo_conta_ldap" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #121212; color: #fff;">
					<option value="estudante">Estudante - Painel de reservas e empréstimos</option>
					<option value="laboratorista">Laboratorista - Painel de controle</option>
					<option value="admin">Admin - Acesso total</option>
				</select>

				<label for="senha_ldap">Senha</label>
				<input id="senha_ldap" name="senha_ldap" type="password" required>

				<button type="submit">Testar LDAP</button>
			</form>
		</div>

		<div class="card">
			<h2>2) Cadastro local (DEV)</h2>
			<p class="muted">Cria conta no banco para desenvolvimento enquanto LDAP não está acessível.</p>
			<form method="post">
				<input type="hidden" name="acao" value="local_register">
				<label for="nome_local">Nome</label>
				<input id="nome_local" name="nome_local" type="text" required>

				<label for="cpf_local">CPF</label>
				<input id="cpf_local" name="cpf_local" type="text" placeholder="Ex: 111.111.111-11" maxlength="14" required>

				<label for="tipo_perfil_local">Tipo de Conta</label>
				<select id="tipo_perfil_local" name="tipo_perfil_local" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #121212; color: #fff;">
					<option value="estudante">Estudante - Painel de reservas e empréstimos</option>
					<option value="laboratorista">Laboratorista - Painel de controle</option>
					<option value="admin">Admin - Acesso total</option>
				</select>

				<label for="senha_local">Senha (mín. 6)</label>
				<input id="senha_local" name="senha_local" type="password" required>

				<button type="submit">Criar conta local</button>
			</form>
		</div>

		<div class="card">
			<h2>3) Login local (DEV)</h2>
			<form method="post">
				<input type="hidden" name="acao" value="local_login">
				<label for="login_local_login">CPF</label>
				<input id="login_local_login" name="login_local_login" type="text" placeholder="Ex: 111.111.111-11" maxlength="14" required>

				<label for="senha_local_login">Senha</label>
				<input id="senha_local_login" name="senha_local_login" type="password" required>

				<button type="submit">Entrar com conta local</button>
			</form>
		</div>

		<div class="card">
			<h2>4) Sessão atual</h2>
			<?php if (is_array($usuarioSessao)): ?>
				<p><strong>Logado como:</strong> <?= htmlspecialchars((string) $usuarioSessao['nome'], ENT_QUOTES, 'UTF-8') ?></p>
				<p><strong>Login:</strong> <?= htmlspecialchars((string) $usuarioSessao['login'], ENT_QUOTES, 'UTF-8') ?></p>
				<p><strong>Perfil:</strong> <?= htmlspecialchars((string) $usuarioSessao['perfil'], ENT_QUOTES, 'UTF-8') ?></p>
				<?php
					$dominio = match($usuarioSessao['perfil']) {
						'admin' => 'ADMIN - Acesso total (alunos, laboratoristas e bloqueios)',
						'laboratorista' => 'LABORATORISTA - Painel de controle do laboratório',
						default => 'ESTUDANTE - Painel de reservas e empréstimos',
					};
				?>
				<p><strong>Domínio:</strong> <?= htmlspecialchars($dominio, ENT_QUOTES, 'UTF-8') ?></p>
				<p><strong>Origem:</strong> <?= htmlspecialchars((string) $usuarioSessao['origem'], ENT_QUOTES, 'UTF-8') ?></p>
				<form method="post">
					<input type="hidden" name="acao" value="local_logout">
					<button type="submit">Sair</button>
				</form>
			<?php else: ?>
				<p class="muted">Nenhuma sessão local ativa.</p>
			<?php endif; ?>
		</div>
	</div>
</body>
<script>
	// Máscara de CPF para cadastro
	document.getElementById('cpf_local').addEventListener('input', function () {
		let v = this.value.replace(/\D/g, '').slice(0, 11);
		if (v.length > 8) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
		else if (v.length > 5) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
		else if (v.length > 2) v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
		this.value = v;
	});

	// Máscara de CPF para login
	document.getElementById('login_local_login').addEventListener('input', function () {
		let v = this.value.replace(/\D/g, '').slice(0, 11);
		if (v.length > 8) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
		else if (v.length > 5) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
		else if (v.length > 2) v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
		this.value = v;
	});
</script>
</html>
