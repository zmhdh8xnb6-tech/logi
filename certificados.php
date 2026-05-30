<?php
require 'config.php';

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE vencimento_certificado IS NOT NULL
    ORDER BY CAST(codigo AS UNSIGNED) ASC
");

$certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Certificados</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="mb-4">
                <h3 class="mb-1">Certificados Digitais</h3>
                <p class="text-muted mb-0">Acompanhe os vencimentos dos certificados digitais dos clientes</p>
            </div>

            <div class="row mb-3">

                <div class="col-md-4">
                    <input
                        type="text"
                        id="buscaCertificado"
                        class="form-control"
                        placeholder="Buscar por código ou CNPJ...">
                </div>

            </div>

            <div class="clientes-box">

                <div class="table-responsive">

                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Cliente</th>
                                <th>Vencimento</th>
                                <th>Dias restantes</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($certificados as $cliente):

                                $hoje = new DateTime(date('Y-m-d'));
                                $vencimento = new DateTime($cliente['vencimento_certificado']);

                                $dias = $hoje->diff($vencimento);
                                $diasRestantes = (int)$dias->format('%r%a');

                                $classe = '';

                            ?>

                                <tr class="linha-cliente">

                                    <td class="codigo-cliente">
                                        <?= htmlspecialchars($cliente['codigo']) ?>
                                    </td>

                                    <td class="doc-cliente">
                                        <?= htmlspecialchars($cliente['documento']) ?>
                                    </td>

                                    <td class="nome-cliente">
                                        <?= htmlspecialchars($cliente['nome']) ?>
                                    </td>

                                    <td>
                                        <div class="d-flex gap-2">
                                            <input
                                                type="date"
                                                class="form-control form-control-sm campo-vencimento"
                                                style="max-width: 120px;"
                                                value="<?= htmlspecialchars($cliente['vencimento_certificado']) ?>"
                                                data-id="<?= (int)$cliente['id'] ?>">

                                            <button
                                                type="button"
                                                class="btn btn-success btn-sm btn-salvar-vencimento">
                                                Salvar
                                            </button>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if ($diasRestantes < 0): ?>

                                            <span class="badge bg-dark">
                                                Vencido há <?= abs($diasRestantes) ?> dias
                                            </span>

                                        <?php elseif ($diasRestantes == 0): ?>

                                            <span class="badge bg-danger">
                                                Vence hoje
                                            </span>

                                        <?php elseif ($diasRestantes <= 14): ?>

                                            <span class="badge bg-danger">
                                                <?= $diasRestantes ?> dias
                                            </span>

                                        <?php elseif ($diasRestantes <= 30): ?>

                                            <span class="badge bg-warning text-dark">
                                                <?= $diasRestantes ?> dias
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-success">
                                                <?= $diasRestantes ?> dias
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>
    </main>

    <script>
        document.getElementById('buscaCertificado').addEventListener('keyup', function() {
            const valor = this.value.toLowerCase();

            document.querySelectorAll('.linha-cliente').forEach(function(linha) {
                const codigo = linha.querySelector('.codigo-cliente').textContent.toLocaleLowerCase();
                const nome = linha.querySelector('.nome-cliente').textContent.toLowerCase();
                const documento = linha.querySelector('.doc-cliente').textContent.toLowerCase();

                const encontrou = nome.includes(valor) || documento.includes(valor) || codigo.includes(valor);

                linha.style.display = encontrou ? '' : 'none';
            });
        });
    </script>

    <script>
        document.querySelectorAll('.btn-salvar-vencimento').forEach(function(botao) {
            botao.addEventListener('click', function(e) {
                e.stopPropagation();

                const linha = this.closest('tr');
                const campo = linha.querySelector('.campo-vencimento');

                const id = campo.dataset.id;
                const vencimento = campo.value;

                this.disabled = true;
                this.innerHTML = 'Salvando...';

                fetch('api_certificados.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(id) +
                            '&vencimento_certificado=' + encodeURIComponent(vencimento)
                    })
                    .then(response => response.text())
                    .then(resp => {
                        if (resp.trim() === 'ok') {
                            this.innerHTML = 'Salvo';
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        } else {
                            this.innerHTML = 'Erro';
                        }
                    })
                    .catch(() => {
                        this.innerHTML = 'Erro';
                    });
            });
        });
    </script>

</body>

</html>