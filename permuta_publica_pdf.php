<?php
define('LOGI_PUBLIC_ACCESS', true);
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$compartilhamento = logiTabelaExiste($authPdo, 'permutas_compartilhamentos')
    ? permutasBuscarCompartilhamentoPublico($authPdo, $token)
    : null;
if (!$compartilhamento) {
    http_response_code(404);
    exit('Este link não está disponível. Solicite um novo link à FECON LOGISTICA.');
}

$conteudo = $compartilhamento['conteudo'];
$competencia = $conteudo['competencia'];
$itens = $conteudo['itens'];
$pdf = permutasGerarPdf(
    $competencia,
    $itens,
    trim((string)($conteudo['empresa_nome'] ?? '')) ?: 'FECON LOGISTICA',
    trim((string)($conteudo['usuario_nome'] ?? '')) ?: 'FECON LOGISTICA'
);
$nomeArquivo = permutasNomeArquivo($competencia);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nomeArquivo . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
echo $pdf;
