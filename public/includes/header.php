<?php
/* ── Dados de usuário (substituir por queries/sessão reais) ── */
$usuario_nome = $usuario_nome ?? 'Emanuel Ziegler';
$usuario_tipo_conta = $usuario_tipo_conta ?? 'Aluno (developer/test)';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' – C.I.R.C.U.I.T.O.' : 'C.I.R.C.U.I.T.O.' ?></title>
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
            background-color: #141414;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #222;
        }

        .nav-logo {
            font-family: 'Courier New', monospace;
            font-size: 1.15rem;
            font-weight: 900;
            letter-spacing: 0.06em;
            color: #ffffff;
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
            background-color: #2a2a2a;
            border: 1.5px solid #333;
            border-radius: 50px;
            color: #ffffff;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .nav-search input::placeholder { color: #666; }
        .nav-search input:focus { border-color: #555; }

        .nav-search button {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #aaa;
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
            color: #ffffff;
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: color 0.15s;
        }

        .nav-action-btn:hover { color: #cccccc; }

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
            color: #ffffff;
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
            color: #aaa;
            font-size: 0.78rem;
        }

        .dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 14px);
            right: 0;
            background-color: #1e1e1e;
            border: 1px solid #2e2e2e;
            border-radius: 12px;
            min-width: 200px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }

        .nav-user.open .dropdown { display: block; }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: #ffffff;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.15s;
            border-bottom: 1px solid #2a2a2a;
        }

        .dropdown a:last-child { border-bottom: none; }
        .dropdown a:hover { background-color: #2a2a2a; }

        .dropdown a svg {
            width: 18px;
            height: 18px;
            color: #aaa;
            flex-shrink: 0;
        }

        @media (max-width: 860px) {
            .navbar { padding: 0 20px; gap: 12px; }
        }
    </style>
