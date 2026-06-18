<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

$parcelamentos = buscarParcelamentosPorOrgao($pdo, 'PGFN');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Parcelamentos</title>
    <link rel="stylesheet" href="assets/home.css">
    <link rel="stylesheet" href="assets/parcelamentos.css">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Parcelamento Divida Ativa</h3>
                    <p class="text-muted mb-0">Acompanhe os parcelamentos dos clientes</p>
                </div>

                <a href="parcelamento_novo.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Novo Parcelamento
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

            <div class="parcelamento-box">
                <h5 class="mb-3">Lista de Parcelamentos</h5>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Órgão</th>
                                <th>Número</th>
                                <th>Forma envio</th>
                                <th>Parcelas</th>
                                <th>Atrasadas</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
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

</body>

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

</html>