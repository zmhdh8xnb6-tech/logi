<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

$parcelamentos = buscarParcelamentosPorOrgao($pdo, $orgaoLiquidado, false, true);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Parcelamentos Liquidados</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('assets/parcelamentos.css') ?>">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($tituloLiquidados) ?></h3>
                    <p class="text-muted mb-0">Consulte os parcelamentos já liquidados</p>
                </div>

                <a href="<?= htmlspecialchars($urlAtivos) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar para ativos
                </a>
            </div>

            <?php if (isset($_GET['quitado'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Parcelamento quitado antecipadamente com sucesso.
                </div>
            <?php endif; ?>

            <div class="parcelamento-box">
                <div class="cabecalho-lista d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Parcelamentos Liquidados</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="Imprimir dados">
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                </div>

                <div class="orgao-impressao">Órgão: <?= htmlspecialchars($orgaoLiquidado) ?> - Liquidados</div>

                <div class="row mb-3 busca-parcelamentos">
                    <div class="col-md-6">
                        <input type="search" class="form-control" id="buscaParcelamento" placeholder="Buscar por código, cliente, número ou status...">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>CPF/CNPJ</th>
                                <th>Órgão</th>
                                <th class="text-end">Número</th>
                                <th class="text-end">Forma envio</th>
                                <th class="text-end">Parcelas</th>
                                <th class="text-end">Atrasadas</th>
                                <th>Status</th>
                                <th class="text-end coluna-acoes">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php renderizarLinhasParcelamentos($parcelamentos, true, false, true); ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <div class="modal fade" id="modalVoltarLiquidado" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Voltar parcelamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1">Deseja devolver este parcelamento para a lista de ativos?</p>
                    <p class="text-muted small mb-2">Ele voltará como atrasado para não liquidar automaticamente de novo.</p>
                    <strong id="clienteParcelamentoVoltar"></strong>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                    <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/parcelamento_voltar_liquidado.php">
                        <input type="hidden" name="id" id="parcelamentoIdVoltar">
                        <button type="submit" class="btn btn-warning">Sim, voltar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.getElementById('modalVoltarLiquidado').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;

            document.getElementById('parcelamentoIdVoltar').value = botao.dataset.parcelamentoId;
            document.getElementById('clienteParcelamentoVoltar').textContent = botao.dataset.cliente;
        });

        setTimeout(function() {
            document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                alerta.classList.remove('show');
                setTimeout(function() {
                    alerta.remove();
                }, 200);
            });
        }, 4000);
    </script>

    <?php renderizarModalDetalhesParcelamento(); ?>

</body>

</html>