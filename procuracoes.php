<?php
require 'config.php';

exigirPermissao('procuracoes');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Procurações</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Procurações</h3>
                    <p class="text-muted mb-0">Escolha o tipo de procuração que deseja acompanhar</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_receita.php'">
                        <div class="icon"><i class="bi bi-bank"></i></div>
                        <h5>Receita Federal</h5>
                        <p>Controle de procurações e-CAC</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_conectividade.php'">
                        <div class="icon"><i class="bi bi-shield-lock"></i></div>
                        <h5>Conectividade Social</h5>
                        <p>Acompanhe acessos e validade</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_fgts.php'">
                        <div class="icon"><i class="bi bi-folder2-open"></i></div>
                        <h5>FGTS</h5>
                        <p>Procurações e autorizações FGTS</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_sefaz.php'">
                        <div class="icon"><i class="bi bi-building"></i></div>
                        <h5>SEFAZ DF</h5>
                        <p>Controle de acessos fiscais</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_empregador_web.php'">
                        <div class="icon"><i class="bi bi-briefcase"></i></div>
                        <h5>Empregador Web</h5>
                        <p>Procurações trabalhistas</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico card-procuracoes" onclick="location.href='procuracao_particular.php'">
                        <div class="icon"><i class="bi bi-file-earmark-person"></i></div>
                        <h5>Particular</h5>
                        <p>Controle de procurações particulares</p>
                    </div>
                </div>

            </div>

        </div>
    </main>

</body>

</html>