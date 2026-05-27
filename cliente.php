<?php
require 'config.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title><?= htmlspecialchars($cliente['nome']) ?> - Cliente</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <a href="index.php" class="btn btn-outline-secondary mb-3">Voltar</a>

            <h3><?= htmlspecialchars($cliente['nome']) ?></h3>
            <p class="text-muted"><?= htmlspecialchars($cliente['documento']) ?></p>

            <div class="d-flex gap-2 mb-4">
                <a href="cliente_editar.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Editar
                </a>

                <button class="btn btn-danger" onclick="excluirCliente(<?= (int)$cliente['id'] ?>)">
                    <i class="bi bi-trash"></i> Excluir
                </button>
            </div>

            <div class="clientes-box mt-4">
                <h5>Dados principais</h5>
                <p><strong>Código:</strong> <?= htmlspecialchars($cliente['codigo'] ?? '') ?></p>
                <p><strong>Nome Fantasia:</strong> <?= htmlspecialchars($cliente['nome_fantasia'] ?? '') ?></p>
                <p><strong>E-mail:</strong> <?= htmlspecialchars($cliente['email'] ?? '') ?></p>
                <p><strong>Telefone:</strong> <?= htmlspecialchars($cliente['telefone'] ?? '') ?></p>
                <p><strong>Inscrição Estadual:</strong> <?= htmlspecialchars($cliente['inscricao_estadual'] ?? '') ?></p>
                <p><strong>NIRE:</strong> <?= htmlspecialchars($cliente['nire'] ?? '') ?></p>
                <p>
                    <strong>Vencimento Certificado Digital:</strong>
                    <?= !empty($cliente['vencimento_certificado'])
                        ? date('d/m/Y', strtotime($cliente['vencimento_certificado']))
                        : 'Não cadastrado'; ?>
                </p>
                <hr>

                <h6 class="mt-3 mb-3">Controles internos</h6>

                <div class="row">
                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Cadastro DF Legal</small>
                        <?= htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $cliente['cadastro_df_legal'] ?: 'Não informado'))
                        ) ?>
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Alvará</small>
                        <?= htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $cliente['alvara'] ?: 'Não informado'))
                        ) ?>
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Contador</small>
                        <?= htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $cliente['contador'] ?: 'Não informado'))
                        ) ?>
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Cadastro CRF</small>
                        <?= htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $cliente['cadastro_crf'] ?: 'Não informado'))
                        ) ?>
                    </div>
                </div>
            </div>

            <div class="clientes-box mt-4">
                <h5>Endereço</h5>
                <p><strong>CEP:</strong> <?= htmlspecialchars($cliente['cep'] ?? '') ?></p>
                <p><strong>Endereço:</strong> <?= htmlspecialchars($cliente['endereco'] ?? '') ?>, <?= htmlspecialchars($cliente['numero_endereco'] ?? '') ?></p>
                <p><strong>Complemento:</strong> <?= htmlspecialchars($cliente['complemento'] ?? '') ?></p>
                <p><strong>Bairro:</strong> <?= htmlspecialchars($cliente['bairro'] ?? '') ?></p>
                <p><strong>Cidade/UF:</strong> <?= htmlspecialchars($cliente['cidade'] ?? '') ?> / <?= htmlspecialchars($cliente['uf'] ?? '') ?></p>
            </div>

        </div>
    </main>

    <?php include 'includes/modal_confirmar.php'; ?>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="assets/script.js"></script>

    <script>
        const clienteAtual = <?= json_encode($cliente, JSON_UNESCAPED_UNICODE) ?>;
    </script>

</body>

</html>