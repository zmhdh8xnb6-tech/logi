<?php
require 'config.php';

exigirPermissao('tarefas');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime('+1 day'));
$mensagem = $_GET['msg'] ?? '';
$tipoMensagem = $_GET['tipo'] ?? 'success';
$aba = $_GET['aba'] ?? 'hoje';
$abasPermitidas = ['hoje', 'amanha', 'importantes', 'atrasadas', 'concluidas', 'todas'];
$aba = in_array($aba, $abasPermitidas, true) ? $aba : 'hoje';

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
    header('Location: tarefas.php?' . http_build_query([
        'msg' => $mensagem,
        'tipo' => $tipo,
        'aba' => $aba,
    ]));
    exit;
}

$sqlTarefas = <<<SQL
CREATE TABLE tarefas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    data_tarefa DATE NOT NULL,
    importante TINYINT(1) NOT NULL DEFAULT 0,
    concluida TINYINT(1) NOT NULL DEFAULT 0,
    concluida_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
            $stmtAntes = $pdo->prepare("SELECT * FROM tarefas WHERE id = ? AND usuario_id = ?");
            $stmtAntes->execute([$id, $usuarioId]);
            $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

            if (!$antes) {
                tarefasRedirecionar('Tarefa não encontrada.', 'danger', $abaRetorno);
            }

            $stmt = $pdo->prepare("
                UPDATE tarefas
                SET titulo = ?, descricao = ?, data_tarefa = ?, importante = ?
                WHERE id = ? AND usuario_id = ?
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
            INSERT INTO tarefas (usuario_id, titulo, descricao, data_tarefa, importante)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuarioId, $titulo, $descricao, $dataTarefa, $importante]);
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
        $stmtAntes = $pdo->prepare("SELECT * FROM tarefas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if ($antes) {
            $pdo->prepare("
                UPDATE tarefas
                SET concluida = 1, concluida_em = NOW()
                WHERE id = ? AND usuario_id = ?
            ")->execute([$id, $usuarioId]);
            registrarAuditoria($pdo, 'Tarefas', 'concluir', 'tarefa', $id, 'Concluiu a tarefa ' . $antes['titulo'], $antes, null);
        }

        tarefasRedirecionar('Tarefa concluída.', 'success', $abaRetorno);
    }

    if ($acao === 'reabrir' && $id > 0) {
        $stmtAntes = $pdo->prepare("SELECT * FROM tarefas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if ($antes) {
            $pdo->prepare("
                UPDATE tarefas
                SET concluida = 0, concluida_em = NULL
                WHERE id = ? AND usuario_id = ?
            ")->execute([$id, $usuarioId]);
            registrarAuditoria($pdo, 'Tarefas', 'reabrir', 'tarefa', $id, 'Reabriu a tarefa ' . $antes['titulo'], $antes, null);
        }

        tarefasRedirecionar('Tarefa reaberta.', 'success', $abaRetorno);
    }

    if ($acao === 'excluir' && $id > 0) {
        $stmtAntes = $pdo->prepare("SELECT * FROM tarefas WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM tarefas WHERE id = ? AND usuario_id = ?")->execute([$id, $usuarioId]);

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
        ");
        $stmtAntes->execute([$usuarioId]);
        $totalAntes = (int)$stmtAntes->fetchColumn();

        $pdo->prepare("
            DELETE FROM tarefas
            WHERE usuario_id = ?
              AND concluida = 1
        ")->execute([$usuarioId]);

        registrarAuditoria($pdo, 'Tarefas', 'limpar', 'tarefa', null, 'Limpou tarefas concluídas', ['total' => $totalAntes], null);
        tarefasRedirecionar('Tarefas concluídas removidas.', 'success', 'hoje');
    }
}

$tabelaDisponivel = tarefasTabelaExiste($pdo);
$tarefas = [];
$resumo = [
    'hoje' => 0,
    'amanha' => 0,
    'importantes' => 0,
    'atrasadas' => 0,
    'concluidas' => 0,
    'todas' => 0,
];

if ($tabelaDisponivel) {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN concluida = 0 AND data_tarefa = ? THEN 1 ELSE 0 END) AS hoje,
            SUM(CASE WHEN concluida = 0 AND data_tarefa = ? THEN 1 ELSE 0 END) AS amanha,
            SUM(CASE WHEN concluida = 0 AND importante = 1 THEN 1 ELSE 0 END) AS importantes,
            SUM(CASE WHEN concluida = 0 AND data_tarefa < ? THEN 1 ELSE 0 END) AS atrasadas,
            SUM(CASE WHEN concluida = 1 THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN concluida = 0 THEN 1 ELSE 0 END) AS todas
        FROM tarefas
        WHERE usuario_id = ?
    ");
    $stmt->execute([$hoje, $amanha, $hoje, $usuarioId]);
    $resumo = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $resumo);

    $filtroSql = 'usuario_id = ?';
    $parametros = [$usuarioId];

    if ($aba === 'hoje') {
        $filtroSql .= ' AND concluida = 0 AND data_tarefa = ?';
        $parametros[] = $hoje;
    } elseif ($aba === 'amanha') {
        $filtroSql .= ' AND concluida = 0 AND data_tarefa = ?';
        $parametros[] = $amanha;
    } elseif ($aba === 'importantes') {
        $filtroSql .= ' AND concluida = 0 AND importante = 1';
    } elseif ($aba === 'atrasadas') {
        $filtroSql .= ' AND concluida = 0 AND data_tarefa < ?';
        $parametros[] = $hoje;
    } elseif ($aba === 'concluidas') {
        $filtroSql .= ' AND concluida = 1';
    } else {
        $filtroSql .= ' AND concluida = 0';
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM tarefas
        WHERE {$filtroSql}
        ORDER BY concluida ASC, importante DESC, data_tarefa ASC, criado_em ASC
    ");
    $stmt->execute($parametros);
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$abas = [
    'hoje' => ['Hoje', $resumo['hoje']],
    'amanha' => ['Amanhã', $resumo['amanha']],
    'importantes' => ['Importante', $resumo['importantes']],
    'atrasadas' => ['Atrasadas', $resumo['atrasadas']],
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
                    <div class="tarefas-resumo-card <?= (int)$resumo['atrasadas'] > 0 ? 'atencao' : '' ?>">
                        <span>Atrasadas</span>
                        <strong><?= (int)$resumo['atrasadas'] ?></strong>
                    </div>
                </section>

                <section class="tarefas-painel">
                    <div class="tarefas-abas">
                        <?php foreach ($abas as $chave => [$rotulo, $total]): ?>
                            <a href="tarefas.php?aba=<?= htmlspecialchars($chave) ?>" class="<?= $aba === $chave ? 'ativo' : '' ?>">
                                <?= htmlspecialchars($rotulo) ?>
                                <span><?= (int)$total ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="tarefas-lista">
                        <?php if ($tarefas === []): ?>
                            <div class="tarefas-vazio">Nenhuma tarefa nesta lista.</div>
                        <?php endif; ?>

                        <?php foreach ($tarefas as $tarefa): ?>
                            <?php
                            $concluida = (int)$tarefa['concluida'] === 1;
                            $atrasada = !$concluida && $tarefa['data_tarefa'] < $hoje;
                            ?>
                            <article class="tarefa-item <?= $concluida ? 'concluida' : '' ?> <?= $atrasada ? 'atrasada' : '' ?>">
                                <form method="post" class="tarefa-check">
                                    <input type="hidden" name="acao" value="<?= $concluida ? 'reabrir' : 'concluir' ?>">
                                    <input type="hidden" name="id" value="<?= (int)$tarefa['id'] ?>">
                                    <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
                                    <button type="submit" title="<?= $concluida ? 'Reabrir tarefa' : 'Concluir tarefa' ?>">
                                        <i class="bi <?= $concluida ? 'bi-check-square-fill' : 'bi-square' ?>"></i>
                                    </button>
                                </form>

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
                                        <?php elseif ($atrasada): ?>
                                            · Atrasada
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
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="bi bi-eraser"></i> Limpar concluídas
                            </button>
                        </form>
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
        </script>
    <?php endif; ?>
</body>

</html>