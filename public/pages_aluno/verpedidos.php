<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin']);

require_once '../../src/config/database.php';

$page_title = 'Meus pedidos';
require_once '../includes/header.php';

$pedidos = [];
$db_ok = false;

try {
	$pdo = db();
	$id_usuario = (int) ($_SESSION['auth_user']['id'] ?? $_SESSION['auth_user']['id_user'] ?? 0);

	if ($id_usuario > 0) {
		$getCols = static function (PDO $pdo, string $table): array {
			$stmt = $pdo->prepare('
				SELECT COLUMN_NAME
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
			');
			$stmt->execute(['table' => $table]);
			return $stmt->fetchAll(PDO::FETCH_COLUMN);
		};

		$pedidoCols = $getCols($pdo, 'Pedido');
		if (!empty($pedidoCols)) {
			$statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
				: (in_array('status', $pedidoCols, true) ? 'status' : null);
			$numeroCol = in_array('numero_pedido', $pedidoCols, true) ? 'numero_pedido' : null;
			$dataCriacaoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao' : null;
			$dataAtualizacaoCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;

			if ($statusCol !== null) {
				$selectNumero = ($numeroCol !== null ? 'p.' . $numeroCol : 'p.id_pedido') . ' AS numero_pedido';
				$selectStatus = 'p.' . $statusCol . ' AS status_pedido';

				if ($dataAtualizacaoCol !== null && $dataCriacaoCol !== null) {
					$selectData = "DATE_FORMAT(COALESCE(p.{$dataAtualizacaoCol}, p.{$dataCriacaoCol}), '%d/%m/%Y') AS data_fmt";
				} elseif ($dataAtualizacaoCol !== null) {
					$selectData = "DATE_FORMAT(p.{$dataAtualizacaoCol}, '%d/%m/%Y') AS data_fmt";
				} elseif ($dataCriacaoCol !== null) {
					$selectData = "DATE_FORMAT(p.{$dataCriacaoCol}, '%d/%m/%Y') AS data_fmt";
				} else {
					$selectData = 'NULL AS data_fmt';
				}

				$orderBy = $dataAtualizacaoCol !== null ? 'p.' . $dataAtualizacaoCol . ' DESC, p.id_pedido DESC' : 'p.id_pedido DESC';

				/* Verifica se coluna arquivado existe */
				$temArquivado = in_array('arquivado', $pedidoCols, true);
				$whereArquivado = $temArquivado
					? 'AND (p.arquivado IS NULL OR p.arquivado = 0)'
					: '';

				$stmt = $pdo->prepare("
					SELECT
						p.id_pedido,
						{$selectNumero},
						{$selectStatus},
						{$selectData}
					FROM Pedido p
					WHERE p.id_user = :id_user {$whereArquivado}
					ORDER BY {$orderBy}
				");
				$stmt->execute(['id_user' => $id_usuario]);
				$pedidos = $stmt->fetchAll();
			}
		}
	}

	$db_ok = true;
} catch (Throwable) {
	/* BD indisponível */
}

$status_map = [
	'pendente' => ['label' => 'Enviado', 'class' => 'enviado'],
	'enviado' => ['label' => 'Enviado', 'class' => 'enviado'],
	'em-separacao' => ['label' => 'Em separação', 'class' => 'em-separacao'],
	'pronto-para-retirada' => ['label' => 'Pronto para retirada', 'class' => 'pronto-para-retirada'],
	'em-andamento' => ['label' => 'Em andamento', 'class' => 'em-andamento'],
	'em-atraso' => ['label' => 'Em atraso', 'class' => 'cancelado'],
	'negado' => ['label' => 'Negado', 'class' => 'cancelado'],
	'cancelado' => ['label' => 'Cancelado', 'class' => 'cancelado'],
	'finalizado' => ['label' => 'Finalizado', 'class' => 'finalizado'],
	'renovacao-solicitada' => ['label' => 'Renovação solicitada', 'class' => 'pronto-para-retirada'],
];
?>

<style>
	.main {
		padding: 40px 40px 80px;
		max-width: 1100px;
		margin: 0 auto;
	}

	.page-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 30px;
	}

	.page-title {
		font-size: 3rem;
		font-weight: 800;
		color: #ffffff;
		line-height: 1.1;
	}

	.btn-back {
		width: 44px;
		height: 44px;
		border-radius: 50%;
		background-color: #2a2a2a;
		border: 1px solid #3a3a3a;
		color: #ffffff;
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
		text-decoration: none;
		transition: background-color 0.15s;
		flex-shrink: 0;
	}

	.btn-back:hover { background-color: #333; }
	.btn-back svg { width: 20px; height: 20px; }

	.pedidos-list {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.pedido-card {
		background-color: #1c1c1c;
		border: 1px solid #2a2a2a;
		border-radius: 16px;
		padding: 22px 24px;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
	}

	.pedido-numero {
		font-size: 1.1rem;
		font-weight: 700;
		color: #ffffff;
		margin-bottom: 5px;
	}

	.pedido-meta {
		font-size: 0.84rem;
		color: #9ca3af;
	}

	.pedido-actions {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-shrink: 0;
	}

	.status-badge {
		padding: 8px 14px;
		border-radius: 8px;
		font-size: 0.8rem;
		font-weight: 700;
		white-space: nowrap;
	}

	.status-badge.enviado { background-color: #374151; color: #e5e7eb; }
	.status-badge.em-separacao { background-color: #7e22ce; color: #ede9fe; }
	.status-badge.pronto-para-retirada { background-color: #92400e; color: #fed7aa; }
	.status-badge.em-andamento { background-color: #1d4ed8; color: #dbeafe; }
	.status-badge.finalizado { background-color: #166534; color: #bbf7d0; }
	.status-badge.cancelado { background-color: #991b1b; color: #fecaca; }

	.btn-ver {
		padding: 10px 16px;
		border-radius: 10px;
		border: 1px solid #3a3a3a;
		background-color: #2a2a2a;
		color: #ffffff;
		text-decoration: none;
		font-size: 0.86rem;
		font-weight: 600;
		transition: background-color 0.15s;
	}

	.btn-ver:hover { background-color: #333; }

	.btn-ver.disabled,
	.btn-arquivar.disabled {
		opacity: 0.45;
		cursor: not-allowed;
		pointer-events: none;
	}

	.btn-bloqueio {
		padding: 8px 10px;
		border-radius: 8px;
		border: 1px solid #7f1d1d;
		background-color: #2a1212;
		color: #fca5a5;
		font-size: 0.74rem;
		font-weight: 700;
		white-space: nowrap;
	}

	.btn-arquivar {
		width: 40px;
		height: 40px;
		border-radius: 10px;
		border: 1px solid #3a3a3a;
		background-color: #2a2a2a;
		color: #9ca3af;
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		transition: background-color 0.15s, color 0.15s, border-color 0.15s;
	}

	.btn-arquivar svg { width: 18px; height: 18px; pointer-events: none; }
	.btn-arquivar:hover { background-color: #2a2e1a; border-color: #84cc16; color: #84cc16; }
	.btn-arquivar[title]:hover::after {
		content: attr(title);
		position: absolute;
		bottom: calc(100% + 6px);
		right: 0;
		background: #1a1a1a;
		border: 1px solid #3a3a3a;
		color: #fff;
		font-size: 0.75rem;
		padding: 4px 8px;
		border-radius: 6px;
		white-space: nowrap;
		pointer-events: none;
	}

	.pedido-actions { position: relative; }

	.link-arquivados {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		color: #6b7280;
		font-size: 0.84rem;
		text-decoration: none;
		transition: color 0.15s;
		margin-bottom: 24px;
	}

	.link-arquivados:hover { color: #9ca3af; }
	.link-arquivados svg { width: 16px; height: 16px; }

	.pedido-card.arquivando {
		opacity: 0;
		transform: translateX(20px);
		transition: opacity 0.3s ease, transform 0.3s ease;
	}

	.empty-state {
		text-align: center;
		padding: 64px 0;
		color: #666;
		font-size: 1rem;
	}

	.db-error {
		background-color: #1c0a0a;
		border: 1px solid #7f1d1d;
		border-radius: 16px;
		padding: 36px;
		text-align: center;
		color: #aaa;
	}

	.db-error h2 { color: #ef4444; margin-bottom: 8px; }

	@media (max-width: 720px) {
		.main { padding: 30px 18px 60px; }
		.pedido-card { flex-direction: column; align-items: flex-start; }
		.pedido-actions { width: 100%; justify-content: space-between; }
		.page-title { font-size: 2.1rem; }
	}
</style>
</head>
<body>

<nav class="navbar">
	<a href="../index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

	<form class="nav-search" action="../index.php" method="GET">
		<input
			type="text"
			name="q"
			placeholder="Pesquise por categoria, nome do item, etc."
			value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
		>
		<button type="submit" aria-label="Buscar">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
				 fill="none" stroke="currentColor" stroke-width="2.5"
				 stroke-linecap="round" stroke-linejoin="round">
				<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
			</svg>
		</button>
	</form>

	<div class="nav-actions">
		<a href="./carrinho.php" class="nav-action-btn">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
				 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
				<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
			</svg>
			Carrinho
		</a>

		<div class="nav-user" id="navUser">
			<button class="nav-user-btn" onclick="toggleDropdown()" aria-haspopup="true">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
					 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
					<circle cx="12" cy="7" r="4"/>
				</svg>
				<div class="greeting">
					<span>Bem vindo,</span>
					<strong><?= htmlspecialchars($usuario_nome) ?>!</strong>
				</div>
			</button>

			<div class="dropdown" role="menu">
				<a href="./profile.php" role="menuitem">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
						 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
						<circle cx="12" cy="7" r="4"/>
					</svg>
					Acessar perfil
				</a>
				<a href="./notificacoes.php" role="menuitem">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
						 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
						<path d="M13.73 21a2 2 0 0 1-3.46 0"/>
					</svg>
					Notificações
				</a>
				<a href="../logout.php" role="menuitem">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
						 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
						<polyline points="16 17 21 12 16 7"/>
						<line x1="21" y1="12" x2="9" y2="12"/>
					</svg>
					Logout
				</a>
			</div>
		</div>
	</div>
</nav>

<script>
	function toggleDropdown() {
		document.getElementById('navUser').classList.toggle('open');
	}

	document.addEventListener('click', function (e) {
		const navUser = document.getElementById('navUser');
		if (!navUser.contains(e.target)) navUser.classList.remove('open');
	});
</script>

<main class="main">
	<div class="page-header">
		<h1 class="page-title">Meus pedidos</h1>
		<a href="javascript:history.back()" class="btn-back" aria-label="Voltar">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
				 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
				<polyline points="15 18 9 12 15 6"/>
			</svg>
		</a>
	</div>

	<a href="./arquivados.php" class="link-arquivados">
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
			 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
			<polyline points="21 8 21 21 3 21 3 8"/>
			<rect x="1" y="3" width="22" height="5"/>
			<line x1="10" y1="12" x2="14" y2="12"/>
		</svg>
		Ver pedidos arquivados
	</a>

	<?php if (!$db_ok): ?>
	<div class="db-error">
		<h2>Banco de dados indisponível</h2>
		<p>Não foi possível carregar seus pedidos no momento.</p>
	</div>

	<?php elseif (empty($pedidos)): ?>
	<p class="empty-state">Você ainda não possui pedidos realizados.</p>

	<?php else: ?>
	<div class="pedidos-list">
		<?php foreach ($pedidos as $p):
			$status = (string) ($p['status_pedido'] ?? 'pendente');
			$statusInfo = $status_map[$status] ?? ['label' => ucfirst($status), 'class' => 'enviado'];
			$numFmt = sprintf('%03d', (int) ($p['numero_pedido'] ?? 0));
		?>
		<div class="pedido-card" id="pedido-<?= (int) $p['id_pedido'] ?>">
			<div>
				<p class="pedido-numero">Pedido #<?= htmlspecialchars($numFmt) ?></p>
				<p class="pedido-meta">Última atualização: <?= htmlspecialchars($p['data_fmt'] ?? '—') ?></p>
			</div>

			<div class="pedido-actions">
				<span class="status-badge <?= htmlspecialchars($statusInfo['class']) ?>">
					<?= htmlspecialchars($statusInfo['label']) ?>
				</span>
				<a href="./pedido.php?id=<?= (int) $p['id_pedido'] ?>" class="btn-ver">Ver detalhes</a>
				<button class="btn-arquivar"
						title="Arquivar pedido"
						onclick="arquivarPedido(this, <?= (int) $p['id_pedido'] ?>)">
					<!-- Ícone fichário / caixa de arquivo -->
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
						 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<polyline points="21 8 21 21 3 21 3 8"/>
						<rect x="1" y="3" width="22" height="5"/>
						<line x1="10" y1="12" x2="14" y2="12"/>
					</svg>
				</button>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</main>

<script>
	async function arquivarPedido(btn, id) {
		btn.disabled = true;
		const fd = new FormData();
		fd.append('id', id);
		fd.append('acao', 'arquivar');

		const res  = await fetch('../api/pedido_arquivar.php', { method: 'POST', body: fd });
		const data = await res.json();

		if (!data.ok) {
			if (data.erro) alert(data.erro);
			btn.disabled = false;
			return;
		}

		const card = document.getElementById('pedido-' + id);
		card.classList.add('arquivando');
		card.addEventListener('transitionend', () => card.remove(), { once: true });
	}
</script>
</body>
</html>
