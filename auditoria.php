<?php
require 'config.php';

exigirLogin();

if (!usuarioEhAdmin()) {
    http_response_code(403);
    exit('Acesso permitido somente para administradores.');
}

$tabelaDisponivel = auditoriaTabelaDisponivel($pdo);
$registros = [];
$usuariosFiltro = [];
$modulosFiltro = [];
$totalRegistros = 0;
$totalHoje = 0;
$totalSeteDias = 0;
$porPagina = 15;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$inicio = trim($_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days')));
$fim = trim($_GET['fim'] ?? date('Y-m-d'));
$usuario = trim($_GET['usuario'] ?? '');
$modulo = trim($_GET['modulo'] ?? '');
$acao = trim($_GET['acao'] ?? '');
$busca = trim($_GET['busca'] ?? '');

function auditoriaDataValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('!Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function auditoriaDadosFormatados(?string $json): string
{
    if (empty($json)) {
        return 'Nenhum dado registrado.';
    }

    $dados = json_decode($json, true);

    if (!is_array($dados) || $dados === []) {
        return 'Nenhum dado registrado.';
    }

    return json_encode(
        $dados,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function classeAcaoAuditoria(string $acao): string
{
    if (in_array($acao, ['criar', 'criar_parcelas', 'login', 'reativar', 'reabrir'], true)) {
        return 'bg-success';
    }

    if (in_array($acao, ['excluir', 'cancelar', 'login_falhou'], true)) {
        return 'bg-danger';
    }

    if (in_array($acao, ['pagar', 'pagar_fatura', 'quitar', 'resolver'], true)) {
        return 'bg-primary';
    }

    if (str_contains($acao, 'automaticamente')) {
        return 'bg-info text-dark';
    }

    return 'bg-warning text-dark';
}

if (!auditoriaDataValida($inicio)) {
    $inicio = date('Y-m-d', strtotime('-30 days'));
}

if (!auditoriaDataValida($fim)) {
    $fim = date('Y-m-d');
}

if ($tabelaDisponivel) {
    $usuariosFiltro = $pdo->query("
        SELECT DISTINCT usuario_id, usuario_nome
        FROM auditoria_logs
        ORDER BY usuario_nome
    ")->fetchAll(PDO::FETCH_ASSOC);

    $modulosFiltro = $pdo->query("
        SELECT DISTINCT modulo
        FROM auditoria_logs
        ORDER BY modulo
    ")->fetchAll(PDO::FETCH_COLUMN);

    $condicoes = ['criado_em >= ?', 'criado_em < DATE_ADD(?, INTERVAL 1 DAY)'];
    $parametros = [$inicio, $fim];

    if ($usuario !== '') {
        $condicoes[] = 'usuario_id = ?';
        $parametros[] = (int)$usuario;
    }

    if ($modulo !== '') {
        $condicoes[] = 'modulo = ?';
        $parametros[] = $modulo;
    }

    if ($acao !== '') {
        $condicoes[] = 'acao = ?';
        $parametros[] = $acao;
    }

    if ($busca !== '') {
        $condicoes[] = '(descricao LIKE ? OR usuario_nome LIKE ? OR entidade LIKE ? OR entidade_id LIKE ?)';
        $termo = '%' . $busca . '%';
        array_push($parametros, $termo, $termo, $termo, $termo);
    }

    $where = implode(' AND ', $condicoes);
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM auditoria_logs WHERE {$where}");
    $stmtTotal->execute($parametros);
    $totalRegistros = (int)$stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $pdo->prepare("
        SELECT *
        FROM auditoria_logs
        WHERE {$where}
        ORDER BY criado_em DESC, id DESC
        LIMIT {$porPagina} OFFSET {$offset}
    ");
    $stmt->execute($parametros);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHoje = (int)$pdo->query("
        SELECT COUNT(*) FROM auditoria_logs WHERE criado_em >= CURDATE()
    ")->fetchColumn();
    $totalSeteDias = (int)$pdo->query("
        SELECT COUNT(*) FROM auditoria_logs WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn();
} else {
    $totalPaginas = 1;
}

$acoesFiltro = [
    'criar' => 'Criar',
    'criar_parcelas' => 'Criar parcelas',
    'editar' => 'Editar',
    'excluir' => 'Excluir',
    'cancelar' => 'Cancelar',
    'reativar' => 'Reativar',
    'reabrir' => 'Reabrir',
    'resolver' => 'Resolver',
    'pagar' => 'Pagar',
    'pagar_fatura' => 'Pagar fatura',
    'quitar' => 'Quitar',
    'login' => 'Entrar',
    'logout' => 'Sair',
    'login_falhou' => 'Login inválido',
    'liquidar_automaticamente' => 'Liquidação automática',
];
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Auditoria</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/auditoria.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Auditoria do sistema</h3>
                    <p class="text-muted mb-0">Histórico de acessos e alterações realizadas pelos usuários</p>
                </div>
                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (!$tabelaDisponivel): ?>
                <div class="alert alert-warning">
                    <strong>Auditoria ainda não ativada.</strong>
                    Execute o SQL da tabela <code>auditoria_logs</code> no banco.
                </div>
            <?php else: ?>
                <div class="auditoria-resumo mb-4">
                    <div>
                        <span>Hoje</span>
                        <strong><?= $totalHoje ?></strong>
                    </div>
                    <div>
                        <span>Últimos 7 dias</span>
                        <strong><?= $totalSeteDias ?></strong>
                    </div>
                    <div>
                        <span>Resultado do filtro</span>
                        <strong><?= $totalRegistros ?></strong>
                    </div>
                </div>

                <section class="auditoria-filtros mb-4">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label for="auditoriaInicio" class="form-label">Data inicial</label>
                            <input type="date" class="form-control" name="inicio" id="auditoriaInicio" value="<?= htmlspecialchars($inicio) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="auditoriaFim" class="form-label">Data final</label>
                            <input type="date" class="form-control" name="fim" id="auditoriaFim" value="<?= htmlspecialchars($fim) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="auditoriaUsuario" class="form-label">Usuário</label>
                            <select class="form-select" name="usuario" id="auditoriaUsuario">
                                <option value="">Todos</option>
                                <?php foreach ($usuariosFiltro as $usuarioFiltro): ?>
                                    <option value="<?= (int)$usuarioFiltro['usuario_id'] ?>" <?= $usuario === (string)$usuarioFiltro['usuario_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($usuarioFiltro['usuario_nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="auditoriaModulo" class="form-label">Módulo</label>
                            <select class="form-select" name="modulo" id="auditoriaModulo">
                                <option value="">Todos</option>
                                <?php foreach ($modulosFiltro as $moduloFiltro): ?>
                                    <option value="<?= htmlspecialchars($moduloFiltro) ?>" <?= $modulo === $moduloFiltro ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($moduloFiltro) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="auditoriaAcao" class="form-label">Ação</label>
                            <select class="form-select" name="acao" id="auditoriaAcao">
                                <option value="">Todas</option>
                                <?php foreach ($acoesFiltro as $valorAcao => $textoAcao): ?>
                                    <option value="<?= htmlspecialchars($valorAcao) ?>" <?= $acao === $valorAcao ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($textoAcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="auditoriaBusca" class="form-label">Buscar</label>
                            <input type="search" class="form-control" name="busca" id="auditoriaBusca" value="<?= htmlspecialchars($busca) ?>" placeholder="Cliente, ação...">
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="auditoria.php" class="btn btn-outline-secondary">Limpar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="auditoria-tabela">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Data e hora</th>
                                    <th>Usuário</th>
                                    <th>Módulo</th>
                                    <th>Ação</th>
                                    <th>Descrição</th>
                                    <th>IP</th>
                                    <th class="text-end">Detalhes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($registros === []): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            Nenhum registro encontrado neste período.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($registros as $registro): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= date('d/m/Y H:i:s', strtotime($registro['criado_em'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($registro['usuario_nome']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($registro['modulo']) ?></td>
                                        <td>
                                            <span class="badge <?= classeAcaoAuditoria($registro['acao']) ?>">
                                                <?= htmlspecialchars($acoesFiltro[$registro['acao']] ?? ucfirst(str_replace('_', ' ', $registro['acao']))) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($registro['descricao']) ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars($registro['endereco_ip'] ?: '-') ?></td>
                                        <td class="text-end">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalAuditoria<?= (int)$registro['id'] ?>"
                                                title="Ver alterações">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if ($totalPaginas > 1): ?>
                    <nav class="mt-4" aria-label="Paginação da auditoria">
                        <ul class="pagination justify-content-center">
                            <?php
                            $parametrosAnterior = $_GET;
                            $parametrosAnterior['pagina'] = max(1, $pagina - 1);
                            ?>
                            <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosAnterior)) ?>">
                                    Anterior
                                </a>
                            </li>

                            <?php for ($numeroPagina = 1; $numeroPagina <= $totalPaginas; $numeroPagina++):
                                $parametrosPagina = $_GET;
                                $parametrosPagina['pagina'] = $numeroPagina;
                            ?>
                                <li class="page-item <?= $numeroPagina === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosPagina)) ?>">
                                        <?= $numeroPagina ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php
                            $parametrosProxima = $_GET;
                            $parametrosProxima['pagina'] = min($totalPaginas, $pagina + 1);
                            ?>
                            <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query($parametrosProxima)) ?>">
                                    Próxima
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php foreach ($registros as $registro): ?>
        <div class="modal fade" id="modalAuditoria<?= (int)$registro['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Detalhes da auditoria</h5>
                            <small class="text-muted">
                                <?= htmlspecialchars($registro['usuario_nome']) ?> ·
                                <?= date('d/m/Y H:i:s', strtotime($registro['criado_em'])) ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="row auditoria-identificacao">
                            <dt class="col-sm-2">Módulo</dt>
                            <dd class="col-sm-4"><?= htmlspecialchars($registro['modulo']) ?></dd>
                            <dt class="col-sm-2">Ação</dt>
                            <dd class="col-sm-4"><?= htmlspecialchars($acoesFiltro[$registro['acao']] ?? $registro['acao']) ?></dd>
                            <dt class="col-sm-2">Registro</dt>
                            <dd class="col-sm-4"><?= htmlspecialchars($registro['entidade']) ?> #<?= htmlspecialchars($registro['entidade_id'] ?? '-') ?></dd>
                            <dt class="col-sm-2">IP</dt>
                            <dd class="col-sm-4"><?= htmlspecialchars($registro['endereco_ip'] ?: '-') ?></dd>
                            <dt class="col-sm-2">Descrição</dt>
                            <dd class="col-sm-10"><?= htmlspecialchars($registro['descricao']) ?></dd>
                        </dl>

                        <div class="auditoria-comparacao">
                            <div>
                                <h6>Antes</h6>
                                <pre><?= htmlspecialchars(auditoriaDadosFormatados($registro['dados_antes'])) ?></pre>
                            </div>
                            <div>
                                <h6>Depois</h6>
                                <pre><?= htmlspecialchars(auditoriaDadosFormatados($registro['dados_depois'])) ?></pre>
                            </div>
                        </div>

                        <div class="mt-3 small text-muted">
                            Navegador: <?= htmlspecialchars($registro['user_agent'] ?: '-') ?><br>
                            Página: <?= htmlspecialchars($registro['url'] ?: '-') ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>