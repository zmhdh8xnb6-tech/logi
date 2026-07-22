<?php
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

function responderAlvara(bool $sucesso, string $mensagem, array $dados = []): void
{
    echo json_encode(
        array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $dados),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

exigirLogin();

if (!usuarioPode('alvaras') && !usuarioPode('pendencias')) {
    http_response_code(403);
    responderAlvara(false, 'Você não possui permissão para alterar os alvarás.');
}

function dataAlvaraValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function tabelaClientesTemColuna(PDO $pdo, string $coluna): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM clientes LIKE ?");
    $stmt->execute([$coluna]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAlvara(false, 'Requisição inválida.');
}

$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
$situacaoAlvara = $_POST['alvara'] ?? '';
$cadastroDfLegal = $_POST['cadastro_df_legal'] ?? '';
$razaoSocialCorreta = $_POST['df_legal_razao_social_correta'] ?? 'sim';
$enderecoCorreto = $_POST['df_legal_endereco_correto'] ?? 'sim';
$alvaras = is_array($_POST['alvaras'] ?? null) ? $_POST['alvaras'] : [];

$orgaos = [
    'ibram' => 'INSTITUTO BRASÍLIA AMBIENTAL - IBRAM',
    'cbmdf' => 'CORPO DE BOMBEIROS MILITAR DO DISTRITO FEDERAL - CBMDF',
    'df_legal' => 'SECRETARIA DE ESTADO DE PROTEÇÃO DA ORDEM URBANÍSTICA DO DISTRITO FEDERAL - DF LEGAL',
    'pcdf' => 'POLÍCIA CIVIL DO DISTRITO FEDERAL - PCDF',
    'seagri' => 'SECRETARIA DE ESTADO DE AGRICULTURA, ABASTECIMENTO E DESENVOLVIMENTO RURAL - SEAGRI',
    'seedf' => 'SECRETARIA DE EDUCAÇÃO DO DISTRITO FEDERAL - SEEDF',
    'sudesc' => 'SUBSECRETARIA DO SISTEMA DE DEFESA CIVIL - SUDESC',
    'visadf' => 'VIGILÂNCIA SANITÁRIA DO DISTRITO FEDERAL - VISADF',
];

if (!$clienteId) {
    responderAlvara(false, 'Cliente inválido.');
}

$stmtClienteExiste = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    " . empresaFiltroClienteDireto($pdo) . "
");
$stmtClienteExiste->execute([$clienteId]);
$clienteAntes = $stmtClienteExiste->fetch(PDO::FETCH_ASSOC);

if (!$clienteAntes) {
    responderAlvara(false, 'Cliente não encontrado.');
}

$stmtAlvarasAntes = $pdo->prepare("
    SELECT ca.orgao_codigo, ca.situacao, ca.vencimento
    FROM cliente_alvaras ca
    INNER JOIN clientes c ON c.id = ca.cliente_id
    WHERE ca.cliente_id = ?
    " . empresaFiltroClienteDireto($pdo, 'c') . "
    ORDER BY ca.orgao_codigo
");
$stmtAlvarasAntes->execute([$clienteId]);
$alvarasAntes = $stmtAlvarasAntes->fetchAll(PDO::FETCH_ASSOC);

if (!in_array($situacaoAlvara, ['possui', 'nao_possui', 'goias'], true)) {
    responderAlvara(false, 'Informe a situação do alvará.');
}

if (!in_array($cadastroDfLegal, ['cadastrado', 'nao_cadastrado', 'goias'], true)) {
    responderAlvara(false, 'Informe a situação do cadastro DF Legal.');
}

if (
    !in_array($razaoSocialCorreta, ['sim', 'nao'], true)
    || !in_array($enderecoCorreto, ['sim', 'nao'], true)
) {
    responderAlvara(false, 'Confira os dados do cadastro DF Legal.');
}

$alvarasValidados = [];
$temAlvaraVencido = false;

if ($situacaoAlvara === 'possui') {
    foreach ($orgaos as $codigo => $nome) {
        $situacao = $alvaras[$codigo]['situacao'] ?? '';
        $vencimento = trim($alvaras[$codigo]['vencimento'] ?? '');

        if (!in_array($situacao, ['com_vencimento', 'dispensado', 'em_estudo'], true)) {
            responderAlvara(false, 'Informe o vencimento, a dispensa ou marque como em estudo em todos os órgãos.');
        }

        if ($situacao === 'com_vencimento' && !dataAlvaraValida($vencimento)) {
            responderAlvara(false, 'Informe uma data válida para todos os órgãos com vencimento.');
        }

        $alvarasValidados[$codigo] = [
            'nome' => $nome,
            'situacao' => $situacao,
            'vencimento' => $situacao === 'com_vencimento' ? $vencimento : null,
        ];

        if ($situacao === 'com_vencimento' && $vencimento < date('Y-m-d')) {
            $temAlvaraVencido = true;
        }
    }
}

try {
    $pdo->beginTransaction();

    $camposExtras = [];
    $valoresExtras = [];

    if (tabelaClientesTemColuna($pdo, 'pendencia_alvara_funcionamento')) {
        $camposExtras[] = 'pendencia_alvara_funcionamento = ?';
        $valoresExtras[] = $temAlvaraVencido ? 1 : 0;
    }

    if (tabelaClientesTemColuna($pdo, 'pendencia_df_legal_dados')) {
        $pendenciaDfLegal = $cadastroDfLegal === 'cadastrado'
            && ($razaoSocialCorreta === 'nao' || $enderecoCorreto === 'nao');
        $camposExtras[] = 'pendencia_df_legal_dados = ?';
        $valoresExtras[] = $pendenciaDfLegal ? 1 : 0;
    }

    $sqlExtras = empty($camposExtras) ? '' : ', ' . implode(', ', $camposExtras);
    $stmtCliente = $pdo->prepare("
        UPDATE clientes
        SET alvara = ?, cadastro_df_legal = ?{$sqlExtras}
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtCliente->execute(array_merge(
        [$situacaoAlvara, $cadastroDfLegal],
        $valoresExtras,
        [$clienteId]
    ));

    $stmtDeleteAlvaras = $pdo->prepare("
        DELETE ca
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE ca.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtDeleteAlvaras->execute([$clienteId]);

    if ($situacaoAlvara === 'possui') {
        $stmtAlvara = $pdo->prepare("
            INSERT INTO cliente_alvaras (
                cliente_id,
                orgao_codigo,
                orgao_nome,
                situacao,
                vencimento
            ) VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($alvarasValidados as $codigo => $alvara) {
            $stmtAlvara->execute([
                $clienteId,
                $codigo,
                $alvara['nome'],
                $alvara['situacao'],
                $alvara['vencimento'],
            ]);
        }
    }

    $pdo->commit();

    $stmtClienteDepois = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtClienteDepois->execute([$clienteId]);
    $clienteDepois = $stmtClienteDepois->fetch(PDO::FETCH_ASSOC) ?: [];
    $mudancasCliente = auditoriaMudancas($clienteAntes, $clienteDepois);
    registrarAuditoria(
        $pdo,
        'Alvarás',
        'editar',
        'cliente',
        $clienteId,
        'Alterou os alvarás de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
        [
            'cliente' => $mudancasCliente['antes'],
            'orgaos' => $alvarasAntes,
        ],
        [
            'cliente' => $mudancasCliente['depois'],
            'orgaos' => $alvarasValidados,
        ]
    );

    $vencimentos = array_filter(array_column($alvarasValidados, 'vencimento'));
    sort($vencimentos);
    $proximoVencimento = $vencimentos[0] ?? '';

    responderAlvara(true, 'Alvarás atualizados com sucesso.', [
        'alvara' => $situacaoAlvara,
        'cadastro_df_legal' => $cadastroDfLegal,
        'proximo_vencimento' => $proximoVencimento,
        'alvaras' => $alvarasValidados,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responderAlvara(false, 'Não foi possível atualizar os alvarás.');
}
