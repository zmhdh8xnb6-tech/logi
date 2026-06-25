<?php
require 'config.php';

exigirPermissao('pendencias');

$hoje = date('Y-m-d');
$limiteAlerta = date('Y-m-d', strtotime('+30 days'));

function adicionarPendencia(array &$pendencias, array &$resumo, array $cliente, string $tipo, string $descricao, string $status, string $nivel = 'danger'): void
{
    $resumo[$tipo] = ($resumo[$tipo] ?? 0) + 1;

    $pendencias[] = [
        'codigo' => $cliente['codigo'] ?? '',
        'nome' => $cliente['nome'] ?? '',
        'documento' => $cliente['documento'] ?? '',
        'tipo' => $tipo,
        'descricao' => $descricao,
        'status' => $status,
        'nivel' => $nivel,
        'cliente_id' => (int)($cliente['id'] ?? 0),
    ];
}

function dataBr(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendencias = [];
$resumo = [
    'Certificado' => 0,
    'Procurações' => 0,
    'Alvará' => 0,
    'Controles internos' => 0,
];

$procuracoes = [
    [
        'status' => 'procuracao_receita_federal',
        'vencimento' => 'vencimento_procuracao_receita_federal',
        'nome' => 'Procuração Receita Federal',
    ],
    [
        'status' => 'procuracao_conectividade',
        'vencimento' => 'vencimento_procuracao_conectividade',
        'nome' => 'Procuração Conectividade',
    ],
    [
        'status' => 'procuracao_fgts',
        'vencimento' => 'vencimento_procuracao_fgts',
        'nome' => 'Procuração FGTS',
    ],
    [
        'status' => 'procuracao_empregador_web',
        'vencimento' => null,
        'nome' => 'Procuração Empregador Web',
    ],
    [
        'status' => 'procuracao_particular',
        'vencimento' => null,
        'nome' => 'Procuração Particular',
    ],
    [
        'status' => 'procuracao_sefaz',
        'vencimento' => null,
        'nome' => 'Procuração SEFAZ',
    ],
];

foreach ($clientes as $cliente) {
    if (empty($cliente['vencimento_certificado'])) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Certificado', 'Certificado digital não informado', 'Não possui');
    } elseif ($cliente['vencimento_certificado'] < $hoje) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Certificado', 'Certificado digital vencido em ' . dataBr($cliente['vencimento_certificado']), 'Vencido');
    } elseif ($cliente['vencimento_certificado'] <= $limiteAlerta) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Certificado', 'Certificado vence em ' . dataBr($cliente['vencimento_certificado']), 'A vencer', 'warning');
    }

    foreach ($procuracoes as $procuracao) {
        $status = $cliente[$procuracao['status']] ?? '';

        if ($status === '' || $status === 'nao_possui') {
            adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' não informada ou não possui', 'Não possui');
            continue;
        }

        if ($procuracao['vencimento'] !== null && $status === 'possui') {
            $vencimento = $cliente[$procuracao['vencimento']] ?? null;

            if (empty($vencimento)) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' sem vencimento', 'Sem data');
            } elseif ($vencimento < $hoje) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' vencida em ' . dataBr($vencimento), 'Vencida');
            } elseif ($vencimento <= $limiteAlerta) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' vence em ' . dataBr($vencimento), 'A vencer', 'warning');
            }
        }
    }

    $alvara = $cliente['alvara'] ?? '';

    if ($alvara === '' || $alvara === 'nao_possui') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Alvará', 'Alvará não informado ou não possui', 'Não possui');
    }

    if (($cliente['contador'] ?? '') === '' || ($cliente['contador'] ?? '') === 'nao') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Contador não informado ou marcado como não', 'Pendente', 'warning');
    }

    if (($cliente['cadastro_crf'] ?? '') === '' || ($cliente['cadastro_crf'] ?? '') === 'nao_cadastrado') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Cadastro CRF não cadastrado', 'Pendente', 'warning');
    }

    if (($cliente['contrato_prestacao_servicos'] ?? '') === '' || ($cliente['contrato_prestacao_servicos'] ?? '') === 'nao_possui') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Contrato de prestação de serviços não possui', 'Pendente', 'warning');
    }
}

try {
    $stmtAlvaras = $pdo->query("
        SELECT ca.*, c.codigo, c.nome, c.documento
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE ca.situacao = 'com_vencimento'
          AND ca.vencimento IS NOT NULL
          AND ca.vencimento <= " . $pdo->quote($limiteAlerta) . "
        ORDER BY ca.vencimento ASC
    ");

    foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvaraCliente) {
        $nivel = $alvaraCliente['vencimento'] < $hoje ? 'danger' : 'warning';
        $status = $alvaraCliente['vencimento'] < $hoje ? 'Vencido' : 'A vencer';

        adicionarPendencia(
            $pendencias,
            $resumo,
            [
                'id' => $alvaraCliente['cliente_id'],
                'codigo' => $alvaraCliente['codigo'],
                'nome' => $alvaraCliente['nome'],
                'documento' => $alvaraCliente['documento'],
            ],
            'Alvará',
            $alvaraCliente['orgao_nome'] . ' - vencimento em ' . dataBr($alvaraCliente['vencimento']),
            $status,
            $nivel
        );
    }
} catch (Throwable $e) {
}

$totalPendencias = count($pendencias);
$maiorResumo = max(array_values($resumo)) ?: 1;
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Pendências</title>
    <style>
        .pendencia-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .pendencia-numero {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .grafico-barra {
            height: 12px;
            background: #e9ecef;
            border-radius: 999px;
            overflow: hidden;
        }

        .grafico-barra span {
            display: block;
            height: 100%;
            background: #0d6efd;
        }
    </style>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Pendências</h3>
                    <p class="text-muted mb-0">Clientes com informações ausentes, vencidas ou próximas do vencimento</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="pendencia-card">
                        <div class="text-muted small">Total</div>
                        <div class="pendencia-numero"><?= $totalPendencias ?></div>
                        <div class="text-muted small">pendências encontradas</div>
                    </div>
                </div>

                <?php foreach ($resumo as $tipo => $quantidade): ?>
                    <div class="col-md-3">
                        <div class="pendencia-card">
                            <div class="d-flex justify-content-between mb-2">
                                <strong><?= htmlspecialchars($tipo) ?></strong>
                                <span><?= (int)$quantidade ?></span>
                            </div>
                            <div class="grafico-barra">
                                <span style="width: <?= $maiorResumo > 0 ? (int)(($quantidade / $maiorResumo) * 100) : 0 ?>%"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="clientes-box">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input type="text" id="buscaPendencia" class="form-control" placeholder="Buscar por cliente, código, documento ou pendência...">
                    </div>

                    <div class="col-md-3">
                        <select id="filtroTipoPendencia" class="form-select">
                            <option value="">Todos os tipos</option>
                            <?php foreach (array_keys($resumo) as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Pendência</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendencias)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Nenhuma pendência encontrada.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($pendencias as $pendencia): ?>
                                <tr class="linha-pendencia" data-tipo="<?= htmlspecialchars($pendencia['tipo']) ?>">
                                    <td class="texto-pendencia">
                                        <strong><?= htmlspecialchars($pendencia['codigo']) ?> - <?= htmlspecialchars($pendencia['nome']) ?></strong>
                                        <small class="text-muted d-block"><?= htmlspecialchars($pendencia['documento']) ?></small>
                                    </td>
                                    <td class="texto-pendencia"><?= htmlspecialchars($pendencia['tipo']) ?></td>
                                    <td class="texto-pendencia"><?= htmlspecialchars($pendencia['descricao']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= htmlspecialchars($pendencia['nivel']) ?>">
                                            <?= htmlspecialchars($pendencia['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="cliente.php?id=<?= (int)$pendencia['cliente_id'] ?>" class="btn btn-outline-primary btn-sm">
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
        function filtrarPendencias() {
            const busca = document.getElementById('buscaPendencia').value.toLowerCase();
            const tipo = document.getElementById('filtroTipoPendencia').value;

            document.querySelectorAll('.linha-pendencia').forEach(function(linha) {
                const texto = linha.textContent.toLowerCase();
                const tipoLinha = linha.dataset.tipo;
                const encontrouBusca = texto.includes(busca);
                const encontrouTipo = tipo === '' || tipoLinha === tipo;

                linha.style.display = encontrouBusca && encontrouTipo ? '' : 'none';
            });
        }

        document.getElementById('buscaPendencia').addEventListener('input', filtrarPendencias);
        document.getElementById('filtroTipoPendencia').addEventListener('change', filtrarPendencias);
    </script>
</body>

</html>