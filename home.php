<?php
require 'config.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Início</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
</head>

<body class="app-layout">

    <!-- SIDEBAR -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- CONTEÚDO -->
    <main class="app-main">

        <div class="container-fluid">

            <div class="mb-4">
                <h3>Bem-vindo ao sistema Logi 👋</h3>
                <p class="text-muted">Escolha o serviço que deseja acessar</p>
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='parcelamentos.php'">
                        <div class="icon">💰</div>
                        <h5>Parcelamentos</h5>
                        <p>Acompanhe tributos e dívidas</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='certificados.php'">
                        <div class="icon">🔐</div>
                        <h5>Certificado Digital</h5>
                        <p>Controle de certificados digitais</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='alvaras.php'">
                        <div class="icon">🏢</div>
                        <h5>Alvarás</h5>
                        <p>Gerencie licenças e alvarás</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='procuracoes.php'">
                        <div class="icon">📜</div>
                        <h5>Procurações</h5>
                        <p>Controle de autorizações</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='paralisadas.php'">
                        <div class="icon">📂</div>
                        <h5>Paralisações</h5>
                        <p>Gerencie Empresas Paralisadas</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='contador.php'">
                        <div class="icon">💼</div>
                        <h5>Contador</h5>
                        <p>Controle de Inclusão e Exclusão</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='baixas.php'">
                        <div class="icon">📋</div>
                        <h5>Baixas</h5>
                        <p>Controle de Baixas</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='CRF.php'">
                        <div class="icon">📋</div>
                        <h5>Cadastro CRF</h5>
                        <p>Controle Cadastro FGTS</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-servico" onclick="location.href='prestacao_servico.php'">
                        <div class="icon">🧾</div>
                        <h5>Contrato de Prestação de Serviços</h5>
                        <p>Controle de Contratos</p>
                    </div>
                </div>

            </div>

        </div>

    </main>

</body>

</html>