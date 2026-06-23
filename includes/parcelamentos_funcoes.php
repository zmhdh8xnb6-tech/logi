<?php

function orgaosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcelamento_simples.php',
        'Previdência Social e Tributos' => 'parcelamento_tributos.php',
        'PGFN' => 'parcelamento_pgfn.php',
        'SEFAZ DF' => 'parcelamento_sefazdf.php',
        'SEFAZ GO' => 'parcelamento_sefazgo.php',
    ];
}

function urlOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function buscarParcelamentosPorOrgao(PDO $pdo, string $orgao): array
{
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
        ORDER BY CAST(c.codigo AS UNSIGNED) ASC, c.nome ASC, p.id DESC
    ");

    $stmt->execute([$orgao]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarParcelamentoPorId(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
    ");

    $stmt->execute([$id]);
    $parcelamento = $stmt->fetch(PDO::FETCH_ASSOC);

    return $parcelamento ?: null;
}

function statusParcelamento(array $parcelamento): string
{
    $parcelasEmitidas = parcelasEmitidasAtual($parcelamento);

    if ((int)$parcelamento['parcelas_atrasadas'] > 0) {
        return 'Atrasado';
    }

    if (
        (int)$parcelamento['parcelas_total'] > 0 &&
        $parcelasEmitidas >= (int)$parcelamento['parcelas_total']
    ) {
        return 'Concluído';
    }

    return 'Em dia';
}

function parcelasEmitidasAtual(array $parcelamento): int
{
    $parcelasTotal = (int)$parcelamento['parcelas_total'];

    if ($parcelasTotal <= 0) {
        return 0;
    }

    if (empty($parcelamento['data_primeira_parcela'])) {
        return min((int)$parcelamento['parcelas_emitidas'], $parcelasTotal);
    }

    $inicio = new DateTime($parcelamento['data_primeira_parcela']);
    $hoje = new DateTime(date('Y-m-d'));

    if ($hoje < $inicio) {
        return 0;
    }

    $meses = (($hoje->format('Y') - $inicio->format('Y')) * 12)
        + ($hoje->format('n') - $inicio->format('n'));

    return min($meses + 1, $parcelasTotal);
}

function renderizarLinhasParcelamentos(array $parcelamentos): void
{
    if (count($parcelamentos) === 0): ?>
        <tr>
            <td colspan="8" class="text-center text-muted py-4">
                Nenhum parcelamento cadastrado ainda.
            </td>
        </tr>
    <?php return;
    endif;

    foreach ($parcelamentos as $parcelamento):
        $parcelasEmitidas = parcelasEmitidasAtual($parcelamento);
        $status = statusParcelamento($parcelamento);
        $badge = $status === 'Atrasado'
            ? 'danger'
            : ($status === 'Concluído' ? 'secondary' : 'success');
    ?>
        <tr class="linha-cliente">
            <td>
                <?= htmlspecialchars($parcelamento['cliente_codigo']) ?>
                -
                <?= htmlspecialchars($parcelamento['cliente_nome']) ?>
            </td>
            <td><?= htmlspecialchars($parcelamento['orgao']) ?></td>
            <td class="text-end"><?= htmlspecialchars($parcelamento['numero_parcelamento']) ?></td>
            <td class="text-end"><?= htmlspecialchars($parcelamento['forma_envio']) ?></td>
            <td class="text-end">
                <?= $parcelasEmitidas ?>
                /
                <?= (int)$parcelamento['parcelas_total'] ?>
            </td>
            <td class="text-end">
                <?php if ((int)$parcelamento['parcelas_atrasadas'] > 0): ?>
                    <span class="badge text-bg-danger">
                        <?= (int)$parcelamento['parcelas_atrasadas'] ?>
                    </span>
                <?php else: ?>
                    <span class="text-muted">0</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge text-bg-<?= $badge ?>">
                    <?= $status ?>
                </span>
            </td>
            <td class="text-end coluna-acoes">
                <a
                    href="parcelamento_editar.php?id=<?= (int)$parcelamento['id'] ?>"
                    class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil"></i>
                </a>
            </td>
        </tr>
<?php endforeach;
}
