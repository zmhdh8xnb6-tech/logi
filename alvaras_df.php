<?php
require 'config.php';

exigirPermissao('alvaras');

$orgaosAlvara = [
    'ibram' => 'INSTITUTO BRASÍLIA AMBIENTAL - IBRAM',
    'cbmdf' => 'CORPO DE BOMBEIROS MILITAR DO DISTRITO FEDERAL - CBMDF',
    'df_legal' => 'SECRETARIA DE ESTADO DE PROTEÇÃO DA ORDEM URBANÍSTICA DO DISTRITO FEDERAL - DF LEGAL',
    'pcdf' => 'POLÍCIA CIVIL DO DISTRITO FEDERAL - PCDF',
    'seagri' => 'SECRETARIA DE ESTADO DE AGRICULTURA, ABASTECIMENTO E DESENVOLVIMENTO RURAL - SEAGRI',
    'seedf' => 'SECRETARIA DE EDUCAÇÃO DO DISTRITO FEDERAL - SEEDF',
    'sudesc' => 'SUBSECRETARIA DO SISTEMA DE DEFESA CIVIL - SUDESC',
    'visadf' => 'VIGILÂNCIA SANITÁRIA DO DISTRITO FEDERAL - VISADF',
];

$stmtClientes = $pdo->query("
    SELECT *
    FROM clientes
    WHERE cliente_contabil = 1
      AND COALESCE(uf, '') <> 'GO'
      AND COALESCE(alvara, '') <> 'goias'
      AND COALESCE(cadastro_df_legal, '') <> 'goias'
      " . clientesFiltroAtivos($pdo) . "
      " . empresaFiltroClienteDireto($pdo) . "
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

$alvarasPorCliente = [];
$stmtAlvaras = $pdo->query("
    SELECT ca.cliente_id, ca.orgao_codigo, ca.situacao, ca.vencimento
    FROM cliente_alvaras ca
    INNER JOIN clientes c ON c.id = ca.cliente_id
    WHERE 1 = 1
    " . empresaFiltroClienteDireto($pdo, 'c') . "
");

foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvara) {
    $alvarasPorCliente[(int)$alvara['cliente_id']][$alvara['orgao_codigo']] = [
        'situacao' => $alvara['situacao'],
        'vencimento' => $alvara['vencimento'] ?? '',
    ];
}

function textoSituacaoAlvaraDf(string $situacao): string
{
    return [
        'possui' => 'Possui',
        'nao_possui' => 'Não possui',
        'goias' => 'Goiás',
    ][$situacao] ?? 'Não informado';
}

function classeSituacaoAlvaraDf(string $situacao): string
{
    return [
        'possui' => 'bg-success',
        'nao_possui' => 'bg-danger',
        'goias' => 'bg-info text-dark',
    ][$situacao] ?? 'bg-warning text-dark';
}

function textoCadastroDfLegal(string $situacao): string
{
    return [
        'cadastrado' => 'Cadastrado',
        'nao_cadastrado' => 'Não cadastrado',
        'goias' => 'Goiás',
    ][$situacao] ?? 'Não informado';
}

function classeCadastroDfLegal(string $situacao, bool $dadosPendentes): string
{
    if ($situacao === 'cadastrado' && !$dadosPendentes) {
        return 'bg-success';
    }

    if ($situacao === 'nao_cadastrado') {
        return 'bg-danger';
    }

    if ($situacao === 'goias') {
        return 'bg-info text-dark';
    }

    return 'bg-warning text-dark';
}

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Alvarás DF e DF Legal</title>
    <style>
        .modal-alvaras-tabela th:first-child {
            min-width: 420px;
        }

        .modal-alvaras-tabela th:nth-child(2) {
            width: 190px;
        }

        .modal-alvaras-tabela th:nth-child(3) {
            width: 180px;
        }

        #modalEditarAlvarasDf .modal-content {
            overflow: hidden;
        }

        #formEditarAlvarasDf {
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 2rem);
            min-height: 0;
        }

        #formEditarAlvarasDf .modal-header,
        #formEditarAlvarasDf .modal-footer {
            flex-shrink: 0;
        }

        #formEditarAlvarasDf .modal-body {
            min-height: 0;
            overflow-y: scroll;
            scrollbar-gutter: stable;
        }

        #formEditarAlvarasDf .modal-body::-webkit-scrollbar {
            width: 10px;
        }

        #formEditarAlvarasDf .modal-body::-webkit-scrollbar-track {
            background: #f1f3f5;
        }

        #formEditarAlvarasDf .modal-body::-webkit-scrollbar-thumb {
            background: #adb5bd;
            border: 2px solid #f1f3f5;
            border-radius: 5px;
        }

        .consulta-orgaos-tabela {
            table-layout: fixed;
        }

        .consulta-orgaos-tabela th:first-child {
            width: 46%;
        }

        .consulta-orgaos-tabela th:nth-child(2) {
            width: 18%;
        }

        .consulta-orgaos-tabela th:nth-child(3) {
            width: 17%;
        }

        .consulta-orgaos-tabela th:nth-child(4) {
            width: 19%;
        }

        .consulta-orgaos-tabela td {
            padding-top: 14px;
            padding-bottom: 14px;
        }

        .consulta-orgaos-tabela tbody tr:hover td {
            background: #f4f8ff;
            cursor: pointer;
        }

        .consulta-orgao-sigla {
            display: block;
            font-size: 0.95rem;
            font-weight: 700;
            color: #212529;
        }

        .consulta-orgao-nome {
            display: block;
            margin-top: 2px;
            color: #6c757d;
            font-size: 0.78rem;
            line-height: 1.3;
        }

        .consulta-prazo {
            min-width: 92px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .modal-alvaras-tabela th:first-child {
                min-width: 280px;
            }

            #formEditarAlvarasDf {
                max-height: calc(100dvh - 1rem);
            }
        }
    </style>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Alvarás DF e DF Legal</h3>
                    <p class="text-muted mb-0">Acompanhe licenças, dispensas, vencimentos e o cadastro DF Legal</p>
                </div>

                <a href="alvaras.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div id="mensagemAlvaras" class="alert d-none" role="alert"></div>

            <div class="row g-2 mb-3">
                <div class="col-md-5">
                    <input type="text" id="buscaAlvaraDf" class="form-control" placeholder="Buscar por código, CNPJ/CPF ou cliente...">
                </div>
                <div class="col-md-3">
                    <select id="filtroAlvaraDf" class="form-select">
                        <option value="">Todos os alvarás</option>
                        <option value="possui">Possui</option>
                        <option value="nao_possui">Não possui</option>
                        <option value="nao_informado">Não informado</option>
                    </select>
                </div>
            </div>

            <div class="clientes-box">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Cliente</th>
                                <th>Alvarás DF</th>
                                <th>Cadastro DF Legal</th>
                                <th>Órgãos</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientes)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Nenhum cliente do Distrito Federal encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clientes as $cliente):
                                    $clienteId = (int)$cliente['id'];
                                    $situacaoAlvara = $cliente['alvara'] ?? '';
                                    $cadastroDfLegal = $cliente['cadastro_df_legal'] ?? '';
                                    $dadosDfLegalPendentes = !empty($cliente['pendencia_df_legal_dados']);
                                    $alvarasCliente = $alvarasPorCliente[$clienteId] ?? [];
                                ?>
                                    <tr
                                        class="linha-cliente-alvara"
                                        data-alvara-filtro="<?= htmlspecialchars($situacaoAlvara ?: 'nao_informado') ?>">
                                        <td class="codigo-cliente"><?= htmlspecialchars($cliente['codigo']) ?></td>
                                        <td class="documento-cliente"><?= htmlspecialchars($cliente['documento']) ?></td>
                                        <td class="nome-cliente"><?= htmlspecialchars($cliente['nome']) ?></td>
                                        <td>
                                            <span class="badge badge-alvara <?= classeSituacaoAlvaraDf($situacaoAlvara) ?>">
                                                <?= htmlspecialchars(textoSituacaoAlvaraDf($situacaoAlvara)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-df-legal <?= classeCadastroDfLegal($cadastroDfLegal, $dadosDfLegalPendentes) ?>">
                                                <?= $dadosDfLegalPendentes ? 'Dados incorretos' : htmlspecialchars(textoCadastroDfLegal($cadastroDfLegal)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm btn-consultar-orgaos"
                                                data-cliente="<?= htmlspecialchars($cliente['codigo'] . ' - ' . $cliente['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-alvaras="<?= htmlspecialchars(json_encode($alvarasCliente, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-search"></i> Consultar órgãos
                                            </button>
                                        </td>
                                        <td class="text-end">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm btn-editar-alvara"
                                                title="Editar alvarás"
                                                data-id="<?= $clienteId ?>"
                                                data-cliente="<?= htmlspecialchars($cliente['codigo'] . ' - ' . $cliente['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-alvara="<?= htmlspecialchars($situacaoAlvara) ?>"
                                                data-df-legal="<?= htmlspecialchars($cadastroDfLegal) ?>"
                                                data-df-legal-pendente="<?= $dadosDfLegalPendentes ? '1' : '0' ?>"
                                                data-alvaras="<?= htmlspecialchars(json_encode($alvarasCliente, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="alvarasDfVazio" class="d-none">
                                <td colspan="7" class="text-center text-muted py-4">Nenhum cliente encontrado.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacaoAlvarasDf"></div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalConsultarOrgaos" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Órgãos do alvará</h5>
                        <small class="text-muted" id="modalConsultaCliente"></small>
                        <div class="small text-muted mt-1" id="resumoConsultaOrgaos"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table align-middle consulta-orgaos-tabela mb-0">
                            <thead>
                                <tr>
                                    <th>Órgão</th>
                                    <th>Situação</th>
                                    <th>Vencimento</th>
                                    <th>Prazo</th>
                                </tr>
                            </thead>
                            <tbody id="listaConsultaOrgaos"></tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarAlvarasDf" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form id="formEditarAlvarasDf" novalidate>
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Editar alvarás DF e DF Legal</h5>
                            <small class="text-muted" id="modalAlvaraCliente"></small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="cliente_id" id="modalAlvaraClienteId">

                        <div class="alert alert-danger d-none" id="alertaModalAlvaras"></div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-5">
                                <label for="modalSituacaoAlvara" class="form-label">Situação dos alvarás</label>
                                <select class="form-select" name="alvara" id="modalSituacaoAlvara">
                                    <option value="">Selecione</option>
                                    <option value="possui">Possui</option>
                                    <option value="nao_possui">Não possui</option>
                                    <option value="goias">Goiás</option>
                                </select>
                                <div class="invalid-feedback">Informe a situação dos alvarás.</div>
                            </div>

                            <div class="col-md-5">
                                <label for="modalCadastroDfLegal" class="form-label">Cadastro DF Legal</label>
                                <select class="form-select" name="cadastro_df_legal" id="modalCadastroDfLegal">
                                    <option value="">Selecione</option>
                                    <option value="cadastrado">Cadastrado</option>
                                    <option value="nao_cadastrado">Não cadastrado</option>
                                    <option value="goias">Goiás</option>
                                </select>
                                <div class="invalid-feedback">Informe a situação do cadastro DF Legal.</div>
                            </div>
                        </div>

                        <div id="grupoConferenciaDfLegal" class="border rounded p-3 mb-4 d-none">
                            <h6 class="fw-bold mb-3">Conferência do cadastro DF Legal</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <span class="d-block mb-2">A razão social está correta?</span>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_razao_social_correta" id="dfLegalRazaoSim" value="sim" checked>
                                        <label class="form-check-label" for="dfLegalRazaoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_razao_social_correta" id="dfLegalRazaoNao" value="nao">
                                        <label class="form-check-label" for="dfLegalRazaoNao">Não</label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <span class="d-block mb-2">O endereço está correto?</span>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_endereco_correto" id="dfLegalEnderecoSim" value="sim" checked>
                                        <label class="form-check-label" for="dfLegalEnderecoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_endereco_correto" id="dfLegalEnderecoNao" value="nao">
                                        <label class="form-check-label" for="dfLegalEnderecoNao">Não</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="grupoOrgaosAlvara" class="d-none">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1">Órgãos e vencimentos</h6>
                                    <p class="text-muted small mb-0">Para cada órgão, informe o vencimento, marque como dispensado ou em estudo.</p>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnDispensarTodosAlvarasDf">
                                    <i class="bi bi-check2-all"></i> Marcar todos como dispensado
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle modal-alvaras-tabela mb-0">
                                    <thead>
                                        <tr>
                                            <th>Órgão</th>
                                            <th>Situação</th>
                                            <th>Vencimento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orgaosAlvara as $codigo => $nome): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($nome) ?></td>
                                                <td>
                                                    <select
                                                        class="form-select modal-orgao-situacao"
                                                        name="alvaras[<?= htmlspecialchars($codigo) ?>][situacao]"
                                                        data-codigo="<?= htmlspecialchars($codigo) ?>"
                                                        data-vencimento="modalAlvaraVencimento_<?= htmlspecialchars($codigo) ?>">
                                                        <option value="">Selecione</option>
                                                        <option value="com_vencimento">Com vencimento</option>
                                                        <option value="dispensado">Dispensado</option>
                                                        <option value="em_estudo">Em estudo</option>
                                                    </select>
                                                    <div class="invalid-feedback">Informe a situação.</div>
                                                </td>
                                                <td>
                                                    <input
                                                        type="date"
                                                        class="form-control modal-orgao-vencimento"
                                                        name="alvaras[<?= htmlspecialchars($codigo) ?>][vencimento]"
                                                        id="modalAlvaraVencimento_<?= htmlspecialchars($codigo) ?>"
                                                        disabled>
                                                    <div class="invalid-feedback">Informe o vencimento.</div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="btnSalvarAlvarasDf">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const orgaosAlvaraDf = <?= json_encode($orgaosAlvara, JSON_UNESCAPED_UNICODE) ?>;
        const modalConsultaOrgaos = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConsultarOrgaos'));
        const modalAlvarasDf = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarAlvarasDf'));
        const formAlvarasDf = document.getElementById('formEditarAlvarasDf');
        const campoSituacaoAlvara = document.getElementById('modalSituacaoAlvara');
        const campoCadastroDfLegal = document.getElementById('modalCadastroDfLegal');
        const grupoOrgaosAlvara = document.getElementById('grupoOrgaosAlvara');
        const grupoConferenciaDfLegal = document.getElementById('grupoConferenciaDfLegal');
        const alertaModalAlvaras = document.getElementById('alertaModalAlvaras');
        let alvarasDfPorPagina = Number(localStorage.getItem('alvarasDfPorPagina') || 15);
        alvarasDfPorPagina = [15, 30, 60, 90].includes(alvarasDfPorPagina) ? alvarasDfPorPagina : 15;
        const linhasAlvarasDf = Array.from(document.querySelectorAll('.linha-cliente-alvara'));
        const paginacaoAlvarasDf = document.getElementById('paginacaoAlvarasDf');
        const alvarasDfVazio = document.getElementById('alvarasDfVazio');
        let alvarasDfPaginaAtual = 1;
        let linhaAlvaraAtual = null;
        let botaoAlvaraAtual = null;

        function atualizarVisibilidadeOrgaos() {
            const possui = campoSituacaoAlvara.value === 'possui';
            grupoOrgaosAlvara.classList.toggle('d-none', !possui);

            document.querySelectorAll('.modal-orgao-situacao').forEach(function(campo) {
                campo.disabled = !possui;

                const campoData = document.getElementById(campo.dataset.vencimento);
                campoData.disabled = !possui || campo.value !== 'com_vencimento';
            });
        }

        function atualizarConferenciaDfLegal() {
            grupoConferenciaDfLegal.classList.toggle('d-none', campoCadastroDfLegal.value !== 'cadastrado');
        }

        function atualizarVencimentoOrgao(campoSituacao, darFoco = false) {
            const campoData = document.getElementById(campoSituacao.dataset.vencimento);
            const possuiVencimento = campoSituacao.value === 'com_vencimento';

            campoData.disabled = !possuiVencimento;
            campoData.classList.remove('is-invalid');

            if (!possuiVencimento) {
                campoData.value = '';
            } else if (darFoco) {
                campoData.focus();
            }
        }

        function preencherModalAlvaras(botao) {
            linhaAlvaraAtual = botao.closest('tr');
            botaoAlvaraAtual = botao;

            document.getElementById('modalAlvaraClienteId').value = botao.dataset.id;
            document.getElementById('modalAlvaraCliente').textContent = botao.dataset.cliente;
            campoSituacaoAlvara.value = botao.dataset.alvara || '';
            campoCadastroDfLegal.value = botao.dataset.dfLegal || '';
            document.getElementById('dfLegalRazaoSim').checked = true;
            document.getElementById('dfLegalEnderecoSim').checked = true;

            const alvaras = JSON.parse(botao.dataset.alvaras || '{}');

            document.querySelectorAll('.modal-orgao-situacao').forEach(function(campo) {
                const alvara = alvaras[campo.dataset.codigo] || {};
                campo.value = alvara.situacao || '';
                campo.classList.remove('is-invalid');

                const campoData = document.getElementById(campo.dataset.vencimento);
                campoData.value = alvara.vencimento || '';
                campoData.classList.remove('is-invalid');
                atualizarVencimentoOrgao(campo);
            });

            campoSituacaoAlvara.classList.remove('is-invalid');
            campoCadastroDfLegal.classList.remove('is-invalid');
            alertaModalAlvaras.classList.add('d-none');
            atualizarVisibilidadeOrgaos();
            atualizarConferenciaDfLegal();
        }

        function validarModalAlvaras() {
            let valido = true;
            let primeiroInvalido = null;

            [campoSituacaoAlvara, campoCadastroDfLegal].forEach(function(campo) {
                const invalido = campo.value === '';
                campo.classList.toggle('is-invalid', invalido);

                if (invalido && primeiroInvalido === null) {
                    primeiroInvalido = campo;
                    valido = false;
                }
            });

            if (campoSituacaoAlvara.value === 'possui') {
                document.querySelectorAll('.modal-orgao-situacao').forEach(function(campo) {
                    const campoData = document.getElementById(campo.dataset.vencimento);
                    const situacaoInvalida = campo.value === '';
                    const dataInvalida = campo.value === 'com_vencimento' && campoData.value === '';

                    campo.classList.toggle('is-invalid', situacaoInvalida);
                    campoData.classList.toggle('is-invalid', dataInvalida);

                    if (situacaoInvalida && primeiroInvalido === null) {
                        primeiroInvalido = campo;
                    } else if (dataInvalida && primeiroInvalido === null) {
                        primeiroInvalido = campoData;
                    }

                    if (situacaoInvalida || dataInvalida) {
                        valido = false;
                    }
                });
            }

            if (!valido) {
                alertaModalAlvaras.textContent = 'Preencha os campos obrigatórios destacados em vermelho.';
                alertaModalAlvaras.classList.remove('d-none');
                primeiroInvalido?.focus();
            } else {
                alertaModalAlvaras.classList.add('d-none');
            }

            return valido;
        }

        function textoAlvara(status) {
            return {
                possui: 'Possui',
                nao_possui: 'Não possui',
                goias: 'Goiás'
            } [status] || 'Não informado';
        }

        function classeAlvara(status) {
            return {
                possui: 'badge badge-alvara bg-success',
                nao_possui: 'badge badge-alvara bg-danger',
                goias: 'badge badge-alvara bg-info text-dark'
            } [status] || 'badge badge-alvara bg-warning text-dark';
        }

        function textoDfLegal(status, pendente) {
            if (pendente) {
                return 'Dados incorretos';
            }

            return {
                cadastrado: 'Cadastrado',
                nao_cadastrado: 'Não cadastrado',
                goias: 'Goiás'
            } [status] || 'Não informado';
        }

        function classeDfLegal(status, pendente) {
            if (pendente) {
                return 'badge badge-df-legal bg-warning text-dark';
            }

            return {
                cadastrado: 'badge badge-df-legal bg-success',
                nao_cadastrado: 'badge badge-df-legal bg-danger',
                goias: 'badge badge-df-legal bg-info text-dark'
            } [status] || 'badge badge-df-legal bg-warning text-dark';
        }

        function formatarData(data) {
            if (!data) {
                return '-';
            }

            const partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }

        function prazoVencimento(data) {
            if (!data) {
                return {
                    texto: 'Sem data',
                    classe: 'bg-danger'
                };
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = data.split('-');
            const vencimento = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
            const dias = Math.round((vencimento - hoje) / 86400000);

            if (dias < 0) {
                const atraso = Math.abs(dias);
                return {
                    texto: 'Vencido há ' + atraso + (atraso === 1 ? ' dia' : ' dias'),
                    classe: 'bg-danger'
                };
            }

            if (dias === 0) {
                return {
                    texto: 'Vence hoje',
                    classe: 'bg-danger'
                };
            }

            if (dias <= 15) {
                return {
                    texto: dias + (dias === 1 ? ' dia para vencer' : ' dias para vencer'),
                    classe: 'bg-danger-subtle text-dark'
                };
            }

            if (dias <= 30) {
                return {
                    texto: dias + (dias === 1 ? ' dia para vencer' : ' dias para vencer'),
                    classe: 'bg-warning text-dark'
                };
            }

            return {
                texto: dias + (dias === 1 ? ' dia para vencer' : ' dias para vencer'),
                classe: 'bg-success'
            };
        }

        function partesNomeOrgao(nome) {
            const separador = nome.lastIndexOf(' - ');

            if (separador === -1) {
                return {
                    sigla: nome,
                    nome: ''
                };
            }

            return {
                sigla: nome.substring(separador + 3),
                nome: nome.substring(0, separador)
            };
        }

        function abrirConsultaOrgaos(botao) {
            const alvaras = JSON.parse(botao.dataset.alvaras || '{}');
            const lista = document.getElementById('listaConsultaOrgaos');
            let totalVencimentos = 0;
            let totalDispensados = 0;
            let totalEmEstudo = 0;
            let totalNaoInformados = 0;

            document.getElementById('modalConsultaCliente').textContent = botao.dataset.cliente;
            lista.innerHTML = '';

            Object.entries(orgaosAlvaraDf).forEach(function([codigo, nome]) {
                const alvara = alvaras[codigo] || {};
                const possuiVencimento = alvara.situacao === 'com_vencimento';
                const dispensado = alvara.situacao === 'dispensado';
                const emEstudo = alvara.situacao === 'em_estudo';
                const partesNome = partesNomeOrgao(nome);
                const textoSituacao = possuiVencimento ?
                    'Com vencimento' :
                    (dispensado ? 'Dispensado' : (emEstudo ? 'Em estudo' : 'Não informado'));
                const classeSituacao = possuiVencimento ?
                    'bg-primary' :
                    (dispensado ? 'bg-success' : (emEstudo ? 'bg-warning text-dark' : 'bg-danger'));
                const prazo = possuiVencimento ?
                    prazoVencimento(alvara.vencimento) :
                    {
                        texto: dispensado ? 'Não se aplica' : 'Pendente',
                        classe: dispensado ? 'bg-secondary' : (emEstudo ? 'bg-warning text-dark' : 'bg-danger')
                    };
                const linha = document.createElement('tr');

                totalVencimentos += possuiVencimento ? 1 : 0;
                totalDispensados += dispensado ? 1 : 0;
                totalEmEstudo += emEstudo ? 1 : 0;
                totalNaoInformados += !possuiVencimento && !dispensado && !emEstudo ? 1 : 0;

                linha.innerHTML =
                    '<td><span class="consulta-orgao-sigla"></span><span class="consulta-orgao-nome"></span></td>' +
                    '<td><span class="badge ' + classeSituacao + '">' + textoSituacao + '</span></td>' +
                    '<td>' + (possuiVencimento ? formatarData(alvara.vencimento) : '-') + '</td>' +
                    '<td><span class="badge consulta-prazo ' + prazo.classe + '">' + prazo.texto + '</span></td>';
                linha.querySelector('.consulta-orgao-sigla').textContent = partesNome.sigla;
                linha.querySelector('.consulta-orgao-nome').textContent = partesNome.nome;
                lista.appendChild(linha);
            });

            const partesResumo = [
                totalVencimentos + (totalVencimentos === 1 ? ' com vencimento' : ' com vencimento'),
                totalDispensados + (totalDispensados === 1 ? ' dispensado' : ' dispensados')
            ];

            if (totalEmEstudo > 0) {
                partesResumo.push(totalEmEstudo + (totalEmEstudo === 1 ? ' em estudo' : ' em estudo'));
            }

            if (totalNaoInformados > 0) {
                partesResumo.push(totalNaoInformados + (totalNaoInformados === 1 ? ' não informado' : ' não informados'));
            }

            document.getElementById('resumoConsultaOrgaos').textContent = partesResumo.join(' • ');
            modalConsultaOrgaos.show();
        }

        function mostrarMensagemAlvaras(mensagem, sucesso) {
            const alerta = document.getElementById('mensagemAlvaras');
            alerta.textContent = mensagem;
            alerta.className = 'alert ' + (sucesso ? 'alert-success' : 'alert-danger');

            window.setTimeout(function() {
                alerta.classList.add('d-none');
            }, 4000);
        }

        function alvarasDfFiltradas() {
            const busca = document.getElementById('buscaAlvaraDf').value.toLocaleLowerCase('pt-BR');
            const filtro = document.getElementById('filtroAlvaraDf').value;

            return linhasAlvarasDf.filter(function(linha) {
                const texto = [
                    linha.querySelector('.codigo-cliente').textContent,
                    linha.querySelector('.documento-cliente').textContent,
                    linha.querySelector('.nome-cliente').textContent
                ].join(' ').toLocaleLowerCase('pt-BR');
                const correspondeBusca = texto.includes(busca);
                const correspondeFiltro = filtro === '' || linha.dataset.alvaraFiltro === filtro;

                return correspondeBusca && correspondeFiltro && linha.isConnected;
            });
        }

        function adicionarPaginaAlvarasDf(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                alvarasDfPaginaAtual = pagina;
                renderizarAlvarasDf();
            });

            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarAlvarasDf() {
            const filtradas = alvarasDfFiltradas();
            const totalPaginas = Math.max(1, Math.ceil(filtradas.length / alvarasDfPorPagina));

            if (alvarasDfPaginaAtual > totalPaginas) {
                alvarasDfPaginaAtual = totalPaginas;
            }

            const inicio = (alvarasDfPaginaAtual - 1) * alvarasDfPorPagina;
            const visiveis = new Set(filtradas.slice(inicio, inicio + alvarasDfPorPagina));

            linhasAlvarasDf.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            alvarasDfVazio.classList.toggle('d-none', filtradas.length > 0);
            paginacaoAlvarasDf.innerHTML = '';

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
            campoLimite.value = String(alvarasDfPorPagina);
            campoLimite.addEventListener('change', function() {
                alvarasDfPorPagina = Number(campoLimite.value);
                localStorage.setItem('alvarasDfPorPagina', String(alvarasDfPorPagina));
                alvarasDfPaginaAtual = 1;
                renderizarAlvarasDf();
            });
            paginacaoAlvarasDf.appendChild(seletorLimite);

            if (filtradas.length <= alvarasDfPorPagina) {
                return;
            }

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            adicionarPaginaAlvarasDf(lista, 'Anterior', Math.max(1, alvarasDfPaginaAtual - 1), alvarasDfPaginaAtual <= 1, false);

            const paginasVisiveis = [];
            let ultimaPagina = 0;

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - alvarasDfPaginaAtual) <= 2) {
                    if (ultimaPagina && pagina - ultimaPagina > 1) {
                        paginasVisiveis.push('...');
                    }

                    paginasVisiveis.push(pagina);
                    ultimaPagina = pagina;
                }
            }

            paginasVisiveis.forEach(function(pagina) {
                if (pagina === '...') {
                    adicionarPaginaAlvarasDf(lista, '...', alvarasDfPaginaAtual, true, false);
                    return;
                }

                adicionarPaginaAlvarasDf(lista, String(pagina), pagina, false, pagina === alvarasDfPaginaAtual);
            });

            adicionarPaginaAlvarasDf(lista, 'Próxima', Math.min(totalPaginas, alvarasDfPaginaAtual + 1), alvarasDfPaginaAtual >= totalPaginas, false);

            nav.appendChild(lista);
            paginacaoAlvarasDf.appendChild(nav);
        }

        function filtrarAlvaras() {
            alvarasDfPaginaAtual = 1;
            renderizarAlvarasDf();
        }

        document.querySelectorAll('.btn-editar-alvara').forEach(function(botao) {
            botao.addEventListener('click', function() {
                preencherModalAlvaras(this);
                modalAlvarasDf.show();
            });
        });

        document.querySelectorAll('.btn-consultar-orgaos').forEach(function(botao) {
            botao.addEventListener('click', function() {
                abrirConsultaOrgaos(this);
            });
        });

        campoSituacaoAlvara.addEventListener('change', atualizarVisibilidadeOrgaos);
        campoCadastroDfLegal.addEventListener('change', atualizarConferenciaDfLegal);
        document.getElementById('buscaAlvaraDf').addEventListener('input', filtrarAlvaras);
        document.getElementById('filtroAlvaraDf').addEventListener('change', filtrarAlvaras);

        document.querySelectorAll('.modal-orgao-situacao').forEach(function(campo) {
            campo.addEventListener('change', function() {
                this.classList.remove('is-invalid');
                atualizarVencimentoOrgao(this, true);
            });
        });

        document.getElementById('btnDispensarTodosAlvarasDf').addEventListener('click', function() {
            document.querySelectorAll('.modal-orgao-situacao').forEach(function(campo) {
                campo.value = 'dispensado';
                campo.classList.remove('is-invalid');
                atualizarVencimentoOrgao(campo);
            });
            alertaModalAlvaras.classList.add('d-none');
        });

        formAlvarasDf.addEventListener('submit', function(evento) {
            evento.preventDefault();

            if (!validarModalAlvaras()) {
                return;
            }

            const botaoSalvar = document.getElementById('btnSalvarAlvarasDf');
            const textoOriginal = botaoSalvar.innerHTML;
            botaoSalvar.disabled = true;
            botaoSalvar.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Salvando';

            fetch('api_alvaras_df.php', {
                    method: 'POST',
                    body: new FormData(formAlvarasDf)
                })
                .then(function(resposta) {
                    return resposta.json();
                })
                .then(function(resposta) {
                    if (!resposta.sucesso) {
                        alertaModalAlvaras.textContent = resposta.mensagem;
                        alertaModalAlvaras.classList.remove('d-none');
                        return;
                    }

                    const razaoCorreta = document.querySelector('input[name="df_legal_razao_social_correta"]:checked').value;
                    const enderecoCorreto = document.querySelector('input[name="df_legal_endereco_correto"]:checked').value;
                    const dfLegalPendente = resposta.cadastro_df_legal === 'cadastrado' &&
                        (razaoCorreta === 'nao' || enderecoCorreto === 'nao');

                    if (resposta.alvara === 'goias' || resposta.cadastro_df_legal === 'goias') {
                        linhaAlvaraAtual.remove();
                    } else {
                        const badgeAlvara = linhaAlvaraAtual.querySelector('.badge-alvara');
                        const badgeDfLegal = linhaAlvaraAtual.querySelector('.badge-df-legal');
                        const botaoConsultar = linhaAlvaraAtual.querySelector('.btn-consultar-orgaos');

                        badgeAlvara.className = classeAlvara(resposta.alvara);
                        badgeAlvara.textContent = textoAlvara(resposta.alvara);
                        badgeDfLegal.className = classeDfLegal(resposta.cadastro_df_legal, dfLegalPendente);
                        badgeDfLegal.textContent = textoDfLegal(resposta.cadastro_df_legal, dfLegalPendente);
                        linhaAlvaraAtual.dataset.alvaraFiltro = resposta.alvara || 'nao_informado';
                        botaoConsultar.dataset.alvaras = JSON.stringify(resposta.alvaras || {});

                        botaoAlvaraAtual.dataset.alvara = resposta.alvara;
                        botaoAlvaraAtual.dataset.dfLegal = resposta.cadastro_df_legal;
                        botaoAlvaraAtual.dataset.dfLegalPendente = dfLegalPendente ? '1' : '0';
                        botaoAlvaraAtual.dataset.alvaras = JSON.stringify(resposta.alvaras || {});
                    }

                    modalAlvarasDf.hide();
                    renderizarAlvarasDf();
                    mostrarMensagemAlvaras(resposta.mensagem, true);
                })
                .catch(function() {
                    alertaModalAlvaras.textContent = 'Não foi possível comunicar com o servidor.';
                    alertaModalAlvaras.classList.remove('d-none');
                })
                .finally(function() {
                    botaoSalvar.disabled = false;
                    botaoSalvar.innerHTML = textoOriginal;
                });
        });

        document.getElementById('modalEditarAlvarasDf').addEventListener('keydown', function(evento) {
            if (evento.key !== 'Enter' || evento.target.tagName === 'TEXTAREA') {
                return;
            }

            evento.preventDefault();
            formAlvarasDf.requestSubmit();
        });

        renderizarAlvarasDf();
    </script>

</body>

</html>