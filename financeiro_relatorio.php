<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? null);
$periodo = (int)($_GET['periodo'] ?? 1);
$periodo = max(1, min(12, $periodo));
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$proximoMes = date('Y-m', strtotime($inicioMes . ' +1 month'));
$estruturaDisponivel = financeiroCategoriasDisponiveis($pdo);
$nomesMeses = [
    1 => 'Janeiro',
    2 => 'Fevereiro',
    3 => 'Março',
    4 => 'Abril',
    5 => 'Maio',
    6 => 'Junho',
    7 => 'Julho',
    8 => 'Agosto',
    9 => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro',
];
$nomeMes = $nomesMeses[(int)date('n', strtotime($inicioMes))]
    . '/'
    . date('Y', strtotime($inicioMes));
$competencias = [];

for ($indice = $periodo - 1; $indice >= 0; $indice--) {
    $competencia = date('Y-m', strtotime($inicioMes . " -{$indice} months"));
    $competencias[$competencia] = [
        'mes' => $nomesMeses[(int)date('n', strtotime($competencia . '-01'))]
            . '/'
            . date('Y', strtotime($competencia . '-01')),
        'receitas' => 0.0,
        'despesas' => 0.0,
        'resultado' => 0.0,
    ];
}

$inicioPeriodo = array_key_first($competencias) . '-01';
$fimPeriodo = $fimMes;
$primeiraCompetencia = array_key_first($competencias);
$nomePrimeiroMes = $nomesMeses[(int)date('n', strtotime($primeiraCompetencia . '-01'))]
    . '/'
    . date('Y', strtotime($primeiraCompetencia . '-01'));
$nomePeriodo = $periodo === 1
    ? $nomeMes
    : $nomePrimeiroMes . ' a ' . $nomeMes;
$categoriasDespesas = [];
$lancamentosPorCategoria = [];
$totalReceitasMes = 0.0;
$totalDespesasMes = 0.0;
$totalGastosCartaoPeriodo = 0.0;
$totalDespesasCategorias = 0.0;
$totalSemCategoria = 0;

if ($estruturaDisponivel) {
    financeiroGarantirCategoriasPadrao($pdo, $usuarioId);
    financeiroSincronizarFaturasCartoes($pdo, $usuarioId);

    foreach (array_keys($competencias) as $competencia) {
        financeiroSincronizarContasRecorrentes($pdo, $usuarioId, $competencia);
        financeiroSincronizarRecebimentosRecorrentes($pdo, $usuarioId, $competencia);
    }

    $temCompetenciaFatura = financeiroColunaExiste(
        $pdo,
        'financeiro_cartao_lancamentos',
        'competencia_fatura'
    );
    $expressaoCompetenciaCartao = $temCompetenciaFatura
        ? "DATE_FORMAT(COALESCE(competencia_fatura, data_compra), '%Y-%m')"
        : "DATE_FORMAT(data_compra, '%Y-%m')";
    $expressaoDataCompetenciaCartao = $temCompetenciaFatura
        ? 'COALESCE(competencia_fatura, data_compra)'
        : 'data_compra';
    $expressaoDataCompetenciaCartaoComAlias = $temCompetenciaFatura
        ? 'COALESCE(l.competencia_fatura, l.data_compra)'
        : 'l.data_compra';

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(data_recebimento, '%Y-%m') AS competencia, SUM(valor) AS total
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND data_recebimento >= ?
          AND data_recebimento < ?
        GROUP BY DATE_FORMAT(data_recebimento, '%Y-%m')
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if (isset($competencias[$linha['competencia']])) {
            $competencias[$linha['competencia']]['receitas'] = (float)$linha['total'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT valor_previsto, valor_pago, vencimento, status, data_pagamento
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND (
                (
                    status = 'pago'
                    AND data_pagamento IS NOT NULL
                    AND data_pagamento >= ?
                    AND data_pagamento < ?
                )
                OR (
                    status <> 'pago'
                    AND vencimento >= ?
                    AND vencimento < ?
                )
          )
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo, $inicioPeriodo, $fimPeriodo]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $pagaNoPeriodo = $linha['status'] === 'pago' && !empty($linha['data_pagamento']);
        $competenciaConta = date(
            'Y-m',
            strtotime($pagaNoPeriodo ? $linha['data_pagamento'] : $linha['vencimento'])
        );

        if (isset($competencias[$competenciaConta])) {
            $valorPrevistoConta = (float)$linha['valor_previsto'];
            $valorPagoConta = (float)($linha['valor_pago'] ?? 0);

            if ($pagaNoPeriodo) {
                $valorDespesa = $valorPagoConta;
            } elseif ($valorPagoConta > 0 && $valorPagoConta < $valorPrevistoConta) {
                $valorDespesa = $valorPrevistoConta - $valorPagoConta;
            } else {
                $valorDespesa = $valorPrevistoConta;
            }

            $competencias[$competenciaConta]['despesas'] += $valorDespesa;
        }
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor), 0) AS total
        FROM financeiro_cartao_lancamentos
        WHERE usuario_id = ?
          AND {$expressaoDataCompetenciaCartao} >= ?
          AND {$expressaoDataCompetenciaCartao} < ?
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);
    $totalGastosCartaoPeriodo = (float)$stmt->fetchColumn();

    foreach ($competencias as &$dadosCompetencia) {
        $dadosCompetencia['resultado'] = $dadosCompetencia['receitas']
            - $dadosCompetencia['despesas'];
    }
    unset($dadosCompetencia);

    $totalReceitasMes = array_sum(array_column($competencias, 'receitas'));
    $totalDespesasMes = array_sum(array_column($competencias, 'despesas'));

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(c.id, 0) AS categoria_id,
            COALESCE(c.nome, 'Sem categoria') AS categoria,
            COALESCE(c.cor, '#94a3b8') AS cor,
            SUM(fc.valor_previsto) AS total,
            COUNT(*) AS quantidade
        FROM financeiro_contas fc
        LEFT JOIN financeiro_categorias c
            ON c.id = fc.categoria_id
           AND c.usuario_id = fc.usuario_id
        WHERE fc.usuario_id = ?
          AND fc.vencimento >= ?
          AND fc.vencimento < ?
          AND fc.cartao_id IS NULL
        GROUP BY c.id, c.nome, c.cor
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $chave = (int)$linha['categoria_id'];
        $categoriasDespesas[$chave] = [
            'id' => $chave,
            'nome' => $linha['categoria'],
            'cor' => $linha['cor'],
            'total' => (float)$linha['total'],
            'quantidade' => (int)$linha['quantidade'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(c.id, 0) AS categoria_id,
            COALESCE(c.nome, 'Sem categoria') AS categoria,
            COALESCE(c.cor, '#94a3b8') AS cor,
            SUM(l.valor) AS total,
            COUNT(*) AS quantidade
        FROM financeiro_cartao_lancamentos l
        LEFT JOIN financeiro_categorias c
            ON c.id = l.categoria_id
           AND c.usuario_id = l.usuario_id
        WHERE l.usuario_id = ?
          AND {$expressaoDataCompetenciaCartaoComAlias} >= ?
          AND {$expressaoDataCompetenciaCartaoComAlias} < ?
        GROUP BY c.id, c.nome, c.cor
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $chave = (int)$linha['categoria_id'];

        if (!isset($categoriasDespesas[$chave])) {
            $categoriasDespesas[$chave] = [
                'id' => $chave,
                'nome' => $linha['categoria'],
                'cor' => $linha['cor'],
                'total' => 0.0,
                'quantidade' => 0,
            ];
        }

        $categoriasDespesas[$chave]['total'] += (float)$linha['total'];
        $categoriasDespesas[$chave]['quantidade'] += (int)$linha['quantidade'];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(c.id, 0) AS categoria_id,
            fc.descricao,
            fc.vencimento AS data_lancamento,
            fc.valor_previsto AS valor,
            fc.status,
            fc.parcela_numero,
            fc.parcelas_total
        FROM financeiro_contas fc
        LEFT JOIN financeiro_categorias c
            ON c.id = fc.categoria_id
           AND c.usuario_id = fc.usuario_id
        WHERE fc.usuario_id = ?
          AND fc.vencimento >= ?
          AND fc.vencimento < ?
          AND fc.cartao_id IS NULL
        ORDER BY fc.vencimento, fc.descricao
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $chave = (int)$linha['categoria_id'];
        $descricao = $linha['descricao'];

        if (!empty($linha['parcela_numero']) && !empty($linha['parcelas_total'])) {
            $descricao .= ' ' . (int)$linha['parcela_numero']
                . '/' . (int)$linha['parcelas_total'];
        }

        if ($linha['status'] === 'pago') {
            $status = 'Pago';
            $statusClasse = 'bg-success';
        } elseif ($linha['data_lancamento'] < date('Y-m-d')) {
            $status = 'Atrasado';
            $statusClasse = 'bg-danger';
        } else {
            $status = 'Pendente';
            $statusClasse = 'bg-warning text-dark';
        }

        $lancamentosPorCategoria[$chave][] = [
            'data' => financeiroData($linha['data_lancamento']),
            'data_ordem' => $linha['data_lancamento'],
            'competencia' => date('m/Y', strtotime($linha['data_lancamento'])),
            'descricao' => $descricao,
            'origem' => 'Conta',
            'status' => $status,
            'status_classe' => $statusClasse,
            'valor' => (float)$linha['valor'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(c.id, 0) AS categoria_id,
            l.descricao,
            l.data_compra AS data_lancamento,
            l.valor,
            l.status,
            l.parcela_numero,
            l.parcelas_total,
            cartao.nome AS cartao_nome,
            cartao.dia_vencimento,
            DATE_FORMAT({$expressaoDataCompetenciaCartaoComAlias}, '%Y-%m') AS competencia_lancamento
        FROM financeiro_cartao_lancamentos l
        INNER JOIN financeiro_cartoes cartao
            ON cartao.id = l.cartao_id
           AND cartao.usuario_id = l.usuario_id
        LEFT JOIN financeiro_categorias c
            ON c.id = l.categoria_id
           AND c.usuario_id = l.usuario_id
        WHERE l.usuario_id = ?
          AND {$expressaoDataCompetenciaCartaoComAlias} >= ?
          AND {$expressaoDataCompetenciaCartaoComAlias} < ?
        ORDER BY l.data_compra, l.descricao
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $chave = (int)$linha['categoria_id'];
        $descricao = $linha['descricao'];

        if (!empty($linha['parcela_numero']) && !empty($linha['parcelas_total'])) {
            $descricao .= ' ' . (int)$linha['parcela_numero']
                . '/' . (int)$linha['parcelas_total'];
        }

        $vencimentoFatura = financeiroVencimentoFatura(
            $linha['competencia_lancamento'],
            (int)$linha['dia_vencimento']
        );

        if ($linha['status'] === 'pago') {
            $status = 'Pago';
            $statusClasse = 'bg-success';
        } elseif ($vencimentoFatura < date('Y-m-d')) {
            $status = 'Atrasado';
            $statusClasse = 'bg-danger';
        } else {
            $status = 'Pendente';
            $statusClasse = 'bg-warning text-dark';
        }

        $lancamentosPorCategoria[$chave][] = [
            'data' => financeiroData($linha['data_lancamento']),
            'data_ordem' => $linha['data_lancamento'],
            'competencia' => date(
                'm/Y',
                strtotime($linha['competencia_lancamento'] . '-01')
            ),
            'descricao' => $descricao,
            'origem' => 'Cartão - ' . $linha['cartao_nome'],
            'status' => $status,
            'status_classe' => $statusClasse,
            'valor' => (float)$linha['valor'],
        ];
    }

    foreach ($lancamentosPorCategoria as &$lancamentosCategoria) {
        usort($lancamentosCategoria, static function (array $a, array $b): int {
            return [$a['data_ordem'], $a['descricao']]
                <=> [$b['data_ordem'], $b['descricao']];
        });
    }
    unset($lancamentosCategoria);

    usort($categoriasDespesas, static function (array $a, array $b): int {
        return $b['total'] <=> $a['total'];
    });

    $totalDespesasCategorias = array_sum(array_column($categoriasDespesas, 'total'));

    foreach ($categoriasDespesas as $categoria) {
        if ($categoria['nome'] === 'Sem categoria') {
            $totalSemCategoria += $categoria['quantidade'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND data_recebimento >= ?
          AND data_recebimento < ?
          AND categoria_id IS NULL
    ");
    $stmt->execute([$usuarioId, $inicioPeriodo, $fimPeriodo]);
    $totalSemCategoria += (int)$stmt->fetchColumn();
}

$resultadoMes = $totalReceitasMes - $totalDespesasMes;
$labelsMeses = array_column($competencias, 'mes');
$dadosReceitas = array_column($competencias, 'receitas');
$dadosDespesas = array_column($competencias, 'despesas');
$labelsCategorias = array_column($categoriasDespesas, 'nome');
$dadosCategorias = array_column($categoriasDespesas, 'total');
$coresCategorias = array_column($categoriasDespesas, 'cor');
$idsCategorias = array_column($categoriasDespesas, 'id');
$detalhesCategorias = [];

foreach ($categoriasDespesas as $categoria) {
    $detalhesCategorias[(string)$categoria['id']] = [
        'nome' => $categoria['nome'],
        'total' => $categoria['total'],
        'lancamentos' => array_map(
            static function (array $lancamento): array {
                unset($lancamento['data_ordem']);
                return $lancamento;
            },
            $lancamentosPorCategoria[$categoria['id']] ?? []
        ),
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Demonstrativo Financeiro</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Demonstrativo Financeiro</h3>
                    <p class="text-muted mb-0">Resultado e composição dos gastos por competência</p>
                </div>
                <a href="financeiro.php?mes=<?= htmlspecialchars($mes) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (!$estruturaDisponivel): ?>
                <div class="alert alert-warning">
                    Execute o SQL das categorias financeiras para gerar este relatório.
                </div>
            <?php else: ?>
                <div class="financeiro-filtros mb-4">
                    <span class="financeiro-mes-titulo"><?= htmlspecialchars($nomePeriodo) ?></span>
                    <div class="financeiro-navegacao-mes financeiro-navegacao-relatorio">
                        <a
                            href="financeiro_relatorio.php?<?= http_build_query(['mes' => $mesAnterior, 'periodo' => $periodo]) ?>"
                            class="btn btn-outline-secondary"
                            aria-label="Período anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <form method="get" id="formMesRelatorio" class="financeiro-periodo-form">
                            <label for="mesRelatorio" class="visually-hidden">Escolher mês</label>
                            <input
                                type="month"
                                class="form-control financeiro-calendario"
                                name="mes"
                                id="mesRelatorio"
                                value="<?= htmlspecialchars($mes) ?>">
                            <label for="periodoRelatorio" class="visually-hidden">Quantidade de meses</label>
                            <select class="form-select" name="periodo" id="periodoRelatorio">
                                <?php for ($quantidade = 1; $quantidade <= 12; $quantidade++): ?>
                                    <option value="<?= $quantidade ?>" <?= $periodo === $quantidade ? 'selected' : '' ?>>
                                        <?= $quantidade ?> <?= $quantidade === 1 ? 'mês' : 'meses' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </form>
                        <a
                            href="financeiro_relatorio.php?<?= http_build_query(['mes' => $proximoMes, 'periodo' => $periodo]) ?>"
                            class="btn btn-outline-secondary"
                            aria-label="Próximo período">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <section class="financeiro-resumo financeiro-resumo-relatorio mb-4">
                    <div class="financeiro-metrica metrica-receita">
                        <span>Receitas do período</span>
                        <strong><?= financeiroMoeda($totalReceitasMes) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-despesa">
                        <span>Contas e faturas</span>
                        <strong><?= financeiroMoeda($totalDespesasMes) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-pendente">
                        <span>Compras no cartão</span>
                        <strong><?= financeiroMoeda($totalGastosCartaoPeriodo) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $resultadoMes < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>">
                        <span>Resultado do período</span>
                        <strong><?= financeiroMoeda($resultadoMes) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $totalSemCategoria > 0 ? 'metrica-pendente' : 'metrica-saldo' ?>">
                        <span>Sem categoria</span>
                        <strong><?= $totalSemCategoria ?></strong>
                    </div>
                </section>

                <div class="financeiro-graficos mb-4">
                    <section class="financeiro-painel financeiro-grafico">
                        <div class="financeiro-painel-titulo">
                            <div>
                                <h5 class="mb-1">Receitas x contas/faturas</h5>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($nomePeriodo) ?></p>
                            </div>
                        </div>
                        <div class="financeiro-grafico-corpo">
                            <canvas id="graficoEvolucao"></canvas>
                        </div>
                    </section>

                    <section class="financeiro-painel financeiro-grafico">
                        <div class="financeiro-painel-titulo">
                            <div>
                                <h5 class="mb-1">Despesas por categoria</h5>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($nomePeriodo) ?></p>
                            </div>
                        </div>
                        <div class="financeiro-grafico-corpo">
                            <canvas id="graficoCategorias"></canvas>
                        </div>
                    </section>
                </div>

                <section class="financeiro-painel mb-4">
                    <div class="financeiro-painel-titulo">
                        <div>
                            <h5 class="mb-1">Resultado por mês</h5>
                            <p class="text-muted small mb-0">Contas e faturas seguem o mês do pagamento ou vencimento</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela">
                            <thead>
                                <tr>
                                    <th>Mês</th>
                                    <th class="text-end">Receitas</th>
                                    <th class="text-end">Contas/faturas</th>
                                    <th class="text-end">Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($competencias as $dados): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($dados['mes']) ?></td>
                                        <td class="text-end text-success"><?= financeiroMoeda($dados['receitas']) ?></td>
                                        <td class="text-end text-danger"><?= financeiroMoeda($dados['despesas']) ?></td>
                                        <td class="text-end fw-semibold <?= $dados['resultado'] < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= financeiroMoeda($dados['resultado']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="financeiro-painel">
                    <div class="financeiro-painel-titulo">
                        <div>
                            <h5 class="mb-1">Detalhamento por categoria</h5>
                            <p class="text-muted small mb-0">Contas e compras do cartão</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela">
                            <thead>
                                <tr>
                                    <th>Categoria</th>
                                    <th class="text-end">Lançamentos</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Participação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($categoriasDespesas === []): ?>
                                    <tr>
                                        <td colspan="4" class="financeiro-vazio">Nenhuma despesa neste período.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($categoriasDespesas as $categoria): ?>
                                    <?php $participacao = $totalDespesasCategorias > 0 ? ($categoria['total'] / $totalDespesasCategorias) * 100 : 0; ?>
                                    <tr
                                        class="financeiro-categoria-clicavel"
                                        data-categoria-id="<?= (int)$categoria['id'] ?>"
                                        role="button"
                                        tabindex="0"
                                        aria-label="Abrir lançamentos de <?= htmlspecialchars($categoria['nome']) ?>">
                                        <td>
                                            <span class="financeiro-categoria-cor" style="background-color:<?= htmlspecialchars($categoria['cor']) ?>"></span>
                                            <?= htmlspecialchars($categoria['nome']) ?>
                                        </td>
                                        <td class="text-end"><?= (int)$categoria['quantidade'] ?></td>
                                        <td class="text-end"><?= financeiroMoeda($categoria['total']) ?></td>
                                        <td class="text-end"><?= number_format($participacao, 1, ',', '.') ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalDetalhesCategoria" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="tituloDetalhesCategoria">Lançamentos da categoria</h5>
                            <p class="text-muted small mb-0"><?= htmlspecialchars($nomePeriodo) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle financeiro-tabela mb-0">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Competência</th>
                                        <th>Descrição</th>
                                        <th>Origem</th>
                                        <th>Status</th>
                                        <th class="text-end">Valor</th>
                                    </tr>
                                </thead>
                                <tbody id="corpoDetalhesCategoria"></tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="5" class="text-end">Total</th>
                                        <th class="text-end" id="totalDetalhesCategoria"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <script src="<?= assetUrl('assets/financeiro.js') ?>"></script>
        <script>
            document.getElementById('mesRelatorio').addEventListener('change', function() {
                document.getElementById('formMesRelatorio').submit();
            });
            document.getElementById('periodoRelatorio').addEventListener('change', function() {
                document.getElementById('formMesRelatorio').submit();
            });

            const formatoMoeda = new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });
            const labelsMeses = <?= json_encode($labelsMeses, JSON_UNESCAPED_UNICODE) ?>;
            const dadosReceitas = <?= json_encode($dadosReceitas, JSON_NUMERIC_CHECK) ?>;
            const dadosDespesas = <?= json_encode($dadosDespesas, JSON_NUMERIC_CHECK) ?>;
            const labelsCategorias = <?= json_encode($labelsCategorias, JSON_UNESCAPED_UNICODE) ?>;
            const dadosCategorias = <?= json_encode($dadosCategorias, JSON_NUMERIC_CHECK) ?>;
            const coresCategorias = <?= json_encode($coresCategorias) ?>;
            const idsCategorias = <?= json_encode($idsCategorias, JSON_NUMERIC_CHECK) ?>;
            const detalhesCategorias = <?= json_encode(
                                            $detalhesCategorias,
                                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK
                                        ) ?>;
            const tooltipMoeda = {
                callbacks: {
                    label: function(contexto) {
                        return contexto.dataset.label + ': ' + formatoMoeda.format(contexto.parsed.y ?? contexto.parsed);
                    }
                }
            };

            function criarCelula(texto, classe) {
                const celula = document.createElement('td');
                celula.textContent = texto;

                if (classe) {
                    celula.className = classe;
                }

                return celula;
            }

            function abrirDetalhesCategoria(categoriaId) {
                const detalhes = detalhesCategorias[String(categoriaId)];

                if (!detalhes) {
                    return;
                }

                const corpo = document.getElementById('corpoDetalhesCategoria');
                corpo.replaceChildren();
                document.getElementById('tituloDetalhesCategoria').textContent =
                    'Lançamentos - ' + detalhes.nome;
                document.getElementById('totalDetalhesCategoria').textContent =
                    formatoMoeda.format(detalhes.total);

                detalhes.lancamentos.forEach(function(lancamento) {
                    const linha = document.createElement('tr');
                    const celulaStatus = document.createElement('td');
                    const status = document.createElement('span');

                    status.className = 'badge ' + lancamento.status_classe;
                    status.textContent = lancamento.status;
                    celulaStatus.appendChild(status);
                    linha.appendChild(criarCelula(lancamento.data));
                    linha.appendChild(criarCelula(lancamento.competencia));
                    linha.appendChild(criarCelula(lancamento.descricao));
                    linha.appendChild(criarCelula(lancamento.origem));
                    linha.appendChild(celulaStatus);
                    linha.appendChild(
                        criarCelula(formatoMoeda.format(lancamento.valor), 'text-end fw-semibold')
                    );
                    corpo.appendChild(linha);
                });

                if (!detalhes.lancamentos.length) {
                    const linhaVazia = document.createElement('tr');
                    const celulaVazia = criarCelula(
                        'Nenhum lançamento encontrado nesta categoria.',
                        'financeiro-vazio'
                    );
                    celulaVazia.colSpan = 6;
                    linhaVazia.appendChild(celulaVazia);
                    corpo.appendChild(linhaVazia);
                }

                bootstrap.Modal.getOrCreateInstance(
                    document.getElementById('modalDetalhesCategoria')
                ).show();
            }

            document.querySelectorAll('.financeiro-categoria-clicavel').forEach(function(linha) {
                linha.addEventListener('click', function() {
                    abrirDetalhesCategoria(this.dataset.categoriaId);
                });
                linha.addEventListener('keydown', function(evento) {
                    if (evento.key === 'Enter' || evento.key === ' ') {
                        evento.preventDefault();
                        abrirDetalhesCategoria(this.dataset.categoriaId);
                    }
                });
            });

            new Chart(document.getElementById('graficoEvolucao'), {
                type: 'bar',
                data: {
                    labels: labelsMeses,
                    datasets: [{
                            label: 'Receitas',
                            data: dadosReceitas,
                            backgroundColor: '#198754'
                        },
                        {
                            label: 'Contas/faturas',
                            data: dadosDespesas,
                            backgroundColor: '#dc3545'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: tooltipMoeda
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(valor) {
                                    return formatoMoeda.format(valor);
                                }
                            }
                        }
                    }
                }
            });

            const graficoCategorias = new Chart(document.getElementById('graficoCategorias'), {
                type: 'doughnut',
                data: {
                    labels: labelsCategorias.length ? labelsCategorias : ['Sem despesas'],
                    datasets: [{
                        label: 'Despesas',
                        data: dadosCategorias.length ? dadosCategorias : [1],
                        backgroundColor: coresCategorias.length ? coresCategorias : ['#e2e8f0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: dadosCategorias.length ? tooltipMoeda : {
                            enabled: false
                        }
                    },
                    onHover: function(evento, elementos) {
                        evento.native.target.style.cursor = elementos.length ? 'pointer' : 'default';
                    },
                    onClick: function(evento, elementos) {
                        if (!elementos.length || !idsCategorias.length) {
                            return;
                        }

                        abrirDetalhesCategoria(idsCategorias[elementos[0].index]);
                    }
                }
            });
        </script>
    <?php endif; ?>
</body>

</html>