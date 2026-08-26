<?php
require 'config.php';

exigirPermissao('clientes');

$empresaSomenteServicoAvulso = strcasecmp(trim(empresaAtivaNome($pdo)), 'MAXWELL') === 0;
$clienteContabilPadrao = $empresaSomenteServicoAvulso || isset($_GET['avulso']) ? 0 : 1;
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
                <form id="clienteForm" novalidate>
                    <input type="hidden" name="id" id="id">

                    <?php include 'includes/formulario_cliente.php'; ?>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="<?= $clienteContabilPadrao ? 'clientes.php' : 'servicos_avulsos.php' ?>" class="btn btn-secondary">
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

    <div class="modal fade" id="modalCadastrarParcelamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cliente cadastrado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    O cliente foi salvo com sucesso. Deseja cadastrar o parcelamento agora?
                </div>
                <div class="modal-footer">
                    <a href="clientes.php" class="btn btn-secondary">Depois</a>
                    <a href="#" class="btn btn-primary" id="btnCadastrarParcelamentoAgora">
                        Cadastrar agora
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

</body>

</html>