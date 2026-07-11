<?php
$alertasFinanceiros = $alertasFinanceiros ?? [];
$financeiroAlertasContexto = $financeiroAlertasContexto ?? '';
?>

<?php if ($alertasFinanceiros !== []): ?>
    <?php
    $totalAlertasFinanceiros = array_sum(array_map(
        static fn(array $alerta): float => (float)($alerta['valor'] ?? 0),
        $alertasFinanceiros
    ));
    ?>
    <section class="financeiro-alertas mb-4" aria-label="Vencimentos próximos">
        <div class="financeiro-alertas-cabecalho">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-bell"></i>
                    <?= count($alertasFinanceiros) ?>
                    <?= count($alertasFinanceiros) === 1 ? 'vencimento pendente' : 'vencimentos pendentes' ?>
                </h5>
                <p class="mb-0">
                    Total em atenção: <?= financeiroMoeda($totalAlertasFinanceiros) ?>
                </p>
            </div>
            <button
                class="btn btn-sm btn-outline-warning financeiro-alertas-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#financeiroAlertasDetalhes"
                aria-expanded="false"
                aria-controls="financeiroAlertasDetalhes">
                Ver detalhes
            </button>
        </div>
        <div class="collapse" id="financeiroAlertasDetalhes">
            <div class="financeiro-alertas-lista">
                <?php foreach ($alertasFinanceiros as $alerta): ?>
                    <div class="financeiro-alerta-item">
                        <span class="financeiro-alerta-icone text-<?= htmlspecialchars($alerta['classe']) ?>">
                            <i class="bi <?= $alerta['tipo'] === 'Fatura' ? 'bi-credit-card' : 'bi-receipt' ?>"></i>
                        </span>
                        <a href="<?= htmlspecialchars($alerta['url']) ?>" class="financeiro-alerta-dados">
                            <strong><?= htmlspecialchars($alerta['descricao']) ?></strong>
                            <small>
                                <?= htmlspecialchars($alerta['tipo']) ?>
                                · <?= financeiroData($alerta['vencimento']) ?>
                                · <?= financeiroMoeda($alerta['valor']) ?>
                            </small>
                        </a>
                        <span class="badge bg-<?= htmlspecialchars($alerta['classe']) ?> <?= $alerta['classe'] === 'warning' ? 'text-dark' : '' ?>">
                            <?= htmlspecialchars($alerta['prazo']) ?>
                        </span>
                        <?php if ($alerta['tipo'] === 'Fatura' && $financeiroAlertasContexto === 'cartoes'): ?>
                            <button
                                type="button"
                                class="btn btn-outline-success btn-sm btn-pagar-fatura financeiro-alerta-pagar"
                                data-cartao-id="<?= (int)($alerta['cartao_id'] ?? 0) ?>"
                                data-mes-fatura="<?= htmlspecialchars($alerta['competencia_cartao'] ?? '') ?>"
                                data-descricao="<?= htmlspecialchars($alerta['descricao']) ?>"
                                data-valor="<?= number_format((float)$alerta['valor'], 2, ',', '.') ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#modalPagarFatura"
                                title="Pagar fatura"
                                aria-label="Pagar <?= htmlspecialchars($alerta['descricao']) ?>">
                                <i class="bi bi-check-lg"></i>
                            </button>
                        <?php else: ?>
                            <button
                                type="button"
                                class="btn btn-outline-success btn-sm btn-pagar-conta financeiro-alerta-pagar"
                                data-id="<?= (int)$alerta['id'] ?>"
                                data-descricao="<?= htmlspecialchars($alerta['descricao']) ?>"
                                data-valor="<?= number_format((float)$alerta['valor'], 2, ',', '.') ?>"
                                data-tipo="<?= htmlspecialchars($alerta['tipo']) ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#modalPagarConta"
                                title="Marcar como pago"
                                aria-label="Marcar <?= htmlspecialchars($alerta['descricao']) ?> como pago">
                                <i class="bi bi-check-lg"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>