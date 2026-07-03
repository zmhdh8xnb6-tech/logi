<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$cartaoSelecionadoId = (int)($_GET['cartao'] ?? $_POST['cartao_retorno'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$proximoMes = date('Y-m', strtotime($inicioMes . ' +1 month'));
$dataPadraoCompra = date('Y-m-d');
$tabelasDisponiveis = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_cartoes', 'financeiro_cartao_lancamentos']
);
$temCompetenciaFatura = $tabelasDisponiveis
    && financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura');
$expressaoCompetenciaFatura = $temCompetenciaFatura
    ? 'COALESCE(competencia_fatura, DATE_FORMAT(data_compra, \'%Y-%m-01\'))'
    : 'DATE_FORMAT(data_compra, \'%Y-%m-01\')';
$expressaoCompetenciaFaturaL = $temCompetenciaFatura
    ? 'COALESCE(l.competencia_fatura, DATE_FORMAT(l.data_compra, \'%Y-%m-01\'))'
    : 'DATE_FORMAT(l.data_compra, \'%Y-%m-01\')';

function urlCartoes(int $cartaoId = 0, ?string $mes = null): string
{
    $parametros = ['mes' => financeiroMesValido($mes)];

    if ($cartaoId > 0) {
        $parametros['cartao'] = $cartaoId;
    }

    return 'financeiro_cartoes.php?' . http_build_query($parametros);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $urlRetorno = urlCartoes($cartaoSelecionadoId, $mes);

    if (!$tabelasDisponiveis) {
        financeiroRedirecionar($urlRetorno, 'Execute o SQL do financeiro antes de cadastrar.', 'danger');
    }

    if (!financeiroTokenValido($_POST['csrf_token'] ?? null)) {
        financeiroRedirecionar($urlRetorno, 'A sessão do formulário expirou. Tente novamente.', 'danger');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar_cartao') {
        $nome = trim($_POST['nome'] ?? '');
        $limiteInformado = $_POST['limite_total'] ?? '';
        $limite = financeiroValorEntrada($limiteInformado);
        $tipo = ($_POST['tipo'] ?? '') === 'loja' ? 'loja' : 'credito';
        $diaVencimento = (int)($_POST['dia_vencimento'] ?? 0);

        if (
            $nome === ''
            || !financeiroValorValido($limiteInformado)
            || $diaVencimento < 1
            || $diaVencimento > 31
        ) {
            financeiroRedirecionar($urlRetorno, 'Informe o nome, o limite e o dia de vencimento do cartão.', 'danger');
        }

        if ($id > 0) {
            $stmtCartaoAntes = $pdo->prepare("SELECT * FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
            $stmtCartaoAntes->execute([$id, $usuarioId]);
            $cartaoAntes = $stmtCartaoAntes->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(valor), 0)
                FROM financeiro_cartao_lancamentos
                WHERE cartao_id = ? AND usuario_id = ? AND status = 'aberto'
            ");
            $stmt->execute([$id, $usuarioId]);
            $totalEmAberto = (float)$stmt->fetchColumn();

            if ($limite < $totalEmAberto) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'O limite não pode ser menor que o total de compras em aberto.',
                    'danger'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE financeiro_cartoes
                SET nome = ?, limite_total = ?, tipo = ?, dia_vencimento = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$nome, $limite, $tipo, $diaVencimento, $id, $usuarioId]);
            $cartaoDepois = array_merge($cartaoAntes, [
                'nome' => $nome,
                'limite_total' => $limite,
                'tipo' => $tipo,
                'dia_vencimento' => $diaVencimento,
            ]);
            $mudancas = auditoriaMudancas($cartaoAntes, $cartaoDepois);
            registrarAuditoria($pdo, 'Financeiro - Cartões', 'editar', 'cartao', $id, 'Alterou o cartão ' . $nome, $mudancas['antes'], $mudancas['depois']);
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(urlCartoes($id, $mes), 'Cartão atualizado com sucesso.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_cartoes
                (usuario_id, nome, limite_total, tipo, dia_vencimento, ativo)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$usuarioId, $nome, $limite, $tipo, $diaVencimento]);
        $novoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'criar',
            'cartao',
            $novoId,
            'Cadastrou o cartão ' . $nome,
            null,
            ['nome' => $nome, 'limite_total' => $limite, 'tipo' => $tipo, 'dia_vencimento' => $diaVencimento]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes($novoId, $mes), 'Cartão cadastrado com sucesso.');
    }

    if ($acao === 'excluir_cartao') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $cartaoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                DELETE FROM financeiro_cartao_lancamentos
                WHERE cartao_id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $usuarioId]);

            $stmt = $pdo->prepare("DELETE FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $usuarioId]);
            $pdo->commit();
            if ($cartaoAntes) {
                registrarAuditoria(
                    $pdo,
                    'Financeiro - Cartões',
                    'excluir',
                    'cartao',
                    $id,
                    'Excluiu o cartão ' . $cartaoAntes['nome'] . ' e seus lançamentos',
                    $cartaoAntes,
                    null
                );
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            financeiroRedirecionar($urlRetorno, 'Não foi possível excluir o cartão.', 'danger');
        }

        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes(0, $mes), 'Cartão e lançamentos excluídos com sucesso.');
    }

    if ($acao === 'salvar_lancamento') {
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $dataCompra = trim($_POST['data_compra'] ?? '');
        $mesFaturaCompra = trim($_POST['mes_fatura_compra'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $valorInformado = $_POST['valor'] ?? '';
        $valor = financeiroValorEntrada($valorInformado);
        $tipoCompra = ($_POST['tipo_compra'] ?? '') === 'parcelada' ? 'parcelada' : 'unica';
        $parcelasTotal = (int)($_POST['parcelas_total'] ?? 1);
        $dataMesFatura = DateTime::createFromFormat('!Y-m', $mesFaturaCompra);
        $mesFaturaValido = $dataMesFatura
            && $dataMesFatura->format('Y-m') === $mesFaturaCompra;

        $stmt = $pdo->prepare("
            SELECT id, limite_total
            FROM financeiro_cartoes
            WHERE id = ? AND usuario_id = ? AND ativo = 1
        ");
        $stmt->execute([$cartaoId, $usuarioId]);
        $cartaoDestino = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$cartaoDestino
            || $dataCompra === ''
            || !$mesFaturaValido
            || $descricao === ''
            || !financeiroValorValido($valorInformado)
        ) {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados da compra corretamente.', 'danger');
        }

        if (!$temCompetenciaFatura) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL da competência da fatura antes de lançar a compra.',
                'danger'
            );
        }

        $competenciaFatura = $mesFaturaCompra . '-01';

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(valor), 0)
            FROM financeiro_cartao_lancamentos
            WHERE cartao_id = ?
              AND usuario_id = ?
              AND status = 'aberto'
              AND id <> ?
        ");
        $stmt->execute([$cartaoId, $usuarioId, $id]);
        $totalEmAberto = (float)$stmt->fetchColumn();

        if ($totalEmAberto + $valor > (float)$cartaoDestino['limite_total']) {
            financeiroRedirecionar(
                urlCartoes($cartaoId, $mes),
                'A compra ultrapassa o limite disponível deste cartão.',
                'danger'
            );
        }

        if ($id > 0) {
            $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartao_lancamentos WHERE id = ? AND usuario_id = ?");
            $stmtAntes->execute([$id, $usuarioId]);
            $lancamentoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare("
                UPDATE financeiro_cartao_lancamentos
                SET cartao_id = ?,
                    data_compra = ?,
                    competencia_fatura = ?,
                    descricao = ?,
                    valor = ?
                WHERE id = ? AND usuario_id = ? AND status = 'aberto'
            ");
            $stmt->execute([
                $cartaoId,
                $dataCompra,
                $competenciaFatura,
                $descricao,
                $valor,
                $id,
                $usuarioId,
            ]);
            $lancamentoDepois = array_merge($lancamentoAntes, [
                'cartao_id' => $cartaoId,
                'data_compra' => $dataCompra,
                'competencia_fatura' => $competenciaFatura,
                'descricao' => $descricao,
                'valor' => $valor,
            ]);
            $mudancas = auditoriaMudancas($lancamentoAntes, $lancamentoDepois);
            registrarAuditoria($pdo, 'Financeiro - Cartões', 'editar', 'compra_cartao', $id, 'Alterou a compra ' . $descricao, $mudancas['antes'], $mudancas['depois']);
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Compra atualizada com sucesso.');
        }

        if ($tipoCompra === 'parcelada') {
            if ($parcelasTotal < 2 || $parcelasTotal > 600) {
                financeiroRedirecionar(
                    urlCartoes($cartaoId, $mes),
                    'Informe corretamente a quantidade de parcelas.',
                    'danger'
                );
            }

            $grupoParcelamento = bin2hex(random_bytes(16));
            $valorTotalCentavos = (int)round($valor * 100);
            $valorBaseCentavos = intdiv($valorTotalCentavos, $parcelasTotal);
            $centavosRestantes = $valorTotalCentavos - ($valorBaseCentavos * $parcelasTotal);
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_cartao_lancamentos (
                    usuario_id,
                    cartao_id,
                    data_compra,
                    competencia_fatura,
                    descricao,
                    valor,
                    status,
                    grupo_parcelamento,
                    parcela_numero,
                    parcelas_total
                )
                VALUES (?, ?, ?, ?, ?, ?, 'aberto', ?, ?, ?)
            ");

            $pdo->beginTransaction();

            try {
                for ($numero = 1; $numero <= $parcelasTotal; $numero++) {
                    $valorParcelaCentavos = $valorBaseCentavos + ($numero <= $centavosRestantes ? 1 : 0);
                    $valorParcela = $valorParcelaCentavos / 100;
                    $competenciaParcela = financeiroSomarMeses($competenciaFatura, $numero - 1);
                    $stmt->execute([
                        $usuarioId,
                        $cartaoId,
                        $dataCompra,
                        $competenciaParcela,
                        $descricao,
                        $valorParcela,
                        $grupoParcelamento,
                        $numero,
                        $parcelasTotal,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Não foi possível gerar as parcelas da compra.', 'danger');
            }

            registrarAuditoria(
                $pdo,
                'Financeiro - Cartões',
                'criar_parcelas',
                'compra_cartao',
                $grupoParcelamento,
                'Lançou a compra parcelada ' . $descricao . ' em ' . $parcelasTotal . ' vezes',
                null,
                [
                    'cartao_id' => $cartaoId,
                    'data_compra' => $dataCompra,
                    'primeira_fatura' => $mesFaturaCompra,
                    'descricao' => $descricao,
                    'valor_total' => $valor,
                    'parcelas_total' => $parcelasTotal,
                ]
            );
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(
                urlCartoes($cartaoId, $mes),
                $parcelasTotal . ' parcelas lançadas e limite atualizado.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_cartao_lancamentos (
                usuario_id,
                cartao_id,
                data_compra,
                competencia_fatura,
                descricao,
                valor,
                status,
                grupo_parcelamento,
                parcela_numero,
                parcelas_total
            )
            VALUES (?, ?, ?, ?, ?, ?, 'aberto', NULL, NULL, NULL)
        ");
        $stmt->execute([
            $usuarioId,
            $cartaoId,
            $dataCompra,
            $competenciaFatura,
            $descricao,
            $valor,
        ]);
        $novoLancamentoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'criar',
            'compra_cartao',
            $novoLancamentoId,
            'Lançou a compra ' . $descricao,
            null,
            [
                'cartao_id' => $cartaoId,
                'data_compra' => $dataCompra,
                'competencia_fatura' => $competenciaFatura,
                'descricao' => $descricao,
                'valor' => $valor,
            ]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Compra lançada e limite atualizado.');
    }

    if ($acao === 'excluir_lancamento') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartao_lancamentos WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $lancamentoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$lancamentoAntes) {
            financeiroRedirecionar($urlRetorno, 'Compra não encontrada.', 'danger');
        }

        if (!empty($lancamentoAntes['grupo_parcelamento'])) {
            $stmtResumo = $pdo->prepare("
                SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0) AS valor_total
                FROM financeiro_cartao_lancamentos
                WHERE usuario_id = ? AND grupo_parcelamento = ?
            ");
            $stmtResumo->execute([$usuarioId, $lancamentoAntes['grupo_parcelamento']]);
            $resumoExclusao = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [
                'quantidade' => 0,
                'valor_total' => 0,
            ];
            $stmt = $pdo->prepare("
                DELETE FROM financeiro_cartao_lancamentos
                WHERE usuario_id = ? AND grupo_parcelamento = ?
            ");
            $stmt->execute([$usuarioId, $lancamentoAntes['grupo_parcelamento']]);
            registrarAuditoria(
                $pdo,
                'Financeiro - Cartões',
                'excluir_parcelamento',
                'compra_cartao',
                $lancamentoAntes['grupo_parcelamento'],
                'Excluiu todas as parcelas da compra ' . $lancamentoAntes['descricao'],
                [
                    'compra' => $lancamentoAntes,
                    'quantidade_parcelas' => (int)$resumoExclusao['quantidade'],
                    'valor_total' => (float)$resumoExclusao['valor_total'],
                ],
                null
            );
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(
                $urlRetorno,
                (int)$resumoExclusao['quantidade'] . ' parcelas excluídas e limite atualizado.'
            );
        }

        $stmt = $pdo->prepare("
            DELETE FROM financeiro_cartao_lancamentos
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'excluir',
            'compra_cartao',
            $id,
            'Excluiu a compra ' . $lancamentoAntes['descricao'],
            $lancamentoAntes,
            null
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar($urlRetorno, 'Lançamento excluído e limite atualizado.');
    }

    if ($acao === 'pagar_fatura') {
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $dataPagamento = trim($_POST['data_pagamento'] ?? '');
        $mesFatura = financeiroMesValido($_POST['mes_fatura'] ?? null);
        $inicioMesFatura = $mesFatura . '-01';
        $fimMesFatura = date('Y-m-d', strtotime($mesFatura . '-01 +1 month'));

        if (
            $cartaoId <= 0
            || $dataPagamento === ''
            || !preg_match('/^\d{4}-\d{2}$/', $_POST['mes_fatura'] ?? '')
        ) {
            financeiroRedirecionar($urlRetorno, 'Informe o mês da fatura e a data de pagamento.', 'danger');
        }

        $stmtFatura = $pdo->prepare("
            SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0) AS total
            FROM financeiro_cartao_lancamentos
            WHERE cartao_id = ?
              AND usuario_id = ?
              AND status = 'aberto'
              AND {$expressaoCompetenciaFatura} >= ?
              AND {$expressaoCompetenciaFatura} < ?
        ");
        $stmtFatura->execute([$cartaoId, $usuarioId, $inicioMesFatura, $fimMesFatura]);
        $faturaAntes = $stmtFatura->fetch(PDO::FETCH_ASSOC) ?: ['quantidade' => 0, 'total' => 0];
        $stmt = $pdo->prepare("
            UPDATE financeiro_cartao_lancamentos l
            INNER JOIN financeiro_cartoes c ON c.id = l.cartao_id
            SET l.status = 'pago', l.data_pagamento = ?
            WHERE l.cartao_id = ?
              AND l.usuario_id = ?
              AND c.usuario_id = ?
              AND l.status = 'aberto'
              AND {$expressaoCompetenciaFaturaL} >= ?
              AND {$expressaoCompetenciaFaturaL} < ?
        ");
        $stmt->execute([
            $dataPagamento,
            $cartaoId,
            $usuarioId,
            $usuarioId,
            $inicioMesFatura,
            $fimMesFatura,
        ]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'pagar_fatura',
            'cartao',
            $cartaoId,
            'Pagou a fatura do cartão de ' . $mesFatura,
            ['parcelas_em_aberto' => (int)$faturaAntes['quantidade'], 'valor' => (float)$faturaAntes['total']],
            ['parcelas_em_aberto' => 0, 'data_pagamento' => $dataPagamento]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Fatura paga e limite liberado.');
    }

    if ($acao === 'reabrir_lancamento') {
        $stmt = $pdo->prepare("
            SELECT l.valor, l.cartao_id, l.descricao, c.limite_total,
                (
                    SELECT COALESCE(SUM(x.valor), 0)
                    FROM financeiro_cartao_lancamentos x
                    WHERE x.cartao_id = l.cartao_id
                      AND x.usuario_id = l.usuario_id
                      AND x.status = 'aberto'
                ) AS total_aberto
            FROM financeiro_cartao_lancamentos l
            INNER JOIN financeiro_cartoes c ON c.id = l.cartao_id AND c.usuario_id = l.usuario_id
            WHERE l.id = ? AND l.usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$lancamento
            || (float)$lancamento['total_aberto'] + (float)$lancamento['valor'] > (float)$lancamento['limite_total']
        ) {
            financeiroRedirecionar($urlRetorno, 'Não há limite suficiente para reabrir esta compra.', 'danger');
        }

        $stmt = $pdo->prepare("
            UPDATE financeiro_cartao_lancamentos
            SET status = 'aberto', data_pagamento = NULL
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'reabrir',
            'compra_cartao',
            $id,
            'Reabriu a compra ' . ($lancamento['descricao'] ?? ('#' . $id)),
            ['status' => 'pago'],
            ['status' => 'aberto', 'data_pagamento' => null]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes((int)$lancamento['cartao_id'], $mes), 'Compra reaberta e limite recalculado.');
    }

    financeiroRedirecionar($urlRetorno, 'Ação de cartão inválida.', 'danger');
}

$mensagem = financeiroObterMensagem();
$cartoes = [];
$lancamentos = [];
$cartaoSelecionado = null;
$resumo = [
    'credito_limite' => 0.0,
    'credito_disponivel' => 0.0,
    'loja_limite' => 0.0,
    'loja_disponivel' => 0.0,
];

if ($tabelasDisponiveis) {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COALESCE(SUM(CASE WHEN l.status = 'aberto' THEN l.valor ELSE 0 END), 0) AS total_aberto,
            COALESCE(SUM(
                CASE
                    WHEN l.status = 'aberto'
                     AND {$expressaoCompetenciaFaturaL} >= ?
                     AND {$expressaoCompetenciaFaturaL} < ?
                    THEN l.valor
                    ELSE 0
                END
            ), 0) AS fatura_mes
        FROM financeiro_cartoes c
        LEFT JOIN financeiro_cartao_lancamentos l
            ON l.cartao_id = c.id
           AND l.usuario_id = c.usuario_id
        WHERE c.usuario_id = ?
        GROUP BY c.id
        ORDER BY c.tipo ASC, c.nome ASC
    ");
    $stmt->execute([$inicioMes, $fimMes, $usuarioId]);
    $cartoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cartoes as &$cartao) {
        $cartao['disponivel'] = (float)$cartao['limite_total'] - (float)$cartao['total_aberto'];
        $prefixo = $cartao['tipo'] === 'loja' ? 'loja' : 'credito';
        $resumo[$prefixo . '_limite'] += (float)$cartao['limite_total'];
        $resumo[$prefixo . '_disponivel'] += (float)$cartao['disponivel'];

        if ((int)$cartao['id'] === $cartaoSelecionadoId) {
            $cartaoSelecionado = $cartao;
        }
    }
    unset($cartao);

    if (!$cartaoSelecionado && $cartoes !== []) {
        $cartaoSelecionado = $cartoes[0];
        $cartaoSelecionadoId = (int)$cartaoSelecionado['id'];
    }

    if ($cartaoSelecionado) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_cartao_lancamentos
            WHERE cartao_id = ?
              AND usuario_id = ?
              AND {$expressaoCompetenciaFatura} >= ?
              AND {$expressaoCompetenciaFatura} < ?
            ORDER BY status ASC, data_compra DESC, id DESC
        ");
        $stmt->execute([$cartaoSelecionadoId, $usuarioId, $inicioMes, $fimMes]);
        $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
$vencimentoFaturaSelecionada = null;
$diasParaVencimentoFatura = null;
$faturaAtrasada = false;
$textoPrazoFatura = 'Dia de vencimento não informado';

if ($cartaoSelecionado && !empty($cartaoSelecionado['dia_vencimento'])) {
    $vencimentoFaturaSelecionada = financeiroVencimentoFatura(
        $mes,
        (int)$cartaoSelecionado['dia_vencimento']
    );
    $diasParaVencimentoFatura = (int)(new DateTimeImmutable('today'))
        ->diff(new DateTimeImmutable($vencimentoFaturaSelecionada))
        ->format('%r%a');
    $faturaAtrasada = (float)$cartaoSelecionado['fatura_mes'] > 0
        && $diasParaVencimentoFatura < 0;

    if ((float)$cartaoSelecionado['fatura_mes'] <= 0) {
        $textoPrazoFatura = 'Sem valor em aberto';
    } elseif ($diasParaVencimentoFatura < 0) {
        $diasAtraso = abs($diasParaVencimentoFatura);
        $textoPrazoFatura = 'Vencida há ' . $diasAtraso . ($diasAtraso === 1 ? ' dia' : ' dias');
    } elseif ($diasParaVencimentoFatura === 0) {
        $textoPrazoFatura = 'Vence hoje';
    } else {
        $textoPrazoFatura = ($diasParaVencimentoFatura === 1 ? 'Falta ' : 'Faltam ')
            . $diasParaVencimentoFatura
            . ($diasParaVencimentoFatura === 1 ? ' dia' : ' dias');
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Cartões de crédito</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Cartões de crédito</h3>
                    <p class="text-muted mb-0">Compras lançadas reduzem o limite disponível automaticamente</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="financeiro.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar ao financeiro
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
                <section class="financeiro-resumo financeiro-resumo-cartoes mb-4" aria-label="Resumo dos cartões">
                    <div class="financeiro-metrica metrica-cartao">
                        <span>Limite cartões</span>
                        <strong><?= financeiroMoeda($resumo['credito_limite']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-saldo">
                        <span>Disponível cartões</span>
                        <strong><?= financeiroMoeda($resumo['credito_disponivel']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-loja">
                        <span>Limite lojas</span>
                        <strong><?= financeiroMoeda($resumo['loja_limite']) ?></strong>
                    </div>
                    <div class="financeiro-metrica metrica-pendente">
                        <span>Disponível lojas</span>
                        <strong><?= financeiroMoeda($resumo['loja_disponivel']) ?></strong>
                    </div>
                </section>

                <div class="financeiro-filtros mb-4">
                    <span class="financeiro-mes-titulo">Fatura de <?= htmlspecialchars($nomeMes) ?></span>

                    <div class="financeiro-navegacao-mes">
                        <a
                            href="<?= htmlspecialchars(urlCartoes($cartaoSelecionadoId, $mesAnterior)) ?>"
                            class="btn btn-outline-secondary"
                            title="Fatura anterior"
                            aria-label="Fatura anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <form method="get" id="formMesCartao">
                            <?php if ($cartaoSelecionadoId > 0): ?>
                                <input type="hidden" name="cartao" value="<?= $cartaoSelecionadoId ?>">
                            <?php endif; ?>
                            <label for="mesCartao" class="visually-hidden">Escolher mês da fatura</label>
                            <input
                                type="month"
                                class="form-control"
                                name="mes"
                                id="mesCartao"
                                value="<?= htmlspecialchars($mes) ?>"
                                title="Escolher outro mês">
                        </form>

                        <a
                            href="<?= htmlspecialchars(urlCartoes($cartaoSelecionadoId, $proximoMes)) ?>"
                            class="btn btn-outline-secondary"
                            title="Próxima fatura"
                            aria-label="Próxima fatura">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <div class="financeiro-cartoes-layout">
                    <aside class="financeiro-painel financeiro-lista-cartoes">
                        <div class="financeiro-painel-titulo">
                            <div>
                                <h5 class="mb-1">Meus cartões</h5>
                                <p class="text-muted small mb-0"><?= count($cartoes) ?> cadastrados</p>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="btnNovoCartao" data-bs-toggle="modal" data-bs-target="#modalCartao">
                                <i class="bi bi-plus-lg"></i> Novo
                            </button>
                        </div>

                        <div class="financeiro-cartoes">
                            <?php if ($cartoes === []): ?>
                                <div class="financeiro-vazio">Cadastre seu primeiro cartão.</div>
                            <?php endif; ?>

                            <?php foreach ($cartoes as $cartao): ?>
                                <a
                                    href="<?= htmlspecialchars(urlCartoes((int)$cartao['id'], $mes)) ?>"
                                    class="financeiro-cartao-item<?= (int)$cartao['id'] === $cartaoSelecionadoId ? ' ativo' : '' ?>">
                                    <span class="financeiro-cartao-icone">
                                        <i class="bi <?= $cartao['tipo'] === 'loja' ? 'bi-shop' : 'bi-credit-card' ?>"></i>
                                    </span>
                                    <span class="financeiro-cartao-dados">
                                        <strong><?= htmlspecialchars($cartao['nome']) ?></strong>
                                        <small><?= financeiroMoeda((float)$cartao['disponivel']) ?> disponível</small>
                                    </span>
                                    <span class="badge <?= $cartao['tipo'] === 'loja' ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                        <?= $cartao['tipo'] === 'loja' ? 'Loja' : 'Crédito' ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </aside>

                    <section class="financeiro-painel">
                        <?php if (!$cartaoSelecionado): ?>
                            <div class="financeiro-vazio py-5">
                                Cadastre um cartão para começar a lançar suas compras.
                            </div>
                        <?php else: ?>
                            <div class="financeiro-painel-titulo financeiro-cartao-cabecalho">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($cartaoSelecionado['nome']) ?></h5>
                                    <p class="text-muted small mb-0">
                                        Limite <?= financeiroMoeda((float)$cartaoSelecionado['limite_total']) ?>
                                        <?php if ($vencimentoFaturaSelecionada): ?>
                                            · fatura de <?= htmlspecialchars($nomeMes) ?>
                                            vence em <?= financeiroData($vencimentoFaturaSelecionada) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm btn-editar-cartao"
                                        data-id="<?= (int)$cartaoSelecionado['id'] ?>"
                                        data-nome="<?= htmlspecialchars($cartaoSelecionado['nome']) ?>"
                                        data-limite="<?= number_format((float)$cartaoSelecionado['limite_total'], 2, ',', '.') ?>"
                                        data-tipo="<?= htmlspecialchars($cartaoSelecionado['tipo']) ?>"
                                        data-vencimento="<?= (int)($cartaoSelecionado['dia_vencimento'] ?? 0) ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalCartao">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm btn-excluir-cartao"
                                        data-id="<?= (int)$cartaoSelecionado['id'] ?>"
                                        data-nome="<?= htmlspecialchars($cartaoSelecionado['nome']) ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalExcluirCartao">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalPagarFatura"
                                        <?= (float)$cartaoSelecionado['fatura_mes'] <= 0 ? 'disabled' : '' ?>>
                                        <i class="bi bi-check-lg"></i> Pagar fatura
                                    </button>
                                    <button type="button" class="btn btn-primary btn-sm" id="btnNovaCompra" data-bs-toggle="modal" data-bs-target="#modalCompra">
                                        <i class="bi bi-plus-lg"></i> Nova compra
                                    </button>
                                </div>
                            </div>

                            <div class="financeiro-limite-barra mb-4">
                                <?php
                                $percentualUsado = (float)$cartaoSelecionado['limite_total'] > 0
                                    ? min(100, ((float)$cartaoSelecionado['total_aberto'] / (float)$cartaoSelecionado['limite_total']) * 100)
                                    : 0;
                                ?>
                                <div class="d-flex flex-wrap justify-content-between gap-3 mb-2">
                                    <span>Fatura de <?= htmlspecialchars($nomeMes) ?>: <strong><?= financeiroMoeda((float)$cartaoSelecionado['fatura_mes']) ?></strong></span>
                                    <span>
                                        Vencimento:
                                        <strong><?= $vencimentoFaturaSelecionada ? financeiroData($vencimentoFaturaSelecionada) : '-' ?></strong>
                                    </span>
                                    <span class="<?= $faturaAtrasada ? 'text-danger' : '' ?>">
                                        Prazo: <strong><?= htmlspecialchars($textoPrazoFatura) ?></strong>
                                    </span>
                                    <span>Compras comprometidas: <strong><?= financeiroMoeda((float)$cartaoSelecionado['total_aberto']) ?></strong></span>
                                    <span>Disponível: <strong><?= financeiroMoeda((float)$cartaoSelecionado['disponivel']) ?></strong></span>
                                </div>
                                <div class="progress" role="progressbar" aria-valuenow="<?= round($percentualUsado) ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar <?= $percentualUsado >= 85 ? 'bg-danger' : ($percentualUsado >= 60 ? 'bg-warning' : 'bg-primary') ?>" style="width: <?= $percentualUsado ?>%"></div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle financeiro-tabela">
                                    <thead>
                                        <tr>
                                            <th>Data da compra</th>
                                            <th>Compra</th>
                                            <th class="text-end">Valor</th>
                                            <th>Status</th>
                                            <th>Pagamento</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($lancamentos === []): ?>
                                            <tr>
                                                <td colspan="6" class="financeiro-vazio">Nenhuma compra nesta fatura.</td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($lancamentos as $lancamento):
                                            $textoCompra = $lancamento['descricao'];
                                            $lancamentoAtrasado = $lancamento['status'] === 'aberto' && $faturaAtrasada;
                                            $mesFaturaLancamento = !empty($lancamento['competencia_fatura'])
                                                ? date('Y-m', strtotime($lancamento['competencia_fatura']))
                                                : date('Y-m', strtotime($lancamento['data_compra']));

                                            if (!empty($lancamento['parcela_numero']) && !empty($lancamento['parcelas_total'])) {
                                                $textoCompra .= ' ' . (int)$lancamento['parcela_numero'] . '/' . (int)$lancamento['parcelas_total'];
                                            }
                                        ?>
                                            <tr>
                                                <td><?= financeiroData($lancamento['data_compra']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($textoCompra) ?>
                                                    <?php if (!empty($lancamento['grupo_parcelamento'])): ?>
                                                        <span class="badge bg-light text-dark border ms-1">Parcelada</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-semibold"><?= financeiroMoeda((float)$lancamento['valor']) ?></td>
                                                <td>
                                                    <span class="badge <?= $lancamento['status'] === 'aberto' ? ($lancamentoAtrasado ? 'bg-danger' : 'bg-warning text-dark') : 'bg-success' ?>">
                                                        <?= $lancamento['status'] === 'aberto' ? ($lancamentoAtrasado ? 'Atrasado' : 'Em aberto') : 'Pago' ?>
                                                    </span>
                                                </td>
                                                <td><?= financeiroData($lancamento['data_pagamento']) ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <?php if ($lancamento['status'] === 'aberto'): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-primary btn-sm btn-editar-compra"
                                                                data-id="<?= (int)$lancamento['id'] ?>"
                                                                data-cartao="<?= (int)$lancamento['cartao_id'] ?>"
                                                                data-data="<?= htmlspecialchars($lancamento['data_compra']) ?>"
                                                                data-fatura="<?= htmlspecialchars($mesFaturaLancamento) ?>"
                                                                data-descricao="<?= htmlspecialchars($lancamento['descricao']) ?>"
                                                                data-valor="<?= number_format((float)$lancamento['valor'], 2, ',', '.') ?>"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modalCompra"
                                                                title="Editar compra">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                                                                <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                                                                <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                                                <input type="hidden" name="acao" value="reabrir_lancamento">
                                                                <input type="hidden" name="id" value="<?= (int)$lancamento['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Reabrir compra">
                                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-danger btn-sm btn-excluir-lancamento"
                                                            data-id="<?= (int)$lancamento['id'] ?>"
                                                            data-descricao="<?= htmlspecialchars($textoCompra) ?>"
                                                            data-parcelada="<?= !empty($lancamento['grupo_parcelamento']) ? '1' : '0' ?>"
                                                            data-total-parcelas="<?= (int)($lancamento['parcelas_total'] ?? 1) ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalExcluirLancamento"
                                                            title="Excluir compra">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($tabelasDisponiveis): ?>
        <div class="modal fade" id="modalCartao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_cartao">
                        <input type="hidden" name="id" id="cartaoId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalCartao">Novo cartão</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="cartaoNome" class="form-label">Nome do cartão</label>
                                <input type="text" class="form-control" name="nome" id="cartaoNome" required>
                                <div class="invalid-feedback">Informe o nome.</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoLimite" class="form-label">Limite total</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="limite_total" id="cartaoLimite" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o limite.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoTipo" class="form-label">Tipo</label>
                                    <select class="form-select" name="tipo" id="cartaoTipo">
                                        <option value="credito">Cartão de crédito</option>
                                        <option value="loja">Loja específica</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="cartaoVencimento" class="form-label">Dia do vencimento</label>
                                <input type="number" min="1" max="31" class="form-control" name="dia_vencimento" id="cartaoVencimento" required>
                                <div class="invalid-feedback">Informe um dia entre 1 e 31.</div>
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

        <div class="modal fade" id="modalCompra" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_lancamento">
                        <input type="hidden" name="id" id="compraId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalCompra">Nova compra</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3" id="grupoTipoCompra">
                                <label for="compraTipo" class="form-label">Tipo de compra</label>
                                <select class="form-select" name="tipo_compra" id="compraTipo">
                                    <option value="unica">À vista</option>
                                    <option value="parcelada">Parcelada</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="compraCartao" class="form-label">Cartão</label>
                                <select class="form-select" name="cartao_id" id="compraCartao" required>
                                    <?php foreach ($cartoes as $cartao): ?>
                                        <option value="<?= (int)$cartao['id'] ?>" <?= (int)$cartao['id'] === $cartaoSelecionadoId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cartao['nome']) ?> · <?= financeiroMoeda((float)$cartao['disponivel']) ?> disponível
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="compraData" class="form-label">Data da compra</label>
                                    <input type="date" class="form-control" name="data_compra" id="compraData" value="<?= date('Y-m-d') ?>" required>
                                    <div class="invalid-feedback">Informe a data.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="compraMesFatura" class="form-label" id="compraMesFaturaLabel">Primeira fatura</label>
                                    <input
                                        type="month"
                                        class="form-control"
                                        name="mes_fatura_compra"
                                        id="compraMesFatura"
                                        value="<?= htmlspecialchars($mes) ?>"
                                        required>
                                    <div class="invalid-feedback">Informe o mês da fatura.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="compraValor" class="form-label" id="compraValorLabel">Valor da compra</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor" id="compraValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o valor.</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="compraDescricao" class="form-label">Descrição</label>
                                <input type="text" class="form-control" name="descricao" id="compraDescricao" required>
                                <div class="invalid-feedback">Informe a descrição.</div>
                            </div>
                            <div class="mb-3 d-none" id="campoParcelasCompra">
                                <label for="compraParcelasTotal" class="form-label">Quantidade de parcelas</label>
                                <input type="number" min="2" max="600" class="form-control" name="parcelas_total" id="compraParcelasTotal">
                                <div class="invalid-feedback">Informe a quantidade de parcelas.</div>
                                <small class="text-muted">
                                    O valor total será dividido e o limite será comprometido pela compra inteira.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Lançar compra</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($cartaoSelecionado): ?>
            <div class="modal fade" id="modalPagarFatura" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" class="financeiro-form" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="pagar_fatura">
                            <input type="hidden" name="cartao_id" value="<?= $cartaoSelecionadoId ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Pagar fatura</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <p>
                                    Pagar a fatura de <strong><?= htmlspecialchars($nomeMes) ?></strong>
                                    do cartão <?= htmlspecialchars($cartaoSelecionado['nome']) ?>.
                                </p>
                                <input type="hidden" name="mes_fatura" value="<?= htmlspecialchars($mes) ?>">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label for="dataPagamentoFatura" class="form-label">Data do pagamento</label>
                                        <input type="date" class="form-control" name="data_pagamento" id="dataPagamentoFatura" value="<?= date('Y-m-d') ?>" required>
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
        <?php endif; ?>

        <div class="modal fade" id="modalExcluirCartao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Excluir cartão</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="nomeCartaoExcluir"></strong>?</p>
                        <small class="text-danger">Todas as compras desse cartão também serão apagadas.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="excluir_cartao">
                            <input type="hidden" name="id" id="cartaoExcluirId">
                            <button type="submit" class="btn btn-danger">Sim, excluir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirLancamento" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="tituloExcluirLancamento">Excluir compra</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="descricaoLancamentoExcluir"></strong>?</p>
                        <small class="text-danger" id="avisoExcluirLancamento">O limite do cartão será recalculado.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="excluir_lancamento">
                            <input type="hidden" name="id" id="lancamentoExcluirId">
                            <button type="submit" class="btn btn-danger">Sim, excluir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= assetUrl('assets/financeiro.js') ?>"></script>
    <?php if ($tabelasDisponiveis): ?>
        <script>
            const cartaoSelecionado = <?= json_encode($cartaoSelecionadoId) ?>;
            const dataPadraoCompra = <?= json_encode($dataPadraoCompra) ?>;
            const mesFaturaSelecionado = <?= json_encode($mes) ?>;

            document.getElementById('mesCartao').addEventListener('change', function() {
                document.getElementById('formMesCartao').submit();
            });

            document.getElementById('btnNovoCartao').addEventListener('click', function() {
                document.getElementById('tituloModalCartao').textContent = 'Novo cartão';
                document.getElementById('cartaoId').value = '';
                document.getElementById('cartaoNome').value = '';
                document.getElementById('cartaoLimite').value = '';
                document.getElementById('cartaoTipo').value = 'credito';
                document.getElementById('cartaoVencimento').value = '';
            });

            document.querySelectorAll('.btn-editar-cartao').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalCartao').textContent = 'Editar cartão';
                    document.getElementById('cartaoId').value = this.dataset.id;
                    document.getElementById('cartaoNome').value = this.dataset.nome;
                    document.getElementById('cartaoLimite').value = this.dataset.limite;
                    document.getElementById('cartaoTipo').value = this.dataset.tipo;
                    document.getElementById('cartaoVencimento').value = this.dataset.vencimento || '';
                });
            });

            document.querySelectorAll('.btn-excluir-cartao').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('cartaoExcluirId').value = this.dataset.id;
                    document.getElementById('nomeCartaoExcluir').textContent = this.dataset.nome;
                });
            });

            const botaoNovaCompra = document.getElementById('btnNovaCompra');

            if (botaoNovaCompra) {
                botaoNovaCompra.addEventListener('click', function() {
                    document.getElementById('tituloModalCompra').textContent = 'Nova compra';
                    document.getElementById('compraId').value = '';
                    document.getElementById('grupoTipoCompra').classList.remove('d-none');
                    document.getElementById('compraTipo').value = 'unica';
                    document.getElementById('compraCartao').value = String(cartaoSelecionado);
                    document.getElementById('compraData').value = dataPadraoCompra;
                    document.getElementById('compraMesFaturaLabel').textContent = 'Primeira fatura';
                    document.getElementById('compraMesFatura').value = mesFaturaSelecionado;
                    document.getElementById('compraDescricao').value = '';
                    document.getElementById('compraValor').value = '';
                    document.getElementById('compraParcelasTotal').value = '';
                    atualizarCamposParcelamentoCompra();
                });
            }

            document.querySelectorAll('.btn-editar-compra').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalCompra').textContent = 'Editar compra';
                    document.getElementById('compraId').value = this.dataset.id;
                    document.getElementById('grupoTipoCompra').classList.add('d-none');
                    document.getElementById('campoParcelasCompra').classList.add('d-none');
                    document.getElementById('compraCartao').value = this.dataset.cartao;
                    document.getElementById('compraData').value = this.dataset.data;
                    document.getElementById('compraMesFaturaLabel').textContent = 'Fatura';
                    document.getElementById('compraMesFatura').value = this.dataset.fatura;
                    document.getElementById('compraDescricao').value = this.dataset.descricao;
                    document.getElementById('compraValor').value = this.dataset.valor;
                });
            });

            function atualizarCamposParcelamentoCompra() {
                const parcelada = document.getElementById('compraTipo').value === 'parcelada';
                const campoParcelas = document.getElementById('campoParcelasCompra');
                const parcelasTotal = document.getElementById('compraParcelasTotal');

                campoParcelas.classList.toggle('d-none', !parcelada);
                parcelasTotal.required = parcelada;
                document.getElementById('compraValorLabel').textContent = parcelada ?
                    'Valor total da compra' :
                    'Valor da compra';

                if (parcelada) {
                    parcelasTotal.focus();
                } else {
                    parcelasTotal.classList.remove('is-invalid');
                }
            }

            document.getElementById('compraTipo').addEventListener('change', atualizarCamposParcelamentoCompra);

            document.querySelectorAll('.btn-excluir-lancamento').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    const parcelada = this.dataset.parcelada === '1';
                    document.getElementById('lancamentoExcluirId').value = this.dataset.id;
                    document.getElementById('descricaoLancamentoExcluir').textContent = this.dataset.descricao;
                    document.getElementById('tituloExcluirLancamento').textContent = parcelada ?
                        'Excluir compra parcelada' :
                        'Excluir compra';
                    document.getElementById('avisoExcluirLancamento').textContent = parcelada ?
                        'Todas as ' + this.dataset.totalParcelas + ' parcelas desta compra serão excluídas e o limite será recalculado.' :
                        'O limite do cartão será recalculado.';
                });
            });

            document.querySelectorAll('.financeiro-form').forEach(function(formulario) {
                formulario.addEventListener('submit', function(event) {
                    let primeiroInvalido = null;

                    formulario.querySelectorAll('[required]').forEach(function(campo) {
                        const invalido = campo.value.trim() === '';
                        campo.classList.toggle('is-invalid', invalido);
                        primeiroInvalido = primeiroInvalido || (invalido ? campo : null);
                    });

                    if (primeiroInvalido) {
                        event.preventDefault();
                        primeiroInvalido.focus();
                    }
                });
            });

            document.querySelectorAll('.financeiro-form input, .financeiro-form select').forEach(function(campo) {
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