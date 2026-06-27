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

$stmtClienteExiste = $pdo->prepare("SELECT id FROM clientes WHERE id = ?");
$stmtClienteExiste->execute([$clienteId]);

if (!$stmtClienteExiste->fetchColumn()) {
    responderAlvara(false, 'Cliente não encontrado.');
}

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

if ($situacaoAlvara === 'possui') {
    foreach ($orgaos as $codigo => $nome) {
        $situacao = $alvaras[$codigo]['situacao'] ?? '';
        $vencimento = trim($alvaras[$codigo]['vencimento'] ?? '');

        if (!in_array($situacao, ['com_vencimento', 'dispensado'], true)) {
            responderAlvara(false, 'Informe o vencimento ou a dispensa de todos os órgãos.');
        }

        if ($situacao === 'com_vencimento' && !dataAlvaraValida($vencimento)) {
            responderAlvara(false, 'Informe uma data válida para todos os órgãos com vencimento.');
        }

        $alvarasValidados[$codigo] = [
            'nome' => $nome,
            'situacao' => $situacao,
            'vencimento' => $situacao === 'com_vencimento' ? $vencimento : null,
        ];
    }
}

try {
    $pdo->beginTransaction();

    $camposExtras = [];
    $valoresExtras = [];

    if (tabelaClientesTemColuna($pdo, 'pendencia_alvara_funcionamento')) {
        $camposExtras[] = 'pendencia_alvara_funcionamento = 0';
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
    ");
    $stmtCliente->execute(array_merge(
        [$situacaoAlvara, $cadastroDfLegal],
        $valoresExtras,
        [$clienteId]
    ));

    $pdo->prepare("DELETE FROM cliente_alvaras WHERE cliente_id = ?")->execute([$clienteId]);

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
