<?php
require 'config.php';

exigirPermissao('certificados');

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE servico_certificado = 1
       OR cliente_contabil = 1
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
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

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Certificados Digitais</h3>
                    <p class="text-muted mb-0">Acompanhe os vencimentos dos certificados digitais dos clientes</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
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
                                <th>Vínculo</th>
                                <th>Status</th>
                                <th>Vencimento</th>
                                <th>Dias restantes</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($certificados as $cliente):

                                $temCertificado = !empty($cliente['vencimento_certificado']);
                                $diasRestantes = null;

                                if ($temCertificado) {
                                    $hoje = new DateTime(date('Y-m-d'));
                                    $vencimento = new DateTime($cliente['vencimento_certificado']);

                                    $dias = $hoje->diff($vencimento);
                                    $diasRestantes = (int)$dias->format('%r%a');
                                }

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
                                        <span class="badge <?= !empty($cliente['cliente_contabil']) ? 'bg-success' : 'bg-info text-dark' ?>">
                                            <?= !empty($cliente['cliente_contabil']) ? 'Cliente contábil' : 'Serviço avulso' ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge certificado-status <?= $temCertificado ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $temCertificado ? 'Possui' : 'Não possui' ?>
                                        </span>
                                    </td>

                                    <td class="vencimento-certificado" data-valor="<?= htmlspecialchars($cliente['vencimento_certificado'] ?? '') ?>">
                                        <?= $temCertificado ? date('d/m/Y', strtotime($cliente['vencimento_certificado'])) : '-' ?>
                                    </td>

                                    <td class="dias-certificado">
                                        <?php if (!$temCertificado): ?>

                                            <span class="badge bg-secondary">
                                                Sem vencimento
                                            </span>

                                        <?php elseif ($diasRestantes < 0): ?>

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

                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm btn-editar-certificado"
                                            data-id="<?= (int)$cliente['id'] ?>"
                                            data-cliente="<?= htmlspecialchars($cliente['codigo'] . ' - ' . $cliente['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-vencimento="<?= htmlspecialchars($cliente['vencimento_certificado'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>
    </main>

    <div class="modal fade" id="modalEditarCertificado" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar certificado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="modalCertificadoId">

                    <div class="mb-3">
                        <label class="form-label">Cliente</label>
                        <input type="text" class="form-control" id="modalCertificadoCliente" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="modalCertificadoVencimento" class="form-label">Vencimento</label>
                        <input type="date" class="form-control" id="modalCertificadoVencimento">
                        <div class="form-text">Deixe em branco para marcar como não possui.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnSalvarModalCertificado">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
        let linhaCertificadoAtual = null;
        let botaoCertificadoAtual = null;

        function textoDiasCertificado(vencimento) {
            if (!vencimento) {
                return '<span class="badge bg-secondary">Sem vencimento</span>';
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
            const diferenca = Math.round((data - hoje) / 86400000);

            if (diferenca < 0) {
                return '<span class="badge bg-dark">Vencido há ' + Math.abs(diferenca) + ' dias</span>';
            }

            if (diferenca === 0) {
                return '<span class="badge bg-danger">Vence hoje</span>';
            }

            if (diferenca <= 14) {
                return '<span class="badge bg-danger">' + diferenca + ' dias</span>';
            }

            if (diferenca <= 30) {
                return '<span class="badge bg-warning text-dark">' + diferenca + ' dias</span>';
            }

            return '<span class="badge bg-success">' + diferenca + ' dias</span>';
        }

        function formatarDataCertificado(data) {
            if (!data) {
                return '-';
            }

            const partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }

        document.querySelectorAll('.btn-editar-certificado').forEach(function(botao) {
            botao.addEventListener('click', function() {
                linhaCertificadoAtual = this.closest('tr');
                botaoCertificadoAtual = this;

                document.getElementById('modalCertificadoId').value = this.dataset.id;
                document.getElementById('modalCertificadoCliente').value = this.dataset.cliente;
                document.getElementById('modalCertificadoVencimento').value = this.dataset.vencimento || '';

                const modal = new bootstrap.Modal(document.getElementById('modalEditarCertificado'));
                modal.show();
            });
        });

        document.getElementById('btnSalvarModalCertificado').addEventListener('click', function() {
            const id = document.getElementById('modalCertificadoId').value;
            const vencimento = document.getElementById('modalCertificadoVencimento').value;

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
                        const status = linhaCertificadoAtual.querySelector('.certificado-status');
                        const vencimentoTexto = linhaCertificadoAtual.querySelector('.vencimento-certificado');
                        const dias = linhaCertificadoAtual.querySelector('.dias-certificado');

                        if (vencimento) {
                            status.className = 'badge certificado-status bg-success';
                            status.textContent = 'Possui';
                        } else {
                            status.className = 'badge certificado-status bg-danger';
                            status.textContent = 'Não possui';
                        }

                        vencimentoTexto.dataset.valor = vencimento;
                        vencimentoTexto.textContent = formatarDataCertificado(vencimento);
                        dias.innerHTML = textoDiasCertificado(vencimento);
                        botaoCertificadoAtual.dataset.vencimento = vencimento;

                        bootstrap.Modal.getInstance(document.getElementById('modalEditarCertificado')).hide();
                    } else {
                        this.innerHTML = 'Erro';
                    }
                })
                .catch(() => {
                    this.innerHTML = 'Erro';
                })
                .finally(() => {
                    setTimeout(() => {
                        this.disabled = false;
                        this.innerHTML = 'Salvar';
                    }, 600);
                });
        });
    </script>

</body>

</html>