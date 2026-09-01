(() => {
    'use strict';

    const porId = (id) => document.getElementById(id);
    const texto = (valor) => valor === null || valor === undefined ? '' : String(valor);

    const lerRegistro = (botao) => {
        try {
            return JSON.parse(botao.dataset.registro || '{}');
        } catch (erro) {
            return {};
        }
    };

    const preencher = (id, valor) => {
        const campo = porId(id);
        if (campo) {
            campo.value = texto(valor);
        }
    };

    const moedaBrasileira = (valor) => {
        const numero = Number.parseFloat(texto(valor).replace(',', '.'));
        return Number.isFinite(numero)
            ? numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '0,00';
    };

    const atualizarCampoPagamento = (select) => {
        const alvo = document.querySelector(select.dataset.alvo || '');
        if (!alvo) return;

        const pago = select.value === 'pago' || select.value === 'paga';
        alvo.classList.toggle('d-none', !pago);
        const campoData = alvo.querySelector('input[type="date"]');
        if (campoData) {
            campoData.required = pago;
            if (!pago) {
                campoData.value = '';
                campoData.classList.remove('is-invalid');
            }
        }
    };

    const prepararModal = (modalId, formId, tituloId, tituloNovo, evento) => {
        const modal = porId(modalId);
        const form = porId(formId);
        if (!modal || !form) return;

        modal.addEventListener('show.bs.modal', (e) => {
            if (e.relatedTarget && e.relatedTarget.dataset.registro) return;
            form.reset();
            form.querySelectorAll('input[name="id"]').forEach((campo) => { campo.value = ''; });
            form.querySelectorAll('.is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
            const titulo = porId(tituloId);
            if (titulo) titulo.textContent = tituloNovo;
            form.querySelectorAll('.campo-situacao-pagamento').forEach(atualizarCampoPagamento);
            if (typeof evento === 'function') evento(form);
        });
    };

    prepararModal('modalVeiculo', 'formVeiculo', 'modalVeiculoTitulo', 'Novo veículo', (form) => {
        const situacao = form.querySelector('#veiculoSituacao');
        if (situacao) situacao.value = 'ativo';
    });

    prepararModal('modalObrigacao', 'formObrigacao', 'modalObrigacaoTitulo', 'Nova obrigação', (form) => {
        preencher('obrigacaoValor', '0,00');
        const situacao = form.querySelector('#obrigacaoSituacao');
        if (situacao) situacao.value = 'pendente';
    });

    prepararModal('modalMulta', 'formMulta', 'modalMultaTitulo', 'Nova multa', (form) => {
        preencher('multaValor', '0,00');
        preencher('multaPontos', '0');
        const situacao = form.querySelector('#multaSituacao');
        if (situacao) situacao.value = 'pendente';
    });

    document.querySelectorAll('.btn-editar-veiculo').forEach((botao) => {
        botao.addEventListener('click', () => {
            const item = lerRegistro(botao);
            porId('formVeiculo').querySelectorAll('.is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
            porId('modalVeiculoTitulo').textContent = 'Editar veículo';
            preencher('veiculoId', item.id);
            preencher('veiculoPlaca', item.placa);
            preencher('veiculoRenavam', item.renavam);
            preencher('veiculoSituacao', item.situacao || 'ativo');
            preencher('veiculoMarca', item.marca);
            preencher('veiculoModelo', item.modelo);
            preencher('veiculoAnoFabricacao', item.ano_fabricacao);
            preencher('veiculoAnoModelo', item.ano_modelo);
            preencher('veiculoCor', item.cor);
            preencher('veiculoResponsavel', item.responsavel);
            preencher('veiculoObservacoes', item.observacoes);
        });
    });

    document.querySelectorAll('.btn-editar-obrigacao').forEach((botao) => {
        botao.addEventListener('click', () => {
            const item = lerRegistro(botao);
            porId('formObrigacao').querySelectorAll('.is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
            porId('modalObrigacaoTitulo').textContent = 'Editar obrigação';
            preencher('obrigacaoId', item.id);
            preencher('obrigacaoVeiculo', item.veiculo_id);
            preencher('obrigacaoTipo', item.tipo);
            preencher('obrigacaoTitulo', item.titulo);
            preencher('obrigacaoCompetencia', item.competencia);
            preencher('obrigacaoVencimento', item.vencimento);
            preencher('obrigacaoValor', moedaBrasileira(item.valor));
            preencher('obrigacaoSituacao', item.situacao || 'pendente');
            preencher('obrigacaoPagoEm', item.pago_em);
            preencher('obrigacaoReferencia', item.referencia);
            preencher('obrigacaoObservacoes', item.observacoes);
            atualizarCampoPagamento(porId('obrigacaoSituacao'));
            preencher('obrigacaoPagoEm', item.pago_em);
        });
    });

    document.querySelectorAll('.btn-editar-multa').forEach((botao) => {
        botao.addEventListener('click', () => {
            const item = lerRegistro(botao);
            porId('formMulta').querySelectorAll('.is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
            porId('modalMultaTitulo').textContent = 'Editar multa';
            preencher('multaId', item.id);
            preencher('multaVeiculo', item.veiculo_id);
            preencher('multaAuto', item.auto_infracao);
            preencher('multaDescricao', item.descricao);
            preencher('multaData', item.data_infracao);
            preencher('multaMotorista', item.motorista);
            preencher('multaVencimento', item.vencimento);
            preencher('multaPontos', item.pontos || 0);
            preencher('multaValor', moedaBrasileira(item.valor));
            preencher('multaSituacao', item.situacao || 'pendente');
            preencher('multaPagoEm', item.pago_em);
            preencher('multaObservacoes', item.observacoes);
            atualizarCampoPagamento(porId('multaSituacao'));
            preencher('multaPagoEm', item.pago_em);
        });
    });

    document.querySelectorAll('.campo-situacao-pagamento').forEach((select) => {
        select.addEventListener('change', () => atualizarCampoPagamento(select));
        atualizarCampoPagamento(select);
    });

    const tipoObrigacao = porId('obrigacaoTipo');
    const tituloObrigacao = porId('obrigacaoTitulo');
    if (tipoObrigacao && tituloObrigacao) {
        const rotulos = {
            ipva: 'IPVA',
            licenciamento: 'Licenciamento / CRLV',
            seguro: 'Seguro',
            revisao: 'Revisão',
            troca_oleo: 'Troca de óleo',
            pneus: 'Pneus',
            outro: ''
        };
        tipoObrigacao.addEventListener('change', () => {
            if (tituloObrigacao.value.trim() === '' || Object.values(rotulos).includes(tituloObrigacao.value)) {
                tituloObrigacao.value = rotulos[tipoObrigacao.value] || '';
            }
        });
    }

    const placa = porId('veiculoPlaca');
    if (placa) {
        placa.addEventListener('input', () => {
            placa.value = placa.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 8);
        });
        placa.addEventListener('blur', () => {
            const limpa = placa.value.replace(/[^A-Z0-9]/g, '');
            placa.value = limpa.length === 7 ? `${limpa.slice(0, 3)}-${limpa.slice(3)}` : limpa;
        });
    }

    const renavam = porId('veiculoRenavam');
    if (renavam) {
        renavam.addEventListener('input', () => {
            renavam.value = renavam.value.replace(/\D/g, '').slice(0, 11);
        });
    }

    document.querySelectorAll('.campo-moeda').forEach((campo) => {
        campo.addEventListener('blur', () => {
            let valor = campo.value.replace(/[^\d,.-]/g, '');
            if (valor.includes(',') && valor.includes('.')) {
                valor = valor.replace(/\./g, '').replace(',', '.');
            } else {
                valor = valor.replace(',', '.');
            }
            campo.value = moedaBrasileira(valor);
        });
    });

    document.querySelectorAll('.frota-form').forEach((formulario) => {
        formulario.addEventListener('submit', (evento) => {
            let primeiroInvalido = null;

            formulario.querySelectorAll('[required]').forEach((campo) => {
                const valorVazio = typeof campo.value === 'string' && campo.value.trim() === '';
                const invalido = valorVazio || !campo.checkValidity();
                campo.classList.toggle('is-invalid', invalido);

                if (invalido && !primeiroInvalido) {
                    primeiroInvalido = campo;
                }
            });

            if (primeiroInvalido) {
                evento.preventDefault();
                primeiroInvalido.focus();
            }
        });

        formulario.querySelectorAll('input, select, textarea').forEach((campo) => {
            const limparErro = () => campo.classList.remove('is-invalid');
            campo.addEventListener('input', limparErro);
            campo.addEventListener('change', limparErro);
        });
    });

    document.querySelectorAll('.btn-excluir-registro').forEach((botao) => {
        botao.addEventListener('click', () => {
            preencher('excluirFrotaAcao', botao.dataset.acao);
            preencher('excluirFrotaAba', botao.dataset.aba);
            preencher('excluirFrotaId', botao.dataset.id);
            porId('excluirFrotaNome').textContent = botao.dataset.nome || 'este registro';
            porId('excluirFrotaAviso').textContent = botao.dataset.acao === 'excluir_veiculo'
                ? 'As obrigações e multas vinculadas a este veículo também serão excluídas.'
                : 'Esta ação não poderá ser desfeita.';
        });
    });

    document.querySelectorAll('[title]').forEach((elemento) => {
        if (window.bootstrap && elemento.matches('button, a')) {
            new bootstrap.Tooltip(elemento);
        }
    });

    const alerta = document.querySelector('.alerta-temporario');
    if (alerta) {
        window.setTimeout(() => alerta.remove(), 6000);
    }
})();