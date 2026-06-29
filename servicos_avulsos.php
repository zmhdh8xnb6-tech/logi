<?php
require 'config.php';

exigirPermissao('clientes');

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE cliente_contabil = 0
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$cadastrosAvulsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Serviços Avulsos</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Serviços Avulsos</h3>
                    <p class="text-muted mb-0">Empresas que ainda não são clientes contábeis</p>
                </div>

                <a href="clientes.php" class="btn btn-outline-secondary">
                    <i class="bi bi-people"></i> Clientes contábeis
                </a>
            </div>

            <div class="row mb-3">
                <div class="col-md-5">
                    <input
                        type="text"
                        id="buscaServicoAvulso"
                        class="form-control"
                        placeholder="Buscar por código, CNPJ/CPF ou empresa...">
                </div>
            </div>

            <div class="clientes-box">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Empresa</th>
                                <th>Serviços</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cadastrosAvulsos)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Nenhum serviço avulso cadastrado.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cadastrosAvulsos as $cadastro): ?>
                                    <tr class="linha-servico-avulso">
                                        <td class="codigo-avulso"><?= htmlspecialchars($cadastro['codigo']) ?></td>
                                        <td class="documento-avulso"><?= htmlspecialchars($cadastro['documento']) ?></td>
                                        <td class="nome-avulso">
                                            <strong><?= htmlspecialchars($cadastro['nome']) ?></strong>
                                            <?php if (!empty($cadastro['nome_fantasia'])): ?>
                                                <small class="text-muted d-block"><?= htmlspecialchars($cadastro['nome_fantasia']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php if (!empty($cadastro['servico_parcelamento'])): ?>
                                                    <span class="badge bg-primary">Parcelamento</span>
                                                <?php endif; ?>

                                                <?php if (!empty($cadastro['servico_certificado'])): ?>
                                                    <span class="badge bg-success">Certificado Digital</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="cliente.php?id=<?= (int)$cadastro['id'] ?>" class="btn btn-outline-primary btn-sm" title="Visualizar">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="cliente_editar.php?id=<?= (int)$cadastro['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.getElementById('buscaServicoAvulso').addEventListener('input', function() {
            const busca = this.value.toLocaleLowerCase('pt-BR');

            document.querySelectorAll('.linha-servico-avulso').forEach(function(linha) {
                const texto = [
                    linha.querySelector('.codigo-avulso').textContent,
                    linha.querySelector('.documento-avulso').textContent,
                    linha.querySelector('.nome-avulso').textContent
                ].join(' ').toLocaleLowerCase('pt-BR');

                linha.style.display = texto.includes(busca) ? '' : 'none';
            });
        });
    </script>

</body>

</html>