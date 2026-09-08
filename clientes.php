<?php
require 'config.php';

exigirPermissao('clientes');

if (strcasecmp(trim(empresaAtivaNome($pdo)), 'MAXWELL') === 0) {
    header('Location: servicos_avulsos.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Clientes</title>
</head>

<body class="app-layout clientes-page">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="logi-page-header">
                <div>
                    <h3 class="mb-1">Clientes</h3>
                    <p class="text-muted mb-0">Cadastro e gerenciamento de clientes</p>
                </div>

                <div class="logi-page-actions">
                    <div class="dropdown">
                        <button
                            type="button"
                            class="btn btn-outline-secondary logi-icon-button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Mais opções"
                            title="Mais opções">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a href="clientes_devolvidos.php" class="dropdown-item">
                                    <i class="bi bi-archive"></i> Clientes devolvidos
                                </a>
                            </li>
                            <li>
                                <a href="servicos_avulsos.php" class="dropdown-item">
                                    <i class="bi bi-briefcase"></i> Serviços avulsos
                                </a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <button type="button" class="dropdown-item" id="btnImprimirClientes">
                                    <i class="bi bi-printer"></i> Imprimir lista
                                </button>
                            </li>
                        </ul>
                    </div>
                    <a href="clientes_importar.php" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Importar
                    </a>
                    <a href="cliente_novo.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Novo Cliente
                    </a>
                </div>
            </div>

            <div class="clientes-box">
                <div class="clientes-toolbar">
                    <div class="clientes-busca">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="buscaCliente"
                            class="form-control"
                            placeholder="Buscar por código, nome, CPF/CNPJ ou e-mail...">
                    </div>

                    <select id="filtroUf" class="form-select" aria-label="Filtrar por estado">
                        <option value="">Todas as UFs</option>
                        <option value="DF">DF</option>
                        <option value="GO">GO</option>
                    </select>

                    <select id="limiteClientes" class="form-select" aria-label="Quantidade por página">
                        <option value="15">Mostrar 15</option>
                        <option value="30">Mostrar 30</option>
                        <option value="60">Mostrar 60</option>
                        <option value="90">Mostrar 90</option>
                    </select>

                    <span class="clientes-result-count" id="totalClientesResumo">Nenhum cliente</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle" id="clientesTable">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CPF/CNPJ</th>
                                <th>Razão Social</th>
                                <th>Nome Fantasia</th>
                                <th>Cidade</th>
                                <th>UF</th>
                                <th>Telefone</th>
                                <th>E-mail</th>
                                <th><span class="visually-hidden">Abrir</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    Nenhum cliente cadastrado ainda.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacao"></div>
            </div>

        </div>
    </main>

    <aside
        class="offcanvas offcanvas-end clientes-detalhes"
        tabindex="-1"
        id="painelCliente"
        data-bs-backdrop="false"
        data-bs-scroll="true"
        aria-labelledby="painelClienteTitulo">
        <div class="offcanvas-header">
            <div>
                <h5 class="offcanvas-title" id="painelClienteTitulo">Cliente</h5>
                <p class="clientes-detalhes-subtitulo" id="painelClienteSubtitulo"></p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
        </div>
        <div class="offcanvas-body">
            <div class="clientes-detalhes-status">
                <span>Situação do cadastro</span>
                <span class="badge bg-success" id="painelClienteSituacao">Ativo</span>
            </div>

            <section class="clientes-detalhes-secao" aria-labelledby="painelClienteDadosTitulo">
                <h6 id="painelClienteDadosTitulo">Dados principais</h6>
                <div class="clientes-detalhes-grade">
                    <div class="clientes-detalhes-campo"><span>Nome fantasia</span><strong id="painelClienteFantasia">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>Tributação</span><strong id="painelClienteTributacao">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>Cidade / UF</span><strong id="painelClienteCidade">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>Telefone</span><strong id="painelClienteTelefone">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>E-mail</span><strong id="painelClienteEmail">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>Inscrição estadual</span><strong id="painelClienteInscricao">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>NIRE</span><strong id="painelClienteNire">-</strong></div>
                    <div class="clientes-detalhes-campo"><span>CEP</span><strong id="painelClienteCep">-</strong></div>
                    <div class="clientes-detalhes-campo clientes-detalhes-campo-largo"><span>Endereço</span><strong id="painelClienteEndereco">-</strong></div>
                </div>
            </section>

            <section class="clientes-detalhes-secao" aria-labelledby="painelClienteAcompanhamentosTitulo">
                <h6 id="painelClienteAcompanhamentosTitulo">Acompanhamentos</h6>
                <div class="clientes-acompanhamentos" id="painelClienteAcompanhamentos"></div>
            </section>

            <section class="clientes-detalhes-secao" aria-labelledby="painelClienteProcuracoesTitulo">
                <h6 id="painelClienteProcuracoesTitulo">Procurações</h6>
                <div class="clientes-acompanhamentos" id="painelClienteProcuracoes"></div>
            </section>
        </div>
        <div class="offcanvas-footer">
            <a href="#" class="btn btn-outline-primary" id="painelClienteEditar">
                <i class="bi bi-pencil"></i> Editar
            </a>
            <a href="#" class="btn btn-primary" id="painelClienteAbrir">
                Ver cadastro completo <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </aside>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

    <script>
        document.getElementById('btnImprimirClientes').addEventListener('click', function() {
            const busca = document.getElementById('buscaCliente').value.trim();
            const uf = document.getElementById('filtroUf').value;
            const janela = window.open('', '_blank', 'width=1100,height=750');

            if (!janela) {
                return;
            }

            janela.document.write('<p style="font-family:Arial;padding:24px">Preparando lista de clientes...</p>');

            const parametros = new URLSearchParams({
                action: 'print_clientes',
                busca: busca,
                uf: uf
            });

            fetch('api.php?' + parametros.toString())
                .then(function(resposta) {
                    if (!resposta.ok) {
                        throw new Error('Falha ao carregar clientes.');
                    }

                    return resposta.json();
                })
                .then(function(resposta) {
                    const clientes = resposta.data || [];
                    const escapar = function(valor) {
                        const elemento = document.createElement('div');
                        elemento.textContent = valor || '';
                        return elemento.innerHTML;
                    };
                    const filtroTexto = [
                        busca !== '' ? 'Busca: ' + busca : '',
                        uf !== '' ? 'UF: ' + uf : ''
                    ].filter(Boolean).join(' | ') || 'Todos os clientes contábeis';
                    const linhas = clientes.map(function(cliente) {
                        return '<tr>' +
                            '<td>' + escapar(cliente.codigo) + '</td>' +
                            '<td>' + escapar(cliente.documento) + '</td>' +
                            '<td>' + escapar(cliente.nome) + '</td>' +
                            '<td>' + escapar(cliente.nome_fantasia) + '</td>' +
                            '<td>' + escapar(cliente.cidade) + '/' + escapar(cliente.uf) + '</td>' +
                            '<td>' + escapar(cliente.telefone) + '</td>' +
                            '<td>' + escapar(cliente.email) + '</td>' +
                            '</tr>';
                    }).join('');

                    janela.document.open();
                    janela.document.write(
                        '<!doctype html><html><head><meta charset="utf-8"><title>Lista de clientes</title>' +
                        '<style>@page{size:A4 landscape;margin:10mm}body{font-family:Arial,sans-serif;color:#111827;margin:0}' +
                        'h1{font-size:20px;margin:0 0 5px}p{color:#4b5563;margin:0 0 16px;font-size:12px}' +
                        'table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:6px;border:1px solid #cbd5e1;text-align:left}' +
                        'th{background:#e2e8f0;font-size:8px;text-transform:uppercase}tr{break-inside:avoid}</style></head><body>' +
                        '<h1>Lista de clientes</h1><p>' + escapar(filtroTexto) + ' | Total: ' + clientes.length + '</p>' +
                        '<table><thead><tr><th>Código</th><th>CPF/CNPJ</th><th>Razão Social</th><th>Nome Fantasia</th>' +
                        '<th>Cidade/UF</th><th>Telefone</th><th>E-mail</th></tr></thead><tbody>' +
                        (linhas || '<tr><td colspan="7">Nenhum cliente encontrado.</td></tr>') +
                        '</tbody></table><script>window.onload=function(){window.print();window.onafterprint=function(){window.close()}};<\/script>' +
                        '</body></html>'
                    );
                    janela.document.close();
                })
                .catch(function() {
                    janela.close();
                    mostrarAviso('Não foi possível preparar a lista para impressão.');
                });
        });
    </script>

    <?php include 'includes/modal_confirmar.php'; ?>

</body>

</html>