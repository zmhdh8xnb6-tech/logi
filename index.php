<?php
session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$conn = new mysqli("localhost", "root", "", "crud_clientes");
if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}
?>

<?php
// index.php - Interface with Bootstrap modal
$conn = new mysqli("localhost", "root", "", "crud_clientes");
if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <?php include 'includes/modal_cliente.php'; ?>
    <title>Clientes</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Clientes</h3>
                    <p class="text-muted mb-0">Cadastro e gerenciamento de clientes</p>
                </div>

                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal" onclick="abrirModalNovo()">
                    <i class="bi bi-plus-circle"></i> Novo Cliente
                </button>
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

    <div class="modal fade" id="modalAviso" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title">Atenção</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center" id="modalAvisoMensagem">
                    Mensagem
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger btn-sm" data-bs-dismiss="modal">
                        OK
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="assets/script.js"></script>

    <div class="modal fade" id="modalConfirmarExclusao" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">

                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title">Confirmar exclusão</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    Tem certeza que deseja excluir este cliente?
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="button" class="btn btn-danger btn-sm" id="btnConfirmarExclusao">
                        Excluir
                    </button>
                </div>

            </div>
        </div>
    </div>

</body>

</html>