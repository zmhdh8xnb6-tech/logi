<?php
require 'config.php';

exigirPermissao('clientes');

$clientesDevolvidos = [];
$situacaoDisponivel = clientesSituacaoDisponivel($pdo);

if ($situacaoDisponivel) {
    $stmt = $pdo->query("
        SELECT id, codigo, documento, nome, nome_fantasia, cidade, uf, telefone, email, situacao_cliente, devolvido_em, motivo_devolucao
        FROM clientes
        WHERE 1 = 1
        " . clientesFiltroDevolvidos($pdo) . "
        " . empresaFiltroClienteDireto($pdo) . "
        ORDER BY devolvido_em DESC, CAST(codigo AS UNSIGNED) ASC, nome ASC
    ");
    $clientesDevolvidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Clientes Devolvidos</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Clientes devolvidos</h3>
                    <p class="text-muted mb-0">Cadastros retirados da rotina, mas guardados para reativação</p>
                </div>

                <a href="clientes.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar para clientes
                </a>
            </div>

            <?php if (!$situacaoDisponivel): ?>
                <div class="alert alert-warning">
                    Execute o SQL de clientes devolvidos para ativar essa tela.
                </div>
            <?php endif; ?>

            <div class="clientes-box">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input
                            type="text"
                            id="buscaClienteDevolvido"
                            class="form-control"
                            placeholder="Buscar por código, nome, CPF/CNPJ ou e-mail...">
                    </div>

                    <div class="col-md-3 ms-md-auto" id="grupoLimiteClientesDevolvidos">
                        <select id="limiteClientesDevolvidos" class="form-select">
                            <option value="15">Mostrar 15</option>
                            <option value="30">Mostrar 30</option>
                            <option value="60">Mostrar 60</option>
                            <option value="90">Mostrar 90</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CPF/CNPJ</th>
                                <th>Razão Social</th>
                                <th>Nome Fantasia</th>
                                <th>Cidade/UF</th>
                                <th>Devolvido em</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientesDevolvidos)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        Nenhum cliente devolvido.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clientesDevolvidos as $cliente): ?>
                                    <tr
                                        class="linha-cliente-devolvido"
                                        data-busca="<?= htmlspecialchars(strtolower(implode(' ', [
                                                        $cliente['codigo'] ?? '',
                                                        $cliente['documento'] ?? '',
                                                        $cliente['nome'] ?? '',
                                                        $cliente['nome_fantasia'] ?? '',
                                                        $cliente['email'] ?? '',
                                                    ]))) ?>">
                                        <td><?= htmlspecialchars($cliente['codigo']) ?></td>
                                        <td><?= htmlspecialchars($cliente['documento']) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($cliente['nome']) ?></strong>
                                            <?php if (($cliente['situacao_cliente'] ?? '') === 'baixado'): ?>
                                                <span class="badge bg-danger ms-2">Baixado</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark ms-2">Devolvido</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($cliente['nome_fantasia']) ?></td>
                                        <td><?= htmlspecialchars(trim(($cliente['cidade'] ?? '') . '/' . ($cliente['uf'] ?? ''), '/')) ?></td>
                                        <td>
                                            <?= !empty($cliente['devolvido_em'])
                                                ? date('d/m/Y H:i', strtotime($cliente['devolvido_em']))
                                                : '-' ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="cliente.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-outline-primary btn-sm" title="Visualizar">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (($cliente['situacao_cliente'] ?? '') !== 'baixado'): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-success btn-sm btn-reativar-cliente"
                                                        data-id="<?= (int)$cliente['id'] ?>"
                                                        data-cliente="<?= htmlspecialchars(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? '')) ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalReativarCliente">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="CNPJ baixado não reativa">
                                                        <i class="bi bi-lock"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm btn-excluir-definitivo"
                                                    data-id="<?= (int)$cliente['id'] ?>"
                                                    data-cliente="<?= htmlspecialchars(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? '')) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirClienteDefinitivo">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <tr id="clientesDevolvidosVazio" class="d-none">
                                <td colspan="7" class="text-center text-muted py-4">
                                    Nenhum cliente devolvido encontrado.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacaoClientesDevolvidos"></div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalReativarCliente" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reativar cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="clienteReativarId">
                    <p class="mb-1">Deseja reativar este cliente?</p>
                    <strong id="clienteReativarNome"></strong>
                    <small class="text-muted d-block mt-2">
                        Ele voltará para a lista principal e para os controles do sistema.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarReativarCliente">
                        <i class="bi bi-arrow-counterclockwise"></i> Reativar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalExcluirClienteDefinitivo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Excluir cliente definitivamente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="clienteExcluirDefinitivoId">
                    <p class="mb-1">Tem certeza que deseja excluir este cliente definitivamente?</p>
                    <strong id="clienteExcluirDefinitivoNome"></strong>
                    <small class="text-danger d-block mt-2">
                        Essa ação apaga o cadastro e não pode ser desfeita.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarExcluirDefinitivo">
                        <i class="bi bi-trash"></i> Excluir definitivamente
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let clientesDevolvidosPorPagina = Number(localStorage.getItem('clientesDevolvidosPorPagina') || 15);
        clientesDevolvidosPorPagina = [15, 30, 60, 90].includes(clientesDevolvidosPorPagina) ? clientesDevolvidosPorPagina : 15;
        let clientesDevolvidosPaginaAtual = 1;
        const buscaClienteDevolvido = document.getElementById('buscaClienteDevolvido');
        const limiteClientesDevolvidos = document.getElementById('limiteClientesDevolvidos');
        const linhasClientesDevolvidos = Array.from(document.querySelectorAll('.linha-cliente-devolvido'));
        const paginacaoClientesDevolvidos = document.getElementById('paginacaoClientesDevolvidos');
        const clientesDevolvidosVazio = document.getElementById('clientesDevolvidosVazio');

        limiteClientesDevolvidos.value = String(clientesDevolvidosPorPagina);

        function clientesDevolvidosFiltrados() {
            const busca = buscaClienteDevolvido.value.toLocaleLowerCase('pt-BR').trim();

            return linhasClientesDevolvidos.filter(function(linha) {
                return (linha.dataset.busca || '').includes(busca);
            });
        }

        function adicionarPaginaClientesDevolvidos(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                clientesDevolvidosPaginaAtual = pagina;
                renderizarClientesDevolvidos();
            });

            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarClientesDevolvidos() {
            const filtrados = clientesDevolvidosFiltrados();
            const totalPaginas = Math.max(1, Math.ceil(filtrados.length / clientesDevolvidosPorPagina));

            if (clientesDevolvidosPaginaAtual > totalPaginas) {
                clientesDevolvidosPaginaAtual = totalPaginas;
            }

            const inicio = (clientesDevolvidosPaginaAtual - 1) * clientesDevolvidosPorPagina;
            const visiveis = new Set(filtrados.slice(inicio, inicio + clientesDevolvidosPorPagina));

            linhasClientesDevolvidos.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            clientesDevolvidosVazio.classList.toggle('d-none', filtrados.length > 0);
            paginacaoClientesDevolvidos.innerHTML = '';
            document.getElementById('grupoLimiteClientesDevolvidos')?.classList.toggle('d-none', filtrados.length <= clientesDevolvidosPorPagina);

            if (filtrados.length <= clientesDevolvidosPorPagina) {
                return;
            }

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            adicionarPaginaClientesDevolvidos(lista, 'Anterior', Math.max(1, clientesDevolvidosPaginaAtual - 1), clientesDevolvidosPaginaAtual <= 1, false);

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - clientesDevolvidosPaginaAtual) <= 2) {
                    adicionarPaginaClientesDevolvidos(lista, String(pagina), pagina, false, pagina === clientesDevolvidosPaginaAtual);
                }
            }

            adicionarPaginaClientesDevolvidos(lista, 'Próxima', Math.min(totalPaginas, clientesDevolvidosPaginaAtual + 1), clientesDevolvidosPaginaAtual >= totalPaginas, false);
            nav.appendChild(lista);
            paginacaoClientesDevolvidos.appendChild(nav);
        }

        buscaClienteDevolvido.addEventListener('input', function() {
            clientesDevolvidosPaginaAtual = 1;
            renderizarClientesDevolvidos();
        });

        limiteClientesDevolvidos.addEventListener('change', function() {
            clientesDevolvidosPorPagina = Number(this.value);
            localStorage.setItem('clientesDevolvidosPorPagina', String(clientesDevolvidosPorPagina));
            clientesDevolvidosPaginaAtual = 1;
            renderizarClientesDevolvidos();
        });

        document.getElementById('modalReativarCliente').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;
            document.getElementById('clienteReativarId').value = botao.dataset.id;
            document.getElementById('clienteReativarNome').textContent = botao.dataset.cliente;
        });

        document.getElementById('modalExcluirClienteDefinitivo').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;
            document.getElementById('clienteExcluirDefinitivoId').value = botao.dataset.id;
            document.getElementById('clienteExcluirDefinitivoNome').textContent = botao.dataset.cliente;
        });

        document.getElementById('btnConfirmarReativarCliente').addEventListener('click', function() {
            const botao = this;
            const id = document.getElementById('clienteReativarId').value;

            botao.disabled = true;
            botao.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Reativando...';

            fetch('api.php?action=reativar_cliente', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id)
                })
                .then(function(resposta) {
                    return resposta.text();
                })
                .then(function(texto) {
                    if (texto.trim() === 'ok') {
                        window.location.reload();
                        return;
                    }

                    alert(texto);
                })
                .finally(function() {
                    botao.disabled = false;
                    botao.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Reativar';
                });
        });

        document.getElementById('btnConfirmarExcluirDefinitivo').addEventListener('click', function() {
            const botao = this;
            const id = document.getElementById('clienteExcluirDefinitivoId').value;

            botao.disabled = true;
            botao.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Excluindo...';

            fetch('api.php?action=delete_permanente', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id)
                })
                .then(function(resposta) {
                    return resposta.text();
                })
                .then(function(texto) {
                    if (texto.trim() === 'ok') {
                        window.location.reload();
                        return;
                    }

                    alert(texto);
                })
                .finally(function() {
                    botao.disabled = false;
                    botao.innerHTML = '<i class="bi bi-trash"></i> Excluir definitivamente';
                });
        });

        renderizarClientesDevolvidos();
    </script>
</body>

</html>