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
    SET liquidado_em = NULL,
        parcelas_atrasadas = GREATEST(COALESCE(parcelas_atrasadas, 0), 1)
    WHERE id = ?
");
$stmt->execute([$id]);

header('Location: ' . urlOrgaoParcelamento($parcelamento['orgao']) . '?voltou_liquidado=1');
exit;
