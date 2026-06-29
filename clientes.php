<?php
require 'config.php';

exigirPermissao('clientes');
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Clientes</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Clientes</h3>
                    <p class="text-muted mb-0">Cadastro e gerenciamento de clientes</p>
                </div>

                <div class="d-flex gap-2">
                    <a href="servicos_avulsos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-briefcase"></i> Serviços Avulsos
                    </a>
                    <a href="cliente_novo.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Novo Cliente
                    </a>
                </div>
            </div>

            <div class="clientes-box">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input
                            type="text"
                            id="buscaCliente"
                            class="form-control"
                            placeholder="Buscar por código, nome, CPF/CNPJ ou e-mail...">
                    </div>

                    <div class="col-md-3">
                        <select id="filtroUf" class="form-select">
                            <option value="">Todas as UFs</option>
                            <option value="DF">DF</option>
                            <option value="GO">GO</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle" id="clientesTable">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CPF/CNPJ</th>
                                <th>Razão Social</th>
                                <th>Nome Fantasia</th>
                                <th>Cidade</th>
                                <th>UF</th>
                                <th>Telefone</th>
                                <th>E-mail</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    Nenhum cliente cadastrado ainda.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacao"></div>
            </div>

        </div>
    </main>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

    <?php include 'includes/modal_confirmar.php'; ?>

</body>

</html>