<?php

function frotaToken(): string
{
    if (empty($_SESSION['frota_csrf_token'])) {
        $_SESSION['frota_csrf_token'] = bin2hex(random_bytes(24));
    }

    return (string)$_SESSION['frota_csrf_token'];
}

function frotaTokenValido(mixed $token): bool
{
    return is_string($token)
        && $token !== ''
        && hash_equals(frotaToken(), $token);
}

function frotaRedirecionar(string $mensagem, string $tipo = 'success', string $aba = 'visao-geral', array $parametros = []): void
{
    $abas = ['visao-geral', 'obrigacoes', 'multas'];
    $aba = in_array($aba, $abas, true) ? $aba : 'visao-geral';

    header('Location: frota.php?' . http_build_query(array_merge([
        'aba' => $aba,
        'msg' => $mensagem,
        'tipo' => $tipo,
    ], $parametros)));
    exit;
}

function frotaTexto(string $valor, int $limite): string
{
    $valor = trim(preg_replace('/\s+/', ' ', $valor) ?? '');
    return function_exists('mb_substr')
        ? mb_substr($valor, 0, $limite)
        : substr($valor, 0, $limite);
}

function frotaPlaca(string $placa): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $placa));
}

function frotaPlacaValida(string $placa): bool
{
    return preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa) === 1;
}

function frotaPlacaFormatada(string $placa): string
{
    $placa = frotaPlaca($placa);
    return strlen($placa) === 7 ? substr($placa, 0, 3) . '-' . substr($placa, 3) : $placa;
}

function frotaRenavam(string $renavam): string
{
    return (string)preg_replace('/\D/', '', $renavam);
}

function frotaDataValida(string $data, bool $obrigatoria = true): bool
{
    if ($data === '') {
        return !$obrigatoria;
    }

    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function frotaMultasVencimentosNormalizar(string $json, int $quantidade): array
{
    try {
        $itens = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Os vencimentos das multas estão inválidos. Abra Vencimentos e tente novamente.');
    }
    if (!is_array($itens) || array_values($itens) !== $itens || count($itens) > 9999) {
        throw new InvalidArgumentException('A lista de vencimentos das multas está inválida.');
    }

    $resultado = [];
    foreach ($itens as $item) {
        if (
            !is_array($item) || !is_int($item['numero'] ?? null)
            || !is_string($item['referencia'] ?? null) || !is_string($item['vencimento'] ?? null)
        ) {
            throw new InvalidArgumentException('Revise os dados de cada multa.');
        }
        $numero = $item['numero'];
        if ($numero < 1 || $numero > $quantidade || isset($resultado[$numero])) {
            throw new InvalidArgumentException('A quantidade não corresponde às multas cadastradas. Ajuste a lista em Vencimentos.');
        }
        $referencia = trim($item['referencia']);
        $tamanho = function_exists('mb_strlen') ? mb_strlen($referencia) : strlen($referencia);
        $vencimento = trim($item['vencimento']);
        if ($tamanho > 120) {
            throw new InvalidArgumentException('A identificação da multa deve ter até 120 caracteres.');
        }
        if (
            !frotaDataValida($vencimento, false)
            || ($vencimento !== '' && ($vencimento < '1900-01-01' || $vencimento > '9999-12-31'))
        ) {
            throw new InvalidArgumentException('Informe uma data de vencimento válida para a multa ' . $numero . '.');
        }
        $resultado[$numero] = ['numero' => $numero, 'referencia' => $referencia, 'vencimento' => $vencimento];
    }
    ksort($resultado);
    // Datas desconhecidas continuam em branco; não criamos vencimentos pela quantidade.
    return array_values(array_filter($resultado, static fn(array $item): bool => $item['referencia'] !== '' || $item['vencimento'] !== ''));
}

function frotaValorEntrada(string $valor): float
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0.0;
    }

    $valor = str_replace(['R$', ' '], '', $valor);
    if (str_contains($valor, ',') && str_contains($valor, '.')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (str_contains($valor, ',')) {
        $valor = str_replace(',', '.', $valor);
    }

    return is_numeric($valor) ? round(max(0, (float)$valor), 2) : -1.0;
}

function frotaMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function frotaData(?string $data): string
{
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}

function frotaSituacaoPrazo(array $registro): string
{
    if (($registro['situacao'] ?? '') !== 'pendente') {
        return (string)($registro['situacao'] ?? 'pendente');
    }

    $vencimento = (string)($registro['vencimento'] ?? '');
    if ($vencimento === '') {
        return 'pendente';
    }

    $hoje = date('Y-m-d');
    if ($vencimento < $hoje) {
        return 'vencido';
    }

    if ($vencimento <= date('Y-m-d', strtotime('+30 days'))) {
        return 'proximo';
    }

    return 'pendente';
}

function frotaTipoObrigacao(string $tipo): string
{
    $tipos = [
        'ipva' => 'IPVA',
        'licenciamento' => 'Licenciamento / CRLV',
        'seguro' => 'Seguro',
        'revisao' => 'Revisão',
        'troca_oleo' => 'Troca de óleo',
        'pneus' => 'Pneus',
        'outro' => 'Outro',
    ];

    return $tipos[$tipo] ?? 'Outro';
}

function frotaSituacaoVeiculo(string $situacao): string
{
    $situacoes = [
        'ativo' => 'Ativo',
        'manutencao' => 'Em manutenção',
        'inativo' => 'Inativo',
        'vendido' => 'Vendido',
    ];

    return $situacoes[$situacao] ?? 'Ativo';
}

function frotaArmazenamentoRaiz(bool $criar = false): ?string
{
    $configurado = defined('LOGI_STORAGE_PATH')
        ? (string)constant('LOGI_STORAGE_PATH')
        : (string)(getenv('LOGI_STORAGE_PATH') ?: '');
    $caminho = trim($configurado);

    if ($caminho === '') {
        // Em produção, o projeto fica em public_html e esta pasta fica fora dele.
        $caminho = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logi_storage';
    } elseif (!str_starts_with($caminho, DIRECTORY_SEPARATOR)) {
        $caminho = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $caminho;
    }

    if ($criar && !is_dir($caminho) && !@mkdir($caminho, 0750, true) && !is_dir($caminho)) {
        return null;
    }

    $raiz = realpath($caminho);
    return $raiz !== false && is_dir($raiz)
        ? rtrim($raiz, DIRECTORY_SEPARATOR)
        : null;
}

function frotaDocumentoCaminhoLogico(string $caminho): ?string
{
    $caminho = str_replace('\\', '/', trim($caminho));
    $caminho = preg_replace('#^(?:\./)+#', '', $caminho) ?? $caminho;

    foreach (['/logi_storage/frota/', '/storage/frota/'] as $marcador) {
        $posicao = strripos('/' . ltrim($caminho, '/'), $marcador);
        if ($posicao !== false) {
            $caminho = 'frota/' . substr('/' . ltrim($caminho, '/'), $posicao + strlen($marcador));
            break;
        }
    }

    $caminho = ltrim($caminho, '/');
    if (str_starts_with($caminho, 'storage/')) {
        $caminho = substr($caminho, strlen('storage/'));
    } elseif (str_starts_with($caminho, 'logi_storage/')) {
        $caminho = substr($caminho, strlen('logi_storage/'));
    }

    return preg_match('#^frota/[1-9]\d*/[1-9]\d*/\d{4}/[a-z0-9][a-z0-9._-]{0,127}\.(?:pdf|jpe?g|png)$#i', $caminho) === 1
        ? $caminho
        : null;
}

function frotaDocumentoPrepararDiretorio(int $empresaId, int $veiculoId, int $ano): ?array
{
    if ($empresaId <= 0 || $veiculoId <= 0 || $ano < 1900 || $ano > 9999) {
        return null;
    }

    $raiz = frotaArmazenamentoRaiz(true);
    if ($raiz === null || !is_writable($raiz)) {
        return null;
    }

    $relativo = 'frota/' . $empresaId . '/' . $veiculoId . '/' . $ano;
    $absoluto = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_dir($absoluto) && !@mkdir($absoluto, 0750, true) && !is_dir($absoluto)) {
        return null;
    }

    return ['relativo' => $relativo, 'absoluto' => $absoluto];
}

function frotaDocumentoCaminhoAbsoluto(string $caminho): ?string
{
    $logico = frotaDocumentoCaminhoLogico($caminho);
    if ($logico === null) {
        return null;
    }

    $raizes = [];
    $raizPersistente = frotaArmazenamentoRaiz();
    if ($raizPersistente !== null) {
        $raizes[] = $raizPersistente;
    }

    // Compatibilidade com documentos enviados quando o storage ainda ficava no projeto.
    $raizLegada = realpath(dirname(__DIR__) . '/storage');
    if ($raizLegada !== false && !in_array($raizLegada, $raizes, true)) {
        $raizes[] = rtrim($raizLegada, DIRECTORY_SEPARATOR);
    }

    foreach ($raizes as $raiz) {
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

function frotaMigrarArmazenamentoLegado(): array
{
    $resultado = ['migrados' => 0, 'falhas' => 0];
    $raizDestino = frotaArmazenamentoRaiz(true);
    $raizLegada = realpath(dirname(__DIR__) . '/storage/frota');

    if ($raizDestino === null || $raizLegada === false || !is_dir($raizLegada)) {
        return $resultado;
    }

    $destinoFrota = $raizDestino . DIRECTORY_SEPARATOR . 'frota';
    if (realpath($destinoFrota) === $raizLegada) {
        return $resultado;
    }

    $arquivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raizLegada, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($arquivos as $arquivo) {
        if (!$arquivo->isFile() || in_array($arquivo->getFilename(), ['.gitkeep', '.DS_Store'], true)) {
            continue;
        }

        $sufixo = ltrim(substr($arquivo->getPathname(), strlen($raizLegada)), DIRECTORY_SEPARATOR);
        $logico = 'frota/' . str_replace(DIRECTORY_SEPARATOR, '/', $sufixo);
        if (frotaDocumentoCaminhoLogico($logico) === null) {
            $resultado['falhas']++;
            continue;
        }

        $destino = $raizDestino . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logico);
        $diretorio = dirname($destino);
        if (!is_dir($diretorio) && !@mkdir($diretorio, 0750, true) && !is_dir($diretorio)) {
            $resultado['falhas']++;
            continue;
        }

        if (is_file($destino)) {
            if (filesize($destino) === $arquivo->getSize() && @unlink($arquivo->getPathname())) {
                $resultado['migrados']++;
            } else {
                $resultado['falhas']++;
            }
            continue;
        }

        if (@rename($arquivo->getPathname(), $destino)) {
            $resultado['migrados']++;
            continue;
        }

        if (@copy($arquivo->getPathname(), $destino) && @unlink($arquivo->getPathname())) {
            $resultado['migrados']++;
        } else {
            @unlink($destino);
            $resultado['falhas']++;
        }
    }

    return $resultado;
}
