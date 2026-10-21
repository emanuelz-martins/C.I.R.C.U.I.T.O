(function () {
    'use strict';

    var STORAGE_KEY = 'tema';
    var TEMA_PADRAO = 'escuro';
    var temaAtual = TEMA_PADRAO;

    function normalizarTema(valor) {
        return valor === 'claro' || valor === 'escuro' ? valor : TEMA_PADRAO;
    }

    function lerTemaSalvo() {
        try {
            return normalizarTema(window.localStorage.getItem(STORAGE_KEY));
        } catch (e) {
            return TEMA_PADRAO;
        }
    }

    function salvarTema(tema) {
        try {
            window.localStorage.setItem(STORAGE_KEY, tema);
        } catch (e) {
            /* Prossegue normalmente na página atual quando o localStorage estiver indisponível */
        }
    }

    function atualizarBotoes(tema) {
        var btnClaro = document.getElementById('btnClaro');
        var btnEscuro = document.getElementById('btnEscuro');
        var isClaro = tema === 'claro';

        if (btnClaro) {
            btnClaro.classList.toggle('active', isClaro);
            btnClaro.setAttribute('aria-pressed', isClaro ? 'true' : 'false');
        }
        if (btnEscuro) {
            btnEscuro.classList.toggle('active', !isClaro);
            btnEscuro.setAttribute('aria-pressed', !isClaro ? 'true' : 'false');
        }
    }

    function aplicarTema(tema, persistir) {
        var temaValido = normalizarTema(tema);
        temaAtual = temaValido;

        if (document.documentElement) {
            document.documentElement.setAttribute('data-tema', temaValido);
        }

        if (persistir) {
            salvarTema(temaValido);
        }

        atualizarBotoes(temaValido);
    }

    window.setTheme = function (tema) {
        aplicarTema(tema, true);
    };

    aplicarTema(lerTemaSalvo(), false);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            atualizarBotoes(temaAtual);
        });
    } else {
        atualizarBotoes(temaAtual);
    }

    window.addEventListener('storage', function (event) {
        if (event && event.key !== null && event.key !== STORAGE_KEY) {
            return;
        }
        var novoTema = event && event.key === STORAGE_KEY
            ? normalizarTema(event.newValue)
            : lerTemaSalvo();
        aplicarTema(novoTema, false);
    });
})();
