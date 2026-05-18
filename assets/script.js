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
                resp = resp.trim();

                if (resp === 'ok') {
                    $('#clienteForm')[0].reset();
                    $('#id').val('');

                    const modalEl = document.getElementById('clienteModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();

                    if (window.location.pathname.includes('cliente.php')) {
                        window.location.reload();
                    } else {
                        carregarClientes(paginaAtual);
                    }

                } else if (resp === 'duplicado') {
                    mostrarAviso('Já existe um cliente cadastrado com este CPF/CNPJ.', '#documento');
                } else {
                    mostrarAviso(resp);
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

});

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
    email
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

$('#btnConfirmarExclusao').on('click', function () {
    if (!clienteParaExcluir) return;

    const botao = $(this);

    botao.prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Excluindo...');

    $.post('api.php?action=delete', { id: clienteParaExcluir }, function (resp) {
        if (resp.trim() === 'ok') {
            window.location.href = 'index.php';
        } else {
            mostrarAviso(resp);
        }
    }).fail(function (xhr) {
        mostrarAviso('Erro: ' + xhr.responseText);
    }).always(function () {
        botao.prop('disabled', false).html('Excluir');

        const modal = bootstrap.Modal.getInstance(document.getElementById('modalConfirmacao'));
        modal.hide();
    });
});

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

document.getElementById('clienteModal').addEventListener('hidden.bs.modal', function () {
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style = "";
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

    botao.prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Excluindo...');

    $.post('api.php?action=delete', { id: clienteParaExcluir }, function (resp) {

        if (resp.trim() === 'ok') {
            window.location.href = 'index.php';
        } else {
            mostrarAviso(resp);
        }

    }).fail(function (xhr) {
        mostrarAviso('Erro: ' + xhr.responseText);
    }).always(function () {

        botao.prop('disabled', false).html('Excluir');

        const modalEl = document.getElementById('modalConfirmarExclusao');
        const modal = bootstrap.Modal.getInstance(modalEl);

        if (modal) modal.hide();
    });
});