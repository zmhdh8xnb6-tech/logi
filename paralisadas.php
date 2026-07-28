<?php
require 'config.php';

exigirPermissao('paralisacoes');

$colunasObrigatorias = [
    'paralisacao_status',
    'paralisacao_inicio',
    'paralisacao_fim',
    'paralisacao_reativada_em',
    'paralisacao_bloqueio_ate',
];
$estruturaParalisacao = true;

foreach ($colunasObrigatorias as $coluna) {
    if (!logiColunaExiste($pdo, 'clientes', $coluna)) {
        $estruturaParalisacao = false;
        break;
    }
}

$clientes = [];

if ($estruturaParalisacao) {
    $stmt = $pdo->query("
        SELECT id, codigo, documento, nome, paralisacao_status, paralisacao_inicio,
               paralisacao_fim, paralisacao_reativada_em, paralisacao_bloqueio_ate
        FROM clientes
        WHERE cliente_contabil = 1
          " . clientesFiltroAtivos($pdo) . "
          " . empresaFiltroClienteDireto($pdo) . "
        ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
    ");
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function paralisacaoData(?string $data): string
{
    return empty($data) ? '-' : date('d/m/Y', strtotime($data));
}

function paralisacaoBadge(array $cliente): string
{
    if (($cliente['paralisacao_status'] ?? '') === 'paralisada') {
        return '<span class="badge bg-secondary">Paralisada</span>';
    }

    $bloqueio = $cliente['paralisacao_bloqueio_ate'] ?? '';

    if ($bloqueio !== '' && $bloqueio >= date('Y-m-d')) {
        return '<span class="badge bg-warning text-dark">Bloqueada para nova paralisação</span>';
    }

    return '<span class="badge bg-success">Ativa</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Paralisações</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Paralisações</h3>
                    <p class="text-muted mb-0">Controle de empresas com atividades interrompidas por até 5 anos</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (!$estruturaParalisacao): ?>
                <div class="clientes-box">
                    <div class="alert alert-warning mb-0">
                        <strong>Controle ainda sem colunas no banco.</strong><br>
                        Rode o SQL <code>sql/paralisacoes_alvaras_goias.sql</code> no banco de dados.
                    </div>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-5">
                        <input type="text" id="buscaParalisacao" class="form-control" placeholder="Buscar por código, cliente ou CNPJ...">
                    </div>
                </div>

                <div class="clientes-box">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cliente</th>
                                    <th>Documento</th>
                                    <th>Status</th>
                                    <th>Início</th>
                                    <th>Fim dos 5 anos</th>
                                    <th>Reativada em</th>
                                    <th>Bloqueio até</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientes as $cliente): ?>
                                    <?php
                                    $busca = strtolower(($cliente['codigo'] ?? '') . ' ' . ($cliente['nome'] ?? '') . ' ' . ($cliente['documento'] ?? ''));
                                    $paralisada = ($cliente['paralisacao_status'] ?? '') === 'paralisada';
                                    $bloqueada = !$paralisada
                                        && !empty($cliente['paralisacao_bloqueio_ate'])
                                        && $cliente['paralisacao_bloqueio_ate'] >= date('Y-m-d');
                                    ?>
                                    <tr
                                        class="linha-paralisacao"
                                        data-busca="<?= htmlspecialchars($busca) ?>"
                                        data-id="<?= (int)$cliente['id'] ?>"
                                        data-codigo="<?= htmlspecialchars($cliente['codigo'] ?? '') ?>"
                                        data-nome="<?= htmlspecialchars($cliente['nome'] ?? '') ?>"
                                        data-status="<?= htmlspecialchars($cliente['paralisacao_status'] ?? 'ativa') ?>"
                                        data-bloqueio="<?= htmlspecialchars($cliente['paralisacao_bloqueio_ate'] ?? '') ?>">
                                        <td><?= htmlspecialchars($cliente['codigo'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cliente['nome'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cliente['documento'] ?? '') ?></td>
                                        <td class="status-paralisacao"><?= paralisacaoBadge($cliente) ?></td>
                                        <td class="inicio-paralisacao"><?= paralisacaoData($cliente['paralisacao_inicio'] ?? '') ?></td>
                                        <td class="fim-paralisacao"><?= paralisacaoData($cliente['paralisacao_fim'] ?? '') ?></td>
                                        <td class="reativada-paralisacao"><?= paralisacaoData($cliente['paralisacao_reativada_em'] ?? '') ?></td>
                                        <td class="bloqueio-paralisacao"><?= paralisacaoData($cliente['paralisacao_bloqueio_ate'] ?? '') ?></td>
                                        <td class="text-end">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm btn-paralisacao"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalParalisacao"
                                                <?= $bloqueada ? 'title="Cliente bloqueado para nova paralisação até ' . htmlspecialchars(paralisacaoData($cliente['paralisacao_bloqueio_ate'])) . '"' : '' ?>>
                                                <i class="bi <?= $paralisada ? 'bi-arrow-counterclockwise' : 'bi-pause-circle' ?>"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="paralisacaoVazio" class="d-none">
                                    <td colspan="9" class="text-center text-muted py-4">Nenhum cliente encontrado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="paginacaoParalisacao" class="mt-3"></div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaParalisacao): ?>
        <div class="modal fade" id="modalParalisacao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form id="formParalisacao" novalidate>
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="tituloModalParalisacao">Paralisar empresa</h5>
                                <p class="text-muted mb-0" id="clienteModalParalisacao"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body">
                            <input type="hidden" name="cliente_id" id="paralisacaoClienteId">
                            <input type="hidden" name="acao" id="paralisacaoAcao">

                            <div class="alert alert-info" id="textoRegraParalisacao"></div>
                            <div class="alert alert-danger d-none" id="alertaParalisacao"></div>

                            <div class="mb-3">
                                <label for="paralisacaoData" class="form-label" id="labelDataParalisacao">Data da paralisação</label>
                                <input type="date" class="form-control" name="data" id="paralisacaoData" required>
                                <div class="invalid-feedback">Informe a data.</div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success" id="btnSalvarParalisacao">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            const hojeParalisacao = <?= json_encode(date('Y-m-d')) ?>;
            const linhasParalisacao = Array.from(document.querySelectorAll('.linha-paralisacao'));
            const buscaParalisacao = document.getElementById('buscaParalisacao');
            const paginacaoParalisacao = document.getElementById('paginacaoParalisacao');
            const paralisacaoVazio = document.getElementById('paralisacaoVazio');
            const modalParalisacao = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalParalisacao'));
            const formParalisacao = document.getElementById('formParalisacao');
            let paralisacaoPorPagina = Number(localStorage.getItem('paralisacaoPorPagina') || 15);
            paralisacaoPorPagina = [15, 30, 60, 90].includes(paralisacaoPorPagina) ? paralisacaoPorPagina : 15;
            let paginaParalisacao = 1;
            let linhaParalisacaoAtual = null;

            function dataBrParalisacao(data) {
                if (!data) return '-';
                const partes = data.split('-');
                return partes[2] + '/' + partes[1] + '/' + partes[0];
            }

            function filtrarParalisacao() {
                const termo = (buscaParalisacao.value || '').trim().toLowerCase();
                return linhasParalisacao.filter((linha) => linha.dataset.busca.includes(termo));
            }

            function adicionarPagina(lista, rotulo, pagina, desabilitado, ativo) {
                const item = document.createElement('li');
                item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'page-link';
                botao.textContent = rotulo;
                botao.disabled = desabilitado;
                botao.addEventListener('click', () => {
                    paginaParalisacao = pagina;
                    renderizarParalisacao();
                });
                item.appendChild(botao);
                lista.appendChild(item);
            }

            function renderizarParalisacao() {
                const filtradas = filtrarParalisacao();
                const totalPaginas = Math.max(1, Math.ceil(filtradas.length / paralisacaoPorPagina));
                paginaParalisacao = Math.min(paginaParalisacao, totalPaginas);

                const inicio = (paginaParalisacao - 1) * paralisacaoPorPagina;
                const visiveis = new Set(filtradas.slice(inicio, inicio + paralisacaoPorPagina));
                linhasParalisacao.forEach((linha) => linha.classList.toggle('d-none', !visiveis.has(linha)));
                paralisacaoVazio.classList.toggle('d-none', filtradas.length > 0);
                paginacaoParalisacao.innerHTML = '';

                if (filtradas.length <= paralisacaoPorPagina) return;

                const seletor = document.createElement('div');
                seletor.className = 'd-flex justify-content-end mb-2';
                seletor.innerHTML = `
                    <select class="form-select form-select-sm w-auto">
                        <option value="15">Mostrar 15</option>
                        <option value="30">Mostrar 30</option>
                        <option value="60">Mostrar 60</option>
                        <option value="90">Mostrar 90</option>
                    </select>
                `;
                const campoLimite = seletor.querySelector('select');
                campoLimite.value = String(paralisacaoPorPagina);
                campoLimite.addEventListener('change', () => {
                    paralisacaoPorPagina = Number(campoLimite.value);
                    localStorage.setItem('paralisacaoPorPagina', String(paralisacaoPorPagina));
                    paginaParalisacao = 1;
                    renderizarParalisacao();
                });
                paginacaoParalisacao.appendChild(seletor);

                const nav = document.createElement('nav');
                const lista = document.createElement('ul');
                lista.className = 'pagination justify-content-center mt-3';
                adicionarPagina(lista, 'Anterior', Math.max(1, paginaParalisacao - 1), paginaParalisacao <= 1, false);

                for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                    if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - paginaParalisacao) <= 2) {
                        adicionarPagina(lista, String(pagina), pagina, false, pagina === paginaParalisacao);
                    }
                }

                adicionarPagina(lista, 'Próxima', Math.min(totalPaginas, paginaParalisacao + 1), paginaParalisacao >= totalPaginas, false);
                nav.appendChild(lista);
                paginacaoParalisacao.appendChild(nav);
            }

            document.querySelectorAll('.btn-paralisacao').forEach((botao) => {
                botao.addEventListener('click', () => {
                    linhaParalisacaoAtual = botao.closest('.linha-paralisacao');
                    const status = linhaParalisacaoAtual.dataset.status;
                    const acao = status === 'paralisada' ? 'reativar' : 'paralisar';

                    formParalisacao.classList.remove('was-validated');
                    document.getElementById('alertaParalisacao').classList.add('d-none');
                    document.getElementById('paralisacaoClienteId').value = linhaParalisacaoAtual.dataset.id;
                    document.getElementById('paralisacaoAcao').value = acao;
                    document.getElementById('paralisacaoData').value = hojeParalisacao;
                    document.getElementById('clienteModalParalisacao').textContent =
                        linhaParalisacaoAtual.dataset.codigo + ' - ' + linhaParalisacaoAtual.dataset.nome;

                    if (acao === 'paralisar') {
                        document.getElementById('tituloModalParalisacao').textContent = 'Paralisar empresa';
                        document.getElementById('labelDataParalisacao').textContent = 'Data da paralisação';
                        document.getElementById('textoRegraParalisacao').textContent =
                            'A empresa ficará com atividades interrompidas por 5 anos. Durante esse período, CRF, certificado, alvarás, DF Legal e procurações dispensadas não entrarão em pendências.';
                    } else {
                        document.getElementById('tituloModalParalisacao').textContent = 'Reativar empresa';
                        document.getElementById('labelDataParalisacao').textContent = 'Data da reativação';
                        document.getElementById('textoRegraParalisacao').textContent =
                            'Após reativar, a empresa ficará bloqueada para nova paralisação por 3 anos.';
                    }
                });
            });

            formParalisacao.addEventListener('submit', async (evento) => {
                evento.preventDefault();

                if (!formParalisacao.checkValidity()) {
                    formParalisacao.classList.add('was-validated');
                    return;
                }

                const alerta = document.getElementById('alertaParalisacao');
                alerta.classList.add('d-none');

                try {
                    const resposta = await fetch('api_paralisacoes.php', {
                        method: 'POST',
                        body: new FormData(formParalisacao)
                    });
                    const dados = await resposta.json();

                    if (!dados.sucesso) {
                        alerta.textContent = dados.mensagem || 'Não foi possível salvar.';
                        alerta.classList.remove('d-none');
                        return;
                    }

                    linhaParalisacaoAtual.dataset.status = dados.paralisacao.status;
                    linhaParalisacaoAtual.dataset.bloqueio = dados.paralisacao.bloqueio_ate || '';
                    linhaParalisacaoAtual.querySelector('.status-paralisacao').innerHTML =
                        dados.paralisacao.status === 'paralisada' ?
                        '<span class="badge bg-secondary">Paralisada</span>' :
                        (dados.paralisacao.bloqueio_ate ?
                            '<span class="badge bg-warning text-dark">Bloqueada para nova paralisação</span>' :
                            '<span class="badge bg-success">Ativa</span>');
                    linhaParalisacaoAtual.querySelector('.inicio-paralisacao').textContent = dataBrParalisacao(dados.paralisacao.inicio);
                    linhaParalisacaoAtual.querySelector('.fim-paralisacao').textContent = dataBrParalisacao(dados.paralisacao.fim);
                    linhaParalisacaoAtual.querySelector('.reativada-paralisacao').textContent = dataBrParalisacao(dados.paralisacao.reativada_em);
                    linhaParalisacaoAtual.querySelector('.bloqueio-paralisacao').textContent = dataBrParalisacao(dados.paralisacao.bloqueio_ate);

                    const icone = linhaParalisacaoAtual.querySelector('.btn-paralisacao i');
                    icone.className = 'bi ' + (dados.paralisacao.status === 'paralisada' ? 'bi-arrow-counterclockwise' : 'bi-pause-circle');
                    modalParalisacao.hide();
                } catch (erro) {
                    alerta.textContent = 'Não foi possível comunicar com o servidor.';
                    alerta.classList.remove('d-none');
                }
            });

            buscaParalisacao.addEventListener('input', () => {
                paginaParalisacao = 1;
                renderizarParalisacao();
            });

            renderizarParalisacao();
        </script>
    <?php endif; ?>
</body>

</html>