<?php
require 'config.php';

exigirPermissao('pendencias');

$hoje = date('Y-m-d');

function adicionarPendencia(array &$pendencias, array &$resumo, array $cliente, string $tipo, string $descricao, string $status, string $nivel = 'danger', ?string $resolver = null, ?string $resolverUrl = null, array $resolverModal = []): void
{
    $resumo[$tipo] = ($resumo[$tipo] ?? 0) + 1;

    $pendencias[] = [
        'codigo' => $cliente['codigo'] ?? '',
        'nome' => $cliente['nome'] ?? '',
        'documento' => $cliente['documento'] ?? '',
        'tipo' => $tipo,
        'descricao' => $descricao,
        'status' => $status,
        'nivel' => $nivel,
        'cliente_id' => (int)($cliente['id'] ?? 0),
        'resolver' => $resolver,
        'resolver_url' => $resolverUrl,
        'resolver_modal' => $resolverModal,
    ];
}

function dataBr(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function modalCertificado(array $cliente): array
{
    return [
        'modo' => 'certificado',
        'titulo' => 'Resolver certificado',
        'cliente' => trim(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? '')),
        'campo_status' => 'certificado_status',
        'status_atual' => $cliente['certificado_status'] ?? (!empty($cliente['vencimento_certificado']) ? 'possui' : 'nao_possui'),
        'opcoes' => [
            'possui' => 'Possui',
            'nao_possui' => 'Não possui',
            'nao_precisa_momento' => 'Não precisa no momento',
        ],
        'vencimento_atual' => $cliente['vencimento_certificado'] ?? '',
    ];
}

function modalControle(array $cliente, string $titulo, string $campoStatus, array $opcoes, ?string $campoVencimento = null, bool $conferirDados = false, bool $conferirSocio = false): array
{
    return [
        'modo' => 'controle',
        'titulo' => $titulo,
        'cliente' => trim(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? '')),
        'campo_status' => $campoStatus,
        'campo_vencimento' => $campoVencimento,
        'status_atual' => $cliente[$campoStatus] ?? '',
        'vencimento_atual' => $campoVencimento !== null ? ($cliente[$campoVencimento] ?? '') : '',
        'opcoes' => $opcoes,
        'conferir_dados' => $conferirDados,
        'conferir_socio' => $conferirSocio,
    ];
}

function modalAlvaraDf(array $cliente, array $alvaras): array
{
    return [
        'modo' => 'alvara_df',
        'titulo' => 'Resolver alvarás DF e DF Legal',
        'cliente' => trim(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? '')),
        'alvara_atual' => $cliente['alvara'] ?? '',
        'df_legal_atual' => $cliente['cadastro_df_legal'] ?? '',
        'alvaras' => $alvaras,
    ];
}

function clienteEnderecoIncompleto(array $cliente): bool
{
    foreach (['cep', 'endereco', 'numero_endereco', 'bairro', 'cidade', 'uf'] as $campo) {
        if (trim((string)($cliente[$campo] ?? '')) === '') {
            return true;
        }
    }

    return false;
}

function clienteDocumentoEhCnpj(array $cliente): bool
{
    return strlen(preg_replace('/\D/', '', (string)($cliente['documento'] ?? ''))) === 14;
}

$orgaosAlvaraDf = [
    'ibram' => 'INSTITUTO BRASÍLIA AMBIENTAL - IBRAM',
    'cbmdf' => 'CORPO DE BOMBEIROS MILITAR DO DISTRITO FEDERAL - CBMDF',
    'df_legal' => 'SECRETARIA DE ESTADO DE PROTEÇÃO DA ORDEM URBANÍSTICA DO DISTRITO FEDERAL - DF LEGAL',
    'pcdf' => 'POLÍCIA CIVIL DO DISTRITO FEDERAL - PCDF',
    'seagri' => 'SECRETARIA DE ESTADO DE AGRICULTURA, ABASTECIMENTO E DESENVOLVIMENTO RURAL - SEAGRI',
    'seedf' => 'SECRETARIA DE EDUCAÇÃO DO DISTRITO FEDERAL - SEEDF',
    'sudesc' => 'SUBSECRETARIA DO SISTEMA DE DEFESA CIVIL - SUDESC',
    'visadf' => 'VIGILÂNCIA SANITÁRIA DO DISTRITO FEDERAL - VISADF',
];

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE 1 = 1
    " . clientesFiltroAtivos($pdo) . "
    " . empresaFiltroClienteDireto($pdo) . "
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$clientesPorId = [];
$alvarasPorCliente = [];
$sociosPorCliente = [];
$paralisacaoDisponivel = logiColunaExiste($pdo, 'clientes', 'paralisacao_status')
    && logiColunaExiste($pdo, 'clientes', 'paralisacao_fim');
$alvaraGoiasDisponivel = logiTabelaExiste($pdo, 'cliente_alvaras_goias');
$alvarasGoiasPorCliente = [];
$certificadoStatusDisponivel = logiColunaExiste($pdo, 'clientes', 'certificado_status');

foreach ($clientes as $cliente) {
    if ((int)($cliente['cliente_contabil'] ?? 1) !== 1) {
        continue;
    }

    $clientesPorId[(int)$cliente['id']] = $cliente;
}

try {
    $stmtTodosAlvaras = $pdo->query("
        SELECT ca.cliente_id, ca.orgao_codigo, ca.situacao, ca.vencimento
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE 1 = 1
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");

    foreach ($stmtTodosAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvaraCliente) {
        $alvarasPorCliente[(int)$alvaraCliente['cliente_id']][$alvaraCliente['orgao_codigo']] = [
            'situacao' => $alvaraCliente['situacao'],
            'vencimento' => $alvaraCliente['vencimento'] ?? '',
        ];
    }
} catch (Throwable $e) {
}

if ($alvaraGoiasDisponivel) {
    try {
        $stmtAlvarasGoias = $pdo->query("
            SELECT ag.cliente_id, ag.orgao_codigo, ag.situacao, ag.vencimento
            FROM cliente_alvaras_goias ag
            INNER JOIN clientes c ON c.id = ag.cliente_id
            WHERE 1 = 1
            " . empresaFiltroClienteDireto($pdo, 'c') . "
        ");

        foreach ($stmtAlvarasGoias->fetchAll(PDO::FETCH_ASSOC) as $alvaraGoias) {
            $alvarasGoiasPorCliente[(int)$alvaraGoias['cliente_id']][$alvaraGoias['orgao_codigo']] = [
                'situacao' => $alvaraGoias['situacao'],
                'vencimento' => $alvaraGoias['vencimento'] ?? '',
            ];
        }
    } catch (Throwable $e) {
    }
}

if (logiTabelaExiste($pdo, 'cliente_socios')) {
    try {
        $stmtTodosSocios = $pdo->query("
            SELECT cs.cliente_id, COUNT(*) AS total_socios
            FROM cliente_socios cs
            INNER JOIN clientes c ON c.id = cs.cliente_id
            WHERE 1 = 1
            " . empresaFiltroClienteDireto($pdo, 'c') . "
            GROUP BY cs.cliente_id
        ");

        foreach ($stmtTodosSocios->fetchAll(PDO::FETCH_ASSOC) as $sociosCliente) {
            $sociosPorCliente[(int)$sociosCliente['cliente_id']] = (int)$sociosCliente['total_socios'];
        }
    } catch (Throwable $e) {
    }
}

$pendencias = [];
$resumo = [
    'Certificado' => 0,
    'Procurações' => 0,
    'Alvará' => 0,
    'Controles internos' => 0,
];
$qsaDisponivel = logiTabelaExiste($pdo, 'cliente_socios');

$procuracoes = [
    [
        'status' => 'procuracao_receita_federal',
        'vencimento' => 'vencimento_procuracao_receita_federal',
        'nome' => 'Procuração Receita Federal',
    ],
    [
        'status' => 'procuracao_conectividade',
        'vencimento' => 'vencimento_procuracao_conectividade',
        'nome' => 'Procuração Conectividade',
    ],
    [
        'status' => 'procuracao_fgts',
        'vencimento' => 'vencimento_procuracao_fgts',
        'nome' => 'Procuração FGTS',
    ],
    [
        'status' => 'procuracao_empregador_web',
        'vencimento' => null,
        'nome' => 'Procuração Empregador Web',
    ],
    [
        'status' => 'procuracao_particular',
        'vencimento' => null,
        'nome' => 'Procuração Particular',
    ],
    [
        'status' => 'procuracao_sefaz',
        'vencimento' => null,
        'nome' => 'Procuração SEFAZ',
    ],
];

foreach ($clientes as $cliente) {
    $clienteContabil = (int)($cliente['cliente_contabil'] ?? 1) === 1;
    $controlaCertificado = $clienteContabil || (int)($cliente['servico_certificado'] ?? 1) === 1;
    $clienteParalisado = $paralisacaoDisponivel
        && ($cliente['paralisacao_status'] ?? '') === 'paralisada'
        && (empty($cliente['paralisacao_fim']) || $cliente['paralisacao_fim'] >= $hoje);
    $certificadoNaoPrecisa = $certificadoStatusDisponivel
        && ($cliente['certificado_status'] ?? '') === 'nao_precisa_momento';

    if ($clienteContabil && clienteEnderecoIncompleto($cliente)) {
        adicionarPendencia(
            $pendencias,
            $resumo,
            $cliente,
            'Dados cadastrais',
            'Endereço incompleto no cadastro do cliente',
            'Incompleto',
            'warning',
            null,
            'cliente_editar.php?id=' . (int)$cliente['id']
        );
    }

    if ($qsaDisponivel && $clienteContabil && clienteDocumentoEhCnpj($cliente) && (($sociosPorCliente[(int)$cliente['id']] ?? 0) <= 0)) {
        adicionarPendencia(
            $pendencias,
            $resumo,
            $cliente,
            'Dados cadastrais',
            'QSA não preenchido no cadastro do cliente',
            'Não informado',
            'warning',
            null,
            'cliente_editar.php?id=' . (int)$cliente['id']
        );
    }

    if (!$clienteParalisado && !$certificadoNaoPrecisa && $controlaCertificado && !empty($cliente['pendencia_certificado_digital'])) {
        adicionarPendencia(
            $pendencias,
            $resumo,
            $cliente,
            'Certificado',
            'Razão social ou UF alterada. Verificar necessidade de substituição do certificado digital.',
            'A resolver',
            'warning',
            'certificado'
        );
    }

    if (!$clienteParalisado && !$certificadoNaoPrecisa && $controlaCertificado && empty($cliente['vencimento_certificado'])) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Certificado', 'Certificado digital não informado', 'Não possui', 'danger', null, null, modalCertificado($cliente));
    } elseif (!$clienteParalisado && !$certificadoNaoPrecisa && $controlaCertificado && $cliente['vencimento_certificado'] < $hoje) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Certificado', 'Certificado digital vencido em ' . dataBr($cliente['vencimento_certificado']), 'Vencido', 'danger', null, null, modalCertificado($cliente));
    }

    if (!$clienteContabil) {
        continue;
    }

    foreach ($procuracoes as $procuracao) {
        if (
            $clienteParalisado
            && in_array($procuracao['status'], [
                'procuracao_conectividade',
                'procuracao_empregador_web',
                'procuracao_sefaz',
            ], true)
        ) {
            continue;
        }

        $status = $cliente[$procuracao['status']] ?? '';
        if ($status === 'nao_precisa_momento') {
            continue;
        }

        if ($procuracao['status'] === 'procuracao_sefaz') {
            $opcoesProcuracao = ['possui' => 'Possui', 'nao_possui' => 'Não possui', 'nao_precisa_momento' => 'Não precisa no momento', 'goias' => 'Goiás'];
        } elseif ($procuracao['status'] === 'procuracao_empregador_web') {
            $opcoesProcuracao = ['possui' => 'Possui', 'nao_possui' => 'Não possui', 'nao_tem_funcionario' => 'Não tem funcionário', 'nao_precisa_momento' => 'Não precisa no momento'];
        } else {
            $opcoesProcuracao = ['possui' => 'Possui', 'nao_possui' => 'Não possui', 'nao_precisa_momento' => 'Não precisa no momento'];
        }
        $modalProcuracao = modalControle(
            $cliente,
            'Resolver ' . $procuracao['nome'],
            $procuracao['status'],
            $opcoesProcuracao,
            $procuracao['vencimento'],
            $procuracao['status'] === 'procuracao_particular',
            $procuracao['status'] === 'procuracao_particular'
        );

        if ($status === '' || $status === 'nao_possui') {
            adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' não informada ou não possui', 'Não possui', 'danger', null, null, $modalProcuracao);
            continue;
        }

        if ($procuracao['vencimento'] !== null && $status === 'possui') {
            $vencimento = $cliente[$procuracao['vencimento']] ?? null;

            if (empty($vencimento)) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' sem vencimento', 'Sem data', 'danger', null, null, $modalProcuracao);
            } elseif ($vencimento < $hoje) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', $procuracao['nome'] . ' vencida em ' . dataBr($vencimento), 'Vencida', 'danger', null, null, $modalProcuracao);
            }
        }
    }

    $alvara = $cliente['alvara'] ?? '';
    $modalAlvaraCliente = modalAlvaraDf(
        $cliente,
        $alvarasPorCliente[(int)$cliente['id']] ?? []
    );

    $clienteGoias = strtoupper((string)($cliente['uf'] ?? '')) === 'GO'
        || ($cliente['alvara'] ?? '') === 'goias'
        || ($cliente['cadastro_df_legal'] ?? '') === 'goias';

    if (!$clienteParalisado && !$clienteGoias && ($alvara === '' || $alvara === 'nao_possui')) {
        adicionarPendencia(
            $pendencias,
            $resumo,
            $cliente,
            'Alvará',
            'Alvará não informado ou não possui',
            'Não possui',
            'danger',
            null,
            null,
            $modalAlvaraCliente
        );
    }

    if (!$clienteParalisado && ($cliente['alvara'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_alvara_funcionamento'])) {
        adicionarPendencia(
            $pendencias,
            $resumo,
            $cliente,
            'Alvará',
            'Razão social, endereço ou UF alterado. Verificar impacto no alvará de funcionamento.',
            'A resolver',
            'warning',
            null,
            null,
            $clienteGoias ? [] : $modalAlvaraCliente
        );
    }

    if (!$clienteParalisado && $clienteGoias && $alvaraGoiasDisponivel) {
        $orgaosGoias = [
            'bombeiros' => 'Bombeiros',
            'vigilancia' => 'Vigilância',
            'prefeitura' => 'Prefeitura',
        ];
        $alvarasGoiasCliente = $alvarasGoiasPorCliente[(int)$cliente['id']] ?? [];

        foreach ($orgaosGoias as $codigoOrgao => $nomeOrgao) {
            $alvaraGoias = $alvarasGoiasCliente[$codigoOrgao] ?? [];
            $situacaoGoias = $alvaraGoias['situacao'] ?? '';
            $vencimentoGoias = $alvaraGoias['vencimento'] ?? '';

            if (in_array($situacaoGoias, ['', 'nao_informado'], true)) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Alvará Goiás', $nomeOrgao . ' não informado', 'Pendente', 'danger', null, 'alvaras_goias.php');
            } elseif ($situacaoGoias === 'em_estudo') {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Alvará Goiás', $nomeOrgao . ' em estudo', 'A resolver', 'warning', null, 'alvaras_goias.php');
            } elseif ($situacaoGoias === 'com_vencimento' && empty($vencimentoGoias)) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Alvará Goiás', $nomeOrgao . ' sem vencimento', 'Sem data', 'danger', null, 'alvaras_goias.php');
            } elseif ($situacaoGoias === 'com_vencimento' && $vencimentoGoias < $hoje) {
                adicionarPendencia($pendencias, $resumo, $cliente, 'Alvará Goiás', $nomeOrgao . ' vencido em ' . dataBr($vencimentoGoias), 'Vencido', 'danger', null, 'alvaras_goias.php');
            }
        }
    }

    if (($cliente['contador'] ?? '') === '' || ($cliente['contador'] ?? '') === 'nao') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Contador não informado ou marcado como sem contador', 'Pendente', 'warning', null, null, modalControle($cliente, 'Resolver contador', 'contador', ['sim' => 'Contador ativo', 'nao' => 'Sem contador', 'nao_precisa_momento' => 'Não precisa no momento']));
    }

    if (!$clienteParalisado && (($cliente['cadastro_crf'] ?? '') === '' || ($cliente['cadastro_crf'] ?? '') === 'nao_cadastrado')) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Cadastro CRF não cadastrado', 'Pendente', 'warning', null, null, modalControle($cliente, 'Resolver Cadastro CRF', 'cadastro_crf', ['cadastrado' => 'Cadastrado', 'nao_cadastrado' => 'Não cadastrado', 'nao_precisa_momento' => 'Não precisa no momento'], null, true));
    }

    if (!$clienteParalisado && ($cliente['cadastro_crf'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_crf_dados'])) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Cadastro CRF com razão social ou endereço incorreto', 'A resolver', 'warning', null, null, modalControle($cliente, 'Resolver Cadastro CRF', 'cadastro_crf', ['cadastrado' => 'Cadastrado', 'nao_cadastrado' => 'Não cadastrado', 'nao_precisa_momento' => 'Não precisa no momento'], null, true));
    }

    if (!$clienteParalisado && (($cliente['cadastro_df_legal'] ?? '') === '' || ($cliente['cadastro_df_legal'] ?? '') === 'nao_cadastrado')) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Cadastro DF Legal não cadastrado', 'Pendente', 'warning', null, null, modalControle($cliente, 'Resolver Cadastro DF Legal', 'cadastro_df_legal', ['cadastrado' => 'Cadastrado', 'nao_cadastrado' => 'Não cadastrado', 'nao_precisa_momento' => 'Não precisa no momento', 'goias' => 'Goiás'], null, true));
    }

    if (!$clienteParalisado && ($cliente['cadastro_df_legal'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_df_legal_dados'])) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Cadastro DF Legal com razão social ou endereço incorreto', 'A resolver', 'warning', null, null, modalControle($cliente, 'Resolver Cadastro DF Legal', 'cadastro_df_legal', ['cadastrado' => 'Cadastrado', 'nao_cadastrado' => 'Não cadastrado', 'nao_precisa_momento' => 'Não precisa no momento', 'goias' => 'Goiás'], null, true));
    }

    if (($cliente['procuracao_particular'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_procuracao_particular_dados'])) {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Procurações', 'Procuração Particular com razão social, endereço ou sócio incorreto', 'A resolver', 'warning', null, null, modalControle($cliente, 'Resolver Procuração Particular', 'procuracao_particular', ['possui' => 'Possui', 'nao_possui' => 'Não possui', 'nao_precisa_momento' => 'Não precisa no momento'], null, true, true));
    }

    if (($cliente['contrato_prestacao_servicos'] ?? '') === '' || ($cliente['contrato_prestacao_servicos'] ?? '') === 'nao_possui') {
        adicionarPendencia($pendencias, $resumo, $cliente, 'Controles internos', 'Contrato de prestação de serviços não possui', 'Pendente', 'warning', null, null, modalControle($cliente, 'Resolver contrato de prestação de serviços', 'contrato_prestacao_servicos', ['possui' => 'Possui', 'nao_possui' => 'Não possui', 'nao_precisa_momento' => 'Não precisa no momento']));
    }
}

try {
    $stmtAlvarasIncompletos = $pdo->query("
        SELECT
            c.id,
            c.codigo,
            c.nome,
            c.documento,
            COUNT(DISTINCT CASE
                WHEN ca.orgao_codigo IN ('ibram', 'cbmdf', 'df_legal', 'pcdf', 'seagri', 'seedf', 'sudesc', 'visadf')
                 AND (
                    ca.situacao = 'dispensado'
                    OR (ca.situacao = 'com_vencimento' AND ca.vencimento IS NOT NULL)
                 )
                THEN ca.orgao_codigo
            END) AS total_preenchido
        FROM clientes c
        LEFT JOIN cliente_alvaras ca ON ca.cliente_id = c.id
        WHERE c.alvara = 'possui'
          AND c.cliente_contabil = 1
          " . clientesFiltroAtivos($pdo, 'c') . "
          " . empresaFiltroClienteDireto($pdo, 'c') . "
          " . ($paralisacaoDisponivel ? " AND NOT (COALESCE(c.paralisacao_status, 'ativa') = 'paralisada' AND (c.paralisacao_fim IS NULL OR c.paralisacao_fim >= CURDATE())) " : "") . "
        GROUP BY c.id, c.codigo, c.nome, c.documento
        HAVING total_preenchido < 8
        ORDER BY CAST(c.codigo AS UNSIGNED) ASC, c.nome ASC
    ");

    foreach ($stmtAlvarasIncompletos->fetchAll(PDO::FETCH_ASSOC) as $clienteAlvara) {
        $faltantes = 8 - (int)$clienteAlvara['total_preenchido'];
        $clienteCompleto = $clientesPorId[(int)$clienteAlvara['id']] ?? $clienteAlvara;

        adicionarPendencia(
            $pendencias,
            $resumo,
            $clienteCompleto,
            'Alvará',
            $faltantes . ($faltantes === 1 ? ' órgão não informado ou em estudo' : ' órgãos não informados ou em estudo'),
            'Incompleto',
            'danger',
            null,
            null,
            modalAlvaraDf(
                $clienteCompleto,
                $alvarasPorCliente[(int)$clienteAlvara['id']] ?? []
            )
        );
    }

    $stmtAlvaras = $pdo->query("
        SELECT ca.*, c.codigo, c.nome, c.documento
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE ca.situacao = 'com_vencimento'
          AND ca.vencimento IS NOT NULL
          AND ca.vencimento < " . $pdo->quote($hoje) . "
          AND c.cliente_contabil = 1
          " . clientesFiltroAtivos($pdo, 'c') . "
          " . empresaFiltroClienteDireto($pdo, 'c') . "
          " . ($paralisacaoDisponivel ? " AND NOT (COALESCE(c.paralisacao_status, 'ativa') = 'paralisada' AND (c.paralisacao_fim IS NULL OR c.paralisacao_fim >= CURDATE())) " : "") . "
        ORDER BY ca.vencimento ASC
    ");

    foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvaraCliente) {
        $nivel = 'danger';
        $status = 'Vencido';
        $clienteCompleto = $clientesPorId[(int)$alvaraCliente['cliente_id']] ?? $alvaraCliente;

        adicionarPendencia(
            $pendencias,
            $resumo,
            [
                'id' => $alvaraCliente['cliente_id'],
                'codigo' => $alvaraCliente['codigo'],
                'nome' => $alvaraCliente['nome'],
                'documento' => $alvaraCliente['documento'],
            ],
            'Alvará',
            $alvaraCliente['orgao_nome'] . ' - vencimento em ' . dataBr($alvaraCliente['vencimento']),
            $status,
            $nivel,
            null,
            null,
            modalAlvaraDf(
                $clienteCompleto,
                $alvarasPorCliente[(int)$alvaraCliente['cliente_id']] ?? []
            )
        );
    }
} catch (Throwable $e) {
}

if (logiTabelaExiste($pdo, 'antivirus_controles')) {
    try {
        $stmtAntivirusPendencias = $pdo->query("
            SELECT *
            FROM antivirus_controles
            WHERE (
                status = 'nao_possui'
                OR (status = 'possui' AND (vencimento IS NULL OR vencimento < " . $pdo->quote($hoje) . "))
            )
            " . empresaFiltro($pdo, 'antivirus_controles') . "
            ORDER BY colaborador ASC, computador ASC
        ");

        foreach ($stmtAntivirusPendencias->fetchAll(PDO::FETCH_ASSOC) as $antivirus) {
            $clienteAntivirus = [
                'id' => 0,
                'codigo' => '',
                'nome' => $antivirus['colaborador'] ?? '',
                'documento' => $antivirus['computador'] ?? '',
            ];
            $statusAntivirus = $antivirus['status'] ?? '';
            $vencimentoAntivirus = $antivirus['vencimento'] ?? '';

            if ($statusAntivirus === 'nao_possui') {
                adicionarPendencia(
                    $pendencias,
                    $resumo,
                    $clienteAntivirus,
                    'Antivírus',
                    'Computador sem antivírus informado',
                    'Não possui',
                    'danger',
                    null,
                    'antivirus.php'
                );
                continue;
            }

            if (empty($vencimentoAntivirus)) {
                adicionarPendencia(
                    $pendencias,
                    $resumo,
                    $clienteAntivirus,
                    'Antivírus',
                    'Antivírus sem vencimento informado',
                    'Sem data',
                    'danger',
                    null,
                    'antivirus.php'
                );
                continue;
            }

            adicionarPendencia(
                $pendencias,
                $resumo,
                $clienteAntivirus,
                'Antivírus',
                'Antivírus vencido em ' . dataBr($vencimentoAntivirus),
                'Vencido',
                'danger',
                null,
                'antivirus.php'
            );
        }
    } catch (Throwable $e) {
    }
}

$totalPendencias = count($pendencias);
$limiteGraficoPendencias = 15;
$pendenciasPorPaginaInicial = 15;
$totalPaginasPendenciasInicial = (int)ceil($totalPendencias / $pendenciasPorPaginaInicial);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Pendências</title>
    <style>
        .pendencia-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .pendencia-numero {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .grafico-barra {
            height: 12px;
            background: #e9ecef;
            border-radius: 999px;
            overflow: hidden;
        }

        .grafico-barra span {
            display: block;
            height: 100%;
            background: #0d6efd;
        }

        #modalPendenciaAlvaraDf .modal-content {
            overflow: hidden;
        }

        #formPendenciaAlvaraDf {
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 2rem);
            min-height: 0;
        }

        #formPendenciaAlvaraDf .modal-header,
        #formPendenciaAlvaraDf .modal-footer {
            flex-shrink: 0;
        }

        #formPendenciaAlvaraDf .modal-body {
            min-height: 0;
            overflow-y: scroll;
            scrollbar-gutter: stable;
        }

        #formPendenciaAlvaraDf .modal-body::-webkit-scrollbar {
            width: 10px;
        }

        #formPendenciaAlvaraDf .modal-body::-webkit-scrollbar-thumb {
            background: #adb5bd;
            border: 2px solid #f1f3f5;
            border-radius: 5px;
        }

        .tabela-pendencia-alvara th:first-child {
            min-width: 420px;
        }

        .tabela-pendencia-alvara th:nth-child(2) {
            width: 190px;
        }

        .tabela-pendencia-alvara th:nth-child(3) {
            width: 180px;
        }

        .pendencias-impressao-cabecalho {
            display: none;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            body {
                background: #fff !important;
            }

            .app-sidebar,
            .container-fluid> :not(.clientes-box),
            .filtros-pendencias,
            .acoes-pendencia {
                display: none !important;
            }

            .app-main,
            .app-sidebar.collapsed+.app-main {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .clientes-box {
                padding: 0 !important;
                border: 0 !important;
                box-shadow: none !important;
            }

            .pendencias-impressao-cabecalho {
                display: block !important;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 2px solid #111827;
            }

            .pendencias-impressao-cabecalho h1 {
                margin: 0 0 4px;
                font-size: 18pt;
            }

            .clientes-box table {
                font-size: 9pt;
            }
        }

        @media (max-width: 768px) {
            #formPendenciaAlvaraDf {
                max-height: calc(100dvh - 1rem);
            }

            .tabela-pendencia-alvara th:first-child {
                min-width: 280px;
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
                    <h3 class="mb-1">Pendências</h3>
                    <p class="text-muted mb-0">Clientes com informações ausentes, vencidas ou próximas do vencimento</p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="importar_pendencias.php" class="btn btn-outline-success">
                        <i class="bi bi-upload"></i> Importar
                    </a>
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['resolvido'])): ?>
                <div class="alert alert-success alert-auto-dismiss fade show">
                    Pendência resolvida com sucesso.
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="pendencia-card">
                        <div class="text-muted small">Total</div>
                        <div class="pendencia-numero" id="totalPendenciasNumero"><?= $totalPendencias ?></div>
                        <div class="text-muted small">pendências encontradas</div>
                    </div>
                </div>

                <?php foreach ($resumo as $tipo => $quantidade): ?>
                    <div class="col-md-3">
                        <div class="pendencia-card">
                            <div class="d-flex justify-content-between mb-2">
                                <strong><?= htmlspecialchars($tipo) ?></strong>
                                <span class="quantidade-resumo" data-resumo-tipo="<?= htmlspecialchars($tipo) ?>"><?= (int)$quantidade ?></span>
                            </div>
                            <div class="grafico-barra">
                                <span
                                    data-barra-tipo="<?= htmlspecialchars($tipo) ?>"
                                    style="width: <?= min(100, (int)(($quantidade / $limiteGraficoPendencias) * 100)) ?>%">
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="clientes-box">
                <div class="pendencias-impressao-cabecalho">
                    <h1>Pendências</h1>
                    <span id="tipoPendenciaImpressao">Todos os tipos</span>
                </div>

                <div class="row g-2 mb-3 filtros-pendencias">
                    <div class="col-md-5">
                        <input type="text" id="buscaPendencia" class="form-control" placeholder="Buscar por cliente, código, documento ou pendência...">
                    </div>

                    <div class="col-md-3">
                        <select id="filtroTipoPendencia" class="form-select">
                            <option value="">Todos os tipos</option>
                            <?php foreach (array_keys($resumo) as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2<?= $totalPendencias <= $pendenciasPorPaginaInicial ? ' d-none' : '' ?>" id="grupoLimitePendencias">
                        <select id="limitePendencias" class="form-select">
                            <option value="15">Mostrar 15</option>
                            <option value="30">Mostrar 30</option>
                            <option value="60">Mostrar 60</option>
                            <option value="90">Mostrar 90</option>
                        </select>
                    </div>

                    <div class="col-md-2 text-md-end">
                        <button type="button" class="btn btn-outline-secondary" id="btnImprimirPendencias">
                            <i class="bi bi-printer"></i> Imprimir filtradas
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Pendência</th>
                                <th>Status</th>
                                <th class="text-end acoes-pendencia">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendencias)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Nenhuma pendência encontrada.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($pendencias as $indicePendencia => $pendencia): ?>
                                <tr class="linha-pendencia<?= $indicePendencia >= $pendenciasPorPaginaInicial ? ' d-none' : '' ?>" data-tipo="<?= htmlspecialchars($pendencia['tipo']) ?>">
                                    <td class="texto-pendencia">
                                        <strong>
                                            <?= trim((string)$pendencia['codigo']) !== '' ? htmlspecialchars($pendencia['codigo']) . ' - ' : '' ?><?= htmlspecialchars($pendencia['nome']) ?>
                                        </strong>
                                        <small class="text-muted d-block"><?= htmlspecialchars($pendencia['documento']) ?></small>
                                    </td>
                                    <td class="texto-pendencia"><?= htmlspecialchars($pendencia['tipo']) ?></td>
                                    <td class="texto-pendencia"><?= htmlspecialchars($pendencia['descricao']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= htmlspecialchars($pendencia['nivel']) ?>">
                                            <?= htmlspecialchars($pendencia['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end acoes-pendencia">
                                        <div class="d-flex justify-content-end gap-2">
                                            <?php if (!empty($pendencia['resolver'])): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm btn-resolver-pendencia"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalResolverPendencia"
                                                    data-cliente-id="<?= (int)$pendencia['cliente_id'] ?>"
                                                    data-tipo="<?= htmlspecialchars($pendencia['resolver']) ?>"
                                                    data-cliente="<?= htmlspecialchars($pendencia['codigo'] . ' - ' . $pendencia['nome']) ?>"
                                                    data-descricao="<?= htmlspecialchars($pendencia['descricao']) ?>">
                                                    <i class="bi bi-check2-circle"></i> Resolver
                                                </button>
                                            <?php elseif (!empty($pendencia['resolver_modal'])): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm btn-editar-pendencia"
                                                    data-cliente-id="<?= (int)$pendencia['cliente_id'] ?>"
                                                    data-modal="<?= htmlspecialchars(json_encode($pendencia['resolver_modal'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                    <i class="bi bi-check2-circle"></i> Resolver
                                                </button>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($pendencia['resolver_url'] ?? ('cliente.php?id=' . (int)$pendencia['cliente_id'])) ?>" class="btn btn-outline-success btn-sm">
                                                    <i class="bi bi-check2-circle"></i> Resolver
                                                </a>
                                            <?php endif; ?>

                                            <?php if ((int)$pendencia['cliente_id'] > 0): ?>
                                                <a href="cliente.php?id=<?= (int)$pendencia['cliente_id'] ?>" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($pendencias)): ?>
                                <tr id="linhaPendenciasFiltradasVazio" class="d-none">
                                    <td colspan="5" class="text-center text-muted py-4">Nenhuma pendência encontrada.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 filtros-pendencias<?= $totalPaginasPendenciasInicial <= 1 ? ' d-none' : '' ?>" id="paginacaoPendencias">
                    <?php if ($totalPaginasPendenciasInicial > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center mb-0">
                                <li class="page-item disabled">
                                    <button type="button" class="page-link" disabled>Anterior</button>
                                </li>
                                <li class="page-item active">
                                    <button type="button" class="page-link">1</button>
                                </li>
                                <?php for ($paginaInicial = 2; $paginaInicial <= min(3, $totalPaginasPendenciasInicial); $paginaInicial++): ?>
                                    <li class="page-item">
                                        <button type="button" class="page-link"><?= $paginaInicial ?></button>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($totalPaginasPendenciasInicial > 4): ?>
                                    <li class="page-item disabled">
                                        <button type="button" class="page-link" disabled>...</button>
                                    </li>
                                <?php endif; ?>
                                <?php if ($totalPaginasPendenciasInicial > 3): ?>
                                    <li class="page-item">
                                        <button type="button" class="page-link"><?= $totalPaginasPendenciasInicial ?></button>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <button type="button" class="page-link">Próxima</button>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalResolverPendencia" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Resolver pendência</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1">Confirma que esta pendência já foi resolvida?</p>
                    <strong id="clientePendenciaResolver" class="d-block mb-2"></strong>
                    <span id="descricaoPendenciaResolver" class="text-muted"></span>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                    <form method="post" action="pendencia_resolver.php" class="m-0">
                        <input type="hidden" name="cliente_id" id="clienteIdPendenciaResolver">
                        <input type="hidden" name="tipo" id="tipoPendenciaResolver">
                        <button type="submit" class="btn btn-success">Sim, resolver</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarPendencia" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tituloModalEditarPendencia">Resolver pendência</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modalPendenciaClienteId">
                    <input type="hidden" id="modalPendenciaModo">
                    <input type="hidden" id="modalPendenciaCampoStatus">
                    <input type="hidden" id="modalPendenciaCampoVencimento">

                    <div class="mb-3">
                        <label class="form-label">Cliente</label>
                        <input type="text" class="form-control" id="modalPendenciaCliente" disabled>
                    </div>

                    <div class="mb-3 d-none" id="grupoModalPendenciaStatus">
                        <label for="modalPendenciaStatus" class="form-label">Situação</label>
                        <select class="form-select" id="modalPendenciaStatus"></select>
                    </div>

                    <div class="mb-3" id="grupoModalPendenciaVencimento">
                        <label for="modalPendenciaVencimento" class="form-label">Vencimento</label>
                        <input type="date" class="form-control" id="modalPendenciaVencimento">
                        <div class="form-text" id="textoAjudaModalPendencia"></div>
                    </div>

                    <div class="d-none" id="grupoModalPendenciaConferencia">
                        <div class="mb-3">
                            <label class="form-label d-block">Razão social está correta?</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_razao_social_correta" id="pendencia_razao_social_sim" value="sim" checked>
                                <label class="form-check-label" for="pendencia_razao_social_sim">Sim</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_razao_social_correta" id="pendencia_razao_social_nao" value="nao">
                                <label class="form-check-label" for="pendencia_razao_social_nao">Não</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label d-block">Endereço está correto?</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_endereco_correto" id="pendencia_endereco_sim" value="sim" checked>
                                <label class="form-check-label" for="pendencia_endereco_sim">Sim</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_endereco_correto" id="pendencia_endereco_nao" value="nao">
                                <label class="form-check-label" for="pendencia_endereco_nao">Não</label>
                            </div>
                        </div>

                        <div class="mb-3 d-none" id="grupoModalPendenciaSocio">
                            <label class="form-label d-block">Sócio está correto?</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_socio_correto" id="pendencia_socio_sim" value="sim" checked>
                                <label class="form-check-label" for="pendencia_socio_sim">Sim</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="pendencia_socio_correto" id="pendencia_socio_nao" value="nao">
                                <label class="form-check-label" for="pendencia_socio_nao">Não</label>
                            </div>
                            <div class="form-text">Por enquanto o sistema ainda não cadastra sócios, então isso fica como pendência operacional.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnSalvarModalPendencia">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPendenciaAlvaraDf" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form id="formPendenciaAlvaraDf" novalidate>
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Resolver alvarás DF e DF Legal</h5>
                            <small class="text-muted" id="pendenciaAlvaraCliente"></small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="cliente_id" id="pendenciaAlvaraClienteId">
                        <div class="alert alert-danger d-none" id="alertaPendenciaAlvara"></div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-5">
                                <label for="pendenciaSituacaoAlvara" class="form-label">Situação dos alvarás</label>
                                <select class="form-select" name="alvara" id="pendenciaSituacaoAlvara">
                                    <option value="">Selecione</option>
                                    <option value="possui">Possui</option>
                                    <option value="nao_possui">Não possui</option>
                                    <option value="nao_precisa_momento">Não precisa no momento</option>
                                    <option value="goias">Goiás</option>
                                </select>
                                <div class="invalid-feedback">Informe a situação dos alvarás.</div>
                            </div>

                            <div class="col-md-5">
                                <label for="pendenciaCadastroDfLegal" class="form-label">Cadastro DF Legal</label>
                                <select class="form-select" name="cadastro_df_legal" id="pendenciaCadastroDfLegal">
                                    <option value="">Selecione</option>
                                    <option value="cadastrado">Cadastrado</option>
                                    <option value="nao_cadastrado">Não cadastrado</option>
                                    <option value="nao_precisa_momento">Não precisa no momento</option>
                                    <option value="goias">Goiás</option>
                                </select>
                                <div class="invalid-feedback">Informe a situação do cadastro DF Legal.</div>
                            </div>
                        </div>

                        <div id="grupoPendenciaConferenciaDfLegal" class="border rounded p-3 mb-4 d-none">
                            <h6 class="fw-bold mb-3">Conferência do cadastro DF Legal</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <span class="d-block mb-2">A razão social está correta?</span>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_razao_social_correta" id="pendenciaDfLegalRazaoSim" value="sim" checked>
                                        <label class="form-check-label" for="pendenciaDfLegalRazaoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_razao_social_correta" id="pendenciaDfLegalRazaoNao" value="nao">
                                        <label class="form-check-label" for="pendenciaDfLegalRazaoNao">Não</label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <span class="d-block mb-2">O endereço está correto?</span>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_endereco_correto" id="pendenciaDfLegalEnderecoSim" value="sim" checked>
                                        <label class="form-check-label" for="pendenciaDfLegalEnderecoSim">Sim</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="df_legal_endereco_correto" id="pendenciaDfLegalEnderecoNao" value="nao">
                                        <label class="form-check-label" for="pendenciaDfLegalEnderecoNao">Não</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="grupoPendenciaOrgaosAlvara" class="d-none">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1">Órgãos e vencimentos</h6>
                                    <p class="text-muted small mb-0">Para cada órgão, informe o vencimento, marque como dispensado ou em estudo.</p>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnDispensarTodosPendenciaAlvara">
                                    <i class="bi bi-check2-all"></i> Marcar todos como dispensado
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle tabela-pendencia-alvara mb-0">
                                    <thead>
                                        <tr>
                                            <th>Órgão</th>
                                            <th>Situação</th>
                                            <th>Vencimento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orgaosAlvaraDf as $codigoOrgao => $nomeOrgao): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($nomeOrgao) ?></td>
                                                <td>
                                                    <select
                                                        class="form-select pendencia-orgao-situacao"
                                                        name="alvaras[<?= htmlspecialchars($codigoOrgao) ?>][situacao]"
                                                        data-codigo="<?= htmlspecialchars($codigoOrgao) ?>"
                                                        data-vencimento="pendenciaAlvaraVencimento_<?= htmlspecialchars($codigoOrgao) ?>">
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
                                                        class="form-control pendencia-orgao-vencimento"
                                                        name="alvaras[<?= htmlspecialchars($codigoOrgao) ?>][vencimento]"
                                                        id="pendenciaAlvaraVencimento_<?= htmlspecialchars($codigoOrgao) ?>"
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
                        <button type="submit" class="btn btn-success" id="btnSalvarPendenciaAlvara">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let botaoPendenciaAtual = null;
        let configuracaoPendenciaAtual = null;
        const modalEditarPendenciaEl = document.getElementById('modalEditarPendencia');
        const campoModalPendenciaVencimento = document.getElementById('modalPendenciaVencimento');
        const botaoSalvarModalPendencia = document.getElementById('btnSalvarModalPendencia');
        let salvandoModalPendencia = false;
        let vencimentoModalPendenciaInicial = '';
        const modalPendenciaAlvaraDf = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPendenciaAlvaraDf'));
        const formPendenciaAlvaraDf = document.getElementById('formPendenciaAlvaraDf');
        const campoPendenciaSituacaoAlvara = document.getElementById('pendenciaSituacaoAlvara');
        const campoPendenciaCadastroDfLegal = document.getElementById('pendenciaCadastroDfLegal');
        const grupoPendenciaOrgaosAlvara = document.getElementById('grupoPendenciaOrgaosAlvara');
        const grupoPendenciaConferenciaDfLegal = document.getElementById('grupoPendenciaConferenciaDfLegal');
        const alertaPendenciaAlvara = document.getElementById('alertaPendenciaAlvara');

        function sincronizarCampoDataPendencia(campo) {
            if (window.sincronizarCalendarioCampo) {
                window.sincronizarCalendarioCampo(campo);
            }
        }

        function focarCampoDataPendencia(campo) {
            sincronizarCampoDataPendencia(campo);

            if (window.focarCalendarioCampo) {
                window.focarCalendarioCampo(campo);
                return;
            }

            campo.focus();
        }

        function atualizarVencimentoOrgaoPendencia(campoSituacao, darFoco = false) {
            const campoData = document.getElementById(campoSituacao.dataset.vencimento);
            const possuiVencimento = campoSituacao.value === 'com_vencimento';

            campoData.disabled = !possuiVencimento;
            campoData.classList.remove('is-invalid');
            sincronizarCampoDataPendencia(campoData);

            if (!possuiVencimento) {
                campoData.value = '';
            } else if (darFoco) {
                focarCampoDataPendencia(campoData);
            }
        }

        function atualizarModalPendenciaAlvara() {
            const possui = campoPendenciaSituacaoAlvara.value === 'possui';
            grupoPendenciaOrgaosAlvara.classList.toggle('d-none', !possui);
            grupoPendenciaConferenciaDfLegal.classList.toggle(
                'd-none',
                campoPendenciaCadastroDfLegal.value !== 'cadastrado'
            );

            document.querySelectorAll('.pendencia-orgao-situacao').forEach(function(campo) {
                campo.disabled = !possui;
                const campoData = document.getElementById(campo.dataset.vencimento);
                campoData.disabled = !possui || campo.value !== 'com_vencimento';
                sincronizarCampoDataPendencia(campoData);
            });
        }

        function abrirModalPendenciaAlvara(botao, configuracao) {
            document.getElementById('pendenciaAlvaraClienteId').value = botao.dataset.clienteId;
            document.getElementById('pendenciaAlvaraCliente').textContent = configuracao.cliente || '';
            campoPendenciaSituacaoAlvara.value = configuracao.alvara_atual || '';
            campoPendenciaCadastroDfLegal.value = configuracao.df_legal_atual || '';
            campoPendenciaSituacaoAlvara.classList.remove('is-invalid');
            campoPendenciaCadastroDfLegal.classList.remove('is-invalid');
            document.getElementById('pendenciaDfLegalRazaoSim').checked = true;
            document.getElementById('pendenciaDfLegalEnderecoSim').checked = true;
            alertaPendenciaAlvara.classList.add('d-none');

            const alvaras = configuracao.alvaras || {};

            document.querySelectorAll('.pendencia-orgao-situacao').forEach(function(campo) {
                const alvara = alvaras[campo.dataset.codigo] || {};
                campo.value = alvara.situacao || '';
                campo.classList.remove('is-invalid');

                const campoData = document.getElementById(campo.dataset.vencimento);
                campoData.value = alvara.vencimento || '';
                campoData.classList.remove('is-invalid');
                atualizarVencimentoOrgaoPendencia(campo);
            });

            atualizarModalPendenciaAlvara();
            modalPendenciaAlvaraDf.show();
        }

        function validarModalPendenciaAlvara() {
            let valido = true;
            let primeiroInvalido = null;

            [campoPendenciaSituacaoAlvara, campoPendenciaCadastroDfLegal].forEach(function(campo) {
                const invalido = campo.value === '';
                campo.classList.toggle('is-invalid', invalido);

                if (invalido) {
                    valido = false;
                    primeiroInvalido = primeiroInvalido || campo;
                }
            });

            if (campoPendenciaSituacaoAlvara.value === 'possui') {
                document.querySelectorAll('.pendencia-orgao-situacao').forEach(function(campo) {
                    const campoData = document.getElementById(campo.dataset.vencimento);
                    const situacaoInvalida = campo.value === '';
                    const dataInvalida = campo.value === 'com_vencimento' && campoData.value === '';

                    campo.classList.toggle('is-invalid', situacaoInvalida);
                    campoData.classList.toggle('is-invalid', dataInvalida);
                    sincronizarCampoDataPendencia(campoData);

                    if (situacaoInvalida || dataInvalida) {
                        valido = false;
                        primeiroInvalido = primeiroInvalido || (situacaoInvalida ? campo : campoData);
                    }
                });
            }

            if (!valido) {
                alertaPendenciaAlvara.textContent = 'Preencha os campos obrigatórios destacados em vermelho.';
                alertaPendenciaAlvara.classList.remove('d-none');
                if (primeiroInvalido) {
                    primeiroInvalido.focus();
                }
            }

            return valido;
        }

        const btnDispensarTodosPendenciaAlvara = document.getElementById('btnDispensarTodosPendenciaAlvara');
        if (btnDispensarTodosPendenciaAlvara) {
            btnDispensarTodosPendenciaAlvara.addEventListener('click', function() {
                document.querySelectorAll('.pendencia-orgao-situacao').forEach(function(campo) {
                    campo.value = 'dispensado';
                    campo.classList.remove('is-invalid');
                    atualizarVencimentoOrgaoPendencia(campo);
                });
                alertaPendenciaAlvara.classList.add('d-none');
            });
        }

        const modalResolverPendencia = document.getElementById('modalResolverPendencia');
        if (modalResolverPendencia) {
            modalResolverPendencia.addEventListener('show.bs.modal', function(event) {
                const botao = event.relatedTarget;

                document.getElementById('clienteIdPendenciaResolver').value = botao.dataset.clienteId;
                document.getElementById('tipoPendenciaResolver').value = botao.dataset.tipo;
                document.getElementById('clientePendenciaResolver').textContent = botao.dataset.cliente;
                document.getElementById('descricaoPendenciaResolver').textContent = botao.dataset.descricao;
            });
        }

        function atualizarResumoPendencia(linha) {
            const tipo = linha.dataset.tipo;
            const total = document.getElementById('totalPendenciasNumero');
            total.textContent = Math.max(Number(total.textContent) - 1, 0);

            document.querySelectorAll('.quantidade-resumo').forEach(function(item) {
                if (item.dataset.resumoTipo === tipo) {
                    item.textContent = Math.max(Number(item.textContent) - 1, 0);
                }
            });

            atualizarBarrasResumo();
        }

        function atualizarBarrasResumo() {
            const quantidades = Array.from(document.querySelectorAll('.quantidade-resumo'));
            const limiteGraficoPendencias = <?= $limiteGraficoPendencias ?>;

            quantidades.forEach(function(item) {
                const tipo = item.dataset.resumoTipo;
                const quantidade = Number(item.textContent) || 0;
                const barra = Array.from(document.querySelectorAll('[data-barra-tipo]')).find(function(elemento) {
                    return elemento.dataset.barraTipo === tipo;
                });
                const largura = Math.min(100, Math.round((quantidade / limiteGraficoPendencias) * 100));

                if (barra) {
                    barra.style.width = largura + '%';
                }
            });
        }

        function atualizarTotalPendenciasServidor() {
            return fetch('api_notificacoes.php?_=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store'
                })
                .then(function(resposta) {
                    if (!resposta.ok) {
                        return null;
                    }

                    return resposta.json();
                })
                .then(function(dados) {
                    if (!dados || dados.total === undefined) {
                        return;
                    }

                    const total = document.getElementById('totalPendenciasNumero');
                    if (total) {
                        total.textContent = String(Number(dados.total || 0));
                    }
                })
                .catch(function() {});
        }

        let renderizarPendenciasLista = function() {};

        function removerLinhaPendencia(botao) {
            const linha = botao.closest('.linha-pendencia');
            atualizarResumoPendencia(linha);
            linha.remove();
            renderizarPendenciasLista();
            window.dispatchEvent(new Event('pendencias:atualizar'));
            atualizarTotalPendenciasServidor();

            if (document.querySelectorAll('.linha-pendencia').length === 0) {
                const tbody = document.querySelector('table tbody');
                const linhaVazia = document.createElement('tr');
                linhaVazia.innerHTML = '<td colspan="5" class="text-center text-muted py-4">Nenhuma pendência encontrada.</td>';
                tbody.appendChild(linhaVazia);
            }
        }

        function preencherOpcoesPendencia(opcoes, valorAtual) {
            const select = document.getElementById('modalPendenciaStatus');
            select.innerHTML = '<option value="">Selecione</option>';

            Object.keys(opcoes || {}).forEach(function(valor) {
                const option = document.createElement('option');
                option.value = valor;
                option.textContent = opcoes[valor];
                select.appendChild(option);
            });

            select.value = valorAtual || '';
        }

        function atualizarVencimentoPendencia(darFoco = false) {
            const modo = document.getElementById('modalPendenciaModo').value;
            const campoVencimento = document.getElementById('modalPendenciaCampoVencimento').value;
            const status = document.getElementById('modalPendenciaStatus').value;
            const grupoVencimento = document.getElementById('grupoModalPendenciaVencimento');
            const vencimento = document.getElementById('modalPendenciaVencimento');

            if (modo === 'certificado') {
                const possui = status === 'possui';
                grupoVencimento.classList.toggle('d-none', !possui);
                vencimento.disabled = !possui;

                if (!possui) {
                    vencimento.value = '';
                }

                sincronizarCampoDataPendencia(vencimento);
                return;
            }

            if (campoVencimento === '') {
                grupoVencimento.classList.add('d-none');
                vencimento.value = '';
                sincronizarCampoDataPendencia(vencimento);
                return;
            }

            grupoVencimento.classList.remove('d-none');
            vencimento.disabled = status !== 'possui';
            sincronizarCampoDataPendencia(vencimento);

            if (status === 'possui' && darFoco) {
                focarCampoDataPendencia(vencimento);
            } else if (status !== 'possui') {
                vencimento.value = '';
                vencimento.classList.remove('is-invalid');
                sincronizarCampoDataPendencia(vencimento);
            }
        }

        function vencimentoNaoEstaVencido(vencimento) {
            if (!vencimento) {
                return false;
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));

            return data >= hoje;
        }

        function pendenciaFoiResolvida(modo, status, vencimento, campoVencimento) {
            if (modo === 'certificado') {
                if (status === 'nao_precisa_momento') {
                    return true;
                }

                return status === 'possui' && vencimentoNaoEstaVencido(vencimento);
            }

            if (status === 'nao_precisa_momento') {
                return true;
            }

            if (['nao', 'nao_possui', 'nao_cadastrado', ''].includes(status)) {
                return false;
            }

            if ((configuracaoPendenciaAtual.conferir_dados || false) && (
                    document.querySelector('input[name="pendencia_razao_social_correta"]:checked').value === 'nao' ||
                    document.querySelector('input[name="pendencia_endereco_correto"]:checked').value === 'nao' ||
                    ((configuracaoPendenciaAtual.conferir_socio || false) && document.querySelector('input[name="pendencia_socio_correto"]:checked').value === 'nao')
                )) {
                return false;
            }

            if (campoVencimento !== '' && status === 'possui') {
                return vencimentoNaoEstaVencido(vencimento);
            }

            return true;
        }

        document.querySelectorAll('.btn-editar-pendencia').forEach(function(botao) {
            botao.addEventListener('click', function() {
                botaoPendenciaAtual = this;
                configuracaoPendenciaAtual = JSON.parse(this.dataset.modal);

                const modo = configuracaoPendenciaAtual.modo || '';

                if (modo === 'alvara_df') {
                    abrirModalPendenciaAlvara(this, configuracaoPendenciaAtual);
                    return;
                }

                document.getElementById('tituloModalEditarPendencia').textContent = configuracaoPendenciaAtual.titulo || 'Resolver pendência';
                document.getElementById('modalPendenciaClienteId').value = this.dataset.clienteId;
                document.getElementById('modalPendenciaModo').value = modo;
                document.getElementById('modalPendenciaCampoStatus').value = configuracaoPendenciaAtual.campo_status || '';
                document.getElementById('modalPendenciaCampoVencimento').value = configuracaoPendenciaAtual.campo_vencimento || '';
                document.getElementById('modalPendenciaCliente').value = configuracaoPendenciaAtual.cliente || '';
                vencimentoModalPendenciaInicial = configuracaoPendenciaAtual.vencimento_atual || '';
                campoModalPendenciaVencimento.value = vencimentoModalPendenciaInicial;
                document.getElementById('modalPendenciaStatus').classList.remove('is-invalid');
                campoModalPendenciaVencimento.classList.remove('is-invalid');
                document.getElementById('grupoModalPendenciaConferencia').classList.toggle('d-none', !(configuracaoPendenciaAtual.conferir_dados || false));
                document.getElementById('grupoModalPendenciaSocio').classList.toggle('d-none', !(configuracaoPendenciaAtual.conferir_socio || false));
                document.getElementById('pendencia_razao_social_sim').checked = true;
                document.getElementById('pendencia_endereco_sim').checked = true;
                document.getElementById('pendencia_socio_sim').checked = true;

                if (modo === 'certificado') {
                    document.getElementById('grupoModalPendenciaStatus').classList.remove('d-none');
                    preencherOpcoesPendencia(configuracaoPendenciaAtual.opcoes || {}, configuracaoPendenciaAtual.status_atual || '');
                    document.getElementById('textoAjudaModalPendencia').textContent = '';
                } else {
                    document.getElementById('grupoModalPendenciaStatus').classList.remove('d-none');
                    document.getElementById('textoAjudaModalPendencia').textContent = '';
                    preencherOpcoesPendencia(configuracaoPendenciaAtual.opcoes || {}, configuracaoPendenciaAtual.status_atual || '');
                }

                atualizarVencimentoPendencia();
                bootstrap.Modal.getOrCreateInstance(modalEditarPendenciaEl).show();
            });
        });

        campoPendenciaSituacaoAlvara.addEventListener('change', atualizarModalPendenciaAlvara);
        campoPendenciaCadastroDfLegal.addEventListener('change', atualizarModalPendenciaAlvara);

        document.querySelectorAll('.pendencia-orgao-situacao').forEach(function(campo) {
            campo.addEventListener('change', function() {
                this.classList.remove('is-invalid');
                atualizarVencimentoOrgaoPendencia(this, true);
            });
        });

        formPendenciaAlvaraDf.addEventListener('submit', function(evento) {
            evento.preventDefault();

            if (!validarModalPendenciaAlvara()) {
                return;
            }

            const botaoSalvar = document.getElementById('btnSalvarPendenciaAlvara');
            const textoOriginal = botaoSalvar.innerHTML;
            botaoSalvar.disabled = true;
            botaoSalvar.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Salvando';

            fetch('api_alvaras_df.php', {
                    method: 'POST',
                    body: new FormData(formPendenciaAlvaraDf)
                })
                .then(function(resposta) {
                    return resposta.json();
                })
                .then(function(resposta) {
                    if (!resposta.sucesso) {
                        alertaPendenciaAlvara.textContent = resposta.mensagem;
                        alertaPendenciaAlvara.classList.remove('d-none');
                        return;
                    }

                    window.location.href = 'pendencias.php?resolvido=1';
                })
                .catch(function() {
                    alertaPendenciaAlvara.textContent = 'Não foi possível comunicar com o servidor.';
                    alertaPendenciaAlvara.classList.remove('d-none');
                })
                .finally(function() {
                    botaoSalvar.disabled = false;
                    botaoSalvar.innerHTML = textoOriginal;
                });
        });

        const modalPendenciaStatus = document.getElementById('modalPendenciaStatus');
        if (modalPendenciaStatus) {
            modalPendenciaStatus.addEventListener('change', function() {
                atualizarVencimentoPendencia(true);
            });
        }

        function salvarModalPendencia() {
            if (salvandoModalPendencia) {
                return;
            }

            const modo = document.getElementById('modalPendenciaModo').value;
            const clienteId = document.getElementById('modalPendenciaClienteId').value;
            const status = document.getElementById('modalPendenciaStatus').value;
            const vencimento = campoModalPendenciaVencimento.value;
            const campoStatus = document.getElementById('modalPendenciaCampoStatus').value;
            const campoVencimento = document.getElementById('modalPendenciaCampoVencimento').value;

            document.getElementById('modalPendenciaStatus').classList.remove('is-invalid');
            campoModalPendenciaVencimento.classList.remove('is-invalid');

            if (modo === 'certificado') {
                if (status === '') {
                    document.getElementById('modalPendenciaStatus').classList.add('is-invalid');
                    document.getElementById('modalPendenciaStatus').focus();
                    return;
                }

                if (status === 'possui' && vencimento === '') {
                    const campoData = campoModalPendenciaVencimento;
                    campoData.classList.add('is-invalid');
                    sincronizarCampoDataPendencia(campoData);
                    focarCampoDataPendencia(campoData);
                    return;
                }
            }

            if (modo === 'controle') {
                if (status === '') {
                    document.getElementById('modalPendenciaStatus').classList.add('is-invalid');
                    document.getElementById('modalPendenciaStatus').focus();
                    return;
                }

                if (campoVencimento !== '' && status === 'possui' && vencimento === '') {
                    const campoData = campoModalPendenciaVencimento;
                    campoData.classList.add('is-invalid');
                    sincronizarCampoDataPendencia(campoData);
                    focarCampoDataPendencia(campoData);
                    return;
                }
            }

            salvandoModalPendencia = true;
            botaoSalvarModalPendencia.disabled = true;
            botaoSalvarModalPendencia.innerHTML = 'Salvando...';

            const destino = modo === 'certificado' ? 'api_certificados.php' : 'api_controles.php';
            const dados = new URLSearchParams();
            dados.append('id', clienteId);

            if (modo === 'certificado') {
                dados.append('certificado_status', status);
                dados.append('vencimento_certificado', vencimento);
            } else {
                dados.append('campo_status', campoStatus);
                dados.append('status', status);
                dados.append('campo_vencimento', campoVencimento);
                dados.append('vencimento', vencimento);
                dados.append('razao_social_correta', (configuracaoPendenciaAtual.conferir_dados || false) ? document.querySelector('input[name="pendencia_razao_social_correta"]:checked').value : 'sim');
                dados.append('endereco_correto', (configuracaoPendenciaAtual.conferir_dados || false) ? document.querySelector('input[name="pendencia_endereco_correto"]:checked').value : 'sim');
                dados.append('socio_correto', (configuracaoPendenciaAtual.conferir_socio || false) ? document.querySelector('input[name="pendencia_socio_correto"]:checked').value : 'sim');
            }

            fetch(destino, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: dados.toString()
                })
                .then(response => response.text())
                .then(resp => {
                    if (resp.trim() === 'ok') {
                        vencimentoModalPendenciaInicial = vencimento;

                        if (pendenciaFoiResolvida(modo, status, vencimento, campoVencimento)) {
                            bootstrap.Modal.getInstance(modalEditarPendenciaEl).hide();
                            removerLinhaPendencia(botaoPendenciaAtual);
                        } else {
                            document.getElementById('textoAjudaModalPendencia').textContent = 'Salvo, mas essa informação ainda continua como pendência.';
                        }
                    } else if (resp.trim() === 'vencimento_obrigatorio') {
                        const campoData = campoModalPendenciaVencimento;
                        campoData.classList.add('is-invalid');
                        sincronizarCampoDataPendencia(campoData);
                        focarCampoDataPendencia(campoData);
                    } else if (resp.trim() === 'certificado_status_coluna_ausente') {
                        botaoSalvarModalPendencia.innerHTML = 'Erro';
                        document.getElementById('textoAjudaModalPendencia').textContent = 'O banco ainda não tem a coluna certificado_status. Rode o SQL de atualização antes de usar "Não precisa no momento" em certificados.';
                    } else if (resp.trim() === 'certificado_status_coluna_desatualizada') {
                        botaoSalvarModalPendencia.innerHTML = 'Erro';
                        document.getElementById('textoAjudaModalPendencia').textContent = 'A coluna certificado_status existe, mas ainda não aceita "Não precisa no momento". Rode o SQL de atualização do certificado_status.';
                    } else if (resp.trim() === 'erro_salvar_certificado') {
                        botaoSalvarModalPendencia.innerHTML = 'Erro';
                        document.getElementById('textoAjudaModalPendencia').textContent = 'Não foi possível salvar o certificado. Verifique se o banco está atualizado.';
                    } else {
                        botaoSalvarModalPendencia.innerHTML = 'Erro';
                        document.getElementById('textoAjudaModalPendencia').textContent = resp.trim() || 'Não foi possível salvar essa pendência.';
                    }
                })
                .catch(() => {
                    botaoSalvarModalPendencia.innerHTML = 'Erro';
                    document.getElementById('textoAjudaModalPendencia').textContent = 'Não foi possível comunicar com o servidor.';
                })
                .finally(() => {
                    setTimeout(() => {
                        salvandoModalPendencia = false;
                        botaoSalvarModalPendencia.disabled = false;
                        botaoSalvarModalPendencia.innerHTML = 'Salvar';
                    }, 600);
                });
        }

        botaoSalvarModalPendencia.addEventListener('click', salvarModalPendencia);

        modalEditarPendenciaEl.addEventListener('keydown', function(evento) {
            if (evento.key !== 'Enter') {
                return;
            }

            evento.preventDefault();
            salvarModalPendencia();
        });

        const campoBuscaPendencia = document.getElementById('buscaPendencia');
        const campoTipoPendencia = document.getElementById('filtroTipoPendencia');
        const campoLimitePendencias = document.getElementById('limitePendencias');
        const grupoLimitePendencias = document.getElementById('grupoLimitePendencias');
        const paginacaoPendencias = document.getElementById('paginacaoPendencias');
        const linhaPendenciasFiltradasVazio = document.getElementById('linhaPendenciasFiltradasVazio');
        const linhasPendencia = Array.from(document.querySelectorAll('.linha-pendencia'));
        let pendenciasPorPagina = Number(localStorage.getItem('pendenciasPorPagina') || 15);
        pendenciasPorPagina = [15, 30, 60, 90].includes(pendenciasPorPagina) ? pendenciasPorPagina : 15;
        let pendenciasPaginaAtual = 1;
        let imprimindoPendencias = false;

        if (campoBuscaPendencia && campoTipoPendencia && campoLimitePendencias && grupoLimitePendencias && paginacaoPendencias) {
            campoLimitePendencias.value = String(pendenciasPorPagina);

            function normalizarPendencias(texto) {
                return String(texto || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim();
            }

            function pendenciasFiltradas() {
                const busca = normalizarPendencias(campoBuscaPendencia.value);
                const tipo = campoTipoPendencia.value;

                return linhasPendencia.filter(function(linha) {
                    if (!linha.isConnected) {
                        return false;
                    }

                    const texto = normalizarPendencias(linha.textContent);
                    const tipoLinha = linha.dataset.tipo;
                    return texto.includes(busca) && (tipo === '' || tipoLinha === tipo);
                });
            }

            function adicionarPaginaPendencia(lista, rotulo, pagina, desabilitado, ativo) {
                const item = document.createElement('li');
                item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'page-link';
                botao.textContent = rotulo;
                botao.disabled = desabilitado;
                botao.addEventListener('click', function() {
                    pendenciasPaginaAtual = pagina;
                    renderizarPendencias();
                });

                item.appendChild(botao);
                lista.appendChild(item);
            }

            function renderizarPendencias() {
                const filtradas = pendenciasFiltradas();
                const filtradasSet = new Set(filtradas);

                if (imprimindoPendencias) {
                    linhasPendencia.forEach(function(linha) {
                        linha.classList.toggle('d-none', !filtradasSet.has(linha));
                    });
                    return;
                }

                const totalPaginas = Math.max(1, Math.ceil(filtradas.length / pendenciasPorPagina));

                if (pendenciasPaginaAtual > totalPaginas) {
                    pendenciasPaginaAtual = totalPaginas;
                }

                const inicio = (pendenciasPaginaAtual - 1) * pendenciasPorPagina;
                const visiveis = new Set(filtradas.slice(inicio, inicio + pendenciasPorPagina));

                linhasPendencia.forEach(function(linha) {
                    linha.classList.toggle('d-none', !visiveis.has(linha));
                });

                if (linhaPendenciasFiltradasVazio) {
                    linhaPendenciasFiltradasVazio.classList.toggle('d-none', filtradas.length > 0);
                }
                grupoLimitePendencias.classList.toggle('d-none', filtradas.length <= pendenciasPorPagina);
                paginacaoPendencias.innerHTML = '';
                paginacaoPendencias.classList.toggle('d-none', filtradas.length <= pendenciasPorPagina);

                if (filtradas.length <= pendenciasPorPagina) {
                    return;
                }

                const nav = document.createElement('nav');
                const lista = document.createElement('ul');
                lista.className = 'pagination justify-content-center mb-0';

                adicionarPaginaPendencia(lista, 'Anterior', Math.max(1, pendenciasPaginaAtual - 1), pendenciasPaginaAtual <= 1, false);

                let ultimaPagina = 0;
                for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                    if (pagina !== 1 && pagina !== totalPaginas && Math.abs(pagina - pendenciasPaginaAtual) > 2) {
                        continue;
                    }

                    if (ultimaPagina > 0 && pagina - ultimaPagina > 1) {
                        adicionarPaginaPendencia(lista, '...', pendenciasPaginaAtual, true, false);
                    }

                    adicionarPaginaPendencia(lista, String(pagina), pagina, false, pagina === pendenciasPaginaAtual);
                    ultimaPagina = pagina;
                }

                adicionarPaginaPendencia(lista, 'Próxima', Math.min(totalPaginas, pendenciasPaginaAtual + 1), pendenciasPaginaAtual >= totalPaginas, false);

                nav.appendChild(lista);
                paginacaoPendencias.appendChild(nav);
            }

            renderizarPendenciasLista = renderizarPendencias;

            campoBuscaPendencia.addEventListener('input', function() {
                pendenciasPaginaAtual = 1;
                renderizarPendencias();
            });

            campoTipoPendencia.addEventListener('change', function() {
                pendenciasPaginaAtual = 1;
                renderizarPendencias();
            });

            campoLimitePendencias.addEventListener('change', function() {
                pendenciasPorPagina = Number(this.value);
                localStorage.setItem('pendenciasPorPagina', String(pendenciasPorPagina));
                pendenciasPaginaAtual = 1;
                renderizarPendencias();
            });

            const btnImprimirPendencias = document.getElementById('btnImprimirPendencias');
            if (btnImprimirPendencias) {
                btnImprimirPendencias.addEventListener('click', function() {
                    const filtro = document.getElementById('filtroTipoPendencia');
                    document.getElementById('tipoPendenciaImpressao').textContent =
                        filtro.value === '' ? 'Todos os tipos' : 'Tipo: ' + filtro.value;
                    window.print();
                });
            }

            window.addEventListener('beforeprint', function() {
                imprimindoPendencias = true;
                renderizarPendencias();
            });

            window.addEventListener('afterprint', function() {
                imprimindoPendencias = false;
                renderizarPendencias();
            });

            renderizarPendencias();
        }

        setTimeout(function() {
            document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                alerta.classList.remove('show');
                setTimeout(function() {
                    alerta.remove();
                }, 200);
            });
        }, 4000);
    </script>
</body>

</html>