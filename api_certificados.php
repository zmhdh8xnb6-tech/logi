<?php
require 'config.php';

exigirPermissao('certificados');

$id = $_POST['id'] ?? '';
$vencimento = $_POST['vencimento_certificado'] ?? '';
$certificadoStatus = $_POST['certificado_status'] ?? '';

if ($id == '') {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;
$certificadoStatusDisponivel = logiColunaExiste($pdo, 'clientes', 'certificado_status');

if ($certificadoStatusDisponivel) {
    if (!in_array($certificadoStatus, ['possui', 'nao_possui', 'nao_precisa_momento'], true)) {
        $certificadoStatus = $vencimento !== null ? 'possui' : 'nao_possui';
    }

    if (in_array($certificadoStatus, ['nao_possui', 'nao_precisa_momento'], true)) {
        $vencimento = null;
    }
}

$stmtAntes = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    " . empresaFiltroClienteDireto($pdo) . "
");
$stmtAntes->execute([$id]);
$clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

if (!$clienteAntes) {
    echo 'Cliente não encontrado nesta empresa.';
    exit;
}

$setCertificadoStatus = $certificadoStatusDisponivel ? ', certificado_status = ?' : '';

$stmt = $pdo->prepare("
    UPDATE clientes
    SET vencimento_certificado = ?,
        servico_certificado = 1
        {$setCertificadoStatus}
    WHERE id = ?
      " . empresaFiltroClienteDireto($pdo) . "
");

$valores = [$vencimento];

if ($certificadoStatusDisponivel) {
    $valores[] = $certificadoStatus;
}

$valores[] = $id;
$ok = $stmt->execute($valores);

if ($ok && $clienteAntes) {
    $clienteDepois = $clienteAntes;
    $clienteDepois['vencimento_certificado'] = $vencimento;
    $clienteDepois['certificado_status'] = $certificadoStatusDisponivel ? $certificadoStatus : ($clienteDepois['certificado_status'] ?? null);
    $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois);
    registrarAuditoria(
        $pdo,
        'Certificados',
        'editar',
        'cliente',
        $id,
        'Alterou o certificado de ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
        $mudancas['antes'],
        $mudancas['depois']
    );
}

echo $ok ? 'ok' : 'erro';
