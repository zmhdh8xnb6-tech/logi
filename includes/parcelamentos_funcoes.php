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

function orgaosCanceladosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcecancelados_simples.php',
        'Previdência Social e Tributos' => 'parcecancelados_tributos.php',
        'PGFN' => 'parcecancelados_pgfn.php',
        'SEFAZ DF' => 'parcecancelados_sefazdf.php',
        'SEFAZ GO' => 'parcecancelados_sefazgo.php',
    ];
}

function urlOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function urlCanceladosOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosCanceladosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function buscarParcelamentosPorOrgao(PDO $pdo, string $orgao, bool $cancelados = false): array
{
    $filtroCancelamento = $cancelados
        ? 'p.cancelado_em IS NOT NULL'
        : 'p.cancelado_em IS NULL';

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
        AND {$filtroCancelamento}
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
    if (!empty($parcelamento['cancelado_em'])) {
        return 'Cancelado';
    }

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

function renderizarLinhasParcelamentos(
    array $parcelamentos,
    bool $mostrarAcoes = true,
    bool $mostrarReativar = false
): void {
    if (count($parcelamentos) === 0): ?>
        <tr>
            <td colspan="<?= $mostrarAcoes ? 8 : 7 ?>" class="text-center text-muted py-4">
                Nenhum parcelamento cadastrado ainda.
            </td>
        </tr>
    <?php return;
    endif;

    foreach ($parcelamentos as $parcelamento):
        $parcelasEmitidas = parcelasEmitidasAtual($parcelamento);
        $status = statusParcelamento($parcelamento);
        $badge = 'success';

        if ($status === 'Atrasado') {
            $badge = 'danger';
        } elseif ($status === 'Concluído') {
            $badge = 'secondary';
        } elseif ($status === 'Cancelado') {
            $badge = 'dark';
        }
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
            <?php if ($mostrarAcoes): ?>
                <td class="text-end coluna-acoes">
                    <?php if ($mostrarReativar): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-success"
                            data-bs-toggle="modal"
                            data-bs-target="#modalReativarParcelamento"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Reativar
                        </button>
                    <?php else: ?>
                        <a
                            href="parcelamento_editar.php?id=<?= (int)$parcelamento['id'] ?>"
                            class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
<?php endforeach;
}
