let paginaAtual = 1;
let limitePorPagina = 15;
let documentoDuplicado = false;
let ultimaConsultaDocumento = '';
let ultimoCnpjConsultado = '';
let dadosCnpjEncontrado = null;
let requisicaoClientes = null;
let timerBuscaClientes = null;
let sequenciaRequisicaoClientes = 0;
let clienteParaExcluir = null;
let clienteUfParaExcluir = '';
let clienteExigeContadorRetirado = true;
let clienteExigeSefazRevogada = false;

$(document).ready(function () {
    const limiteClientesSalvo = Number(localStorage.getItem('limiteClientes') || 15);
    limitePorPagina = [15, 30, 60, 90].includes(limiteClientesSalvo) ? limiteClientesSalvo : 15;
    $('#limiteClientes').val(String(limitePorPagina));

    aplicarMascaras();
    carregarClientes();

    $('#limiteClientes').on('change', function () {
        const novoLimite = Number(this.value);
        limitePorPagina = [15, 30, 60, 90].includes(novoLimite) ? novoLimite : 15;
        localStorage.setItem('limiteClientes', String(limitePorPagina));
        carregarClientes(1);
    });

    $('#cep').on('blur', function () {
        let cep = $(this).val().replace(/\D/g, '');

        if (cep.length === 0) return;
        if (cep.length !== 8) return;

        $('#cepFeedback')
            .html('<span class="spinner-border spinner-border-sm me-1"></span> Buscando CEP...')
            .removeClass('text-danger')
            .addClass('text-muted');

        $.getJSON(`https://viacep.com.br/ws/${cep}/json/`, function (dados) {

            $('#cepFeedback')
                .html('')
                .removeClass('text-muted text-danger');

            if (!dados.erro) {
                $('#endereco').val(dados.logradouro || '');
                $('#bairro').val(dados.bairro || '');
                $('#cidade').val(dados.localidade || '');
                $('#uf').val((dados.uf || '').toUpperCase());
            } else {
                mostrarAviso('CEP não encontrado.', '#cep');
            }

        }).fail(function () {

            $('#cepFeedback')
                .html('')
                .removeClass('text-muted text-danger');

            mostrarAviso('Erro ao consultar CEP.', '#cep');
        });
    });

    $('#documento').on('blur', function () {
        const documento = $(this).val().replace(/\D/g, '');
        const documentoFormatado = $(this).val().trim();

        if (documento.length === 0) {
            documentoDuplicado = false;
            return;
        }

        if (!validarCpfOuCnpj(documento)) {
            $('#documento').addClass('is-invalid');
            mostrarAviso('CPF ou CNPJ inválido.', '#documento');
            documentoDuplicado = false;
            return;
        }

        verificarDocumentoDuplicado(documentoFormatado).done(function (resposta) {
            if (!resposta.duplicado && documento.length === 14) {
                consultarCnpjParaPreenchimento(documento);
            }
        });
    });

    $('#clienteForm').on('submit', function (e) {
        e.preventDefault();

        if (!validarFormulario()) {
            return;
        }

        let id = $('#id').val();
        let action = id === '' ? 'create' : 'update';

        // 🔥 LOADING NO BOTÃO
        $('#btnSalvarCliente')
            .prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span> Salvando...');

        $.ajax({
            url: 'api.php?action=' + action,
            method: 'POST',
            data: $(this).serialize(),

            success: function (resp) {

                const resposta = resp.trim();
                const respostaPartes = resposta.split('|');
                const cadastroSalvo = respostaPartes[0] === 'ok';
                const clienteIdSalvo = respostaPartes[1] || $('#id').val();
                const possuiParcelamento = $('#possui_parcelamento').val() === 'possui';
                const servicoParcelamento = $('#servico_parcelamento').is(':checked');
                const clienteContabil = $('#cliente_contabil').val() === '1';

                if (cadastroSalvo) {
                    sessionStorage.setItem('logi_forcar_atualizar_pendencias', '1');
                    window.dispatchEvent(new Event('pendencias:atualizar'));

                    $('#btnSalvarCliente')
                        .prop('disabled', false)
                        .html('Salvar Alterações');

                    if (window.location.pathname.includes('cliente_editar.php')) {

                        window.location.href =
                            'cliente.php?id=' + $('#id').val();

                        return;
                    }

                    $('#clienteForm')[0].reset();
                    $('#id').val('');

                    if (window.location.pathname.includes('cliente_novo.php')) {

                        if (!clienteContabil && servicoParcelamento) {
                            window.location.href =
                                'parcelamento_novo.php?cliente_id=' + encodeURIComponent(clienteIdSalvo);
                            return;
                        }

                        if (clienteContabil && possuiParcelamento) {
                            const botaoParcelamento = document.getElementById('btnCadastrarParcelamentoAgora');
                            botaoParcelamento.href =
                                'parcelamento_novo.php?cliente_id=' + encodeURIComponent(clienteIdSalvo);

                            const modalParcelamento = new bootstrap.Modal(
                                document.getElementById('modalCadastrarParcelamento'),
                                { backdrop: 'static', keyboard: false }
                            );
                            modalParcelamento.show();
                            return;
                        }

                        window.location.href = clienteContabil ? 'clientes.php' : 'servicos_avulsos.php';

                        return;
                    }

                    carregarClientes(paginaAtual);

                } else if (resp.trim() === 'duplicado') {

                    mostrarAviso(
                        'Já existe um cliente cadastrado com este CPF/CNPJ.',
                        '#documento'
                    );

                    $('#btnSalvarCliente')
                        .prop('disabled', false)
                        .html('Salvar');

                } else if (resp.trim() === 'inscricao_estadual_invalida') {

                    $('#inscricao_estadual').addClass('is-invalid').focus();
                    mostrarAviso('Inscrição Estadual inválida para a UF informada.');

                } else if (resp.trim() === 'vencimento_procuracao_obrigatorio') {

                    mostrarAviso('Informe o vencimento das procurações marcadas como Possui.');

                } else if (resp.trim() === 'procuracoes_incompletas') {

                    mostrarAviso('Preencha a situação de todas as procurações.');

                } else if (resp.trim() === 'alvaras_incompletos') {

                    mostrarAviso('Preencha todos os órgãos do alvará com vencimento, como dispensado ou em estudo.');

                } else if (resp.trim() === 'alvaras_goias_incompletos') {

                    mostrarAviso('Confira os alvarás Goiás. Quando marcar “Com vencimento”, informe a data.');

                } else if (resp.trim() === 'servico_avulso_obrigatorio') {

                    $('#servicosAvulsosFeedback').removeClass('d-none').show();
                    mostrarAviso('Selecione pelo menos um serviço para o cadastro avulso.');

                } else if (resp.trim() === 'cliente_contabil_obrigatorio') {

                    $('#cliente_contabil').addClass('is-invalid').focus();
                    mostrarAviso('Informe se a empresa é cliente contábil.');

                } else if (resp.trim() === 'alvara_obrigatorio') {

                    $('#alvara').addClass('is-invalid').focus();
                    mostrarAviso('Informe a situação do alvará.');

                } else if (resp.trim() === 'parcelamento_obrigatorio') {

                    $('#possui_parcelamento').addClass('is-invalid').focus();
                    mostrarAviso('Informe se o cliente possui parcelamento.');

                } else {

                    mostrarAviso(resp);

                    $('#btnSalvarCliente')
                        .prop('disabled', false)
                        .html('Salvar');

                }
            },

            error: function (xhr) {
                mostrarAviso('Erro: ' + xhr.responseText);
            },

            complete: function () {
                // 🔥 VOLTA BOTÃO AO NORMAL
                $('#btnSalvarCliente')
                    .prop('disabled', false)
                    .html('Salvar');
            }
        });
    });

    $('#uf').on('input', function () {
        this.value = this.value.replace(/[^a-zA-Z]/g, '').toUpperCase().slice(0, 2);
    });

    $('#codigo').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });

    $('#buscaCliente').on('input', function () {
        clearTimeout(timerBuscaClientes);
        timerBuscaClientes = setTimeout(function () {
            carregarClientes(1);
        }, 250);
    });

    $('#filtroUf').on('change', function () {
        carregarClientes(1);
    });

    $('#cliente_contabil, #possui_parcelamento, #servico_parcelamento, #servico_certificado').on('change', atualizarVinculoServicos);
    atualizarVinculoServicos();

    $(document).on('input', '#nome_fantasia', function () {
        this.value = this.value.toUpperCase();
    });

    $('#btnPreencherDadosCnpj').on('click', function () {
        preencherCadastroComCnpj();
    });

    $('#btnAtualizarQsaReceita').on('click', function () {
        atualizarQsaPelaReceita();
    });

    $('#documento').on('input', function () {
        documentoDuplicado = false;
        ultimaConsultaDocumento = '';
    });

    $('#inscricao_estadual').on('input', function () {
        const valor = this.value.toUpperCase();
        this.value = valor === 'ISENTO'
            ? valor
            : valor.replace(/[^0-9.\/-]/g, '');
        this.classList.remove('is-invalid');
    });

    $('#inscricao_estadual').on('blur', function () {
        validarCampoInscricaoEstadual();
    });

    $('#uf').on('change blur', function () {
        if ($('#inscricao_estadual').val().trim() !== '') {
            validarCampoInscricaoEstadual();
        }
    });

    function atualizarBotoesAlvaras() {
        const valorAlvara = $('#alvara').val();
        $('#btnEditarAlvaras').toggleClass('d-none', valorAlvara !== 'possui');
        $('#btnEditarAlvarasGoias').toggleClass('d-none', valorAlvara !== 'goias');
    }

    function abrirModalAlvarasGoias() {
        const modalGoias = document.getElementById('modalAlvarasGoiasCliente');

        if (modalGoias) {
            bootstrap.Modal.getOrCreateInstance(modalGoias).show();
        }
    }

    $('#alvara').on('change', function () {
        atualizarBotoesAlvaras();

        if (this.value === 'possui') {
            document.querySelectorAll('.alvara-situacao').forEach(function (campo) {
                campo.required = true;
            });

            const modal = new bootstrap.Modal(document.getElementById('modalAlvaras'));
            modal.show();
        } else {
            document.querySelectorAll('.alvara-situacao').forEach(function (campo) {
                campo.required = false;
            });

            $('.alvara-situacao').val('').trigger('change');

            if (this.value === 'goias') {
                abrirModalAlvarasGoias();
            }
        }
    });

    $('#cadastro_df_legal').on('change', function () {
        if (this.value === 'goias') {
            abrirModalAlvarasGoias();
        }
    });

    $('#btnDispensarTodosAlvaras').on('click', function () {
        document.querySelectorAll('.alvara-situacao').forEach(function (campo) {
            campo.value = 'dispensado';
            campo.classList.remove('is-invalid');
            campo.dispatchEvent(new Event('change', { bubbles: true }));
        });

        document.getElementById('alertaAlvarasObrigatorios').classList.add('d-none');
    });

    $(document).on('change', '.alvara-situacao', function () {
        const campoVencimento = document.getElementById(this.dataset.vencimento);
        const possuiVencimento = this.value === 'com_vencimento';

        campoVencimento.disabled = !possuiVencimento;
        campoVencimento.required = possuiVencimento;

        if (possuiVencimento) {
            campoVencimento.focus();
        } else {
            campoVencimento.value = '';
            campoVencimento.classList.remove('is-invalid');
        }
    });

    $(document).on('change', '.alvara-goias-situacao', function () {
        const campoVencimento = document.getElementById(this.dataset.vencimento);
        const possuiVencimento = this.value === 'com_vencimento';

        campoVencimento.disabled = !possuiVencimento;
        campoVencimento.required = possuiVencimento;

        if (!possuiVencimento) {
            campoVencimento.value = '';
            campoVencimento.classList.remove('is-invalid');
        }
    });

    $(document).on('input', '.alvara-goias-taxa', function () {
        const digitos = this.value.replace(/\D/g, '');
        const valor = Number(digitos || 0) / 100;
        this.value = valor.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    });

    $(document).on('change', '.controle-com-vencimento', function () {
        atualizarCampoVencimentoControle(this, true);
    });

    $('#certificado_status').on('change', function () {
        const campoVencimento = document.getElementById('vencimento_certificado');

        if (!campoVencimento) {
            return;
        }

        const possui = this.value === 'possui';
        campoVencimento.disabled = !possui;
        campoVencimento.required = possui;

        if (possui && this.dataset.inicializadoCertificado === '1') {
            setTimeout(function () {
                campoVencimento.focus({ preventScroll: true });
            }, 0);
        } else if (!possui) {
            campoVencimento.value = '';
            campoVencimento.classList.remove('is-invalid');
        }
    }).each(function () {
        $(this).trigger('change');
        this.dataset.inicializadoCertificado = '1';
    });

    const controlesConferencia = {
        cadastro_df_legal: {
            valor: 'cadastrado',
            prefixo: 'df_legal',
            titulo: 'Conferir cadastro DF Legal',
            socio: false
        },
        cadastro_crf: {
            valor: 'cadastrado',
            prefixo: 'crf',
            titulo: 'Conferir Cadastro CRF',
            socio: false
        },
        procuracao_particular: {
            valor: 'possui',
            prefixo: 'procuracao_particular',
            titulo: 'Conferir Procuração Particular',
            socio: true
        }
    };

    function abrirModalConferenciaCadastro(config) {
        const modalEl = document.getElementById('modalConferenciaCadastro');

        if (!modalEl) {
            return;
        }

        $('#prefixoConferenciaCadastro').val(config.prefixo);
        $('#tituloModalConferenciaCadastro').text(config.titulo);
        $('#grupoModalSocioCorreto').toggleClass('d-none', !config.socio);
        $('#alertaConferenciaCadastro').addClass('d-none');

        $(`input[name="modal_razao_social_correta"][value="${$('#' + config.prefixo + '_razao_social_correta').val() || 'sim'}"]`).prop('checked', true);
        $(`input[name="modal_endereco_correto"][value="${$('#' + config.prefixo + '_endereco_correto').val() || 'sim'}"]`).prop('checked', true);

        if (config.socio) {
            $(`input[name="modal_socio_correto"][value="${$('#' + config.prefixo + '_socio_correto').val() || 'sim'}"]`).prop('checked', true);
        } else {
            $('input[name="modal_socio_correto"]').prop('checked', false);
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    Object.keys(controlesConferencia).forEach(function (campoId) {
        $('#' + campoId).on('change', function () {
            const config = controlesConferencia[campoId];

            if (this.value === config.valor) {
                abrirModalConferenciaCadastro(config);
            } else {
                $('#' + config.prefixo + '_razao_social_correta').val('sim');
                $('#' + config.prefixo + '_endereco_correto').val('sim');
                $('#' + config.prefixo + '_socio_correto').val('sim');
            }
        });
    });

    $('#btnConcluirConferenciaCadastro').on('click', function () {
        const prefixo = $('#prefixoConferenciaCadastro').val();
        const razao = $('input[name="modal_razao_social_correta"]:checked').val();
        const endereco = $('input[name="modal_endereco_correto"]:checked').val();
        const precisaSocio = !$('#grupoModalSocioCorreto').hasClass('d-none');
        const socio = $('input[name="modal_socio_correto"]:checked').val();

        if (!razao || !endereco || (precisaSocio && !socio)) {
            $('#alertaConferenciaCadastro').removeClass('d-none');
            return;
        }

        $('#' + prefixo + '_razao_social_correta').val(razao);
        $('#' + prefixo + '_endereco_correto').val(endereco);

        if (precisaSocio) {
            $('#' + prefixo + '_socio_correto').val(socio);
        }

        bootstrap.Modal.getInstance(document.getElementById('modalConferenciaCadastro')).hide();
    });

    $('#btnConcluirAlvaras').on('click', function () {
        if (!validarPreenchimentoAlvaras()) {
            return;
        }

        const modal = bootstrap.Modal.getInstance(document.getElementById('modalAlvaras'));

        if (modal) {
            modal.hide();
        }
    });

    $('#btnConcluirAlvarasGoias').on('click', function () {
        if (!validarPreenchimentoAlvarasGoias()) {
            return;
        }

        const modal = bootstrap.Modal.getInstance(document.getElementById('modalAlvarasGoiasCliente'));

        if (modal) {
            modal.hide();
        }
    });

});

function atualizarCampoVencimentoControle(campoSituacao, darFoco = false) {
    const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);
    const possui = campoSituacao.value === 'possui';

    campoVencimento.disabled = !possui;
    campoVencimento.required = possui;

    if (possui && darFoco) {
        campoVencimento.focus();
    } else if (!possui) {
        campoVencimento.value = '';
        campoVencimento.classList.remove('is-invalid');
    }
}

function validarPreenchimentoAlvaras() {
    let valido = true;

    document.querySelectorAll('.alvara-situacao').forEach(function (campoSituacao) {
        const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);

        if (campoSituacao.value === '') {
            campoSituacao.classList.add('is-invalid');
            valido = false;
        } else {
            campoSituacao.classList.remove('is-invalid');
        }

        if (campoSituacao.value === 'com_vencimento' && campoVencimento.value === '') {
            campoVencimento.classList.add('is-invalid');
            valido = false;
        } else {
            campoVencimento.classList.remove('is-invalid');
        }
    });

    const alerta = document.getElementById('alertaAlvarasObrigatorios');
    alerta.classList.toggle('d-none', valido);

    return valido;
}

function validarPreenchimentoAlvarasGoias() {
    let valido = true;

    document.querySelectorAll('.alvara-goias-situacao').forEach(function (campoSituacao) {
        const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);

        campoSituacao.classList.remove('is-invalid');
        campoVencimento.classList.remove('is-invalid');

        if (campoSituacao.value === 'com_vencimento' && campoVencimento.value === '') {
            campoVencimento.classList.add('is-invalid');
            valido = false;
        }
    });

    const alerta = document.getElementById('alertaAlvarasGoiasObrigatorios');

    if (alerta) {
        alerta.classList.toggle('d-none', valido);
    }

    return valido;
}

function digitoModulo11(numero, pesos) {
    const soma = numero.split('').reduce(function (total, digito, indice) {
        return total + Number(digito) * pesos[indice];
    }, 0);
    const resultado = 11 - (soma % 11);

    return resultado >= 10 ? 0 : resultado;
}

function validarInscricaoEstadual(valor, uf) {
    const normalizado = String(valor || '').trim().toUpperCase();

    if (normalizado === '' || normalizado === 'ISENTO') {
        return true;
    }

    const numero = normalizado.replace(/\D/g, '');
    const estado = String(uf || '').trim().toUpperCase();

    if (estado === 'DF') {
        if (numero.length !== 13 || !/^(07|08)/.test(numero)) return false;

        const primeiro = digitoModulo11(numero.slice(0, 11), [4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        if (primeiro !== Number(numero[11])) return false;

        const segundo = digitoModulo11(numero.slice(0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        return segundo === Number(numero[12]);
    }

    if (estado === 'GO') {
        if (numero.length !== 9 || !/^(10|11|15|20)/.test(numero)) return false;

        const base = numero.slice(0, 8);
        const soma = base.split('').reduce(function (total, digito, indice) {
            return total + Number(digito) * [9, 8, 7, 6, 5, 4, 3, 2][indice];
        }, 0);
        const resto = soma % 11;
        let digito = 0;

        if (resto === 0) {
            digito = 0;
        } else if (resto === 1) {
            const faixa = Number(base);
            digito = faixa >= 10103105 && faixa <= 10119997 ? 1 : 0;
        } else {
            digito = 11 - resto;
        }

        return digito === Number(numero[8]);
    }

    return numero.length >= 8 && numero.length <= 14;
}

function validarCampoInscricaoEstadual() {
    const campo = document.getElementById('inscricao_estadual');
    const uf = document.getElementById('uf').value;
    const valido = validarInscricaoEstadual(campo.value, uf);

    campo.classList.toggle('is-invalid', !valido);

    return valido;
}

function mostrarAviso(mensagem, campo = null) {
    $('#modalAvisoMensagem').text(mensagem);

    const modalEl = document.getElementById('modalAviso');

    const modal = new bootstrap.Modal(modalEl, {
        backdrop: 'static',
        keyboard: false
    });

    modal.show();

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (campo) {
            $(campo).val('').focus();
        }
    }, { once: true });
}

function carregarClientes(page = 1) {
    paginaAtual = page;
    const sequenciaAtual = ++sequenciaRequisicaoClientes;

    const parametros = new URLSearchParams({
        action: 'read',
        page: String(page),
        limit: String(limitePorPagina),
        busca: ($('#buscaCliente').val() || '').trim(),
        uf: $('#filtroUf').val() || ''
    });

    if (requisicaoClientes && requisicaoClientes.readyState !== 4) {
        requisicaoClientes.abort();
    }

    requisicaoClientes = $.getJSON(`api.php?${parametros.toString()}`, function (res) {
        if (sequenciaAtual !== sequenciaRequisicaoClientes) {
            return;
        }

        let linhas = '';
        const clientes = Array.isArray(res) ? res : (res.data || []);
        const totalClientes = Number(res.total || clientes.length || 0);

        $('#totalClientesResumo').text(
            'Total: ' + totalClientes + ' ' + (totalClientes === 1 ? 'cliente' : 'clientes')
        );

        if (clientes.length === 0) {
            $('#clientesTable tbody').html(`
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        Nenhum cliente cadastrado ainda.
                    </td>
                </tr>
            `);

            $('#paginacao').html('');
            $('#grupoLimiteClientes').addClass('d-none');
            return;
        }

        clientes.forEach(cliente => {
            linhas += `
<tr class="linha-cliente"
    data-busca="${escapeHtml(`${cliente.codigo} ${cliente.documento} ${cliente.nome} ${cliente.nome_fantasia} ${cliente.email}`).toLowerCase()}"
    data-uf="${escapeHtml(cliente.uf)}"
    data-url="cliente.php?id=${cliente.id}"
    role="button"
    tabindex="0">

    <td>${escapeHtml(cliente.codigo)}</td>
    <td class="coluna-documento-cliente">${escapeHtml(cliente.documento)}</td>
    <td>${escapeHtml(cliente.nome)}</td>
    <td>${escapeHtml(cliente.nome_fantasia)}</td>
    <td>${escapeHtml(cliente.cidade)}</td>
    <td>${escapeHtml(cliente.uf)}</td>
    <td>${escapeHtml(cliente.telefone)}</td>
    <td>${escapeHtml(cliente.email)}</td>
    <td class="text-end text-muted">
        <i class="bi bi-chevron-right"></i>
    </td>
</tr>
`;
        });

        $('#clientesTable tbody').html(linhas);
        renderizarPaginacao(res.total || 0, res.page || 1, res.limit || limitePorPagina);
    }).fail(function (xhr) {
        if (xhr.statusText === 'abort') {
            return;
        }

        ('Erro ao carregar clientes: ' + xhr.responseText);
    });
}

function renderizarPaginacao(total, pagina, limite) {
    let totalPaginas = Math.ceil(total / limite);
    let html = '';

    if (totalPaginas <= 1) {
        $('#paginacao').html('');
        $('#grupoLimiteClientes').addClass('d-none');
        return;
    }

    $('#grupoLimiteClientes').removeClass('d-none');

    html += `<nav><ul class="pagination justify-content-center mt-3">`;

    html += `
        <li class="page-item ${pagina <= 1 ? 'disabled' : ''}">
            <button class="page-link" onclick="carregarClientes(${pagina - 1})">Anterior</button>
        </li>
    `;

    const paginasVisiveis = [];
    let ultimaPagina = 0;

    for (let i = 1; i <= totalPaginas; i++) {
        if (i === 1 || i === totalPaginas || Math.abs(i - pagina) <= 2) {
            if (ultimaPagina && i - ultimaPagina > 1) {
                paginasVisiveis.push('...');
            }

            paginasVisiveis.push(i);
            ultimaPagina = i;
        }
    }

    paginasVisiveis.forEach(function (i) {
        if (i === '...') {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            return;
        }

        html += `
            <li class="page-item ${i === pagina ? 'active' : ''}">
                <button class="page-link" onclick="carregarClientes(${i})">${i}</button>
            </li>
        `;
    });

    html += `
        <li class="page-item ${pagina >= totalPaginas ? 'disabled' : ''}">
            <button class="page-link" onclick="carregarClientes(${pagina + 1})">Próxima</button>
        </li>
    `;

    html += `</ul></nav>`;

    $('#paginacao').html(html);
}

function abrirModalNovo() {
    $('#clienteModalLabel').text('Novo Cliente');

    $('#clienteForm')[0].reset();
    $('#id').val('');

    limparValidacoes();

    // 🔥 LIMPAR CEP COMPLETAMENTE
    $('#cep')
        .val('')
        .removeClass('');

    $('#cepFeedback')
        .text('')
        .removeClass('text-success text-danger text-muted');

    aplicarMascaras();

    const modal = new bootstrap.Modal(document.getElementById('clienteModal'));
    modal.show();

    setTimeout(() => $('#codigo').focus(), 500);

    $('#clienteModal').on('show.bs.modal', function () {
        $('#cep').removeClass('');
    });
}

function abrirModalEditar(
    id,
    codigo,
    documento,
    nome,
    nome_fantasia,
    endereco,
    numero_endereco,
    complemento,
    bairro,
    cidade,
    uf,
    cep,
    telefone,
    inscricao_estadual,
    nire,
    email,
    vencimento_certificado
) {
    $('#clienteModalLabel').text('Editar Cliente');

    $('#id').val(id);
    $('#codigo').val(codigo);
    $('#documento').val(documento);
    $('#nome').val(nome);
    $('#nome_fantasia').val(nome_fantasia);
    $('#endereco').val(endereco);
    $('#numero_endereco').val(numero_endereco);
    $('#complemento').val(complemento);
    $('#bairro').val(bairro);
    $('#cidade').val(cidade);
    $('#uf').val(uf);
    $('#cep').val(cep);
    $('#telefone').val(telefone);
    $('#inscricao_estadual').val(inscricao_estadual);
    $('#nire').val(nire);
    $('#email').val(email);
    $('#vencimento_certificado').val(vencimento_certificado);

    limparValidacoes();
    aplicarMascaras();

    const modal = new bootstrap.Modal(document.getElementById('clienteModal'));
    modal.show();
}

function excluirCliente(id, uf = '', exigeContadorRetirado = true, exigeSefazRevogada = false) {
    clienteParaExcluir = id;
    clienteUfParaExcluir = String(uf || '').toUpperCase();
    clienteExigeContadorRetirado = exigeContadorRetirado === true || exigeContadorRetirado === '1';
    clienteExigeSefazRevogada = (exigeSefazRevogada === true || exigeSefazRevogada === '1')
        && clienteUfParaExcluir === 'DF';

    $('#confirmarContadorRetirado').prop('checked', false).removeClass('is-invalid');
    $('#confirmarSefazRevogada').prop('checked', false).removeClass('is-invalid');
    $('#grupoConfirmarContadorRetirado').toggleClass('d-none', !clienteExigeContadorRetirado);
    $('#grupoConfirmarSefazRevogada').toggleClass('d-none', !clienteExigeSefazRevogada);

    const modal = new bootstrap.Modal(document.getElementById('modalConfirmarExclusao'));
    modal.show();
}

function validarFormulario() {
    limparValidacoes();

    let valido = true;
    let primeiroCampoInvalido = null;

    function validarObrigatorio(campo) {
        if ($(campo).val().trim() === '') {
            $(campo).addClass('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = campo;
            }

            valido = false;
        }
    }

    validarObrigatorio('#codigo');
    validarObrigatorio('#cliente_contabil');
    validarObrigatorio('#documento');
    validarObrigatorio('#nome');
    validarObrigatorio('#telefone');

    const clienteContabil = $('#cliente_contabil').val() === '1';
    const servicoParcelamento = $('#servico_parcelamento').is(':checked');
    const servicoCertificado = $('#servico_certificado').is(':checked');

    if (clienteContabil) {
        validarObrigatorio('#possui_parcelamento');
        validarObrigatorio('#cep');
        validarObrigatorio('#numero_endereco');
    }

    const documento = $('#documento').val().replace(/\D/g, '');
    const telefone = $('#telefone').val().replace(/\D/g, '');
    const email = $('#email').val().trim();

    if (documentoDuplicado) {
        $('#documento').addClass('is-invalid');
        mostrarAviso('Já existe um cliente cadastrado com este CPF/CNPJ.', '#documento');
        return false;
    }

    if (documento !== '' && !validarCpfOuCnpj(documento)) {
        $('#documento').addClass('is-invalid');
        mostrarAviso('CPF ou CNPJ inválido.', '#documento');
        return false;
    }

    if (telefone !== '' && (telefone.length < 10 || telefone.length > 11)) {
        $('#telefone').addClass('is-invalid');

        if (primeiroCampoInvalido === null) {
            primeiroCampoInvalido = '#telefone';
        }

        valido = false;
    }

    if (email !== '' && !validarEmail(email)) {
        $('#email').addClass('is-invalid');

        if (primeiroCampoInvalido === null) {
            primeiroCampoInvalido = '#email';
        }

        valido = false;
    }

    if (clienteContabil && !validarCampoInscricaoEstadual()) {
        if (primeiroCampoInvalido === null) {
            primeiroCampoInvalido = '#inscricao_estadual';
        }

        valido = false;
    }

    document.querySelectorAll('.procuracao-obrigatoria').forEach(function (campo) {
        if (!campo.disabled && campo.value === '') {
            campo.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campo.id;
            }

            valido = false;
        }
    });

    document.querySelectorAll('.controle-interno-obrigatorio').forEach(function (campo) {
        if (!campo.disabled && campo.value === '') {
            campo.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campo.id;
            }

            valido = false;
        }
    });

    document.querySelectorAll('.controle-com-vencimento').forEach(function (campoSituacao) {
        const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);

        if (!campoSituacao.disabled && campoSituacao.value === 'possui' && campoVencimento.value === '') {
            campoVencimento.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campoVencimento.id;
            }

            valido = false;
        }
    });

    const ocultarServicosAcompanhados = $('#ocultar_servicos_acompanhados').val() === '1';

    if (!clienteContabil && !ocultarServicosAcompanhados && !servicoParcelamento && !servicoCertificado) {
        $('#servicosAvulsosFeedback').removeClass('d-none').show();
        valido = false;
    } else {
        $('#servicosAvulsosFeedback').addClass('d-none').hide();
    }

    const alvarasValidos = !clienteContabil
        || $('#alvara').val() !== 'possui'
        || validarPreenchimentoAlvaras();

    if (!alvarasValidos) {
        valido = false;
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlvaras'));
        modal.show();
        return false;
    }

    const alvarasGoiasValidos = !clienteContabil
        || ($('#alvara').val() !== 'goias' && $('#cadastro_df_legal').val() !== 'goias')
        || validarPreenchimentoAlvarasGoias();

    if (!alvarasGoiasValidos) {
        valido = false;
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlvarasGoiasCliente'));
        modal.show();
        return false;
    }

    if (!valido && primeiroCampoInvalido !== null) {
        $(primeiroCampoInvalido).focus();
    }

    return valido;
}

function atualizarVinculoServicos() {
    const campoClienteContabil = document.getElementById('cliente_contabil');

    if (!campoClienteContabil) {
        return;
    }

    const clienteContabil = campoClienteContabil.value === '1';
    const campoServicoParcelamento = document.getElementById('servico_parcelamento');
    const campoServicoCertificado = document.getElementById('servico_certificado');
    const ocultarServicosAcompanhados = document.getElementById('ocultar_servicos_acompanhados')?.value === '1';

    document.querySelectorAll('.secao-servicos-avulsos').forEach(function (bloco) {
        const ocultarBloco = clienteContabil || ocultarServicosAcompanhados;
        bloco.hidden = ocultarBloco;
        bloco.classList.toggle('d-none', ocultarBloco);
    });

    if (ocultarServicosAcompanhados) {
        campoServicoParcelamento.checked = false;
        campoServicoParcelamento.disabled = true;
        campoServicoCertificado.checked = false;
        campoServicoCertificado.disabled = true;
    }

    const servicoParcelamento = campoServicoParcelamento.checked;
    const servicoCertificado = campoServicoCertificado.checked;

    document.querySelectorAll('.secao-cliente-contabil, .campo-cliente-contabil').forEach(function (bloco) {
        bloco.hidden = !clienteContabil;
        bloco.classList.toggle('d-none', !clienteContabil);

        bloco.querySelectorAll('input, select, textarea').forEach(function (campo) {
            campo.disabled = !clienteContabil;
            campo.classList.remove('is-invalid');
        });
    });

    const campoParcelamento = document.getElementById('possui_parcelamento');

    if (!clienteContabil) {
        campoParcelamento.value = servicoParcelamento ? 'possui' : 'nao_possui';
    }

    document.querySelectorAll('.campo-servico-certificado').forEach(function (bloco) {
        const exibirVencimentoCertificado = clienteContabil || servicoCertificado;
        bloco.hidden = !exibirVencimentoCertificado;
        bloco.classList.toggle('d-none', !exibirVencimentoCertificado);

        bloco.querySelectorAll('input, select, textarea').forEach(function (campo) {
            campo.disabled = !exibirVencimentoCertificado;

            if (!exibirVencimentoCertificado) {
                campo.value = '';
            }
        });
    });

    if (clienteContabil) {
        document.querySelectorAll('.controle-com-vencimento').forEach(function (campo) {
            atualizarCampoVencimentoControle(campo);
        });
    }

    if (clienteContabil || ocultarServicosAcompanhados || servicoParcelamento || servicoCertificado) {
        $('#servicosAvulsosFeedback').addClass('d-none').hide();
    }
}

function verificarDocumentoDuplicado(documentoFormatado) {
    const id = $('#id').val() || '';

    if (documentoFormatado === '' || documentoFormatado === ultimaConsultaDocumento) {
        return $.Deferred().resolve({ duplicado: documentoDuplicado }).promise();
    }

    ultimaConsultaDocumento = documentoFormatado;

    return $.getJSON('api.php?action=check_documento', {
        documento: documentoFormatado,
        id: id
    }).done(function (resposta) {
        if (resposta.duplicado) {
            documentoDuplicado = true;
            $('#documento').addClass('is-invalid');

            const cliente = resposta.cliente || {};
            const nomeCliente = cliente.codigo && cliente.nome
                ? cliente.codigo + ' - ' + cliente.nome
                : 'cliente já cadastrado';

            mostrarAviso(
                'Já existe um cliente cadastrado com este CPF/CNPJ: ' + nomeCliente + '.',
                '#documento'
            );
        } else {
            documentoDuplicado = false;
            $('#documento').removeClass('is-invalid');
        }
    }).fail(function () {
        documentoDuplicado = false;
    });
}

function formatarCnpj(valor) {
    const cnpj = String(valor || '').replace(/\D/g, '');

    if (cnpj.length !== 14) {
        return valor || '';
    }

    return cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
}

function formatarCep(valor) {
    const cep = String(valor || '').replace(/\D/g, '');

    if (cep.length !== 8) {
        return valor || '';
    }

    return cep.replace(/^(\d{5})(\d{3})$/, '$1-$2');
}

function formatarDataBr(valor) {
    if (!valor || !/^\d{4}-\d{2}-\d{2}$/.test(valor)) {
        return '-';
    }

    const partes = valor.split('-');
    return `${partes[2]}/${partes[1]}/${partes[0]}`;
}

function textoFonteCnpj(dados) {
    if (!dados) {
        return '';
    }

    const partes = [];

    if (dados.fonte) {
        partes.push(`Fonte: ${dados.fonte}`);
    }

    if (dados.fonte_qsa && dados.fonte_qsa !== dados.fonte) {
        partes.push(`QSA: ${dados.fonte_qsa}`);
    }

    if (dados.ultima_atualizacao) {
        partes.push(`Atualizado em ${formatarDataBr(String(dados.ultima_atualizacao).slice(0, 10))}`);
    }

    return partes.join(' | ');
}

function renderizarQsaCliente(socios) {
    const tabelaWrapper = $('#qsaClienteTabelaWrapper');
    const tabelaCorpo = $('#qsaClienteTabelaCorpo');
    const mensagemVazia = $('#qsaClienteVazio');

    if (!tabelaWrapper.length || !tabelaCorpo.length || !mensagemVazia.length) {
        return;
    }

    const listaSocios = Array.isArray(socios) ? socios : [];
    tabelaCorpo.empty();

    if (!listaSocios.length) {
        tabelaWrapper.addClass('d-none');
        mensagemVazia.removeClass('d-none');
        return;
    }

    listaSocios.forEach(function (socio) {
        tabelaCorpo.append(`
            <tr>
                <td>${escapeHtml(socio.nome || '')}</td>
                <td>${escapeHtml(socio.qualificacao || '-')}</td>
                <td>${escapeHtml(socio.documento || '-')}</td>
                <td>${escapeHtml(formatarDataBr(socio.entrada_sociedade))}</td>
            </tr>
        `);
    });

    tabelaWrapper.removeClass('d-none');
    mensagemVazia.addClass('d-none');
}

function atualizarQsaPelaReceita() {
    const botao = $('#btnAtualizarQsaReceita');
    const status = $('#qsaClienteStatus');
    const documento = $('#documento').val().replace(/\D/g, '');

    if (documento.length !== 14) {
        $('#documento').addClass('is-invalid');
        mostrarAviso('Informe um CNPJ válido para atualizar o QSA.');
        return;
    }

    botao
        .prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Atualizando...');
    status
        .removeClass('text-danger text-success')
        .addClass('text-muted')
        .text('Consultando dados públicos do CNPJ...');

    $.getJSON('api.php?action=consultar_cnpj', { cnpj: documento })
        .done(function (resposta) {
            if (!resposta.ok || !resposta.dados) {
                status
                    .removeClass('text-muted text-success')
                    .addClass('text-danger')
                    .text('Não foi possível consultar o QSA agora.');
                return;
            }

            const sociosEncontrados = Array.isArray(resposta.dados.socios) ? resposta.dados.socios : [];
            dadosCnpjEncontrado = resposta.dados;
            $('#qsa_json').val(JSON.stringify(sociosEncontrados));
            renderizarQsaCliente(sociosEncontrados);

            status
                .removeClass('text-muted text-danger')
                .addClass('text-success')
                .text(
                    (
                        sociosEncontrados.length
                            ? 'QSA atualizado. Clique em Salvar para gravar.'
                            : 'A consulta não retornou sócios. Clique em Salvar para gravar essa conferência.'
                    ) + (textoFonteCnpj(resposta.dados) ? ` ${textoFonteCnpj(resposta.dados)}.` : '')
                );
        })
        .fail(function () {
            status
                .removeClass('text-muted text-success')
                .addClass('text-danger')
                .text('Erro ao consultar o QSA. Tente novamente em instantes.');
        })
        .always(function () {
            botao
                .prop('disabled', false)
                .html('<i class="bi bi-arrow-clockwise"></i> Atualizar pela Receita');
        });
}

function consultarCnpjParaPreenchimento(cnpj) {
    const paginaCliente = window.location.pathname.includes('cliente_novo.php')
        || window.location.pathname.includes('cliente_editar.php');

    if (!paginaCliente) {
        return;
    }

    if (cnpj === ultimoCnpjConsultado) {
        return;
    }

    ultimoCnpjConsultado = cnpj;

    $('#documento').removeClass('is-invalid');

    $.getJSON('api.php?action=consultar_cnpj', { cnpj: cnpj })
        .done(function (resposta) {
            if (!resposta.ok || !resposta.dados) {
                return;
            }

            dadosCnpjEncontrado = resposta.dados;
            const sociosEncontrados = Array.isArray(dadosCnpjEncontrado.socios)
                ? dadosCnpjEncontrado.socios
                : [];

            $('#cnpjConsultaRazao').text(dadosCnpjEncontrado.nome || 'Razão social não informada');
            $('#cnpjConsultaDocumento').text(formatarCnpj(dadosCnpjEncontrado.documento));
            $('#cnpjConsultaEndereco').text([
                dadosCnpjEncontrado.endereco,
                dadosCnpjEncontrado.numero_endereco,
                dadosCnpjEncontrado.bairro,
                dadosCnpjEncontrado.cidade,
                dadosCnpjEncontrado.uf
            ].filter(Boolean).join(' - ') || 'Endereço não informado');
            $('#cnpjConsultaQsa').text(
                (
                    sociosEncontrados.length
                        ? `QSA: ${sociosEncontrados.length} ${sociosEncontrados.length === 1 ? 'sócio encontrado' : 'sócios encontrados'}`
                        : 'QSA não retornado pela consulta.'
                ) + (textoFonteCnpj(dadosCnpjEncontrado) ? ` ${textoFonteCnpj(dadosCnpjEncontrado)}.` : '')
            );

            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPreencherCnpj'));
            modal.show();
        });
}

function preencherCampoSeExistir(seletor, valor) {
    const campo = $(seletor);

    if (!campo.length || valor === undefined || valor === null || String(valor).trim() === '') {
        return;
    }

    campo.val(valor).trigger('input').trigger('change');
    campo.removeClass('is-invalid');
}

function preencherCadastroComCnpj() {
    if (!dadosCnpjEncontrado) {
        return;
    }

    preencherCampoSeExistir('#documento', formatarCnpj(dadosCnpjEncontrado.documento));
    preencherCampoSeExistir('#nome', dadosCnpjEncontrado.nome);
    preencherCampoSeExistir('#nome_fantasia', dadosCnpjEncontrado.nome_fantasia);
    preencherCampoSeExistir('#email', dadosCnpjEncontrado.email);
    preencherCampoSeExistir('#telefone', dadosCnpjEncontrado.telefone);
    preencherCampoSeExistir('#cep', formatarCep(dadosCnpjEncontrado.cep));
    preencherCampoSeExistir('#endereco', dadosCnpjEncontrado.endereco);
    preencherCampoSeExistir('#numero_endereco', dadosCnpjEncontrado.numero_endereco);
    preencherCampoSeExistir('#complemento', dadosCnpjEncontrado.complemento);
    preencherCampoSeExistir('#bairro', dadosCnpjEncontrado.bairro);
    preencherCampoSeExistir('#cidade', dadosCnpjEncontrado.cidade);
    preencherCampoSeExistir('#uf', String(dadosCnpjEncontrado.uf || '').toUpperCase());
    const sociosEncontrados = Array.isArray(dadosCnpjEncontrado.socios) ? dadosCnpjEncontrado.socios : [];
    $('#qsa_json').val(JSON.stringify(sociosEncontrados));
    renderizarQsaCliente(sociosEncontrados);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPreencherCnpj')).hide();
    validarCampoInscricaoEstadual();
}

function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

$('input, select').on('input change', function () {
    if ($(this).val().trim() !== '') {
        $(this).removeClass('is-invalid');
    }
});

function marcarInvalido(campo) {
    $(campo).addClass('is-invalid').focus();
}

function limparValidacoes() {
    $('.is-invalid').removeClass('is-invalid');
    $('.is-valid').removeClass('is-valid');
    $('#cepFeedback').text('').removeClass('text-danger text-success text-muted');
}

function validarCpfOuCnpj(valor) {
    if (valor.length === 11) return validarCPF(valor);
    if (valor.length === 14) return validarCNPJ(valor);
    return false;
}

function validarCPF(cpf) {
    if (!cpf || cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) return false;

    let soma = 0;

    for (let i = 0; i < 9; i++) {
        soma += parseInt(cpf.charAt(i)) * (10 - i);
    }

    let resto = 11 - (soma % 11);
    let digito1 = resto >= 10 ? 0 : resto;

    if (digito1 !== parseInt(cpf.charAt(9))) return false;

    soma = 0;

    for (let i = 0; i < 10; i++) {
        soma += parseInt(cpf.charAt(i)) * (11 - i);
    }

    resto = 11 - (soma % 11);
    let digito2 = resto >= 10 ? 0 : resto;

    return digito2 === parseInt(cpf.charAt(10));
}

function validarCNPJ(cnpj) {
    if (!cnpj || cnpj.length !== 14 || /^(\d)\1+$/.test(cnpj)) return false;

    let tamanho = cnpj.length - 2;
    let numeros = cnpj.substring(0, tamanho);
    let digitos = cnpj.substring(tamanho);
    let soma = 0;
    let pos = tamanho - 7;

    for (let i = tamanho; i >= 1; i--) {
        soma += parseInt(numeros.charAt(tamanho - i)) * pos--;
        if (pos < 2) pos = 9;
    }

    let resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);

    if (resultado !== parseInt(digitos.charAt(0))) return false;

    tamanho = tamanho + 1;
    numeros = cnpj.substring(0, tamanho);
    soma = 0;
    pos = tamanho - 7;

    for (let i = tamanho; i >= 1; i--) {
        soma += parseInt(numeros.charAt(tamanho - i)) * pos--;
        if (pos < 2) pos = 9;
    }

    resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);

    return resultado === parseInt(digitos.charAt(1));
}

function aplicarMascaras() {
    $('#cep').mask('00000-000');
    $('#telefone').mask('(00) 00000-0000');

    const cpfCnpjMaskBehavior = function (val) {
        return val.replace(/\D/g, '').length > 11
            ? '00.000.000/0000-00'
            : '000.000.000-009';
    };

    const cpfCnpjOptions = {
        onKeyPress: function (val, e, field, options) {
            field.mask(cpfCnpjMaskBehavior.apply({}, arguments), options);
        }
    };

    $('#documento').mask(cpfCnpjMaskBehavior, cpfCnpjOptions);
}

function escapeHtml(valor) {
    return String(valor || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function jsString(valor) {
    return JSON.stringify(valor || '');
}

const clienteModalEl = document.getElementById('clienteModal');

if (clienteModalEl) {
    clienteModalEl.addEventListener('hidden.bs.modal', function () {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style = "";
    });
}

$(document).on('pointerdown', '#clientesTable tbody tr.linha-cliente', function (evento) {
    if (evento.button !== 0) {
        return;
    }

    const url = $(this).data('url');

    if (url) {
        window.location.href = url;
    }
});

$(document).on('keydown', '#clientesTable tbody tr.linha-cliente', function (evento) {
    if (evento.key !== 'Enter' && evento.key !== ' ') {
        return;
    }

    evento.preventDefault();
    const url = $(this).data('url');

    if (url) {
        window.location.href = url;
    }
});

function filtrarClientesNaTela() {
    const busca = $('#buscaCliente').val().toLowerCase().trim();
    const uf = $('#filtroUf').val();

    $('#clientesTable tbody tr.linha-cliente').each(function () {
        const texto = $(this).data('busca') || '';
        const linhaUf = $(this).data('uf') || '';

        const bateBusca = texto.includes(busca);
        const bateUf = uf === '' || linhaUf === uf;

        $(this).toggle(bateBusca && bateUf);
    });
}

$(document).on('click', '#btnConfirmarExclusao', function () {

    if (!clienteParaExcluir) return;

    const botao = $(this);
    const contadorRetirado = $('#confirmarContadorRetirado').is(':checked');
    const procuracaoSefazRevogada = $('#confirmarSefazRevogada').is(':checked');
    let devolucaoConfirmada = false;

    if (clienteExigeContadorRetirado && !contadorRetirado) {
        $('#confirmarContadorRetirado').addClass('is-invalid').focus();
        mostrarAviso('Confirme que o contador já foi retirado antes de devolver.');
        return;
    }

    if (clienteExigeSefazRevogada && !procuracaoSefazRevogada) {
        $('#confirmarSefazRevogada').addClass('is-invalid').focus();
        mostrarAviso('Confirme que a procuração SEFAZ DF já foi revogada.');
        return;
    }

    botao.prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Devolvendo...');

    $.post('api.php?action=delete', {
        id: clienteParaExcluir,
        contador_retirado: contadorRetirado ? '1' : '0',
        procuracao_sefaz_revogada: procuracaoSefazRevogada ? '1' : '0'
    }, function (resp) {

        if (resp.trim() === 'ok') {
            devolucaoConfirmada = true;
            window.location.href = 'clientes.php';
        } else {
            mostrarAviso(resp);
        }

    }).fail(function (xhr) {
        mostrarAviso('Erro: ' + xhr.responseText);
    }).always(function () {

        botao.prop('disabled', false)
            .html('<i class="bi bi-archive"></i> Sim, devolver');

        const modalEl = document.getElementById('modalConfirmarExclusao');
        const modal = bootstrap.Modal.getInstance(modalEl);

        if (modal && devolucaoConfirmada) modal.hide();
    });
});