<?php
require_once __DIR__ . '/../../src/services/notificacoes.php';
$resumoMenu = ['total' => 0, 'avisos' => 0, 'pedidos' => 0];
try {
    $resumoMenu = resumoNotificacoesNaoLidas(db(), (int) ($_SESSION['auth_user']['id'] ?? 0), (string) ($_SESSION['auth_user']['perfil'] ?? ''));
} catch (Throwable) { /* A navegação continua disponível se a contagem falhar. */ }
$linkNotificacoes = $notificacoesPerfil ?? ($basePath . '/pages_aluno/notificacoes.php');
?>
<a href="<?= htmlspecialchars($linkNotificacoes, ENT_QUOTES, 'UTF-8') ?>" role="menuitem" data-notificacoes-link
   data-resumo-url="<?= htmlspecialchars($basePath . '/api/notificacoes_resumo.php', ENT_QUOTES, 'UTF-8') ?>"
   aria-label="Notificações, <?= $resumoMenu['total'] ?> não lidas">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <span>Notificações</span>
    <span class="notif-count-badge" data-notificacoes-total aria-hidden="true" <?= $resumoMenu['total'] === 0 ? 'hidden' : '' ?>><?= $resumoMenu['total'] > 99 ? '99+' : $resumoMenu['total'] ?></span>
</a>
<script src="<?= htmlspecialchars($basePath . '/assets/js/notificacoes-indicador.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
