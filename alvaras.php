<?php
require 'config.php';

exigirPermissao('alvaras');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Alvarás</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
    <style>
        .card-servico {
            height: 100%;
            color: inherit;
            text-decoration: none;
            display: block;
        }
    </style>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Alvarás</h3>
                    <p class="text-muted mb-0">Escolha o estado que deseja acompanhar</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <a href="alvaras_df.php" class="card-servico">
                        <div class="icon"><i class="bi bi-building-check"></i></div>
                        <h5>Distrito Federal e DF Legal</h5>
                        <p>Órgãos, vencimentos, dispensas e cadastro DF Legal</p>
                    </a>
                </div>

                <div class="col-md-6">
                    <a href="alvaras_goias.php" class="card-servico">
                        <div class="icon"><i class="bi bi-buildings"></i></div>
                        <h5>Goiás</h5>
                        <p>Clientes e controles específicos do estado de Goiás</p>
                    </a>
                </div>
            </div>
        </div>
    </main>

</body>

</html>