<?php
require 'config.php';

exigirPermissao('alvaras');

$estruturaGoias = logiTabelaExiste($pdo, 'cliente_alvaras_goias');
$paralisacaoGoiasDisponivel = logiColunaExiste($pdo, 'clientes', 'paralisacao_status')
    && logiColunaExiste($pdo, 'clientes', 'paralisacao_fim');
$orgaosGoias = [
    'bombeiros' => 'Bombeiros',
    'vigilancia' => 'Vigilância',
    'prefeitura' => 'Prefeitura',
];
$clientesGoias = [];
$alvarasPorCliente = [];

if ($estruturaGoias) {
    $colunasParalisacaoGoias = $paralisacaoGoiasDisponivel
        ? ', paralisacao_status, paralisacao_fim'
        : '';

    $stmt = $pdo->query("
        SELECT id, codigo, documento, nome, uf, alvara, cadastro_df_legal{$colunasParalisacaoGoias}
        FROM clientes
        WHERE cliente_contabil = 1
          " . clientesFiltroAtivos($pdo) . "
          " . empresaFiltroClienteDireto($pdo) . "
          AND (
            UPPER(COALESCE(uf, '')) = 'GO'
            OR alvara = 'goias'
            OR cadastro_df_legal = 'goias'
          )
        ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
    ");
    $clientesGoias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtAlvaras = $pdo->query("
        SELECT ag.*
        FROM cliente_alvaras_goias ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        WHERE 1 = 1
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");

    foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvara) {
        $alvarasPorCliente[(int)$alvara['cliente_id']][$alvara['orgao_codigo']] = $alvara;
    }
}

function alvaraGoiasData(?string $data): string
{
    return empty($data) ? '-' : date('d/m/Y', strtotime($data));
}

function alvaraGoiasResumo(array $cliente, array $alvaras, array $orgaos): string
{
    if (($cliente['paralisacao_status'] ?? '') === 'paralisada'
        && (empty($cliente['paralisacao_fim']) || $cliente['paralisacao_fim'] >= date('Y-m-d'))
    ) {
        return '<span class="badge bg-secondary">Empresa paralisada</span>';
    }

    $pendentes = 0;
    $vencidos = 0;
    $hoje = date('Y-m-d');

    foreach (array_keys($orgaos) as $codigo) {
        $alvara = $alvaras[$codigo] ?? [];
        $situacao = $alvara['situacao'] ?? '';
        $vencimento = $alvara['vencimento'] ?? '';

        if (in_array($situacao, ['', 'nao_informado', 'em_estudo'], true)) {
            $pendentes++;
        } elseif ($situacao === 'com_vencimento' && ($vencimento === '' || $vencimento < $hoje)) {
            $vencidos++;
        }
    }

    if ($vencidos > 0) {
        return '<span class="badge bg-danger">' . $vencidos . ' vencido(s)</span>';
    }

    if ($pendentes > 0) {
        return '<span class="badge bg-warning text-dark">' . $pendentes . ' pendente(s)</span>';
    }

    return '<span class="badge bg-success">Em dia</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Alvarás Goiás</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Alvarás Goiás</h3>
                    <p class="text-muted mb-0">Bombeiros, Vigilância e Prefeitura com vencimento, taxa e vistoria</p>
                </div>

                <a href="alvaras.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (!$estruturaGoias): ?>
                <div class="clientes-box">
                    <div class="alert alert-warning mb-0">
                        <strong>Controle ainda sem tabela no banco.</strong><br>
                        Rode o SQL <code>sql/paralisacoes_alvaras_goias.sql</code> no banco de dados.
                    </div>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-5">
                        <input type="text" id="buscaAlvaraGoias" class="form-control" placeholder="Buscar por código, cliente ou CNPJ...">
                    </div>
                </div>

                <div class="clientes-box">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cliente</th>
                                    <th>CNPJ/CPF</th>
                                    <th>UF</th>
                                    <th>Status</th>
                                    <th>Próximo vencimento</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientesGoias as $cliente): ?>
                                    <?php
                                    $clienteId = (int)$cliente['id'];
                                    $alvarasCliente = $alvarasPorCliente[$clienteId] ?? [];
                                    $busca = strtolower(($cliente['codigo'] ?? '') . ' ' . ($cliente['nome'] ?? '') . ' ' . ($cliente['documento'] ?? ''));
                                    $clienteParalisado = ($cliente['paralisacao_status'] ?? '') === 'paralisada'
                                        && (empty($cliente['paralisacao_fim']) || $cliente['paralisacao_fim'] >= date('Y-m-d'));
                                    $vencimentos = [];

                                    foreach ($alvarasCliente as $alvara) {
                                        if (($alvara['situacao'] ?? '') === 'com_vencimento' && !empty($alvara['vencimento'])) {
                                            $vencimentos[] = $alvara['vencimento'];
                                        }
                                    }

                                    sort($vencimentos);
                                    ?>
                                    <tr
                                        class="linha-alvara-goias"
                                        data-busca="<?= htmlspecialchars($busca) ?>"
                                        data-id="<?= $clienteId ?>"
                                        data-codigo="<?= htmlspecialchars($cliente['codigo'] ?? '') ?>"
                                        data-nome="<?= htmlspecialchars($cliente['nome'] ?? '') ?>"
                                        data-paralisada="<?= $clienteParalisado ? '1' : '0' ?>"
                                        data-alvaras="<?= htmlspecialchars(json_encode($alvarasCliente, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                        <td><?= htmlspecialchars($cliente['codigo'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cliente['nome'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cliente['documento'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cliente['uf'] ?: 'GO') ?></td>
                                        <td class="status-goias"><?= alvaraGoiasResumo($cliente, $alvarasCliente, $orgaosGoias) ?></td>
                                        <td class="vencimento-goias"><?= $clienteParalisado ? '-' : alvaraGoiasData($vencimentos[0] ?? '') ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-primary btn-sm btn-editar-goias" data-bs-toggle="modal" data-bs-target="#modalAlvaraGoias">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="alvarasGoiasVazio" class="d-none">
                                    <td colspan="7" class="text-center text-muted py-4">Nenhum cliente encontrado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3" id="paginacaoAlvarasGoias"></div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaGoias): ?>
        <div class="modal fade" id="modalAlvaraGoias" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form id="formAlvaraGoias" novalidate>
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Editar alvarás Goiás</h5>
                                <p class="text-muted mb-0" id="clienteModalGoias"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body">
                            <input type="hidden" name="cliente_id" id="goiasClienteId">
                            <div class="alert alert-danger d-none" id="alertaAlvaraGoias"></div>

                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Órgão</th>
                                            <th>Situação</th>
                                            <th>Vencimento</th>
                                            <th>Taxa</th>
                                            <th>Vistoria prévia</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orgaosGoias as $codigo => $nome): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($nome) ?></td>
                                                <td>
                                                    <select class="form-select goias-situacao" name="orgaos[<?= htmlspecialchars($codigo) ?>][situacao]" data-codigo="<?= htmlspecialchars($codigo) ?>">
                                                        <option value="nao_informado">Não informado</option>
                                                        <option value="com_vencimento">Com vencimento</option>
                                                        <option value="dispensado">Dispensado</option>
                                                        <option value="em_estudo">Em estudo</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="date" class="form-control goias-vencimento" name="orgaos[<?= htmlspecialchars($codigo) ?>][vencimento]" data-codigo="<?= htmlspecialchars($codigo) ?>" disabled>
                                                </td>
                                                <td>
                                                    <input type="text" inputmode="decimal" class="form-control campo-moeda goias-taxa" name="orgaos[<?= htmlspecialchars($codigo) ?>][taxa]" placeholder="0,00">
                                                </td>
                                                <td>
                                                    <select class="form-select goias-vistoria" name="orgaos[<?= htmlspecialchars($codigo) ?>][vistoria_previa]">
                                                        <option value="sim">Sim</option>
                                                        <option value="nao">Não</option>
                                                        <option value="dispensada">Dispensada</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="<?= assetUrl('assets/financeiro.js') ?>"></script>
        <script>
            const orgaosGoias = <?= json_encode($orgaosGoias, JSON_UNESCAPED_UNICODE) ?>;
            const linhasGoias = Array.from(document.querySelectorAll('.linha-alvara-goias'));
            const buscaGoias = document.getElementById('buscaAlvaraGoias');
            const paginacaoGoias = document.getElementById('paginacaoAlvarasGoias');
            const vazioGoias = document.getElementById('alvarasGoiasVazio');
            const formGoias = document.getElementById('formAlvaraGoias');
            const modalGoias = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlvaraGoias'));
            let goiasPorPagina = Number(localStorage.getItem('goiasPorPagina') || 15);
            goiasPorPagina = [15, 30, 60, 90].includes(goiasPorPagina) ? goiasPorPagina : 15;
            let paginaGoias = 1;
            let linhaGoiasAtual = null;

            function moedaParaNumero(valor) {
                const normalizado = String(valor || '').replace(/\./g, '').replace(',', '.');
                const numero = Number(normalizado);
                return Number.isFinite(numero) ? numero : 0;
            }

            function dataBr(data) {
                if (!data) return '-';
                const partes = data.split('-');
                return partes[2] + '/' + partes[1] + '/' + partes[0];
            }

            function formatarMoeda(valor) {
                return Number(valor || 0).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function atualizarCamposVencimentoGoias() {
                document.querySelectorAll('.goias-situacao').forEach((campo) => {
                    const data = document.querySelector('.goias-vencimento[data-codigo="' + campo.dataset.codigo + '"]');
                    data.disabled = campo.value !== 'com_vencimento';
                    data.required = campo.value === 'com_vencimento';

                    if (campo.value !== 'com_vencimento') {
                        data.value = '';
                    }
                });
            }

            function resumoGoias(alvaras) {
                if (linhaGoiasAtual && linhaGoiasAtual.dataset.paralisada === '1') {
                    return '<span class="badge bg-secondary">Empresa paralisada</span>';
                }

                let pendentes = 0;
                let vencidos = 0;
                const hoje = <?= json_encode(date('Y-m-d')) ?>;

                Object.keys(orgaosGoias).forEach((codigo) => {
                    const alvara = alvaras[codigo] || {};
                    const situacao = alvara.situacao || '';
                    const vencimento = alvara.vencimento || '';

                    if (['', 'nao_informado', 'em_estudo'].includes(situacao)) {
                        pendentes++;
                    } else if (situacao === 'com_vencimento' && (!vencimento || vencimento < hoje)) {
                        vencidos++;
                    }
                });

                if (vencidos > 0) return '<span class="badge bg-danger">' + vencidos + ' vencido(s)</span>';
                if (pendentes > 0) return '<span class="badge bg-warning text-dark">' + pendentes + ' pendente(s)</span>';
                return '<span class="badge bg-success">Em dia</span>';
            }

            function filtrarGoias() {
                const termo = (buscaGoias.value || '').trim().toLowerCase();
                return linhasGoias.filter((linha) => linha.dataset.busca.includes(termo));
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
                    paginaGoias = pagina;
                    renderizarGoias();
                });
                item.appendChild(botao);
                lista.appendChild(item);
            }

            function renderizarGoias() {
                const filtradas = filtrarGoias();
                const totalPaginas = Math.max(1, Math.ceil(filtradas.length / goiasPorPagina));
                paginaGoias = Math.min(paginaGoias, totalPaginas);
                const inicio = (paginaGoias - 1) * goiasPorPagina;
                const visiveis = new Set(filtradas.slice(inicio, inicio + goiasPorPagina));
                linhasGoias.forEach((linha) => linha.classList.toggle('d-none', !visiveis.has(linha)));
                vazioGoias.classList.toggle('d-none', filtradas.length > 0);
                paginacaoGoias.innerHTML = '';

                if (filtradas.length <= goiasPorPagina) return;

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
                campoLimite.value = String(goiasPorPagina);
                campoLimite.addEventListener('change', () => {
                    goiasPorPagina = Number(campoLimite.value);
                    localStorage.setItem('goiasPorPagina', String(goiasPorPagina));
                    paginaGoias = 1;
                    renderizarGoias();
                });
                paginacaoGoias.appendChild(seletor);

                const nav = document.createElement('nav');
                const lista = document.createElement('ul');
                lista.className = 'pagination justify-content-center mt-3';
                adicionarPagina(lista, 'Anterior', Math.max(1, paginaGoias - 1), paginaGoias <= 1, false);

                for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                    if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - paginaGoias) <= 2) {
                        adicionarPagina(lista, String(pagina), pagina, false, pagina === paginaGoias);
                    }
                }

                adicionarPagina(lista, 'Próxima', Math.min(totalPaginas, paginaGoias + 1), paginaGoias >= totalPaginas, false);
                nav.appendChild(lista);
                paginacaoGoias.appendChild(nav);
            }

            document.querySelectorAll('.btn-editar-goias').forEach((botao) => {
                botao.addEventListener('click', () => {
                    linhaGoiasAtual = botao.closest('.linha-alvara-goias');
                    const alvaras = JSON.parse(linhaGoiasAtual.dataset.alvaras || '{}');
                    formGoias.reset();
                    formGoias.classList.remove('was-validated');
                    document.getElementById('alertaAlvaraGoias').classList.add('d-none');
                    document.getElementById('goiasClienteId').value = linhaGoiasAtual.dataset.id;
                    document.getElementById('clienteModalGoias').textContent =
                        linhaGoiasAtual.dataset.codigo + ' - ' + linhaGoiasAtual.dataset.nome;

                    Object.keys(orgaosGoias).forEach((codigo) => {
                        const alvara = alvaras[codigo] || {};
                        document.querySelector('.goias-situacao[data-codigo="' + codigo + '"]').value = alvara.situacao || 'nao_informado';
                        document.querySelector('.goias-vencimento[data-codigo="' + codigo + '"]').value = alvara.vencimento || '';
                        document.querySelector('[name="orgaos[' + codigo + '][taxa]"]').value = formatarMoeda(alvara.taxa || 0);
                        document.querySelector('[name="orgaos[' + codigo + '][vistoria_previa]"]').value = alvara.vistoria_previa || 'sim';
                    });

                    atualizarCamposVencimentoGoias();
                });
            });

            document.querySelectorAll('.goias-situacao').forEach((campo) => {
                campo.addEventListener('change', atualizarCamposVencimentoGoias);
            });

            formGoias.addEventListener('submit', async (evento) => {
                evento.preventDefault();

                if (!formGoias.checkValidity()) {
                    formGoias.classList.add('was-validated');
                    return;
                }

                const alerta = document.getElementById('alertaAlvaraGoias');
                alerta.classList.add('d-none');
                const dados = new FormData(formGoias);

                try {
                    const resposta = await fetch('api_alvaras_goias.php', {
                        method: 'POST',
                        body: dados
                    });
                    const retorno = await resposta.json();

                    if (!retorno.sucesso) {
                        alerta.textContent = retorno.mensagem || 'Não foi possível salvar.';
                        alerta.classList.remove('d-none');
                        return;
                    }

                    linhaGoiasAtual.dataset.alvaras = JSON.stringify(retorno.alvaras || {});
                    linhaGoiasAtual.querySelector('.status-goias').innerHTML = resumoGoias(retorno.alvaras || {});

                    const vencimentos = Object.values(retorno.alvaras || {})
                        .filter((alvara) => alvara.situacao === 'com_vencimento' && alvara.vencimento)
                        .map((alvara) => alvara.vencimento)
                        .sort();
                    linhaGoiasAtual.querySelector('.vencimento-goias').textContent =
                        linhaGoiasAtual.dataset.paralisada === '1' ? '-' : dataBr(vencimentos[0] || '');
                    modalGoias.hide();
                } catch (erro) {
                    alerta.textContent = 'Não foi possível comunicar com o servidor.';
                    alerta.classList.remove('d-none');
                }
            });

            buscaGoias.addEventListener('input', () => {
                paginaGoias = 1;
                renderizarGoias();
            });

            renderizarGoias();
        </script>
    <?php endif; ?>
</body>

</html>