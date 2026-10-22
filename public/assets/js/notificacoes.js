(() => {
    const page = document.querySelector('[data-notificacoes-page]');
    if (!page) return;
    const tabs = [...page.querySelectorAll('[role="tab"]')];
    const erro = page.querySelector('[data-notif-error]');

    function selecionar(tab, atualizarUrl = true) {
        tabs.forEach(item => {
            const ativa = item === tab;
            item.setAttribute('aria-selected', String(ativa));
            item.tabIndex = ativa ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !ativa;
        });
        if (atualizarUrl) history.replaceState(null, '', location.pathname + location.search + (tab.id === 'tab-avisos' ? '#avisos' : '#pedidos'));
    }
    const hashTab = tabs.find(tab => tab.id === (location.hash === '#pedidos' ? 'tab-automaticas' : location.hash === '#avisos' ? 'tab-avisos' : ''));
    if (hashTab) selecionar(hashTab, false);
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selecionar(tab));
        tab.addEventListener('keydown', e => {
            let proximo;
            if (e.key === 'ArrowRight') proximo = tabs[(index + 1) % tabs.length];
            if (e.key === 'ArrowLeft') proximo = tabs[(index + tabs.length - 1) % tabs.length];
            if (e.key === 'Home') proximo = tabs[0];
            if (e.key === 'End') proximo = tabs[tabs.length - 1];
            if (!proximo) return;
            e.preventDefault();
            selecionar(proximo);
            proximo.focus();
        });
    });

    page.addEventListener('click', async e => {
        const botao = e.target.closest('button[data-acao]');
        if (!botao || botao.disabled) return;
        const card = botao.closest('.notif-card');
        if (card.dataset.ocupada) return;
        card.dataset.ocupada = 'true';
        const botoes = [...card.querySelectorAll('button')];
        botoes.forEach(item => { item.disabled = true; });
        erro.textContent = '';
        try {
            const fd = new FormData();
            fd.append('acao', botao.dataset.acao);
            fd.append('id', botao.dataset.id);
            const res = await fetch(page.dataset.actionUrl, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.erro || data.mensagem || 'Não foi possível atualizar a notificação. Tente novamente.');
            if (botao.dataset.acao === 'marcar_lida') {
                card.classList.add('lida');
                card.querySelector('.dot-new')?.remove();
                botao.classList.add('active');
                botao.title = 'Lida';
                botao.setAttribute('aria-label', 'Lida');
            } else {
                const painel = card.closest('.notif-panel');
                card.remove();
                painel.querySelector('.notif-empty').hidden = Boolean(painel.querySelector('.notif-card'));
            }
            document.dispatchEvent(new CustomEvent('notificacoes:atualizadas', { detail: data.resumo }));
        } catch (err) {
            erro.textContent = err instanceof TypeError ? 'Não foi possível conectar. Tente novamente.' : err.message;
        } finally {
            delete card.dataset.ocupada;
            botoes.forEach(item => { item.disabled = item.classList.contains('btn-lida') && card.classList.contains('lida'); });
        }
    });
})();
