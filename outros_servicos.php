<?php
require 'config.php';

exigirPermissao('outros_servicos');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Outros Serviços</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Outros Serviços</h3>
                    <p class="text-muted mb-0">Controles internos que não ficam nas rotinas principais</p>
                </div>
                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card-servico card-antivirus" onclick="location.href='antivirus.php'">
                        <div class="icon"><i class="bi bi-shield-check"></i></div>
                        <h5>Antivírus</h5>
                        <p>Controle vencimentos dos computadores</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>

</html>