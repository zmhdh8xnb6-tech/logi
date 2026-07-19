<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'mensagem' => 'Método inválido.']);
    exit;
}

$orgao = trim($_POST['orgao'] ?? '');

if (!array_key_exists($orgao, orgaosParcelamento())) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensagem' => 'Órgão inválido.']);
    exit;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$competenciasPendentes = competenciasParcelamentosPendentesImpressao($pdo, $orgao);
$competenciasParaRegistrar = $competenciasPendentes !== []
    ? $competenciasPendentes
    : [competenciaAtualParcelamentos()];

$registrado = false;

foreach ($competenciasParaRegistrar as $competencia) {
    $registrado = registrarImpressaoParcelamentos($pdo, $orgao, $usuarioId, $competencia) || $registrado;
}

echo json_encode([
    'ok' => $registrado,
    'competencias' => $competenciasParaRegistrar,
    'mensagem' => $registrado
        ? 'Impressão registrada.'
        : 'Tabela de controle ainda não criada.',
]);
