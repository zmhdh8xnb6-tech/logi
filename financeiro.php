<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$tabelasDisponiveis = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_recebimentos', 'financeiro_contas']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $urlRetorno = 'financeiro.php?mes=' . urlencode($mes);

    if (!$tabelasDisponiveis) {
        financeiroRedirecionar($urlRetorno, 'Execute o SQL do financeiro antes de cadastrar.', 'danger');
    }

    if (!financeiroTokenValido($_POST['csrf_token'] ?? null)) {
        financeiroRedirecionar($urlRetorno, 'A sessão do formulário expirou. Tente novamente.', 'danger');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar_recebimento') {
        $data = trim($_POST['data_recebimento'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $recebidoDe = trim($_POST['recebido_de'] ?? '');
        $valor = financeiroValorEntrada($_POST['valor'] ?? '');

        if ($data === '' || $descricao === '' || $valor <= 0) {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados do recebimento corretamente.', 'danger');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE financeiro_recebimentos
                SET data_recebimento = ?, descricao = ?, recebido_de = ?, valor = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$data, $descricao, $recebidoDe, $valor, $id, $usuarioId]);
            financeiroRedirecionar($urlRetorno, 'Recebimento atualizado com sucesso.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_recebimentos
                (usuario_id, data_recebimento, descricao, recebido_de, valor)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuarioId, $data, $descricao, $recebidoDe, $valor]);
        financeiroRedirecionar($urlRetorno, 'Recebimento cadastrado com sucesso.');
    }

    if ($acao === 'excluir_recebimento') {
        $stmt = $pdo->prepare("DELETE FROM financeiro_recebimentos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuarioId]);
        financeiroRedirecionar($urlRetorno, 'Recebimento excluído com sucesso.');
    }

    if ($acao === 'salvar_conta') {
        $descricao = trim($_POST['descricao'] ?? '');
        $valorPrevisto = financeiroValorEntrada($_POST['valor_previsto'] ?? '');
        $vencimento = trim($_POST['vencimento'] ?? '');
        $tipoLancamento = ($_POST['tipo_lancamento'] ?? '') === 'parcelada' ? 'parcelada' : 'unica';
        $parcelaInicial = (int)($_POST['parcela_inicial'] ?? 1);
        $parcelasTotal = (int)($_POST['parcelas_total'] ?? 1);

        if ($descricao === '' || $valorPrevisto <= 0 || $vencimento === '') {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados da conta corretamente.', 'danger');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE financeiro_contas
                SET descricao = ?, valor_previsto = ?, vencimento = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$descricao, $valorPrevisto, $vencimento, $id, $usuarioId]);
            financeiroRedirecionar($urlRetorno, 'Conta atualizada com sucesso.');
        }

        if ($tipoLancamento === 'parcelada') {
            if (
                $parcelaInicial < 1
                || $parcelasTotal < 2
                || $parcelaInicial > $parcelasTotal
                || $parcelasTotal > 600
            ) {
                financeiroRedirecionar($urlRetorno, 'Informe corretamente a parcela atual e o total de parcelas.', 'danger');
            }

            $grupoParcelamento = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_contas (
                    usuario_id,
                    descricao,
                    valor_previsto,
                    vencimento,
                    status,
                    grupo_parcelamento,
                    parcela_numero,
                    parcelas_total
                )
                VALUES (?, ?, ?, ?, 'pendente', ?, ?, ?)
            ");

            $pdo->beginTransaction();

            try {
                for ($numero = $parcelaInicial; $numero <= $parcelasTotal; $numero++) {
                    $mesesDepois = $numero - $parcelaInicial;
                    $vencimentoParcela = financeiroSomarMeses($vencimento, $mesesDepois);
                    $stmt->execute([
                        $usuarioId,
                        $descricao,
                        $valorPrevisto,
                        $vencimentoParcela,
                        $grupoParcelamento,
                        $numero,
                        $parcelasTotal,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                financeiroRedirecionar($urlRetorno, 'Não foi possível gerar as parcelas.', 'danger');
            }

            $quantidadeGerada = $parcelasTotal - $parcelaInicial + 1;
            financeiroRedirecionar(
                $urlRetorno,
                $quantidadeGerada . ($quantidadeGerada === 1 ? ' parcela cadastrada.' : ' parcelas cadastradas automaticamente.')
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_contas (
                usuario_id,
                descricao,
                valor_previsto,
                vencimento,
                status,
                grupo_parcelamento,
                parcela_numero,
                parcelas_total
            )
            VALUES (?, ?, ?, ?, 'pendente', NULL, NULL, NULL)
        ");
        $stmt->execute([$usuarioId, $descricao, $valorPrevisto, $vencimento]);
        financeiroRedirecionar($urlRetorno, 'Conta cadastrada com sucesso.');
    }

    if ($acao === 'pagar_conta') {
        $valorPago = financeiroValorEntrada($_POST['valor_pago'] ?? '');
        $dataPagamento = trim($_POST['data_pagamento'] ?? '');

        if ($id <= 0 || $valorPago <= 0 || $dataPagamento === '') {
            financeiroRedirecionar($urlRetorno, 'Informe o valor e a data do pagamento.', 'danger');
        }

        $stmt = $pdo->prepare("
            UPDATE financeiro_contas
            SET status = 'pago', valor_pago = ?, data_pagamento = ?
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$valorPago, $dataPagamento, $id, $usuarioId]);
        financeiroRedirecionar($urlRetorno, 'Conta marcada como paga.');
    }

    if ($acao === 'reabrir_conta') {
        $stmt = $pdo->prepare("
            UPDATE financeiro_contas
            SET status = 'pendente', valor_pago = NULL, data_pagamento = NULL
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        financeiroRedirecionar($urlRetorno, 'Conta voltou para pendente.');
    }

    if ($acao === 'excluir_conta') {
        $stmt = $pdo->prepare("DELETE FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuarioId]);
        financeiroRedirecionar($urlRetorno, 'Conta excluída com sucesso.');
    }

    financeiroRedirecionar($urlRetorno, 'Ação financeira inválida.', 'danger');
}

$mensagem = financeiroObterMensagem();
$recebimentos = [];
$contas = [];
$totalReceitas = 0.0;
$totalPrevisto = 0.0;
$totalPago = 0.0;
$totalPendente = 0.0;

if ($tabelasDisponiveis) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND data_recebimento >= ?
          AND data_recebimento < ?
        ORDER BY data_recebimento ASC, id ASC
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes]);
    $recebimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND vencimento >= ?
          AND vencimento < ?
        ORDER BY status ASC, vencimento ASC, descricao ASC
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes]);
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalReceitas = array_sum(array_map('floatval', array_column($recebimentos, 'valor')));
    $totalPrevisto = array_sum(array_map('floatval', array_column($contas, 'valor_previsto')));

    foreach ($contas as $conta) {
        if ($conta['status'] === 'pago') {
            $totalPago += (float)($conta['valor_pago'] ?? 0);
        } else {
            $totalPendente += (float)$conta['valor_previsto'];
        }
    }
}

$saldoAtual = $totalReceitas - $totalPago;
$saldoPrevisto = $totalReceitas - $totalPrevisto;
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
$numeroMes = (int)date('n', strtotime($inicioMes));
$nomeMes = $nomesMeses[$numeroMes] . '/' . date('Y', strtotime($inicioMes));
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Financeiro</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Financeiro</h3>
                    <p class="text-muted mb-0">Recebimentos e contas pessoais por mês</p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="financeiro_cartoes.php" class="btn btn-outline-primary">
                        <i class="bi bi-credit-card"></i> Cartões
                    </a>
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$tabelasDisponiveis): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o SQL do financeiro no phpMyAdmin e atualize esta página.
                </div>
            <?php else: ?>
                <div class="financeiro-filtros mb-4">
                    <form method="get" class="d-flex align-items-end gap-2">
                        <div>
                            <label for="mesFinanceiro" class="form-label">Mês de referência</label>
                            <input type="month" class="form-control" name="mes" id="mesFinanceiro" value="<?= htmlspecialchars($mes) ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Consultar
                        </button>
                    </form>
                    <strong><?= htmlspecialchars($nomeMes) ?></strong>
                </div>

                <section class="financeiro-resumo mb-4" aria-label="Resumo financeiro">
                    <div class="financeiro-metrica metrica-receita">
                        <span>Receitas</span>
                        <strong><?= financeiroMoeda($totalReceitas) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-despesa">
                        <span>Despesas previstas</span>
                        <strong><?= financeiroMoeda($totalPrevisto) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-pendente">
                        <span>A pagar</span>
                        <strong><?= financeiroMoeda($totalPendente) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $saldoAtual < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>">
                        <span>Saldo atual</span>
                        <strong><?= financeiroMoeda($saldoAtual) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $saldoPrevisto < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>">
                        <span>Saldo previsto</span>
                        <strong><?= financeiroMoeda($saldoPrevisto) ?></strong>
                    </div>
                </section>

                <section class="financeiro-painel mb-4">
                    <div class="financeiro-painel-titulo">
                        <div>
                            <h5 class="mb-1">Recebimentos</h5>
                            <p class="text-muted small mb-0">Salário e demais entradas do mês</p>
                        </div>
                        <button type="button" class="btn btn-success" id="btnNovoRecebimento" data-bs-toggle="modal" data-bs-target="#modalRecebimento">
                            <i class="bi bi-plus-lg"></i> Novo recebimento
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Descrição</th>
                                    <th>Recebido de</th>
                                    <th class="text-end">Valor</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recebimentos === []): ?>
                                    <tr>
                                        <td colspan="5" class="financeiro-vazio">Nenhum recebimento neste mês.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($recebimentos as $recebimento): ?>
                                    <tr>
                                        <td><?= financeiroData($recebimento['data_recebimento']) ?></td>
                                        <td><?= htmlspecialchars($recebimento['descricao']) ?></td>
                                        <td><?= htmlspecialchars($recebimento['recebido_de'] ?: '-') ?></td>
                                        <td class="text-end fw-semibold text-success"><?= financeiroMoeda((float)$recebimento['valor']) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm btn-editar-recebimento"
                                                    data-id="<?= (int)$recebimento['id'] ?>"
                                                    data-data="<?= htmlspecialchars($recebimento['data_recebimento']) ?>"
                                                    data-descricao="<?= htmlspecialchars($recebimento['descricao']) ?>"
                                                    data-recebido-de="<?= htmlspecialchars($recebimento['recebido_de']) ?>"
                                                    data-valor="<?= number_format((float)$recebimento['valor'], 2, ',', '.') ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalRecebimento"
                                                    title="Editar recebimento">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm btn-excluir-financeiro"
                                                    data-acao="excluir_recebimento"
                                                    data-id="<?= (int)$recebimento['id'] ?>"
                                                    data-descricao="<?= htmlspecialchars($recebimento['descricao']) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirFinanceiro"
                                                    title="Excluir recebimento">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Total recebido</th>
                                    <th class="text-end text-success"><?= financeiroMoeda($totalReceitas) ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="financeiro-painel">
                    <div class="financeiro-painel-titulo">
                        <div>
                            <h5 class="mb-1">Contas a pagar</h5>
                            <p class="text-muted small mb-0">Vencimentos e pagamentos de <?= htmlspecialchars($nomeMes) ?></p>
                        </div>
                        <button type="button" class="btn btn-primary" id="btnNovaConta" data-bs-toggle="modal" data-bs-target="#modalConta">
                            <i class="bi bi-plus-lg"></i> Nova conta
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela">
                            <thead>
                                <tr>
                                    <th>Despesa</th>
                                    <th>Vencimento</th>
                                    <th class="text-end">Previsto</th>
                                    <th class="text-end">Pago</th>
                                    <th>Data do pagamento</th>
                                    <th>Status</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($contas === []): ?>
                                    <tr>
                                        <td colspan="7" class="financeiro-vazio">Nenhuma conta cadastrada neste mês.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($contas as $conta):
                                    $paga = $conta['status'] === 'pago';
                                    $atrasada = !$paga && $conta['vencimento'] < date('Y-m-d');
                                    $textoConta = $conta['descricao'];

                                    if (!empty($conta['parcela_numero']) && !empty($conta['parcelas_total'])) {
                                        $textoConta .= ' ' . (int)$conta['parcela_numero'] . '/' . (int)$conta['parcelas_total'];
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($textoConta) ?>
                                            <?php if (!empty($conta['grupo_parcelamento'])): ?>
                                                <span class="badge bg-light text-dark border ms-1">Parcelada</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= financeiroData($conta['vencimento']) ?></td>
                                        <td class="text-end"><?= financeiroMoeda((float)$conta['valor_previsto']) ?></td>
                                        <td class="text-end"><?= $paga ? financeiroMoeda((float)$conta['valor_pago']) : '-' ?></td>
                                        <td><?= financeiroData($conta['data_pagamento']) ?></td>
                                        <td>
                                            <span class="badge <?= $paga ? 'bg-success' : ($atrasada ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                                <?= $paga ? 'Pago' : ($atrasada ? 'Atrasado' : 'Pendente') ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if (!$paga): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-success btn-sm btn-pagar-conta"
                                                        data-id="<?= (int)$conta['id'] ?>"
                                                        data-descricao="<?= htmlspecialchars($textoConta) ?>"
                                                        data-valor="<?= number_format((float)$conta['valor_previsto'], 2, ',', '.') ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalPagarConta"
                                                        title="Marcar como paga">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                                                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                                        <input type="hidden" name="acao" value="reabrir_conta">
                                                        <input type="hidden" name="id" value="<?= (int)$conta['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Voltar para pendente">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm btn-editar-conta"
                                                    data-id="<?= (int)$conta['id'] ?>"
                                                    data-descricao="<?= htmlspecialchars($conta['descricao']) ?>"
                                                    data-valor="<?= number_format((float)$conta['valor_previsto'], 2, ',', '.') ?>"
                                                    data-vencimento="<?= htmlspecialchars($conta['vencimento']) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalConta"
                                                    title="Editar conta">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm btn-excluir-financeiro"
                                                    data-acao="excluir_conta"
                                                    data-id="<?= (int)$conta['id'] ?>"
                                                    data-descricao="<?= htmlspecialchars($textoConta) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirFinanceiro"
                                                    title="Excluir conta">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2" class="text-end">Totais</th>
                                    <th class="text-end"><?= financeiroMoeda($totalPrevisto) ?></th>
                                    <th class="text-end text-success"><?= financeiroMoeda($totalPago) ?></th>
                                    <th colspan="3"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($tabelasDisponiveis): ?>
        <div class="modal fade" id="modalRecebimento" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_recebimento">
                        <input type="hidden" name="id" id="recebimentoId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalRecebimento">Novo recebimento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-5 mb-3">
                                    <label for="recebimentoData" class="form-label">Data</label>
                                    <input type="date" class="form-control" name="data_recebimento" id="recebimentoData" required>
                                    <div class="invalid-feedback">Informe a data.</div>
                                </div>
                                <div class="col-md-7 mb-3">
                                    <label for="recebimentoValor" class="form-label">Valor</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor" id="recebimentoValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe um valor.</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="recebimentoDescricao" class="form-label">Descrição</label>
                                <input type="text" class="form-control" name="descricao" id="recebimentoDescricao" required>
                                <div class="invalid-feedback">Informe a descrição.</div>
                            </div>
                            <div class="mb-3">
                                <label for="recebimentoOrigem" class="form-label">Recebido de</label>
                                <input type="text" class="form-control" name="recebido_de" id="recebimentoOrigem">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalConta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_conta">
                        <input type="hidden" name="id" id="contaId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalConta">Nova conta</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3" id="grupoTipoConta">
                                <label for="contaTipoLancamento" class="form-label">Tipo de conta</label>
                                <select class="form-select" name="tipo_lancamento" id="contaTipoLancamento">
                                    <option value="unica">Conta única</option>
                                    <option value="parcelada">Parcelada</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="contaDescricao" class="form-label">Despesa</label>
                                <input type="text" class="form-control" name="descricao" id="contaDescricao" required>
                                <div class="invalid-feedback">Informe a despesa.</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contaValor" class="form-label">Valor previsto</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor_previsto" id="contaValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o valor.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contaVencimento" class="form-label">Vencimento</label>
                                    <input type="date" class="form-control" name="vencimento" id="contaVencimento" required>
                                    <div class="invalid-feedback">Informe o vencimento.</div>
                                </div>
                            </div>
                            <div class="row d-none" id="camposParcelamentoConta">
                                <div class="col-md-6 mb-3">
                                    <label for="contaParcelaInicial" class="form-label">Parcela atual</label>
                                    <input type="number" min="1" max="600" class="form-control" name="parcela_inicial" id="contaParcelaInicial" value="1">
                                    <div class="invalid-feedback">Informe a parcela atual.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contaParcelasTotal" class="form-label">Total de parcelas</label>
                                    <input type="number" min="2" max="600" class="form-control" name="parcelas_total" id="contaParcelasTotal">
                                    <div class="invalid-feedback">Informe o total de parcelas.</div>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">
                                        Exemplo: informe parcela atual 17 e total 48 para gerar da 17/48 até a 48/48.
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalPagarConta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="pagar_conta">
                        <input type="hidden" name="id" id="pagarContaId">
                        <div class="modal-header">
                            <h5 class="modal-title">Confirmar pagamento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Pagamento de <strong id="pagarContaDescricao"></strong></p>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="pagarContaValor" class="form-label">Valor pago</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor_pago" id="pagarContaValor" required>
                                    <div class="invalid-feedback">Informe o valor pago.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="pagarContaData" class="form-label">Data do pagamento</label>
                                    <input type="date" class="form-control" name="data_pagamento" id="pagarContaData" value="<?= date('Y-m-d') ?>" required>
                                    <div class="invalid-feedback">Informe a data.</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg"></i> Confirmar pagamento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirFinanceiro" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Excluir lançamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="descricaoExcluirFinanceiro"></strong>?</p>
                        <small class="text-danger">Essa ação não poderá ser desfeita.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" id="acaoExcluirFinanceiro">
                            <input type="hidden" name="id" id="idExcluirFinanceiro">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Sim, excluir
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($tabelasDisponiveis): ?>
        <script>
            const mesSelecionado = <?= json_encode($mes) ?>;
            const dataHoje = <?= json_encode(date('Y-m-d')) ?>;

            document.getElementById('btnNovoRecebimento').addEventListener('click', function() {
                document.getElementById('tituloModalRecebimento').textContent = 'Novo recebimento';
                document.getElementById('recebimentoId').value = '';
                document.getElementById('recebimentoData').value = mesSelecionado + '-01';
                document.getElementById('recebimentoDescricao').value = '';
                document.getElementById('recebimentoOrigem').value = '';
                document.getElementById('recebimentoValor').value = '';
            });

            document.querySelectorAll('.btn-editar-recebimento').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalRecebimento').textContent = 'Editar recebimento';
                    document.getElementById('recebimentoId').value = this.dataset.id;
                    document.getElementById('recebimentoData').value = this.dataset.data;
                    document.getElementById('recebimentoDescricao').value = this.dataset.descricao;
                    document.getElementById('recebimentoOrigem').value = this.dataset.recebidoDe;
                    document.getElementById('recebimentoValor').value = this.dataset.valor;
                });
            });

            document.getElementById('btnNovaConta').addEventListener('click', function() {
                document.getElementById('tituloModalConta').textContent = 'Nova conta';
                document.getElementById('contaId').value = '';
                document.getElementById('grupoTipoConta').classList.remove('d-none');
                document.getElementById('contaTipoLancamento').value = 'unica';
                document.getElementById('contaDescricao').value = '';
                document.getElementById('contaValor').value = '';
                document.getElementById('contaVencimento').value = mesSelecionado + '-01';
                document.getElementById('contaParcelaInicial').value = '1';
                document.getElementById('contaParcelasTotal').value = '';
                atualizarCamposParcelamentoConta();
            });

            document.querySelectorAll('.btn-editar-conta').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalConta').textContent = 'Editar conta';
                    document.getElementById('contaId').value = this.dataset.id;
                    document.getElementById('grupoTipoConta').classList.add('d-none');
                    document.getElementById('camposParcelamentoConta').classList.add('d-none');
                    document.getElementById('contaDescricao').value = this.dataset.descricao;
                    document.getElementById('contaValor').value = this.dataset.valor;
                    document.getElementById('contaVencimento').value = this.dataset.vencimento;
                });
            });

            function atualizarCamposParcelamentoConta() {
                const parcelada = document.getElementById('contaTipoLancamento').value === 'parcelada';
                const campos = document.getElementById('camposParcelamentoConta');
                const parcelaInicial = document.getElementById('contaParcelaInicial');
                const parcelasTotal = document.getElementById('contaParcelasTotal');

                campos.classList.toggle('d-none', !parcelada);
                parcelaInicial.required = parcelada;
                parcelasTotal.required = parcelada;

                if (parcelada) {
                    parcelaInicial.focus();
                } else {
                    parcelaInicial.classList.remove('is-invalid');
                    parcelasTotal.classList.remove('is-invalid');
                }
            }

            document.getElementById('contaTipoLancamento').addEventListener('change', atualizarCamposParcelamentoConta);

            document.querySelectorAll('.btn-pagar-conta').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('pagarContaId').value = this.dataset.id;
                    document.getElementById('pagarContaDescricao').textContent = this.dataset.descricao;
                    document.getElementById('pagarContaValor').value = this.dataset.valor;
                    document.getElementById('pagarContaData').value = dataHoje;
                });
            });

            document.querySelectorAll('.btn-excluir-financeiro').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('acaoExcluirFinanceiro').value = this.dataset.acao;
                    document.getElementById('idExcluirFinanceiro').value = this.dataset.id;
                    document.getElementById('descricaoExcluirFinanceiro').textContent = this.dataset.descricao;
                });
            });

            document.querySelectorAll('.financeiro-form').forEach(function(formulario) {
                formulario.addEventListener('submit', function(event) {
                    let primeiroInvalido = null;

                    formulario.querySelectorAll('[required]').forEach(function(campo) {
                        const invalido = campo.value.trim() === '';
                        campo.classList.toggle('is-invalid', invalido);

                        if (invalido && !primeiroInvalido) {
                            primeiroInvalido = campo;
                        }
                    });

                    if (primeiroInvalido) {
                        event.preventDefault();
                        primeiroInvalido.focus();
                    }
                });
            });

            document.querySelectorAll('.financeiro-form input').forEach(function(campo) {
                campo.addEventListener('input', function() {
                    this.classList.remove('is-invalid');
                });
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