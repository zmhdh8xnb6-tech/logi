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
$tabelasDisponiveis = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_recebimentos', 'financeiro_contas']
);
$categoriasDisponiveis = $tabelasDisponiveis && financeiroCategoriasDisponiveis($pdo);
$categoriasReceita = [];
$categoriasDespesa = [];
$todasCategoriasReceita = [];
$todasCategoriasDespesa = [];
$categoriasPorId = [];

if ($categoriasDisponiveis) {
    financeiroGarantirCategoriasPadrao($pdo, $usuarioId);
    $categoriasReceita = financeiroListarCategorias($pdo, $usuarioId, 'receita');
    $categoriasDespesa = financeiroListarCategorias($pdo, $usuarioId, 'despesa');
    $todasCategoriasReceita = financeiroListarCategorias($pdo, $usuarioId, 'receita', false);
    $todasCategoriasDespesa = financeiroListarCategorias($pdo, $usuarioId, 'despesa', false);

    foreach (array_merge($todasCategoriasReceita, $todasCategoriasDespesa) as $categoria) {
        $categoriasPorId[(int)$categoria['id']] = $categoria;
    }
}

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
        if (!$categoriasDisponiveis) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das categorias financeiras antes de salvar.',
                'danger'
            );
        }

        $data = trim($_POST['data_recebimento'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $recebidoDe = trim($_POST['recebido_de'] ?? '');
        $valorInformado = $_POST['valor'] ?? '';
        $valor = financeiroValorEntrada($valorInformado);
        $tipoRecebimento = ($_POST['tipo_recebimento'] ?? '') === 'recorrente'
            ? 'recorrente'
            : 'unico';
        $recorrenciaRecebimentoId = (int)($_POST['recorrencia_recebimento_id'] ?? 0);
        $fimRecorrenciaRecebimento = trim($_POST['fim_recorrencia_recebimento'] ?? '');
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);

        if (
            $data === ''
            || $descricao === ''
            || !financeiroValorValido($valorInformado)
            || !financeiroCategoriaValida($pdo, $usuarioId, $categoriaId, 'receita')
        ) {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados do recebimento corretamente.', 'danger');
        }

        if ($tipoRecebimento === 'recorrente') {
            if (
                !financeiroTabelasDisponiveis($pdo, ['financeiro_recebimentos_recorrentes'])
                || !financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'recorrencia_id')
                || !financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'competencia_recorrencia')
            ) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Execute o SQL dos recebimentos recorrentes antes de cadastrar.',
                    'danger'
                );
            }

            $fimMesRecebimento = null;
            if ($fimRecorrenciaRecebimento !== '') {
                if (!preg_match('/^\d{4}-\d{2}$/', $fimRecorrenciaRecebimento)) {
                    financeiroRedirecionar($urlRetorno, 'Informe corretamente o último mês da recorrência.', 'danger');
                }

                $fimMesRecebimento = $fimRecorrenciaRecebimento . '-01';
                if ($fimMesRecebimento < date('Y-m-01', strtotime($data))) {
                    financeiroRedirecionar(
                        $urlRetorno,
                        'O último mês não pode ser anterior ao primeiro recebimento.',
                        'danger'
                    );
                }
            }

            if ($recorrenciaRecebimentoId > 0) {
                $stmtAntes = $pdo->prepare("
                    SELECT *
                    FROM financeiro_recebimentos_recorrentes
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmtAntes->execute([$recorrenciaRecebimentoId, $usuarioId]);
                $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

                if (!$antes) {
                    financeiroRedirecionar($urlRetorno, 'Recebimento recorrente não encontrado.', 'danger');
                }

                $stmt = $pdo->prepare("
                    UPDATE financeiro_recebimentos_recorrentes
                    SET descricao = ?,
                        recebido_de = ?,
                        valor = ?,
                        primeiro_recebimento = ?,
                        fim_mes = ?,
                        categoria_id = ?,
                        ativa = 1
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmt->execute([
                    $descricao,
                    $recebidoDe,
                    $valor,
                    $data,
                    $fimMesRecebimento,
                    $categoriaId,
                    $recorrenciaRecebimentoId,
                    $usuarioId,
                ]);
                $stmt = $pdo->prepare("
                    UPDATE financeiro_recebimentos
                    SET categoria_id = ?
                    WHERE usuario_id = ? AND recorrencia_id = ?
                ");
                $stmt->execute([
                    $categoriaId,
                    $usuarioId,
                    $recorrenciaRecebimentoId,
                ]);
                $stmt = $pdo->prepare("
                    DELETE FROM financeiro_recebimentos
                    WHERE usuario_id = ?
                      AND recorrencia_id = ?
                      AND data_recebimento >= ?
                ");
                $stmt->execute([$usuarioId, $recorrenciaRecebimentoId, date('Y-m-01')]);
                $depois = array_merge($antes, [
                    'descricao' => $descricao,
                    'recebido_de' => $recebidoDe,
                    'valor' => $valor,
                    'primeiro_recebimento' => $data,
                    'fim_mes' => $fimMesRecebimento,
                    'categoria_id' => $categoriaId,
                    'ativa' => 1,
                ]);
                $mudancas = auditoriaMudancas($antes, $depois);
                registrarAuditoria(
                    $pdo,
                    'Financeiro',
                    'editar',
                    'recebimento_recorrente',
                    $recorrenciaRecebimentoId,
                    'Alterou o recebimento recorrente ' . $descricao,
                    $mudancas['antes'],
                    $mudancas['depois']
                );
                financeiroRedirecionar($urlRetorno, 'Recebimento recorrente atualizado com sucesso.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO financeiro_recebimentos_recorrentes (
                    usuario_id,
                    descricao,
                    recebido_de,
                    valor,
                    primeiro_recebimento,
                    fim_mes,
                    categoria_id,
                    ativa
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $usuarioId,
                $descricao,
                $recebidoDe,
                $valor,
                $data,
                $fimMesRecebimento,
                $categoriaId,
            ]);
            $novaRecorrenciaId = (int)$pdo->lastInsertId();
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'criar',
                'recebimento_recorrente',
                $novaRecorrenciaId,
                'Cadastrou o recebimento recorrente ' . $descricao,
                null,
                [
                    'descricao' => $descricao,
                    'recebido_de' => $recebidoDe,
                    'valor' => $valor,
                    'primeiro_recebimento' => $data,
                    'fim_mes' => $fimMesRecebimento,
                    'categoria_id' => $categoriaId,
                ]
            );
            financeiroRedirecionar($urlRetorno, 'Recebimento recorrente cadastrado com sucesso.');
        }

        if ($id > 0) {
            $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_recebimentos WHERE id = ? AND usuario_id = ?");
            $stmtAntes->execute([$id, $usuarioId]);
            $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC) ?: [];

            if (!empty($antes['recorrencia_id'])) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Edite a regra do recebimento recorrente pelo botão de lápis.',
                    'warning'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE financeiro_recebimentos
                SET data_recebimento = ?, descricao = ?, recebido_de = ?, valor = ?, categoria_id = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$data, $descricao, $recebidoDe, $valor, $categoriaId, $id, $usuarioId]);
            $depois = array_merge($antes, [
                'data_recebimento' => $data,
                'descricao' => $descricao,
                'recebido_de' => $recebidoDe,
                'valor' => $valor,
                'categoria_id' => $categoriaId,
            ]);
            $mudancas = auditoriaMudancas($antes, $depois);
            registrarAuditoria($pdo, 'Financeiro', 'editar', 'recebimento', $id, 'Alterou o recebimento ' . $descricao, $mudancas['antes'], $mudancas['depois']);
            financeiroRedirecionar($urlRetorno, 'Recebimento atualizado com sucesso.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_recebimentos
                (usuario_id, data_recebimento, descricao, recebido_de, valor, categoria_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuarioId, $data, $descricao, $recebidoDe, $valor, $categoriaId]);
        $novoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro',
            'criar',
            'recebimento',
            $novoId,
            'Cadastrou o recebimento ' . $descricao,
            null,
            [
                'data' => $data,
                'descricao' => $descricao,
                'recebido_de' => $recebidoDe,
                'valor' => $valor,
                'categoria_id' => $categoriaId,
            ]
        );
        financeiroRedirecionar($urlRetorno, 'Recebimento cadastrado com sucesso.');
    }

    if ($acao === 'excluir_recebimento') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_recebimentos WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!empty($antes['recorrencia_id'])) {
            financeiroRedirecionar(
                $urlRetorno,
                'Use a opção de encerrar para excluir o recebimento recorrente.',
                'warning'
            );
        }

        $stmt = $pdo->prepare("DELETE FROM financeiro_recebimentos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuarioId]);
        if ($antes) {
            registrarAuditoria($pdo, 'Financeiro', 'excluir', 'recebimento', $id, 'Excluiu o recebimento ' . $antes['descricao'], $antes, null);
        }
        financeiroRedirecionar($urlRetorno, 'Recebimento excluído com sucesso.');
    }

    if ($acao === 'excluir_recorrencia_recebimento') {
        if (!financeiroTabelasDisponiveis($pdo, ['financeiro_recebimentos_recorrentes'])) {
            financeiroRedirecionar($urlRetorno, 'A tabela de recebimentos recorrentes não foi encontrada.', 'danger');
        }

        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM financeiro_recebimentos_recorrentes
            WHERE id = ? AND usuario_id = ?
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$antes) {
            financeiroRedirecionar($urlRetorno, 'Recebimento recorrente não encontrado.', 'danger');
        }

        $pdo->beginTransaction();
        try {
            $inicioProximoMes = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
            $stmt = $pdo->prepare("
                UPDATE financeiro_recebimentos
                SET recorrencia_id = NULL, competencia_recorrencia = NULL
                WHERE usuario_id = ?
                  AND recorrencia_id = ?
                  AND data_recebimento < ?
            ");
            $stmt->execute([$usuarioId, $id, $inicioProximoMes]);

            $stmt = $pdo->prepare("
                DELETE FROM financeiro_recebimentos
                WHERE usuario_id = ? AND recorrencia_id = ?
            ");
            $stmt->execute([$usuarioId, $id]);

            $stmt = $pdo->prepare("
                DELETE FROM financeiro_recebimentos_recorrentes
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $usuarioId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            financeiroRedirecionar($urlRetorno, 'Não foi possível encerrar o recebimento recorrente.', 'danger');
        }

        registrarAuditoria(
            $pdo,
            'Financeiro',
            'excluir',
            'recebimento_recorrente',
            $id,
            'Encerrou o recebimento recorrente ' . $antes['descricao'],
            $antes,
            null
        );
        financeiroRedirecionar($urlRetorno, 'Recebimento recorrente encerrado com sucesso.');
    }

    if ($acao === 'salvar_conta') {
        if (!$categoriasDisponiveis) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das categorias financeiras antes de salvar.',
                'danger'
            );
        }

        $descricao = trim($_POST['descricao'] ?? '');
        $valorPrevistoInformado = $_POST['valor_previsto'] ?? '';
        $valorPrevisto = financeiroValorEntrada($valorPrevistoInformado);
        $vencimento = trim($_POST['vencimento'] ?? '');
        $tiposPermitidos = ['unica', 'parcelada', 'recorrente'];
        $tipoLancamento = in_array($_POST['tipo_lancamento'] ?? '', $tiposPermitidos, true)
            ? $_POST['tipo_lancamento']
            : 'unica';
        $parcelaInicial = (int)($_POST['parcela_inicial'] ?? 1);
        $parcelasTotal = (int)($_POST['parcelas_total'] ?? 1);
        $recorrenciaId = (int)($_POST['recorrencia_id'] ?? 0);
        $fimRecorrencia = trim($_POST['fim_recorrencia'] ?? '');
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);

        if (
            $descricao === ''
            || !financeiroValorValido($valorPrevistoInformado)
            || $vencimento === ''
            || !financeiroCategoriaValida($pdo, $usuarioId, $categoriaId, 'despesa')
        ) {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados da conta corretamente.', 'danger');
        }

        if ($tipoLancamento === 'recorrente') {
            if (
                !financeiroTabelasDisponiveis($pdo, ['financeiro_contas_recorrentes'])
                || !financeiroColunaExiste($pdo, 'financeiro_contas', 'recorrencia_id')
                || !financeiroColunaExiste($pdo, 'financeiro_contas', 'competencia_recorrencia')
            ) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Execute o SQL das contas recorrentes antes de cadastrar.',
                    'danger'
                );
            }

            $fimMes = null;
            if ($fimRecorrencia !== '') {
                if (!preg_match('/^\d{4}-\d{2}$/', $fimRecorrencia)) {
                    financeiroRedirecionar($urlRetorno, 'Informe corretamente o último mês da recorrência.', 'danger');
                }

                $fimMes = $fimRecorrencia . '-01';
                if ($fimMes < date('Y-m-01', strtotime($vencimento))) {
                    financeiroRedirecionar(
                        $urlRetorno,
                        'O último mês não pode ser anterior ao primeiro vencimento.',
                        'danger'
                    );
                }
            }

            if ($recorrenciaId > 0) {
                $stmtAntes = $pdo->prepare("
                    SELECT *
                    FROM financeiro_contas_recorrentes
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmtAntes->execute([$recorrenciaId, $usuarioId]);
                $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

                if (!$antes) {
                    financeiroRedirecionar($urlRetorno, 'Conta recorrente não encontrada.', 'danger');
                }

                $stmt = $pdo->prepare("
                    UPDATE financeiro_contas_recorrentes
                    SET descricao = ?, valor = ?, primeiro_vencimento = ?, fim_mes = ?,
                        categoria_id = ?, ativa = 1
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmt->execute([
                    $descricao,
                    $valorPrevisto,
                    $vencimento,
                    $fimMes,
                    $categoriaId,
                    $recorrenciaId,
                    $usuarioId,
                ]);
                $stmt = $pdo->prepare("
                    UPDATE financeiro_contas
                    SET categoria_id = ?
                    WHERE usuario_id = ? AND recorrencia_id = ?
                ");
                $stmt->execute([$categoriaId, $usuarioId, $recorrenciaId]);
                $stmt = $pdo->prepare("
                    DELETE FROM financeiro_contas
                    WHERE usuario_id = ?
                      AND recorrencia_id = ?
                      AND status = 'pendente'
                      AND vencimento >= ?
                ");
                $stmt->execute([$usuarioId, $recorrenciaId, date('Y-m-01')]);
                $depois = array_merge($antes, [
                    'descricao' => $descricao,
                    'valor' => $valorPrevisto,
                    'primeiro_vencimento' => $vencimento,
                    'fim_mes' => $fimMes,
                    'categoria_id' => $categoriaId,
                    'ativa' => 1,
                ]);
                $mudancas = auditoriaMudancas($antes, $depois);
                registrarAuditoria(
                    $pdo,
                    'Financeiro',
                    'editar',
                    'recorrencia',
                    $recorrenciaId,
                    'Alterou a conta recorrente ' . $descricao,
                    $mudancas['antes'],
                    $mudancas['depois']
                );
                financeiroRedirecionar($urlRetorno, 'Conta recorrente atualizada com sucesso.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO financeiro_contas_recorrentes (
                    usuario_id,
                    descricao,
                    valor,
                    primeiro_vencimento,
                    fim_mes,
                    categoria_id,
                    ativa
                )
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $usuarioId,
                $descricao,
                $valorPrevisto,
                $vencimento,
                $fimMes,
                $categoriaId,
            ]);
            $novaRecorrenciaId = (int)$pdo->lastInsertId();
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'criar',
                'recorrencia',
                $novaRecorrenciaId,
                'Cadastrou a conta recorrente ' . $descricao,
                null,
                [
                    'descricao' => $descricao,
                    'valor' => $valorPrevisto,
                    'primeiro_vencimento' => $vencimento,
                    'fim_mes' => $fimMes,
                    'categoria_id' => $categoriaId,
                ]
            );
            financeiroRedirecionar($urlRetorno, 'Conta recorrente cadastrada com sucesso.');
        }

        if ($id > 0) {
            $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
            $stmtAntes->execute([$id, $usuarioId]);
            $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC) ?: [];

            if (!empty($antes['cartao_id'])) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Faturas automáticas devem ser alteradas pela tela de cartões.',
                    'warning'
                );
            }

            if (!empty($antes['recorrencia_id'])) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'Edite a regra da conta recorrente pelo botão de lápis.',
                    'warning'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE financeiro_contas
                SET descricao = ?, valor_previsto = ?, vencimento = ?, categoria_id = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$descricao, $valorPrevisto, $vencimento, $categoriaId, $id, $usuarioId]);
            $depois = array_merge($antes, [
                'descricao' => $descricao,
                'valor_previsto' => $valorPrevisto,
                'vencimento' => $vencimento,
                'categoria_id' => $categoriaId,
            ]);
            $mudancas = auditoriaMudancas($antes, $depois);
            registrarAuditoria($pdo, 'Financeiro', 'editar', 'conta', $id, 'Alterou a conta ' . $descricao, $mudancas['antes'], $mudancas['depois']);
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
                    categoria_id,
                    grupo_parcelamento,
                    parcela_numero,
                    parcelas_total
                )
                VALUES (?, ?, ?, ?, 'pendente', ?, ?, ?, ?)
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
                        $categoriaId,
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
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'criar_parcelas',
                'conta',
                $grupoParcelamento,
                'Gerou ' . $quantidadeGerada . ' parcelas da conta ' . $descricao,
                null,
                [
                    'descricao' => $descricao,
                    'valor_parcela' => $valorPrevisto,
                    'primeiro_vencimento' => $vencimento,
                    'parcela_inicial' => $parcelaInicial,
                    'parcelas_total' => $parcelasTotal,
                    'categoria_id' => $categoriaId,
                ]
            );
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
                categoria_id,
                grupo_parcelamento,
                parcela_numero,
                parcelas_total
            )
            VALUES (?, ?, ?, ?, 'pendente', ?, NULL, NULL, NULL)
        ");
        $stmt->execute([$usuarioId, $descricao, $valorPrevisto, $vencimento, $categoriaId]);
        $novaContaId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro',
            'criar',
            'conta',
            $novaContaId,
            'Cadastrou a conta ' . $descricao,
            null,
            [
                'descricao' => $descricao,
                'valor_previsto' => $valorPrevisto,
                'vencimento' => $vencimento,
                'categoria_id' => $categoriaId,
            ]
        );
        financeiroRedirecionar($urlRetorno, 'Conta cadastrada com sucesso.');
    }

    if ($acao === 'pagar_conta') {
        $valorPagoInformado = $_POST['valor_pago'] ?? '';
        $valorPago = financeiroValorEntrada($valorPagoInformado);
        $dataPagamento = trim($_POST['data_pagamento'] ?? '');

        if ($id <= 0 || !financeiroValorValido($valorPagoInformado) || $dataPagamento === '') {
            financeiroRedirecionar($urlRetorno, 'Informe o valor e a data do pagamento.', 'danger');
        }

        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!empty($antes['cartao_id']) && !empty($antes['competencia_cartao'])) {
            $inicioCompetencia = $antes['competencia_cartao'] . '-01';
            $fimCompetencia = date('Y-m-d', strtotime($inicioCompetencia . ' +1 month'));
            $valorPago = (float)$antes['valor_previsto'];
            $temCompetenciaFatura = financeiroColunaExiste(
                $pdo,
                'financeiro_cartao_lancamentos',
                'competencia_fatura'
            );
            $filtroCompetenciaFatura = $temCompetenciaFatura
                ? "COALESCE(competencia_fatura, DATE_FORMAT(data_compra, '%Y-%m-01'))"
                : "DATE_FORMAT(data_compra, '%Y-%m-01')";
            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE financeiro_cartao_lancamentos
                    SET status = 'pago', data_pagamento = ?
                    WHERE usuario_id = ?
                      AND cartao_id = ?
                      AND {$filtroCompetenciaFatura} >= ?
                      AND {$filtroCompetenciaFatura} < ?
                      AND status = 'aberto'
                ");
                $stmt->execute([
                    $dataPagamento,
                    $usuarioId,
                    $antes['cartao_id'],
                    $inicioCompetencia,
                    $fimCompetencia,
                ]);

                $stmt = $pdo->prepare("
                    UPDATE financeiro_contas
                    SET status = 'pago', valor_pago = ?, data_pagamento = ?
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmt->execute([$valorPago, $dataPagamento, $id, $usuarioId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                financeiroRedirecionar($urlRetorno, 'Não foi possível pagar a fatura do cartão.', 'danger');
            }
        } else {
            $stmt = $pdo->prepare("
                UPDATE financeiro_contas
                SET status = 'pago', valor_pago = ?, data_pagamento = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$valorPago, $dataPagamento, $id, $usuarioId]);
        }

        if ($antes) {
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'pagar',
                'conta',
                $id,
                'Marcou como paga a conta ' . $antes['descricao'],
                ['status' => $antes['status'], 'valor_pago' => $antes['valor_pago'], 'data_pagamento' => $antes['data_pagamento']],
                ['status' => 'pago', 'valor_pago' => $valorPago, 'data_pagamento' => $dataPagamento]
            );
        }
        financeiroRedirecionar($urlRetorno, 'Conta marcada como paga.');
    }

    if ($acao === 'reabrir_conta') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!empty($antes['cartao_id'])) {
            financeiroRedirecionar(
                'financeiro_cartoes.php?cartao=' . (int)$antes['cartao_id'],
                'Reabra as compras pela tela do cartão para conferir o limite disponível.',
                'warning'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE financeiro_contas
            SET status = 'pendente', valor_pago = NULL, data_pagamento = NULL
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        if ($antes) {
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'reabrir',
                'conta',
                $id,
                'Voltou para pendente a conta ' . $antes['descricao'],
                ['status' => $antes['status'], 'valor_pago' => $antes['valor_pago'], 'data_pagamento' => $antes['data_pagamento']],
                ['status' => 'pendente', 'valor_pago' => null, 'data_pagamento' => null]
            );
        }
        financeiroRedirecionar($urlRetorno, 'Conta voltou para pendente.');
    }

    if ($acao === 'excluir_conta') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$antes) {
            financeiroRedirecionar($urlRetorno, 'Conta não encontrada.', 'danger');
        }

        if (!empty($antes['cartao_id'])) {
            financeiroRedirecionar(
                $urlRetorno,
                'A fatura automática é removida ao excluir as compras do cartão.',
                'warning'
            );
        }

        if (!empty($antes['recorrencia_id'])) {
            financeiroRedirecionar(
                $urlRetorno,
                'Use a opção de encerrar para excluir a conta recorrente.',
                'warning'
            );
        }

        if (!empty($antes['grupo_parcelamento'])) {
            $stmtResumo = $pdo->prepare("
                SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor_previsto), 0) AS valor_total
                FROM financeiro_contas
                WHERE usuario_id = ? AND grupo_parcelamento = ?
            ");
            $stmtResumo->execute([$usuarioId, $antes['grupo_parcelamento']]);
            $resumoExclusao = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [
                'quantidade' => 0,
                'valor_total' => 0,
            ];

            $stmt = $pdo->prepare("
                DELETE FROM financeiro_contas
                WHERE usuario_id = ? AND grupo_parcelamento = ?
            ");
            $stmt->execute([$usuarioId, $antes['grupo_parcelamento']]);

            registrarAuditoria(
                $pdo,
                'Financeiro',
                'excluir_parcelamento',
                'conta',
                $antes['grupo_parcelamento'],
                'Excluiu todas as parcelas da conta ' . $antes['descricao'],
                [
                    'conta' => $antes,
                    'quantidade_parcelas' => (int)$resumoExclusao['quantidade'],
                    'valor_total' => (float)$resumoExclusao['valor_total'],
                ],
                null
            );
            financeiroRedirecionar(
                $urlRetorno,
                (int)$resumoExclusao['quantidade'] . ' parcelas excluídas com sucesso.'
            );
        }

        $stmt = $pdo->prepare("DELETE FROM financeiro_contas WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuarioId]);
        registrarAuditoria($pdo, 'Financeiro', 'excluir', 'conta', $id, 'Excluiu a conta ' . $antes['descricao'], $antes, null);
        financeiroRedirecionar($urlRetorno, 'Conta excluída com sucesso.');
    }

    if ($acao === 'excluir_recorrencia') {
        if (!financeiroTabelasDisponiveis($pdo, ['financeiro_contas_recorrentes'])) {
            financeiroRedirecionar($urlRetorno, 'A tabela de contas recorrentes não foi encontrada.', 'danger');
        }

        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM financeiro_contas_recorrentes
            WHERE id = ? AND usuario_id = ?
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$antes) {
            financeiroRedirecionar($urlRetorno, 'Conta recorrente não encontrada.', 'danger');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE financeiro_contas
                SET recorrencia_id = NULL, competencia_recorrencia = NULL
                WHERE usuario_id = ?
                  AND recorrencia_id = ?
                  AND (status = 'pago' OR vencimento < ?)
            ");
            $stmt->execute([
                $usuarioId,
                $id,
                date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month')),
            ]);

            $stmt = $pdo->prepare("
                DELETE FROM financeiro_contas
                WHERE usuario_id = ? AND recorrencia_id = ? AND status = 'pendente'
            ");
            $stmt->execute([$usuarioId, $id]);

            $stmt = $pdo->prepare("
                DELETE FROM financeiro_contas_recorrentes
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $usuarioId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            financeiroRedirecionar($urlRetorno, 'Não foi possível encerrar a conta recorrente.', 'danger');
        }

        registrarAuditoria(
            $pdo,
            'Financeiro',
            'excluir',
            'recorrencia',
            $id,
            'Encerrou a conta recorrente ' . $antes['descricao'],
            $antes,
            null
        );
        financeiroRedirecionar($urlRetorno, 'Conta recorrente encerrada com sucesso.');
    }

    financeiroRedirecionar($urlRetorno, 'Ação financeira inválida.', 'danger');
}

$mensagem = financeiroObterMensagem();
$recebimentos = [];
$contas = [];
$totalReceitas = 0.0;
$totalRecebidoAtual = 0.0;
$totalPrevisto = 0.0;
$totalPago = 0.0;
$totalPagoAtual = 0.0;
$totalPendente = 0.0;
$recorrenciasPorId = [];
$recorrenciasRecebimentosPorId = [];
$alertasFinanceiros = [];

if ($tabelasDisponiveis) {
    financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
    $mesAtualAlertas = date('Y-m');
    $proximoMesAlertas = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));

    foreach (array_unique([$mes, $mesAtualAlertas, $proximoMesAlertas]) as $mesSincronizar) {
        financeiroSincronizarContasRecorrentes($pdo, $usuarioId, $mesSincronizar);
    }

    financeiroSincronizarRecebimentosRecorrentes($pdo, $usuarioId, $mes);
    $alertasFinanceiros = financeiroListarAlertasVencimento($pdo, $usuarioId, 10);

    if (financeiroTabelasDisponiveis($pdo, ['financeiro_contas_recorrentes'])) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_contas_recorrentes
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuarioId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recorrencia) {
            $recorrenciasPorId[(int)$recorrencia['id']] = $recorrencia;
        }
    }

    if (financeiroTabelasDisponiveis($pdo, ['financeiro_recebimentos_recorrentes'])) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_recebimentos_recorrentes
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuarioId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recorrenciaRecebimento) {
            $recorrenciasRecebimentosPorId[(int)$recorrenciaRecebimento['id']] = $recorrenciaRecebimento;
        }
    }

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
    $hoje = date('Y-m-d');

    foreach ($recebimentos as $recebimento) {
        if ($recebimento['data_recebimento'] <= $hoje) {
            $totalRecebidoAtual += (float)$recebimento['valor'];
        }
    }

    foreach ($contas as $conta) {
        $pagaNoMesSelecionado = $conta['status'] === 'pago'
            && !empty($conta['data_pagamento'])
            && $conta['data_pagamento'] >= $inicioMes
            && $conta['data_pagamento'] < $fimMes;

        if ($conta['status'] !== 'pago' || $pagaNoMesSelecionado) {
            $totalPrevisto += (float)$conta['valor_previsto'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor_pago), 0)
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND status = 'pago'
          AND data_pagamento >= ?
          AND data_pagamento < ?
          AND data_pagamento <= ?
    ");
    $stmt->execute([$usuarioId, $inicioMes, $fimMes, $hoje]);
    $totalPagoAtual = (float)$stmt->fetchColumn();

    foreach ($contas as $conta) {
        if ($conta['status'] === 'pago') {
            $totalPago += (float)($conta['valor_pago'] ?? 0);
        } else {
            $totalPendente += (float)$conta['valor_previsto'];
        }
    }
}

$saldoAtual = $totalRecebidoAtual - $totalPagoAtual;
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
                    <a href="financeiro_categorias.php" class="btn btn-outline-primary">
                        <i class="bi bi-tags"></i> Categorias
                    </a>
                    <a href="financeiro_relatorio.php?mes=<?= htmlspecialchars($mes) ?>" class="btn btn-outline-success">
                        <i class="bi bi-bar-chart"></i> Relatório
                    </a>
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

            <?php if ($tabelasDisponiveis && !$categoriasDisponiveis): ?>
                <div class="alert alert-warning">
                    Execute o SQL das categorias financeiras para classificar lançamentos e acessar os relatórios.
                </div>
            <?php endif; ?>

            <?php if (!$tabelasDisponiveis): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o SQL do financeiro no phpMyAdmin e atualize esta página.
                </div>
            <?php else: ?>
                <?php include 'includes/financeiro_alertas.php'; ?>

                <div class="financeiro-filtros mb-4">
                    <span class="financeiro-mes-titulo"><?= htmlspecialchars($nomeMes) ?></span>

                    <div class="financeiro-navegacao-mes">
                        <a
                            href="financeiro.php?mes=<?= htmlspecialchars($mesAnterior) ?>"
                            class="btn btn-outline-secondary"
                            title="Mês anterior"
                            aria-label="Mês anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <form method="get" id="formMesFinanceiro">
                            <label for="mesFinanceiro" class="visually-hidden">Escolher mês</label>
                            <input
                                type="month"
                                class="form-control financeiro-calendario"
                                name="mes"
                                id="mesFinanceiro"
                                value="<?= htmlspecialchars($mes) ?>"
                                title="Escolher outro mês">
                        </form>

                        <a
                            href="financeiro.php?mes=<?= htmlspecialchars(date('Y-m')) ?>"
                            class="btn btn-outline-primary"
                            title="Voltar para o mês atual"
                            aria-label="Voltar para o mês atual">
                            <i class="bi bi-calendar-check"></i>
                        </a>

                        <a
                            href="financeiro.php?mes=<?= htmlspecialchars($proximoMes) ?>"
                            class="btn btn-outline-secondary"
                            title="Próximo mês"
                            aria-label="Próximo mês">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <section class="financeiro-resumo mb-4" aria-label="Resumo financeiro">
                    <div class="financeiro-metrica metrica-receita">
                        <span>Recebido até hoje</span>
                        <strong><?= financeiroMoeda($totalRecebidoAtual) ?></strong>
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

                    <div class="financeiro-lista-filtros">
                        <div class="financeiro-campo-busca">
                            <i class="bi bi-search"></i>
                            <input
                                type="search"
                                class="form-control"
                                id="filtroBuscaRecebimentos"
                                placeholder="Buscar recebimento..."
                                aria-label="Buscar recebimento">
                        </div>
                        <select class="form-select" id="filtroStatusRecebimentos" aria-label="Filtrar recebimentos por situação">
                            <option value="">Todas as situações</option>
                            <option value="disponivel">Disponível</option>
                            <option value="agendado">Agendado</option>
                        </select>
                        <select class="form-select" id="filtroCategoriaRecebimentos" aria-label="Filtrar recebimentos por categoria">
                            <option value="">Todas as categorias</option>
                            <option value="sem_categoria">Sem categoria</option>
                            <?php foreach ($todasCategoriasReceita as $categoria): ?>
                                <option value="<?= (int)$categoria['id'] ?>">
                                    <?= htmlspecialchars($categoria['nome']) ?><?= (int)$categoria['ativa'] === 1 ? '' : ' (desativada)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela" id="tabelaRecebimentos">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Descrição</th>
                                    <th>Recebido de</th>
                                    <th>Situação</th>
                                    <th class="text-end">Valor</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recebimentos === []): ?>
                                    <tr>
                                        <td colspan="6" class="financeiro-vazio">Nenhum recebimento neste mês.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($recebimentos as $recebimento):
                                    $recebimentoRecorrente = !empty($recebimento['recorrencia_id']);
                                    $regraRecebimento = $recebimentoRecorrente
                                        ? ($recorrenciasRecebimentosPorId[(int)$recebimento['recorrencia_id']] ?? null)
                                        : null;
                                    $recebimentoDisponivel = $recebimento['data_recebimento'] <= date('Y-m-d');
                                    $categoriaRecebimento = $categoriasPorId[(int)($recebimento['categoria_id'] ?? 0)] ?? null;
                                ?>
                                    <tr
                                        class="linha-filtro-recebimento"
                                        data-filtro="<?= htmlspecialchars($recebimento['descricao'] . ' ' . ($recebimento['recebido_de'] ?? '') . ' ' . ($categoriaRecebimento['nome'] ?? 'Sem categoria')) ?>"
                                        data-status="<?= $recebimentoDisponivel ? 'disponivel' : 'agendado' ?>"
                                        data-categoria="<?= $categoriaRecebimento ? (int)$categoriaRecebimento['id'] : 'sem_categoria' ?>">
                                        <td><?= financeiroData($recebimento['data_recebimento']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($recebimento['descricao']) ?>
                                            <?php if ($recebimentoRecorrente): ?>
                                                <span class="badge bg-info text-dark ms-1">Recorrente</span>
                                            <?php endif; ?>
                                            <span
                                                class="badge ms-1 <?= $categoriaRecebimento ? 'text-white' : 'bg-secondary' ?>"
                                                <?= $categoriaRecebimento ? 'style="background-color:' . htmlspecialchars($categoriaRecebimento['cor']) . '"' : '' ?>>
                                                <?= htmlspecialchars($categoriaRecebimento['nome'] ?? 'Sem categoria') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($recebimento['recebido_de'] ?: '-') ?></td>
                                        <td>
                                            <span class="badge <?= $recebimentoDisponivel ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                <?= $recebimentoDisponivel ? 'Disponível' : 'Agendado' ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold <?= $recebimentoDisponivel ? 'text-success' : 'text-muted' ?>">
                                            <?= financeiroMoeda((float)$recebimento['valor']) ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if ($recebimentoRecorrente && $regraRecebimento): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-primary btn-sm btn-editar-recebimento-recorrente"
                                                        data-recorrencia-id="<?= (int)$regraRecebimento['id'] ?>"
                                                        data-data="<?= htmlspecialchars($regraRecebimento['primeiro_recebimento']) ?>"
                                                        data-descricao="<?= htmlspecialchars($regraRecebimento['descricao']) ?>"
                                                        data-recebido-de="<?= htmlspecialchars($regraRecebimento['recebido_de']) ?>"
                                                        data-valor="<?= number_format((float)$regraRecebimento['valor'], 2, ',', '.') ?>"
                                                        data-categoria="<?= (int)($regraRecebimento['categoria_id'] ?? 0) ?>"
                                                        data-fim="<?= $regraRecebimento['fim_mes'] ? htmlspecialchars(date('Y-m', strtotime($regraRecebimento['fim_mes']))) : '' ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalRecebimento"
                                                        title="Editar recorrência">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-danger btn-sm btn-excluir-financeiro"
                                                        data-acao="excluir_recorrencia_recebimento"
                                                        data-id="<?= (int)$regraRecebimento['id'] ?>"
                                                        data-descricao="<?= htmlspecialchars($regraRecebimento['descricao']) ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalExcluirFinanceiro"
                                                        title="Encerrar recorrência">
                                                        <i class="bi bi-stop-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-primary btn-sm btn-editar-recebimento"
                                                        data-id="<?= (int)$recebimento['id'] ?>"
                                                        data-data="<?= htmlspecialchars($recebimento['data_recebimento']) ?>"
                                                        data-descricao="<?= htmlspecialchars($recebimento['descricao']) ?>"
                                                        data-recebido-de="<?= htmlspecialchars($recebimento['recebido_de']) ?>"
                                                        data-valor="<?= number_format((float)$recebimento['valor'], 2, ',', '.') ?>"
                                                        data-categoria="<?= (int)($recebimento['categoria_id'] ?? 0) ?>"
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
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="d-none" id="semResultadoRecebimentos">
                                    <td colspan="6" class="financeiro-vazio">Nenhum recebimento corresponde aos filtros.</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4" class="text-end">Total previsto no mês</th>
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

                    <div class="financeiro-lista-filtros">
                        <div class="financeiro-campo-busca">
                            <i class="bi bi-search"></i>
                            <input
                                type="search"
                                class="form-control"
                                id="filtroBuscaContas"
                                placeholder="Buscar despesa..."
                                aria-label="Buscar conta a pagar">
                        </div>
                        <select class="form-select" id="filtroStatusContas" aria-label="Filtrar contas por situação">
                            <option value="">Todas as situações</option>
                            <option value="pendente">Pendente</option>
                            <option value="atrasado">Atrasado</option>
                            <option value="pago">Pago</option>
                        </select>
                        <select class="form-select" id="filtroCategoriaContas" aria-label="Filtrar contas por categoria">
                            <option value="">Todas as categorias</option>
                            <option value="sem_categoria">Sem categoria</option>
                            <?php foreach ($todasCategoriasDespesa as $categoria): ?>
                                <option value="<?= (int)$categoria['id'] ?>">
                                    <?= htmlspecialchars($categoria['nome']) ?><?= (int)$categoria['ativa'] === 1 ? '' : ' (desativada)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle financeiro-tabela" id="tabelaContas">
                            <thead>
                                <tr>
                                    <th>Despesa</th>
                                    <th>Vencimento</th>
                                    <th>Prazo</th>
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
                                        <td colspan="8" class="financeiro-vazio">Nenhuma conta cadastrada neste mês.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($contas as $conta):
                                    $paga = $conta['status'] === 'pago';
                                    $atrasada = !$paga && $conta['vencimento'] < date('Y-m-d');
                                    $diasParaVencer = (int)(new DateTimeImmutable('today'))
                                        ->diff(new DateTimeImmutable($conta['vencimento']))
                                        ->format('%r%a');
                                    $faturaCartao = !empty($conta['cartao_id']);
                                    $contaRecorrente = !empty($conta['recorrencia_id']);
                                    $recorrencia = $contaRecorrente
                                        ? ($recorrenciasPorId[(int)$conta['recorrencia_id']] ?? null)
                                        : null;
                                    $textoConta = $conta['descricao'];

                                    if (!empty($conta['parcela_numero']) && !empty($conta['parcelas_total'])) {
                                        $textoConta .= ' ' . (int)$conta['parcela_numero'] . '/' . (int)$conta['parcelas_total'];
                                    }
                                    $statusFiltroConta = $paga ? 'pago' : ($atrasada ? 'atrasado' : 'pendente');
                                    $categoriaConta = $categoriasPorId[(int)($conta['categoria_id'] ?? 0)] ?? null;
                                ?>
                                    <tr
                                        class="linha-filtro-conta"
                                        data-filtro="<?= htmlspecialchars($textoConta . ' ' . ($categoriaConta['nome'] ?? 'Sem categoria')) ?>"
                                        data-status="<?= $statusFiltroConta ?>"
                                        data-categoria="<?= $faturaCartao ? 'fatura' : ($categoriaConta ? (int)$categoriaConta['id'] : 'sem_categoria') ?>">
                                        <td>
                                            <?= htmlspecialchars($textoConta) ?>
                                            <?php if (!empty($conta['grupo_parcelamento'])): ?>
                                                <span class="badge bg-light text-dark border ms-1">Parcelada</span>
                                            <?php endif; ?>
                                            <?php if ($faturaCartao): ?>
                                                <span class="badge bg-primary ms-1">Cartão</span>
                                            <?php endif; ?>
                                            <?php if ($contaRecorrente): ?>
                                                <span class="badge bg-info text-dark ms-1">Recorrente</span>
                                            <?php endif; ?>
                                            <?php if (!$faturaCartao): ?>
                                                <span
                                                    class="badge ms-1 <?= $categoriaConta ? 'text-white' : 'bg-secondary' ?>"
                                                    <?= $categoriaConta ? 'style="background-color:' . htmlspecialchars($categoriaConta['cor']) . '"' : '' ?>>
                                                    <?= htmlspecialchars($categoriaConta['nome'] ?? 'Sem categoria') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= financeiroData($conta['vencimento']) ?></td>
                                        <td>
                                            <?php if ($paga): ?>
                                                <span class="text-muted">-</span>
                                            <?php elseif ($diasParaVencer < 0): ?>
                                                <span class="text-danger fw-semibold">
                                                    Vencida há <?= abs($diasParaVencer) ?>
                                                    <?= abs($diasParaVencer) === 1 ? 'dia' : 'dias' ?>
                                                </span>
                                            <?php elseif ($diasParaVencer === 0): ?>
                                                <span class="text-warning fw-semibold">Vence hoje</span>
                                            <?php else: ?>
                                                <span class="text-muted">
                                                    <?= $diasParaVencer === 1 ? 'Falta' : 'Faltam' ?>
                                                    <?= $diasParaVencer ?>
                                                    <?= $diasParaVencer === 1 ? 'dia' : 'dias' ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
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
                                                    <?php if ($faturaCartao): ?>
                                                        <a
                                                            href="financeiro_cartoes.php?cartao=<?= (int)$conta['cartao_id'] ?>"
                                                            class="btn btn-outline-secondary btn-sm"
                                                            title="Abrir cartão">
                                                            <i class="bi bi-credit-card"></i>
                                                        </a>
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
                                                <?php endif; ?>

                                                <?php if ($contaRecorrente && $recorrencia): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-primary btn-sm btn-editar-recorrencia"
                                                        data-recorrencia-id="<?= (int)$recorrencia['id'] ?>"
                                                        data-descricao="<?= htmlspecialchars($recorrencia['descricao']) ?>"
                                                        data-valor="<?= number_format((float)$recorrencia['valor'], 2, ',', '.') ?>"
                                                        data-vencimento="<?= htmlspecialchars($recorrencia['primeiro_vencimento']) ?>"
                                                        data-fim="<?= $recorrencia['fim_mes'] ? htmlspecialchars(date('Y-m', strtotime($recorrencia['fim_mes']))) : '' ?>"
                                                        data-categoria="<?= (int)($recorrencia['categoria_id'] ?? 0) ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalConta"
                                                        title="Editar recorrência">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-danger btn-sm btn-excluir-financeiro"
                                                        data-acao="excluir_recorrencia"
                                                        data-id="<?= (int)$recorrencia['id'] ?>"
                                                        data-descricao="<?= htmlspecialchars($recorrencia['descricao']) ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalExcluirFinanceiro"
                                                        title="Encerrar recorrência">
                                                        <i class="bi bi-stop-circle"></i>
                                                    </button>
                                                <?php elseif (!$faturaCartao): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-primary btn-sm btn-editar-conta"
                                                        data-id="<?= (int)$conta['id'] ?>"
                                                        data-descricao="<?= htmlspecialchars($conta['descricao']) ?>"
                                                        data-valor="<?= number_format((float)$conta['valor_previsto'], 2, ',', '.') ?>"
                                                        data-vencimento="<?= htmlspecialchars($conta['vencimento']) ?>"
                                                        data-categoria="<?= (int)($conta['categoria_id'] ?? 0) ?>"
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
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="d-none" id="semResultadoContas">
                                    <td colspan="8" class="financeiro-vazio">Nenhuma conta corresponde aos filtros.</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Totais</th>
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
                        <input type="hidden" name="recorrencia_recebimento_id" id="recebimentoRecorrenciaId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalRecebimento">Novo recebimento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3" id="grupoTipoRecebimento">
                                <label for="recebimentoTipo" class="form-label">Tipo de recebimento</label>
                                <select class="form-select" name="tipo_recebimento" id="recebimentoTipo">
                                    <option value="unico">Recebimento único</option>
                                    <option value="recorrente">Recorrente mensal</option>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-5 mb-3">
                                    <label for="recebimentoData" class="form-label">Data</label>
                                    <input type="date" class="form-control financeiro-calendario" name="data_recebimento" id="recebimentoData" required>
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
                                <label for="recebimentoCategoria" class="form-label">Categoria</label>
                                <select class="form-select" name="categoria_id" id="recebimentoCategoria" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($categoriasReceita as $categoria): ?>
                                        <option value="<?= (int)$categoria['id'] ?>">
                                            <?= htmlspecialchars($categoria['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione a categoria.</div>
                            </div>
                            <div class="mb-3">
                                <label for="recebimentoOrigem" class="form-label">Recebido de</label>
                                <input type="text" class="form-control" name="recebido_de" id="recebimentoOrigem">
                            </div>
                            <div class="d-none" id="camposRecorrenciaRecebimento">
                                <label for="recebimentoFimRecorrencia" class="form-label">Último mês (opcional)</label>
                                <input
                                    type="month"
                                    class="form-control financeiro-calendario"
                                    name="fim_recorrencia_recebimento"
                                    id="recebimentoFimRecorrencia">
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
                        <input type="hidden" name="recorrencia_id" id="contaRecorrenciaId">
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
                                    <option value="recorrente">Recorrente mensal</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="contaDescricao" class="form-label">Despesa</label>
                                <input type="text" class="form-control" name="descricao" id="contaDescricao" required>
                                <div class="invalid-feedback">Informe a despesa.</div>
                            </div>
                            <div class="mb-3">
                                <label for="contaCategoria" class="form-label">Categoria</label>
                                <select class="form-select" name="categoria_id" id="contaCategoria" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($categoriasDespesa as $categoria): ?>
                                        <option value="<?= (int)$categoria['id'] ?>">
                                            <?= htmlspecialchars($categoria['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione a categoria.</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contaValor" class="form-label">Valor previsto</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor_previsto" id="contaValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o valor.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contaVencimento" class="form-label">Vencimento</label>
                                    <input type="date" class="form-control financeiro-calendario" name="vencimento" id="contaVencimento" required>
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
                            <div class="d-none" id="camposRecorrenciaConta">
                                <label for="contaFimRecorrencia" class="form-label">Último mês (opcional)</label>
                                <input type="month" class="form-control financeiro-calendario" name="fim_recorrencia" id="contaFimRecorrencia">
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
                                    <input type="date" class="form-control financeiro-calendario" name="data_pagamento" id="pagarContaData" value="<?= date('Y-m-d') ?>" required>
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
                        <h5 class="modal-title" id="tituloExcluirFinanceiro">Excluir lançamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="descricaoExcluirFinanceiro"></strong>?</p>
                        <small class="text-danger" id="avisoExcluirFinanceiro">Essa ação não poderá ser desfeita.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" id="acaoExcluirFinanceiro">
                            <input type="hidden" name="id" id="idExcluirFinanceiro">
                            <button type="submit" class="btn btn-danger" id="btnConfirmarExcluirFinanceiro">
                                <i class="bi bi-trash"></i> <span>Sim, excluir</span>
                            </button>
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
            const mesSelecionado = <?= json_encode($mes) ?>;
            const dataHoje = <?= json_encode(date('Y-m-d')) ?>;

            function normalizarTextoFiltro(texto) {
                return String(texto || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim();
            }

            function configurarFiltroFinanceiro(configuracao) {
                const campoBusca = document.getElementById(configuracao.busca);
                const campoStatus = document.getElementById(configuracao.status);
                const campoCategoria = document.getElementById(configuracao.categoria);
                const linhas = Array.from(document.querySelectorAll(configuracao.linhas));
                const semResultado = document.getElementById(configuracao.vazio);

                if (!campoBusca || !campoStatus || !campoCategoria || linhas.length === 0 || !semResultado) {
                    return;
                }

                function filtrar() {
                    const busca = normalizarTextoFiltro(campoBusca.value);
                    const status = campoStatus.value;
                    const categoria = campoCategoria.value;
                    let visiveis = 0;

                    linhas.forEach(function(linha) {
                        const correspondeBusca = normalizarTextoFiltro(linha.dataset.filtro).includes(busca);
                        const correspondeStatus = status === '' || linha.dataset.status === status;
                        const correspondeCategoria = categoria === '' || linha.dataset.categoria === categoria;
                        const mostrar = correspondeBusca && correspondeStatus && correspondeCategoria;

                        linha.classList.toggle('d-none', !mostrar);
                        visiveis += mostrar ? 1 : 0;
                    });

                    semResultado.classList.toggle('d-none', visiveis > 0);
                }

                campoBusca.addEventListener('input', filtrar);
                campoStatus.addEventListener('change', filtrar);
                campoCategoria.addEventListener('change', filtrar);
            }

            configurarFiltroFinanceiro({
                busca: 'filtroBuscaRecebimentos',
                status: 'filtroStatusRecebimentos',
                categoria: 'filtroCategoriaRecebimentos',
                linhas: '.linha-filtro-recebimento',
                vazio: 'semResultadoRecebimentos'
            });

            configurarFiltroFinanceiro({
                busca: 'filtroBuscaContas',
                status: 'filtroStatusContas',
                categoria: 'filtroCategoriaContas',
                linhas: '.linha-filtro-conta',
                vazio: 'semResultadoContas'
            });

            document.getElementById('mesFinanceiro').addEventListener('change', function() {
                document.getElementById('formMesFinanceiro').submit();
            });

            document.getElementById('btnNovoRecebimento').addEventListener('click', function() {
                document.getElementById('tituloModalRecebimento').textContent = 'Novo recebimento';
                document.getElementById('recebimentoId').value = '';
                document.getElementById('recebimentoRecorrenciaId').value = '';
                document.getElementById('grupoTipoRecebimento').classList.remove('d-none');
                document.getElementById('recebimentoTipo').value = 'unico';
                document.getElementById('recebimentoData').value = mesSelecionado + '-01';
                document.getElementById('recebimentoDescricao').value = '';
                document.getElementById('recebimentoOrigem').value = '';
                document.getElementById('recebimentoValor').value = '';
                document.getElementById('recebimentoCategoria').value = '';
                document.getElementById('recebimentoFimRecorrencia').value = '';
                atualizarCamposRecorrenciaRecebimento();
            });

            document.querySelectorAll('.btn-editar-recebimento').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalRecebimento').textContent = 'Editar recebimento';
                    document.getElementById('recebimentoId').value = this.dataset.id;
                    document.getElementById('recebimentoRecorrenciaId').value = '';
                    document.getElementById('recebimentoTipo').value = 'unico';
                    document.getElementById('grupoTipoRecebimento').classList.add('d-none');
                    document.getElementById('camposRecorrenciaRecebimento').classList.add('d-none');
                    document.getElementById('recebimentoData').value = this.dataset.data;
                    document.getElementById('recebimentoDescricao').value = this.dataset.descricao;
                    document.getElementById('recebimentoOrigem').value = this.dataset.recebidoDe;
                    document.getElementById('recebimentoValor').value = this.dataset.valor;
                    document.getElementById('recebimentoCategoria').value = this.dataset.categoria || '';
                    document.getElementById('recebimentoFimRecorrencia').value = '';
                });
            });

            document.querySelectorAll('.btn-editar-recebimento-recorrente').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalRecebimento').textContent = 'Editar recebimento recorrente';
                    document.getElementById('recebimentoId').value = '';
                    document.getElementById('recebimentoRecorrenciaId').value = this.dataset.recorrenciaId;
                    document.getElementById('grupoTipoRecebimento').classList.remove('d-none');
                    document.getElementById('recebimentoTipo').value = 'recorrente';
                    document.getElementById('recebimentoData').value = this.dataset.data;
                    document.getElementById('recebimentoDescricao').value = this.dataset.descricao;
                    document.getElementById('recebimentoOrigem').value = this.dataset.recebidoDe;
                    document.getElementById('recebimentoValor').value = this.dataset.valor;
                    document.getElementById('recebimentoCategoria').value = this.dataset.categoria || '';
                    document.getElementById('recebimentoFimRecorrencia').value = this.dataset.fim;
                    atualizarCamposRecorrenciaRecebimento();
                });
            });

            function atualizarCamposRecorrenciaRecebimento() {
                const recorrente = document.getElementById('recebimentoTipo').value === 'recorrente';
                document.getElementById('camposRecorrenciaRecebimento').classList.toggle('d-none', !recorrente);
            }

            document.getElementById('recebimentoTipo').addEventListener('change', atualizarCamposRecorrenciaRecebimento);

            document.getElementById('btnNovaConta').addEventListener('click', function() {
                document.getElementById('tituloModalConta').textContent = 'Nova conta';
                document.getElementById('contaId').value = '';
                document.getElementById('contaRecorrenciaId').value = '';
                document.getElementById('grupoTipoConta').classList.remove('d-none');
                document.getElementById('contaTipoLancamento').value = 'unica';
                document.getElementById('contaDescricao').value = '';
                document.getElementById('contaValor').value = '';
                document.getElementById('contaCategoria').value = '';
                document.getElementById('contaVencimento').value = mesSelecionado + '-01';
                document.getElementById('contaParcelaInicial').value = '1';
                document.getElementById('contaParcelasTotal').value = '';
                document.getElementById('contaFimRecorrencia').value = '';
                atualizarCamposParcelamentoConta();
            });

            document.querySelectorAll('.btn-editar-conta').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalConta').textContent = 'Editar conta';
                    document.getElementById('contaId').value = this.dataset.id;
                    document.getElementById('contaRecorrenciaId').value = '';
                    document.getElementById('contaTipoLancamento').value = 'unica';
                    document.getElementById('grupoTipoConta').classList.add('d-none');
                    document.getElementById('camposParcelamentoConta').classList.add('d-none');
                    document.getElementById('camposRecorrenciaConta').classList.add('d-none');
                    document.getElementById('contaDescricao').value = this.dataset.descricao;
                    document.getElementById('contaValor').value = this.dataset.valor;
                    document.getElementById('contaCategoria').value = this.dataset.categoria || '';
                    document.getElementById('contaVencimento').value = this.dataset.vencimento;
                    document.getElementById('contaFimRecorrencia').value = '';
                });
            });

            document.querySelectorAll('.btn-editar-recorrencia').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalConta').textContent = 'Editar conta recorrente';
                    document.getElementById('contaId').value = '';
                    document.getElementById('contaRecorrenciaId').value = this.dataset.recorrenciaId;
                    document.getElementById('grupoTipoConta').classList.remove('d-none');
                    document.getElementById('contaTipoLancamento').value = 'recorrente';
                    document.getElementById('contaDescricao').value = this.dataset.descricao;
                    document.getElementById('contaValor').value = this.dataset.valor;
                    document.getElementById('contaCategoria').value = this.dataset.categoria || '';
                    document.getElementById('contaVencimento').value = this.dataset.vencimento;
                    document.getElementById('contaFimRecorrencia').value = this.dataset.fim;
                    atualizarCamposParcelamentoConta();
                });
            });

            function atualizarCamposParcelamentoConta() {
                const tipo = document.getElementById('contaTipoLancamento').value;
                const parcelada = tipo === 'parcelada';
                const recorrente = tipo === 'recorrente';
                const campos = document.getElementById('camposParcelamentoConta');
                const camposRecorrencia = document.getElementById('camposRecorrenciaConta');
                const parcelaInicial = document.getElementById('contaParcelaInicial');
                const parcelasTotal = document.getElementById('contaParcelasTotal');

                campos.classList.toggle('d-none', !parcelada);
                camposRecorrencia.classList.toggle('d-none', !recorrente);
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
                    const recorrenciaConta = this.dataset.acao === 'excluir_recorrencia';
                    const recorrenciaRecebimento = this.dataset.acao === 'excluir_recorrencia_recebimento';
                    const recorrencia = recorrenciaConta || recorrenciaRecebimento;
                    document.getElementById('acaoExcluirFinanceiro').value = this.dataset.acao;
                    document.getElementById('idExcluirFinanceiro').value = this.dataset.id;
                    document.getElementById('descricaoExcluirFinanceiro').textContent = this.dataset.descricao;
                    document.getElementById('tituloExcluirFinanceiro').textContent = recorrencia ?
                        (recorrenciaRecebimento ? 'Encerrar recebimento recorrente' : 'Encerrar conta recorrente') :
                        'Excluir lançamento';
                    document.getElementById('avisoExcluirFinanceiro').textContent = recorrencia ?
                        (recorrenciaRecebimento ?
                            'Os recebimentos dos meses anteriores e do mês atual serão mantidos no histórico.' :
                            'As contas pendentes dessa recorrência serão removidas. As já pagas serão mantidas no histórico.') :
                        'Essa ação não poderá ser desfeita.';
                    document.querySelector('#btnConfirmarExcluirFinanceiro span').textContent = recorrencia ?
                        'Sim, encerrar' :
                        'Sim, excluir';
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