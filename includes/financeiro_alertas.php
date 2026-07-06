<?php
$alertasFinanceiros = $alertasFinanceiros ?? [];
?>

<?php if ($alertasFinanceiros !== []): ?>
    <section class="financeiro-alertas mb-4" aria-label="Vencimentos próximos">
        <div class="financeiro-alertas-cabecalho">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-bell"></i>
                    Vencimentos que precisam de atenção
                </h5>
                <p class="mb-0">
                    <?= count($alertasFinanceiros) ?>
                    <?= count($alertasFinanceiros) === 1 ? 'lançamento pendente' : 'lançamentos pendentes' ?>
                </p>
            </div>
        </div>
        <div class="financeiro-alertas-lista">
            <?php foreach ($alertasFinanceiros as $alerta): ?>
                <a href="<?= htmlspecialchars($alerta['url']) ?>" class="financeiro-alerta-item">
                    <span class="financeiro-alerta-icone text-<?= htmlspecialchars($alerta['classe']) ?>">
                        <i class="bi <?= $alerta['tipo'] === 'Fatura' ? 'bi-credit-card' : 'bi-receipt' ?>"></i>
                    </span>
                    <span class="financeiro-alerta-dados">
                        <strong><?= htmlspecialchars($alerta['descricao']) ?></strong>
                        <small>
                            <?= htmlspecialchars($alerta['tipo']) ?>
                            · <?= financeiroData($alerta['vencimento']) ?>
                            · <?= financeiroMoeda($alerta['valor']) ?>
                        </small>
                    </span>
                    <span class="badge bg-<?= htmlspecialchars($alerta['classe']) ?> <?= $alerta['classe'] === 'warning' ? 'text-dark' : '' ?>">
                        <?= htmlspecialchars($alerta['prazo']) ?>
                    </span>
                    <i class="bi bi-chevron-right financeiro-alerta-seta"></i>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>