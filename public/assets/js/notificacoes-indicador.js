(() => {
    const link = document.querySelector('[data-notificacoes-link]');
    if (!link) return;
    const badge = link.querySelector('[data-notificacoes-total]');
    const botaoPerfil = document.querySelector('.nav-user-btn');
    let buscando = false;
    let revisao = 0;

    function aplicar(resumo) {
        const total = Math.max(0, Number(resumo.total) || 0);
        badge.hidden = total === 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        link.setAttribute('aria-label', 'Notificações, ' + total + ' não lidas');
        botaoPerfil?.classList.toggle('has-unread-notifications', total > 0);
        document.querySelectorAll('[data-notificacoes-grupo]').forEach(contador => {
            const quantidade = Math.max(0, Number(resumo[contador.dataset.notificacoesGrupo]) || 0);
            contador.textContent = String(quantidade);
            contador.setAttribute('aria-label', quantidade + ' não lidas');
            contador.hidden = quantidade === 0;
        });
    }

    async function atualizar() {
        if (buscando || document.hidden) return;
        buscando = true;
        const revisaoConsulta = revisao;
        try {
            const res = await fetch(link.dataset.resumoUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (revisaoConsulta !== revisao) return;
            if (res.ok && data.ok) aplicar(data.resumo);
            else if (res.status === 401) aplicar({ total: 0, avisos: 0, pedidos: 0 });
        } catch { /* Mantém a contagem conhecida em falhas de rede. */ }
        finally { buscando = false; }
    }

    botaoPerfil?.classList.toggle('has-unread-notifications', parseInt(badge.textContent, 10) > 0);
    document.addEventListener('notificacoes:atualizadas', e => {
        revisao++;
        if (e.detail) aplicar(e.detail);
        else atualizar();
    });
    window.addEventListener('focus', atualizar);
    window.addEventListener('pageshow', atualizar);
    document.addEventListener('visibilitychange', atualizar);
    setInterval(atualizar, 45000);
})();
