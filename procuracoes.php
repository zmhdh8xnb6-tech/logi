<?php require 'config.php'; ?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Procurações</title>
    <link rel="stylesheet" href="assets/home.css">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="mb-4">
                <h3 class="mb-1">Procurações</h3>
                <p class="text-muted mb-0">Escolha o tipo de procuração que deseja acompanhar</p>
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="card-servico">
                        <div class="icon">🏛️</div>
                        <h5>Receita Federal</h5>
                        <p>Controle de procurações e-CAC</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico">
                        <div class="icon">🔐</div>
                        <h5>Conectividade Social</h5>
                        <p>Acompanhe acessos e validade</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico">
                        <div class="icon">📁</div>
                        <h5>FGTS</h5>
                        <p>Procurações e autorizações FGTS</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico">
                        <div class="icon">🏢</div>
                        <h5>SEFAZ DF</h5>
                        <p>Controle de acessos fiscais</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico">
                        <div class="icon">💼</div>
                        <h5>Empregador Web</h5>
                        <p>Procurações trabalhistas</p>
                    </div>
                </div>

            </div>

        </div>
    </main>

</body>

</html>