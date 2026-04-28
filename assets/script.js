let paginaAtual = 1;
let limitePorPagina = 10;

$(document).ready(function () {
    aplicarMascaras();
    carregarClientes();

    $('#cep').on('blur', function () {
        let cep = $(this).val().replace(/\D/g, '');
        let feedback = $('#cepFeedback');

        $('#cep').removeClass('is-invalid is-valid');

        if (cep.length !== 8) {
            feedback.text('CEP deve ter 8 números').addClass('text-danger').removeClass('text-success');
            $('#cep').addClass('is-invalid');
            return;
        }

        feedback.text('Consultando CEP...').removeClass('text-danger text-success').addClass('text-muted');

        $.getJSON(`https://viacep.com.br/ws/${cep}/json/`, function (dados) {

            if (!dados.erro) {
                $('#endereco').val(dados.logradouro || '');
                $('#bairro').val(dados.bairro || '');
                $('#cidade').val(dados.localidade || '');
                $('#uf').val((dados.uf || '').toUpperCase());

                feedback.text('CEP encontrado').removeClass('text-danger').addClass('text-success');
                $('#cep').addClass('is-valid');

            } else {
                feedback.text('CEP não encontrado').removeClass('text-success').addClass('text-danger');
                $('#cep').addClass('is-invalid');
            }

        }).fail(function () {
            feedback.text('Erro ao consultar CEP').removeClass('text-success').addClass('text-danger');
            $('#cep').addClass('is-invalid');
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

    $('#documento').on('input', function () {
        let value = $(this).val().replace(/\D/g, '');

        if (value.length <= 11) {
            $(this).mask('000.000.000-00');
        } else {
            $(this).mask('00.000.000/0000-00');
        }
    });

    $('#cep').mask('00000-000');
    $('#telefone').mask('(00) 00000-0000');
});

function carregarClientes(page = 1) {
    paginaAtual = page;

    $.getJSON(`api.php?action=read&page=${page}&limit=${limitePorPagina}`, function (res) {
        let linhas = '';
        const clientes = res.data || [];

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
    aplicarMascaras();

    const modal = new bootstrap.Modal(document.getElementById('clienteModal'));
    modal.show();
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
    const codigo = $('#codigo').val().trim();
    const documento = $('#documento').val().replace(/\D/g, '');
    const nome = $('#nome').val().trim();
    const uf = $('#uf').val().trim();

    if (codigo === '') {
        alert('Informe o código.');
        return false;
    }

    if (documento === '') {
        alert('Informe o CPF/CNPJ.');
        return false;
    }

    if (!validarCpfOuCnpj(documento)) {
        alert('CPF ou CNPJ inválido.');
        return false;
    }

    if (nome === '') {
        alert('Informe o nome / razão social.');
        return false;
    }

    if (uf !== '' && uf.length !== 2) {
        alert('UF deve ter 2 letras.');
        return false;
    }

    return true;
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

    const documento = $('#documento').val().replace(/\D/g, '');
    if (documento.length <= 11) {
        $('#documento').mask('000.000.000-00');
    } else {
        $('#documento').mask('00.000.000/0000-00');
    }
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
$('#cep').on('blur', function () {
    let cep = $(this).val().replace(/\D/g, '');

    if (cep.length !== 8) {
        return;
    }

    $.getJSON(`https://viacep.com.br/ws/${cep}/json/`, function (dados) {

        if (!dados.erro) {
            $('#endereco').val(dados.logradouro || '');
            $('#bairro').val(dados.bairro || '');
            $('#cidade').val(dados.localidade || '');
            $('#uf').val((dados.uf || '').toUpperCase());

            feedback.text('CEP encontrado').removeClass('text-danger').addClass('text-success');
            $('#cep').addClass('is-valid');

        } else {
            feedback.text('CEP não encontrado').removeClass('text-success').addClass('text-danger');
            $('#cep').addClass('is-invalid');
        }

    }).fail(function () {
        feedback.text('Erro ao consultar CEP').removeClass('text-success').addClass('text-danger');
        $('#cep').addClass('is-invalid');
    });
});