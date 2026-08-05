<?php
require 'config.php';

exigirPermissao('outros_servicos');

$tabelaAntivirusExiste = logiTabelaExiste($pdo, 'antivirus_controles');
$mensagem = $_GET['msg'] ?? '';
$erro = '';
$hoje = date('Y-m-d');

function antivirusDataBr(?string $data): string
{
    return empty($data) ? '-' : date('d/m/Y', strtotime($data));
}

function antivirusStatusRotulo(string $status, ?string $vencimento): string
{
    if ($status === 'nao_precisa_momento') {
        return 'Não precisa no momento';
    }

    if ($status === 'nao_possui') {
        return 'Não possui';
    }

    if ($status === 'possui' && !empty($vencimento) && $vencimento < date('Y-m-d')) {
        return 'Vencido';
    }

    if ($status === 'possui') {
        return 'Possui';
    }

    return 'Não informado';
}

function antivirusStatusClasse(string $status, ?string $vencimento): string
{
    if ($status === 'nao_precisa_momento') {
        return 'bg-secondary';
    }

    if ($status === 'possui' && !empty($vencimento) && $vencimento < date('Y-m-d')) {
        return 'bg-danger';
    }

    if ($status === 'possui') {
        return 'bg-success';
    }

    return 'bg-danger';
}

function antivirusPrazo(?string $vencimento): array
{
    if (empty($vencimento)) {
        return ['Sem vencimento', 'bg-secondary'];
    }

    $dias = (int)((strtotime($vencimento) - strtotime(date('Y-m-d'))) / 86400);

    if ($dias < 0) {
        return ['Vencido há ' . abs($dias) . ' dia' . (abs($dias) === 1 ? '' : 's'), 'bg-danger'];
    }

    if ($dias === 0) {
        return ['Vence hoje', 'bg-danger'];
    }

    if ($dias <= 30) {
        return [$dias . ' dia' . ($dias === 1 ? '' : 's') . ' para vencer', 'bg-warning text-dark'];
    }

    return [$dias . ' dia' . ($dias === 1 ? '' : 's') . ' para vencer', 'bg-success'];
}

if ($tabelaAntivirusExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $colaborador = trim((string)($_POST['colaborador'] ?? ''));
            $computador = trim((string)($_POST['computador'] ?? ''));
            $antivirusNome = trim((string)($_POST['antivirus_nome'] ?? ''));
            $status = (string)($_POST['status'] ?? 'nao_possui');
            $vencimento = trim((string)($_POST['vencimento'] ?? ''));
            $statusValidos = ['possui', 'nao_possui', 'nao_precisa_momento'];

            if ($colaborador === '') {
                throw new RuntimeException('Informe o colaborador.');
            }

            if (!in_array($status, $statusValidos, true)) {
                $status = 'nao_possui';
            }

            if ($status !== 'possui') {
                $vencimento = '';
            }

            if ($status === 'possui' && $vencimento === '') {
                throw new RuntimeException('Informe o vencimento do antivírus.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE antivirus_controles
                    SET colaborador = ?, computador = ?, antivirus_nome = ?, status = ?, vencimento = ?, atualizado_em = NOW()
                    WHERE id = ?
                    " . empresaFiltro($pdo, 'antivirus_controles') . "
                ");
                $stmt->execute([
                    $colaborador,
                    $computador !== '' ? $computador : null,
                    $antivirusNome !== '' ? $antivirusNome : null,
                    $status,
                    $vencimento !== '' ? $vencimento : null,
                    $id,
                ]);
                header('Location: antivirus.php?msg=atualizado');
                exit;
            }

            $colunasEmpresa = empresaInsertColuna($pdo, 'antivirus_controles');
            $placeholdersEmpresa = empresaInsertPlaceholder($pdo, 'antivirus_controles');
            $valoresEmpresa = empresaInsertValores($pdo, 'antivirus_controles');

            $stmt = $pdo->prepare("
                INSERT INTO antivirus_controles ({$colunasEmpresa}colaborador, computador, antivirus_nome, status, vencimento)
                VALUES ({$placeholdersEmpresa}?, ?, ?, ?, ?)
            ");
            $stmt->execute(array_merge($valoresEmpresa, [
                $colaborador,
                $computador !== '' ? $computador : null,
                $antivirusNome !== '' ? $antivirusNome : null,
                $status,
                $vencimento !== '' ? $vencimento : null,
            ]));
            header('Location: antivirus.php?msg=salvo');
            exit;
        }

        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM antivirus_controles
                    WHERE id = ?
                    " . empresaFiltro($pdo, 'antivirus_controles') . "
                ");
                $stmt->execute([$id]);
            }

            header('Location: antivirus.php?msg=excluido');
            exit;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$controles = [];
if ($tabelaAntivirusExiste) {
    $stmt = $pdo->query("
        SELECT *
        FROM antivirus_controles
        WHERE 1 = 1
        " . empresaFiltro($pdo, 'antivirus_controles') . "
        ORDER BY colaborador ASC, computador ASC, id ASC
    ");
    $controles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Antivírus</title>
    <style>
        .antivirus-box {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            padding: 24px;
        }

        .linha-antivirus {
            transition: background-color .18s ease;
            cursor: pointer;
        }

        .linha-antivirus:hover {
            background: #f8fafc;
        }

        @media print {

            .sidebar,
            .btn,
            .form-control,
            .form-select,
            .acoes-antivirus,
            #paginacaoAntivirus,
            .mensagem-tela,
            .modal {
                display: none !important;
            }

            body,
            .app-main {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .antivirus-box {
                box-shadow: none;
                padding: 0;
            }

            .linha-antivirus.d-none {
                display: table-row !important;
            }
        }
    </style>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Antivírus</h3>
                    <p class="text-muted mb-0">Controle de antivírus dos computadores do escritório</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="btnImprimirAntivirus" <?= !$tabelaAntivirusExiste ? 'disabled' : '' ?>>
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAntivirus" id="btnNovoAntivirus" <?= !$tabelaAntivirusExiste ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-lg"></i> Novo controle
                    </button>
                    <a href="outros_servicos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if (!$tabelaAntivirusExiste): ?>
                <div class="alert alert-warning mensagem-tela">
                    <strong>Falta criar a tabela do antivírus.</strong>
                    <p class="mb-2">Rode este SQL no phpMyAdmin para liberar a tela:</p>
                    <pre class="mb-0 small">CREATE TABLE IF NOT EXISTS antivirus_controles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL DEFAULT 1,
    colaborador VARCHAR(150) NOT NULL,
    computador VARCHAR(150) NULL,
    antivirus_nome VARCHAR(100) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'nao_possui',
    vencimento DATE NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL,
    INDEX idx_antivirus_empresa (empresa_id),
    INDEX idx_antivirus_status (status),
    INDEX idx_antivirus_vencimento (vencimento)
);</pre>
                </div>
            <?php endif; ?>

            <?php if ($mensagem): ?>
                <div class="alert alert-success mensagem-tela alerta-temporario">
                    <?= $mensagem === 'salvo' ? 'Controle cadastrado com sucesso.' : ($mensagem === 'atualizado' ? 'Controle atualizado com sucesso.' : 'Controle excluído com sucesso.') ?>
                </div>
            <?php endif; ?>

            <?php if ($erro): ?>
                <div class="alert alert-danger mensagem-tela"><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3 mensagem-tela">
                <input type="text" id="buscaAntivirus" class="form-control" style="max-width: 430px;" placeholder="Buscar por colaborador, computador ou antivírus...">
                <select class="form-select" id="filtroStatusAntivirus" style="max-width: 240px;">
                    <option value="todos">Todos os status</option>
                    <option value="possui">Possui</option>
                    <option value="nao_possui">Não possui</option>
                    <option value="nao_precisa_momento">Não precisa no momento</option>
                    <option value="vencido">Vencidos</option>
                    <option value="a_vencer">A vencer em 30 dias</option>
                </select>
            </div>

            <div class="antivirus-box">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th>Computador</th>
                                <th>Antivírus</th>
                                <th>Status</th>
                                <th>Vencimento</th>
                                <th>Dias restantes</th>
                                <th class="text-end acoes-antivirus">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($controles)): ?>
                                <tr id="linhaAntivirusSemDados">
                                    <td colspan="7" class="text-center text-muted py-4">Nenhum controle de antivírus cadastrado.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($controles as $controle): ?>
                                <?php
                                [$prazoRotulo, $prazoClasse] = antivirusPrazo($controle['vencimento'] ?? null);
                                $status = (string)($controle['status'] ?? 'nao_possui');
                                $vencimento = $controle['vencimento'] ?? '';
                                $dias = empty($vencimento) ? '' : (int)((strtotime($vencimento) - strtotime($hoje)) / 86400);
                                ?>
                                <tr
                                    class="linha-antivirus"
                                    data-id="<?= (int)$controle['id'] ?>"
                                    data-colaborador="<?= htmlspecialchars($controle['colaborador'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-computador="<?= htmlspecialchars($controle['computador'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-antivirus="<?= htmlspecialchars($controle['antivirus_nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="<?= htmlspecialchars($status) ?>"
                                    data-vencimento="<?= htmlspecialchars($vencimento ?? '') ?>"
                                    data-dias="<?= htmlspecialchars((string)$dias) ?>">
                                    <td><strong><?= htmlspecialchars($controle['colaborador'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($controle['computador'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($controle['antivirus_nome'] ?: '-') ?></td>
                                    <td>
                                        <span class="badge <?= antivirusStatusClasse($status, $vencimento) ?>">
                                            <?= antivirusStatusRotulo($status, $vencimento) ?>
                                        </span>
                                    </td>
                                    <td><?= antivirusDataBr($vencimento) ?></td>
                                    <td>
                                        <?php if ($status === 'possui'): ?>
                                            <span class="badge <?= $prazoClasse ?>"><?= htmlspecialchars($prazoRotulo) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end acoes-antivirus">
                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="btn btn-outline-primary btn-sm btn-editar-antivirus" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm btn-excluir-antivirus" title="Excluir">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="linhaAntivirusVazio" class="d-none">
                                <td colspan="7" class="text-center text-muted py-4">Nenhum controle encontrado.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3" id="paginacaoAntivirus"></div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalAntivirus" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" id="formAntivirus" novalidate>
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="id" id="antivirusId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAntivirusTitulo">Novo controle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="antivirusColaborador" class="form-label">Colaborador</label>
                            <input type="text" class="form-control" name="colaborador" id="antivirusColaborador">
                            <div class="invalid-feedback">Informe o colaborador.</div>
                        </div>
                        <div class="mb-3">
                            <label for="antivirusComputador" class="form-label">Computador</label>
                            <input type="text" class="form-control" name="computador" id="antivirusComputador" placeholder="Ex.: Notebook recepção">
                        </div>
                        <div class="mb-3">
                            <label for="antivirusNome" class="form-label">Antivírus</label>
                            <input type="text" class="form-control" name="antivirus_nome" id="antivirusNome" placeholder="Ex.: Kaspersky, Avast, Windows Defender">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="antivirusStatus" class="form-label">Status</label>
                                <select class="form-select" name="status" id="antivirusStatus">
                                    <option value="possui">Possui</option>
                                    <option value="nao_possui">Não possui</option>
                                    <option value="nao_precisa_momento">Não precisa no momento</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="antivirusVencimento" class="form-label">Vencimento</label>
                                <input type="date" class="form-control" name="vencimento" id="antivirusVencimento">
                                <div class="invalid-feedback">Informe o vencimento.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalExcluirAntivirus" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="id" id="excluirAntivirusId">
                    <div class="modal-header">
                        <h5 class="modal-title">Excluir controle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir este controle de antivírus?</p>
                        <p class="text-muted mb-0" id="excluirAntivirusTexto"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Excluir
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let antivirusPorPagina = Number(localStorage.getItem('antivirusPorPagina') || 15);
        antivirusPorPagina = [15, 30, 60, 90].includes(antivirusPorPagina) ? antivirusPorPagina : 15;
        let antivirusPaginaAtual = 1;
        const linhasAntivirus = Array.from(document.querySelectorAll('.linha-antivirus'));
        const buscaAntivirus = document.getElementById('buscaAntivirus');
        const filtroStatusAntivirus = document.getElementById('filtroStatusAntivirus');
        const paginacaoAntivirus = document.getElementById('paginacaoAntivirus');
        const linhaAntivirusVazio = document.getElementById('linhaAntivirusVazio');
        const modalAntivirus = document.getElementById('modalAntivirus');
        const instanciaModalAntivirus = modalAntivirus ? new bootstrap.Modal(modalAntivirus) : null;
        const modalExcluirAntivirus = document.getElementById('modalExcluirAntivirus');
        const instanciaModalExcluirAntivirus = modalExcluirAntivirus ? new bootstrap.Modal(modalExcluirAntivirus) : null;

        function normalizarTextoAntivirus(texto) {
            return (texto || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function linhasAntivirusFiltradas() {
            const termo = normalizarTextoAntivirus(buscaAntivirus ? buscaAntivirus.value : '');
            const filtro = filtroStatusAntivirus ? filtroStatusAntivirus.value : 'todos';

            return linhasAntivirus.filter(function(linha) {
                const texto = normalizarTextoAntivirus([
                    linha.dataset.colaborador,
                    linha.dataset.computador,
                    linha.dataset.antivirus
                ].join(' '));
                const status = linha.dataset.status || '';
                const dias = linha.dataset.dias === '' ? null : Number(linha.dataset.dias);

                const passaTexto = texto.includes(termo);
                let passaStatus = filtro === 'todos' || status === filtro;

                if (filtro === 'vencido') {
                    passaStatus = status === 'possui' && dias !== null && dias < 0;
                } else if (filtro === 'a_vencer') {
                    passaStatus = status === 'possui' && dias !== null && dias >= 0 && dias <= 30;
                }

                return passaTexto && passaStatus;
            });
        }

        function adicionarPaginaAntivirus(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                antivirusPaginaAtual = pagina;
                renderizarAntivirus();
            });
            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarAntivirus() {
            const filtradas = linhasAntivirusFiltradas();
            const totalPaginas = Math.max(1, Math.ceil(filtradas.length / antivirusPorPagina));

            if (antivirusPaginaAtual > totalPaginas) {
                antivirusPaginaAtual = totalPaginas;
            }

            const inicio = (antivirusPaginaAtual - 1) * antivirusPorPagina;
            const visiveis = new Set(filtradas.slice(inicio, inicio + antivirusPorPagina));

            linhasAntivirus.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            if (linhaAntivirusVazio) {
                linhaAntivirusVazio.classList.toggle('d-none', filtradas.length > 0);
            }

            if (!paginacaoAntivirus) {
                return;
            }

            paginacaoAntivirus.innerHTML = '';

            if (filtradas.length <= antivirusPorPagina) {
                return;
            }

            const seletorLimite = document.createElement('div');
            seletorLimite.className = 'd-flex justify-content-end mb-2';
            seletorLimite.innerHTML = `
                <select class="form-select form-select-sm w-auto" aria-label="Itens por página">
                    <option value="15">Mostrar 15</option>
                    <option value="30">Mostrar 30</option>
                    <option value="60">Mostrar 60</option>
                    <option value="90">Mostrar 90</option>
                </select>
            `;
            const campoLimite = seletorLimite.querySelector('select');
            campoLimite.value = String(antivirusPorPagina);
            campoLimite.addEventListener('change', function() {
                antivirusPorPagina = Number(campoLimite.value);
                localStorage.setItem('antivirusPorPagina', String(antivirusPorPagina));
                antivirusPaginaAtual = 1;
                renderizarAntivirus();
            });
            paginacaoAntivirus.appendChild(seletorLimite);

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mb-0';
            adicionarPaginaAntivirus(lista, 'Anterior', Math.max(1, antivirusPaginaAtual - 1), antivirusPaginaAtual <= 1, false);

            let ultimaPagina = 0;
            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - antivirusPaginaAtual) <= 2) {
                    if (ultimaPagina && pagina - ultimaPagina > 1) {
                        const itemReticencias = document.createElement('li');
                        itemReticencias.className = 'page-item disabled';
                        itemReticencias.innerHTML = '<span class="page-link">...</span>';
                        lista.appendChild(itemReticencias);
                    }
                    adicionarPaginaAntivirus(lista, String(pagina), pagina, false, pagina === antivirusPaginaAtual);
                    ultimaPagina = pagina;
                }
            }

            adicionarPaginaAntivirus(lista, 'Próxima', Math.min(totalPaginas, antivirusPaginaAtual + 1), antivirusPaginaAtual >= totalPaginas, false);
            nav.appendChild(lista);
            paginacaoAntivirus.appendChild(nav);
        }

        function limparModalAntivirus() {
            document.getElementById('modalAntivirusTitulo').textContent = 'Novo controle';
            document.getElementById('antivirusId').value = '';
            document.getElementById('antivirusColaborador').value = '';
            document.getElementById('antivirusComputador').value = '';
            document.getElementById('antivirusNome').value = '';
            document.getElementById('antivirusStatus').value = 'possui';
            document.getElementById('antivirusVencimento').value = '';
            atualizarVencimentoAntivirus();
            document.querySelectorAll('#formAntivirus .is-invalid').forEach(function(campo) {
                campo.classList.remove('is-invalid');
            });
        }

        function preencherModalAntivirus(linha) {
            document.getElementById('modalAntivirusTitulo').textContent = 'Editar controle';
            document.getElementById('antivirusId').value = linha.dataset.id || '';
            document.getElementById('antivirusColaborador').value = linha.dataset.colaborador || '';
            document.getElementById('antivirusComputador').value = linha.dataset.computador || '';
            document.getElementById('antivirusNome').value = linha.dataset.antivirus || '';
            document.getElementById('antivirusStatus').value = linha.dataset.status || 'nao_possui';
            document.getElementById('antivirusVencimento').value = linha.dataset.vencimento || '';
            atualizarVencimentoAntivirus();
        }

        function atualizarVencimentoAntivirus() {
            const status = document.getElementById('antivirusStatus');
            const vencimento = document.getElementById('antivirusVencimento');

            if (!status || !vencimento) {
                return;
            }

            const precisaData = status.value === 'possui';
            vencimento.disabled = !precisaData;
            if (!precisaData) {
                vencimento.value = '';
                vencimento.classList.remove('is-invalid');
            }
        }

        document.getElementById('btnNovoAntivirus')?.addEventListener('click', limparModalAntivirus);
        document.getElementById('antivirusStatus')?.addEventListener('change', atualizarVencimentoAntivirus);
        buscaAntivirus?.addEventListener('input', function() {
            antivirusPaginaAtual = 1;
            renderizarAntivirus();
        });
        filtroStatusAntivirus?.addEventListener('change', function() {
            antivirusPaginaAtual = 1;
            renderizarAntivirus();
        });

        linhasAntivirus.forEach(function(linha) {
            linha.querySelector('.btn-editar-antivirus')?.addEventListener('click', function(evento) {
                evento.stopPropagation();
                preencherModalAntivirus(linha);
                instanciaModalAntivirus?.show();
            });

            linha.querySelector('.btn-excluir-antivirus')?.addEventListener('click', function(evento) {
                evento.stopPropagation();
                document.getElementById('excluirAntivirusId').value = linha.dataset.id || '';
                document.getElementById('excluirAntivirusTexto').textContent = linha.dataset.colaborador || '';
                instanciaModalExcluirAntivirus?.show();
            });

            linha.addEventListener('click', function() {
                preencherModalAntivirus(linha);
                instanciaModalAntivirus?.show();
            });
        });

        document.getElementById('formAntivirus')?.addEventListener('submit', function(evento) {
            let valido = true;
            const colaborador = document.getElementById('antivirusColaborador');
            const status = document.getElementById('antivirusStatus');
            const vencimento = document.getElementById('antivirusVencimento');

            colaborador.classList.toggle('is-invalid', colaborador.value.trim() === '');
            if (colaborador.value.trim() === '') {
                valido = false;
            }

            vencimento.classList.toggle('is-invalid', status.value === 'possui' && vencimento.value.trim() === '');
            if (status.value === 'possui' && vencimento.value.trim() === '') {
                valido = false;
            }

            if (!valido) {
                evento.preventDefault();
            }
        });

        document.getElementById('btnImprimirAntivirus')?.addEventListener('click', function() {
            const filtradas = new Set(linhasAntivirusFiltradas());
            linhasAntivirus.forEach(function(linha) {
                linha.classList.toggle('d-none', !filtradas.has(linha));
            });
            window.print();
            renderizarAntivirus();
        });

        document.querySelectorAll('.alerta-temporario').forEach(function(alerta) {
            setTimeout(function() {
                alerta.remove();
            }, 4000);
        });

        renderizarAntivirus();
    </script>
</body>

</html>