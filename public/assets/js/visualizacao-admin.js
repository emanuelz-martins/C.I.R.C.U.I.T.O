(() => {
    const config = JSON.parse(document.getElementById('adminPreviewConfig').textContent);
    const estilo = document.createElement('style');
    estilo.textContent = '.admin-preview-bar{display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:16px;padding:10px 20px;background:#1e1e1e;border-bottom:1px solid #333;color:#aaa;font:13px "Segoe UI",Arial,sans-serif}.admin-preview-bar a{color:#fff;text-decoration:underline}.admin-preview-notice{position:fixed;right:24px;bottom:24px;z-index:10000;width:min(370px,calc(100vw - 48px));padding:18px 42px 18px 18px;border:1px solid #444;border-radius:12px;background:#1e1e1e;color:#fff;box-shadow:0 8px 30px #0006;font:14px/1.5 "Segoe UI",Arial,sans-serif}.admin-preview-notice[hidden]{display:none}.admin-preview-notice button{position:absolute;right:12px;top:9px;color:#aaa;border:0;background:none;cursor:pointer;font-size:22px}';
    document.head.append(estilo);
    const aviso = document.createElement('div');
    aviso.className = 'admin-preview-notice';
    aviso.setAttribute('role', 'alert');
    aviso.hidden = true;
    const texto = document.createElement('span');
    texto.textContent = config.mensagem;
    const fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.setAttribute('aria-label', 'Fechar aviso');
    fechar.textContent = '×';
    fechar.addEventListener('click', () => { aviso.hidden = true; });
    aviso.append(texto, fechar);
    document.body.append(aviso);
    let timer;
    window.mostrarAvisoPermissaoAdmin = () => {
        aviso.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(() => { aviso.hidden = true; }, 6500);
    };
    if (config.ativo) {
        const faixa = document.createElement('div');
        faixa.className = 'admin-preview-bar';
        const rotulo = document.createElement('span');
        rotulo.textContent = 'Visualização de ' + config.modo + ' · Conta Administrador';
        const voltar = document.createElement('a');
        voltar.href = config.voltar;
        voltar.textContent = 'Voltar ao administrador';
        faixa.append(rotulo, voltar);
        document.body.prepend(faixa);
    }
    const impedir = (evento) => {
        evento.preventDefault();
        evento.stopImmediatePropagation();
        window.mostrarAvisoPermissaoAdmin();
    };
    document.addEventListener('click', (evento) => {
        if (!(evento.target instanceof Element)) return;
        const itemLab = '.menu-dropdown button,.menu-dropdown a,.action-dropdown button,.action-dropdown a,.relatorio-dropdown button,.relatorio-dropdown a,.menu-item,.pedir-info-btn,.btn-restaurar';
        const comprar = '#btnEnviar,.btn-confirm-submit';
        if ((config.labRestrito && evento.target.closest(itemLab)) || (config.aluno && evento.target.closest(comprar))) impedir(evento);
    }, true);
    document.addEventListener('submit', (evento) => {
        const form = evento.target;
        if (!(form instanceof HTMLFormElement)) return;
        if ((config.labRestrito && form.method.toLowerCase() !== 'get') || (config.aluno && form.id === 'formEnviarPedido')) impedir(evento);
    }, true);
})();
