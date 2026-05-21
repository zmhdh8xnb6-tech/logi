<?php
require 'config.php';

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE vencimento_certificado IS NOT NULL
    ORDER BY vencimento_certificado ASC
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

                                $hoje = new DateTime();
                                $vencimento = new DateTime($cliente['vencimento_certificado']);

                                $dias = $hoje->diff($vencimento);
                                $diasRestantes = (int)$dias->format('%r%a');

                                $classe = '';

                            ?>

                                <tr class="linha-cliente">

                                    <td>
                                        <?= htmlspecialchars($cliente['codigo']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($cliente['documento']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($cliente['nome']) ?>
                                    </td>

                                    <td>
                                        <?= date(
                                            'd/m/Y',
                                            strtotime($cliente['vencimento_certificado'])
                                        ) ?>
                                    </td>

                                    <td>

                                        <?php
                                        if ($diasRestantes < 0) {
                                            echo 'Vencido';
                                        } elseif ($diasRestantes == 0) {
                                            echo 'Vence hoje';
                                        } else {
                                            echo $diasRestantes . ' dias';
                                        }
                                        ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>
    </main>

</body>

</html>