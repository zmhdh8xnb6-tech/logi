<?php
$nivelBufferInicial = ob_get_level();
ob_start();

require 'config.php';
require_once __DIR__ . '/includes/legalizacao_funcoes.php';
require_once __DIR__ . '/includes/legalizacao_pdf.php';

exigirPermissao('legalizacao');

$processoId = (int)($_GET['id'] ?? 0);

if (!legalizacaoTabelasDisponiveis($pdo)) {
    http_response_code(404);
    exit('O controle de legalização ainda não foi instalado.');
}

$processo = $processoId > 0 ? legalizacaoBuscarProcesso($pdo, $processoId) : null;

if (!$processo) {
    http_response_code(404);
    exit('Processo de legalização não encontrado.');
}

legalizacaoGarantirDocumentacaoObrigatoria($pdo, $processo);

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ? " . empresaFiltroClienteDireto($pdo));
$stmt->execute([(int)$processo['cliente_id']]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("SELECT * FROM legalizacao_etapas WHERE processo_id = ? ORDER BY ordem");
$stmt->execute([$processoId]);
$etapas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM legalizacao_checklist WHERE processo_id = ? ORDER BY id");
$stmt->execute([$processoId]);
$checklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM legalizacao_historico WHERE processo_id = ? ORDER BY criado_em DESC, id DESC LIMIT 15");
$stmt->execute([$processoId]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conteudo = legalizacaoGerarPdf(
    $processo,
    $cliente,
    $etapas,
    $checklist,
    $historico,
    empresaAtivaNome($pdo),
    trim((string)($_SESSION['usuario_nome'] ?? ''))
);
$nomeArquivo = legalizacaoNomeArquivoPdf($processo);

while (ob_get_level() > $nivelBufferInicial) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nomeArquivo . '"');
header('Content-Length: ' . strlen($conteudo));
header('X-Content-Type-Options: nosniff');
echo $conteudo;
