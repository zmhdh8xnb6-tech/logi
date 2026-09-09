<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function permutasToken(): string
{
    if (empty($_SESSION['permutas_csrf_token'])) {
        $_SESSION['permutas_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['permutas_csrf_token'];
}

function permutasTokenValido(?string $token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['permutas_csrf_token'])
        && hash_equals((string)$_SESSION['permutas_csrf_token'], $token);
}

function permutasEmpresaId(PDO $pdo): int
{
    return max(1, (int)(empresaAtivaId($pdo) ?? 1));
}

function permutasCompetenciaNormalizar(?string $competencia): string
{
    $competencia = trim((string)$competencia);
    if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $competencia) === 1) {
        return $competencia;
    }

    return date('Y-m');
}

function permutasCompetenciaData(string $competencia): string
{
    return permutasCompetenciaNormalizar($competencia) . '-01';
}

function permutasCompetenciaRotulo(string $competencia): string
{
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];
    [$ano, $mes] = array_map('intval', explode('-', permutasCompetenciaNormalizar($competencia)));

    return $meses[$mes] . '/' . $ano;
}

function permutasDataBr(?string $data, bool $comHorario = false): string
{
    if (empty($data)) {
        return '-';
    }

    return date($comHorario ? 'd/m/Y H:i' : 'd/m/Y', strtotime($data));
}

function permutasMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function permutasNumero(string|int|float|null $valor): float
{
    if (is_int($valor) || is_float($valor)) {
        return (float)$valor;
    }

    $normalizado = preg_replace('/[^0-9,.-]/', '', trim((string)$valor)) ?? '';
    if (str_contains($normalizado, ',')) {
        $normalizado = str_replace('.', '', $normalizado);
        $normalizado = str_replace(',', '.', $normalizado);
    }

    return is_numeric($normalizado) ? (float)$normalizado : 0.0;
}

function permutasQuantidadeRotulo(float $quantidade): string
{
    if (abs($quantidade - round($quantidade)) < 0.00001) {
        return (string)(int)round($quantidade);
    }

    return rtrim(rtrim(number_format($quantidade, 2, ',', '.'), '0'), ',');
}

function permutasItensPadrao(): array
{
    return ['Resma', 'Toner HP', 'Toner Brother'];
}

function permutasItensDisponiveis(PDO $pdo): array
{
    $itens = permutasItensPadrao();
    $normalizados = array_map(static fn(string $item): string => mb_strtolower($item), $itens);

    $stmt = $pdo->prepare('SELECT DISTINCT descricao FROM permutas_itens WHERE empresa_id = ? ORDER BY descricao');
    $stmt->execute([permutasEmpresaId($pdo)]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $descricao) {
        $descricao = trim((string)$descricao);
        $normalizada = mb_strtolower($descricao);

        if ($descricao !== '' && !in_array($normalizada, $normalizados, true)) {
            $itens[] = $descricao;
            $normalizados[] = $normalizada;
        }
    }

    return $itens;
}

function permutasStatusRotulo(string $status): string
{
    return match ($status) {
        'enviado' => 'Enviado ao financeiro',
        'confirmado' => 'Confirmado pelo financeiro',
        default => 'Em preenchimento',
    };
}

function permutasStatusClasse(string $status): string
{
    return match ($status) {
        'enviado' => 'bg-primary',
        'confirmado' => 'bg-success',
        default => 'bg-warning text-dark',
    };
}

function permutasBuscarCompetencia(PDO $pdo, string $competencia, bool $criar = false): ?array
{
    $empresaId = permutasEmpresaId($pdo);
    $competenciaData = permutasCompetenciaData($competencia);
    $stmt = $pdo->prepare('
        SELECT *
        FROM permutas_competencias
        WHERE empresa_id = ? AND parceiro = ? AND competencia = ?
        LIMIT 1
    ');
    $stmt->execute([$empresaId, 'DF Cartuchos', $competenciaData]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($registro || !$criar) {
        return $registro ?: null;
    }

    $stmt = $pdo->prepare('
        INSERT INTO permutas_competencias (empresa_id, parceiro, competencia)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$empresaId, 'DF Cartuchos', $competenciaData]);

    return permutasBuscarCompetencia($pdo, $competencia, false);
}

function permutasBuscarItens(PDO $pdo, int $competenciaId): array
{
    if ($competenciaId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT *, (quantidade * valor_unitario) AS valor_total
        FROM permutas_itens
        WHERE competencia_id = ? AND empresa_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$competenciaId, permutasEmpresaId($pdo)]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function permutasBuscarItem(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT i.*
        FROM permutas_itens i
        INNER JOIN permutas_competencias c ON c.id = i.competencia_id
        WHERE i.id = ? AND i.empresa_id = ? AND c.empresa_id = ?
        LIMIT 1
    ');
    $empresaId = permutasEmpresaId($pdo);
    $stmt->execute([$id, $empresaId, $empresaId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function permutasTotal(array $itens): float
{
    return array_reduce(
        $itens,
        static fn(float $total, array $item): float => $total
            + ((float)$item['quantidade'] * (float)$item['valor_unitario']),
        0.0
    );
}

function permutasBuscarCompetenciasRecentes(PDO $pdo, int $limite = 12): array
{
    $limite = max(1, min(36, $limite));
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COUNT(i.id) AS itens_total,
            COALESCE(SUM(i.quantidade * i.valor_unitario), 0) AS valor_total
        FROM permutas_competencias c
        LEFT JOIN permutas_itens i
          ON i.competencia_id = c.id
         AND i.empresa_id = c.empresa_id
        WHERE c.empresa_id = ?
        GROUP BY c.id
        ORDER BY c.competencia DESC
        LIMIT {$limite}
    ");
    $stmt->execute([permutasEmpresaId($pdo)]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function permutasBuscarEnvios(PDO $pdo, int $competenciaId): array
{
    if ($competenciaId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM permutas_envios
        WHERE competencia_id = ? AND empresa_id = ?
        ORDER BY enviado_em DESC, id DESC
    ');
    $stmt->execute([$competenciaId, permutasEmpresaId($pdo)]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function permutasUltimoDestinatario(PDO $pdo): string
{
    $stmt = $pdo->prepare('
        SELECT destinatario
        FROM permutas_envios
        WHERE empresa_id = ?
        ORDER BY enviado_em DESC, id DESC
        LIMIT 1
    ');
    $stmt->execute([permutasEmpresaId($pdo)]);

    return trim((string)($stmt->fetchColumn() ?: ''));
}

function permutasHabilitarMultiplosAnexos(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM information_schema.statistics\n            WHERE table_schema = DATABASE()\n              AND table_name = 'permutas_anexos'\n              AND index_name = 'uk_permutas_anexo_competencia'\n        ");
        $stmt->execute();

        if ((int)$stmt->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE permutas_anexos DROP INDEX uk_permutas_anexo_competencia');
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM information_schema.statistics\n            WHERE table_schema = DATABASE()\n              AND table_name = 'permutas_anexos'\n              AND index_name = 'idx_permutas_anexos_competencia'\n        ");
        $stmt->execute();

        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE permutas_anexos ADD INDEX idx_permutas_anexos_competencia (empresa_id, competencia_id, enviado_em)');
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function permutasBuscarAnexos(PDO $pdo, int $competenciaId): array
{
    if ($competenciaId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM permutas_anexos
        WHERE empresa_id = ? AND competencia_id = ?
        ORDER BY enviado_em DESC, id DESC
    ');
    $stmt->execute([permutasEmpresaId($pdo), $competenciaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function permutasBuscarAnexoPorId(PDO $pdo, int $anexoId, int $competenciaId = 0): ?array
{
    if ($anexoId <= 0) {
        return null;
    }

    $sql = 'SELECT * FROM permutas_anexos WHERE id = ? AND empresa_id = ?';
    $parametros = [$anexoId, permutasEmpresaId($pdo)];
    if ($competenciaId > 0) {
        $sql .= ' AND competencia_id = ?';
        $parametros[] = $competenciaId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function permutasNormalizarArquivosUpload(?array $campo): array
{
    if (!$campo || !array_key_exists('name', $campo)) {
        return [];
    }

    if (!is_array($campo['name'])) {
        return [$campo];
    }

    $arquivos = [];
    foreach (array_keys($campo['name']) as $indice) {
        $arquivos[] = [
            'name' => $campo['name'][$indice] ?? '',
            'type' => $campo['type'][$indice] ?? '',
            'tmp_name' => $campo['tmp_name'][$indice] ?? '',
            'error' => $campo['error'][$indice] ?? UPLOAD_ERR_NO_FILE,
            'size' => $campo['size'][$indice] ?? 0,
        ];
    }

    return $arquivos;
}

function permutasAmbienteLocal(): bool
{
    $servidor = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '')));
    $servidor = preg_replace('/:\d+$/', '', $servidor) ?? $servidor;

    return PHP_SAPI === 'cli-server'
        || in_array($servidor, ['localhost', '127.0.0.1', '::1'], true);
}

function permutasArmazenamentoCandidatos(): array
{
    $configurado = defined('LOGI_STORAGE_PATH')
        ? (string)constant('LOGI_STORAGE_PATH')
        : (string)(getenv('LOGI_STORAGE_PATH') ?: '');
    $caminho = trim($configurado);

    if ($caminho !== '') {
        if (!str_starts_with($caminho, DIRECTORY_SEPARATOR)) {
            $caminho = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $caminho;
        }

        return [$caminho];
    }

    $externo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logi_storage';
    if (!permutasAmbienteLocal()) {
        return [$externo];
    }

    return array_values(array_unique([
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        $externo,
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logi_storage',
    ]));
}

function permutasArmazenamentoRaiz(bool $criar = false): ?string
{
    foreach (permutasArmazenamentoCandidatos() as $caminho) {
        if ($criar && !is_dir($caminho) && !@mkdir($caminho, 0750, true) && !is_dir($caminho)) {
            continue;
        }

        $raiz = realpath($caminho);
        if ($raiz === false || !is_dir($raiz) || ($criar && !is_writable($raiz))) {
            continue;
        }

        return rtrim($raiz, DIRECTORY_SEPARATOR);
    }

    return null;
}

function permutasArmazenamentoRaizesLeitura(): array
{
    $raizes = [];

    foreach (permutasArmazenamentoCandidatos() as $caminho) {
        $raiz = realpath($caminho);
        if ($raiz !== false && is_dir($raiz)) {
            $raizes[] = rtrim($raiz, DIRECTORY_SEPARATOR);
        }
    }

    $raizLegada = realpath(dirname(__DIR__) . '/storage');
    if ($raizLegada !== false) {
        $raizes[] = rtrim($raizLegada, DIRECTORY_SEPARATOR);
    }

    return array_values(array_unique($raizes));
}

function permutasAnexoCaminhoLogico(string $caminho): ?string
{
    $caminho = str_replace('\\', '/', trim($caminho));
    $caminho = preg_replace('#^(?:\./)+#', '', $caminho) ?? $caminho;

    foreach (['/logi_storage/permutas/', '/storage/permutas/'] as $marcador) {
        $posicao = strripos('/' . ltrim($caminho, '/'), $marcador);
        if ($posicao !== false) {
            $caminho = 'permutas/' . substr('/' . ltrim($caminho, '/'), $posicao + strlen($marcador));
            break;
        }
    }

    $caminho = ltrim($caminho, '/');
    if (str_starts_with($caminho, 'storage/')) {
        $caminho = substr($caminho, strlen('storage/'));
    } elseif (str_starts_with($caminho, 'logi_storage/')) {
        $caminho = substr($caminho, strlen('logi_storage/'));
    }

    return preg_match('#^permutas/[1-9]\d*/[1-9]\d*/[a-z0-9][a-z0-9._-]{0,127}\.(?:pdf|jpe?g|png)$#i', $caminho) === 1
        ? $caminho
        : null;
}

function permutasAnexoPrepararDiretorio(int $empresaId, int $competenciaId): ?array
{
    if ($empresaId <= 0 || $competenciaId <= 0) {
        return null;
    }

    $raiz = permutasArmazenamentoRaiz(true);
    if ($raiz === null || !is_writable($raiz)) {
        return null;
    }

    $relativo = 'permutas/' . $empresaId . '/' . $competenciaId;
    $absoluto = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_dir($absoluto) && !@mkdir($absoluto, 0750, true) && !is_dir($absoluto)) {
        return null;
    }

    return ['relativo' => $relativo, 'absoluto' => $absoluto];
}

function permutasAnexoCaminhoAbsoluto(string $caminho): ?string
{
    $logico = permutasAnexoCaminhoLogico($caminho);
    if ($logico === null) {
        return null;
    }

    foreach (permutasArmazenamentoRaizesLeitura() as $raiz) {
        $candidato = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logico));
        if (
            $candidato !== false
            && str_starts_with($candidato, $raiz . DIRECTORY_SEPARATOR)
            && is_file($candidato)
            && is_readable($candidato)
        ) {
            return $candidato;
        }
    }

    return null;
}

function permutasMarcarAlterada(PDO $pdo, int $competenciaId): void
{
    $stmt = $pdo->prepare("
        UPDATE permutas_competencias
        SET status = 'em_preenchimento',
            email_destinatario = NULL,
            assunto_email = NULL,
            enviado_em = NULL,
            enviado_por = NULL,
            confirmado_em = NULL,
            atualizado_em = NOW()
        WHERE id = ? AND empresa_id = ?
    ");
    $stmt->execute([$competenciaId, permutasEmpresaId($pdo)]);
}

function permutasNomeArquivo(array $competencia): string
{
    $data = (string)($competencia['competencia'] ?? date('Y-m-01'));
    return 'permuta-df-cartuchos-' . date('m-Y', strtotime($data)) . '.pdf';
}

function permutasHtmlRelatorio(array $competencia, array $itens, string $empresaNome, string $usuarioNome): string
{
    $competenciaMes = date('Y-m', strtotime((string)$competencia['competencia']));
    $rotulo = permutasCompetenciaRotulo($competenciaMes);
    $total = permutasTotal($itens);
    $linhas = '';

    foreach ($itens as $item) {
        $quantidade = (float)$item['quantidade'];
        $unitario = (float)$item['valor_unitario'];
        $linhas .= '<tr>'
            . '<td class="numero">' . htmlspecialchars(permutasQuantidadeRotulo($quantidade)) . '</td>'
            . '<td>' . htmlspecialchars((string)$item['descricao']) . '</td>'
            . '<td>' . htmlspecialchars((string)($item['destino'] ?: '-')) . '</td>'
            . '<td class="moeda">' . htmlspecialchars(permutasMoeda($unitario)) . '</td>'
            . '<td class="moeda forte">' . htmlspecialchars(permutasMoeda($quantidade * $unitario)) . '</td>'
            . '</tr>';
    }

    return '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><style>
        @page { margin: 28px 32px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #172033; font-size: 11px; }
        .cabecalho { border-bottom: 3px solid #f97316; padding-bottom: 14px; margin-bottom: 22px; }
        .cabecalho h1 { margin: 0 0 5px; font-size: 20px; color: #172033; }
        .cabecalho p { margin: 0; color: #5f6b7a; }
        .meta { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .meta td { padding: 8px 10px; background: #f6f8fb; border: 1px solid #dfe5ec; }
        .meta strong { display: block; margin-bottom: 2px; color: #5f6b7a; font-size: 9px; text-transform: uppercase; }
        table.itens { width: 100%; border-collapse: collapse; }
        table.itens thead { display: table-header-group; }
        table.itens th { padding: 9px 7px; background: #fff4e8; color: #9a4d00; border: 1px solid #f0c999; text-align: left; font-size: 9px; text-transform: uppercase; }
        table.itens td { padding: 8px 7px; border: 1px solid #dfe5ec; vertical-align: top; }
        table.itens tr:nth-child(even) td { background: #fafbfc; }
        .numero { text-align: center; }
        .moeda { text-align: right; white-space: nowrap; }
        .forte { font-weight: bold; }
        .total { margin-top: 16px; padding: 12px 14px; background: #e7f7ec; border: 1px solid #a8ddb8; text-align: right; font-size: 15px; font-weight: bold; color: #146c38; }
        .rodape { margin-top: 20px; color: #7b8794; font-size: 9px; text-align: center; }
    </style></head><body>
        <div class="cabecalho"><h1>Relatório de permuta - DF Cartuchos</h1><p>Itens retirados por permuta e encaminhados ao financeiro</p></div>
        <table class="meta"><tr>
            <td><strong>Empresa</strong>' . htmlspecialchars($empresaNome !== '' ? $empresaNome : 'Logi') . '</td>
            <td><strong>Competência</strong>' . htmlspecialchars($rotulo) . '</td>
            <td><strong>Itens</strong>' . count($itens) . '</td>
        </tr></table>
        <table class="itens"><thead><tr><th>Qtd.</th><th>Descrição</th><th>Destino</th><th>Valor unitário</th><th>Total</th></tr></thead><tbody>'
        . $linhas
        . '</tbody></table>
        <div class="total">Total da competência: ' . htmlspecialchars(permutasMoeda($total)) . '</div>
        <div class="rodape">Gerado pelo Sistema Logi em ' . date('d/m/Y H:i') . ' por ' . htmlspecialchars($usuarioNome ?: 'Usuário') . '</div>
    </body></html>';
}

function permutasGerarPdf(array $competencia, array $itens, string $empresaNome, string $usuarioNome): string
{
    $opcoes = new Options();
    $opcoes->set('defaultFont', 'DejaVu Sans');
    $opcoes->set('isRemoteEnabled', false);
    $opcoes->set('isPhpEnabled', false);

    $pdf = new Dompdf($opcoes);
    $pdf->loadHtml(permutasHtmlRelatorio($competencia, $itens, $empresaNome, $usuarioNome), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();

    return $pdf->output();
}

function permutasCorpoEmail(string $mensagem, array $competencia, float $total): string
{
    $rotulo = permutasCompetenciaRotulo(date('Y-m', strtotime((string)$competencia['competencia'])));

    return '<div style="font-family:Arial,sans-serif;color:#172033;line-height:1.55">'
        . '<p>' . nl2br(htmlspecialchars($mensagem)) . '</p>'
        . '<div style="margin:20px 0;padding:14px 16px;border-left:4px solid #f97316;background:#fff7ed">'
        . '<strong>DF Cartuchos - ' . htmlspecialchars($rotulo) . '</strong><br>'
        . 'Total da competência: <strong>' . htmlspecialchars(permutasMoeda($total)) . '</strong>'
        . '</div><p style="color:#64748b;font-size:12px">O relatório detalhado segue anexado em PDF.</p></div>';
}
