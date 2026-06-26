<?php
require 'config.php';

exigirPermissao('pendencias');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pendencias.php');
    exit;
}

$clienteId = (int)($_POST['cliente_id'] ?? 0);
$tipo = $_POST['tipo'] ?? '';

$colunas = [
    'alvara' => 'pendencia_alvara_funcionamento',
    'certificado' => 'pendencia_certificado_digital',
];

if ($clienteId <= 0 || !isset($colunas[$tipo])) {
    header('Location: pendencias.php');
    exit;
}

$stmt = $pdo->prepare("
    UPDATE clientes
    SET {$colunas[$tipo]} = 0
    WHERE id = ?
");
$stmt->execute([$clienteId]);

header('Location: pendencias.php?resolvido=1');
exit;
