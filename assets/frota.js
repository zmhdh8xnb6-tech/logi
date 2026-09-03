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
            if (typeof evento === 'function') evento(form);
        });
    };

    prepararModal('modalVeiculo', 'formVeiculo', 'modalVeiculoTitulo', 'Novo veículo', (form) => {
        const situacao = form.querySelector('#veiculoSituacao');
        if (situacao) situacao.value = 'ativo';
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

    const atualizarLinhaObrigacoes = (linha) => {
        const documento = linha.querySelector('.frota-switch-documento');
        const boletos = linha.querySelector('.frota-switch-boletos');
        const status = linha.querySelector('.frota-status-controle');
        if (!documento || !boletos || !status) return;

        const pendente = !documento.checked || !boletos.checked;
        linha.classList.toggle('tem-pendencia', pendente);
        documento.nextElementSibling.textContent = documento.checked ? 'Sim, concluído' : 'Não, pendente';
        boletos.nextElementSibling.textContent = boletos.checked ? 'Sim, enviados' : 'Não, pendente';
        status.textContent = pendente ? 'Com pendência' : 'Em dia';
        status.classList.toggle('text-bg-danger', pendente);
        status.classList.toggle('text-bg-success', !pendente);
    };

    const atualizarLinhaMultas = (linha) => {
        const possui = linha.querySelector('.frota-switch-multas');
        const quantidade = linha.querySelector('.frota-quantidade-multas');
        const status = linha.querySelector('.frota-status-controle');
        if (!possui || !quantidade || !status) return;

        quantidade.disabled = !possui.checked;
        quantidade.required = possui.checked;
        possui.nextElementSibling.textContent = possui.checked ? 'Sim' : 'Não';

        if (!possui.checked) {
            quantidade.value = '0';
            quantidade.classList.remove('is-invalid');
            linha.classList.remove('tem-pendencia');
            status.textContent = 'Sem multas';
            status.classList.remove('text-bg-danger');
            status.classList.add('text-bg-success');
            return;
        }

        const total = Number.parseInt(quantidade.value, 10);
        linha.classList.add('tem-pendencia');
        status.textContent = Number.isFinite(total) && total > 0
            ? `${total} ${total === 1 ? 'multa pendente' : 'multas pendentes'}`
            : 'Informe a quantidade';
        status.classList.add('text-bg-danger');
        status.classList.remove('text-bg-success');
    };

    document.querySelectorAll('.frota-linha-controle[data-tipo-controle="obrigacoes"]').forEach((linha) => {
        linha.querySelectorAll('.form-check-input').forEach((campo) => {
            campo.addEventListener('change', () => atualizarLinhaObrigacoes(linha));
        });
        atualizarLinhaObrigacoes(linha);
    });

    document.querySelectorAll('.frota-linha-controle[data-tipo-controle="multas"]').forEach((linha) => {
        const possui = linha.querySelector('.frota-switch-multas');
        const quantidade = linha.querySelector('.frota-quantidade-multas');
        possui?.addEventListener('change', () => {
            const total = Number.parseInt(quantidade.value, 10);
            if (possui.checked && (!Number.isFinite(total) || total < 1)) {
                quantidade.value = '1';
            }
            atualizarLinhaMultas(linha);
        });
        quantidade?.addEventListener('input', () => atualizarLinhaMultas(linha));
        atualizarLinhaMultas(linha);
    });

    document.querySelectorAll('.frota-form-multas').forEach((formulario) => {
        formulario.addEventListener('submit', (evento) => {
            const invalido = Array.from(formulario.querySelectorAll('.frota-quantidade-multas:not(:disabled)'))
                .find((campo) => !campo.checkValidity() || Number.parseInt(campo.value, 10) < 1);
            if (invalido) {
                evento.preventDefault();
                invalido.classList.add('is-invalid');
                invalido.focus();
            }
        });
    });

    document.querySelectorAll('.btn-excluir-registro').forEach((botao) => {
        botao.addEventListener('click', () => {
            preencher('excluirFrotaAcao', botao.dataset.acao);
            preencher('excluirFrotaAba', botao.dataset.aba);
            preencher('excluirFrotaId', botao.dataset.id);
            porId('excluirFrotaNome').textContent = botao.dataset.nome || 'este registro';
            porId('excluirFrotaAviso').textContent = botao.dataset.acao === 'excluir_veiculo'
                ? 'O acompanhamento anual vinculado a este veículo também será excluído.'
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