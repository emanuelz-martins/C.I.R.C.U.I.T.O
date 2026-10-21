<?php
require_once __DIR__ . '/../../src/config/database.php';
loadEnv(dirname(__DIR__, 2) . '/.env');
$basePath = rtrim((string) parse_url((string) env('APP_URL', ''), PHP_URL_PATH), '/');
if ($basePath === '' && isset($_SERVER['SCRIPT_NAME'])) {
    $scriptPath = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    if (preg_match('#^(.*?/public)(?:/|$)#', $scriptPath, $m)) {
        $basePath = rtrim($m[1], '/');
    }
}
$faviconUrl = $basePath . '/assets/img/sempre/circuito.svg?v=2';
$temaJsUrl = $basePath . '/assets/js/tema.js';
$temaCssUrl = $basePath . '/assets/css/tema.css';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$authUser = $_SESSION['auth_user'] ?? [];

$usuario_nome = $usuario_nome ?? (is_array($authUser) ? (string) ($authUser['nome'] ?? 'Usuário') : 'Usuário');
$usuario_tipo_conta = $usuario_tipo_conta ?? (is_array($authUser) ? (string) ($authUser['perfil'] ?? 'Conta local') : 'Conta local');
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="<?= htmlspecialchars($temaJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars($temaCssUrl, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/svg+xml" sizes="any" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' – C.I.R.C.U.I.T.O.' : 'C.I.R.C.U.I.T.O.' ?></title>
    <?php if (($authUser['perfil'] ?? '') === 'admin'):
        $previewScript = rawurldecode(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $previewArquivo = basename($previewScript);
        $previewLab = str_contains($previewScript, '/pages_laboratorista/');
        $previewRestrito = $previewLab && !in_array($previewArquivo, ['index.php', 'catalogo.php', 'cadastrar_catalogo.php', 'cadastrar_categoria.php', 'ajuda.php'], true);
        $previewAluno = $previewArquivo !== 'perfil.php' && (str_contains($previewScript, '/pages_aluno/') || (($_SESSION['admin_visualizacao'] ?? '') === 'estudante' && !$previewLab && !str_contains($previewScript, '/pages_admin/')));
        $previewAtivo = $previewLab || $previewAluno;
    ?>
    <script id="adminPreviewConfig" type="application/json"><?= json_encode(['labRestrito' => $previewRestrito, 'aluno' => $previewAluno, 'ativo' => $previewAtivo, 'modo' => $previewLab ? 'Laboratorista' : 'Estudante', 'mensagem' => AVISO_PERMISSAO_ADMIN, 'voltar' => $basePath . '/pages_admin/index.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="<?= htmlspecialchars($basePath . '/assets/js/visualizacao-admin.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-pagina);
            color: var(--texto-principal);
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
        }

        /* ══════════════════════════════════════════
           NAVBAR
        ══════════════════════════════════════════ */
        .navbar {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 0 40px;
            height: 64px;
            background-color: var(--bg-pagina);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--borda-sutil);
        }

        .nav-logo {
            font-family: 'Courier New', monospace;
            font-size: 1.15rem;
            font-weight: 900;
            letter-spacing: 0.06em;
            color: var(--texto-principal);
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Barra de pesquisa */
        .nav-search {
            flex: 1;
            max-width: 520px;
            position: relative;
        }

        .nav-search input {
            width: 100%;
            padding: 10px 44px 10px 18px;
            background-color: var(--bg-controle);
            border: 1.5px solid var(--borda-campo);
            border-radius: 50px;
            color: var(--texto-principal);
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .nav-search input::placeholder { color: var(--texto-auxiliar); }
        .nav-search input:focus { border-color: var(--borda-foco); }

        .nav-search button {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--texto-secundario);
            padding: 0;
            display: flex;
            align-items: center;
        }

        /* Ações do nav */
        .nav-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .nav-action-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            background: none;
            border: none;
            color: var(--texto-principal);
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: color 0.15s;
        }

        .nav-action-btn:hover { color: var(--texto-medio); }

        .nav-action-btn svg {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        /* Usuário + dropdown */
        .nav-user {
            position: relative;
        }

        .nav-user-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: none;
            border: none;
            color: var(--texto-principal);
            cursor: pointer;
            font-size: 0.875rem;
            text-align: left;
        }

        .nav-user-btn svg {
            width: 26px;
            height: 26px;
            flex-shrink: 0;
        }

        .nav-user-btn .greeting { line-height: 1.3; }

        .nav-user-btn .greeting span {
            display: block;
            color: var(--texto-secundario);
            font-size: 0.78rem;
        }

        .dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 14px);
            right: 0;
            background-color: var(--bg-superficie-alt);
            border: 1px solid var(--borda-card);
            border-radius: 12px;
            min-width: 200px;
            overflow: hidden;
            box-shadow: var(--sombra-dropdown);
        }

        .nav-user.open .dropdown { display: block; }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--texto-principal);
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.15s;
            border-bottom: 1px solid var(--borda-padrao);
        }

        .dropdown a:last-child { border-bottom: none; }
        .dropdown a:hover { background-color: var(--bg-hover-menu); }

        .dropdown a svg {
            width: 18px;
            height: 18px;
            color: var(--texto-secundario);
            flex-shrink: 0;
        }

        @media (max-width: 860px) {
            .navbar { padding: 0 20px; gap: 12px; }
        }
    </style>
