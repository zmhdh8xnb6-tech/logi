<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$urlRetorno = 'financeiro_categorias.php';
$estruturaDisponivel = financeiroCategoriasDisponiveis($pdo);

if ($estruturaDisponivel) {
    financeiroGarantirCategoriasPadrao($pdo, $usuarioId);
}

function financeiroQuantidadeUsosCategoria(PDO $pdo, int $usuarioId, int $categoriaId): int
{
    $tabelas = [
        'financeiro_recebimentos',
        'financeiro_recebimentos_recorrentes',
        'financeiro_contas',
        'financeiro_contas_recorrentes',
        'financeiro_cartao_lancamentos',
    ];
    $total = 0;

    foreach ($tabelas as $tabela) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM {$tabela}
            WHERE usuario_id = ? AND categoria_id = ?
        ");
        $stmt->execute([$usuarioId, $categoriaId]);
        $total += (int)$stmt->fetchColumn();
    }

    return $total;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$estruturaDisponivel) {
        financeiroRedirecionar(
            $urlRetorno,
            'Execute o SQL das categorias financeiras antes de continuar.',
            'danger'
        );
    }

    if (!financeiroTokenValido($_POST['csrf_token'] ?? null)) {
        financeiroRedirecionar(
            $urlRetorno,
            'A sessão do formulário expirou. Tente novamente.',
            'danger'
        );
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar_categoria') {
        $nome = trim($_POST['nome'] ?? '');
        $tipo = $_POST['tipo'] ?? '';
        $cor = strtolower(trim($_POST['cor'] ?? ''));
        $tamanhoNome = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);

        if (
            $nome === ''
            || $tamanhoNome > 80
            || !in_array($tipo, ['receita', 'despesa'], true)
            || !preg_match('/^#[0-9a-f]{6}$/', $cor)
        ) {
            financeiroRedirecionar(
                $urlRetorno,
                'Preencha corretamente o nome, o tipo e a cor da categoria.',
                'danger'
            );
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM financeiro_categorias
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $usuarioId]);
            $categoriaAntes = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$categoriaAntes) {
                financeiroRedirecionar($urlRetorno, 'Categoria não encontrada.', 'danger');
            }

            $quantidadeUsos = financeiroQuantidadeUsosCategoria($pdo, $usuarioId, $id);

            if ($quantidadeUsos > 0 && $tipo !== $categoriaAntes['tipo']) {
                financeiroRedirecionar(
                    $urlRetorno,
                    'O tipo não pode ser alterado porque esta categoria já possui lançamentos.',
                    'warning'
                );
            }

            try {
                $stmt = $pdo->prepare("
                    UPDATE financeiro_categorias
                    SET nome = ?, tipo = ?, cor = ?
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmt->execute([$nome, $tipo, $cor, $id, $usuarioId]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }

                financeiroRedirecionar(
                    $urlRetorno,
                    'Já existe uma categoria com esse nome e tipo.',
                    'warning'
                );
            }

            $categoriaDepois = array_merge($categoriaAntes, [
                'nome' => $nome,
                'tipo' => $tipo,
                'cor' => $cor,
            ]);
            $mudancas = auditoriaMudancas($categoriaAntes, $categoriaDepois);
            registrarAuditoria(
                $pdo,
                'Financeiro',
                'editar',
                'categoria_financeira',
                $id,
                'Alterou a categoria financeira ' . $nome,
                $mudancas['antes'],
                $mudancas['depois']
            );
            financeiroRedirecionar($urlRetorno, 'Categoria atualizada com sucesso.');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_categorias
                    (usuario_id, nome, tipo, cor, ativa)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$usuarioId, $nome, $tipo, $cor]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            financeiroRedirecionar(
                $urlRetorno,
                'Já existe uma categoria com esse nome e tipo.',
                'warning'
            );
        }

        $novaCategoriaId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro',
            'criar',
            'categoria_financeira',
            $novaCategoriaId,
            'Criou a categoria financeira ' . $nome,
            null,
            ['nome' => $nome, 'tipo' => $tipo, 'cor' => $cor, 'ativa' => 1]
        );
        financeiroRedirecionar($urlRetorno, 'Categoria cadastrada com sucesso.');
    }

    if ($acao === 'alterar_status_categoria') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_categorias
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        $categoriaAntes = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$categoriaAntes) {
            financeiroRedirecionar($urlRetorno, 'Categoria não encontrada.', 'danger');
        }

        $novoStatus = (int)$categoriaAntes['ativa'] === 1 ? 0 : 1;
        $stmt = $pdo->prepare("
            UPDATE financeiro_categorias
            SET ativa = ?
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$novoStatus, $id, $usuarioId]);

        registrarAuditoria(
            $pdo,
            'Financeiro',
            $novoStatus === 1 ? 'reativar' : 'desativar',
            'categoria_financeira',
            $id,
            ($novoStatus === 1 ? 'Reativou' : 'Desativou')
                . ' a categoria financeira '
                . $categoriaAntes['nome'],
            ['ativa' => (int)$categoriaAntes['ativa']],
            ['ativa' => $novoStatus]
        );
        financeiroRedirecionar(
            $urlRetorno,
            $novoStatus === 1
                ? 'Categoria reativada com sucesso.'
                : 'Categoria desativada sem apagar o histórico.'
        );
    }
}

$mensagem = financeiroObterMensagem();
$categorias = [];
$categoriasPorTipo = ['receita' => [], 'despesa' => []];

if ($estruturaDisponivel) {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            (
                (SELECT COUNT(*) FROM financeiro_recebimentos r
                 WHERE r.usuario_id = c.usuario_id AND r.categoria_id = c.id)
                +
                (SELECT COUNT(*) FROM financeiro_recebimentos_recorrentes rr
                 WHERE rr.usuario_id = c.usuario_id AND rr.categoria_id = c.id)
                +
                (SELECT COUNT(*) FROM financeiro_contas fc
                 WHERE fc.usuario_id = c.usuario_id AND fc.categoria_id = c.id)
                +
                (SELECT COUNT(*) FROM financeiro_contas_recorrentes fcr
                 WHERE fcr.usuario_id = c.usuario_id AND fcr.categoria_id = c.id)
                +
                (SELECT COUNT(*) FROM financeiro_cartao_lancamentos l
                 WHERE l.usuario_id = c.usuario_id AND l.categoria_id = c.id)
            ) AS quantidade_usos
        FROM financeiro_categorias c
        WHERE c.usuario_id = ?
        ORDER BY c.tipo, c.ativa DESC, c.nome
    ");
    $stmt->execute([$usuarioId]);
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($categorias as $categoria) {
        $categoriasPorTipo[$categoria['tipo']][] = $categoria;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Categorias financeiras</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Categorias financeiras</h3>
                    <p class="text-muted mb-0">Organize receitas e despesas sem perder o histórico</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($estruturaDisponivel): ?>
                        <button
                            type="button"
                            class="btn btn-primary"
                            id="btnNovaCategoria"
                            data-bs-toggle="modal"
                            data-bs-target="#modalCategoria">
                            <i class="bi bi-plus-lg"></i> Nova categoria
                        </button>
                    <?php endif; ?>
                    <a href="financeiro.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$estruturaDisponivel): ?>
                <div class="alert alert-warning">
                    Execute o SQL das categorias financeiras e atualize esta página.
                </div>
            <?php else: ?>
                <div class="financeiro-categorias-grid">
                    <?php
                    $configuracoesTipos = [
                        'receita' => [
                            'titulo' => 'Receitas',
                            'descricao' => 'Salários, serviços e outras entradas',
                            'icone' => 'bi-arrow-down-circle',
                            'classe' => 'text-success',
                        ],
                        'despesa' => [
                            'titulo' => 'Despesas',
                            'descricao' => 'Contas, compras e demais gastos',
                            'icone' => 'bi-arrow-up-circle',
                            'classe' => 'text-danger',
                        ],
                    ];
                    ?>
                    <?php foreach ($configuracoesTipos as $tipo => $configuracao): ?>
                        <section class="financeiro-painel">
                            <div class="financeiro-painel-titulo">
                                <div>
                                    <h5 class="mb-1 <?= $configuracao['classe'] ?>">
                                        <i class="bi <?= $configuracao['icone'] ?>"></i>
                                        <?= $configuracao['titulo'] ?>
                                    </h5>
                                    <p class="text-muted small mb-0"><?= $configuracao['descricao'] ?></p>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle financeiro-tabela">
                                    <thead>
                                        <tr>
                                            <th>Categoria</th>
                                            <th>Situação</th>
                                            <th class="text-end">Usos</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($categoriasPorTipo[$tipo] === []): ?>
                                            <tr>
                                                <td colspan="4" class="financeiro-vazio">Nenhuma categoria cadastrada.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($categoriasPorTipo[$tipo] as $categoria): ?>
                                            <tr class="<?= (int)$categoria['ativa'] === 1 ? '' : 'financeiro-categoria-inativa' ?>">
                                                <td>
                                                    <span
                                                        class="financeiro-categoria-cor"
                                                        style="background-color:<?= htmlspecialchars($categoria['cor']) ?>"></span>
                                                    <strong><?= htmlspecialchars($categoria['nome']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge <?= (int)$categoria['ativa'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= (int)$categoria['ativa'] === 1 ? 'Ativa' : 'Desativada' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?= (int)$categoria['quantidade_usos'] ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-primary btn-sm btn-editar-categoria"
                                                            data-id="<?= (int)$categoria['id'] ?>"
                                                            data-nome="<?= htmlspecialchars($categoria['nome']) ?>"
                                                            data-tipo="<?= htmlspecialchars($categoria['tipo']) ?>"
                                                            data-cor="<?= htmlspecialchars($categoria['cor']) ?>"
                                                            data-usos="<?= (int)$categoria['quantidade_usos'] ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalCategoria"
                                                            title="Editar categoria">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn <?= (int)$categoria['ativa'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-sm btn-status-categoria"
                                                            data-id="<?= (int)$categoria['id'] ?>"
                                                            data-nome="<?= htmlspecialchars($categoria['nome']) ?>"
                                                            data-ativa="<?= (int)$categoria['ativa'] ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalStatusCategoria"
                                                            title="<?= (int)$categoria['ativa'] === 1 ? 'Desativar categoria' : 'Reativar categoria' ?>">
                                                            <i class="bi <?= (int)$categoria['ativa'] === 1 ? 'bi-slash-circle' : 'bi-arrow-counterclockwise' ?>"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalCategoria" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" id="formCategoria" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="acao" value="salvar_categoria">
                        <input type="hidden" name="id" id="categoriaId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalCategoria">Nova categoria</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="categoriaNome" class="form-label">Nome</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    name="nome"
                                    id="categoriaNome"
                                    maxlength="80"
                                    required>
                                <div class="invalid-feedback">Informe o nome da categoria.</div>
                            </div>
                            <div class="mb-3">
                                <label for="categoriaTipo" class="form-label">Tipo</label>
                                <select class="form-select" name="tipo" id="categoriaTipo" required>
                                    <option value="">Selecione</option>
                                    <option value="receita">Receita</option>
                                    <option value="despesa">Despesa</option>
                                </select>
                                <div class="invalid-feedback">Selecione o tipo.</div>
                                <small class="text-muted d-none" id="avisoTipoCategoria">
                                    O tipo não pode ser alterado porque a categoria já possui lançamentos.
                                </small>
                            </div>
                            <div>
                                <label for="categoriaCor" class="form-label">Cor</label>
                                <input
                                    type="color"
                                    class="form-control form-control-color"
                                    name="cor"
                                    id="categoriaCor"
                                    value="#0d6efd"
                                    title="Escolher cor"
                                    required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar categoria</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalStatusCategoria" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="tituloStatusCategoria">Desativar categoria</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1" id="textoStatusCategoria"></p>
                        <small class="text-muted" id="avisoStatusCategoria"></small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="acao" value="alterar_status_categoria">
                            <input type="hidden" name="id" id="statusCategoriaId">
                            <button type="submit" class="btn" id="btnConfirmarStatusCategoria"></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($estruturaDisponivel): ?>
        <script>
            const categoriaId = document.getElementById('categoriaId');
            const categoriaNome = document.getElementById('categoriaNome');
            const categoriaTipo = document.getElementById('categoriaTipo');
            const categoriaCor = document.getElementById('categoriaCor');
            const avisoTipoCategoria = document.getElementById('avisoTipoCategoria');

            document.getElementById('btnNovaCategoria').addEventListener('click', function() {
                document.getElementById('tituloModalCategoria').textContent = 'Nova categoria';
                categoriaId.value = '';
                categoriaNome.value = '';
                categoriaTipo.value = '';
                categoriaTipo.disabled = false;
                categoriaCor.value = '#0d6efd';
                avisoTipoCategoria.classList.add('d-none');
                document.querySelectorAll('#formCategoria .is-invalid').forEach(function(campo) {
                    campo.classList.remove('is-invalid');
                });
            });

            document.querySelectorAll('.btn-editar-categoria').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    const possuiUsos = Number(this.dataset.usos) > 0;
                    document.getElementById('tituloModalCategoria').textContent = 'Editar categoria';
                    categoriaId.value = this.dataset.id;
                    categoriaNome.value = this.dataset.nome;
                    categoriaTipo.value = this.dataset.tipo;
                    categoriaTipo.disabled = possuiUsos;
                    categoriaCor.value = this.dataset.cor;
                    avisoTipoCategoria.classList.toggle('d-none', !possuiUsos);
                    document.querySelectorAll('#formCategoria .is-invalid').forEach(function(campo) {
                        campo.classList.remove('is-invalid');
                    });
                });
            });

            document.getElementById('formCategoria').addEventListener('submit', function(event) {
                categoriaTipo.disabled = false;
                let primeiroInvalido = null;

                this.querySelectorAll('[required]').forEach(function(campo) {
                    const invalido = campo.value.trim() === '';
                    campo.classList.toggle('is-invalid', invalido);
                    primeiroInvalido = primeiroInvalido || (invalido ? campo : null);
                });

                if (primeiroInvalido) {
                    event.preventDefault();
                    primeiroInvalido.focus();
                }
            });

            document.querySelectorAll('#formCategoria input, #formCategoria select').forEach(function(campo) {
                campo.addEventListener('input', function() {
                    this.classList.remove('is-invalid');
                });
            });

            document.querySelectorAll('.btn-status-categoria').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    const desativar = this.dataset.ativa === '1';
                    const acao = desativar ? 'Desativar' : 'Reativar';
                    const confirmar = document.getElementById('btnConfirmarStatusCategoria');

                    document.getElementById('statusCategoriaId').value = this.dataset.id;
                    document.getElementById('tituloStatusCategoria').textContent = acao + ' categoria';
                    document.getElementById('textoStatusCategoria').textContent =
                        acao + ' a categoria "' + this.dataset.nome + '"?';
                    document.getElementById('avisoStatusCategoria').textContent = desativar ?
                        'Os lançamentos antigos continuarão exibindo esta categoria.' :
                        'A categoria voltará a aparecer nos novos lançamentos.';
                    confirmar.textContent = acao;
                    confirmar.className = 'btn ' + (desativar ? 'btn-danger' : 'btn-success');
                });
            });

            setTimeout(function() {
                document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                    alerta.classList.remove('show');
                    setTimeout(function() {
                        alerta.remove();
                    }, 200);
                });
            }, 4000);
        </script>
    <?php endif; ?>
</body>

</html>