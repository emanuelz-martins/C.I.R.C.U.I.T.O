(() => {
    'use strict';

    const config = JSON.parse(document.getElementById('cadastroItemConfig').textContent);
    const form = document.getElementById('formCadastro');
    const input = document.getElementById('inputImagem');
    const area = document.getElementById('uploadArea');
    const campos = ['nome', 'id_cat', 'descricao_curta', 'descricao_completa', 'qtd_disponivel', 'qtd_max_user', 'qtd_minima'];
    const chave = 'item:' + config.usuario;
    const tipos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    let imagem = null;
    let previewUrl = null;
    let timer;
    let tocado = false;
    let gravacoes = Promise.resolve();
    let enviando = false;

    // IndexedDB guarda o arquivo inteiro, inclusive imagens de até 5 MB.
    const banco = new Promise((resolve, reject) => {
        const pedido = indexedDB.open('circuito-cadastro-item', 1);
        pedido.onupgradeneeded = () => pedido.result.createObjectStore('rascunhos');
        pedido.onsuccess = () => resolve(pedido.result);
        pedido.onerror = () => reject(pedido.error);
        pedido.onblocked = () => reject(new Error('Armazenamento indisponível.'));
    });

    async function operar(modo, acao) {
        const db = await banco;
        return new Promise((resolve, reject) => {
            const transacao = db.transaction('rascunhos', modo);
            const pedido = acao(transacao.objectStore('rascunhos'));
            transacao.oncomplete = () => resolve(pedido.result);
            transacao.onerror = () => reject(transacao.error);
            transacao.onabort = () => reject(transacao.error);
        });
    }

    function avisoRascunho() {
        if (document.getElementById('avisoRascunho')) return;
        const aviso = document.createElement('p');
        aviso.id = 'avisoRascunho';
        aviso.className = 'upload-hint';
        aviso.setAttribute('role', 'status');
        aviso.textContent = 'Não foi possível guardar o rascunho neste navegador. Cadastre o item antes de recarregar.';
        area.append(aviso);
    }

    function salvarRascunho() {
        clearTimeout(timer);
        const valores = {};
        campos.forEach(nome => { valores[nome] = form.elements.namedItem(nome).value; });
        const rascunho = { versao: config.versao, valores, imagem, nomeImagem: imagem?.name };
        gravacoes = gravacoes.then(() => operar('readwrite', loja => loja.put(rascunho, chave)))
            .catch(avisoRascunho);
        return gravacoes;
    }

    function exibirImagem() {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = imagem ? URL.createObjectURL(imagem) : null;
        document.getElementById('uploadDefault').style.display = imagem ? 'none' : '';
        document.getElementById('uploadPreview').style.display = imagem ? 'flex' : 'none';
        const img = document.getElementById('previewImg');
        if (previewUrl) img.src = previewUrl;
        else img.removeAttribute('src');
        document.getElementById('previewNome').textContent = imagem
            ? imagem.name + ' (' + (imagem.size / 1024).toFixed(0) + ' KB)' : '';
    }

    function atribuirImagem(arquivo) {
        const arquivos = new DataTransfer();
        if (arquivo) arquivos.items.add(arquivo);
        input.files = arquivos.files;
    }

    window.previewImagem = () => {
        const arquivo = input.files?.[0];
        if (!arquivo) return;
        tocado = true;
        if (!tipos.includes(arquivo.type) || arquivo.size > 5 * 1024 * 1024) {
            atribuirImagem(imagem);
            alert('Use uma imagem JPG, PNG, GIF ou WebP de até 5 MB.');
            return;
        }
        imagem = arquivo;
        exibirImagem();
        salvarRascunho();
    };

    window.limparImagem = evento => {
        evento.stopPropagation();
        tocado = true;
        imagem = null;
        atribuirImagem(null);
        exibirImagem();
        salvarRascunho();
    };

    const pronto = (async () => {
        try {
            const salvo = await operar('readonly', loja => loja.get(chave));
            if (!salvo) return;
            // Um cadastro concluído invalida o rascunho da sessão anterior.
            if (salvo.versao !== config.versao) {
                await operar('readwrite', loja => loja.delete(chave));
                return;
            }
            if (tocado) return;
            if (config.restaurarCampos) {
                campos.forEach(nome => {
                    if (typeof salvo.valores?.[nome] !== 'string') return;
                    const campo = form.elements.namedItem(nome);
                    if (campo.tagName === 'SELECT' && !Array.from(campo.options).some(opcao => opcao.value === salvo.valores[nome])) return;
                    campo.value = salvo.valores[nome];
                });
            }
            if (salvo.imagem instanceof Blob && tipos.includes(salvo.imagem.type) && salvo.imagem.size <= 5 * 1024 * 1024) {
                imagem = new File([salvo.imagem], salvo.nomeImagem || 'imagem', { type: salvo.imagem.type });
                atribuirImagem(imagem);
                exibirImagem();
            }
        } catch {
            // O formulário continua utilizável mesmo sem armazenamento local.
        }
    })();

    form.querySelectorAll('.quantity-control').forEach(controle => {
        const campo = controle.querySelector('input');
        const diminuir = controle.querySelector('[data-step="-1"]');
        const atualizar = () => {
            const valor = Number.isFinite(campo.valueAsNumber) ? campo.valueAsNumber : 0;
            diminuir.disabled = valor <= Number(campo.min || 0);
        };
        controle.querySelectorAll('[data-step]').forEach(botao => {
            botao.addEventListener('click', () => {
                campo.stepUp(Number(botao.dataset.step));
                campo.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        campo.addEventListener('input', atualizar);
        pronto.then(atualizar);
        atualizar();
    });

    for (const evento of ['input', 'change']) {
        form.addEventListener(evento, e => {
            if (!campos.includes(e.target.name)) return;
            tocado = true;
            clearTimeout(timer);
            timer = setTimeout(salvarRascunho, 200);
        });
    }
    area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('dragover'); });
    area.addEventListener('dragleave', () => area.classList.remove('dragover'));
    area.addEventListener('drop', e => {
        e.preventDefault();
        area.classList.remove('dragover');
        if (!e.dataTransfer.files.length) return;
        atribuirImagem(e.dataTransfer.files[0]);
        window.previewImagem();
    });
    window.addEventListener('pagehide', () => { if (tocado) salvarRascunho(); });
    window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (enviando) return;
        await pronto;
        const erros = [];
        if (!form.elements.namedItem('nome').value.trim()) erros.push('Nome do item é obrigatório.');
        if (!form.elements.namedItem('id_cat').value) erros.push('Selecione uma categoria.');
        if (form.elements.namedItem('descricao_curta').value.trim().length > 500) erros.push('Especificações Técnicas devem ter no máximo 500 caracteres.');
        if (form.elements.namedItem('descricao_completa').value.trim().length > 500) erros.push('Descrição completa deve ter no máximo 500 caracteres.');
        if (erros.length) { alert(erros.join('\n')); return; }
        enviando = true;
        const botao = form.querySelector('[type="submit"]');
        botao.disabled = true;
        await salvarRascunho();
        HTMLFormElement.prototype.submit.call(form);
    });
})();
