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

function orgaosLiquidadosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcliquidados_simples.php',
        'Previdência Social e Tributos' => 'parcliquidados_tributos.php',
        'PGFN' => 'parcliquidados_pgfn.php',
        'SEFAZ DF' => 'parcliquidados_sefazdf.php',
        'SEFAZ GO' => 'parcliquidados_sefazgo.php',
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

function urlLiquidadosOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosLiquidadosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function parcelamentosTemColuna(PDO $pdo, string $coluna): bool
{
    static $cache = [];

    if (array_key_exists($coluna, $cache)) {
        return $cache[$coluna];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM parcelamentos LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$coluna] = false;
    }

    return $cache[$coluna];
}

function atualizarParcelamentosLiquidados(PDO $pdo, string $orgao): array
{
    if (!parcelamentosTemColuna($pdo, 'liquidado_em')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.numero_parcelamento,
            p.parcelas_total,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
          AND p.liquidado_em IS NULL
          AND p.cancelado_em IS NULL
          AND p.parcelas_total > 0
          AND p.parcelas_atrasadas = 0
          AND (
              (p.data_primeira_parcela IS NULL AND p.parcelas_emitidas >= p.parcelas_total)
              OR (
                  p.data_primeira_parcela IS NOT NULL
                  AND CURDATE() >= p.data_primeira_parcela
                  AND TIMESTAMPDIFF(
                      MONTH,
                      p.data_primeira_parcela,
                      CURDATE()
                  ) + 1 > p.parcelas_total
              )
          )
        ORDER BY CAST(c.codigo AS UNSIGNED), c.nome, p.id
    ");
    $stmt->execute([$orgao]);
    $liquidados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$liquidados) {
        return [];
    }

    $ids = array_column($liquidados, 'id');
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $camposLiquidacao = 'liquidado_em = NOW()';

    if (parcelamentosTemColuna($pdo, 'liquidacao_tipo')) {
        $camposLiquidacao .= ", liquidacao_tipo = 'automatica'";
    }

    if (parcelamentosTemColuna($pdo, 'liquidacao_observacao')) {
        $camposLiquidacao .= ', liquidacao_observacao = NULL';
    }

    $stmt = $pdo->prepare("
        UPDATE parcelamentos
        SET {$camposLiquidacao}
        WHERE liquidado_em IS NULL
          AND id IN ({$marcadores})
    ");
    $stmt->execute($ids);

    return $liquidados;
}

function buscarParcelamentosPorOrgao(
    PDO $pdo,
    string $orgao,
    bool $cancelados = false,
    bool $liquidados = false
): array {
    if (!$cancelados && !$liquidados) {
        $GLOBALS['parcelamentos_liquidados_agora'] = atualizarParcelamentosLiquidados($pdo, $orgao);
    }

    $temLiquidadoEm = parcelamentosTemColuna($pdo, 'liquidado_em');

    if ($liquidados) {
        $filtroSituacao = $temLiquidadoEm
            ? 'p.liquidado_em IS NOT NULL'
            : '1 = 0';
    } elseif ($cancelados) {
        $filtroSituacao = 'p.cancelado_em IS NOT NULL';
    } else {
        $filtroSituacao = $temLiquidadoEm
            ? 'p.cancelado_em IS NULL AND p.liquidado_em IS NULL'
            : 'p.cancelado_em IS NULL';
    }

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
        AND {$filtroSituacao}
        ORDER BY CAST(c.codigo AS UNSIGNED) ASC, c.nome ASC, p.id DESC
    ");

    $stmt->execute([$orgao]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function renderizarAvisoLiquidacoesAutomaticas(string $orgao): void
{
    $liquidados = $GLOBALS['parcelamentos_liquidados_agora'] ?? [];

    if (!$liquidados) {
        return;
    }

    $urlLiquidados = urlLiquidadosOrgaoParcelamento($orgao);
?>
    <div class="modal fade" id="modalLiquidacoesAutomaticas" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Parcelamento liquidado automaticamente
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p>
                        Os parcelamentos abaixo chegaram à última parcela. Confira se os clientes realmente pagaram:
                    </p>

                    <div class="list-group">
                        <?php foreach ($liquidados as $parcelamento): ?>
                            <div class="list-group-item">
                                <strong class="d-block">
                                    <?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>
                                </strong>
                                <span class="text-muted small">
                                    Nº <?= htmlspecialchars($parcelamento['numero_parcelamento']) ?>
                                    · <?= (int)$parcelamento['parcelas_total'] ?>/<?= (int)$parcelamento['parcelas_total'] ?> parcelas
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="text-muted small mt-3 mb-0">
                        Caso algum cliente não tenha pago, use “Voltar” na lista de liquidados.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="<?= htmlspecialchars($urlLiquidados) ?>" class="btn btn-success">
                        Revisar liquidados
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('modalLiquidacoesAutomaticas');
            bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    </script>
<?php
}

function renderizarModalQuitarParcelamento(): void
{
?>
    <div class="modal fade" id="modalQuitarParcelamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="parcelamento_quitar.php" id="formQuitarParcelamento" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">Quitar parcelamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <strong id="clienteParcelamentoQuitar" class="d-block mb-3"></strong>

                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between gap-3 mb-2">
                                <span class="text-muted">Parcela atual</span>
                                <strong id="parcelaAtualQuitar"></strong>
                            </div>
                            <div class="d-flex justify-content-between gap-3">
                                <span class="text-muted">Parcelas atrasadas</span>
                                <strong id="parcelasAtrasadasQuitar"></strong>
                            </div>
                        </div>

                        <input type="hidden" name="id" id="parcelamentoIdQuitar">

                        <div class="mb-3">
                            <label for="dataQuitacao" class="form-label">Data da quitação</label>
                            <input
                                type="date"
                                class="form-control"
                                name="data_quitacao"
                                id="dataQuitacao"
                                value="<?= date('Y-m-d') ?>"
                                max="<?= date('Y-m-d') ?>">
                            <div class="invalid-feedback">Informe uma data válida.</div>
                        </div>

                        <div>
                            <label for="observacaoQuitacao" class="form-label">Observação <span class="text-muted">(opcional)</span></label>
                            <textarea
                                class="form-control"
                                name="observacao"
                                id="observacaoQuitacao"
                                rows="3"
                                maxlength="500"
                                placeholder="Ex.: cliente realizou o pagamento integral"></textarea>
                        </div>

                        <p class="text-muted small mt-3 mb-0">
                            O parcelamento será enviado para Liquidados como quitado antecipadamente.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar quitação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('modalQuitarParcelamento').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;

            document.getElementById('parcelamentoIdQuitar').value = botao.dataset.parcelamentoId;
            document.getElementById('clienteParcelamentoQuitar').textContent = botao.dataset.cliente;
            document.getElementById('parcelaAtualQuitar').textContent = botao.dataset.parcelas;
            document.getElementById('parcelasAtrasadasQuitar').textContent = botao.dataset.atrasadas;
        });

        document.getElementById('formQuitarParcelamento').addEventListener('submit', function(event) {
            const campoData = document.getElementById('dataQuitacao');

            if (!campoData.value || campoData.value > campoData.max) {
                event.preventDefault();
                campoData.classList.add('is-invalid');
                campoData.focus();
                return;
            }

            campoData.classList.remove('is-invalid');
        });
    </script>
    <?php
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

    if (!empty($parcelamento['liquidado_em'])) {
        return ($parcelamento['liquidacao_tipo'] ?? '') === 'antecipada'
            ? 'Quitado antecipadamente'
            : 'Liquidado';
    }

    if ((int)$parcelamento['parcelas_atrasadas'] > 0) {
        return 'Atrasado';
    }

    return 'Em dia';
}

function parcelasEmitidasAtual(array $parcelamento): int
{
    $dataReferencia = new DateTime(date('Y-m-d'));

    if (!empty($parcelamento['cancelado_em'])) {
        $dataReferencia = new DateTime($parcelamento['cancelado_em']);
    } elseif (!empty($parcelamento['liquidado_em'])) {
        $dataReferencia = new DateTime($parcelamento['liquidado_em']);
    }

    return parcelasEmitidasNaData($parcelamento, $dataReferencia);
}

function parcelasEmitidasNaData(array $parcelamento, DateTimeInterface $dataReferencia): int
{
    $parcelasTotal = (int)$parcelamento['parcelas_total'];

    if ($parcelasTotal <= 0) {
        return 0;
    }

    if (empty($parcelamento['data_primeira_parcela'])) {
        return min((int)$parcelamento['parcelas_emitidas'], $parcelasTotal);
    }

    $inicio = new DateTime($parcelamento['data_primeira_parcela']);
    $referencia = new DateTime($dataReferencia->format('Y-m-d'));

    if ($referencia < $inicio) {
        return 0;
    }

    $meses = (($referencia->format('Y') - $inicio->format('Y')) * 12)
        + ($referencia->format('n') - $inicio->format('n'));

    return min($meses + 1, $parcelasTotal);
}

function diasDesdeCancelamento(array $parcelamento): int
{
    if (empty($parcelamento['cancelado_em'])) {
        return 0;
    }

    $cancelamento = new DateTime($parcelamento['cancelado_em']);
    $hoje = new DateTime(date('Y-m-d'));

    if ($hoje <= $cancelamento) {
        return 0;
    }

    return (int)$cancelamento->diff($hoje)->days;
}

function renderizarLinhasParcelamentos(
    array $parcelamentos,
    bool $mostrarAcoes = true,
    bool $mostrarReativar = false,
    bool $mostrarVoltarLiquidado = false
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
        $parcelasAoReativar = parcelasEmitidasNaData(
            $parcelamento,
            new DateTime(date('Y-m-d'))
        );
        $status = statusParcelamento($parcelamento);
        $badge = 'success';

        if ($status === 'Atrasado') {
            $badge = 'danger';
        } elseif ($status === 'Liquidado') {
            $badge = 'success';
        } elseif ($status === 'Cancelado') {
            $badge = 'dark';
        }
    ?>
        <tr class="linha-cliente">
            <td>
                <?= htmlspecialchars($parcelamento['cliente_codigo']) ?>
                -
                <?= htmlspecialchars($parcelamento['cliente_nome']) ?>
                <?php if (!empty($parcelamento['liquidado_em'])): ?>
                    <div class="small text-muted mt-1">
                        Liquidado em <?= (new DateTime($parcelamento['liquidado_em']))->format('d/m/Y') ?>
                        <?php if (!empty($parcelamento['liquidacao_observacao'])): ?>
                            · <?= htmlspecialchars($parcelamento['liquidacao_observacao']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>"
                            data-parcela-cancelada="<?= $parcelasEmitidas ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-parcela-reativada="<?= $parcelasAoReativar ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-dias-cancelado="<?= diasDesdeCancelamento($parcelamento) ?>"
                            data-cancelado-em="<?= !empty($parcelamento['cancelado_em']) ? (new DateTime($parcelamento['cancelado_em']))->format('d/m/Y') : '' ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Reativar
                        </button>
                    <?php elseif ($mostrarVoltarLiquidado): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning"
                            data-bs-toggle="modal"
                            data-bs-target="#modalVoltarLiquidado"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Voltar
                        </button>
                    <?php else: ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-success"
                            data-bs-toggle="modal"
                            data-bs-target="#modalQuitarParcelamento"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>"
                            data-parcelas="<?= $parcelasEmitidas ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-atrasadas="<?= (int)$parcelamento['parcelas_atrasadas'] ?>"
                            title="Quitar parcelamento">
                            <i class="bi bi-check2-circle"></i>
                        </button>
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
