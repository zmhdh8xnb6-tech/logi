<?php
require 'config.php';

exigirPermissao('frota');

$empresaId = max(1, (int)(empresaAtivaId($pdo) ?? 1));
$documentoId = max(0, (int)($_GET['id'] ?? 0));

if ($documentoId <= 0 || !logiTabelaExiste($pdo, 'frota_documentos')) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$stmt = $pdo->prepare("
    SELECT d.nome_original, d.caminho_arquivo, d.tipo_mime, d.tamanho_bytes
    FROM frota_documentos d
    INNER JOIN frota_veiculos v
        ON v.id = d.veiculo_id AND v.empresa_id = d.empresa_id
    WHERE d.id = ? AND d.empresa_id = ?
    LIMIT 1
");
$stmt->execute([$documentoId, $empresaId]);
$documento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$documento) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$raizArmazenamento = realpath(__DIR__ . '/storage/frota');
$caminhoArquivo = realpath(__DIR__ . '/' . ltrim((string)$documento['caminho_arquivo'], '/'));
if (
    $raizArmazenamento === false
    || $caminhoArquivo === false
    || !str_starts_with($caminhoArquivo, $raizArmazenamento . DIRECTORY_SEPARATOR)
    || !is_file($caminhoArquivo)
) {
    http_response_code(404);
    exit('O arquivo deste documento não está disponível.');
}

$tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/png'];
$tipoMime = in_array($documento['tipo_mime'], $tiposPermitidos, true)
    ? (string)$documento['tipo_mime']
    : 'application/octet-stream';
$nomeOriginal = basename(str_replace(["\r", "\n", '"'], '', (string)$documento['nome_original']));
$nomeAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nomeOriginal) ?: 'documento';

header('Content-Type: ' . $tipoMime);
header('Content-Length: ' . filesize($caminhoArquivo));
header('Content-Disposition: inline; filename="' . $nomeAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nomeOriginal));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, no-store, max-age=0');

readfile($caminhoArquivo);
exit;
