<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parcelamentos.php');
    exit;
}

$orgao = trim((string)($_POST['orgao'] ?? ''));
$urlRetorno = urlOrgaoParcelamento($orgao);
$idsInformados = is_array($_POST['parcelamentos'] ?? null)
    ? array_filter($_POST['parcelamentos'], 'is_scalar')
    : [];
$decisoesInformadas = is_array($_POST['decisoes'] ?? null) ? $_POST['decisoes'] : [];
$ids = array_values(array_unique(array_filter(
    array_map('intval', $idsInformados),
    static fn(int $id): bool => $id > 0
)));

if (
    !parcelamentosTokenValido($_POST['csrf_token'] ?? null)
    || !array_key_exists($orgao, orgaosParcelamento())
    || $ids === []
) {
    header('Location: ' . $urlRetorno . '?erro_revisao_liquidacao=1');
    exit;
}

$decisoes = [];

foreach ($ids as $id) {
    $decisao = $decisoesInformadas[$id] ?? '';

    if (!in_array($decisao, ['sim', 'nao'], true)) {
        header('Location: ' . $urlRetorno . '?erro_revisao_liquidacao=1');
        exit;
    }

    $decisoes[$id] = $decisao;
}

try {
    $pdo->beginTransaction();
    $pendentes = buscarParcelamentosPendentesLiquidacao($pdo, $orgao, $ids, true);

    if (count($pendentes) !== count($ids)) {
        $pdo->rollBack();
        header('Location: ' . $urlRetorno . '?revisao_liquidacao_desatualizada=1');
        exit;
    }

    foreach ($pendentes as $parcelamento) {
        $id = (int)$parcelamento['id'];
        $cliente = ($parcelamento['cliente_codigo'] ?? '') . ' - ' . ($parcelamento['cliente_nome'] ?? '');

        if ($decisoes[$id] === 'sim') {
            $campos = [
                'liquidado_em = NOW()',
                'parcelas_emitidas = parcelas_total',
                'parcelas_atrasadas = 0',
            ];

            if (parcelamentosTemColuna($pdo, 'liquidacao_tipo')) {
                $campos[] = "liquidacao_tipo = 'automatica'";
            }

            if (parcelamentosTemColuna($pdo, 'liquidacao_observacao')) {
                $campos[] = 'liquidacao_observacao = NULL';
            }

            $stmt = $pdo->prepare("
                UPDATE parcelamentos
                SET " . implode(', ', $campos) . "
                WHERE id = ?
                  " . empresaFiltro($pdo, 'parcelamentos') . "
                  AND cancelado_em IS NULL
                  AND liquidado_em IS NULL
            ");
            $stmt->execute([$id]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('O parcelamento confirmado não pôde ser liquidado.');
            }

            registrarAuditoria(
                $pdo,
                'Parcelamentos',
                'confirmar_liquidacao',
                'parcelamento',
                $id,
                'Confirmou a liquidação do parcelamento de ' . $cliente,
                [
                    'liquidado_em' => null,
                    'parcelas_emitidas' => $parcelamento['parcelas_emitidas'],
                    'parcelas_atrasadas' => $parcelamento['parcelas_atrasadas'],
                ],
                [
                    'liquidado_em' => date('Y-m-d H:i:s'),
                    'parcelas_emitidas' => $parcelamento['parcelas_total'],
                    'parcelas_atrasadas' => 0,
                ]
            );

            continue;
        }

        $stmt = $pdo->prepare("
            UPDATE parcelamentos
            SET parcelas_atrasadas = GREATEST(COALESCE(parcelas_atrasadas, 0), 1)
            WHERE id = ?
              " . empresaFiltro($pdo, 'parcelamentos') . "
              AND cancelado_em IS NULL
              AND liquidado_em IS NULL
        ");
        $stmt->execute([$id]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('O parcelamento não pôde ser mantido como atrasado.');
        }

        registrarAuditoria(
            $pdo,
            'Parcelamentos',
            'manter_ativo_apos_revisao',
            'parcelamento',
            $id,
            'Informou que o parcelamento de ' . $cliente . ' ainda não foi pago',
            ['parcelas_atrasadas' => $parcelamento['parcelas_atrasadas']],
            ['parcelas_atrasadas' => max(1, (int)$parcelamento['parcelas_atrasadas'])]
        );
    }

    $pdo->commit();
    header('Location: ' . $urlRetorno . '?revisao_liquidacao=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: ' . $urlRetorno . '?erro_revisao_liquidacao=1');
    exit;
}
