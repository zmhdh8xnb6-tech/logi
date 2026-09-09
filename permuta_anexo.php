<?php
require 'config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

exigirPermissao('outros_servicos');

$empresaId = permutasEmpresaId($pdo);
$anexoId = max(0, (int)($_GET['id'] ?? 0));

function permutaAnexoErro(string $titulo, string $mensagem, int $status = 404): never
{
    http_response_code($status);
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <?php include __DIR__ . '/includes/head.php'; ?>
        <title><?= htmlspecialchars($titulo) ?> - Logi</title>
    </head>

    <body class="bg-light">
        <main class="min-vh-100 d-flex align-items-center justify-content-center p-4">
            <section class="bg-white border rounded-3 p-4 shadow-sm" style="max-width:560px" role="alert">
                <i class="bi bi-file-earmark-x fs-2 text-danger"></i>
                <h1 class="h5 mt-3"><?= htmlspecialchars($titulo) ?></h1>
                <p class="text-muted"><?= htmlspecialchars($mensagem) ?></p>
                <a href="permutas.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Voltar para Permutas</a>
            </section>
        </main>
    </body>

    </html>
<?php
    exit;
}

if ($anexoId <= 0 || !logiTabelaExiste($pdo, 'permutas_anexos')) {
    permutaAnexoErro('Relatório não encontrado', 'O arquivo solicitado não existe ou já foi removido.');
}

$stmt = $pdo->prepare('
    SELECT a.*, c.competencia
    FROM permutas_anexos a
    INNER JOIN permutas_competencias c
        ON c.id = a.competencia_id AND c.empresa_id = a.empresa_id
    WHERE a.id = ? AND a.empresa_id = ?
    LIMIT 1
');
$stmt->execute([$anexoId, $empresaId]);
$anexo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anexo) {
    permutaAnexoErro('Relatório não encontrado', 'O arquivo não pertence à empresa selecionada ou já foi removido.');
}

$caminhoArquivo = permutasAnexoCaminhoAbsoluto((string)$anexo['caminho_arquivo']);
if ($caminhoArquivo === null) {
    permutaAnexoErro('Arquivo não localizado', 'Anexe novamente o relatório digitalizado desta competência.', 410);
}

$tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/png'];
$tipoMime = in_array($anexo['tipo_mime'], $tiposPermitidos, true)
    ? (string)$anexo['tipo_mime']
    : 'application/octet-stream';
$nomeOriginal = basename(str_replace(["\r", "\n", '"'], '', (string)$anexo['nome_original']));
$nomeAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nomeOriginal) ?: 'relatorio-digitalizado';
$tamanhoArquivo = filesize($caminhoArquivo);

if ($tamanhoArquivo === false || $tamanhoArquivo <= 0) {
    permutaAnexoErro('Arquivo indisponível', 'O relatório está vazio. Anexe o arquivo novamente.', 422);
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
    permutaAnexoErro('Não foi possível abrir o relatório', 'Confira a permissão da pasta logi_storage.', 500);
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
