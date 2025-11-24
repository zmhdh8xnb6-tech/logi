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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Logi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <div class="container mt-5">
    <h1 class="text-center mb-4">Empresas</h1>
    <div class="text-end mb-3">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal" onclick="abrirModalNovo()">➕
        Novo Cliente</button>
    </div>

    <div class="card p-4">
      <h2 class="mb-3">Empresas</h2>
      <div class="table-responsive">
        <table class="table table-striped" id="clientesTable">
          <thead>
            <tr>
              <th>Número</th>
              <th>CNPJ</th>
              <th>Nome</th>
              <th>Endereço</th>
              <th>E-mail</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal fade" id="clienteModal" tabindex="-1" aria-labelledby="clienteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form id="clienteForm">
          <div class="modal-header">
            <h5 class="modal-title" id="clienteModalLabel">Novo Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="id" id="id">
            <div class="mb-3">
              <label for="numero" class="form-label">Número</label>
              <input type="text" class="form-control" id="numero" name="numero" required>
            </div>
            <div class="mb-3">
              <label for="cnpj" class="form-label">Cnpj</label>
              <input type="text" class="form-control" id="cnpj" name="cnpj" required>
            </div>
            <div class="mb-3">
              <label for="nome" class="form-label">Nome</label>
              <input type="text" class="form-control" id="nome" name="nome">
            </div>
            <div class="mb-3">
              <label for="endereco" class="form-label">Endereço</label>
              <input type="text" class="form-control" id="endereco" name="endereco">
            </div>
            <div class="mb-3">
              <label for="email" class="form-label">E-mail</label>
              <input type="email" class="form-control" id="email" name="email">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="assets/script.js"></script>
</body>

</html>