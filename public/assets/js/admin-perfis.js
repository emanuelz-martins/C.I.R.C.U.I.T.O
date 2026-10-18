(() => {
    'use strict';

    const dados = JSON.parse(document.getElementById('perfisData').textContent);
    const usuarios = new Map(dados.usuarios.map(usuario => [Number(usuario.id_user), usuario]));
    const tabela = document.getElementById('usersTable');
    const busca = document.getElementById('searchInput');
    const painel = document.getElementById('perfilPanel');
    const titulo = document.getElementById('panelTitle');
    const formulario = document.getElementById('perfilForm');
    const perfil = document.getElementById('newRole');
    const salvar = document.getElementById('saveProfile');
    const feedback = document.getElementById('profileFeedback');
    let usuarioAtual = null;
    let salvando = false;

    const normalizar = valor => String(valor ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    const valorOuTraco = valor => String(valor ?? '').trim() || '—';
    const formasLogin = { google: 'Google', teste: 'Teste', sigaa: 'SIGAA' };

    function destacarPerfil(elemento, tipo) {
        elemento.className = 'badge badge-' + (Object.hasOwn(dados.perfis, tipo) ? tipo : 'desconhecido');
        elemento.textContent = dados.perfis[tipo] || 'Não reconhecido';
    }

    function atualizarFormulario() {
        const propriaConta = usuarioAtual === Number(dados.atorId);
        perfil.disabled = propriaConta || salvando;
        salvar.disabled = propriaConta || salvando || !Object.hasOwn(dados.perfis, perfil.value);
        salvar.textContent = salvando ? 'Salvando…' : 'Salvar perfil';
    }

    function preencherUsuario(usuario) {
        titulo.textContent = 'Dados de ' + usuario.nome;
        document.getElementById('detailId').textContent = '#' + usuario.id_user;
        document.getElementById('detailName').textContent = usuario.nome;
        document.getElementById('detailMatricula').textContent = valorOuTraco(usuario.matricula);
        document.getElementById('detailEmail').textContent = valorOuTraco(usuario.email);
        document.getElementById('detailLoginMethod').textContent = Object.hasOwn(formasLogin, usuario.forma_login)
            ? formasLogin[usuario.forma_login] : '—';
        destacarPerfil(document.getElementById('detailRole'), usuario.tipo_perfil);
        document.getElementById('selectedUserId').value = usuario.id_user;
        perfil.value = Object.hasOwn(dados.perfis, usuario.tipo_perfil) ? usuario.tipo_perfil : '';
        document.getElementById('profileNote').textContent = usuarioAtual === Number(dados.atorId)
            ? 'Peça a outro administrador para alterar seu perfil.' : '';
        atualizarFormulario();
    }

    function abrirPerfil(id) {
        const usuario = usuarios.get(id);
        if (!usuario) return;
        usuarioAtual = id;
        feedback.textContent = '';
        document.getElementById('pageFeedback').textContent = '';
        preencherUsuario(usuario);
        painel.hidden = false;
        tabela.style.opacity = '0.4';
        busca.style.opacity = '0.4';
        tabela.querySelectorAll('[aria-expanded]').forEach(botao => {
            botao.setAttribute('aria-expanded', String(Number(botao.closest('.user-row').dataset.usuarioId) === id));
        });
        titulo.focus({ preventScroll: true });
        painel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function fecharPerfil(devolverFoco = true) {
        const anterior = usuarioAtual;
        painel.hidden = true;
        tabela.style.opacity = '';
        busca.style.opacity = '';
        tabela.querySelectorAll('[aria-expanded]').forEach(botao => botao.setAttribute('aria-expanded', 'false'));
        usuarioAtual = null;
        feedback.textContent = '';
        if (devolverFoco && anterior !== null) document.getElementById('abrirPerfil-' + anterior)?.focus();
    }

    tabela.addEventListener('click', evento => {
        const linha = evento.target.closest('.user-row');
        if (linha) abrirPerfil(Number(linha.dataset.usuarioId));
    });
    document.getElementById('closePanel').addEventListener('click', () => fecharPerfil());
    document.addEventListener('keydown', evento => {
        if (evento.key === 'Escape' && !painel.hidden && !salvando) fecharPerfil();
    });
    perfil.addEventListener('change', atualizarFormulario);

    busca.addEventListener('input', () => {
        const termo = normalizar(busca.value);
        const digitos = termo.replace(/\D/g, '');
        let quantidade = 0;
        tabela.querySelectorAll('.user-row').forEach(linha => {
            const id = Number(linha.dataset.usuarioId);
            const usuario = usuarios.get(id);
            const corresponde = normalizar(usuario.nome).includes(termo)
                || normalizar(usuario.login).includes(termo)
                || (digitos.length === 11 && usuario.login.replace(/\D/g, '') === digitos);
            linha.hidden = !corresponde;
            if (corresponde) quantidade++;
            if (!corresponde && usuarioAtual === id) fecharPerfil(false);
        });
        document.getElementById('emptyUsers').hidden = quantidade !== 0;
        document.getElementById('userCount').textContent = quantidade + ' usuário(s) encontrado(s)';
    });

    formulario.addEventListener('submit', async evento => {
        evento.preventDefault();
        if (usuarioAtual === null || salvar.disabled) return;
        const id = usuarioAtual;
        const payload = new FormData(formulario);
        salvando = true;
        atualizarFormulario();
        feedback.textContent = '';
        try {
            const resposta = await fetch(window.location.href, {
                method: 'POST', headers: { Accept: 'application/json' }, body: payload,
            });
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.ok || !resultado.usuario) {
                throw new Error(resultado.error || 'Não foi possível salvar o perfil. Tente novamente.');
            }
            usuarios.set(id, resultado.usuario);
            destacarPerfil(tabela.querySelector(`[data-usuario-id="${id}"] [data-role-badge]`), resultado.usuario.tipo_perfil);
            if (usuarioAtual === id) {
                preencherUsuario(resultado.usuario);
                feedback.className = 'feedback success';
                feedback.textContent = 'Perfil salvo.';
            }
        } catch (erro) {
            if (usuarioAtual === id) {
                feedback.className = 'feedback error';
                feedback.textContent = erro instanceof SyntaxError ? 'Não foi possível salvar o perfil. Recarregue a página e tente novamente.' : erro.message;
            }
        } finally {
            salvando = false;
            atualizarFormulario();
        }
    });
})();
