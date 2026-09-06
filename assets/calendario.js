(function () {
    function dispararAlteracao(campo) {
        campo.dispatchEvent(new Event('input', { bubbles: true }));
        campo.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function descritorPropriedade(elemento, propriedade) {
        let prototipo = Object.getPrototypeOf(elemento);

        while (prototipo) {
            const descritor = Object.getOwnPropertyDescriptor(prototipo, propriedade);

            if (descritor) {
                return descritor;
            }

            prototipo = Object.getPrototypeOf(prototipo);
        }

        return null;
    }

    function instalarSincronia(campo, instancia) {
        const descritorDisabled = descritorPropriedade(campo, 'disabled');
        const descritorRequired = descritorPropriedade(campo, 'required');
        const descritorValue = descritorPropriedade(campo, 'value');
        const focoOriginal = campo.focus.bind(campo);
        let atualizandoValor = false;

        function sincronizar() {
            if (!instancia.altInput) {
                return;
            }

            const botao = campo._botaoCalendario || null;
            const wrapper = campo._calendarioWrapper || null;

            instancia.altInput.disabled = campo.disabled;
            instancia.altInput.required = campo.required;
            instancia.altInput.classList.toggle('is-invalid', campo.classList.contains('is-invalid'));
            instancia.altInput.classList.toggle('is-valid', campo.classList.contains('is-valid'));

            if (botao) {
                botao.disabled = campo.disabled;
            }

            if (wrapper) {
                wrapper.classList.toggle('calendario-campo-disabled', campo.disabled);
                wrapper.classList.toggle('calendario-campo-invalid', campo.classList.contains('is-invalid'));
            }
        }

        if (descritorDisabled && descritorRequired && descritorValue && campo.dataset.calendarioSincronizado !== '1') {
            Object.defineProperty(campo, 'disabled', {
                configurable: true,
                get: function () {
                    return descritorDisabled.get.call(this);
                },
                set: function (valor) {
                    descritorDisabled.set.call(this, valor);
                    sincronizar();
                }
            });

            Object.defineProperty(campo, 'required', {
                configurable: true,
                get: function () {
                    return descritorRequired.get.call(this);
                },
                set: function (valor) {
                    descritorRequired.set.call(this, valor);
                    sincronizar();
                }
            });

            Object.defineProperty(campo, 'value', {
                configurable: true,
                get: function () {
                    return descritorValue.get.call(this);
                },
                set: function (valor) {
                    descritorValue.set.call(this, valor);

                    if (!atualizandoValor) {
                        atualizandoValor = true;

                        try {
                            if (valor) {
                                instancia.setDate(valor, false, instancia.config.dateFormat);
                            } else {
                                instancia.clear(false);
                            }
                        } finally {
                            atualizandoValor = false;
                        }
                    }

                    sincronizar();
                }
            });

            campo.dataset.calendarioSincronizado = '1';
        }

        campo.focus = function () {
            sincronizar();

            if (instancia.altInput && !campo.disabled) {
                instancia.altInput.focus();
                return;
            }

            focoOriginal();
        };
        campo._sincronizarCalendario = sincronizar;
        campo._focarCalendario = campo.focus;

        new MutationObserver(sincronizar).observe(campo, {
            attributes: true,
            attributeFilter: ['class', 'disabled', 'required']
        });

        sincronizar();
    }

    function instalarBotaoCalendario(campo, instancia) {
        if (!instancia.altInput || campo.dataset.calendarioBotao === '1') {
            return;
        }

        const input = instancia.altInput;
        const wrapper = document.createElement('div');
        wrapper.className = 'calendario-campo';

        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'calendario-botao';
        botao.setAttribute('aria-label', 'Abrir calendário');
        botao.title = 'Abrir calendário';
        botao.innerHTML = '<i class="bi bi-calendar3"></i>';

        botao.addEventListener('pointerdown', function (evento) {
            evento.preventDefault();
        });

        botao.addEventListener('click', function (evento) {
            evento.preventDefault();
            evento.stopPropagation();

            if (campo.disabled) {
                return;
            }

            instancia.open();

            if (instancia.altInput) {
                instancia.altInput.focus({ preventScroll: true });
            }
        });

        wrapper.appendChild(botao);
        campo._botaoCalendario = botao;
        campo._calendarioWrapper = wrapper;
        campo.dataset.calendarioBotao = '1';
    }

    function aplicarMascaraData(input) {
        if (!input || input.dataset.mascaraDataAplicada === '1') {
            return;
        }

        input.dataset.mascaraDataAplicada = '1';
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '10');

        input.addEventListener('input', function () {
            const selecaoNoFim = input.selectionStart === input.value.length;
            let valor = input.value.replace(/\D/g, '').slice(0, 8);

            if (valor.length > 4) {
                valor = valor.replace(/^(\d{2})(\d{2})(\d{1,4}).*/, '$1/$2/$3');
            } else if (valor.length > 2) {
                valor = valor.replace(/^(\d{2})(\d{1,2}).*/, '$1/$2');
            }

            input.value = valor;

            if (selecaoNoFim) {
                input.setSelectionRange(input.value.length, input.value.length);
            }
        });
    }

    function iniciarCalendarios(contexto) {
        if (!window.flatpickr) {
            return;
        }

        if (window.flatpickr.l10ns && window.flatpickr.l10ns.pt) {
            window.flatpickr.localize(window.flatpickr.l10ns.pt);
        }

        const raiz = contexto || document;
        const seletor = [
            'input[type="date"]:not([data-calendario-nativo]):not([data-no-flatpickr])',
            'input[type="month"]:not([data-calendario-nativo]):not([data-no-flatpickr])'
        ].join(',');

        const campos = [];
        if (raiz.matches && raiz.matches(seletor)) {
            campos.push(raiz);
        }
        if (raiz.querySelectorAll) {
            raiz.querySelectorAll(seletor).forEach(function (campo) {
                campos.push(campo);
            });
        }

        campos.forEach(function (campo) {
            if (campo._flatpickr || campo.dataset.calendarioAplicado === '1') {
                return;
            }

            const tipoOriginal = campo.type;
            const config = {
                altInput: true,
                allowInput: true,
                disableMobile: true,
                clickOpens: false,
                locale: 'pt',
                onChange: function () {
                    dispararAlteracao(campo);
                }
            };

            if (tipoOriginal === 'month') {
                if (!window.monthSelectPlugin) {
                    return;
                }

                config.altFormat = 'F/Y';
                config.dateFormat = 'Y-m';
                config.plugins = [
                    new window.monthSelectPlugin({
                        shorthand: false,
                        dateFormat: 'Y-m',
                        altFormat: 'F/Y'
                    })
                ];
            } else {
                config.altFormat = 'd/m/Y';
                config.dateFormat = 'Y-m-d';
            }

            campo.dataset.calendarioAplicado = '1';
            const instancia = window.flatpickr(campo, config);
            if (tipoOriginal === 'date') {
                aplicarMascaraData(instancia.altInput);
            }
            instalarBotaoCalendario(campo, instancia);
            instalarSincronia(campo, instancia);
        });
    }

    window.inicializarCalendarios = iniciarCalendarios;

    window.sincronizarCalendarioCampo = function (campoOuId) {
        const campo = typeof campoOuId === 'string'
            ? document.getElementById(campoOuId)
            : campoOuId;

        if (!campo) {
            return;
        }

        if (!campo._flatpickr && window.inicializarCalendarios) {
            window.inicializarCalendarios(document);
        }

        if (campo._sincronizarCalendario) {
            campo._sincronizarCalendario();
        }
    };

    window.focarCalendarioCampo = function (campoOuId) {
        const campo = typeof campoOuId === 'string'
            ? document.getElementById(campoOuId)
            : campoOuId;

        if (!campo) {
            return;
        }

        window.sincronizarCalendarioCampo(campo);

        setTimeout(function () {
            if (campo._focarCalendario) {
                campo._focarCalendario();
                return;
            }

            campo.focus();
        }, 0);
    };

    window.definirDataCalendario = function (campoOuId, valor) {
        const campo = typeof campoOuId === 'string'
            ? document.getElementById(campoOuId)
            : campoOuId;

        if (!campo) {
            return;
        }

        if (campo._flatpickr) {
            campo._flatpickr.setDate(valor || null, false);
            return;
        }

        campo.value = valor || '';
    };

    document.addEventListener('DOMContentLoaded', function () {
        iniciarCalendarios(document);

        if (!document.body || !window.MutationObserver) {
            return;
        }

        new MutationObserver(function (mutacoes) {
            mutacoes.forEach(function (mutacao) {
                mutacao.addedNodes.forEach(function (no) {
                    if (no.nodeType === Node.ELEMENT_NODE) {
                        iniciarCalendarios(no);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();