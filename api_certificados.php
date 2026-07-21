<?php
require 'config.php';

exigirPermissao('certificados');

$id = $_POST['id'] ?? '';
$vencimento = $_POST['vencimento_certificado'] ?? '';

if ($id == '') {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;

$stmtAntes = $pdo->prepare("
    SELECT id, codigo, nome, vencimento_certificado
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

$stmt = $pdo->prepare("
    UPDATE clientes
    SET vencimento_certificado = ?,
        servico_certificado = 1
    WHERE id = ?
      " . empresaFiltroClienteDireto($pdo) . "
");

$ok = $stmt->execute([
    $vencimento,
    $id
]);

if ($ok && $clienteAntes) {
    $clienteDepois = $clienteAntes;
    $clienteDepois['vencimento_certificado'] = $vencimento;
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
