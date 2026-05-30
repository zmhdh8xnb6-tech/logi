<?php
require 'config.php';

$stmt = $pdo->query("
SELECT id,codigo,nome
FROM clientes
ORDER BY CAST(codigo AS UNSIGNED) ASC
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
                                id="cliente_id">

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
                                name="orgao"
                                id="orgao">

                                <option value="">Selecione</option>
                                <option value="SEFAZ DF">SEFAZ DF</option>
                                <option value="Receita Federal">Receita Federal</option>
                                <option value="PGFN">PGFN</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Número Parcelamento
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="numero_parcelamento"
                                id="numero_parcelamento">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Forma envio
                            </label>

                            <select
                                class="form-select"
                                name="forma_envio"
                                id="forma_envio">

                                <option value="">Selecione</option>
                                <option value="E-mail">E-mail</option>
                                <option value="WhatsApp"></option>
                                <option value="Em mãos">Em mãos</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Total Parcelas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_total"
                                id="parceas_total">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas emitidas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_emitidas"
                                id="parcelas_emitidas">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas atrasadas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_atrasadas"
                                id="parcelas_atrasadas">

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

    <script>
        const camposParcelamento = [
            'cliente_id',
            'orgao',
            'numero_parcelamento',
            'forma_envio',
            'parcelas_total',
            'parcelas_emitidas',
            'parcelas_atrasadas'
        ];

        camposParcelamento.forEach(function(id) {
            const campo = document.getElementById(id);

            if (!campo) {
                console.warn('Campo não encontrado:', id);
                return;
            }

            campo.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });

            campo.addEventListener('change', function() {
                this.classList.remove('is-invalid');
            });
        });

        document.getElementById('formParcelamento').addEventListener('submit', function(e) {
            e.preventDefault();

            let valido = true;

            camposParcelamento.forEach(function(id) {
                const campo = document.getElementById(id);

                if (!campo) {
                    console.warn('Campo não encontrado:', id);
                    valido = false;
                    return;
                }

                if (!campo.value.trim()) {
                    campo.classList.add('is-invalid');
                    valido = false;
                }
            });

            if (!valido) {
                return;
            }

            // Aqui depois vamos salvar no banco
        });
    </script>

</body>

</html>