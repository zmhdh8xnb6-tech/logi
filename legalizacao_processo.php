<?php
require 'config.php';
require 'includes/legalizacao_funcoes.php';

exigirPermissao('legalizacao');

$tabelasDisponiveis = legalizacaoTabelasDisponiveis($pdo);
$processoId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$tabelasDisponiveis) {
    legalizacaoRedirect('legalizacao.php', 'Execute o SQL da legalização antes de abrir processos.', 'danger');
}

$processo = $processoId > 0 ? legalizacaoBuscarProcesso($pdo, $processoId) : null;

if (!$processo) {
    legalizacaoRedirect('legalizacao.php', 'Processo não encontrado.', 'danger');
}

$usuarios = legalizacaoListarUsuarios($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'atualizar_dados') {
        $status = $_POST['status'] ?? 'em_andamento';
        $prazo = trim($_POST['prazo'] ?? '');
        $responsavelId = (int)($_POST['responsavel_id'] ?? 0);
        $contatoCliente = trim($_POST['contato_cliente'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $statusValidos = ['em_andamento', 'pendente_cliente', 'pendente_orgao', 'pausado', 'concluido', 'cancelado'];

        if (
            !in_array($status, $statusValidos, true)
            || ($prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo))
        ) {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Confira os dados informados.', 'danger');
        }

        $responsavelNome = $processo['responsavel_nome'];

        if ($responsavelId > 0) {
            foreach ($usuarios as $usuario) {
                if ((int)$usuario['id'] === $responsavelId) {
                    $responsavelNome = $usuario['nome'];
                    break;
                }
            }
        }

        $antes = $processo;
        $concluidoEmSql = $status === 'concluido' && empty($processo['concluido_em'])
            ? ', concluido_em = NOW()'
            : ($status !== 'concluido' ? ', concluido_em = NULL' : '');
        $stmt = $pdo->prepare("
            UPDATE legalizacao_processos
            SET status = ?,
                prazo = ?,
                responsavel_id = ?,
                responsavel_nome = ?,
                contato_cliente = ?,
                observacoes = ?,
                atualizado_em = NOW()
                {$concluidoEmSql}
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $prazo === '' ? null : $prazo,
            $responsavelId,
            $responsavelNome,
            $contatoCliente,
            $observacoes,
            $processoId,
        ]);

        legalizacaoRegistrarHistorico($pdo, $processoId, 'editar', 'Dados do processo atualizados.');
        $depois = legalizacaoBuscarProcesso($pdo, $processoId);
        registrarAuditoria($pdo, 'Legalização', 'editar', 'processo', $processoId, 'Atualizou dados do processo', $antes, $depois);
        legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Processo atualizado com sucesso.');
    }

    if ($acao === 'avancar_etapa' || $acao === 'voltar_etapa') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM legalizacao_etapas
            WHERE processo_id = ?
            ORDER BY ordem
        ");
        $stmt->execute([$processoId]);
        $etapasAcao = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $indiceAtual = null;

        foreach ($etapasAcao as $indice => $etapa) {
            if ((int)$etapa['ordem'] === (int)$processo['etapa_atual_ordem']) {
                $indiceAtual = $indice;
                break;
            }
        }

        if ($indiceAtual === null) {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Etapa atual não encontrada.', 'danger');
        }

        $proximoIndice = $acao === 'avancar_etapa' ? $indiceAtual + 1 : $indiceAtual - 1;

        if (!isset($etapasAcao[$proximoIndice])) {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Não há etapa para movimentar.', 'warning');
        }

        $etapaAtual = $etapasAcao[$indiceAtual];
        $novaEtapa = $etapasAcao[$proximoIndice];
        $novoStatus = 'em_andamento';
        $concluidoEm = null;

        if ($acao === 'avancar_etapa' && stripos($novaEtapa['nome'], 'concluído') !== false) {
            $novoStatus = 'concluido';
            $concluidoEm = date('Y-m-d H:i:s');
        }

        $pdo->beginTransaction();

        try {
            if ($acao === 'avancar_etapa') {
                $stmt = $pdo->prepare("
                    UPDATE legalizacao_etapas
                    SET status = 'concluida', concluido_em = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([(int)$etapaAtual['id']]);

                $stmt = $pdo->prepare("
                    UPDATE legalizacao_etapas
                    SET status = 'atual', iniciado_em = COALESCE(iniciado_em, NOW())
                    WHERE id = ?
                ");
                $stmt->execute([(int)$novaEtapa['id']]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE legalizacao_etapas
                    SET status = 'pendente', iniciado_em = NULL, concluido_em = NULL
                    WHERE id = ?
                ");
                $stmt->execute([(int)$etapaAtual['id']]);

                $stmt = $pdo->prepare("
                    UPDATE legalizacao_etapas
                    SET status = 'atual', concluido_em = NULL
                    WHERE id = ?
                ");
                $stmt->execute([(int)$novaEtapa['id']]);
            }

            $stmt = $pdo->prepare("
                UPDATE legalizacao_processos
                SET etapa_atual_ordem = ?,
                    etapa_atual_nome = ?,
                    status = ?,
                    concluido_em = ?,
                    atualizado_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                (int)$novaEtapa['ordem'],
                $novaEtapa['nome'],
                $novoStatus,
                $concluidoEm,
                $processoId,
            ]);

            legalizacaoRegistrarHistorico(
                $pdo,
                $processoId,
                $acao === 'avancar_etapa' ? 'avancar' : 'voltar',
                ($acao === 'avancar_etapa' ? 'Avançou' : 'Voltou') . ' da etapa "' . $etapaAtual['nome'] . '" para "' . $novaEtapa['nome'] . '".'
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Não foi possível movimentar a etapa.', 'danger');
        }

        registrarAuditoria(
            $pdo,
            'Legalização',
            $acao === 'avancar_etapa' ? 'avancar' : 'voltar',
            'processo',
            $processoId,
            ($acao === 'avancar_etapa' ? 'Avançou' : 'Voltou') . ' etapa do processo',
            ['etapa' => $etapaAtual['nome']],
            ['etapa' => $novaEtapa['nome']]
        );

        legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Etapa atualizada com sucesso.');
    }

    if ($acao === 'atualizar_checklist') {
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        $statusChecklist = $_POST['status'] ?? 'pendente';

        if (!in_array($statusChecklist, ['pendente', 'recebido', 'dispensado'], true)) {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Status do checklist inválido.', 'danger');
        }

        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM legalizacao_checklist
            WHERE id = ? AND processo_id = ?
        ");
        $stmtAntes->execute([$checklistId, $processoId]);
        $antesChecklist = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$antesChecklist) {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Item do checklist não encontrado.', 'danger');
        }

        $stmt = $pdo->prepare("
            UPDATE legalizacao_checklist
            SET status = ?, atualizado_em = NOW()
            WHERE id = ? AND processo_id = ?
        ");
        $stmt->execute([$statusChecklist, $checklistId, $processoId]);

        legalizacaoRegistrarHistorico(
            $pdo,
            $processoId,
            'checklist',
            'Checklist "' . $antesChecklist['item'] . '" marcado como ' . legalizacaoStatusChecklist($statusChecklist) . '.'
        );

        legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Checklist atualizado.');
    }

    if ($acao === 'adicionar_historico') {
        $descricao = trim($_POST['descricao'] ?? '');

        if ($descricao === '') {
            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Escreva a observação antes de salvar.', 'danger');
        }

        legalizacaoRegistrarHistorico($pdo, $processoId, 'observacao', $descricao);
        registrarAuditoria($pdo, 'Legalização', 'observacao', 'processo', $processoId, 'Adicionou observação ao processo', null, ['descricao' => $descricao]);
        legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Observação adicionada.');
    }

    legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Ação inválida.', 'danger');
}

$processo = legalizacaoBuscarProcesso($pdo, $processoId);
$mensagem = legalizacaoObterFlash();

$stmt = $pdo->prepare("
    SELECT *
    FROM legalizacao_etapas
    WHERE processo_id = ?
    ORDER BY ordem
");
$stmt->execute([$processoId]);
$etapas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *
    FROM legalizacao_checklist
    WHERE processo_id = ?
    ORDER BY id
");
$stmt->execute([$processoId]);
$checklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *
    FROM legalizacao_historico
    WHERE processo_id = ?
    ORDER BY criado_em DESC, id DESC
");
$stmt->execute([$processoId]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

$prazoInfo = legalizacaoPrazoTexto($processo['prazo'], $processo['status']);
$pendentesChecklist = array_values(array_filter($checklist, static fn(array $item): bool => $item['status'] === 'pendente'));
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Processo de Legalização</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/legalizacao.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars(legalizacaoTextoTipo($processo['tipo'])) ?></h3>
                    <p class="text-muted mb-0">
                        <?= htmlspecialchars(($processo['cliente_codigo'] ? $processo['cliente_codigo'] . ' - ' : '') . $processo['cliente_nome']) ?>
                    </p>
                </div>

                <div class="d-flex gap-2">
                    <a href="legalizacao.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <form method="post">
                        <input type="hidden" name="id" value="<?= $processoId ?>">
                        <input type="hidden" name="acao" value="voltar_etapa">
                        <button type="submit" class="btn btn-outline-secondary" <?= (int)$processo['etapa_atual_ordem'] <= 1 ? 'disabled' : '' ?>>
                            <i class="bi bi-chevron-left"></i> Voltar etapa
                        </button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="id" value="<?= $processoId ?>">
                        <input type="hidden" name="acao" value="avancar_etapa">
                        <button type="submit" class="btn btn-primary" <?= $processo['status'] === 'concluido' ? 'disabled' : '' ?>>
                            Avançar etapa <i class="bi bi-chevron-right"></i>
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <section class="legalizacao-detalhes mb-4">
                <div class="legalizacao-card legalizacao-card-principal">
                    <div>
                        <span>Etapa atual</span>
                        <strong><?= htmlspecialchars($processo['etapa_atual_nome']) ?></strong>
                    </div>
                    <div>
                        <span>Status</span>
                        <strong>
                            <span class="badge <?= htmlspecialchars(legalizacaoClasseStatus($processo['status'])) ?>">
                                <?= htmlspecialchars(legalizacaoTextoStatus($processo['status'])) ?>
                            </span>
                        </strong>
                    </div>
                    <div>
                        <span>Prazo</span>
                        <strong class="legalizacao-prazo <?= htmlspecialchars($prazoInfo['classe']) ?>">
                            <?= htmlspecialchars($prazoInfo['texto']) ?>
                        </strong>
                        <small><?= legalizacaoFormatarData($processo['prazo']) ?></small>
                    </div>
                    <div>
                        <span>Responsável</span>
                        <strong><?= htmlspecialchars($processo['responsavel_nome']) ?></strong>
                    </div>
                    <div>
                        <span>Checklist</span>
                        <strong><?= count($pendentesChecklist) ?> pendente<?= count($pendentesChecklist) === 1 ? '' : 's' ?></strong>
                    </div>
                </div>
            </section>

            <section class="legalizacao-painel mb-4">
                <div class="legalizacao-painel-titulo">
                    <div>
                        <h5 class="mb-1">Fluxo do processo</h5>
                        <p class="text-muted small mb-0">Movimente o processo entre as etapas</p>
                    </div>
                </div>

                <div class="legalizacao-etapas">
                    <?php foreach ($etapas as $etapa): ?>
                        <div class="legalizacao-etapa legalizacao-etapa-<?= htmlspecialchars($etapa['status']) ?>">
                            <span><?= (int)$etapa['ordem'] ?></span>
                            <strong><?= htmlspecialchars($etapa['nome']) ?></strong>
                            <small><?= htmlspecialchars($etapa['status'] === 'concluida' ? 'Concluída' : ($etapa['status'] === 'atual' ? 'Atual' : 'Pendente')) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="legalizacao-grid">
                <section class="legalizacao-painel">
                    <div class="legalizacao-painel-titulo">
                        <div>
                            <h5 class="mb-1">Dados do processo</h5>
                            <p class="text-muted small mb-0">Prazo, status, responsável e observações</p>
                        </div>
                    </div>

                    <form method="post" class="p-3">
                        <input type="hidden" name="id" value="<?= $processoId ?>">
                        <input type="hidden" name="acao" value="atualizar_dados">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Solicitado em</label>
                                <input type="text" class="form-control" value="<?= legalizacaoFormatarData($processo['solicitado_em']) ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="prazoProcesso">Prazo</label>
                                <input type="date" class="form-control" name="prazo" id="prazoProcesso" value="<?= htmlspecialchars($processo['prazo'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="statusProcesso">Status</label>
                                <select class="form-select" name="status" id="statusProcesso">
                                    <?php foreach (['em_andamento', 'pendente_cliente', 'pendente_orgao', 'pausado', 'concluido', 'cancelado'] as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $processo['status'] === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(legalizacaoTextoStatus($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="responsavelProcesso">Responsável</label>
                                <select class="form-select" name="responsavel_id" id="responsavelProcesso">
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?= (int)$usuario['id'] ?>" <?= (int)$processo['responsavel_id'] === (int)$usuario['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($usuario['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="contatoCliente">Contato do cliente</label>
                                <input type="text" class="form-control" name="contato_cliente" id="contatoCliente" value="<?= htmlspecialchars($processo['contato_cliente'] ?? '') ?>">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="observacoesProcesso">Observações</label>
                                <textarea class="form-control" name="observacoes" id="observacoesProcesso" rows="4"><?= htmlspecialchars($processo['observacoes'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg"></i> Salvar dados
                            </button>
                        </div>
                    </form>
                </section>

                <section class="legalizacao-painel">
                    <div class="legalizacao-painel-titulo">
                        <div>
                            <h5 class="mb-1">Checklist</h5>
                            <p class="text-muted small mb-0">Itens pendentes, recebidos ou dispensados</p>
                        </div>
                    </div>

                    <div class="legalizacao-checklist">
                        <?php foreach ($checklist as $item): ?>
                            <form method="post" class="legalizacao-checklist-item">
                                <input type="hidden" name="id" value="<?= $processoId ?>">
                                <input type="hidden" name="acao" value="atualizar_checklist">
                                <input type="hidden" name="checklist_id" value="<?= (int)$item['id'] ?>">
                                <div>
                                    <strong><?= htmlspecialchars($item['item']) ?></strong>
                                    <span class="badge <?= htmlspecialchars(legalizacaoClasseChecklist($item['status'])) ?>">
                                        <?= htmlspecialchars(legalizacaoStatusChecklist($item['status'])) ?>
                                    </span>
                                </div>
                                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                    <?php foreach (['pendente', 'recebido', 'dispensado'] as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $item['status'] === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(legalizacaoStatusChecklist($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="legalizacao-painel mt-4">
                <div class="legalizacao-painel-titulo">
                    <div>
                        <h5 class="mb-1">Histórico</h5>
                        <p class="text-muted small mb-0">Nada é apagado, tudo fica registrado</p>
                    </div>
                </div>

                <form method="post" class="legalizacao-historico-form">
                    <input type="hidden" name="id" value="<?= $processoId ?>">
                    <input type="hidden" name="acao" value="adicionar_historico">
                    <input type="text" class="form-control" name="descricao" placeholder="Adicionar observação ao histórico...">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Adicionar
                    </button>
                </form>

                <div class="legalizacao-historico">
                    <?php if ($historico === []): ?>
                        <div class="legalizacao-vazio">Nenhum histórico registrado.</div>
                    <?php endif; ?>

                    <?php foreach ($historico as $evento): ?>
                        <div class="legalizacao-historico-item">
                            <span><?= legalizacaoFormatarDataHora($evento['criado_em']) ?></span>
                            <strong><?= htmlspecialchars($evento['usuario_nome']) ?></strong>
                            <p><?= htmlspecialchars($evento['descricao']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</body>

</html>