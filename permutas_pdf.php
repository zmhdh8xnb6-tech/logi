<?php
require 'config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

exigirPermissao('outros_servicos');

if (!logiTabelaExiste($pdo, 'permutas_competencias') || !logiTabelaExiste($pdo, 'permutas_itens')) {
    http_response_code(404);
    exit('O controle de permutas ainda não foi instalado.');
}

$competencia = permutasCompetenciaNormalizar($_GET['competencia'] ?? null);
$competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);
$itens = permutasBuscarItens($pdo, (int)($competenciaRegistro['id'] ?? 0));

if (!$competenciaRegistro || $itens === []) {
    http_response_code(404);
    exit('Nenhum item encontrado para esta competência.');
}

$conteudo = permutasGerarPdf(
    $competenciaRegistro,
    $itens,
    empresaAtivaNome($pdo),
    trim((string)($_SESSION['usuario_nome'] ?? ''))
);
$nomeArquivo = permutasNomeArquivo($competenciaRegistro);
$disposicao = ($_GET['baixar'] ?? '') === '1' ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposicao . '; filename="' . $nomeArquivo . '"');
header('Content-Length: ' . strlen($conteudo));
header('X-Content-Type-Options: nosniff');
echo $conteudo;
