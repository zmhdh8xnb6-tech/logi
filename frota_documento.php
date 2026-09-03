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
$tamanhoArquivo = filesize($caminhoArquivo);

if ($tamanhoArquivo === false || $tamanhoArquivo <= 0) {
    http_response_code(404);
    exit('O arquivo deste documento está vazio ou indisponível.');
}

$inicio = 0;
$fim = $tamanhoArquivo - 1;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

if ($range !== '') {
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $partes) !== 1) {
        header('Content-Range: bytes */' . $tamanhoArquivo);
        http_response_code(416);
        exit;
    }

    if ($partes[1] === '' && $partes[2] !== '') {
        $quantidadeFinal = (int)$partes[2];
        if ($quantidadeFinal <= 0) {
            header('Content-Range: bytes */' . $tamanhoArquivo);
            http_response_code(416);
            exit;
        }
        $inicio = max(0, $tamanhoArquivo - $quantidadeFinal);
    } else {
        $inicio = (int)$partes[1];
        if ($partes[2] !== '') {
            $fim = min((int)$partes[2], $tamanhoArquivo - 1);
        }
    }

    if ($inicio < 0 || $inicio >= $tamanhoArquivo || $fim < $inicio) {
        header('Content-Range: bytes */' . $tamanhoArquivo);
        http_response_code(416);
        exit;
    }

    http_response_code(206);
    header('Content-Range: bytes ' . $inicio . '-' . $fim . '/' . $tamanhoArquivo);
}

$bytesParaEnviar = $fim - $inicio + 1;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

@ini_set('zlib.output_compression', '0');

header('Content-Type: ' . $tipoMime);
header('Content-Length: ' . $bytesParaEnviar);
header('Content-Disposition: inline; filename="' . $nomeAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nomeOriginal));
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

$arquivo = fopen($caminhoArquivo, 'rb');
if ($arquivo === false) {
    http_response_code(500);
    exit('Não foi possível abrir o documento.');
}

fseek($arquivo, $inicio);
$restante = $bytesParaEnviar;

while ($restante > 0 && !feof($arquivo) && connection_status() === CONNECTION_NORMAL) {
    $bloco = fread($arquivo, min(8192, $restante));
    if ($bloco === false || $bloco === '') {
        break;
    }
    echo $bloco;
    $restante -= strlen($bloco);
    flush();
}

fclose($arquivo);
exit;
