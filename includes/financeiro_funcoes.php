<?php

function financeiroTabelasDisponiveis(PDO $pdo, array $tabelas): bool
{
    if ($tabelas === []) {
        return true;
    }

    $marcadores = implode(',', array_fill(0, count($tabelas), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ({$marcadores})
    ");
    $stmt->execute($tabelas);

    return (int)$stmt->fetchColumn() === count($tabelas);
}

function financeiroValorEntrada(string $valor): float
{
    $valor = trim(str_replace(['R$', ' '], '', $valor));

    if ($valor === '') {
        return 0.0;
    }

    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') > 1) {
        $valor = str_replace('.', '', $valor);
    } elseif (str_contains($valor, '.')) {
        $casasDepoisDoPonto = strlen($valor) - strrpos($valor, '.') - 1;

        if ($casasDepoisDoPonto === 3) {
            $valor = str_replace('.', '', $valor);
        }
    }

    return is_numeric($valor) ? round((float)$valor, 2) : 0.0;
}

function financeiroValorValido(string $valor): bool
{
    $valor = trim(str_replace(['R$', ' '], '', $valor));

    if ($valor === '') {
        return false;
    }

    return (bool)preg_match(
        '/^(?:(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?|\d+(?:\.\d{1,2})?)$/',
        $valor
    );
}

function financeiroMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function financeiroData(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function financeiroMesValido(?string $mes): string
{
    if (is_string($mes) && preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $data = DateTime::createFromFormat('!Y-m', $mes);

        if ($data && $data->format('Y-m') === $mes) {
            return $mes;
        }
    }

    return date('Y-m');
}

function financeiroSomarMeses(string $data, int $meses): string
{
    $origem = new DateTime($data);
    $dia = (int)$origem->format('d');
    $destino = new DateTime($origem->format('Y-m-01'));
    $destino->modify(($meses >= 0 ? '+' : '') . $meses . ' months');
    $ultimoDia = (int)$destino->format('t');
    $destino->setDate(
        (int)$destino->format('Y'),
        (int)$destino->format('m'),
        min($dia, $ultimoDia)
    );

    return $destino->format('Y-m-d');
}

function financeiroColunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    static $cache = [];
    $tabelasPermitidas = [
        'financeiro_recebimentos',
        'financeiro_recebimentos_recorrentes',
        'financeiro_contas',
        'financeiro_contas_recorrentes',
        'financeiro_cartoes',
        'financeiro_cartao_lancamentos',
        'financeiro_cartao_recorrencias',
    ];

    if (!in_array($tabela, $tabelasPermitidas, true)) {
        return false;
    }

    $chave = $tabela . '.' . $coluna;

    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$tabela} LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$chave] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$chave] = false;
    }

    return $cache[$chave];
}

function financeiroCategoriasDisponiveis(PDO $pdo): bool
{
    return financeiroTabelasDisponiveis($pdo, ['financeiro_categorias'])
        && financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_recebimentos_recorrentes', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_contas', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_contas_recorrentes', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'categoria_id');
}

function financeiroGarantirCategoriasPadrao(PDO $pdo, int $usuarioId): void
{
    if ($usuarioId <= 0 || !financeiroTabelasDisponiveis($pdo, ['financeiro_categorias'])) {
        return;
    }

    $categorias = [
        ['Salário', 'receita', '#198754'],
        ['Serviços', 'receita', '#0d6efd'],
        ['Aluguel recebido', 'receita', '#14b8a6'],
        ['Outras receitas', 'receita', '#64748b'],
        ['Alimentação', 'despesa', '#ef4444'],
        ['Moradia', 'despesa', '#f97316'],
        ['Saúde', 'despesa', '#ec4899'],
        ['Transporte', 'despesa', '#eab308'],
        ['Educação', 'despesa', '#3b82f6'],
        ['Lazer', 'despesa', '#8b5cf6'],
        ['Assinaturas', 'despesa', '#6366f1'],
        ['Dívidas', 'despesa', '#b91c1c'],
        ['Impostos', 'despesa', '#475569'],
        ['Compras', 'despesa', '#06b6d4'],
        ['Outras despesas', 'despesa', '#94a3b8'],
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM financeiro_categorias
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuarioId]);

        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO financeiro_categorias
                (usuario_id, nome, tipo, cor, ativa)
            VALUES (?, ?, ?, ?, 1)
        ");

        foreach ($categorias as [$nome, $tipo, $cor]) {
            $stmt->execute([$usuarioId, $nome, $tipo, $cor]);
        }
    } catch (Throwable $e) {
        // A ausência das categorias não deve impedir a abertura do financeiro.
    }
}

function financeiroListarCategorias(
    PDO $pdo,
    int $usuarioId,
    string $tipo,
    bool $somenteAtivas = true
): array {
    if (
        $usuarioId <= 0
        || !in_array($tipo, ['receita', 'despesa'], true)
        || !financeiroTabelasDisponiveis($pdo, ['financeiro_categorias'])
    ) {
        return [];
    }

    try {
        $filtroAtivas = $somenteAtivas ? 'AND ativa = 1' : '';
        $stmt = $pdo->prepare("
            SELECT id, nome, tipo, cor, ativa
            FROM financeiro_categorias
            WHERE usuario_id = ?
              AND tipo = ?
              {$filtroAtivas}
            ORDER BY ativa DESC, nome
        ");
        $stmt->execute([$usuarioId, $tipo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function financeiroCategoriaValida(
    PDO $pdo,
    int $usuarioId,
    int $categoriaId,
    string $tipo
): bool {
    if ($categoriaId <= 0 || !in_array($tipo, ['receita', 'despesa'], true)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM financeiro_categorias
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
              AND ativa = 1
        ");
        $stmt->execute([$categoriaId, $usuarioId, $tipo]);

        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function financeiroVencimentoFatura(string $competencia, int $dia): string
{
    $inicioMes = new DateTime($competencia . '-01');
    $dia = max(1, min($dia, (int)$inicioMes->format('t')));
    $inicioMes->setDate(
        (int)$inicioMes->format('Y'),
        (int)$inicioMes->format('m'),
        $dia
    );

    return $inicioMes->format('Y-m-d');
}

function financeiroSincronizarCartaoRecorrencias(PDO $pdo, int $usuarioId, string $mes): void
{
    $mes = financeiroMesValido($mes);

    if (
        !financeiroTabelasDisponiveis(
            $pdo,
            ['financeiro_cartoes', 'financeiro_cartao_lancamentos', 'financeiro_cartao_recorrencias']
        )
        || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura')
        || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'recorrencia_id')
    ) {
        return;
    }

    $inicioMes = $mes . '-01';
    $fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));

    try {
        $stmt = $pdo->prepare("
            SELECT r.*
            FROM financeiro_cartao_recorrencias r
            INNER JOIN financeiro_cartoes c
                ON c.id = r.cartao_id
               AND c.usuario_id = r.usuario_id
            WHERE r.usuario_id = ?
              AND r.ativa = 1
              AND c.ativo = 1
              AND r.primeira_fatura < ?
              AND (r.fim_mes IS NULL OR r.fim_mes >= ?)
        ");
        $stmt->execute([$usuarioId, $fimMes, $inicioMes]);
        $recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtExiste = $pdo->prepare("
            SELECT id
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND recorrencia_id = ?
              AND competencia_fatura = ?
            LIMIT 1
        ");
        $stmtInserir = $pdo->prepare("
            INSERT INTO financeiro_cartao_lancamentos (
                usuario_id,
                cartao_id,
                data_compra,
                competencia_fatura,
                descricao,
                valor,
                status,
                categoria_id,
                recorrencia_id,
                grupo_parcelamento,
                parcela_numero,
                parcelas_total
            )
            VALUES (?, ?, ?, ?, ?, ?, 'aberto', ?, ?, NULL, NULL, NULL)
        ");

        foreach ($recorrencias as $recorrencia) {
            $competenciaFatura = $mes . '-01';
            $stmtExiste->execute([$usuarioId, $recorrencia['id'], $competenciaFatura]);

            if ($stmtExiste->fetchColumn()) {
                continue;
            }

            $diaCompra = (int)date('d', strtotime($recorrencia['data_compra']));
            $dataCompra = financeiroVencimentoFatura($mes, $diaCompra);
            $stmtInserir->execute([
                $usuarioId,
                $recorrencia['cartao_id'],
                $dataCompra,
                $competenciaFatura,
                $recorrencia['descricao'],
                $recorrencia['valor'],
                $recorrencia['categoria_id'] ?? null,
                $recorrencia['id'],
            ]);
        }
    } catch (Throwable $e) {
        // A recorrência do cartão não deve impedir a abertura do financeiro.
    }
}

function financeiroSincronizarCartaoRecorrenciasAteMesAtual(PDO $pdo, int $usuarioId): void
{
    if (
        !financeiroTabelasDisponiveis(
            $pdo,
            ['financeiro_cartoes', 'financeiro_cartao_lancamentos', 'financeiro_cartao_recorrencias']
        )
        || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura')
        || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'recorrencia_id')
    ) {
        return;
    }

    $mesAtual = date('Y-m');
    $inicioMesAtual = $mesAtual . '-01';
    $proximoMes = date('Y-m-d', strtotime($inicioMesAtual . ' +1 month'));

    try {
        $stmtLimparFuturas = $pdo->prepare("
            DELETE FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND recorrencia_id IS NOT NULL
              AND status = 'aberto'
              AND competencia_fatura >= ?
        ");
        $stmtLimparFuturas->execute([$usuarioId, $proximoMes]);

        $stmtPrimeira = $pdo->prepare("
            SELECT MIN(primeira_fatura)
            FROM financeiro_cartao_recorrencias
            WHERE usuario_id = ?
              AND ativa = 1
              AND primeira_fatura <= ?
        ");
        $stmtPrimeira->execute([$usuarioId, $inicioMesAtual]);
        $primeiraFatura = $stmtPrimeira->fetchColumn();

        if (!$primeiraFatura) {
            return;
        }

        $cursor = new DateTimeImmutable(date('Y-m-01', strtotime($primeiraFatura)));
        $limite = new DateTimeImmutable($inicioMesAtual);
        $contador = 0;

        while ($cursor <= $limite && $contador < 240) {
            financeiroSincronizarCartaoRecorrencias($pdo, $usuarioId, $cursor->format('Y-m'));
            $cursor = $cursor->modify('+1 month');
            $contador++;
        }
    } catch (Throwable $e) {
        // A recorrência do cartão não deve impedir a abertura do financeiro.
    }
}

function financeiroSincronizarFaturasCartoes(PDO $pdo, int $usuarioId): void
{
    if (
        !financeiroTabelasDisponiveis(
            $pdo,
            ['financeiro_contas', 'financeiro_cartoes', 'financeiro_cartao_lancamentos']
        )
        || !financeiroColunaExiste($pdo, 'financeiro_contas', 'cartao_id')
        || !financeiroColunaExiste($pdo, 'financeiro_contas', 'competencia_cartao')
    ) {
        return;
    }

    $temCompetenciaFatura = financeiroColunaExiste(
        $pdo,
        'financeiro_cartao_lancamentos',
        'competencia_fatura'
    );
    $expressaoCompetencia = $temCompetenciaFatura
        ? "COALESCE(DATE_FORMAT(competencia_fatura, '%Y-%m'), DATE_FORMAT(data_compra, '%Y-%m'))"
        : "DATE_FORMAT(data_compra, '%Y-%m')";

    try {
        $stmtCartoes = $pdo->prepare("
            SELECT id, nome, dia_vencimento
            FROM financeiro_cartoes
            WHERE usuario_id = ?
              AND dia_vencimento IS NOT NULL
        ");
        $stmtCartoes->execute([$usuarioId]);
        $cartoes = $stmtCartoes->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("
            DELETE fc
            FROM financeiro_contas fc
            LEFT JOIN financeiro_cartoes c
                ON c.id = fc.cartao_id
               AND c.usuario_id = fc.usuario_id
            WHERE fc.usuario_id = ?
              AND fc.cartao_id IS NOT NULL
              AND (c.id IS NULL OR c.dia_vencimento IS NULL)
        ")->execute([$usuarioId]);

        $stmtFaturas = $pdo->prepare("
            SELECT
                {$expressaoCompetencia} AS competencia,
                SUM(valor) AS valor_total,
                SUM(CASE WHEN status = 'aberto' THEN 1 ELSE 0 END) AS parcelas_abertas,
                MAX(data_pagamento) AS data_pagamento
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND cartao_id = ?
            GROUP BY {$expressaoCompetencia}
            ORDER BY competencia
        ");
        $stmtBuscarConta = $pdo->prepare("
            SELECT id
            FROM financeiro_contas
            WHERE usuario_id = ?
              AND cartao_id = ?
              AND competencia_cartao = ?
            ORDER BY id
            LIMIT 1
        ");
        $stmtInserirConta = $pdo->prepare("
            INSERT INTO financeiro_contas (
                usuario_id,
                descricao,
                valor_previsto,
                vencimento,
                status,
                valor_pago,
                data_pagamento,
                cartao_id,
                competencia_cartao
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtAtualizarConta = $pdo->prepare("
            UPDATE financeiro_contas
            SET descricao = ?,
                valor_previsto = ?,
                vencimento = ?,
                status = ?,
                valor_pago = ?,
                data_pagamento = ?
            WHERE id = ? AND usuario_id = ?
        ");
        $stmtExcluirDuplicadas = $pdo->prepare("
            DELETE FROM financeiro_contas
            WHERE usuario_id = ?
              AND cartao_id = ?
              AND competencia_cartao = ?
              AND id <> ?
        ");
        $stmtExcluirOrfas = $pdo->prepare("
            DELETE FROM financeiro_contas
            WHERE usuario_id = ?
              AND cartao_id = ?
              AND competencia_cartao IS NOT NULL
        ");

        foreach ($cartoes as $cartao) {
            $stmtFaturas->execute([$usuarioId, $cartao['id']]);
            $faturas = $stmtFaturas->fetchAll(PDO::FETCH_ASSOC);
            $competencias = [];

            foreach ($faturas as $fatura) {
                $competencia = $fatura['competencia'];
                $competencias[] = $competencia;
                $valorTotal = (float)$fatura['valor_total'];
                $paga = (int)$fatura['parcelas_abertas'] === 0;
                $descricao = 'Fatura ' . $cartao['nome'];
                $vencimento = financeiroVencimentoFatura(
                    $competencia,
                    (int)$cartao['dia_vencimento']
                );
                $status = $paga ? 'pago' : 'pendente';
                $valorPago = $paga ? $valorTotal : null;
                $dataPagamento = $paga ? $fatura['data_pagamento'] : null;

                $stmtBuscarConta->execute([$usuarioId, $cartao['id'], $competencia]);
                $contaId = (int)$stmtBuscarConta->fetchColumn();

                if ($contaId > 0) {
                    $stmtAtualizarConta->execute([
                        $descricao,
                        $valorTotal,
                        $vencimento,
                        $status,
                        $valorPago,
                        $dataPagamento,
                        $contaId,
                        $usuarioId,
                    ]);
                } else {
                    $stmtInserirConta->execute([
                        $usuarioId,
                        $descricao,
                        $valorTotal,
                        $vencimento,
                        $status,
                        $valorPago,
                        $dataPagamento,
                        $cartao['id'],
                        $competencia,
                    ]);
                    $contaId = (int)$pdo->lastInsertId();
                }

                $stmtExcluirDuplicadas->execute([
                    $usuarioId,
                    $cartao['id'],
                    $competencia,
                    $contaId,
                ]);
            }

            if ($competencias === []) {
                $stmtExcluirOrfas->execute([$usuarioId, $cartao['id']]);
                continue;
            }

            $marcadores = implode(',', array_fill(0, count($competencias), '?'));
            $stmtExcluir = $pdo->prepare("
                DELETE FROM financeiro_contas
                WHERE usuario_id = ?
                  AND cartao_id = ?
                  AND competencia_cartao IS NOT NULL
                  AND competencia_cartao NOT IN ({$marcadores})
            ");
            $stmtExcluir->execute(array_merge([$usuarioId, $cartao['id']], $competencias));
        }
    } catch (Throwable $e) {
        // A sincronização automática não deve impedir a abertura do financeiro.
    }
}

function financeiroSincronizarContasRecorrentes(PDO $pdo, int $usuarioId, string $mes): void
{
    $mes = financeiroMesValido($mes);

    if (
        !financeiroTabelasDisponiveis(
            $pdo,
            ['financeiro_contas', 'financeiro_contas_recorrentes']
        )
        || !financeiroColunaExiste($pdo, 'financeiro_contas', 'recorrencia_id')
        || !financeiroColunaExiste($pdo, 'financeiro_contas', 'competencia_recorrencia')
    ) {
        return;
    }

    $inicioMes = $mes . '-01';
    $fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
    $temCategoria = financeiroColunaExiste($pdo, 'financeiro_contas', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_contas_recorrentes', 'categoria_id');
    $colunaCategoria = $temCategoria ? 'categoria_id,' : '';
    $valorCategoria = $temCategoria ? '?, ' : '';
    $atualizarCategoria = $temCategoria
        ? ",
                categoria_id = IF(
                    financeiro_contas.status = 'pendente'
                        OR financeiro_contas.categoria_id IS NULL,
                    VALUES(categoria_id),
                    financeiro_contas.categoria_id
                )"
        : '';

    try {
        if ($temCategoria) {
            $stmtCategoria = $pdo->prepare("
                UPDATE financeiro_contas c
                INNER JOIN financeiro_contas_recorrentes r
                    ON r.id = c.recorrencia_id
                   AND r.usuario_id = c.usuario_id
                SET c.categoria_id = r.categoria_id
                WHERE c.usuario_id = ?
                  AND c.categoria_id IS NULL
                  AND r.categoria_id IS NOT NULL
            ");
            $stmtCategoria->execute([$usuarioId]);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_contas_recorrentes
            WHERE usuario_id = ?
              AND ativa = 1
              AND primeiro_vencimento < ?
              AND (fim_mes IS NULL OR fim_mes >= ?)
        ");
        $stmt->execute([$usuarioId, $fimMes, $inicioMes]);
        $recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmtSalvar = $pdo->prepare("
            INSERT INTO financeiro_contas (
                usuario_id,
                descricao,
                valor_previsto,
                vencimento,
                status,
                {$colunaCategoria}
                recorrencia_id,
                competencia_recorrencia
            )
            VALUES (?, ?, ?, ?, 'pendente', {$valorCategoria}?, ?)
            ON DUPLICATE KEY UPDATE
                descricao = IF(
                    financeiro_contas.status = 'pendente',
                    VALUES(descricao),
                    financeiro_contas.descricao
                ),
                valor_previsto = IF(
                    financeiro_contas.status = 'pendente',
                    VALUES(valor_previsto),
                    financeiro_contas.valor_previsto
                ),
                vencimento = IF(
                    financeiro_contas.status = 'pendente',
                    VALUES(vencimento),
                    financeiro_contas.vencimento
                )
                {$atualizarCategoria}
        ");

        foreach ($recorrencias as $recorrencia) {
            $dia = (int)date('d', strtotime($recorrencia['primeiro_vencimento']));
            $valores = [
                $usuarioId,
                $recorrencia['descricao'],
                $recorrencia['valor'],
                financeiroVencimentoFatura($mes, $dia),
            ];

            if ($temCategoria) {
                $valores[] = $recorrencia['categoria_id'] ?? null;
            }

            $valores[] = $recorrencia['id'];
            $valores[] = $mes;
            $stmtSalvar->execute($valores);
        }
    } catch (Throwable $e) {
        // A recorrência nunca deve impedir a abertura do financeiro.
    }
}

function financeiroSincronizarRecebimentosRecorrentes(PDO $pdo, int $usuarioId, string $mes): void
{
    $mes = financeiroMesValido($mes);

    if (
        !financeiroTabelasDisponiveis(
            $pdo,
            ['financeiro_recebimentos', 'financeiro_recebimentos_recorrentes']
        )
        || !financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'recorrencia_id')
        || !financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'competencia_recorrencia')
    ) {
        return;
    }

    $inicioMes = $mes . '-01';
    $fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
    $temCategoria = financeiroColunaExiste($pdo, 'financeiro_recebimentos', 'categoria_id')
        && financeiroColunaExiste($pdo, 'financeiro_recebimentos_recorrentes', 'categoria_id');
    $colunaCategoria = $temCategoria ? 'categoria_id,' : '';
    $valorCategoria = $temCategoria ? '?, ' : '';
    $atualizarCategoria = $temCategoria
        ? ', categoria_id = VALUES(categoria_id)'
        : '';

    try {
        if ($temCategoria) {
            $stmtCategoria = $pdo->prepare("
                UPDATE financeiro_recebimentos r
                INNER JOIN financeiro_recebimentos_recorrentes rr
                    ON rr.id = r.recorrencia_id
                   AND rr.usuario_id = r.usuario_id
                SET r.categoria_id = rr.categoria_id
                WHERE r.usuario_id = ?
                  AND r.categoria_id IS NULL
                  AND rr.categoria_id IS NOT NULL
            ");
            $stmtCategoria->execute([$usuarioId]);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_recebimentos_recorrentes
            WHERE usuario_id = ?
              AND ativa = 1
              AND primeiro_recebimento < ?
              AND (fim_mes IS NULL OR fim_mes >= ?)
        ");
        $stmt->execute([$usuarioId, $fimMes, $inicioMes]);
        $recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmtSalvar = $pdo->prepare("
            INSERT INTO financeiro_recebimentos (
                usuario_id,
                data_recebimento,
                descricao,
                recebido_de,
                valor,
                {$colunaCategoria}
                recorrencia_id,
                competencia_recorrencia
            )
            VALUES (?, ?, ?, ?, ?, {$valorCategoria}?, ?)
            ON DUPLICATE KEY UPDATE
                data_recebimento = VALUES(data_recebimento),
                descricao = VALUES(descricao),
                recebido_de = VALUES(recebido_de),
                valor = VALUES(valor)
                {$atualizarCategoria}
        ");

        foreach ($recorrencias as $recorrencia) {
            $dia = (int)date('d', strtotime($recorrencia['primeiro_recebimento']));
            $valores = [
                $usuarioId,
                financeiroVencimentoFatura($mes, $dia),
                $recorrencia['descricao'],
                $recorrencia['recebido_de'],
                $recorrencia['valor'],
            ];

            if ($temCategoria) {
                $valores[] = $recorrencia['categoria_id'] ?? null;
            }

            $valores[] = $recorrencia['id'];
            $valores[] = $mes;
            $stmtSalvar->execute($valores);
        }
    } catch (Throwable $e) {
        // A sincronização automática não deve impedir a abertura do financeiro.
    }
}

function financeiroListarAlertasVencimento(
    PDO $pdo,
    int $usuarioId,
    int $diasAntecedencia = 10
): array {
    if (
        $usuarioId <= 0
        || !financeiroTabelasDisponiveis($pdo, ['financeiro_contas'])
    ) {
        return [];
    }

    $diasAntecedencia = max(0, min(90, $diasAntecedencia));
    $hoje = new DateTimeImmutable('today');
    $limite = $hoje->modify('+' . $diasAntecedencia . ' days')->format('Y-m-d');

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                descricao,
                valor_previsto,
                vencimento,
                cartao_id,
                competencia_cartao
            FROM financeiro_contas
            WHERE usuario_id = ?
              AND status = 'pendente'
              AND valor_previsto > 0
              AND vencimento <= ?
            ORDER BY vencimento, descricao
        ");
        $stmt->execute([$usuarioId, $limite]);
        $alertas = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $conta) {
            $vencimento = new DateTimeImmutable($conta['vencimento']);
            $dias = (int)$hoje->diff($vencimento)->format('%r%a');
            $fatura = !empty($conta['cartao_id']);

            if ($dias < 0) {
                $prazo = 'Vencida há ' . abs($dias)
                    . (abs($dias) === 1 ? ' dia' : ' dias');
                $classe = 'danger';
            } elseif ($dias === 0) {
                $prazo = 'Vence hoje';
                $classe = 'danger';
            } else {
                $prazo = 'Vence em ' . $dias . ($dias === 1 ? ' dia' : ' dias');
                $classe = $dias <= 3 ? 'warning' : 'primary';
            }

            $mesConta = date('Y-m', strtotime($conta['vencimento']));
            $url = $fatura
                ? 'financeiro_cartoes.php?'
                . http_build_query([
                    'cartao' => (int)$conta['cartao_id'],
                    'mes' => $conta['competencia_cartao'] ?: $mesConta,
                ])
                : 'financeiro.php?' . http_build_query(['mes' => $mesConta]);

            $alertas[] = [
                'id' => (int)$conta['id'],
                'descricao' => $conta['descricao'],
                'valor' => (float)$conta['valor_previsto'],
                'vencimento' => $conta['vencimento'],
                'prazo' => $prazo,
                'classe' => $classe,
                'tipo' => $fatura ? 'Fatura' : 'Conta',
                'url' => $url,
            ];
        }

        return $alertas;
    } catch (Throwable $e) {
        return [];
    }
}

function financeiroToken(): string
{
    if (empty($_SESSION['financeiro_csrf'])) {
        $_SESSION['financeiro_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['financeiro_csrf'];
}

function financeiroTokenValido(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['financeiro_csrf'])
        && hash_equals($_SESSION['financeiro_csrf'], $token);
}

function financeiroDefinirMensagem(string $texto, string $tipo = 'success'): void
{
    $_SESSION['financeiro_mensagem'] = [
        'texto' => $texto,
        'tipo' => $tipo,
    ];
}

function financeiroObterMensagem(): ?array
{
    $mensagem = $_SESSION['financeiro_mensagem'] ?? null;
    unset($_SESSION['financeiro_mensagem']);

    return is_array($mensagem) ? $mensagem : null;
}

function financeiroRedirecionar(string $url, string $mensagem, string $tipo = 'success'): void
{
    financeiroDefinirMensagem($mensagem, $tipo);
    header('Location: ' . $url);
    exit;
}
