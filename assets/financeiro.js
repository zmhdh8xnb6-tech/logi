document.querySelectorAll('.campo-moeda').forEach(function (campo) {
    campo.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9.,]/g, '');
    });

    campo.addEventListener('blur', function () {
        let valor = this.value.trim();

        if (valor === '') {
            return;
        }

        if (valor.includes(',')) {
            valor = valor.replace(/\./g, '').replace(',', '.');
        } else {
            const partes = valor.split('.');

            if (partes.length > 2) {
                valor = partes.join('');
            } else if (partes.length === 2 && partes[1].length === 3) {
                valor = partes.join('');
            }
        }

        const numero = Number(valor);

        if (!Number.isFinite(numero)) {
            return;
        }

        this.value = numero.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    });
});

(function () {
    const chavePrivacidade = 'financeiroValoresOcultos';
    const regexMoeda = /-?R\$\s?-?\d{1,3}(?:\.\d{3})*,\d{2}|-?R\$\s?-?\d+,\d{2}/g;
    const seletoresIgnorados = 'script, style, input, textarea, select, option, button, canvas, .financeiro-valor-sensivel';
    let observadorAtivo = false;
    let observador = null;

    function valoresOcultos() {
        return localStorage.getItem(chavePrivacidade) === '1';
    }

    function aplicarEstado() {
        const ocultar = valoresOcultos();
        document.body.classList.toggle('financeiro-valores-ocultos', ocultar);

        document.querySelectorAll('.financeiro-privacidade-toggle').forEach(function (botao) {
            const icone = botao.querySelector('i');
            const texto = botao.querySelector('span');
            botao.setAttribute('aria-pressed', ocultar ? 'true' : 'false');
            botao.title = ocultar ? 'Mostrar valores' : 'Ocultar valores';

            if (icone) {
                icone.className = ocultar ? 'bi bi-eye-slash' : 'bi bi-eye';
            }

            if (texto) {
                texto.textContent = ocultar ? 'Mostrar valores' : 'Ocultar valores';
            }
        });
    }

    function criarBotaoPrivacidade() {
        const destino = document.querySelector('.financeiro-cabecalho > div:last-child');

        if (!destino || destino.querySelector('.financeiro-privacidade-toggle')) {
            return;
        }

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'btn btn-outline-secondary financeiro-privacidade-toggle';
        botao.innerHTML = '<i class="bi bi-eye"></i><span>Ocultar valores</span>';
        botao.addEventListener('click', function () {
            localStorage.setItem(chavePrivacidade, valoresOcultos() ? '0' : '1');
            aplicarEstado();
        });

        const buscaGeral = destino.querySelector('.financeiro-busca-geral');

        if (buscaGeral) {
            buscaGeral.insertAdjacentElement('afterend', botao);
        } else {
            destino.prepend(botao);
        }
    }

    function podeProcessarNo(no) {
        regexMoeda.lastIndex = 0;

        return no
            && no.parentElement
            && !no.parentElement.closest(seletoresIgnorados)
            && regexMoeda.test(no.nodeValue || '');
    }

    function mascararTexto(no) {
        const texto = no.nodeValue || '';

        regexMoeda.lastIndex = 0;

        if (!regexMoeda.test(texto)) {
            return;
        }

        regexMoeda.lastIndex = 0;
        const fragmento = document.createDocumentFragment();
        let cursor = 0;
        let combinacao;

        while ((combinacao = regexMoeda.exec(texto)) !== null) {
            if (combinacao.index > cursor) {
                fragmento.appendChild(document.createTextNode(texto.slice(cursor, combinacao.index)));
            }

            const span = document.createElement('span');
            span.className = 'financeiro-valor-sensivel';
            span.dataset.mascara = 'R$ •••••';
            span.textContent = combinacao[0];
            fragmento.appendChild(span);
            cursor = combinacao.index + combinacao[0].length;
        }

        if (cursor < texto.length) {
            fragmento.appendChild(document.createTextNode(texto.slice(cursor)));
        }

        no.parentNode.replaceChild(fragmento, no);
    }

    function processarValores(root) {
        const base = root || document.body;

        if (!base || base.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const walker = document.createTreeWalker(base, NodeFilter.SHOW_TEXT, {
            acceptNode: function (no) {
                regexMoeda.lastIndex = 0;
                return podeProcessarNo(no)
                    ? NodeFilter.FILTER_ACCEPT
                    : NodeFilter.FILTER_REJECT;
            }
        });
        const nos = [];

        while (walker.nextNode()) {
            nos.push(walker.currentNode);
        }

        nos.forEach(mascararTexto);
    }

    function iniciarObservador() {
        if (observadorAtivo || !window.MutationObserver) {
            return;
        }

        observador = new MutationObserver(function (mudancas) {
            observador.disconnect();

            mudancas.forEach(function (mudanca) {
                mudanca.addedNodes.forEach(function (no) {
                    if (no.nodeType === Node.TEXT_NODE && podeProcessarNo(no)) {
                        mascararTexto(no);
                    } else if (no.nodeType === Node.ELEMENT_NODE) {
                        processarValores(no);
                    }
                });
            });

            aplicarEstado();
            observador.observe(document.body, { childList: true, subtree: true });
        });

        observador.observe(document.body, { childList: true, subtree: true });
        observadorAtivo = true;
    }

    function iniciarPrivacidadeFinanceira() {
        if (!document.querySelector('.financeiro-cabecalho')) {
            return;
        }

        criarBotaoPrivacidade();
        processarValores(document.body);
        aplicarEstado();
        iniciarObservador();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarPrivacidadeFinanceira);
    } else {
        iniciarPrivacidadeFinanceira();
    }
})();