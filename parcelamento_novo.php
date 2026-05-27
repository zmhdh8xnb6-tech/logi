<?php
require 'config.php';

$stmt = $pdo->query("
SELECT id,codigo,nome
FROM clientes
ORDER BY nome
");

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Novo Parcelamento</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="mb-4">

                <h3 class="mb-1">
                    Novo Parcelamento
                </h3>

                <p class="text-muted mb-0">
                    Cadastre um parcelamento para um cliente
                </p>

            </div>

            <div class="clientes-box">

                <form id="formParcelamento">

                    <div class="row">

                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Cliente
                            </label>

                            <select
                                class="form-select"
                                name="cliente_id"
                                required>

                                <option value="">
                                    Selecione
                                </option>

                                <?php foreach ($clientes as $c): ?>

                                    <option value="<?= $c['id'] ?>">

                                        <?= $c['codigo'] ?>
                                        -
                                        <?= htmlspecialchars($c['nome']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-md-2 mb-3">

                            <label class="form-label">
                                Órgão
                            </label>

                            <select
                                class="form-select"
                                name="orgao">

                                <option>SEFAZ DF</option>
                                <option>Receita Federal</option>
                                <option>PGFN</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Número Parcelamento
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="numero_parcelamento">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Forma envio
                            </label>

                            <select
                                class="form-select"
                                name="forma_envio">

                                <option>E-mail</option>
                                <option>Zap</option>
                                <option>Em mãos</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Total Parcelas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_total">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas emitidas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_emitidas">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas atrasadas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_atrasadas">

                        </div>

                    </div>

                    <div class="mt-3 text-end">

                        <button
                            class="btn btn-success"
                            type="submit">

                            Salvar

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </main>

</body>

</html>