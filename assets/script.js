let paginaAtual = 1;
let limitePorPagina = 10;

$(document).ready(function () {
    aplicarMascaras();
    carregarClientes();

    $('#cep').on('blur', function () {
        let cep = $(this).val().replace(/\D/g, '');
        let feedback = $('#cepFeedback');

        feedback.text('').removeClass('text-danger text-success text-muted');

        if (cep.length === 0) {
            return;
        }

        if (cep.length !== 8) {
            feedback.text('CEP deve ter 8 números.').addClass('text-danger');
            return;
        }

        feedback.text('Consultando CEP...').addClass('text-muted');

        $.getJSON(`https://viacep.com.br/ws/${cep}/json/`, function (dados) {
            feedback.text('').removeClass('text-danger text-success text-muted');

            if (!dados.erro) {
                $('#endereco').val(dados.logradouro || '');
                $('#bairro').val(dados.bairro || '');
                $('#cidade').val(dados.localidade || '');
                $('#uf').val((dados.uf || '').toUpperCase());
            } else {
                feedback.text('CEP não encontrado.').addClass('text-danger');
            }
        }).fail(function () {
            feedback.text('Erro ao consultar CEP.').addClass('text-danger');
        });
    });

    $('#clienteForm').on('submit', function (e) {
        e.preventDefault();

        if (!validarFormulario()) {
            return;
        }

        let id = $('#id').val();
        let action = id === '' ? 'create' : 'update';

        $.ajax({
            url: 'api.php?action=' + action,
            method: 'POST',
            data: $(this).serialize(),
            success: function (resp) {
                if (resp.trim() === 'ok') {
                    $('#clienteForm')[0].reset();
                    $('#id').val('');

                    const modalEl = document.getElementById('clienteModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();

                    carregarClientes(paginaAtual);
                } else {
                    alert(resp);
                }
            },
            error: function (xhr) {
                alert('Erro: ' + xhr.responseText);
            }
        });
    });

    $('#uf').on('input', function () {
        this.value = this.value.replace(/[^a-zA-Z]/g, '').toUpperCase().slice(0, 2);
    });

    $('#codigo').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });

    $('#numero_endereco').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });
});

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
                <tr>
                    <td>${escapeHtml(cliente.codigo)}</td>
                    <td>${escapeHtml(cliente.documento)}</td>
                    <td>${escapeHtml(cliente.nome)}</td>
                    <td>${escapeHtml(cliente.nome_fantasia)}</td>
                    <td>${escapeHtml(cliente.cidade)}</td>
                    <td>${escapeHtml(cliente.uf)}</td>
                    <td>${escapeHtml(cliente.telefone)}</td>
                    <td>${escapeHtml(cliente.email)}</td>
                    <td>
                        <button class="btn btn-warning btn-sm me-1" onclick="abrirModalEditar(
                            ${cliente.id},
                            ${jsString(cliente.codigo)},
                            ${jsString(cliente.documento)},
                            ${jsString(cliente.nome)},
                            ${jsString(cliente.nome_fantasia)},
                            ${jsString(cliente.endereco)},
                            ${jsString(cliente.numero_endereco)},
                            ${jsString(cliente.complemento)},
                            ${jsString(cliente.bairro)},
                            ${jsString(cliente.cidade)},
                            ${jsString(cliente.uf)},
                            ${jsString(cliente.cep)},
                            ${jsString(cliente.telefone)},
                            ${jsString(cliente.inscricao_estadual)},
                            ${jsString(cliente.nire)},
                            ${jsString(cliente.email)}
                        )">Editar</button>
                        <button class="btn btn-danger btn-sm" onclick="excluirCliente(${cliente.id})">Excluir</button>
                    </td>
                </tr>
            `;
        });

        $('#clientesTable tbody').html(linhas);
        renderizarPaginacao(res.total || 0, res.page || 1, res.limit || limitePorPagina);
    }).fail(function (xhr) {
        alert('Erro ao carregar clientes: ' + xhr.responseText);
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
    if (confirm("Tem certeza que deseja excluir este cliente?")) {
        $.post('api.php?action=delete', { id: id }, function (resp) {
            if (resp.trim() === 'ok') {
                carregarClientes(paginaAtual);
            } else {
                alert(resp);
            }
        }).fail(function (xhr) {
            alert('Erro: ' + xhr.responseText);
        });
    }
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
    validarObrigatorio('#endereco');
    validarObrigatorio('#numero_endereco');
    validarObrigatorio('#bairro');
    validarObrigatorio('#cidade');
    validarObrigatorio('#uf');
    validarObrigatorio('#telefone');
    validarObrigatorio('#email');

    const documento = $('#documento').val().replace(/\D/g, '');
    const telefone = $('#telefone').val().replace(/\D/g, '');
    const email = $('#email').val().trim();

    if (documento !== '' && !validarCpfOuCnpj(documento)) {
        $('#documento').addClass('is-invalid');

        if (primeiroCampoInvalido === null) {
            primeiroCampoInvalido = '#documento';
        }

        valido = false;
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

$('#numero_endereco').on('input', function () {
    this.value = this.value.replace(/\D/g, '');
});

function marcarInvalido(campo) {
    $(campo).addClass('is-invalid').focus();
}

function limparValidacoes() {
    $('.is-invalid').removeClass('is-invalid');
    $('.is-valid').removeClass('is-valid');

    $('#cepFeedback')
        .text('')
        .removeClass('text-danger text-success text-muted');
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