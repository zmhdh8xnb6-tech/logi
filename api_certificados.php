<?php
require 'config.php';

$id = $_POST['id'] ?? '';
$vencimento = $_POST['vencimento_certificado'] ?? '';

if ($id == '') {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;

$stmt = $pdo->prepare("
    UPDATE clientes
    SET vencimento_certificado = ?
    WHERE id = ?
");

$ok = $stmt->execute([
    $vencimento,
    $id
]);

echo $ok ? 'ok' : 'erro';
