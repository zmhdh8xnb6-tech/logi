<?php
require 'config.php';

exigirPermissao('paralisacoes');
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Paralisações</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Paralisações</h3>
                    <p class="text-muted mb-0">Controle de empresas paralisadas</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="clientes-box">
                <div class="text-center py-5">
                    <div class="mb-3 text-secondary fs-1">
                        <i class="bi bi-folder2-open"></i>
                    </div>
                    <h5 class="mb-2">Controle ainda não configurado</h5>
                    <p class="text-muted mb-0">
                        A página já está criada para evitar erro 404. Falta definir quais dados serão controlados aqui.
                    </p>
                </div>
            </div>
        </div>
    </main>
</body>

</html>