<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

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

header('Location: ' . urlOrgaoParcelamento($parcelamento['orgao']) . '?reativado=1');
exit;
