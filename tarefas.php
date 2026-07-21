<?php
require 'config.php';

exigirPermissao('tarefas');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$hoje = date('Y-m-d');
$mensagem = $_GET['msg'] ?? '';
$tipoMensagem = $_GET['tipo'] ?? 'success';
$aba = $_GET['aba'] ?? 'hoje';
$abasPermitidas = ['hoje', 'importantes', 'concluidas', 'todas'];
$aba = in_array($aba, $abasPermitidas, true) ? $aba : 'hoje';
$busca = trim($_REQUEST['busca'] ?? '');
$limitesPermitidos = [15, 30, 60, 90];
$limite = (int)($_REQUEST['limite'] ?? 15);
$limite = in_array($limite, $limitesPermitidos, true) ? $limite : 15;
$pagina = max(1, (int)($_REQUEST['pagina'] ?? 1));

function tarefasTabelaExiste(PDO $pdo): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tarefas'");
        $existe = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $existe = false;
    }

    return $existe;
}

function tarefasRedirecionar(string $mensagem, string $tipo = 'success', string $aba = 'hoje'): void
{
    global $busca, $limite, $pagina;

    $parametros = [
        'msg' => $mensagem,
        'tipo' => $tipo,
        'aba' => $aba,
        'limite' => $limite,
        'pagina' => $pagina,
    ];

    if ($busca !== '') {
        $parametros['busca'] = $busca;
    }

    header('Location: tarefas.php?' . http_build_query($parametros));
    exit;
}

function tarefasUrl(array $parametros): string
{
    $parametros = array_filter($parametros, static function ($valor): bool {
        return $valor !== '' && $valor !== null;
    });

    return 'tarefas.php?' . http_build_query($parametros);
}

$sqlTarefas = <<<SQL
CREATE TABLE tarefas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL DEFAULT 1,
    usuario_id INT NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    data_tarefa DATE NOT NULL,
    importante TINYINT(1) NOT NULL DEFAULT 0,
    concluida TINYINT(1) NOT NULL DEFAULT 0,
    concluida_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tarefas_empresa (empresa_id),
    INDEX idx_tarefas_usuario_data (usuario_id, data_tarefa),
    INDEX idx_tarefas_usuario_concluida (usuario_id, concluida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

if (tarefasTabelaExiste($pdo) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $abaRetorno = $_POST['aba'] ?? $aba;
    $abaRetorno = in_array($abaRetorno, $abasPermitidas, true) ? $abaRetorno : 'hoje';

    if ($acao === 'salvar') {
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $dataTarefa = trim($_POST['data_tarefa'] ?? '');
        $importante = isset($_POST['importante']) ? 1 : 0;

        if ($titulo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataTarefa)) {
            tarefasRedirecionar('Preencha o título e a data da tarefa.', 'danger', $abaRetorno);
        }

        if ($id > 0) {
            $stmtAntes = $pdo->prepare("
                SELECT *
                FROM tarefas
                WHERE id = ?
                  AND usuario_id = ?
                  " . empresaFiltro($pdo, 'tarefas') . "
            ");
            $stmtAntes->execute([$id, $usuarioId]);
            $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

            if (!$antes) {
                tarefasRedirecionar('Tarefa não encontrada.', 'danger', $abaRetorno);
            }

            $stmt = $pdo->prepare("
                UPDATE tarefas
                SET titulo = ?, descricao = ?, data_tarefa = ?, importante = ?
                WHERE id = ? AND usuario_id = ?
                " . empresaFiltro($pdo, 'tarefas') . "
            ");
            $stmt->execute([$titulo, $descricao, $dataTarefa, $importante, $id, $usuarioId]);
            registrarAuditoria($pdo, 'Tarefas', 'editar', 'tarefa', $id, 'Editou a tarefa ' . $titulo, $antes, [
                'titulo' => $titulo,
                'descricao' => $descricao,
                'data_tarefa' => $dataTarefa,
                'importante' => $importante,
            ]);
            tarefasRedirecionar('Tarefa atualizada.', 'success', $abaRetorno);
        }

        $stmt = $pdo->prepare("
            INSERT INTO tarefas (" . empresaInsertColuna($pdo, 'tarefas') . "usuario_id, titulo, descricao, data_tarefa, importante)
            VALUES (" . empresaInsertPlaceholder($pdo, 'tarefas') . "?, ?, ?, ?, ?)
        ");
        $stmt->execute(array_merge(
            empresaInsertValores($pdo, 'tarefas'),
            [$usuarioId, $titulo, $descricao, $dataTarefa, $importante]
        ));
        $novaTarefaId = (int)$pdo->lastInsertId();
        registrarAuditoria($pdo, 'Tarefas', 'criar', 'tarefa', $novaTarefaId, 'Criou a tarefa ' . $titulo, null, [
            'titulo' => $titulo,
            'descricao' => $descricao,
            'data_tarefa' => $dataTarefa,
            'importante' => $importante,
        ]);
        tarefasRedirecionar('Tarefa criada.', 'success', $abaRetorno);
    }

    if ($acao === 'concluir' && $id > 0) {
        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM tarefas
            WHERE id = ?
              AND usuario_id = ?
              " . empresaFiltro($pdo, 'tarefas') . "
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if ($antes) {
            $pdo->prepare("
                UPDATE tarefas
                SET concluida = 1, concluida_em = NOW()
                WHERE id = ? AND usuario_id = ?
                " . empresaFiltro($pdo, 'tarefas') . "
            ")->execute([$id, $usuarioId]);
            registrarAuditoria($pdo, 'Tarefas', 'concluir', 'tarefa', $id, 'Concluiu a tarefa ' . $antes['titulo'], $antes, null);
        }

        tarefasRedirecionar('Tarefa concluída.', 'success', $abaRetorno);
    }

    if ($acao === 'reabrir' && $id > 0) {
        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM tarefas
            WHERE id = ?
              AND usuario_id = ?
              " . empresaFiltro($pdo, 'tarefas') . "
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if ($antes) {
            $pdo->prepare("
                UPDATE tarefas
                SET concluida = 0, concluida_em = NULL
                WHERE id = ? AND usuario_id = ?
                " . empresaFiltro($pdo, 'tarefas') . "
            ")->execute([$id, $usuarioId]);
            registrarAuditoria($pdo, 'Tarefas', 'reabrir', 'tarefa', $id, 'Reabriu a tarefa ' . $antes['titulo'], $antes, null);
        }

        tarefasRedirecionar('Tarefa reaberta.', 'success', $abaRetorno);
    }

    if ($acao === 'excluir' && $id > 0) {
        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM tarefas
            WHERE id = ?
              AND usuario_id = ?
              " . empresaFiltro($pdo, 'tarefas') . "
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("
            DELETE FROM tarefas
            WHERE id = ?
              AND usuario_id = ?
              " . empresaFiltro($pdo, 'tarefas') . "
        ")->execute([$id, $usuarioId]);

        if ($antes) {
            registrarAuditoria($pdo, 'Tarefas', 'excluir', 'tarefa', $id, 'Excluiu a tarefa ' . $antes['titulo'], $antes, null);
        }

        tarefasRedirecionar('Tarefa excluída.', 'success', $abaRetorno);
    }

    if ($acao === 'limpar_concluidas') {
        $stmtAntes = $pdo->prepare("
            SELECT COUNT(*)
            FROM tarefas
            WHERE usuario_id = ?
              AND concluida = 1
              " . empresaFiltro($pdo, 'tarefas') . "
        ");
        $stmtAntes->execute([$usuarioId]);
        $totalAntes = (int)$stmtAntes->fetchColumn();

        $pdo->prepare("
            DELETE FROM tarefas
            WHERE usuario_id = ?
              AND concluida = 1
              " . empresaFiltro($pdo, 'tarefas') . "
        ")->execute([$usuarioId]);

        registrarAuditoria($pdo, 'Tarefas', 'limpar', 'tarefa', null, 'Limpou tarefas concluídas', ['total' => $totalAntes], null);
        tarefasRedirecionar('Tarefas concluídas removidas.', 'success', 'hoje');
    }
}

$tabelaDisponivel = tarefasTabelaExiste($pdo);
$tarefas = [];
$totalTarefas = 0;
$totalPaginas = 1;
$resumo = [
    'hoje' => 0,
    'importantes' => 0,
    'concluidas' => 0,
    'todas' => 0,
];

if ($tabelaDisponivel) {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN concluida = 0 AND data_tarefa = ? THEN 1 ELSE 0 END) AS hoje,
            SUM(CASE WHEN concluida = 0 AND importante = 1 THEN 1 ELSE 0 END) AS importantes,
            SUM(CASE WHEN concluida = 1 THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN concluida = 0 THEN 1 ELSE 0 END) AS todas
        FROM tarefas
        WHERE usuario_id = ?
        " . empresaFiltro($pdo, 'tarefas') . "
    ");
    $stmt->execute([$hoje, $usuarioId]);
    $resumo = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $resumo);

    $filtroSql = 'usuario_id = ?' . empresaFiltro($pdo, 'tarefas');
    $parametros = [$usuarioId];

    if ($aba === 'hoje') {
        $filtroSql .= ' AND concluida = 0 AND data_tarefa = ?';
        $parametros[] = $hoje;
    } elseif ($aba === 'importantes') {
        $filtroSql .= ' AND concluida = 0 AND importante = 1';
    } elseif ($aba === 'concluidas') {
        $filtroSql .= ' AND concluida = 1';
    } else {
        $filtroSql .= ' AND concluida = 0';
    }

    if ($busca !== '') {
        $filtroSql .= ' AND (titulo LIKE ? OR descricao LIKE ?)';
        $termoBusca = '%' . $busca . '%';
        $parametros[] = $termoBusca;
        $parametros[] = $termoBusca;
    }

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM tarefas
        WHERE {$filtroSql}
    ");
    $stmtTotal->execute($parametros);
    $totalTarefas = (int)$stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int)ceil($totalTarefas / $limite));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $limite;

    $stmt = $pdo->prepare("
        SELECT *
        FROM tarefas
        WHERE {$filtroSql}
        ORDER BY concluida ASC, importante DESC, data_tarefa ASC, criado_em ASC
        LIMIT {$limite} OFFSET {$offset}
    ");
    $stmt->execute($parametros);
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$abas = [
    'hoje' => ['Hoje', $resumo['hoje']],
    'importantes' => ['Importante', $resumo['importantes']],
    'concluidas' => ['Concluídas', $resumo['concluidas']],
    'todas' => ['Todas', $resumo['todas']],
];
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Minhas Tarefas</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/tarefas.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-4 tarefas-cabecalho">
                <div>
                    <h3 class="mb-1">Minhas Tarefas</h3>
                    <p class="text-muted mb-0">Post-it digital para organizar o dia sem misturar com processos.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <?php if ($tabelaDisponivel): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTarefa">
                            <i class="bi bi-plus-lg"></i> Nova tarefa
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mensagem !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($tipoMensagem === 'danger' ? 'danger' : 'success') ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <?php if (!$tabelaDisponivel): ?>
                <section class="tarefas-painel">
                    <h5 class="mb-2">Tabela de tarefas ainda não criada</h5>
                    <p class="text-muted">Rode este SQL no phpMyAdmin para ativar o módulo:</p>
                    <pre class="tarefas-sql"><?= htmlspecialchars($sqlTarefas) ?></pre>
                </section>
            <?php else: ?>
                <section class="tarefas-resumo mb-4">
                    <div class="tarefas-resumo-card">
                        <span>Pendentes hoje</span>
                        <strong><?= (int)$resumo['hoje'] ?></strong>
                    </div>
                    <div class="tarefas-resumo-card">
                        <span>Concluídas</span>
                        <strong><?= (int)$resumo['concluidas'] ?></strong>
                    </div>
                    <div class="tarefas-resumo-card">
                        <span>Importantes</span>
                        <strong><?= (int)$resumo['importantes'] ?></strong>
                    </div>
                </section>

                <section class="tarefas-painel">
                    <form method="get" class="row g-2 mb-3 tarefas-filtros" id="formFiltroTarefas">
                        <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                        <input type="hidden" name="pagina" id="tarefasPaginaFiltro" value="<?= (int)$pagina ?>">
                        <div class="col-md-8">
                            <input
                                type="text"
                                name="busca"
                                id="buscaTarefa"
                                class="form-control"
                                value="<?= htmlspecialchars($busca) ?>"
                                autocomplete="off"
                                placeholder="Buscar por tarefa ou observação...">
                        </div>
                        <?php if ($totalPaginas > 1): ?>
                            <div class="col-md-4">
                                <select name="limite" class="form-select" id="limiteTarefas">
                                    <?php foreach ($limitesPermitidos as $opcaoLimite): ?>
                                        <option value="<?= (int)$opcaoLimite ?>" <?= $limite === $opcaoLimite ? 'selected' : '' ?>>
                                            Mostrar <?= (int)$opcaoLimite ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </form>

                    <div class="tarefas-abas">
                        <?php foreach ($abas as $chave => [$rotulo, $total]): ?>
                            <a href="<?= htmlspecialchars(tarefasUrl([
                                            'aba' => $chave,
                                            'busca' => $busca,
                                            'limite' => $limite,
                                            'pagina' => 1,
                                        ])) ?>" class="<?= $aba === $chave ? 'ativo' : '' ?>">
                                <?= htmlspecialchars($rotulo) ?>
                                <span><?= (int)$total ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="tarefas-lista">
                        <?php if ($tarefas === []): ?>
                            <div class="tarefas-vazio">Nenhuma tarefa encontrada nesta lista.</div>
                        <?php endif; ?>

                        <?php foreach ($tarefas as $tarefa): ?>
                            <?php
                            $concluida = (int)$tarefa['concluida'] === 1;
                            ?>
                            <article class="tarefa-item <?= $concluida ? 'concluida' : '' ?>">
                                <?php if ($concluida): ?>
                                    <form method="post" class="tarefa-check">
                                        <input type="hidden" name="acao" value="reabrir">
                                        <input type="hidden" name="id" value="<?= (int)$tarefa['id'] ?>">
                                        <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                                        <input type="hidden" name="busca" value="<?= htmlspecialchars($busca) ?>">
                                        <input type="hidden" name="limite" value="<?= (int)$limite ?>">
                                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                                        <button type="submit" title="Reabrir tarefa">
                                            <i class="bi bi-check-square-fill"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="tarefa-check">
                                        <button
                                            type="button"
                                            class="btn-concluir-tarefa"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalConcluirTarefa"
                                            data-id="<?= (int)$tarefa['id'] ?>"
                                            data-titulo="<?= htmlspecialchars($tarefa['titulo']) ?>"
                                            title="Concluir tarefa">
                                            <i class="bi bi-square"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <div class="tarefa-corpo">
                                    <div class="tarefa-titulo">
                                        <?php if ((int)$tarefa['importante'] === 1): ?>
                                            <i class="bi bi-star-fill text-warning"></i>
                                        <?php endif; ?>
                                        <strong><?= htmlspecialchars($tarefa['titulo']) ?></strong>
                                    </div>
                                    <?php if (trim((string)$tarefa['descricao']) !== ''): ?>
                                        <p><?= nl2br(htmlspecialchars($tarefa['descricao'])) ?></p>
                                    <?php endif; ?>
                                    <small>
                                        <?= date('d/m/Y', strtotime($tarefa['data_tarefa'])) ?>
                                        <?php if ($concluida && !empty($tarefa['concluida_em'])): ?>
                                            · Concluído às <?= date('H:i', strtotime($tarefa['concluida_em'])) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>

                                <div class="tarefa-acoes">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary btn-editar-tarefa"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalTarefa"
                                        data-id="<?= (int)$tarefa['id'] ?>"
                                        data-titulo="<?= htmlspecialchars($tarefa['titulo']) ?>"
                                        data-descricao="<?= htmlspecialchars($tarefa['descricao'] ?? '') ?>"
                                        data-data="<?= htmlspecialchars($tarefa['data_tarefa']) ?>"
                                        data-importante="<?= (int)$tarefa['importante'] ?>"
                                        title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger btn-excluir-tarefa"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalExcluirTarefa"
                                        data-id="<?= (int)$tarefa['id'] ?>"
                                        data-titulo="<?= htmlspecialchars($tarefa['titulo']) ?>"
                                        title="Excluir">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((int)$resumo['concluidas'] > 0): ?>
                        <form method="post" class="text-end mt-3">
                            <input type="hidden" name="acao" value="limpar_concluidas">
                            <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                            <input type="hidden" name="busca" value="<?= htmlspecialchars($busca) ?>">
                            <input type="hidden" name="limite" value="<?= (int)$limite ?>">
                            <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="bi bi-eraser"></i> Limpar concluídas
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($totalPaginas > 1): ?>
                        <nav aria-label="Paginação de tarefas">
                            <ul class="pagination justify-content-center mt-3 mb-0">
                                <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(tarefasUrl([
                                                                    'aba' => $aba,
                                                                    'busca' => $busca,
                                                                    'limite' => $limite,
                                                                    'pagina' => max(1, $pagina - 1),
                                                                ])) ?>">Anterior</a>
                                </li>

                                <?php
                                $ultimaPaginaMostrada = 0;
                                for ($i = 1; $i <= $totalPaginas; $i++):
                                    $mostrarPagina = $i === 1 || $i === $totalPaginas || abs($i - $pagina) <= 2;

                                    if (!$mostrarPagina) {
                                        continue;
                                    }

                                    if ($ultimaPaginaMostrada && $i - $ultimaPaginaMostrada > 1):
                                ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>

                                    <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(tarefasUrl([
                                                                        'aba' => $aba,
                                                                        'busca' => $busca,
                                                                        'limite' => $limite,
                                                                        'pagina' => $i,
                                                                    ])) ?>"><?= (int)$i ?></a>
                                    </li>
                                <?php
                                    $ultimaPaginaMostrada = $i;
                                endfor;
                                ?>

                                <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(tarefasUrl([
                                                                    'aba' => $aba,
                                                                    'busca' => $busca,
                                                                    'limite' => $limite,
                                                                    'pagina' => min($totalPaginas, $pagina + 1),
                                                                ])) ?>">Próxima</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($tabelaDisponivel): ?>
        <div class="modal fade" id="modalTarefa" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" novalidate>
                        <input type="hidden" name="acao" value="salvar">
                        <input type="hidden" name="id" id="tarefaId">
                        <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                        <input type="hidden" name="busca" value="<?= htmlspecialchars($busca) ?>">
                        <input type="hidden" name="limite" value="<?= (int)$limite ?>">
                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalTarefaTitulo">Nova tarefa</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="tarefaTitulo" class="form-label">Tarefa</label>
                                <input type="text" class="form-control" name="titulo" id="tarefaTitulo" maxlength="180" required>
                                <div class="invalid-feedback">Informe a tarefa.</div>
                            </div>
                            <div class="mb-3">
                                <label for="tarefaData" class="form-label">Data</label>
                                <input type="date" class="form-control" name="data_tarefa" id="tarefaData" value="<?= htmlspecialchars($hoje) ?>" required>
                                <div class="invalid-feedback">Informe a data.</div>
                            </div>
                            <div class="mb-3">
                                <label for="tarefaDescricao" class="form-label">Observações</label>
                                <textarea class="form-control" name="descricao" id="tarefaDescricao" rows="3"></textarea>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="importante" id="tarefaImportante" value="1">
                                <label class="form-check-label" for="tarefaImportante">Marcar como importante</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirTarefa" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" id="excluirTarefaId">
                        <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                        <input type="hidden" name="busca" value="<?= htmlspecialchars($busca) ?>">
                        <input type="hidden" name="limite" value="<?= (int)$limite ?>">
                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Excluir tarefa</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            Tem certeza que deseja excluir <strong id="excluirTarefaTitulo"></strong>?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger">Excluir</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalConcluirTarefa" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="acao" value="concluir">
                        <input type="hidden" name="id" id="concluirTarefaId">
                        <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                        <input type="hidden" name="busca" value="<?= htmlspecialchars($busca) ?>">
                        <input type="hidden" name="limite" value="<?= (int)$limite ?>">
                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Concluir tarefa</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            Tem certeza que deseja concluir <strong id="concluirTarefaTitulo"></strong>?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check2"></i> Concluir
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                setTimeout(function() {
                    alerta.classList.remove('show');
                    setTimeout(function() {
                        alerta.remove();
                    }, 200);
                }, 4000);
            });

            const modalTarefa = document.getElementById('modalTarefa');

            modalTarefa?.addEventListener('show.bs.modal', function(event) {
                const botao = event.relatedTarget;
                const editar = botao?.classList.contains('btn-editar-tarefa');

                document.getElementById('modalTarefaTitulo').textContent = editar ? 'Editar tarefa' : 'Nova tarefa';
                document.getElementById('tarefaId').value = editar ? botao.dataset.id : '';
                document.getElementById('tarefaTitulo').value = editar ? botao.dataset.titulo : '';
                document.getElementById('tarefaDescricao').value = editar ? botao.dataset.descricao : '';
                document.getElementById('tarefaData').value = editar ? botao.dataset.data : '<?= htmlspecialchars($hoje) ?>';
                document.getElementById('tarefaImportante').checked = editar && botao.dataset.importante === '1';
            });

            document.getElementById('modalExcluirTarefa')?.addEventListener('show.bs.modal', function(event) {
                const botao = event.relatedTarget;
                document.getElementById('excluirTarefaId').value = botao?.dataset.id || '';
                document.getElementById('excluirTarefaTitulo').textContent = botao?.dataset.titulo || 'esta tarefa';
            });

            document.getElementById('modalConcluirTarefa')?.addEventListener('show.bs.modal', function(event) {
                const botao = event.relatedTarget;
                document.getElementById('concluirTarefaId').value = botao?.dataset.id || '';
                document.getElementById('concluirTarefaTitulo').textContent = botao?.dataset.titulo || 'esta tarefa';
            });

            const formFiltroTarefas = document.getElementById('formFiltroTarefas');
            const buscaTarefa = document.getElementById('buscaTarefa');
            const limiteTarefas = document.getElementById('limiteTarefas');
            const tarefasPaginaFiltro = document.getElementById('tarefasPaginaFiltro');
            let timerBuscaTarefa = null;

            buscaTarefa?.addEventListener('input', function() {
                clearTimeout(timerBuscaTarefa);
                timerBuscaTarefa = setTimeout(function() {
                    if (tarefasPaginaFiltro) {
                        tarefasPaginaFiltro.value = '1';
                    }

                    formFiltroTarefas?.submit();
                }, 450);
            });

            limiteTarefas?.addEventListener('change', function() {
                if (tarefasPaginaFiltro) {
                    tarefasPaginaFiltro.value = '1';
                }

                formFiltroTarefas?.submit();
            });
        </script>
    <?php endif; ?>
</body>

</html>