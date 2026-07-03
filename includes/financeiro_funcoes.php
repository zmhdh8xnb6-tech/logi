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
        'financeiro_contas',
        'financeiro_cartoes',
        'financeiro_cartao_lancamentos',
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
                DATE_FORMAT(data_compra, '%Y-%m') AS competencia,
                SUM(valor) AS valor_total,
                SUM(CASE WHEN status = 'aberto' THEN 1 ELSE 0 END) AS parcelas_abertas,
                MAX(data_pagamento) AS data_pagamento
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND cartao_id = ?
            GROUP BY DATE_FORMAT(data_compra, '%Y-%m')
            ORDER BY competencia
        ");
        $stmtSalvar = $pdo->prepare("
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
            ON DUPLICATE KEY UPDATE
                descricao = VALUES(descricao),
                valor_previsto = VALUES(valor_previsto),
                vencimento = VALUES(vencimento),
                status = VALUES(status),
                valor_pago = VALUES(valor_pago),
                data_pagamento = VALUES(data_pagamento)
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
                $stmtSalvar->execute([
                    $usuarioId,
                    'Fatura ' . $cartao['nome'],
                    $valorTotal,
                    financeiroVencimentoFatura($competencia, (int)$cartao['dia_vencimento']),
                    $paga ? 'pago' : 'pendente',
                    $paga ? $valorTotal : null,
                    $paga ? $fatura['data_pagamento'] : null,
                    $cartao['id'],
                    $competencia,
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

    try {
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
                recorrencia_id,
                competencia_recorrencia
            )
            VALUES (?, ?, ?, ?, 'pendente', ?, ?)
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
        ");

        foreach ($recorrencias as $recorrencia) {
            $dia = (int)date('d', strtotime($recorrencia['primeiro_vencimento']));
            $stmtSalvar->execute([
                $usuarioId,
                $recorrencia['descricao'],
                $recorrencia['valor'],
                financeiroVencimentoFatura($mes, $dia),
                $recorrencia['id'],
                $mes,
            ]);
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

    try {
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
                recorrencia_id,
                competencia_recorrencia
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                data_recebimento = VALUES(data_recebimento),
                descricao = VALUES(descricao),
                recebido_de = VALUES(recebido_de),
                valor = VALUES(valor)
        ");

        foreach ($recorrencias as $recorrencia) {
            $dia = (int)date('d', strtotime($recorrencia['primeiro_recebimento']));
            $stmtSalvar->execute([
                $usuarioId,
                financeiroVencimentoFatura($mes, $dia),
                $recorrencia['descricao'],
                $recorrencia['recebido_de'],
                $recorrencia['valor'],
                $recorrencia['id'],
                $mes,
            ]);
        }
    } catch (Throwable $e) {
        // A sincronização automática não deve impedir a abertura do financeiro.
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
