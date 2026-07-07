<?php
require 'config.php';
require_once 'includes/parcelamentos_funcoes.php';

exigirPermissao('clientes');

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: clientes.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

$stmtAlvaras = $pdo->prepare("
    SELECT orgao_nome, situacao, vencimento
    FROM cliente_alvaras
    WHERE cliente_id = ?
    ORDER BY orgao_nome
");
$stmtAlvaras->execute([$id]);
$alvarasCliente = $stmtAlvaras->fetchAll(PDO::FETCH_ASSOC);

$stmtParcelamentos = $pdo->prepare("
    SELECT *
    FROM parcelamentos
    WHERE cliente_id = ?
    ORDER BY orgao, data_primeira_parcela, id
");
$stmtParcelamentos->execute([$id]);
$parcelamentosCliente = $stmtParcelamentos->fetchAll(PDO::FETCH_ASSOC);

$rotulosControle = [
    'possui' => 'Possui',
    'nao_possui' => 'Não possui',
    'goias' => 'Goiás',
    'cadastrado' => 'Cadastrado',
    'nao_cadastrado' => 'Não cadastrado',
    'sim' => 'Sim',
    'nao' => 'Não',
    'simples_nacional' => 'Simples Nacional',
    'lucro_presumido' => 'Lucro Presumido',
    'lucro_real' => 'Lucro Real',
    'mei' => 'Microempreendedor Individual',
];

$formatarControle = static function ($valor) use ($rotulosControle): string {
    return $rotulosControle[$valor] ?? 'Não informado';
};

$formatarData = static function ($data): string {
    return !empty($data) ? date('d/m/Y', strtotime($data)) : 'Não informado';
};

$clienteContabil = (int)($cliente['cliente_contabil'] ?? 1) === 1;
$servicoParcelamento = (int)($cliente['servico_parcelamento'] ?? 0) === 1;
$servicoCertificado = (int)($cliente['servico_certificado'] ?? 1) === 1;
$paginaRetorno = $clienteContabil ? 'clientes.php' : 'servicos_avulsos.php';
$valorOuNaoInformado = static function ($valor): string {
    $valor = trim((string)$valor);

    return $valor !== '' ? $valor : 'Não informado';
};

$controlesImpressao = [];

if ($clienteContabil) {
    $controlesImpressao = [
        ['Cadastro DF Legal', $formatarControle($cliente['cadastro_df_legal'] ?? '')],
        ['Alvará', $formatarControle($cliente['alvara'] ?? '')],
        ['Contador', $formatarControle($cliente['contador'] ?? '')],
        ['Cadastro CRF', $formatarControle($cliente['cadastro_crf'] ?? '')],
        [
            'Procuração Receita Federal',
            $formatarControle($cliente['procuracao_receita_federal'] ?? '')
                . (($cliente['procuracao_receita_federal'] ?? '') === 'possui'
                    ? ' - ' . $formatarData($cliente['vencimento_procuracao_receita_federal'] ?? null)
                    : ''),
        ],
        [
            'Procuração Conectividade',
            $formatarControle($cliente['procuracao_conectividade'] ?? '')
                . (($cliente['procuracao_conectividade'] ?? '') === 'possui'
                    ? ' - ' . $formatarData($cliente['vencimento_procuracao_conectividade'] ?? null)
                    : ''),
        ],
        ['Procuração Empregador Web', $formatarControle($cliente['procuracao_empregador_web'] ?? '')],
        [
            'Procuração FGTS',
            $formatarControle($cliente['procuracao_fgts'] ?? '')
                . (($cliente['procuracao_fgts'] ?? '') === 'possui'
                    ? ' - ' . $formatarData($cliente['vencimento_procuracao_fgts'] ?? null)
                    : ''),
        ],
        ['Procuração Particular', $formatarControle($cliente['procuracao_particular'] ?? '')],
        ['Procuração SEFAZ', $formatarControle($cliente['procuracao_sefaz'] ?? '')],
        ['Contrato de Prestação de Serviços', $formatarControle($cliente['contrato_prestacao_servicos'] ?? '')],
        ['Tributação', $formatarControle($cliente['tributacao'] ?? '')],
        ['Parcelamentos', $formatarControle($cliente['possui_parcelamento'] ?? '')],
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title><?= htmlspecialchars($cliente['nome']) ?> - Cliente</title>
    <style>
        .ficha-cliente-impressao {
            display: none;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            html,
            body,
            .app-layout {
                display: block !important;
                min-height: 0 !important;
                height: auto !important;
                overflow: visible !important;
                background: #fff !important;
            }

            .app-sidebar,
            .container-fluid> :not(.ficha-cliente-impressao),
            .modal,
            .modal-backdrop {
                display: none !important;
            }

            .app-main,
            .app-sidebar.collapsed+.app-main {
                width: 100% !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .container-fluid {
                width: 100% !important;
                padding: 0 !important;
            }

            .ficha-cliente-impressao {
                display: block !important;
                color: #111827;
                font-family: Arial, sans-serif;
                font-size: 9.5pt;
            }

            .ficha-impressao-cabecalho {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 20px;
                margin-bottom: 14px;
                padding-bottom: 10px;
                border-bottom: 2px solid #1f2937;
            }

            .ficha-impressao-marca {
                margin-bottom: 5px;
                color: #4b5563;
                font-size: 8pt;
                font-weight: 700;
                text-transform: uppercase;
            }

            .ficha-impressao-cabecalho h1 {
                margin: 0 0 4px;
                font-size: 18pt;
            }

            .ficha-impressao-cabecalho p {
                margin: 0;
                color: #4b5563;
            }

            .ficha-impressao-data {
                color: #6b7280;
                font-size: 8pt;
                text-align: right;
            }

            .ficha-impressao-secao {
                margin-bottom: 12px;
                break-inside: avoid;
            }

            .ficha-impressao-secao h2 {
                margin: 0 0 7px;
                padding: 5px 7px;
                background: #e5e7eb !important;
                color: #111827;
                font-size: 10pt;
                text-transform: uppercase;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .ficha-impressao-grade {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 7px 14px;
                padding: 0 7px;
            }

            .ficha-impressao-item {
                min-width: 0;
                padding-bottom: 4px;
                border-bottom: 1px solid #e5e7eb;
                overflow-wrap: anywhere;
            }

            .ficha-impressao-item span {
                display: block;
                margin-bottom: 2px;
                color: #6b7280;
                font-size: 7.5pt;
            }

            .ficha-impressao-item strong {
                font-weight: 600;
            }

            .ficha-impressao-tabela {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5pt;
            }

            .ficha-impressao-tabela th,
            .ficha-impressao-tabela td {
                padding: 5px 7px;
                border: 1px solid #d1d5db;
                text-align: left;
            }

            .ficha-impressao-tabela th {
                background: #f3f4f6 !important;
                font-size: 7.5pt;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <a href="<?= $paginaRetorno ?>" class="btn btn-outline-secondary mb-3 nao-imprimir">Voltar</a>

            <h3><?= htmlspecialchars($cliente['nome']) ?></h3>
            <p class="text-muted"><?= htmlspecialchars($cliente['documento']) ?></p>
            <span class="badge <?= $clienteContabil ? 'bg-success' : 'bg-info text-dark' ?> mb-3">
                <?= $clienteContabil ? 'Cliente contábil' : 'Serviço avulso' ?>
            </span>

            <div class="d-flex gap-2 mb-4 nao-imprimir">
                <a href="cliente_editar.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Editar
                </a>

                <button class="btn btn-danger" onclick="excluirCliente(<?= (int)$cliente['id'] ?>)">
                    <i class="bi bi-trash"></i> Excluir
                </button>

                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimir ficha
                </button>
            </div>

            <div class="clientes-box mt-4">
                <h5>Dados principais</h5>
                <p><strong>Código:</strong> <?= htmlspecialchars($cliente['codigo'] ?? '') ?></p>
                <p><strong>Nome Fantasia:</strong> <?= htmlspecialchars($cliente['nome_fantasia'] ?? '') ?></p>
                <p><strong>E-mail:</strong> <?= htmlspecialchars($cliente['email'] ?? '') ?></p>
                <p><strong>Telefone:</strong> <?= htmlspecialchars($cliente['telefone'] ?? '') ?></p>
                <p><strong>Inscrição Estadual:</strong> <?= htmlspecialchars($cliente['inscricao_estadual'] ?? '') ?></p>
                <p><strong>NIRE:</strong> <?= htmlspecialchars($cliente['nire'] ?? '') ?></p>
                <?php if ($servicoCertificado): ?>
                    <p>
                        <strong>Vencimento Certificado Digital:</strong>
                        <?= !empty($cliente['vencimento_certificado'])
                            ? date('d/m/Y', strtotime($cliente['vencimento_certificado']))
                            : 'Não cadastrado'; ?>
                    </p>
                <?php endif; ?>
                <hr>

                <?php if (!$clienteContabil): ?>
                    <h6 class="mt-3 mb-2">Serviços acompanhados</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($servicoParcelamento): ?>
                            <span class="badge bg-primary">Parcelamento</span>
                        <?php endif; ?>
                        <?php if ($servicoCertificado): ?>
                            <span class="badge bg-success">Certificado Digital</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <h6 class="mt-3 mb-3">Controles internos</h6>

                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Cadastro DF Legal</small>
                            <?= htmlspecialchars($formatarControle($cliente['cadastro_df_legal'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Alvará</small>
                            <?= htmlspecialchars($formatarControle($cliente['alvara'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Contador</small>
                            <?= htmlspecialchars($formatarControle($cliente['contador'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Cadastro CRF</small>
                            <?= htmlspecialchars($formatarControle($cliente['cadastro_crf'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Receita Federal</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_receita_federal'] ?? '')) ?>
                            <?php if (($cliente['procuracao_receita_federal'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_receita_federal'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Conectividade</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_conectividade'] ?? '')) ?>
                            <?php if (($cliente['procuracao_conectividade'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_conectividade'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Empregador Web</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_empregador_web'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração FGTS</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_fgts'] ?? '')) ?>
                            <?php if (($cliente['procuracao_fgts'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_fgts'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Particular</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_particular'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração SEFAZ</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_sefaz'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Contrato de Prestação de Serviços</small>
                            <?= htmlspecialchars($formatarControle($cliente['contrato_prestacao_servicos'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Tributação</small>
                            <?= htmlspecialchars($formatarControle($cliente['tributacao'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Parcelamentos</small>
                            <?= htmlspecialchars($formatarControle($cliente['possui_parcelamento'] ?? '')) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($clienteContabil && !empty($alvarasCliente)): ?>
                <div class="clientes-box mt-4">
                    <h5>Alvarás e licenças</h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Órgão</th>
                                    <th>Situação</th>
                                    <th>Vencimento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alvarasCliente as $alvaraCliente): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($alvaraCliente['orgao_nome']) ?></td>
                                        <td>
                                            <?php
                                            $textoSituacaoAlvara = [
                                                'com_vencimento' => 'Com vencimento',
                                                'dispensado' => 'Dispensado',
                                                'em_estudo' => 'Em estudo',
                                            ][$alvaraCliente['situacao']] ?? 'Não informado';
                                            ?>
                                            <?= htmlspecialchars($textoSituacaoAlvara) ?>
                                        </td>
                                        <td><?= $alvaraCliente['situacao'] === 'com_vencimento' ? htmlspecialchars($formatarData($alvaraCliente['vencimento'])) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="clientes-box mt-4">
                <h5>Endereço</h5>
                <p><strong>CEP:</strong> <?= htmlspecialchars($cliente['cep'] ?? '') ?></p>
                <p><strong>Endereço:</strong> <?= htmlspecialchars($cliente['endereco'] ?? '') ?>, <?= htmlspecialchars($cliente['numero_endereco'] ?? '') ?></p>
                <p><strong>Complemento:</strong> <?= htmlspecialchars($cliente['complemento'] ?? '') ?></p>
                <p><strong>Bairro:</strong> <?= htmlspecialchars($cliente['bairro'] ?? '') ?></p>
                <p><strong>Cidade/UF:</strong> <?= htmlspecialchars($cliente['cidade'] ?? '') ?> / <?= htmlspecialchars($cliente['uf'] ?? '') ?></p>
            </div>

            <article class="ficha-cliente-impressao">
                <header class="ficha-impressao-cabecalho">
                    <div>
                        <div class="ficha-impressao-marca">Logi | Ficha cadastral</div>
                        <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
                        <p>
                            <?= htmlspecialchars($valorOuNaoInformado($cliente['documento'] ?? '')) ?>
                            | Código <?= htmlspecialchars($valorOuNaoInformado($cliente['codigo'] ?? '')) ?>
                            | <?= $clienteContabil ? 'Cliente contábil' : 'Serviço avulso' ?>
                        </p>
                    </div>
                    <div class="ficha-impressao-data">
                        Emitido em <?= date('d/m/Y H:i') ?>
                    </div>
                </header>

                <section class="ficha-impressao-secao">
                    <h2>Dados principais</h2>
                    <div class="ficha-impressao-grade">
                        <div class="ficha-impressao-item">
                            <span>Nome fantasia</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['nome_fantasia'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>E-mail</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['email'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>Telefone</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['telefone'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>Inscrição Estadual</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['inscricao_estadual'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>NIRE</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['nire'] ?? '')) ?></strong>
                        </div>
                        <?php if ($servicoCertificado): ?>
                            <div class="ficha-impressao-item">
                                <span>Vencimento do certificado digital</span>
                                <strong><?= htmlspecialchars($formatarData($cliente['vencimento_certificado'] ?? null)) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($clienteContabil): ?>
                    <section class="ficha-impressao-secao">
                        <h2>Controles internos</h2>
                        <div class="ficha-impressao-grade">
                            <?php foreach ($controlesImpressao as [$rotuloControle, $valorControle]): ?>
                                <div class="ficha-impressao-item">
                                    <span><?= htmlspecialchars($rotuloControle) ?></span>
                                    <strong><?= htmlspecialchars($valorControle) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="ficha-impressao-secao">
                        <h2>Serviços acompanhados</h2>
                        <div class="ficha-impressao-grade">
                            <div class="ficha-impressao-item">
                                <span>Parcelamento</span>
                                <strong><?= $servicoParcelamento ? 'Sim' : 'Não' ?></strong>
                            </div>
                            <div class="ficha-impressao-item">
                                <span>Certificado digital</span>
                                <strong><?= $servicoCertificado ? 'Sim' : 'Não' ?></strong>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($clienteContabil && !empty($alvarasCliente)): ?>
                    <section class="ficha-impressao-secao">
                        <h2>Alvarás e licenças</h2>
                        <table class="ficha-impressao-tabela">
                            <thead>
                                <tr>
                                    <th>Órgão</th>
                                    <th>Situação</th>
                                    <th>Vencimento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alvarasCliente as $alvaraCliente):
                                    $textoSituacaoImpressao = [
                                        'com_vencimento' => 'Com vencimento',
                                        'dispensado' => 'Dispensado',
                                        'em_estudo' => 'Em estudo',
                                    ][$alvaraCliente['situacao']] ?? 'Não informado';
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($alvaraCliente['orgao_nome']) ?></td>
                                        <td><?= htmlspecialchars($textoSituacaoImpressao) ?></td>
                                        <td>
                                            <?= $alvaraCliente['situacao'] === 'com_vencimento'
                                                ? htmlspecialchars($formatarData($alvaraCliente['vencimento']))
                                                : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <?php if (!empty($parcelamentosCliente)): ?>
                    <section class="ficha-impressao-secao">
                        <h2>Parcelamentos</h2>
                        <table class="ficha-impressao-tabela">
                            <thead>
                                <tr>
                                    <th>Órgão</th>
                                    <th>Número</th>
                                    <th>Forma envio</th>
                                    <th>Primeira parcela</th>
                                    <th>Parcelas</th>
                                    <th>Atrasadas</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parcelamentosCliente as $parcelamentoCliente): ?>
                                    <?php
                                    $parcelasEmitidasImpressao = parcelasEmitidasAtual($parcelamentoCliente);
                                    $parcelasTotalImpressao = (int)($parcelamentoCliente['parcelas_total'] ?? 0);
                                    $parcelasAtrasadasImpressao = max(0, (int)($parcelamentoCliente['parcelas_atrasadas'] ?? 0));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($parcelamentoCliente['orgao'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($valorOuNaoInformado($parcelamentoCliente['numero_parcelamento'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($valorOuNaoInformado($parcelamentoCliente['forma_envio'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($formatarData($parcelamentoCliente['data_primeira_parcela'] ?? null)) ?></td>
                                        <td>
                                            <?= $parcelasEmitidasImpressao ?>
                                            /
                                            <?= $parcelasTotalImpressao ?>
                                        </td>
                                        <td><?= $parcelasAtrasadasImpressao ?></td>
                                        <td><?= htmlspecialchars(statusParcelamento($parcelamentoCliente)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section class="ficha-impressao-secao">
                    <h2>Endereço</h2>
                    <div class="ficha-impressao-grade">
                        <div class="ficha-impressao-item">
                            <span>CEP</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['cep'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item" style="grid-column: span 2;">
                            <span>Logradouro e número</span>
                            <strong>
                                <?= htmlspecialchars($valorOuNaoInformado(
                                    trim((string)($cliente['endereco'] ?? ''))
                                        . (!empty($cliente['numero_endereco']) ? ', ' . $cliente['numero_endereco'] : '')
                                )) ?>
                            </strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>Complemento</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['complemento'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>Bairro</span>
                            <strong><?= htmlspecialchars($valorOuNaoInformado($cliente['bairro'] ?? '')) ?></strong>
                        </div>
                        <div class="ficha-impressao-item">
                            <span>Cidade/UF</span>
                            <strong>
                                <?= htmlspecialchars($valorOuNaoInformado(
                                    trim((string)($cliente['cidade'] ?? ''))
                                        . (!empty($cliente['uf']) ? ' / ' . $cliente['uf'] : '')
                                )) ?>
                            </strong>
                        </div>
                    </div>
                </section>
            </article>

        </div>
    </main>

    <?php include 'includes/modal_confirmar.php'; ?>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

    <script>
        const clienteAtual = <?= json_encode($cliente, JSON_UNESCAPED_UNICODE) ?>;
    </script>

</body>

</html>