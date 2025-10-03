$(document).ready(function () {
    carregarClientes();

    $('#clienteForm').on('submit', function (e) {
        e.preventDefault();
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
                    var modalEl = document.getElementById('clienteModal');
                    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();
                    carregarClientes();
                } else {
                    alert(resp);
                }
            },
            error: function (xhr) {
                alert('Erro: ' + xhr.responseText);
            }
        });
    });
});

function carregarClientes() {
    $.getJSON('api.php?action=read', function (data) {
        let linhas = '';
        $.each(data, function (i, cliente) {
            const nome = (cliente.nome || '').replace(/'/g, "\\'");
            const email = (cliente.email || '').replace(/'/g, "\\'");
            const telefone = (cliente.telefone || '').replace(/'/g, "\\'");
            const numero_empresa = (cliente.numero_empresa || '').replace(/'/g, "\\'");
            const cnpj = (cliente.cnpj || '').replace(/'/g, "\\'");

            linhas += `
                <tr>
                    <td>${nome}</td>
                    <td>${email}</td>
                    <td>${telefone}</td>
                    <td>${numero_empresa}</td>
                    <td>${cnpj}</td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="abrirModalEditar(${cliente.id}, '${nome}', '${email}', '${telefone}', '${numero_empresa}', '${cnpj}')">Editar</button>
                        <button class="btn btn-danger btn-sm" onclick="excluirCliente(${cliente.id})">Excluir</button>
                    </td>
                </tr>`;
        });
        $('#clientesTable tbody').html(linhas);
    });
}

function abrirModalNovo() {
    $('#clienteModalLabel').text('Novo Cliente');
    $('#clienteForm')[0].reset();
    $('#id').val('');
    var modal = new bootstrap.Modal(document.getElementById('clienteModal'));
    modal.show();
}

function abrirModalEditar(id, nome, email, telefone, numero_empresa, cnpj) {
    $('#clienteModalLabel').text('Editar Cliente');
    $('#id').val(id);
    $('#nome').val(nome);
    $('#email').val(email);
    $('#telefone').val(telefone);
    $('#numero_empresa').val(numero_empresa);
    $('#cnpj').val(cnpj);
    var modal = new bootstrap.Modal(document.getElementById('clienteModal'));
    modal.show();
}

function excluirCliente(id) {
    if (confirm("Tem certeza que deseja excluir este cliente?")) {
        $.post('api.php?action=delete', { id: id }, function (resp) {
            if (resp.trim() === 'ok') {
                carregarClientes();
            } else {
                alert(resp);
            }
        }).fail(function (xhr) { alert('Erro: ' + xhr.responseText); });
    }
}

// Garantir que backdrop some ao fechar
document.getElementById('clienteModal').addEventListener('hidden.bs.modal', function () {
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style = "";
});
