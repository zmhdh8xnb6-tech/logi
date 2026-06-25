let paginaAtual = 1;
let limitePorPagina = 10;

$(document).ready(function () {
    aplicarMascaras();
    carregarClientes();

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

        if (documento.length === 0) {
            return;
        }

        if (!validarCpfOuCnpj(documento)) {
            $('#documento').addClass('is-invalid');
            mostrarAviso('CPF ou CNPJ inválido.', '#documento');
        }
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
                const possuiParcelamento = $('#possui_parcelamento').val();

                if (cadastroSalvo) {

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

                        if (possuiParcelamento === 'possui') {
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

                        window.location.href = 'clientes.php';

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

                    mostrarAviso('Preencha todos os órgãos do alvará com vencimento ou como dispensado.');

                } else if (resp.trim() === 'parcelamento_obrigatorio') {

                    $('#possui_parcelamento').addClass('is-invalid').focus();
                    mostrarAviso('Informe se o cliente possui parcelamento.');

                } else if (resp.trim() === 'alvara_obrigatorio') {

                    $('#alvara').addClass('is-invalid').focus();
                    mostrarAviso('Informe a situação do alvará.');

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

    $('#buscaCliente, #filtroUf').on('input change', function () {
        filtrarClientesNaTela();
    });

    $(document).on('input', '#nome_fantasia', function () {
        this.value = this.value.toUpperCase();
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

    $('#alvara').on('change', function () {
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
        }
    });

    $(document).on('change', '.alvara-situacao', function () {
        const campoVencimento = document.getElementById(this.dataset.vencimento);
        const possuiVencimento = this.value === 'com_vencimento';

        campoVencimento.disabled = !possuiVencimento;
        campoVencimento.required = possuiVencimento;

        if (!possuiVencimento) {
            campoVencimento.value = '';
            campoVencimento.classList.remove('is-invalid');
        }
    });

    $(document).on('change', '.controle-com-vencimento', function () {
        atualizarCampoVencimentoControle(this);
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

});

function atualizarCampoVencimentoControle(campoSituacao) {
    const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);
    const possui = campoSituacao.value === 'possui';

    campoVencimento.disabled = !possui;
    campoVencimento.required = possui;

    if (!possui) {
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

    $.getJSON(`api.php?action=read&page=${page}&limit=${limitePorPagina}`, function (res) {
        let linhas = '';
        const clientes = Array.isArray(res) ? res : (res.data || []);

        if (clientes.length === 0) {
            $('#clientesTable tbody').html(`
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        Nenhum cliente cadastrado ainda.
                    </td>
                </tr>
            `);

            $('#paginacao').html('');
            return;
        }

        clientes.forEach(cliente => {
            linhas += `
<tr class="linha-cliente"
    data-busca="${escapeHtml(`${cliente.codigo} ${cliente.documento} ${cliente.nome} ${cliente.nome_fantasia} ${cliente.email}`).toLowerCase()}"
    data-uf="${escapeHtml(cliente.uf)}"
    onclick="window.location.href='cliente.php?id=${cliente.id}'">

    <td>${escapeHtml(cliente.codigo)}</td>
    <td>${escapeHtml(cliente.documento)}</td>
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
        ('Erro ao carregar clientes: ' + xhr.responseText);
    });
}

function renderizarPaginacao(total, pagina, limite) {
    let totalPaginas = Math.ceil(total / limite);
    let html = '';

    if (totalPaginas <= 1) {
        $('#paginacao').html('');
        return;
    }

    html += `<nav><ul class="pagination justify-content-center mt-3">`;

    html += `
        <li class="page-item ${pagina <= 1 ? 'disabled' : ''}">
            <button class="page-link" onclick="carregarClientes(${pagina - 1})">Anterior</button>
        </li>
    `;

    for (let i = 1; i <= totalPaginas; i++) {
        html += `
            <li class="page-item ${i === pagina ? 'active' : ''}">
                <button class="page-link" onclick="carregarClientes(${i})">${i}</button>
            </li>
        `;
    }

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

function excluirCliente(id) {
    clienteParaExcluir = id;

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
    validarObrigatorio('#documento');
    validarObrigatorio('#nome');
    validarObrigatorio('#cep');
    validarObrigatorio('#numero_endereco');
    validarObrigatorio('#telefone');
    validarObrigatorio('#email');

    const documento = $('#documento').val().replace(/\D/g, '');
    const telefone = $('#telefone').val().replace(/\D/g, '');
    const email = $('#email').val().trim();

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

    if (!validarCampoInscricaoEstadual()) {
        if (primeiroCampoInvalido === null) {
            primeiroCampoInvalido = '#inscricao_estadual';
        }

        valido = false;
    }

    document.querySelectorAll('.procuracao-obrigatoria').forEach(function (campo) {
        if (campo.value === '') {
            campo.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campo.id;
            }

            valido = false;
        }
    });

    document.querySelectorAll('.controle-interno-obrigatorio').forEach(function (campo) {
        if (campo.value === '') {
            campo.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campo.id;
            }

            valido = false;
        }
    });

    document.querySelectorAll('.controle-com-vencimento').forEach(function (campoSituacao) {
        const campoVencimento = document.getElementById(campoSituacao.dataset.vencimento);

        if (campoSituacao.value === 'possui' && campoVencimento.value === '') {
            campoVencimento.classList.add('is-invalid');

            if (primeiroCampoInvalido === null) {
                primeiroCampoInvalido = '#' + campoVencimento.id;
            }

            valido = false;
        }
    });

    const alvarasValidos = $('#alvara').val() !== 'possui'
        || validarPreenchimentoAlvaras();

    if (!alvarasValidos) {
        valido = false;
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlvaras'));
        modal.show();
        return false;
    }

    if (!valido && primeiroCampoInvalido !== null) {
        $(primeiroCampoInvalido).focus();
    }

    return valido;
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

    botao.prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Excluindo...');

    $.post('api.php?action=delete', { id: clienteParaExcluir }, function (resp) {

        if (resp.trim() === 'ok') {
            window.location.href = 'clientes.php';
        } else {
            mostrarAviso(resp);
        }

    }).fail(function (xhr) {
        mostrarAviso('Erro: ' + xhr.responseText);
    }).always(function () {

        botao.prop('disabled', false)
            .html('<i class="bi bi-trash"></i> Sim, excluir');

        const modalEl = document.getElementById('modalConfirmarExclusao');
        const modal = bootstrap.Modal.getInstance(modalEl);

        if (modal) modal.hide();
    });
});