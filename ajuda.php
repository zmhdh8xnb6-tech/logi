<?php
require 'config.php';

exigirLogin();

$topicos = [
    [
        'icone' => 'bi-house',
        'titulo' => 'Início',
        'texto' => 'A home reúne os cards principais, tarefas do dia e avisos importantes de rotina.',
    ],
    [
        'icone' => 'bi-people',
        'titulo' => 'Clientes',
        'texto' => 'Cadastre clientes contábeis ou serviços avulsos. As informações incompletas entram nas pendências.',
    ],
    [
        'icone' => 'bi-cash-coin',
        'titulo' => 'Parcelamentos',
        'texto' => 'Controle parcelamentos por órgão, atrasos, cancelamentos, liquidações e impressão mensal.',
    ],
    [
        'icone' => 'bi-wallet2',
        'titulo' => 'Financeiro',
        'texto' => 'Registre recebimentos, contas, cartões, metas financeiras e relatórios por período.',
    ],
    [
        'icone' => 'bi-diagram-3',
        'titulo' => 'Legalização',
        'texto' => 'Acompanhe processos, etapas, checklist, histórico e responsáveis por cada andamento.',
    ],
    [
        'icone' => 'bi-file-earmark-text',
        'titulo' => 'Certificados e Procurações',
        'texto' => 'Consulte vencimentos, atualize dados pelos modais e acompanhe alertas nas pendências.',
    ],
    [
        'icone' => 'bi-exclamation-triangle',
        'titulo' => 'Pendências',
        'texto' => 'Mostra o que falta, venceu ou precisa de correção nos cadastros e controles internos.',
    ],
    [
        'icone' => 'bi-check2-square',
        'titulo' => 'Tarefas',
        'texto' => 'Use como post-it digital: crie tarefas, marque importantes e conclua com confirmação.',
    ],
];
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Ajuda</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/ajuda.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Ajuda e Manual</h3>
                    <p class="text-muted mb-0">Guia rápido para consultar sempre que precisar.</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <section class="ajuda-painel ajuda-destaque mb-4">
                <div>
                    <span>Primeiro acesso</span>
                    <h4>Como usar o sistema sem se perder</h4>
                    <p>
                        Comece pela home. Cada card leva para uma rotina específica. Quando algo exigir atenção,
                        o sistema mostra como aviso, notificação ou pendência.
                    </p>
                </div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTutorialManual">
                    <i class="bi bi-play-circle"></i> Ver tutorial
                </button>
            </section>

            <section class="ajuda-grid">
                <?php foreach ($topicos as $topico): ?>
                    <article class="ajuda-card">
                        <i class="bi <?= htmlspecialchars($topico['icone']) ?>"></i>
                        <h5><?= htmlspecialchars($topico['titulo']) ?></h5>
                        <p><?= htmlspecialchars($topico['texto']) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
    </main>

    <div class="modal fade" id="modalTutorialManual" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tutorial rápido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-passos">
                        <div>
                            <strong>1. Entre pela home</strong>
                            <span>Ela mostra os módulos liberados para o seu usuário.</span>
                        </div>
                        <div>
                            <strong>2. Resolva avisos e pendências</strong>
                            <span>Avisos são rotinas do mês. Pendências são dados faltando, vencidos ou incorretos.</span>
                        </div>
                        <div>
                            <strong>3. Use os cards por rotina</strong>
                            <span>Parcelamentos, clientes, legalização, tarefas e demais módulos ficam separados.</span>
                        </div>
                        <div>
                            <strong>4. Consulte este manual quando quiser</strong>
                            <span>O botão Ajuda fica no menu lateral e pode ser aberto novamente a qualquer momento.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>