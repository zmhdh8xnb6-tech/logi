<?php
define('LOGI_PUBLIC_ACCESS', true);
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

function permutaPublicaAnexoErro(int $status = 404): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Este arquivo não está disponível. Solicite um novo link à FECON LOGISTICA.');
}

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$anexoId = max(0, (int)($_GET['id'] ?? 0));
$compartilhamento = logiTabelaExiste($authPdo, 'permutas_compartilhamentos')
    ? permutasBuscarCompartilhamentoPublico($authPdo, $token)
    : null;
if (!$compartilhamento || $anexoId <= 0) {
    permutaPublicaAnexoErro();
}

$anexo = null;
foreach (($compartilhamento['conteudo']['anexos'] ?? []) as $anexoCompartilhado) {
    if ((int)($anexoCompartilhado['id'] ?? 0) === $anexoId) {
        $anexo = $anexoCompartilhado;
        break;
    }
}
if (!$anexo) {
    permutaPublicaAnexoErro();
}

$caminhoArquivo = permutasAnexoCaminhoAbsoluto((string)($anexo['caminho_arquivo'] ?? ''));
if ($caminhoArquivo === null) {
    permutaPublicaAnexoErro(410);
}

$tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/png'];
$tipoMime = in_array($anexo['tipo_mime'] ?? '', $tiposPermitidos, true)
    ? (string)$anexo['tipo_mime']
    : 'application/octet-stream';
$nomeOriginal = basename(str_replace(["\r", "\n", '"'], '', (string)($anexo['nome_original'] ?? 'relatorio-digitalizado')));
$nomeAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nomeOriginal) ?: 'relatorio-digitalizado';
$tamanhoArquivo = filesize($caminhoArquivo);
if ($tamanhoArquivo === false || $tamanhoArquivo <= 0) {
    permutaPublicaAnexoErro(422);
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

$arquivo = fopen($caminhoArquivo, 'rb');
if ($arquivo === false) {
    permutaPublicaAnexoErro(500);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

$bytesParaEnviar = $fim - $inicio + 1;
header('Content-Type: ' . $tipoMime);
header('Content-Length: ' . $bytesParaEnviar);
header('Content-Disposition: inline; filename="' . $nomeAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nomeOriginal));
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, max-age=0');

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
