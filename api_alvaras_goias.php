<?php
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

exigirLogin();

function responderAlvaraGoias(bool $sucesso, string $mensagem, array $dados = []): void
{
    echo json_encode(
        array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $dados),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function dataGoiasValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function moedaGoiasParaFloat(string $valor): float
{
    $normalizado = str_replace('.', '', trim($valor));
    $normalizado = str_replace(',', '.', $normalizado);
    return is_numeric($normalizado) ? (float)$normalizado : 0.0;
}

if (!usuarioPode('alvaras') && !usuarioPode('pendencias')) {
    http_response_code(403);
    responderAlvaraGoias(false, 'Você não possui permissão para alterar alvarás.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAlvaraGoias(false, 'Requisição inválida.');
}

if (!logiTabelaExiste($pdo, 'cliente_alvaras_goias')) {
    responderAlvaraGoias(false, 'Rode o SQL de alvarás Goiás antes de usar este controle.');
}

$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
$orgaosRecebidos = is_array($_POST['orgaos'] ?? null) ? $_POST['orgaos'] : [];
$temEmpresaAlvarasGoias = logiColunaExiste($pdo, 'cliente_alvaras_goias', 'empresa_id');
$orgaos = [
    'bombeiros' => 'Bombeiros',
    'vigilancia' => 'Vigilância',
    'prefeitura' => 'Prefeitura',
];

if (!$clienteId) {
    responderAlvaraGoias(false, 'Cliente inválido.');
}

$stmtCliente = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    " . empresaFiltroClienteDireto($pdo) . "
");
$stmtCliente->execute([$clienteId]);
$clienteAntes = $stmtCliente->fetch(PDO::FETCH_ASSOC);

if (!$clienteAntes) {
    responderAlvaraGoias(false, 'Cliente não encontrado nesta empresa.');
}

$stmtAntes = $pdo->prepare("
    SELECT ag.*
    FROM cliente_alvaras_goias ag
    INNER JOIN clientes c ON c.id = ag.cliente_id
    WHERE ag.cliente_id = ?
    " . empresaFiltroClienteDireto($pdo, 'c') . "
    ORDER BY ag.orgao_codigo
");
$stmtAntes->execute([$clienteId]);
$alvarasAntes = $stmtAntes->fetchAll(PDO::FETCH_ASSOC);
$alvarasValidados = [];

foreach ($orgaos as $codigo => $nome) {
    $dados = is_array($orgaosRecebidos[$codigo] ?? null) ? $orgaosRecebidos[$codigo] : [];
    $situacao = $dados['situacao'] ?? 'nao_informado';
    $vencimento = trim($dados['vencimento'] ?? '');
    $taxa = moedaGoiasParaFloat($dados['taxa'] ?? '0');
    $vistoria = $dados['vistoria_previa'] ?? 'sim';

    if (!in_array($situacao, ['nao_informado', 'com_vencimento', 'dispensado', 'em_estudo'], true)) {
        responderAlvaraGoias(false, 'Situação inválida em ' . $nome . '.');
    }

    if (!in_array($vistoria, ['sim', 'nao', 'dispensada'], true)) {
        responderAlvaraGoias(false, 'Informe a vistoria prévia em ' . $nome . '.');
    }

    if ($situacao === 'com_vencimento' && !dataGoiasValida($vencimento)) {
        responderAlvaraGoias(false, 'Informe o vencimento em ' . $nome . '.');
    }

    $alvarasValidados[$codigo] = [
        'orgao_codigo' => $codigo,
        'orgao_nome' => $nome,
        'situacao' => $situacao,
        'vencimento' => $situacao === 'com_vencimento' ? $vencimento : null,
        'taxa' => $taxa,
        'vistoria_previa' => $vistoria,
    ];
}

try {
    $pdo->beginTransaction();

    $stmtDelete = $pdo->prepare("
        DELETE ag
        FROM cliente_alvaras_goias ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        WHERE ag.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtDelete->execute([$clienteId]);

    $colunaEmpresa = $temEmpresaAlvarasGoias ? "empresa_id,\n            " : '';
    $placeholderEmpresa = $temEmpresaAlvarasGoias ? '?, ' : '';
    $valoresEmpresa = $temEmpresaAlvarasGoias ? [empresaAtivaId($pdo)] : [];

    $stmtInsert = $pdo->prepare("
        INSERT INTO cliente_alvaras_goias (
            {$colunaEmpresa}
            cliente_id,
            orgao_codigo,
            orgao_nome,
            situacao,
            vencimento,
            taxa,
            vistoria_previa
        ) VALUES ({$placeholderEmpresa}?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($alvarasValidados as $alvara) {
        $stmtInsert->execute(array_merge($valoresEmpresa, [
            $clienteId,
            $alvara['orgao_codigo'],
            $alvara['orgao_nome'],
            $alvara['situacao'],
            $alvara['vencimento'],
            $alvara['taxa'],
            $alvara['vistoria_previa'],
        ]));
    }

    $pdo->commit();

    registrarAuditoria(
        $pdo,
        'Alvarás Goiás',
        'editar',
        'cliente',
        $clienteId,
        'Alterou os alvarás Goiás de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
        $alvarasAntes,
        $alvarasValidados
    );

    responderAlvaraGoias(true, 'Alvarás Goiás atualizados com sucesso.', [
        'alvaras' => $alvarasValidados,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responderAlvaraGoias(false, 'Não foi possível salvar os alvarás Goiás.');
}
