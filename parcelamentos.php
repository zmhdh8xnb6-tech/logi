<?php require 'config.php'; ?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Parcelamentos</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Parcelamentos</h3>
                    <p class="text-muted mb-0">Escolha o tipo de parcelamento que deseja acompanhar</p>
                </div>

                <div class="d-flex gap-2">
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>

                    <a href="parcelamento_novo.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Novo Parcelamento
                    </a>
                </div>
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <a href="parcelamento_simples.php" class="text-decoration-none text-dark d-block">
                        <div class="card-servico">
                            <div class="icon">🏦</div>
                            <h5>Simples Nacional</h5>
                            <p>Parcelamentos DAS</p>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="parcelamento_tributos.php" class="text-decoration-none text-dark d-block">
                        <div class="card-servico">
                            <div class="icon">⚖️</div>
                            <h5>Previdência Social</h5>
                            <p>Multas / Tributos</p>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="parcelamento_pgfn.php" class="text-decoration-none text-dark d-block">
                        <div class="card-servico">
                            <div class="icon">⚖️</div>
                            <h5>PGFN</h5>
                            <p>Dívida Ativa</p>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="parcelamento_sefazdf.php" class="text-decoration-none text-dark d-block">
                        <div class="card-servico">
                            <div class="icon">🏢</div>
                            <h5>SEFAZ DF</h5>
                            <p>Débitos Estaduais</p>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="parcelamento_sefazgo.php" class="text-decoration-none text-dark d-block">
                        <div class="card-servico">
                            <div class="icon">🏢</div>
                            <h5>SEFAZ GO</h5>
                            <p>Débitos Estaduais</p>
                        </div>
                    </a>
                </div>

            </div>

        </div>
    </main>

</body>

</html>