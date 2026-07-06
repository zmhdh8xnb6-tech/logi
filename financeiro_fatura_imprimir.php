<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$cartaoId = (int)($_GET['cartao'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? null);
$periodo = max(1, min(12, (int)($_GET['periodo'] ?? 1)));
$inicioMes = $mes . '-01';
$inicioPeriodo = date(
    'Y-m-d',
    strtotime($inicioMes . ' -' . ($periodo - 1) . ' months')
);
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$tabelasDisponiveis = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_cartoes', 'financeiro_cartao_lancamentos']
);
$cartao = null;
$lancamentos = [];

if ($tabelasDisponiveis && $cartaoId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_cartoes
        WHERE id = ? AND usuario_id = ?
    ");
    $stmt->execute([$cartaoId, $usuarioId]);
    $cartao = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$cartao) {
    http_response_code(404);
}

$temCompetenciaFatura = $tabelasDisponiveis
    && financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura');
$expressaoCompetencia = $temCompetenciaFatura
    ? "COALESCE(l.competencia_fatura, DATE_FORMAT(l.data_compra, '%Y-%m-01'))"
    : "DATE_FORMAT(l.data_compra, '%Y-%m-01')";
$categoriasDisponiveis = $tabelasDisponiveis && financeiroCategoriasDisponiveis($pdo);
$selectCategoria = $categoriasDisponiveis
    ? "COALESCE(c.nome, 'Sem categoria')"
    : "'Sem categoria'";
$joinCategoria = $categoriasDisponiveis
    ? 'LEFT JOIN financeiro_categorias c
           ON c.id = l.categoria_id
          AND c.usuario_id = l.usuario_id'
    : '';

if ($cartao) {
    $stmt = $pdo->prepare("
        SELECT
            l.*,
            {$selectCategoria} AS categoria,
            DATE_FORMAT({$expressaoCompetencia}, '%Y-%m') AS competencia_relatorio
        FROM financeiro_cartao_lancamentos l
        {$joinCategoria}
        WHERE l.cartao_id = ?
          AND l.usuario_id = ?
          AND {$expressaoCompetencia} >= ?
          AND {$expressaoCompetencia} < ?
        ORDER BY l.data_compra, l.id
    ");
    $stmt->execute([$cartaoId, $usuarioId, $inicioPeriodo, $fimMes]);
    $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
$nomePrimeiroMes = $nomesMeses[(int)date('n', strtotime($inicioPeriodo))]
    . '/'
    . date('Y', strtotime($inicioPeriodo));
$nomePeriodo = $periodo === 1
    ? $nomeMes
    : $nomePrimeiroMes . ' a ' . $nomeMes;
$vencimento = $cartao && !empty($cartao['dia_vencimento'])
    ? financeiroVencimentoFatura($mes, (int)$cartao['dia_vencimento'])
    : null;
$totalFatura = array_sum(array_map('floatval', array_column($lancamentos, 'valor')));
$totalAberto = array_sum(array_map(
    static fn(array $lancamento): float => $lancamento['status'] === 'aberto'
        ? (float)$lancamento['valor']
        : 0.0,
    $lancamentos
));

$possuiFaturaAtrasada = false;

foreach ($lancamentos as $lancamento) {
    if (
        $lancamento['status'] === 'aberto'
        && !empty($cartao['dia_vencimento'])
        && financeiroVencimentoFatura(
            $lancamento['competencia_relatorio'],
            (int)$cartao['dia_vencimento']
        ) < date('Y-m-d')
    ) {
        $possuiFaturaAtrasada = true;
        break;
    }
}

if ($lancamentos === []) {
    $statusFatura = 'Sem lançamentos';
    $classeStatus = 'secondary';
} elseif ($totalAberto <= 0) {
    $statusFatura = 'Paga';
    $classeStatus = 'success';
} elseif ($possuiFaturaAtrasada) {
    $statusFatura = $periodo === 1 ? 'Atrasada' : 'Possui atraso';
    $classeStatus = 'danger';
} else {
    $statusFatura = $periodo === 1 ? 'Em aberto' : 'Possui pendências';
    $classeStatus = 'warning text-dark';
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Fatura <?= htmlspecialchars($cartao['nome'] ?? '') ?> - <?= htmlspecialchars($nomePeriodo) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="financeiro-impressao">
    <main class="financeiro-impressao-pagina">
        <div class="financeiro-impressao-acoes no-print">
            <a
                href="financeiro_cartoes.php?<?= http_build_query(['cartao' => $cartaoId, 'mes' => $mes]) ?>"
                class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
            <form method="get" class="financeiro-impressao-filtros" id="formPeriodoFatura">
                <input type="hidden" name="cartao" value="<?= $cartaoId ?>">
                <label for="mesFaturaRelatorio" class="visually-hidden">Mês final</label>
                <input
                    type="month"
                    class="form-control financeiro-calendario"
                    name="mes"
                    id="mesFaturaRelatorio"
                    value="<?= htmlspecialchars($mes) ?>">
                <label for="periodoFaturaRelatorio" class="visually-hidden">Quantidade de meses</label>
                <select class="form-select" name="periodo" id="periodoFaturaRelatorio">
                    <?php for ($quantidade = 1; $quantidade <= 12; $quantidade++): ?>
                        <option value="<?= $quantidade ?>" <?= $periodo === $quantidade ? 'selected' : '' ?>>
                            <?= $quantidade ?> <?= $quantidade === 1 ? 'mês' : 'meses' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> Imprimir
            </button>
        </div>

        <?php if (!$cartao): ?>
            <div class="alert alert-danger">Cartão não encontrado.</div>
        <?php else: ?>
            <header class="financeiro-impressao-cabecalho">
                <div>
                    <span class="financeiro-impressao-marca">LOGI | CONTROLE FINANCEIRO</span>
                    <h1><?= $periodo === 1 ? 'Fatura do cartão' : 'Relatório de faturas' ?></h1>
                    <p><?= htmlspecialchars($cartao['nome']) ?></p>
                </div>
                <span class="badge bg-<?= htmlspecialchars($classeStatus) ?>">
                    <?= htmlspecialchars($statusFatura) ?>
                </span>
            </header>

            <section class="financeiro-impressao-resumo">
                <div>
                    <span>Período</span>
                    <strong><?= htmlspecialchars($nomePeriodo) ?></strong>
                </div>
                <div>
                    <span><?= $periodo === 1 ? 'Vencimento' : 'Último vencimento' ?></span>
                    <strong><?= $vencimento ? financeiroData($vencimento) : '-' ?></strong>
                </div>
                <div>
                    <span><?= $periodo === 1 ? 'Total da fatura' : 'Total do período' ?></span>
                    <strong><?= financeiroMoeda($totalFatura) ?></strong>
                </div>
                <div>
                    <span>Em aberto</span>
                    <strong><?= financeiroMoeda($totalAberto) ?></strong>
                </div>
            </section>

            <div class="table-responsive">
                <table class="table align-middle financeiro-tabela financeiro-impressao-tabela">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Competência</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Parcela</th>
                            <th>Status</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($lancamentos === []): ?>
                            <tr>
                                <td colspan="7" class="financeiro-vazio">Nenhuma compra neste período.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lancamentos as $lancamento): ?>
                            <?php
                            $vencimentoLancamento = !empty($cartao['dia_vencimento'])
                                ? financeiroVencimentoFatura(
                                    $lancamento['competencia_relatorio'],
                                    (int)$cartao['dia_vencimento']
                                )
                                : null;
                            $statusLancamento = $lancamento['status'] === 'pago'
                                ? 'Pago'
                                : ($vencimentoLancamento && $vencimentoLancamento < date('Y-m-d')
                                    ? 'Atrasado'
                                    : 'Em aberto');
                            ?>
                            <tr>
                                <td><?= financeiroData($lancamento['data_compra']) ?></td>
                                <td><?= htmlspecialchars(date('m/Y', strtotime($lancamento['competencia_relatorio'] . '-01'))) ?></td>
                                <td><?= htmlspecialchars($lancamento['descricao']) ?></td>
                                <td><?= htmlspecialchars($lancamento['categoria']) ?></td>
                                <td>
                                    <?= !empty($lancamento['parcela_numero'])
                                        ? (int)$lancamento['parcela_numero'] . '/' . (int)$lancamento['parcelas_total']
                                        : '-' ?>
                                </td>
                                <td><?= $statusLancamento ?></td>
                                <td class="text-end"><?= financeiroMoeda((float)$lancamento['valor']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="6" class="text-end">Total</th>
                            <th class="text-end"><?= financeiroMoeda($totalFatura) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <footer class="financeiro-impressao-rodape">
                Emitido em <?= date('d/m/Y') ?> às <?= date('H:i') ?>
            </footer>
        <?php endif; ?>
    </main>
    <script>
        document.getElementById('mesFaturaRelatorio')?.addEventListener('change', function() {
            document.getElementById('formPeriodoFatura').submit();
        });
        document.getElementById('periodoFaturaRelatorio')?.addEventListener('change', function() {
            document.getElementById('formPeriodoFatura').submit();
        });
    </script>
</body>

</html>