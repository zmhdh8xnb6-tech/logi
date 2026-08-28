<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$proximoMes = date('Y-m', strtotime($inicioMes . ' +1 month'));
$urlRetorno = 'financeiro_planejamento.php?mes=' . urlencode($mes);
$categoriasDisponiveis = financeiroCategoriasDisponiveis($pdo);
$planejamentoDisponivel = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_planejamentos']
);
$estruturaDisponivel = $categoriasDisponiveis && $planejamentoDisponivel;
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

if ($categoriasDisponiveis) {
    financeiroGarantirCategoriasPadrao($pdo, $usuarioId);
    financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
    financeiroSincronizarContasRecorrentes($pdo, $usuarioId, $mes);
    financeiroSincronizarRecebimentosRecorrentes($pdo, $usuarioId, $mes);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$estruturaDisponivel) {
        financeiroRedirecionar(
            $urlRetorno,
            'Execute o SQL do planejamento financeiro antes de continuar.',
            'danger'
        );
    }

    if (!financeiroTokenValido($_POST['csrf_token'] ?? null)) {
        financeiroRedirecionar(
            $urlRetorno,
            'A sessão do formulário expirou. Tente novamente.',
            'danger'
        );
    }

    if (($_POST['acao'] ?? '') === 'salvar_planejamento') {
        $valoresInformados = $_POST['planejado'] ?? [];

        if (!is_array($valoresInformados)) {
            financeiroRedirecionar($urlRetorno, 'Valores do planejamento inválidos.', 'danger');
        }

        $stmt = $pdo->prepare("
            SELECT id, nome
            FROM financeiro_categorias
            WHERE usuario_id = ? AND ativa = 1
        ");
        $stmt->execute([$usuarioId]);
        $categoriasValidas = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $categoria) {
            $categoriasValidas[(int)$categoria['id']] = $categoria['nome'];
        }

        $stmt = $pdo->prepare("
            SELECT categoria_id, valor_planejado
            FROM financeiro_planejamentos
            WHERE usuario_id = ? AND competencia = ?
        ");
        $stmt->execute([$usuarioId, $inicioMes]);
        $valoresAntes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $planejamento) {
            $valoresAntes[(int)$planejamento['categoria_id']] = (float)$planejamento['valor_planejado'];
        }

        $valoresDepois = [];

        foreach ($categoriasValidas as $categoriaId => $categoriaNome) {
            $valorTexto = trim((string)($valoresInformados[$categoriaId] ?? '0,00'));

            if ($valorTexto === '') {
                $valorTexto = '0,00';
            }

            if (!financeiroValorValido($valorTexto)) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Informe um valor válido para a categoria ' . $categoriaNome . '.',
                    'danger'
                );
            }

            $valor = financeiroValorEntrada($valorTexto);

            if ($valor < 0 || $valor > 9999999999.99) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'O valor da categoria ' . $categoriaNome . ' está fora do limite permitido.',
                    'danger'
                );
            }

            $valoresDepois[$categoriaId] = $valor;
        }

        try {
            $pdo->beginTransaction();
            $stmtSalvar = $pdo->prepare("
                INSERT INTO financeiro_planejamentos
                    (usuario_id, categoria_id, competencia, valor_planejado)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    valor_planejado = VALUES(valor_planejado),
                    atualizado_em = CURRENT_TIMESTAMP
            ");
            $stmtExcluir = $pdo->prepare("
                DELETE FROM financeiro_planejamentos
                WHERE usuario_id = ?
                  AND categoria_id = ?
                  AND competencia = ?
            ");

            foreach ($valoresDepois as $categoriaId => $valor) {
                if ($valor <= 0) {
                    $stmtExcluir->execute([$usuarioId, $categoriaId, $inicioMes]);
                    continue;
                }

                $stmtSalvar->execute([$usuarioId, $categoriaId, $inicioMes, $valor]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            financeiroRedirecionar(
                $urlRetorno,
                'Não foi possível salvar o planejamento financeiro.',
                'danger'
            );
        }

        $auditoriaAntes = [];
        $auditoriaDepois = [];

        foreach ($categoriasValidas as $categoriaId => $categoriaNome) {
            $valorAntes = $valoresAntes[$categoriaId] ?? 0.0;
            $valorDepois = $valoresDepois[$categoriaId] ?? 0.0;

            if ($valorAntes == $valorDepois) {
                continue;
            }

            $auditoriaAntes[$categoriaNome] = $valorAntes;
            $auditoriaDepois[$categoriaNome] = $valorDepois;
        }

        if ($auditoriaAntes !== [] || $auditoriaDepois !== []) {
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'editar',
                'planejamento_financeiro',
                $mes,
                'Atualizou o planejamento financeiro de ' . $nomeMes,
                $auditoriaAntes,
                $auditoriaDepois
            );
        }

        financeiroRedirecionar($urlRetorno, 'Planejamento atualizado com sucesso.');
    }
}

$mensagem = financeiroObterMensagem();
$categoriasPorTipo = ['receita' => [], 'despesa' => []];
$planejamentos = [];
$realizados = [];
$semCategoria = 0;

if ($categoriasDisponiveis) {
    $stmt = $pdo->prepare("
        SELECT id, nome, tipo, cor
        FROM financeiro_categorias
        WHERE usuario_id = ? AND ativa = 1
        ORDER BY tipo DESC, nome
    ");
    $stmt->execute([$usuarioId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $categoria) {
        $categoria['id'] = (int)$categoria['id'];
        $categoriasPorTipo[$categoria['tipo']][] = $categoria;
        $realizados[$categoria['id']] = 0.0;
    }

    if ($planejamentoDisponivel) {
        $stmt = $pdo->prepare("
            SELECT categoria_id, valor_planejado
            FROM financeiro_planejamentos
            WHERE usuario_id = ? AND competencia = ?
        ");
        $stmt->execute([$usuarioId, $inicioMes]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $planejamento) {
            $planejamentos[(int)$planejamento['categoria_id']] = (float)$planejamento['valor_planejado'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT categoria_id, SUM(valor) AS total
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND data_recebimento >= ?
          AND data_recebimento < ?
          AND categoria_id IS NOT NULL
        GROUP BY categoria_id
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $categoriaId = (int)$linha['categoria_id'];
        $realizados[$categoriaId] = ($realizados[$categoriaId] ?? 0.0) + (float)$linha['total'];
    }

    $stmt = $pdo->prepare("
        SELECT categoria_id, SUM(valor_previsto) AS total
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND vencimento >= ?
          AND vencimento < ?
          AND cartao_id IS NULL
          AND categoria_id IS NOT NULL
        GROUP BY categoria_id
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $categoriaId = (int)$linha['categoria_id'];
        $realizados[$categoriaId] = ($realizados[$categoriaId] ?? 0.0) + (float)$linha['total'];
    }

    $temCompetenciaFatura = financeiroColunaExiste(
        $pdo,
        'financeiro_cartao_lancamentos',
        'competencia_fatura'
    );
    $dataCompetenciaCartao = $temCompetenciaFatura
        ? 'COALESCE(competencia_fatura, data_compra)'
        : 'data_compra';
    $stmt = $pdo->prepare("
        SELECT categoria_id, SUM(valor) AS total
        FROM financeiro_cartao_lancamentos
        WHERE usuario_id = ?
          AND {$dataCompetenciaCartao} >= ?
          AND {$dataCompetenciaCartao} < ?
          AND categoria_id IS NOT NULL
        GROUP BY categoria_id
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $categoriaId = (int)$linha['categoria_id'];
        $realizados[$categoriaId] = ($realizados[$categoriaId] ?? 0.0) + (float)$linha['total'];
    }

    $consultasSemCategoria = [
        [
            "SELECT COUNT(*) FROM financeiro_recebimentos
             WHERE usuario_id = ? AND data_recebimento >= ? AND data_recebimento < ?
               AND categoria_id IS NULL",
            [$usuarioId, $inicioMes, $fimMes],
        ],
        [
            "SELECT COUNT(*) FROM financeiro_contas
             WHERE usuario_id = ? AND vencimento >= ? AND vencimento < ?
               AND cartao_id IS NULL AND categoria_id IS NULL",
            [$usuarioId, $inicioMes, $fimMes],
        ],
        [
            "SELECT COUNT(*) FROM financeiro_cartao_lancamentos
             WHERE usuario_id = ? AND {$dataCompetenciaCartao} >= ? AND {$dataCompetenciaCartao} < ?
               AND categoria_id IS NULL",
            [$usuarioId, $inicioMes, $fimMes],
        ],
    ];

    foreach ($consultasSemCategoria as [$sql, $parametros]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parametros);
        $semCategoria += (int)$stmt->fetchColumn();
    }
}

$totais = [
    'receita_planejada' => 0.0,
    'receita_realizada' => 0.0,
    'despesa_planejada' => 0.0,
    'despesa_realizada' => 0.0,
];

foreach ($categoriasPorTipo as $tipo => &$categorias) {
    foreach ($categorias as &$categoria) {
        $categoriaId = (int)$categoria['id'];
        $categoria['planejado'] = $planejamentos[$categoriaId] ?? 0.0;
        $categoria['realizado'] = $realizados[$categoriaId] ?? 0.0;
        $categoria['diferenca'] = $tipo === 'receita'
            ? $categoria['realizado'] - $categoria['planejado']
            : $categoria['planejado'] - $categoria['realizado'];
        $categoria['percentual'] = $categoria['planejado'] > 0
            ? ($categoria['realizado'] / $categoria['planejado']) * 100
            : ($categoria['realizado'] > 0 ? 100 : 0);
        $totais[$tipo . '_planejada'] += $categoria['planejado'];
        $totais[$tipo . '_realizada'] += $categoria['realizado'];
    }
    unset($categoria);
}
unset($categorias);

$saldoPlanejado = $totais['receita_planejada'] - $totais['despesa_planejada'];
$saldoLancado = $totais['receita_realizada'] - $totais['despesa_realizada'];
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Planejamento financeiro</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Planejamento financeiro</h3>
                    <p class="text-muted mb-0">Defina metas mensais e acompanhe o que já foi lançado</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($estruturaDisponivel): ?>
                        <button type="submit" class="btn btn-success" form="formPlanejamento">
                            <i class="bi bi-check-lg"></i> Salvar planejamento
                        </button>
                    <?php endif; ?>
                    <a href="financeiro.php?mes=<?= htmlspecialchars($mes) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$categoriasDisponiveis): ?>
                <div class="alert alert-warning">
                    Prepare as categorias financeiras antes de criar o planejamento.
                </div>
            <?php elseif (!$planejamentoDisponivel): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o arquivo <code>sql/financeiro_planejamentos.sql</code> no phpMyAdmin.
                </div>
            <?php else: ?>
                <div class="financeiro-filtros mb-4">
                    <span class="financeiro-mes-titulo"><?= htmlspecialchars($nomeMes) ?></span>
                    <div class="financeiro-navegacao-mes">
                        <a
                            href="financeiro_planejamento.php?mes=<?= htmlspecialchars($mesAnterior) ?>"
                            class="btn btn-outline-secondary"
                            title="Mês anterior"
                            aria-label="Mês anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <form method="get" id="formMesPlanejamento">
                            <label for="mesPlanejamento" class="visually-hidden">Escolher mês</label>
                            <input
                                type="month"
                                class="form-control financeiro-calendario"
                                name="mes"
                                id="mesPlanejamento"
                                value="<?= htmlspecialchars($mes) ?>">
                        </form>
                        <a
                            href="financeiro_planejamento.php?mes=<?= htmlspecialchars(date('Y-m')) ?>"
                            class="btn btn-outline-primary"
                            title="Voltar para o mês atual"
                            aria-label="Voltar para o mês atual">
                            <i class="bi bi-calendar-check"></i>
                        </a>
                        <a
                            href="financeiro_planejamento.php?mes=<?= htmlspecialchars($proximoMes) ?>"
                            class="btn btn-outline-secondary"
                            title="Próximo mês"
                            aria-label="Próximo mês">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <?php if ($semCategoria > 0): ?>
                    <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <span>
                            <strong><?= $semCategoria ?> lançamento<?= $semCategoria === 1 ? '' : 's' ?> sem categoria.</strong>
                            Eles não entram na comparação abaixo.
                        </span>
                        <a href="financeiro.php?mes=<?= htmlspecialchars($mes) ?>" class="btn btn-sm btn-outline-dark">
                            Revisar lançamentos
                        </a>
                    </div>
                <?php endif; ?>

                <section class="financeiro-resumo financeiro-resumo-planejamento mb-4" aria-label="Resumo do planejamento">
                    <div class="financeiro-metrica metrica-receita">
                        <span>Receitas planejadas</span>
                        <strong id="totalReceitaPlanejada"><?= financeiroMoeda($totais['receita_planejada']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-receita">
                        <span>Receitas lançadas</span>
                        <strong><?= financeiroMoeda($totais['receita_realizada']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-despesa">
                        <span>Despesas planejadas</span>
                        <strong id="totalDespesaPlanejada"><?= financeiroMoeda($totais['despesa_planejada']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-despesa">
                        <span>Despesas comprometidas</span>
                        <strong><?= financeiroMoeda($totais['despesa_realizada']) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $saldoPlanejado < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>" id="metricaSaldoPlanejado">
                        <span>Saldo planejado</span>
                        <strong id="saldoPlanejado"><?= financeiroMoeda($saldoPlanejado) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $saldoLancado < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>">
                        <span>Saldo dos lançamentos</span>
                        <strong><?= financeiroMoeda($saldoLancado) ?></strong>
                    </div>
                </section>

                <form method="post" id="formPlanejamento">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                    <input type="hidden" name="acao" value="salvar_planejamento">
                    <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">

                    <div class="financeiro-planejamento-grid mb-4">
                        <?php
                        $configuracoes = [
                            'receita' => [
                                'titulo' => 'Receitas',
                                'descricao' => 'Quanto pretende receber em cada categoria',
                                'icone' => 'bi-arrow-down-circle',
                                'classe' => 'text-success',
                                'coluna_planejado' => 'Planejado',
                                'coluna_realizado' => 'Lançado',
                                'coluna_diferenca' => 'Diferença',
                            ],
                            'despesa' => [
                                'titulo' => 'Despesas',
                                'descricao' => 'Defina o limite de gasto de cada categoria',
                                'icone' => 'bi-arrow-up-circle',
                                'classe' => 'text-danger',
                                'coluna_planejado' => 'Limite',
                                'coluna_realizado' => 'Usado',
                                'coluna_diferenca' => 'Disponível',
                            ],
                        ];
                        ?>
                        <?php foreach ($configuracoes as $tipo => $configuracao): ?>
                            <section class="financeiro-painel">
                                <div class="financeiro-painel-titulo">
                                    <div>
                                        <h5 class="mb-1 <?= $configuracao['classe'] ?>">
                                            <i class="bi <?= $configuracao['icone'] ?>"></i>
                                            <?= $configuracao['titulo'] ?>
                                        </h5>
                                        <p class="text-muted small mb-0"><?= $configuracao['descricao'] ?></p>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle financeiro-tabela financeiro-planejamento-tabela">
                                        <thead>
                                            <tr>
                                                <th>Categoria</th>
                                                <th class="text-end"><?= $configuracao['coluna_planejado'] ?></th>
                                                <th class="text-end"><?= $configuracao['coluna_realizado'] ?></th>
                                                <th class="text-end"><?= $configuracao['coluna_diferenca'] ?></th>
                                                <th>Acompanhamento</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($categoriasPorTipo[$tipo] === []): ?>
                                                <tr>
                                                    <td colspan="5" class="financeiro-vazio">
                                                        Nenhuma categoria ativa.
                                                        <a href="financeiro_categorias.php">Gerenciar categorias</a>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($categoriasPorTipo[$tipo] as $categoria): ?>
                                                <?php
                                                $percentualBarra = min(100, max(0, $categoria['percentual']));
                                                $acimaLimite = $tipo === 'despesa'
                                                    && $categoria['planejado'] > 0
                                                    && $categoria['realizado'] > $categoria['planejado'];
                                                $metaAlcancada = $tipo === 'receita'
                                                    && $categoria['planejado'] > 0
                                                    && $categoria['realizado'] >= $categoria['planejado'];
                                                $semMeta = $categoria['planejado'] <= 0;

                                                if ($semMeta) {
                                                    $status = $categoria['realizado'] > 0 ? 'Sem limite definido' : 'Sem planejamento';
                                                    $statusClasse = $categoria['realizado'] > 0 && $tipo === 'despesa'
                                                        ? 'bg-danger'
                                                        : 'bg-secondary';
                                                    $barraClasse = 'bg-secondary';
                                                } elseif ($acimaLimite) {
                                                    $status = 'Acima do limite';
                                                    $statusClasse = 'bg-danger';
                                                    $barraClasse = 'bg-danger';
                                                } elseif ($metaAlcancada) {
                                                    $status = 'Meta alcançada';
                                                    $statusClasse = 'bg-success';
                                                    $barraClasse = 'bg-success';
                                                } elseif ($categoria['percentual'] >= 80) {
                                                    $status = $tipo === 'despesa' ? 'Atenção ao limite' : 'Próximo da meta';
                                                    $statusClasse = 'bg-warning text-dark';
                                                    $barraClasse = 'bg-warning';
                                                } else {
                                                    $status = $tipo === 'despesa' ? 'Dentro do limite' : 'Em andamento';
                                                    $statusClasse = $tipo === 'despesa' ? 'bg-success' : 'bg-primary';
                                                    $barraClasse = $tipo === 'despesa' ? 'bg-success' : 'bg-primary';
                                                }
                                                ?>
                                                <tr
                                                    class="planejamento-linha"
                                                    data-tipo="<?= htmlspecialchars($tipo) ?>"
                                                    data-realizado="<?= number_format($categoria['realizado'], 2, '.', '') ?>">
                                                    <td>
                                                        <span
                                                            class="financeiro-categoria-cor"
                                                            style="background-color:<?= htmlspecialchars($categoria['cor']) ?>"></span>
                                                        <strong><?= htmlspecialchars($categoria['nome']) ?></strong>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="input-group input-group-sm planejamento-valor-grupo ms-auto">
                                                            <span class="input-group-text">R$</span>
                                                            <input
                                                                type="text"
                                                                class="form-control text-end campo-moeda planejamento-valor"
                                                                name="planejado[<?= (int)$categoria['id'] ?>]"
                                                                value="<?= number_format($categoria['planejado'], 2, ',', '.') ?>"
                                                                inputmode="decimal"
                                                                aria-label="Valor planejado para <?= htmlspecialchars($categoria['nome']) ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-end planejamento-realizado">
                                                        <?= financeiroMoeda($categoria['realizado']) ?>
                                                    </td>
                                                    <td class="text-end fw-semibold planejamento-diferenca <?= $categoria['diferenca'] < 0 ? 'text-danger' : 'text-success' ?>">
                                                        <?= financeiroMoeda($categoria['diferenca']) ?>
                                                    </td>
                                                    <td>
                                                        <div class="planejamento-acompanhamento">
                                                            <div class="progress" role="progressbar" aria-valuenow="<?= round($categoria['percentual'], 1) ?>" aria-valuemin="0" aria-valuemax="100">
                                                                <div class="progress-bar <?= $barraClasse ?>" style="width:<?= $percentualBarra ?>%"></div>
                                                            </div>
                                                            <span class="badge <?= $statusClasse ?> planejamento-status"><?= $status ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg"></i> Salvar planejamento
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= assetUrl('assets/financeiro.js') ?>"></script>
    <?php if ($estruturaDisponivel): ?>
        <script>
            const moedaPlanejamento = new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL',
            });

            function numeroPlanejamento(valor) {
                const texto = String(valor || '').trim();

                if (texto === '') {
                    return 0;
                }

                const normalizado = texto.includes(',') ?
                    texto.replace(/\./g, '').replace(',', '.') :
                    texto;
                const numero = Number(normalizado);

                return Number.isFinite(numero) ? numero : 0;
            }

            function atualizarLinhaPlanejamento(linha) {
                const tipo = linha.dataset.tipo;
                const realizado = Number(linha.dataset.realizado || 0);
                const planejado = numeroPlanejamento(linha.querySelector('.planejamento-valor').value);
                const diferenca = tipo === 'receita' ?
                    realizado - planejado :
                    planejado - realizado;
                const percentual = planejado > 0 ?
                    (realizado / planejado) * 100 :
                    (realizado > 0 ? 100 : 0);
                const diferencaElemento = linha.querySelector('.planejamento-diferenca');
                const barra = linha.querySelector('.progress-bar');
                const status = linha.querySelector('.planejamento-status');
                let statusTexto;
                let statusClasses;
                let barraClasse;

                diferencaElemento.textContent = moedaPlanejamento.format(diferenca);
                diferencaElemento.classList.toggle('text-danger', diferenca < 0);
                diferencaElemento.classList.toggle('text-success', diferenca >= 0);
                barra.style.width = Math.min(100, Math.max(0, percentual)) + '%';
                barra.parentElement.setAttribute('aria-valuenow', percentual.toFixed(1));

                if (planejado <= 0) {
                    statusTexto = realizado > 0 ? 'Sem limite definido' : 'Sem planejamento';
                    statusClasses = realizado > 0 && tipo === 'despesa' ?
                        'bg-danger' :
                        'bg-secondary';
                    barraClasse = 'bg-secondary';
                } else if (tipo === 'despesa' && realizado > planejado) {
                    statusTexto = 'Acima do limite';
                    statusClasses = 'bg-danger';
                    barraClasse = 'bg-danger';
                } else if (tipo === 'receita' && realizado >= planejado) {
                    statusTexto = 'Meta alcançada';
                    statusClasses = 'bg-success';
                    barraClasse = 'bg-success';
                } else if (percentual >= 80) {
                    statusTexto = tipo === 'despesa' ? 'Atenção ao limite' : 'Próximo da meta';
                    statusClasses = 'bg-warning text-dark';
                    barraClasse = 'bg-warning';
                } else {
                    statusTexto = tipo === 'despesa' ? 'Dentro do limite' : 'Em andamento';
                    statusClasses = tipo === 'despesa' ? 'bg-success' : 'bg-primary';
                    barraClasse = tipo === 'despesa' ? 'bg-success' : 'bg-primary';
                }

                status.textContent = statusTexto;
                status.className = 'badge planejamento-status ' + statusClasses;
                barra.className = 'progress-bar ' + barraClasse;
            }

            function atualizarResumoPlanejamento() {
                let receitas = 0;
                let despesas = 0;

                document.querySelectorAll('.planejamento-linha').forEach(function(linha) {
                    const valor = numeroPlanejamento(linha.querySelector('.planejamento-valor').value);

                    if (linha.dataset.tipo === 'receita') {
                        receitas += valor;
                    } else {
                        despesas += valor;
                    }
                });

                const saldo = receitas - despesas;
                const metricaSaldo = document.getElementById('metricaSaldoPlanejado');

                document.getElementById('totalReceitaPlanejada').textContent = moedaPlanejamento.format(receitas);
                document.getElementById('totalDespesaPlanejada').textContent = moedaPlanejamento.format(despesas);
                document.getElementById('saldoPlanejado').textContent = moedaPlanejamento.format(saldo);
                metricaSaldo.classList.toggle('metrica-negativa', saldo < 0);
                metricaSaldo.classList.toggle('metrica-saldo', saldo >= 0);
            }

            document.querySelectorAll('.planejamento-valor').forEach(function(campo) {
                campo.addEventListener('input', function() {
                    atualizarLinhaPlanejamento(this.closest('.planejamento-linha'));
                    atualizarResumoPlanejamento();
                });
            });

            document.getElementById('mesPlanejamento').addEventListener('change', function() {
                document.getElementById('formMesPlanejamento').submit();
            });

            setTimeout(function() {
                document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                    alerta.classList.remove('show');
                    setTimeout(function() {
                        alerta.remove();
                    }, 200);
                });
            }, 4000);
        </script>
    <?php endif; ?>
</body>

</html>