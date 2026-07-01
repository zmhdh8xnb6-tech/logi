<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parcelamentos.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$parcelamento = buscarParcelamentoPorId($pdo, $id);

if (!$parcelamento) {
    header('Location: parcelamentos.php');
    exit;
}

$stmt = $pdo->prepare("
    UPDATE parcelamentos
    SET cancelado_em = NULL
    WHERE id = ?
");
$stmt->execute([$id]);

registrarAuditoria(
    $pdo,
    'Parcelamentos',
    'reativar',
    'parcelamento',
    $id,
    'Reativou o parcelamento de ' . $parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome'],
    ['cancelado_em' => $parcelamento['cancelado_em'] ?? null],
    ['cancelado_em' => null]
);

header('Location: ' . urlOrgaoParcelamento($parcelamento['orgao']) . '?reativado=1');
exit;
