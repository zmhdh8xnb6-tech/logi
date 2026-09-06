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

    document.querySelectorAll('.btn-documento-veiculo').forEach((botao) => {
        botao.addEventListener('click', () => {
            const formulario = porId('formDocumentoVeiculo');
            const documentoAtual = porId('documentoVeiculoAtual');
            formulario?.reset();
            formulario?.querySelectorAll('.is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
            preencher('documentoVeiculoId', botao.dataset.veiculoId);
            porId('documentoVeiculoNome').textContent = botao.dataset.veiculoNome || 'Veículo';

            if (documentoAtual) {
                const nomeAtual = botao.dataset.documentoNome || '';
                documentoAtual.textContent = nomeAtual ? `Arquivo atual: ${nomeAtual}` : '';
                documentoAtual.classList.toggle('d-none', nomeAtual === '');
            }
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

    const formMultas = porId('formControleMultas');
    const lerPrazosMultas = (linha) => JSON.parse(linha.querySelector('.frota-multas-vencimentos')?.value || '[]');
    const maiorNumeroMulta = (linha) => lerPrazosMultas(linha).reduce((maior, item) => Math.max(maior, item.numero), 0);
    const situacaoPrazo = (data) => {
        if (!data) return { chave: 'sem-data', nome: 'Sem vencimento', cor: 'secondary' };
        if (data < formMultas.dataset.hoje) return { chave: 'vencida', nome: 'Vencida', cor: 'danger' };
        if (data <= formMultas.dataset.limite) return { chave: 'proxima', nome: data === formMultas.dataset.hoje ? 'Vence hoje' : 'Até 30 dias', cor: 'warning' };
        return { chave: 'futura', nome: 'A vencer', cor: 'success' };
    };
    const atualizarResumoPrazos = (linha, total) => {
        const resumo = linha.querySelector('.frota-resumo-vencimentos');
        if (!resumo) return;
        resumo.replaceChildren();
        const datas = lerPrazosMultas(linha).filter((item) => item.numero <= total && item.vencimento).map((item) => item.vencimento).sort();
        const vencidas = datas.filter((data) => data < formMultas.dataset.hoje).length;
        const proximas = datas.filter((data) => data >= formMultas.dataset.hoje && data <= formMultas.dataset.limite).length;
        [[vencidas, vencidas === 1 ? 'vencida' : 'vencidas', 'danger'], [proximas, 'em até 30 dias', 'warning'], [total - datas.length, 'sem vencimento', 'secondary']].forEach(([numero, rotulo, cor]) => {
            if (numero <= 0) return;
            const aviso = document.createElement('span');
            aviso.className = `badge text-bg-${cor}`;
            aviso.textContent = `${numero} ${rotulo}`;
            resumo.appendChild(aviso);
        });
        const proxima = datas.find((data) => data >= formMultas.dataset.hoje);
        if (proxima) {
            const aviso = document.createElement('small');
            aviso.textContent = `Próximo: ${proxima.split('-').reverse().join('/')}`;
            resumo.appendChild(aviso);
        }
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
            atualizarResumoPrazos(linha, 0);
            return;
        }

        const total = Number.parseInt(quantidade.value, 10);
        linha.classList.add('tem-pendencia');
        status.textContent = Number.isFinite(total) && total > 0
            ? `${total} ${total === 1 ? 'multa pendente' : 'multas pendentes'}`
            : 'Informe a quantidade';
        status.classList.add('text-bg-danger');
        status.classList.remove('text-bg-success');
        atualizarResumoPrazos(linha, Number.isFinite(total) ? total : 0);
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
        const validarQuantidade = () => {
            const protegidos = maiorNumeroMulta(linha);
            const invalido = protegidos > Number(quantidade.value);
            quantidade.setCustomValidity(invalido ? 'Remova as multas pela opção Vencimentos antes de reduzir a quantidade.' : '');
            linha.querySelector('.frota-erro-quantidade').textContent = invalido
                ? 'Há multas com dados cadastrados. Remova-as em Vencimentos antes de reduzir.'
                : 'Informe de 1 a 9999 multas.';
            quantidade.classList.toggle('is-invalid', !quantidade.checkValidity());
        };
        possui?.addEventListener('change', () => {
            if (!possui.checked && maiorNumeroMulta(linha) > 0) {
                possui.checked = true;
                quantidade.classList.add('is-invalid');
                linha.querySelector('.frota-erro-quantidade').textContent = 'Remova as multas cadastradas em Vencimentos antes de marcar Não.';
                return;
            }
            const total = Number.parseInt(quantidade.value, 10);
            if (possui.checked && (!Number.isFinite(total) || total < 1)) {
                quantidade.value = '1';
            }
            atualizarLinhaMultas(linha);
            validarQuantidade();
        });
        quantidade?.addEventListener('input', () => {
            validarQuantidade();
            atualizarLinhaMultas(linha);
        });
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

    const modalPrazos = porId('modalVencimentosMultas');
    if (modalPrazos && formMultas) {
        const corpo = porId('itensVencimentosMultas');
        const filtro = porId('filtroVencimentosMultas');
        const erro = porId('erroVencimentosMultas');
        const porPagina = 10;
        let edicao = null;

        const guardarLinha = (linha) => {
            const numero = Number(linha.dataset.numero);
            const referencia = linha.querySelector('.frota-prazo-referencia').value.trim();
            const vencimento = linha.querySelector('.frota-prazo-data').value;
            if (referencia || vencimento) edicao.dados.set(numero, { numero, referencia, vencimento });
            else edicao.dados.delete(numero);
            const status = situacaoPrazo(vencimento);
            const badge = linha.querySelector('.frota-prazo-status');
            badge.className = `badge frota-prazo-status text-bg-${status.cor}`;
            badge.textContent = status.nome;
        };
        const validarPagina = (ignorar = null) => {
            let primeiro = null;
            corpo.querySelectorAll('tr[data-numero]').forEach((linha) => {
                if (linha === ignorar) return;
                linha.querySelectorAll('input').forEach((campo) => {
                    campo.classList.toggle('is-invalid', !campo.checkValidity());
                    if (!campo.checkValidity() && !primeiro) primeiro = campo;
                });
                guardarLinha(linha);
            });
            erro.classList.toggle('d-none', !primeiro);
            erro.textContent = primeiro ? 'Revise os campos destacados antes de continuar.' : '';
            primeiro?.focus();
            return !primeiro;
        };
        const renderizar = () => {
            corpo.replaceChildren();
            const numeros = Array.from({ length: edicao.total }, (_, indice) => indice + 1)
                .filter((numero) => edicao.filtro === 'todos' || situacaoPrazo(edicao.dados.get(numero)?.vencimento).chave === edicao.filtro);
            const paginas = Math.max(1, Math.ceil(numeros.length / porPagina));
            edicao.pagina = Math.min(paginas, Math.max(1, edicao.pagina));
            numeros.slice((edicao.pagina - 1) * porPagina, edicao.pagina * porPagina).forEach((numero) => {
                const linha = porId('modeloVencimentoMulta').content.firstElementChild.cloneNode(true);
                const item = edicao.dados.get(numero);
                linha.dataset.numero = String(numero);
                linha.querySelector('.frota-prazo-numero').textContent = `#${numero}`;
                const referencia = linha.querySelector('.frota-prazo-referencia');
                const data = linha.querySelector('.frota-prazo-data');
                referencia.value = item?.referencia || '';
                data.value = item?.vencimento || '';
                referencia.setAttribute('aria-label', `Identificação da multa ${numero}`);
                data.setAttribute('aria-label', `Vencimento da multa ${numero}`);
                linha.querySelectorAll('input').forEach((campo) => campo.addEventListener('input', () => {
                    campo.classList.remove('is-invalid');
                    guardarLinha(linha);
                }));
                const remover = linha.querySelector('.frota-remover-prazo');
                remover.setAttribute('aria-label', `Remover multa ${numero}`);
                remover.title = `Remover multa ${numero}`;
                remover.addEventListener('click', () => {
                    if (!validarPagina(linha)) return;
                    const novos = new Map();
                    edicao.dados.forEach((valor, chave) => {
                        if (chave === numero) return;
                        const posicao = chave > numero ? chave - 1 : chave;
                        novos.set(posicao, { ...valor, numero: posicao });
                    });
                    edicao.dados = novos;
                    edicao.total -= 1;
                    renderizar();
                });
                guardarLinha(linha);
                corpo.appendChild(linha);
            });
            if (numeros.length === 0) {
                const linha = corpo.insertRow();
                const celula = linha.insertCell();
                celula.colSpan = 5;
                celula.className = 'text-muted text-center py-4';
                celula.textContent = 'Nenhuma multa neste filtro.';
            }
            porId('paginaVencimentosMultas').textContent = `${numeros.length} de ${edicao.total} multas · Página ${edicao.pagina} de ${paginas}`;
            porId('paginaAnteriorMultas').disabled = edicao.pagina <= 1;
            porId('paginaSeguinteMultas').disabled = edicao.pagina >= paginas;
            porId('adicionarVencimentoMulta').disabled = edicao.total >= 9999;
        };
        modalPrazos.addEventListener('show.bs.modal', (evento) => {
            const linha = evento.relatedTarget?.closest('.frota-linha-controle');
            if (!linha) { evento.preventDefault(); return; }
            const dados = lerPrazosMultas(linha);
            const total = linha.querySelector('.frota-switch-multas').checked ? Number(linha.querySelector('.frota-quantidade-multas').value) : 0;
            edicao = {
                linha, total: Math.max(maiorNumeroMulta(linha), Math.min(9999, Math.max(0, Math.floor(total) || 0))),
                dados: new Map(dados.map((item) => [item.numero, { ...item }])), pagina: 1, filtro: 'todos',
            };
            porId('veiculoVencimentosMultas').textContent = linha.dataset.veiculoNome;
            filtro.value = 'todos';
            erro.classList.add('d-none');
            renderizar();
        });
        filtro.addEventListener('change', () => {
            if (!validarPagina()) { filtro.value = edicao.filtro; return; }
            edicao.filtro = filtro.value;
            edicao.pagina = 1;
            renderizar();
        });
        [['paginaAnteriorMultas', -1], ['paginaSeguinteMultas', 1]].forEach(([id, deslocamento]) => {
            porId(id).addEventListener('click', () => {
                if (!validarPagina()) return;
                edicao.pagina += deslocamento;
                renderizar();
            });
        });
        porId('adicionarVencimentoMulta').addEventListener('click', () => {
            if (!validarPagina() || edicao.total >= 9999) return;
            edicao.total += 1;
            edicao.filtro = filtro.value = 'todos';
            edicao.pagina = Math.ceil(edicao.total / porPagina);
            renderizar();
            corpo.querySelector('tr:last-child input')?.focus();
        });
        porId('salvarVencimentosMultas').addEventListener('click', () => {
            if (!validarPagina()) return;
            const linha = edicao.linha;
            linha.querySelector('.frota-multas-vencimentos').value = JSON.stringify(Array.from(edicao.dados.values()).sort((a, b) => a.numero - b.numero));
            linha.querySelector('.frota-switch-multas').checked = edicao.total > 0;
            const quantidade = linha.querySelector('.frota-quantidade-multas');
            quantidade.value = String(edicao.total);
            quantidade.setCustomValidity('');
            quantidade.classList.remove('is-invalid');
            atualizarLinhaMultas(linha);
            modalPrazos.addEventListener('hidden.bs.modal', () => formMultas.requestSubmit(), { once: true });
            bootstrap.Modal.getInstance(modalPrazos).hide();
        });
        modalPrazos.addEventListener('hidden.bs.modal', () => { edicao = null; corpo.replaceChildren(); });
    }

    document.querySelectorAll('.btn-excluir-registro').forEach((botao) => {
        botao.addEventListener('click', () => {
            preencher('excluirFrotaAcao', botao.dataset.acao);
            preencher('excluirFrotaAba', botao.dataset.aba);
            preencher('excluirFrotaId', botao.dataset.id);
            porId('excluirFrotaNome').textContent = botao.dataset.nome || 'este registro';
            const avisosExclusao = {
                excluir_veiculo: 'O acompanhamento anual e os documentos vinculados a este veículo também serão excluídos.',
                excluir_documento_veiculo: 'Somente o arquivo deste ano será excluído. O veículo voltará a aparecer com documento pendente.',
            };
            porId('excluirFrotaAviso').textContent = avisosExclusao[botao.dataset.acao]
                || 'Esta ação não poderá ser desfeita.';
        });
    });

    document.querySelectorAll('[title]').forEach((elemento) => {
        if (window.bootstrap && elemento.matches('button, a')) {
            const tooltip = bootstrap.Tooltip.getOrCreateInstance(elemento, {
                trigger: 'hover',
                container: 'body',
            });
            elemento.addEventListener('click', () => tooltip.hide());
            elemento.addEventListener('mouseleave', () => tooltip.hide());
            elemento.addEventListener('blur', () => tooltip.hide());
        }
    });

    const alerta = document.querySelector('.alerta-temporario');
    if (alerta) {
        window.setTimeout(() => alerta.remove(), 6000);
    }
})();