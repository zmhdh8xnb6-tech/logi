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

$stmtCliente = $pdo->prepare("SELECT id, codigo, nome, {$colunas[$tipo]} AS pendencia FROM clientes WHERE id = ?");
$stmtCliente->execute([$clienteId]);
$clienteAntes = $stmtCliente->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    UPDATE clientes
    SET {$colunas[$tipo]} = 0
    WHERE id = ?
");
$stmt->execute([$clienteId]);

if ($clienteAntes) {
    registrarAuditoria(
        $pdo,
        'Pendências',
        'resolver',
        'cliente',
        $clienteId,
        'Marcou como resolvida a pendência de ' . $tipo . ' de ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
        ['pendencia' => $clienteAntes['pendencia']],
        ['pendencia' => 0]
    );
}

header('Location: pendencias.php?resolvido=1');
exit;
