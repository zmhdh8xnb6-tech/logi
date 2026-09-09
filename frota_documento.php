<?php
require 'config.php';
require_once __DIR__ . '/includes/frota_funcoes.php';

exigirPermissao('frota');

$empresaId = max(1, (int)(empresaAtivaId($pdo) ?? 1));
$documentoId = max(0, (int)($_GET['id'] ?? 0));

function frotaDocumentoExibirErro(string $titulo, string $mensagem, int $status = 404, ?int $ano = null): void
{
    http_response_code($status);
    $retorno = 'frota.php?aba=visao-geral';
    if ($ano !== null) {
        $retorno .= '&ano=' . $ano;
    }
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <?php include __DIR__ . '/includes/head.php'; ?>
        <title><?= htmlspecialchars($titulo) ?> - Logi</title>
        <style>
            body {
                background: #eef3f9;
            }

            .documento-erro {
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 24px;
            }

            .documento-erro__painel {
                width: min(560px, 100%);
                padding: 28px;
                border: 1px solid #dbe3ee;
                border-radius: 8px;
                background: #fff;
                box-shadow: 0 12px 32px rgba(15, 23, 42, .08);
            }

            .documento-erro__icone {
                display: grid;
                place-items: center;
                width: 48px;
                height: 48px;
                margin-bottom: 18px;
                border-radius: 8px;
                color: #dc3545;
                background: #fff0f1;
                font-size: 24px;
            }

            .documento-erro h1 {
                margin: 0 0 8px;
                color: #172238;
                font-size: 1.35rem;
            }

            .documento-erro p {
                margin: 0 0 22px;
                color: #5f6f85;
                line-height: 1.55;
            }
        </style>
    </head>

    <body>
        <main class="documento-erro">
            <section class="documento-erro__painel" role="alert">
                <div class="documento-erro__icone"><i class="bi bi-file-earmark-x"></i></div>
                <h1><?= htmlspecialchars($titulo) ?></h1>
                <p><?= htmlspecialchars($mensagem) ?></p>
                <a class="btn btn-primary" href="<?= htmlspecialchars($retorno) ?>">
                    <i class="bi bi-arrow-left"></i> Voltar para Gestão da Frota
                </a>
            </section>
        </main>
    </body>

    </html>
<?php
    exit;
}

if ($documentoId <= 0 || !logiTabelaExiste($pdo, 'frota_documentos')) {
    frotaDocumentoExibirErro('Documento não encontrado', 'O link deste documento é inválido ou o cadastro já foi removido.');
}

$stmt = $pdo->prepare("
    SELECT d.nome_original, d.caminho_arquivo, d.tipo_mime, d.tamanho_bytes, d.ano
    FROM frota_documentos d
    INNER JOIN frota_veiculos v
        ON v.id = d.veiculo_id AND v.empresa_id = d.empresa_id
    WHERE d.id = ? AND d.empresa_id = ?
    LIMIT 1
");
$stmt->execute([$documentoId, $empresaId]);
$documento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$documento) {
    frotaDocumentoExibirErro('Documento não encontrado', 'O documento não pertence à empresa selecionada ou o cadastro já foi removido.');
}

$caminhoArquivo = frotaDocumentoCaminhoAbsoluto((string)$documento['caminho_arquivo']);
if ($caminhoArquivo === null) {
    frotaDocumentoExibirErro(
        'Arquivo não localizado no servidor',
        'O cadastro do documento existe, mas o PDF não está no armazenamento. Anexe o documento novamente; o sistema agora só confirma o envio depois de validar o arquivo físico.',
        410,
        (int)$documento['ano']
    );
}

$tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/png'];
$tipoMime = in_array($documento['tipo_mime'], $tiposPermitidos, true)
    ? (string)$documento['tipo_mime']
    : 'application/octet-stream';
$nomeOriginal = basename(str_replace(["\r", "\n", '"'], '', (string)$documento['nome_original']));
$nomeAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nomeOriginal) ?: 'documento';
$tamanhoArquivo = filesize($caminhoArquivo);

if ($tamanhoArquivo === false || $tamanhoArquivo <= 0) {
    frotaDocumentoExibirErro(
        'Arquivo vazio ou indisponível',
        'O documento foi encontrado, mas não possui conteúdo válido. Anexe o PDF novamente.',
        422,
        (int)$documento['ano']
    );
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
$arquivo = fopen($caminhoArquivo, 'rb');
if ($arquivo === false) {
    frotaDocumentoExibirErro(
        'Não foi possível abrir o documento',
        'O arquivo existe, mas o servidor não concedeu permissão de leitura. Confira a permissão da pasta logi_storage.',
        500,
        (int)$documento['ano']
    );
}

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
