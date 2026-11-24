(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('contaEmailConfig').textContent);
    const error = document.getElementById('contaErro');
    const panels = { identidade: 'etapaIdentidade', endereco: 'etapaEndereco', codigo: 'etapaCodigo' };
    const dialog = document.getElementById('avisoCadastro');
    let etapa = config.etapa;
    let busy = false;
    let cooldown = Number(config.reenvioEm || 0);
    let pendingEmail = config.email;
    let pendingName = document.getElementById('contaNome')?.value || '';
    function mostrar(next, focus = true) {
        etapa = next;
        for (const [key, id] of Object.entries(panels)) document.getElementById(id).hidden = key !== next;
        document.querySelectorAll('[data-etapa]').forEach(el => {
            if (el.dataset.etapa === next) el.setAttribute('aria-current', 'step');
            else el.removeAttribute('aria-current');
        });
        if (focus) document.querySelector('#' + panels[next] + ' input')?.focus();
    }
    function atualizarReenvio() {
        const btn = document.getElementById('reenviarCodigo');
        btn.disabled = busy || cooldown > 0;
        btn.textContent = cooldown > 0 ? `Reenviar em ${cooldown}s` : 'Reenviar código';
    }
    async function enviar(acao, values = {}) {
        if (busy) return null;
        busy = true;
        error.hidden = true;
        document.querySelectorAll('button').forEach(btn => { btn.disabled = true; });
        try {
            const body = new URLSearchParams({ acao, csrf_email: config.csrf, ...values });
            const res = await fetch(config.endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                if (data.reautenticar) { document.getElementById('contaCodigo').value = ''; mostrar('identidade'); }
                throw new Error(data.erro || data.mensagem || 'Não foi possível continuar. Atualize a página e tente novamente.');
            }
            if (data.destino) { window.location.assign(data.destino); return null; }
            return data;
        } catch (e) {
            error.textContent = e instanceof Error ? e.message : 'Falha de conexão. Tente novamente.';
            error.hidden = false;
            error.scrollIntoView({ block: 'nearest' });
            return null;
        } finally {
            document.getElementById('contaSenha').value = '';
            busy = false;
            document.querySelectorAll('button').forEach(btn => { btn.disabled = false; });
            atualizarReenvio();
        }
    }
    document.getElementById('formIdentidade').addEventListener('submit', async e => {
        e.preventDefault();
        const data = await enviar('autenticar', { login: document.getElementById('contaLogin').value, senha: document.getElementById('contaSenha').value });
        if (data) mostrar('endereco');
    });
    async function enviarCodigo() {
        const data = await enviar('enviar', { email: pendingEmail, nome: pendingName });
        if (data) {
            document.getElementById('emailDestino').textContent = pendingEmail;
            document.getElementById('contaCodigo').value = '';
            cooldown = 60;
            atualizarReenvio();
            mostrar('codigo');
        }
    }
    document.getElementById('formEndereco').addEventListener('submit', e => {
        e.preventDefault();
        pendingEmail = document.getElementById('contaEmail').value.trim();
        pendingName = document.getElementById('contaNome')?.value.trim() || '';
        enviarCodigo();
    });
    document.getElementById('formCodigo').addEventListener('submit', async e => {
        e.preventDefault();
        await enviar('confirmar', { codigo: document.getElementById('contaCodigo').value });
    });
    document.getElementById('reenviarCodigo').addEventListener('click', enviarCodigo);
    document.getElementById('trocarEndereco').addEventListener('click', () => { error.hidden = true; mostrar('endereco'); });
    document.getElementById('cancelarEmail')?.addEventListener('click', async e => {
        const destino = e.currentTarget.dataset.destino;
        if (await enviar('cancelar')) window.location.assign(destino);
    });
    document.getElementById('contaCodigo').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });
    document.querySelector('[data-cpf]')?.addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    });
    document.getElementById('entendiCadastro').addEventListener('click', () => { dialog.close(); mostrar(etapa); });
    dialog.addEventListener('cancel', e => { e.preventDefault(); dialog.close(); mostrar(etapa); });
    mostrar(etapa, !config.popup);
    if (config.popup) dialog.showModal();
    atualizarReenvio();
    setInterval(() => { cooldown = Math.max(0, cooldown - 1); atualizarReenvio(); }, 1000);
})();
