<?php
require 'config.php';

if (!isset($_SESSION['usuario_id'])) {
    echo 'nao_autorizado';
    exit;
}

$id = $_POST['id'] ?? '';
$campoStatus = $_POST['campo_status'] ?? '';
$status = $_POST['status'] ?? '';
$campoVencimento = $_POST['campo_vencimento'] ?? '';
$vencimento = $_POST['vencimento'] ?? '';

$camposStatusPermitidos = [
    'contador' => ['sim', 'nao'],
    'cadastro_crf' => ['cadastrado', 'nao_cadastrado'],
    'contrato_prestacao_servicos' => ['possui', 'nao_possui'],
    'procuracao_receita_federal' => ['possui', 'nao_possui'],
    'procuracao_conectividade' => ['possui', 'nao_possui'],
    'procuracao_empregador_web' => ['possui', 'nao_possui'],
    'procuracao_fgts' => ['possui', 'nao_possui'],
    'procuracao_particular' => ['possui', 'nao_possui'],
    'procuracao_sefaz' => ['possui', 'nao_possui', 'goias'],
];

$camposVencimentoPermitidos = [
    'vencimento_procuracao_receita_federal',
    'vencimento_procuracao_conectividade',
    'vencimento_procuracao_fgts',
];

$relacaoStatusVencimento = [
    'procuracao_receita_federal' => 'vencimento_procuracao_receita_federal',
    'procuracao_conectividade' => 'vencimento_procuracao_conectividade',
    'procuracao_fgts' => 'vencimento_procuracao_fgts',
];

if ($id === '' || !array_key_exists($campoStatus, $camposStatusPermitidos)) {
    echo 'erro';
    exit;
}

if (!in_array($status, $camposStatusPermitidos[$campoStatus], true)) {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;

if ($campoVencimento !== '') {
    if (!in_array($campoVencimento, $camposVencimentoPermitidos, true)) {
        echo 'erro';
        exit;
    }

    if (($relacaoStatusVencimento[$campoStatus] ?? '') !== $campoVencimento) {
        echo 'erro';
        exit;
    }
}

if ($campoVencimento !== '' && $status !== 'possui') {
    $vencimento = null;
}

if ($campoVencimento !== '' && $status === 'possui' && $vencimento === null) {
    echo 'vencimento_obrigatorio';
    exit;
}

if ($campoVencimento !== '') {
    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$campoStatus} = ?, {$campoVencimento} = ?
        WHERE id = ?
    ");

    $ok = $stmt->execute([
        $status,
        $vencimento,
        $id,
    ]);
} else {
    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$campoStatus} = ?
        WHERE id = ?
    ");

    $ok = $stmt->execute([
        $status,
        $id,
    ]);
}

echo $ok ? 'ok' : 'erro';
