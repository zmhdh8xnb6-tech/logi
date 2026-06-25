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
        return 'bg-secondary';
    }

    if ($valor === 'goias') {
        return 'bg-info text-dark';
    }

    return 'bg-warning text-dark';
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
                                $podeEditarVencimento = $mostrarVencimento && $statusAtual === 'possui';
                                ?>
                                <tr class="linha-cliente">
                                    <td class="codigo-cliente"><?= htmlspecialchars($cliente['codigo']) ?></td>
                                    <td class="doc-cliente"><?= htmlspecialchars($cliente['documento']) ?></td>
                                    <td class="nome-cliente"><?= htmlspecialchars($cliente['nome']) ?></td>
                                    <td>
                                        <select
                                            class="form-select form-select-sm campo-status"
                                            data-campo="<?= htmlspecialchars($campoStatus) ?>">
                                            <option value="">Selecione</option>
                                            <?php foreach ($opcoesStatus as $valorOpcao => $textoOpcao): ?>
                                                <option
                                                    value="<?= htmlspecialchars($valorOpcao) ?>"
                                                    <?= $statusAtual === $valorOpcao ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($textoOpcao) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <?php if ($mostrarVencimento): ?>
                                        <td>
                                            <input
                                                type="date"
                                                class="form-control form-control-sm campo-vencimento"
                                                style="max-width: 140px;"
                                                value="<?= htmlspecialchars($vencimentoAtual ?? '') ?>"
                                                data-id="<?= (int)$cliente['id'] ?>"
                                                data-campo="<?= htmlspecialchars($campoVencimento) ?>"
                                                <?= $podeEditarVencimento ? '' : 'disabled' ?>>
                                        </td>
                                        <td class="dias-restantes">
                                            <?= formatarDiasControle($vencimentoAtual ?? null) ?>
                                        </td>
                                    <?php endif; ?>

                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-success btn-sm btn-salvar-controle"
                                            data-id="<?= (int)$cliente['id'] ?>">
                                            Salvar
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

    <script>
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

        document.querySelectorAll('.campo-status').forEach(function(campoStatus) {
            campoStatus.addEventListener('change', function() {
                const linha = this.closest('tr');
                const campoVencimento = linha.querySelector('.campo-vencimento');

                if (!campoVencimento) {
                    return;
                }

                if (this.value === 'possui') {
                    campoVencimento.disabled = false;
                } else {
                    campoVencimento.value = '';
                    campoVencimento.disabled = true;
                    campoVencimento.classList.remove('is-invalid');

                    const dias = linha.querySelector('.dias-restantes');
                    if (dias) {
                        dias.innerHTML = textoDiasRestantes('');
                    }
                }
            });
        });

        document.querySelectorAll('.campo-vencimento').forEach(function(campoVencimento) {
            campoVencimento.addEventListener('change', function() {
                const linha = this.closest('tr');
                const dias = linha.querySelector('.dias-restantes');

                if (dias) {
                    dias.innerHTML = textoDiasRestantes(this.value);
                }
            });
        });

        document.querySelectorAll('.btn-salvar-controle').forEach(function(botao) {
            botao.addEventListener('click', function(e) {
                e.stopPropagation();

                const linha = this.closest('tr');
                const campoStatus = linha.querySelector('.campo-status');
                const campoVencimento = linha.querySelector('.campo-vencimento');
                const status = campoStatus.value;
                let vencimento = '';
                let nomeCampoVencimento = '';

                campoStatus.classList.remove('is-invalid');

                if (campoVencimento) {
                    campoVencimento.classList.remove('is-invalid');
                    nomeCampoVencimento = campoVencimento.dataset.campo;
                    vencimento = campoVencimento.value;

                    if (status === 'possui' && vencimento === '') {
                        campoVencimento.classList.add('is-invalid');
                        campoVencimento.focus();
                        this.innerHTML = 'Informe a data';
                        setTimeout(() => {
                            this.innerHTML = 'Salvar';
                        }, 1600);
                        return;
                    }
                }

                if (status === '') {
                    campoStatus.classList.add('is-invalid');
                    campoStatus.focus();
                    this.innerHTML = 'Escolha';
                    setTimeout(() => {
                        this.innerHTML = 'Salvar';
                    }, 1600);
                    return;
                }

                this.disabled = true;
                this.innerHTML = 'Salvando...';

                fetch('api_controles.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(this.dataset.id) +
                            '&campo_status=' + encodeURIComponent(campoStatus.dataset.campo) +
                            '&status=' + encodeURIComponent(status) +
                            '&campo_vencimento=' + encodeURIComponent(nomeCampoVencimento) +
                            '&vencimento=' + encodeURIComponent(vencimento)
                    })
                    .then(response => response.text())
                    .then(resp => {
                        if (resp.trim() === 'ok') {
                            this.innerHTML = 'Salvo';

                            if (campoVencimento && status !== 'possui') {
                                campoVencimento.value = '';
                                campoVencimento.disabled = true;
                            }

                            setTimeout(() => {
                                this.innerHTML = 'Salvar';
                                this.disabled = false;
                            }, 1200);
                        } else {
                            this.innerHTML = 'Erro';
                            this.disabled = false;
                        }
                    })
                    .catch(() => {
                        this.innerHTML = 'Erro';
                        this.disabled = false;
                    });
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