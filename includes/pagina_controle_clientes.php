<?php
if (!isset($titulo, $subtitulo, $campoStatus, $opcoesStatus)) {
    exit('Configuracao da pagina nao informada.');
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$campoVencimento = $campoVencimento ?? null;
$mostrarVencimento = $campoVencimento !== null;
$placeholderBusca = $placeholderBusca ?? 'Buscar por codigo, cliente ou CNPJ...';
$tituloTabela = $tituloTabela ?? 'Clientes';
$voltarUrl = $voltarUrl ?? (strpos($campoStatus, 'procuracao_') === 0 ? 'procuracoes.php' : 'home.php');

$camposPermitidos = [
    'contador',
    'cadastro_crf',
    'contrato_prestacao_servicos',
    'procuracao_receita_federal',
    'procuracao_conectividade',
    'procuracao_empregador_web',
    'procuracao_fgts',
    'procuracao_particular',
    'procuracao_sefaz',
];

$vencimentosPermitidos = [
    'vencimento_procuracao_receita_federal',
    'vencimento_procuracao_conectividade',
    'vencimento_procuracao_fgts',
];

if (!in_array($campoStatus, $camposPermitidos, true)) {
    exit('Campo de controle invalido.');
}

if ($campoVencimento !== null && !in_array($campoVencimento, $vencimentosPermitidos, true)) {
    exit('Campo de vencimento invalido.');
}

$colunas = "id, codigo, documento, nome, {$campoStatus} AS status_controle";

if ($mostrarVencimento) {
    $colunas .= ", {$campoVencimento} AS vencimento_controle";
}

$stmt = $pdo->query("
    SELECT {$colunas}
    FROM clientes
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatarStatusControle(?string $valor, array $opcoesStatus): string
{
    if ($valor === null || $valor === '') {
        return 'Nao informado';
    }

    return $opcoesStatus[$valor] ?? $valor;
}

function classeStatusControle(?string $valor): string
{
    if (in_array($valor, ['sim', 'possui', 'cadastrado'], true)) {
        return 'bg-success';
    }

    if (in_array($valor, ['nao', 'nao_possui', 'nao_cadastrado'], true)) {
        return 'bg-danger';
    }

    if ($valor === 'goias') {
        return 'bg-info text-dark';
    }

    return 'bg-warning text-dark';
}

function formatarDataControle(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function formatarDiasControle(?string $vencimento): string
{
    if (empty($vencimento)) {
        return '<span class="badge bg-secondary">Sem vencimento</span>';
    }

    $hoje = new DateTime(date('Y-m-d'));
    $dataVencimento = new DateTime($vencimento);
    $diasRestantes = (int)$hoje->diff($dataVencimento)->format('%r%a');

    if ($diasRestantes < 0) {
        return '<span class="badge bg-dark">Vencido ha ' . abs($diasRestantes) . ' dias</span>';
    }

    if ($diasRestantes === 0) {
        return '<span class="badge bg-danger">Vence hoje</span>';
    }

    if ($diasRestantes <= 14) {
        return '<span class="badge bg-danger">' . $diasRestantes . ' dias</span>';
    }

    if ($diasRestantes <= 30) {
        return '<span class="badge bg-warning text-dark">' . $diasRestantes . ' dias</span>';
    }

    return '<span class="badge bg-success">' . $diasRestantes . ' dias</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title><?= htmlspecialchars($titulo) ?></title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($titulo) ?></h3>
                    <p class="text-muted mb-0"><?= htmlspecialchars($subtitulo) ?></p>
                </div>

                <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row mb-3">
                <div class="col-md-5">
                    <input
                        type="text"
                        id="buscaControle"
                        class="form-control"
                        placeholder="<?= htmlspecialchars($placeholderBusca) ?>">
                </div>
            </div>

            <div class="clientes-box">
                <h5 class="mb-3"><?= htmlspecialchars($tituloTabela) ?></h5>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>CNPJ/CPF</th>
                                <th>Cliente</th>
                                <th>Situacao</th>
                                <?php if ($mostrarVencimento): ?>
                                    <th>Vencimento</th>
                                    <th>Dias restantes</th>
                                <?php endif; ?>
                                <th class="text-end">Acoes</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($clientes)): ?>
                                <tr>
                                    <td colspan="<?= $mostrarVencimento ? 7 : 5 ?>" class="text-center text-muted py-4">
                                        Nenhum cliente encontrado.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($clientes as $cliente): ?>
                                <?php
                                $statusAtual = $cliente['status_controle'] ?? '';
                                $vencimentoAtual = $cliente['vencimento_controle'] ?? '';
                                ?>
                                <tr class="linha-cliente">
                                    <td class="codigo-cliente"><?= htmlspecialchars($cliente['codigo']) ?></td>
                                    <td class="doc-cliente"><?= htmlspecialchars($cliente['documento']) ?></td>
                                    <td class="nome-cliente"><?= htmlspecialchars($cliente['nome']) ?></td>
                                    <td>
                                        <span class="badge status-badge <?= classeStatusControle($statusAtual) ?>">
                                            <?= htmlspecialchars(formatarStatusControle($statusAtual, $opcoesStatus)) ?>
                                        </span>
                                    </td>

                                    <?php if ($mostrarVencimento): ?>
                                        <td class="vencimento-texto" data-valor="<?= htmlspecialchars($vencimentoAtual ?? '') ?>">
                                            <?= htmlspecialchars(formatarDataControle($vencimentoAtual ?? null)) ?>
                                        </td>
                                        <td class="dias-restantes">
                                            <?= formatarDiasControle($vencimentoAtual ?? null) ?>
                                        </td>
                                    <?php endif; ?>

                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm btn-editar-controle"
                                            data-id="<?= (int)$cliente['id'] ?>"
                                            data-cliente="<?= htmlspecialchars($cliente['codigo'] . ' - ' . $cliente['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($statusAtual ?? '') ?>"
                                            data-vencimento="<?= htmlspecialchars($vencimentoAtual ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <a href="cliente.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <div class="modal fade" id="modalEditarControle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar controle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="modalControleId">

                    <div class="mb-3">
                        <label class="form-label">Cliente</label>
                        <input type="text" class="form-control" id="modalControleCliente" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="modalControleStatus" class="form-label">Situacao</label>
                        <select class="form-select" id="modalControleStatus">
                            <option value="">Selecione</option>
                            <?php foreach ($opcoesStatus as $valorOpcao => $textoOpcao): ?>
                                <option value="<?= htmlspecialchars($valorOpcao) ?>">
                                    <?= htmlspecialchars($textoOpcao) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($mostrarVencimento): ?>
                        <div class="mb-3" id="grupoModalVencimento">
                            <label for="modalControleVencimento" class="form-label">Vencimento</label>
                            <input type="date" class="form-control" id="modalControleVencimento">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnSalvarModalControle">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const campoStatusControle = <?= json_encode($campoStatus) ?>;
        const campoVencimentoControle = <?= json_encode($campoVencimento) ?>;
        const opcoesStatusControle = <?= json_encode($opcoesStatus) ?>;
        const possuiVencimentoControle = <?= $mostrarVencimento ? 'true' : 'false' ?>;
        let linhaControleAtual = null;
        let botaoControleAtual = null;

        function classeStatusControleJs(status) {
            if (['sim', 'possui', 'cadastrado'].includes(status)) {
                return 'badge status-badge bg-success';
            }

            if (['nao', 'nao_possui', 'nao_cadastrado'].includes(status)) {
                return 'badge status-badge bg-danger';
            }

            if (status === 'goias') {
                return 'badge status-badge bg-info text-dark';
            }

            return 'badge status-badge bg-warning text-dark';
        }

        function textoStatusControle(status) {
            return opcoesStatusControle[status] || 'Nao informado';
        }

        function formatarDataControle(data) {
            if (!data) {
                return '-';
            }

            const partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }

        function textoDiasRestantes(vencimento) {
            if (!vencimento) {
                return '<span class="badge bg-secondary">Sem vencimento</span>';
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
            const diferenca = Math.round((data - hoje) / 86400000);

            if (diferenca < 0) {
                return '<span class="badge bg-dark">Vencido ha ' + Math.abs(diferenca) + ' dias</span>';
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

        function atualizarCampoVencimentoModal() {
            if (!possuiVencimentoControle) {
                return;
            }

            const status = document.getElementById('modalControleStatus').value;
            const campoVencimento = document.getElementById('modalControleVencimento');

            if (status === 'possui') {
                campoVencimento.disabled = false;
            } else {
                campoVencimento.value = '';
                campoVencimento.disabled = true;
                campoVencimento.classList.remove('is-invalid');
            }
        }

        document.getElementById('modalControleStatus').addEventListener('change', atualizarCampoVencimentoModal);

        document.querySelectorAll('.btn-editar-controle').forEach(function(botao) {
            botao.addEventListener('click', function() {
                linhaControleAtual = this.closest('tr');
                botaoControleAtual = this;

                document.getElementById('modalControleId').value = this.dataset.id;
                document.getElementById('modalControleCliente').value = this.dataset.cliente;
                document.getElementById('modalControleStatus').value = this.dataset.status || '';

                if (possuiVencimentoControle) {
                    const campoVencimento = document.getElementById('modalControleVencimento');
                    campoVencimento.value = this.dataset.vencimento || '';
                    campoVencimento.classList.remove('is-invalid');
                    atualizarCampoVencimentoModal();
                }

                document.getElementById('modalControleStatus').classList.remove('is-invalid');

                const modal = new bootstrap.Modal(document.getElementById('modalEditarControle'));
                modal.show();
            });
        });

        document.getElementById('btnSalvarModalControle').addEventListener('click', function() {
            const campoStatus = document.getElementById('modalControleStatus');
            const status = campoStatus.value;
            let vencimento = '';

            campoStatus.classList.remove('is-invalid');

            if (possuiVencimentoControle) {
                const campoVencimento = document.getElementById('modalControleVencimento');
                campoVencimento.classList.remove('is-invalid');
                vencimento = campoVencimento.value;

                if (status === 'possui' && vencimento === '') {
                    campoVencimento.classList.add('is-invalid');
                    campoVencimento.focus();
                    return;
                }
            }

            if (status === '') {
                campoStatus.classList.add('is-invalid');
                campoStatus.focus();
                return;
            }

            this.disabled = true;
            this.innerHTML = 'Salvando...';

            fetch('api_controles.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(document.getElementById('modalControleId').value) +
                        '&campo_status=' + encodeURIComponent(campoStatusControle) +
                        '&status=' + encodeURIComponent(status) +
                        '&campo_vencimento=' + encodeURIComponent(campoVencimentoControle || '') +
                        '&vencimento=' + encodeURIComponent(vencimento)
                })
                .then(response => response.text())
                .then(resp => {
                    if (resp.trim() === 'ok') {
                        const badge = linhaControleAtual.querySelector('.status-badge');
                        badge.className = classeStatusControleJs(status);
                        badge.textContent = textoStatusControle(status);

                        if (possuiVencimentoControle) {
                            const vencimentoTexto = linhaControleAtual.querySelector('.vencimento-texto');
                            const dias = linhaControleAtual.querySelector('.dias-restantes');
                            const vencimentoFinal = status === 'possui' ? vencimento : '';

                            vencimentoTexto.dataset.valor = vencimentoFinal;
                            vencimentoTexto.textContent = formatarDataControle(vencimentoFinal);
                            dias.innerHTML = textoDiasRestantes(vencimentoFinal);
                        }

                        botaoControleAtual.dataset.status = status;
                        botaoControleAtual.dataset.vencimento = status === 'possui' ? vencimento : '';

                        bootstrap.Modal.getInstance(document.getElementById('modalEditarControle')).hide();
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

    <script>
        document.getElementById('buscaControle').addEventListener('keyup', function() {
            const valor = this.value.toLowerCase();

            document.querySelectorAll('.linha-cliente').forEach(function(linha) {
                const codigo = linha.querySelector('.codigo-cliente').textContent.toLowerCase();
                const nome = linha.querySelector('.nome-cliente').textContent.toLowerCase();
                const documento = linha.querySelector('.doc-cliente').textContent.toLowerCase();

                const encontrou = codigo.includes(valor) || nome.includes(valor) || documento.includes(valor);
                linha.style.display = encontrou ? '' : 'none';
            });
        });
    </script>

</body>

</html>