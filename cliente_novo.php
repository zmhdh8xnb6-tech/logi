<?php
require 'config.php';

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Novo Cliente</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="mb-4">
                <h3 class="mb-1">Novo Cliente</h3>
                <p class="text-muted mb-0">Cadastre um novo cliente no sistema</p>
            </div>

            <div class="clientes-box">
                <form id="clienteForm">
                    <input type="hidden" name="id" id="id">

                    <?php include 'includes/formulario_cliente.php'; ?>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="index.php" class="btn btn-secondary">
                            Cancelar
                        </a>

                        <button type="submit" class="btn btn-success" id="btnSalvarCliente">
                            Salvar
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="assets/script.js"></script>

</body>

</html>