<?php
if (!isset($titulo, $subtitulo, $campoStatus, $opcoesStatus)) {
    exit('Configuracao da pagina nao informada.');
}

if (isset($moduloPermissao)) {
    exigirPermissao($moduloPermissao);
} else {
    exigirLogin();
}

$campoVencimento = $campoVencimento ?? null;
$mostrarVencimento = $campoVencimento !== null;
$placeholderBusca = $placeholderBusca ?? 'Buscar por codigo, cliente ou CNPJ...';
$tituloTabela = $tituloTabela ?? 'Clientes';
$voltarUrl = $voltarUrl ?? (strpos($campoStatus, 'procuracao_') === 0 ? 'procuracoes.php' : 'home.php');
$mostrarConferenciaDados = in_array($campoStatus, ['cadastro_crf', 'procuracao_particular'], true);
$mostrarConferenciaSocio = $campoStatus === 'procuracao_particular';
$itensPorPagina = 15;

$camposPermitidos = [
    'contador',
    'cadastro_crf',
    'contrato_prestacao_servicos',
    'procuracao_receita_federal',
    'procuracao_conectividade',
    'procuracao_empregador_web',
    'procuracao_fgts',
    'procuracao_particular',
    'procuracao_sefaz',
];

$vencimentosPermitidos = [
    'vencimento_procuracao_receita_federal',
    'vencimento_procuracao_conectividade',
    'vencimento_procuracao_fgts',
];

if (!in_array($campoStatus, $camposPermitidos, true)) {
    exit('Campo de controle invalido.');
}

if ($campoVencimento !== null && !in_array($campoVencimento, $vencimentosPermitidos, true)) {
    exit('Campo de vencimento invalido.');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('Conexao com o banco nao informada.');
}

if (!function_exists('controleClienteTemColuna')) {
    function controleClienteTemColuna(PDO $pdo, string $coluna): bool
    {
        static $cache = [];

        if (array_key_exists($coluna, $cache)) {
            return $cache[$coluna];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM clientes LIKE ?");
            $stmt->execute([$coluna]);
            $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$coluna] = false;
        }

        return $cache[$coluna];
    }
}

if (!controleClienteTemColuna($pdo, $campoStatus)) {
    $sqlSugerido = $sqlSugerido ?? 'Rode o SQL de atualizacao deste controle no banco de dados.';
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <?php include 'includes/head.php'; ?>
        <title><?= htmlspecialchars($titulo) ?></title>
    </head>

    <body class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="app-main">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="mb-1"><?= htmlspecialchars($titulo) ?></h3>
                        <p class="text-muted mb-0"><?= htmlspecialchars($subtitulo) ?></p>
                    </div>

                    <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>

                <div class="clientes-box">
                    <div class="alert alert-warning mb-0">
                        <strong>Controle ainda sem coluna no banco.</strong><br>
                        <?= htmlspecialchars($sqlSugerido) ?>
                    </div>
                </div>
            </div>
        </main>
    </body>

    </html>
<?php
    return;
}

if ($campoVencimento !== null && !controleClienteTemColuna($pdo, $campoVencimento)) {
    $sqlSugerido = $sqlSugerido ?? 'Rode o SQL de atualizacao deste controle no banco de dados.';
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <?php include 'includes/head.php'; ?>
        <title><?= htmlspecialchars($titulo) ?></title>
    </head>

    <body class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="app-main">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="mb-1"><?= htmlspecialchars($titulo) ?></h3>
                        <p class="text-muted mb-0"><?= htmlspecialchars($subtitulo) ?></p>
                    </div>

                    <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>

                <div class="clientes-box">
                    <div class="alert alert-warning mb-0">
                        <strong>Controle ainda sem coluna de vencimento no banco.</strong><br>
                        <?= htmlspecialchars($sqlSugerido) ?>
                    </div>
                </div>
            </div>
        </main>
    </body>

    </html>
<?php
    return;
}

$colunas = "id, codigo, documento, nome, {$campoStatus} AS status_controle";

if ($mostrarVencimento) {
    $colunas .= ", {$campoVencimento} AS vencimento_controle";
}

if ($mostrarConferenciaDados) {
    $colunaPendencia = $campoStatus === 'cadastro_crf'
        ? 'pendencia_crf_dados'
        : 'pendencia_procuracao_particular_dados';
    $mostrarConferenciaDados = controleClienteTemColuna($pdo, $colunaPendencia);

    if ($mostrarConferenciaDados) {
        $colunas .= ", {$colunaPendencia} AS pendencia_dados";
    }
}

$stmt = $pdo->query("
    SELECT {$colunas}
    FROM clientes
    WHERE cliente_contabil = 1
    " . clientesFiltroAtivos($pdo) . "
    " . empresaFiltroClienteDireto($pdo) . "
    " . ($filtroClientesExtra ?? '') . "
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('controleFormatarStatus')) {
    function controleFormatarStatus(?string $valor, array $opcoesStatus): string
    {
        if ($valor === null || $valor === '') {
            return 'Nao informado';
        }

        return $opcoesStatus[$valor] ?? $valor;
    }
}

if (!function_exists('controleClasseStatus')) {
    function controleClasseStatus(?string $valor): string
    {
        if (in_array($valor, ['sim', 'possui', 'cadastrado', 'ativa'], true)) {
            return 'bg-success';
        }

        if ($valor === 'paralisada') {
            return 'bg-secondary';
        }

        if (in_array($valor, ['nao', 'nao_possui', 'nao_cadastrado'], true)) {
            return 'bg-danger';
        }

        if (in_array($valor, ['goias', 'nao_tem_funcionario', 'dispensado'], true)) {
            return 'bg-info text-dark';
        }

        if ($valor === 'em_estudo') {
            return 'bg-warning text-dark';
        }

        return 'bg-warning text-dark';
    }
}

if (!function_exists('controleFormatarData')) {
    function controleFormatarData(?string $data): string
    {
        if (empty($data)) {
            return '-';
        }

        return date('d/m/Y', strtotime($data));
    }
}

if (!function_exists('controleFormatarPrazo')) {
    function controleFormatarPrazo(?string $vencimento): string
    {
        if (empty($vencimento)) {
            return '<span class="badge bg-secondary">Sem vencimento</span>';
        }

        $hoje = new DateTime(date('Y-m-d'));
        $dataVencimento = new DateTime($vencimento);
        $diasRestantes = (int)$hoje->diff($dataVencimento)->format('%r%a');

        if ($diasRestantes < 0) {
            return '<span class="badge bg-danger">Vencido ha ' . abs($diasRestantes) . ' dias</span>';
        }

        if ($diasRestantes === 0) {
            return '<span class="badge bg-danger">Vence hoje</span>';
        }

        $textoDias = $diasRestantes . ($diasRestantes === 1 ? ' dia para vencer' : ' dias para vencer');

        if ($diasRestantes <= 15) {
            return '<span class="badge bg-danger-subtle text-dark">' . $textoDias . '</span>';
        }

        if ($diasRestantes <= 30) {
            return '<span class="badge bg-warning text-dark">' . $textoDias . '</span>';
        }

        return '<span class="badge bg-success">' . $textoDias . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title><?= htmlspecialchars($titulo) ?></title>
    <style>
        .controle-acoes {
            white-space: nowrap;
            text-align: right;
        }

        .controle-vazio {
            padding: 34px 16px;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($titulo) ?></h3>
                    <p class="text-muted mb-0"><?= htmlspecialchars($subtitulo) ?></p>
                </div>

                <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <input
                        type="text"
                        class="form-control"
                        id="controleBusca"
                        placeholder="<?= htmlspecialchars($placeholderBusca) ?>">
                </div>
            </div>

            <div class="clientes-box">
                <div class="table-responsive">
                    <table class="table align-middle controle-tabela">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Cliente</th>
                                <th>Documento</th>
                                <th>Status</th>
                                <?php if ($mostrarVencimento): ?>
                                    <th>Vencimento</th>
                                    <th>Prazo</th>
                                <?php endif; ?>
                                <?php if ($mostrarConferenciaDados): ?>
                                    <th>Conferência</th>
                                <?php endif; ?>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="controleTabelaCorpo">
                            <?php foreach ($clientes as $cliente): ?>
                                <?php
                                $statusAtual = $cliente['status_controle'] ?? '';
                                $vencimentoAtual = $cliente['vencimento_controle'] ?? '';
                                $textoBusca = strtolower(
                                    ($cliente['codigo'] ?? '') . ' ' .
                                        ($cliente['nome'] ?? '') . ' ' .
                                        ($cliente['documento'] ?? '')
                                );
                                ?>
                                <tr class="linha-controle"
                                    data-busca="<?= htmlspecialchars($textoBusca) ?>"
                                    data-id="<?= (int)$cliente['id'] ?>"
                                    data-codigo="<?= htmlspecialchars($cliente['codigo'] ?? '') ?>"
                                    data-nome="<?= htmlspecialchars($cliente['nome'] ?? '') ?>"
                                    data-status="<?= htmlspecialchars($statusAtual) ?>"
                                    data-vencimento="<?= htmlspecialchars($vencimentoAtual) ?>">
                                    <td><?= htmlspecialchars($cliente['codigo'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($cliente['nome'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($cliente['documento'] ?? '') ?></td>
                                    <td>
                                        <span class="badge status-badge <?= controleClasseStatus($statusAtual) ?>">
                                            <?= htmlspecialchars(controleFormatarStatus($statusAtual, $opcoesStatus)) ?>
                                        </span>
                                    </td>
                                    <?php if ($mostrarVencimento): ?>
                                        <td class="vencimento-controle"><?= htmlspecialchars(controleFormatarData($vencimentoAtual)) ?></td>
                                        <td class="prazo-controle"><?= controleFormatarPrazo($vencimentoAtual) ?></td>
                                    <?php endif; ?>
                                    <?php if ($mostrarConferenciaDados): ?>
                                        <td class="conferencia-controle">
                                            <?php if (!empty($cliente['pendencia_dados'])): ?>
                                                <span class="badge bg-warning text-dark">A conferir</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Ok</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="controle-acoes">
                                        <button type="button"
                                            class="btn btn-outline-primary btn-sm btn-editar-controle"
                                            title="Editar"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalControle">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="controleVazio" class="controle-vazio d-none">
                    Nenhum cliente encontrado.
                </div>

                <div class="mt-3" id="controlePaginacao"></div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalControle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="formControle">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Editar <?= htmlspecialchars($titulo) ?></h5>
                            <p class="text-muted mb-0" id="modalControleCliente"></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="id" id="controleId">
                        <input type="hidden" name="campo_status" value="<?= htmlspecialchars($campoStatus) ?>">
                        <input type="hidden" name="campo_vencimento" value="<?= htmlspecialchars($campoVencimento ?? '') ?>">

                        <div class="mb-3">
                            <label for="controleStatus" class="form-label">Status</label>
                            <select class="form-select" name="status" id="controleStatus" required>
                                <option value="">Selecione</option>
                                <?php foreach ($opcoesStatus as $valor => $rotulo): ?>
                                    <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Selecione o status.</div>
                        </div>

                        <?php if ($mostrarVencimento): ?>
                            <div class="mb-3" id="grupoVencimentoControle">
                                <label for="controleVencimento" class="form-label">Vencimento</label>
                                <input type="date" class="form-control" name="vencimento" id="controleVencimento">
                                <div class="invalid-feedback">Informe o vencimento.</div>
                            </div>
                        <?php endif; ?>

                        <?php if ($mostrarConferenciaDados): ?>
                            <div class="border rounded p-3">
                                <h6 class="mb-3">Conferencia dos dados</h6>

                                <div class="mb-3">
                                    <label class="form-label d-block">A razao social esta correta?</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="razao_social_correta" id="controleRazaoSim" value="sim" checked>
                                        <label class="form-check-label" for="controleRazaoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="razao_social_correta" id="controleRazaoNao" value="nao">
                                        <label class="form-check-label" for="controleRazaoNao">Nao</label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label d-block">O endereco esta correto?</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="endereco_correto" id="controleEnderecoSim" value="sim" checked>
                                        <label class="form-check-label" for="controleEnderecoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="endereco_correto" id="controleEnderecoNao" value="nao">
                                        <label class="form-check-label" for="controleEnderecoNao">Nao</label>
                                    </div>
                                </div>

                                <?php if ($mostrarConferenciaSocio): ?>
                                    <div>
                                        <label class="form-label d-block">O socio esta correto?</label>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="socio_correto" id="controleSocioSim" value="sim" checked>
                                            <label class="form-check-label" for="controleSocioSim">Sim</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="socio_correto" id="controleSocioNao" value="nao">
                                            <label class="form-check-label" for="controleSocioNao">Nao</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-danger d-none mt-3 mb-0" id="alertaControle"></div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const controleOpcoesStatus = <?= json_encode($opcoesStatus) ?>;
        const controleCampoVencimento = <?= json_encode($campoVencimento) ?>;
        const controlePossuiVencimento = <?= $mostrarVencimento ? 'true' : 'false' ?>;
        const controlePossuiConferencia = <?= $mostrarConferenciaDados ? 'true' : 'false' ?>;
        let controleItensPorPagina = Number(localStorage.getItem('controleItensPorPagina') || <?= (int)$itensPorPagina ?>);
        controleItensPorPagina = [15, 30, 60, 90].includes(controleItensPorPagina) ? controleItensPorPagina : 15;
        const controleLinhas = Array.from(document.querySelectorAll('.linha-controle'));
        const controleBusca = document.getElementById('controleBusca');
        const controlePaginacao = document.getElementById('controlePaginacao');
        const controleVazio = document.getElementById('controleVazio');
        const modalControleEl = document.getElementById('modalControle');
        const modalControle = bootstrap.Modal.getOrCreateInstance(modalControleEl);
        const formControle = document.getElementById('formControle');
        const alertaControle = document.getElementById('alertaControle');
        const controleId = document.getElementById('controleId');
        const controleStatus = document.getElementById('controleStatus');
        const controleVencimento = document.getElementById('controleVencimento');
        const modalControleCliente = document.getElementById('modalControleCliente');
        let controleLinhaAtual = null;
        let controlePaginaAtual = 1;
        let controleSalvando = false;
        let controleVencimentoInicial = '';

        function controleClasseStatus(status) {
            if (['sim', 'possui', 'cadastrado', 'ativa'].includes(status)) {
                return 'badge status-badge bg-success';
            }

            if (status === 'paralisada') {
                return 'badge status-badge bg-secondary';
            }

            if (['nao', 'nao_possui', 'nao_cadastrado'].includes(status)) {
                return 'badge status-badge bg-danger';
            }

            if (['goias', 'nao_tem_funcionario', 'dispensado'].includes(status)) {
                return 'badge status-badge bg-info text-dark';
            }

            if (status === 'em_estudo') {
                return 'badge status-badge bg-warning text-dark';
            }

            return 'badge status-badge bg-warning text-dark';
        }

        function controleTextoStatus(status) {
            return controleOpcoesStatus[status] || 'Nao informado';
        }

        function controleDataBr(data) {
            if (!data) {
                return '-';
            }

            const partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }

        function controleTextoPrazo(vencimento) {
            if (!vencimento) {
                return '<span class="badge bg-secondary">Sem vencimento</span>';
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
            const diferenca = Math.round((data - hoje) / 86400000);

            if (diferenca < 0) {
                return '<span class="badge bg-danger">Vencido ha ' + Math.abs(diferenca) + ' dias</span>';
            }

            if (diferenca === 0) {
                return '<span class="badge bg-danger">Vence hoje</span>';
            }

            if (diferenca <= 15) {
                return '<span class="badge bg-danger-subtle text-dark">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
            }

            if (diferenca <= 30) {
                return '<span class="badge bg-warning text-dark">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
            }

            return '<span class="badge bg-success">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
        }

        function controleLinhasFiltradas() {
            const termo = (controleBusca.value || '').trim().toLowerCase();

            if (!termo) {
                return controleLinhas;
            }

            return controleLinhas.filter((linha) => linha.dataset.busca.includes(termo));
        }

        function controleRenderizar() {
            const filtradas = controleLinhasFiltradas();
            const totalPaginas = Math.max(1, Math.ceil(filtradas.length / controleItensPorPagina));

            if (controlePaginaAtual > totalPaginas) {
                controlePaginaAtual = totalPaginas;
            }

            const inicio = (controlePaginaAtual - 1) * controleItensPorPagina;
            const fim = inicio + controleItensPorPagina;
            const visiveis = new Set(filtradas.slice(inicio, fim));

            controleLinhas.forEach((linha) => {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            controleVazio.classList.toggle('d-none', filtradas.length > 0);
            controlePaginacao.innerHTML = '';

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
            campoLimite.value = String(controleItensPorPagina);
            campoLimite.addEventListener('change', () => {
                controleItensPorPagina = Number(campoLimite.value);
                localStorage.setItem('controleItensPorPagina', String(controleItensPorPagina));
                controlePaginaAtual = 1;
                controleRenderizar();
            });
            if (filtradas.length <= controleItensPorPagina) {

                return;

            }


            controlePaginacao.appendChild(seletorLimite);

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            function adicionarItem(rotulo, pagina, desabilitado, ativo) {
                const item = document.createElement('li');
                item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'page-link';
                botao.textContent = rotulo;
                botao.disabled = desabilitado;
                botao.addEventListener('click', () => {
                    controlePaginaAtual = pagina;
                    controleRenderizar();
                });

                item.appendChild(botao);
                lista.appendChild(item);
            }

            adicionarItem('Anterior', Math.max(1, controlePaginaAtual - 1), controlePaginaAtual <= 1, false);

            const paginasVisiveis = [];
            let ultimaPagina = 0;

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - controlePaginaAtual) <= 2) {
                    if (ultimaPagina && pagina - ultimaPagina > 1) {
                        paginasVisiveis.push('...');
                    }

                    paginasVisiveis.push(pagina);
                    ultimaPagina = pagina;
                }
            }

            paginasVisiveis.forEach((pagina) => {
                if (pagina === '...') {
                    adicionarItem('...', controlePaginaAtual, true, false);
                    return;
                }

                adicionarItem(String(pagina), pagina, false, pagina === controlePaginaAtual);
            });

            adicionarItem('Próxima', Math.min(totalPaginas, controlePaginaAtual + 1), controlePaginaAtual >= totalPaginas, false);

            nav.appendChild(lista);
            controlePaginacao.appendChild(nav);
        }

        controleBusca.addEventListener('input', () => {
            controlePaginaAtual = 1;
            controleRenderizar();
        });

        document.querySelectorAll('.btn-editar-controle').forEach((botao) => {
            botao.addEventListener('click', () => {
                controleLinhaAtual = botao.closest('.linha-controle');
                alertaControle.classList.add('d-none');
                alertaControle.textContent = '';
                formControle.classList.remove('was-validated');

                controleId.value = controleLinhaAtual.dataset.id || '';
                controleStatus.value = controleLinhaAtual.dataset.status || '';
                modalControleCliente.textContent = (controleLinhaAtual.dataset.codigo || '') + ' - ' + (controleLinhaAtual.dataset.nome || '');

                if (controlePossuiVencimento && controleVencimento) {
                    controleVencimentoInicial = controleLinhaAtual.dataset.vencimento || '';
                    controleVencimento.value = controleVencimentoInicial;
                    controleVencimento.required = controleStatus.value === 'possui';
                    controleVencimento.disabled = controleStatus.value !== 'possui';

                    if (window.sincronizarCalendarioCampo) {
                        window.sincronizarCalendarioCampo(controleVencimento);
                    }
                }

                if (controlePossuiConferencia) {
                    formControle.querySelectorAll('input[type="radio"][value="sim"]').forEach((radio) => {
                        radio.checked = true;
                    });
                }
            });
        });

        if (controlePossuiVencimento && controleStatus && controleVencimento) {
            controleStatus.addEventListener('change', () => {
                const exigeVencimento = controleStatus.value === 'possui';
                controleVencimento.required = exigeVencimento;
                controleVencimento.disabled = !exigeVencimento;

                if (!exigeVencimento) {
                    controleVencimento.value = '';
                } else {
                    controleVencimento.focus();
                }

                if (window.sincronizarCalendarioCampo) {
                    window.sincronizarCalendarioCampo(controleVencimento);
                }
            });
        }

        formControle.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (controleSalvando) {
                return;
            }

            alertaControle.classList.add('d-none');
            alertaControle.textContent = '';

            if (!formControle.checkValidity()) {
                formControle.classList.add('was-validated');
                return;
            }

            const dados = new FormData(formControle);
            controleSalvando = true;

            try {
                const resposta = await fetch('api_controles.php', {
                    method: 'POST',
                    body: dados,
                });
                const texto = (await resposta.text()).trim();

                if (texto !== 'ok') {
                    alertaControle.textContent = texto === 'vencimento_obrigatorio' ?
                        'Informe o vencimento.' :
                        'Nao foi possivel salvar agora.';
                    alertaControle.classList.remove('d-none');
                    return;
                }

                const status = controleStatus.value;
                const vencimento = controlePossuiVencimento && controleVencimento ? controleVencimento.value : '';
                controleVencimentoInicial = vencimento;

                controleLinhaAtual.dataset.status = status;
                controleLinhaAtual.dataset.vencimento = vencimento;

                const badge = controleLinhaAtual.querySelector('.status-badge');
                badge.className = controleClasseStatus(status);
                badge.textContent = controleTextoStatus(status);

                if (controlePossuiVencimento) {
                    controleLinhaAtual.querySelector('.vencimento-controle').textContent = controleDataBr(vencimento);
                    controleLinhaAtual.querySelector('.prazo-controle').innerHTML = controleTextoPrazo(vencimento);
                }

                if (controlePossuiConferencia) {
                    const algumaPendencia = Array.from(formControle.querySelectorAll('input[type="radio"][value="nao"]'))
                        .some((radio) => radio.checked);
                    const conferencia = controleLinhaAtual.querySelector('.conferencia-controle');
                    conferencia.innerHTML = algumaPendencia ?
                        '<span class="badge bg-warning text-dark">A conferir</span>' :
                        '<span class="badge bg-success">Ok</span>';
                }

                modalControle.hide();
            } catch (erro) {
                alertaControle.textContent = 'Nao foi possivel comunicar com o servidor.';
                alertaControle.classList.remove('d-none');
            } finally {
                controleSalvando = false;
            }
        });

        modalControleEl.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            formControle.requestSubmit();
        });

        controleRenderizar();
    </script>
</body>

</html>