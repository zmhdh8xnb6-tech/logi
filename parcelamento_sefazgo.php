<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

$parcelamentos = buscarParcelamentosPorOrgao($pdo, 'SEFAZ GO');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Parcelamentos</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('assets/parcelamentos.css') ?>">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Parcelamento SEFAZ GO</h3>
                    <p class="text-muted mb-0">Acompanhe os parcelamentos dos clientes</p>
                </div>

                <a href="parcelamentos.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (isset($_GET['salvo'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Parcelamento salvo com sucesso.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['editado'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Parcelamento atualizado com sucesso.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['excluido'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Parcelamento excluído com sucesso.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['reativado'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Parcelamento reativado com sucesso.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['voltou_liquidado'])): ?>
                <div class="alert alert-warning alert-auto-dismiss fade show">
                    Parcelamento voltou para ativos como atrasado.
                </div>
            <?php endif; ?>

            <div class="parcelamento-box">
                <div class="cabecalho-lista d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Lista de Parcelamentos</h5>
                    <div class="d-flex gap-2">
                        <a href="parcecancelados_sefazgo.php" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-archive"></i> Cancelados
                        </a>
                        <a href="parcliquidados_sefazgo.php" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-check-circle"></i> Liquidados
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="Imprimir dados">
                            <i class="bi bi-printer"></i> Imprimir
                        </button>
                    </div>
                </div>

                <div class="orgao-impressao">Órgão: SEFAZ GO</div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
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
                            <?php renderizarLinhasParcelamentos($parcelamentos); ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <?php renderizarAvisoLiquidacoesAutomaticas('SEFAZ GO'); ?>
    <?php renderizarModalQuitarParcelamento(); ?>

    <script>
        setTimeout(function() {
            document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                alerta.classList.remove('show');

                setTimeout(function() {
                    alerta.remove();
                }, 200);
            });
        }, 4000);
    </script>

</body>

</html>