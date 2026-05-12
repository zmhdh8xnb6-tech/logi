<?php require 'config.php'; ?>

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
                    <h3 class="mb-1">Parcelamentos</h3>
                    <p class="text-muted mb-0">Acompanhe os parcelamentos dos clientes</p>
                </div>

                <a href="#" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Novo Parcelamento
                </a>
            </div>

            <div class="parcelamento-box">
                <h5 class="mb-3">Lista de Parcelamentos</h5>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Órgão</th>
                                <th>Tipo</th>
                                <th>Parcelas</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Nenhum parcelamento cadastrado ainda.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

</body>

</html>