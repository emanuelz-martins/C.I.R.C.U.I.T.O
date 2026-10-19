<style>
    .site-footer {
        background: #111;
        border-top: 1px solid #1e1e1e;
        padding: 56px 48px;
        display: flex;
        align-items: flex-start;
        gap: 80px;
        margin-top: 80px;
    }

    .footer-brand {
        flex-shrink: 0;
    }

    .footer-brand p {
        font-size: 0.9rem;
        color: #555;
        margin-bottom: 8px;
    }

    .footer-brand-name {
        font-family: 'Courier New', monospace;
        font-size: 1.6rem;
        font-weight: 900;
        letter-spacing: 0.06em;
        color: #ffffff;
    }

    .footer-text {
        flex: 1;
        font-size: 0.83rem;
        color: #555;
        line-height: 1.7;
    }

    .footer-text ul {
        list-style: disc;
        padding-left: 18px;
        margin-top: 8px;
    }

@media(max-width:900px){.site-footer{flex-direction:column;gap:32px;padding:40px 20px}}
</style>
<footer class="site-footer">
    <div class="footer-brand">
        <p>Conheça o</p>
        <div class="footer-brand-name">C.I.R.C.U.I.T.O.</div>
    </div>
    <div class="footer-text">
        <p>O sistema oficial do Laboratório de Hardware do Instituto Federal Farroupilha / Campus Frederico
        Westphalen para gerenciamento de componentes. Aqui você encontra um catálogo organizado, realiza
        reservas com datas definidas e acompanha todo o processo de empréstimo de forma simples, segura e
        transparente.</p>
        <p style="margin-top:12px;">O projeto foi realizado pelos estudantes:</p>
        <ul>
            <li>Davi Cadoná Marion;</li>
            <li>Emanuel Ziegler Martins;</li>
            <li>Luiz Fernando Schwanz;</li>
            <li>Pedro Henrique Toazza;</li>
            <li>Victor Borba de Moura e Silva.</li>
        </ul>
        <?php if (($_SESSION['auth_user']['perfil'] ?? '') === 'admin' && str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/pages_admin/')): ?>
        <div class="admin-view-options">
            <?php foreach (['estudante' => 'Entrar como Estudante', 'laboratorista' => 'Entrar como Laboratorista'] as $modo => $rotulo): ?>
            <form method="POST" action="./visualizar.php">
                <input type="hidden" name="csrf_visualizacao_admin" value="<?= htmlspecialchars($_SESSION['csrf_visualizacao_admin'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="visualizacao" value="<?= $modo ?>">
                <button type="submit"><?= $rotulo ?></button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</footer>
<style>
.admin-view-options{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
.admin-view-options button{background:#1e1e1e;color:#fff;border:1px solid #333;border-radius:10px;padding:12px 18px;font:600 .85rem 'Segoe UI',Arial,sans-serif;cursor:pointer}
.admin-view-options button:hover{background:#2a2a2a}
.admin-view-options button:focus-visible{outline:2px solid #fff;outline-offset:3px}
</style>
