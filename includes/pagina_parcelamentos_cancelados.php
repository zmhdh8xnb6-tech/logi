<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

$parcelamentos = buscarParcelamentosPorOrgao($pdo, $orgaoCancelado, true);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Parcelamentos Cancelados</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('assets/parcelamentos.css') ?>">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($tituloCancelados) ?></h3>
                    <p class="text-muted mb-0">Consulte os parcelamentos cancelados</p>
                </div>

                <a href="<?= htmlspecialchars($urlAtivos) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar para ativos
                </a>
            </div>

            <?php if (isset($_GET['cancelado'])): ?>
                <div class="alert alert-warning alert-auto-dismiss fade show">
                    Parcelamento cancelado com sucesso.
                </div>
            <?php endif; ?>

            <div class="parcelamento-box">
                <div class="cabecalho-lista d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Parcelamentos Cancelados</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="Imprimir dados">
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                </div>

                <div class="orgao-impressao">Órgão: <?= htmlspecialchars($orgaoCancelado) ?> - Cancelados</div>

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
                            <?php renderizarLinhasParcelamentos($parcelamentos, true, true); ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <div class="modal fade" id="modalReativarParcelamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reativar parcelamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <strong id="clienteParcelamentoReativar" class="d-block mb-3"></strong>

                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <span class="text-muted">Cancelado em</span>
                            <strong id="dataCancelamentoReativar"></strong>
                        </div>
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <span class="text-muted">Parcela congelada</span>
                            <strong id="parcelaCanceladaReativar"></strong>
                        </div>
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <span class="text-muted">Tempo cancelado</span>
                            <strong id="tempoCanceladoReativar"></strong>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-muted">Parcela ao reativar</span>
                            <strong id="parcelaNovaReativar" class="text-success"></strong>
                        </div>
                    </div>

                    <p class="mb-0">
                        Ao confirmar, o parcelamento voltará para os ativos seguindo o calendário original.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                    <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/parcelamento_reativar.php">
                        <input type="hidden" name="id" id="parcelamentoIdReativar">
                        <button type="submit" class="btn btn-success">Sim, reativar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.getElementById('modalReativarParcelamento').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;

            document.getElementById('parcelamentoIdReativar').value = botao.dataset.parcelamentoId;
            document.getElementById('clienteParcelamentoReativar').textContent = botao.dataset.cliente;
            document.getElementById('dataCancelamentoReativar').textContent = botao.dataset.canceladoEm;
            document.getElementById('parcelaCanceladaReativar').textContent = botao.dataset.parcelaCancelada;
            document.getElementById('parcelaNovaReativar').textContent = botao.dataset.parcelaReativada;

            const dias = Number(botao.dataset.diasCancelado || 0);
            document.getElementById('tempoCanceladoReativar').textContent = dias === 0 ?
                'Menos de 1 dia' :
                dias + (dias === 1 ? ' dia' : ' dias');
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