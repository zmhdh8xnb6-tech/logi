<?php
require 'config.php';
require 'includes/legalizacao_funcoes.php';

exigirPermissao('legalizacao');

$tabelasDisponiveis = legalizacaoTabelasDisponiveis($pdo);
$mensagem = legalizacaoObterFlash();
$tiposProcesso = legalizacaoTiposProcesso();
$clientes = $tabelasDisponiveis ? legalizacaoListarClientes($pdo) : [];
$usuarios = $tabelasDisponiveis ? legalizacaoListarUsuarios($pdo) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tabelasDisponiveis) {
        legalizacaoRedirect('legalizacao.php', 'Execute o SQL da legalização antes de cadastrar.', 'danger');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_processo') {
        $clienteId = (int)($_POST['cliente_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $responsavelId = (int)($_POST['responsavel_id'] ?? 0);
        $solicitadoEm = $_POST['solicitado_em'] ?? date('Y-m-d');
        $prazo = trim($_POST['prazo'] ?? '');
        $contatoCliente = trim($_POST['contato_cliente'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');

        if (
            $clienteId <= 0
            || !array_key_exists($tipo, $tiposProcesso)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $solicitadoEm)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)
            || $responsavelId <= 0
            || $contatoCliente === ''
            || $observacoes === ''
        ) {
            legalizacaoRedirect('legalizacao.php', 'Preencha todos os campos obrigatórios do processo.', 'danger');
        }

        $cliente = legalizacaoBuscarCliente($pdo, $clienteId);

        if (!$cliente) {
            legalizacaoRedirect('legalizacao.php', 'Cliente não encontrado.', 'danger');
        }

        $responsavelNome = $_SESSION['usuario_nome'] ?? 'Usuário';

        if ($responsavelId > 0) {
            foreach ($usuarios as $usuario) {
                if ((int)$usuario['id'] === $responsavelId) {
                    $responsavelNome = $usuario['nome'];
                    break;
                }
            }
        } else {
            $responsavelId = (int)($_SESSION['usuario_id'] ?? 0);
        }

        $fluxo = legalizacaoFluxoPorTipoECliente($tipo, $cliente);
        $etapaInicial = $fluxo['etapas'][0] ?? 'Novo processo';
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO legalizacao_processos (
                    cliente_id,
                    cliente_codigo,
                    cliente_nome,
                    cliente_documento,
                    tipo,
                    etapa_atual_ordem,
                    etapa_atual_nome,
                    status,
                    solicitado_em,
                    prazo,
                    responsavel_id,
                    responsavel_nome,
                    contato_cliente,
                    observacoes,
                    criado_por,
                    criado_em,
                    atualizado_em
                )
                VALUES (?, ?, ?, ?, ?, 1, ?, 'em_andamento', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $clienteId,
                $cliente['codigo'] ?? '',
                $cliente['nome'] ?? '',
                $cliente['documento'] ?? '',
                $tipo,
                $etapaInicial,
                $solicitadoEm,
                $prazo,
                $responsavelId,
                $responsavelNome,
                $contatoCliente,
                $observacoes,
                (int)($_SESSION['usuario_id'] ?? 0),
            ]);
            $processoId = (int)$pdo->lastInsertId();

            $stmtEtapa = $pdo->prepare("
                INSERT INTO legalizacao_etapas (
                    processo_id,
                    ordem,
                    nome,
                    status,
                    iniciado_em,
                    concluido_em
                )
                VALUES (?, ?, ?, ?, ?, NULL)
            ");

            foreach ($fluxo['etapas'] as $indice => $nomeEtapa) {
                $ordem = $indice + 1;
                $stmtEtapa->execute([
                    $processoId,
                    $ordem,
                    $nomeEtapa,
                    $ordem === 1 ? 'atual' : 'pendente',
                    $ordem === 1 ? date('Y-m-d H:i:s') : null,
                ]);
            }

            $stmtChecklist = $pdo->prepare("
                INSERT INTO legalizacao_checklist (
                    processo_id,
                    item,
                    status,
                    atualizado_em
                )
                VALUES (?, ?, 'pendente', NOW())
            ");

            foreach ($fluxo['checklist'] as $item) {
                $stmtChecklist->execute([$processoId, $item]);
            }

            legalizacaoRegistrarHistorico($pdo, $processoId, 'criar', 'Processo criado na etapa "' . $etapaInicial . '".');
            $pdo->commit();

            registrarAuditoria(
                $pdo,
                'Legalização',
                'criar',
                'processo',
                $processoId,
                'Criou processo de ' . legalizacaoTextoTipo($tipo) . ' para ' . ($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? ''),
                null,
                ['id' => $processoId, 'tipo' => $tipo, 'cliente_id' => $clienteId]
            );

            legalizacaoRedirect('legalizacao_processo.php?id=' . $processoId, 'Processo criado com sucesso.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            legalizacaoRedirect('legalizacao.php', 'Não foi possível criar o processo.', 'danger');
        }
    }

    legalizacaoRedirect('legalizacao.php', 'Ação inválida.', 'danger');
}

$filtroBusca = trim($_GET['busca'] ?? '');
$filtroTipo = $_GET['tipo'] ?? '';
$filtroStatus = $_GET['status'] ?? '';
$filtroResponsavel = (int)($_GET['responsavel'] ?? 0);
$processos = [];
$processosPorPagina = 15;
$paginaProcessos = max(1, (int)($_GET['pagina'] ?? 1));
$totalProcessos = 0;
$totalPaginasProcessos = 1;
$resumo = [
    'em_andamento' => 0,
    'pendente_cliente' => 0,
    'pendente_orgao' => 0,
    'vencidos' => 0,
    'concluidos_hoje' => 0,
];

if ($tabelasDisponiveis) {
    $where = ['1=1'];
    $params = [];

    if ($filtroBusca !== '') {
        $where[] = "(p.cliente_codigo LIKE ? OR p.cliente_nome LIKE ? OR p.cliente_documento LIKE ? OR p.etapa_atual_nome LIKE ?)";
        $like = '%' . $filtroBusca . '%';
        array_push($params, $like, $like, $like, $like);
    }

    if ($filtroTipo !== '' && array_key_exists($filtroTipo, $tiposProcesso)) {
        $where[] = 'p.tipo = ?';
        $params[] = $filtroTipo;
    }

    if ($filtroStatus !== '') {
        $where[] = 'p.status = ?';
        $params[] = $filtroStatus;
    }

    if ($filtroResponsavel > 0) {
        $where[] = 'p.responsavel_id = ?';
        $params[] = $filtroResponsavel;
    }

    $stmtTotalProcessos = $pdo->prepare("
        SELECT COUNT(*)
        FROM legalizacao_processos p
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmtTotalProcessos->execute($params);
    $totalProcessos = (int)$stmtTotalProcessos->fetchColumn();
    $totalPaginasProcessos = max(1, (int)ceil($totalProcessos / $processosPorPagina));
    $paginaProcessos = min($paginaProcessos, $totalPaginasProcessos);
    $offsetProcessos = ($paginaProcessos - 1) * $processosPorPagina;

    $stmt = $pdo->prepare("
        SELECT p.*,
               SUM(CASE WHEN ck.status = 'pendente' THEN 1 ELSE 0 END) AS checklist_pendente,
               COUNT(ck.id) AS checklist_total
        FROM legalizacao_processos p
        LEFT JOIN legalizacao_checklist ck ON ck.processo_id = p.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY p.id
        ORDER BY
            FIELD(p.status, 'pendente_cliente', 'pendente_orgao', 'em_andamento', 'pausado', 'concluido', 'cancelado'),
            p.prazo IS NULL,
            p.prazo ASC,
            p.atualizado_em DESC
        LIMIT {$processosPorPagina} OFFSET {$offsetProcessos}
    ");
    $stmt->execute($params);
    $processos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtResumo = $pdo->query("
        SELECT
            SUM(status = 'em_andamento') AS em_andamento,
            SUM(status = 'pendente_cliente') AS pendente_cliente,
            SUM(status = 'pendente_orgao') AS pendente_orgao,
            SUM(status <> 'concluido' AND status <> 'cancelado' AND prazo IS NOT NULL AND prazo < CURDATE()) AS vencidos,
            SUM(status = 'concluido' AND DATE(concluido_em) = CURDATE()) AS concluidos_hoje
        FROM legalizacao_processos
    ");
    $resumoBanco = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($resumo as $chave => $valor) {
        $resumo[$chave] = (int)($resumoBanco[$chave] ?? 0);
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Legalização</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/legalizacao.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Legalização</h3>
                    <p class="text-muted mb-0">Controle de processos, etapas, checklist e histórico</p>
                </div>

                <div class="d-flex gap-2">
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#modalNovoProcesso"
                        <?= $tabelasDisponiveis ? '' : 'disabled' ?>>
                        <i class="bi bi-plus-circle"></i> Novo processo
                    </button>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$tabelasDisponiveis): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o SQL da legalização no phpMyAdmin e atualize esta página.
                </div>
            <?php else: ?>
                <section class="legalizacao-resumo mb-4">
                    <div class="legalizacao-metrica">
                        <span>Em andamento</span>
                        <strong><?= $resumo['em_andamento'] ?></strong>
                    </div>
                    <div class="legalizacao-metrica metrica-alerta">
                        <span>Pendentes cliente</span>
                        <strong><?= $resumo['pendente_cliente'] ?></strong>
                    </div>
                    <div class="legalizacao-metrica metrica-orgao">
                        <span>Pendentes órgão</span>
                        <strong><?= $resumo['pendente_orgao'] ?></strong>
                    </div>
                    <div class="legalizacao-metrica metrica-urgente">
                        <span>Prazo vencido</span>
                        <strong><?= $resumo['vencidos'] ?></strong>
                    </div>
                    <div class="legalizacao-metrica metrica-ok">
                        <span>Concluídos hoje</span>
                        <strong><?= $resumo['concluidos_hoje'] ?></strong>
                    </div>
                </section>

                <section class="legalizacao-painel">
                    <form method="get" class="legalizacao-filtros">
                        <div class="legalizacao-campo-busca">
                            <i class="bi bi-search"></i>
                            <input
                                type="search"
                                class="form-control"
                                name="busca"
                                value="<?= htmlspecialchars($filtroBusca) ?>"
                                placeholder="Buscar empresa, CNPJ, etapa...">
                        </div>
                        <select class="form-select" name="tipo">
                            <option value="">Todos os tipos</option>
                            <?php foreach ($tiposProcesso as $valor => $texto): ?>
                                <option value="<?= htmlspecialchars($valor) ?>" <?= $filtroTipo === $valor ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($texto) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="status">
                            <option value="">Todos os status</option>
                            <?php foreach (['em_andamento', 'pendente_cliente', 'pendente_orgao', 'pausado', 'concluido', 'cancelado'] as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $filtroStatus === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(legalizacaoTextoStatus($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="responsavel">
                            <option value="0">Todos os responsáveis</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= (int)$usuario['id'] ?>" <?= $filtroResponsavel === (int)$usuario['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($usuario['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Filtrar
                        </button>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle legalizacao-tabela">
                            <thead>
                                <tr>
                                    <th>Empresa</th>
                                    <th>Tipo</th>
                                    <th>Etapa atual</th>
                                    <th>Responsável</th>
                                    <th>Prazo</th>
                                    <th>Checklist</th>
                                    <th>Status</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($processos === []): ?>
                                    <tr>
                                        <td colspan="8" class="legalizacao-vazio">Nenhum processo encontrado.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($processos as $processo):
                                    $prazoInfo = legalizacaoPrazoTexto($processo['prazo'], $processo['status']);
                                    $processoVencido = $processo['prazo']
                                        && !in_array($processo['status'], ['concluido', 'cancelado'], true)
                                        && $processo['prazo'] < date('Y-m-d');
                                    $statusClasse = $processoVencido ? 'bg-danger' : legalizacaoClasseStatus($processo['status']);
                                    $statusTexto = $processoVencido ? $prazoInfo['texto'] : legalizacaoTextoStatus($processo['status']);
                                    $checklistPendente = (int)($processo['checklist_pendente'] ?? 0);
                                    $checklistTotal = (int)($processo['checklist_total'] ?? 0);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars(($processo['cliente_codigo'] ? $processo['cliente_codigo'] . ' - ' : '') . $processo['cliente_nome']) ?></strong>
                                            <small><?= htmlspecialchars($processo['cliente_documento'] ?: '-') ?></small>
                                        </td>
                                        <td><?= htmlspecialchars(legalizacaoTextoTipo($processo['tipo'])) ?></td>
                                        <td><?= htmlspecialchars($processo['etapa_atual_nome']) ?></td>
                                        <td><?= htmlspecialchars($processo['responsavel_nome']) ?></td>
                                        <td>
                                            <span class="legalizacao-prazo legalizacao-prazo-neutro">
                                                <?= legalizacaoFormatarData($processo['prazo']) ?>
                                            </span>
                                            <?php if ($processo['prazo'] && !$processoVencido): ?>
                                                <small><?= htmlspecialchars($prazoInfo['texto']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $checklistPendente > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
                                                <?= $checklistPendente ?> pendente<?= $checklistPendente === 1 ? '' : 's' ?>
                                            </span>
                                            <small><?= $checklistTotal ?> itens</small>
                                        </td>
                                        <td>
                                            <span class="badge <?= htmlspecialchars($statusClasse) ?>">
                                                <?= htmlspecialchars($statusTexto) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="legalizacao_processo.php?id=<?= (int)$processo['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPaginasProcessos > 1): ?>
                        <nav class="mt-4" aria-label="Paginação dos processos">
                            <ul class="pagination justify-content-center">
                                <?php
                                $parametrosAnterior = $_GET;
                                $parametrosAnterior['pagina'] = max(1, $paginaProcessos - 1);
                                ?>
                                <li class="page-item <?= $paginaProcessos <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosAnterior)) ?>">Anterior</a>
                                </li>

                                <?php for ($numeroPagina = 1; $numeroPagina <= $totalPaginasProcessos; $numeroPagina++):
                                    $parametrosPagina = $_GET;
                                    $parametrosPagina['pagina'] = $numeroPagina;
                                ?>
                                    <li class="page-item <?= $numeroPagina === $paginaProcessos ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosPagina)) ?>">
                                            <?= $numeroPagina ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php
                                $parametrosProxima = $_GET;
                                $parametrosProxima['pagina'] = min($totalPaginasProcessos, $paginaProcessos + 1);
                                ?>
                                <li class="page-item <?= $paginaProcessos >= $totalPaginasProcessos ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosProxima)) ?>">Próxima</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($tabelasDisponiveis): ?>
        <div class="modal fade" id="modalNovoProcesso" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="post" class="needs-validation" novalidate>
                        <input type="hidden" name="acao" value="criar_processo">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Novo processo</h5>
                                <p class="text-muted small mb-0">O fluxo e o checklist serão criados automaticamente</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label">Empresa</label>
                                    <div class="cliente-seletor" id="clienteSeletorLegalizacao">
                                        <i class="bi bi-search cliente-seletor-icone"></i>
                                        <input
                                            type="search"
                                            class="form-control cliente-seletor-input"
                                            id="clienteBuscaLegalizacao"
                                            placeholder="Digite o código ou a razão social"
                                            autocomplete="off"
                                            aria-haspopup="listbox"
                                            aria-expanded="false">

                                        <div class="cliente-seletor-menu d-none" id="clienteSeletorLegalizacaoMenu">
                                            <div class="cliente-seletor-opcoes" id="clienteOpcoesLegalizacao" role="listbox">
                                                <?php foreach ($clientes as $cliente):
                                                    $textoCliente = trim(($cliente['codigo'] ? $cliente['codigo'] . ' - ' : '') . $cliente['nome']);
                                                ?>
                                                    <button
                                                        type="button"
                                                        class="cliente-seletor-opcao"
                                                        data-id="<?= (int)$cliente['id'] ?>"
                                                        data-texto="<?= htmlspecialchars($textoCliente) ?>"
                                                        role="option"
                                                        aria-selected="false">
                                                        <strong><?= htmlspecialchars($cliente['codigo'] ?: '-') ?></strong>
                                                        <span><?= htmlspecialchars($cliente['nome']) ?></span>
                                                    </button>
                                                <?php endforeach; ?>

                                                <div class="cliente-seletor-vazio d-none" id="clienteVazioLegalizacao">
                                                    Nenhum cliente encontrado.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="cliente_id" id="clienteProcesso" value="">
                                    <div class="invalid-feedback" id="clienteFeedbackLegalizacao">Selecione uma empresa da lista.</div>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="tipoProcesso">Tipo</label>
                                    <select class="form-select" name="tipo" id="tipoProcesso" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($tiposProcesso as $valor => $texto): ?>
                                            <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($texto) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Selecione o tipo.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="solicitadoEm">Solicitado em</label>
                                    <input type="date" class="form-control" name="solicitado_em" id="solicitadoEm" value="<?= date('Y-m-d') ?>" required>
                                    <div class="invalid-feedback">Informe a data.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="prazoProcesso">Prazo</label>
                                    <input type="date" class="form-control" name="prazo" id="prazoProcesso" required>
                                    <div class="invalid-feedback">Informe o prazo.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="responsavelProcesso">Responsável</label>
                                    <select class="form-select" name="responsavel_id" id="responsavelProcesso" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <option value="<?= (int)$usuario['id'] ?>" <?= (int)$usuario['id'] === (int)($_SESSION['usuario_id'] ?? 0) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($usuario['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Selecione o responsável.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="contatoCliente">Contato do cliente</label>
                                    <input type="text" class="form-control" name="contato_cliente" id="contatoCliente" placeholder="Nome, telefone ou e-mail" required>
                                    <div class="invalid-feedback">Informe o contato do cliente.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="observacoesProcesso">Observações</label>
                                    <input type="text" class="form-control" name="observacoes" id="observacoesProcesso" placeholder="Resumo inicial" required>
                                    <div class="invalid-feedback">Informe uma observação inicial.</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Criar processo
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            const seletorClienteLegalizacao = document.getElementById('clienteSeletorLegalizacao');
            const menuClienteLegalizacao = document.getElementById('clienteSeletorLegalizacaoMenu');
            const campoBuscaClienteLegalizacao = document.getElementById('clienteBuscaLegalizacao');
            const campoClienteLegalizacao = document.getElementById('clienteProcesso');
            const feedbackClienteLegalizacao = document.getElementById('clienteFeedbackLegalizacao');
            const avisoClienteVazioLegalizacao = document.getElementById('clienteVazioLegalizacao');
            const opcoesClientesLegalizacao = Array.from(document.querySelectorAll('#clienteOpcoesLegalizacao .cliente-seletor-opcao'));

            function normalizarBuscaClienteLegalizacao(texto) {
                return String(texto || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim();
            }

            function filtrarClientesLegalizacao() {
                const busca = normalizarBuscaClienteLegalizacao(campoBuscaClienteLegalizacao.value);
                let totalVisivel = 0;

                opcoesClientesLegalizacao.forEach(function(opcao) {
                    const visivel = normalizarBuscaClienteLegalizacao(opcao.dataset.texto).includes(busca);
                    opcao.classList.toggle('d-none', !visivel);
                    totalVisivel += visivel ? 1 : 0;
                });

                avisoClienteVazioLegalizacao.classList.toggle('d-none', totalVisivel > 0);
            }

            function abrirClientesLegalizacao() {
                menuClienteLegalizacao.classList.remove('d-none');
                campoBuscaClienteLegalizacao.setAttribute('aria-expanded', 'true');
                filtrarClientesLegalizacao();
            }

            function fecharClientesLegalizacao() {
                menuClienteLegalizacao.classList.add('d-none');
                campoBuscaClienteLegalizacao.setAttribute('aria-expanded', 'false');
            }

            function selecionarClienteLegalizacao(opcao) {
                campoClienteLegalizacao.value = opcao.dataset.id;
                campoBuscaClienteLegalizacao.value = opcao.dataset.texto;
                campoBuscaClienteLegalizacao.classList.remove('is-invalid');
                feedbackClienteLegalizacao.classList.remove('d-block');

                opcoesClientesLegalizacao.forEach(function(item) {
                    const selecionado = item === opcao;
                    item.classList.toggle('selecionado', selecionado);
                    item.setAttribute('aria-selected', selecionado ? 'true' : 'false');
                });

                fecharClientesLegalizacao();
            }

            campoBuscaClienteLegalizacao?.addEventListener('focus', abrirClientesLegalizacao);

            campoBuscaClienteLegalizacao?.addEventListener('input', function() {
                campoClienteLegalizacao.value = '';
                campoBuscaClienteLegalizacao.classList.remove('is-invalid');
                feedbackClienteLegalizacao.classList.remove('d-block');
                abrirClientesLegalizacao();
            });

            opcoesClientesLegalizacao.forEach(function(opcao) {
                opcao.addEventListener('click', function() {
                    selecionarClienteLegalizacao(opcao);
                });
            });

            document.addEventListener('click', function(evento) {
                if (seletorClienteLegalizacao && !seletorClienteLegalizacao.contains(evento.target)) {
                    fecharClientesLegalizacao();
                }
            });

            campoBuscaClienteLegalizacao?.addEventListener('keydown', function(evento) {
                if (evento.key === 'Escape') {
                    fecharClientesLegalizacao();
                    campoBuscaClienteLegalizacao.blur();
                }
            });

            document.querySelectorAll('.needs-validation').forEach(function(formulario) {
                formulario.addEventListener('submit', function(evento) {
                    const clienteSelecionado = campoClienteLegalizacao?.value.trim() !== '';
                    campoBuscaClienteLegalizacao?.classList.toggle('is-invalid', !clienteSelecionado);
                    feedbackClienteLegalizacao?.classList.toggle('d-block', !clienteSelecionado);

                    if (!formulario.checkValidity() || !clienteSelecionado) {
                        evento.preventDefault();
                        evento.stopPropagation();
                    }

                    formulario.classList.add('was-validated');
                });
            });
        </script>
    <?php endif; ?>
</body>

</html>