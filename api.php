<?php
require 'config.php';

exigirPermissao('clientes');

function validarInscricaoEstadualServidor(string $valor, string $uf): bool
{
    $normalizado = strtoupper(trim($valor));

    if ($normalizado === '' || $normalizado === 'ISENTO') {
        return true;
    }

    $numero = preg_replace('/\D/', '', $normalizado);
    $uf = strtoupper(trim($uf));

    if ($uf === 'DF') {
        if (strlen($numero) !== 13 || !preg_match('/^(07|08)/', $numero)) {
            return false;
        }

        $calcular = function (string $base, array $pesos): int {
            $soma = 0;

            foreach ($pesos as $indice => $peso) {
                $soma += (int)$base[$indice] * $peso;
            }

            $digito = 11 - ($soma % 11);
            return $digito >= 10 ? 0 : $digito;
        };

        $primeiro = $calcular(substr($numero, 0, 11), [4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $segundo = $calcular(substr($numero, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $primeiro === (int)$numero[11] && $segundo === (int)$numero[12];
    }

    if ($uf === 'GO') {
        if (strlen($numero) !== 9 || !preg_match('/^(10|11|15|20)/', $numero)) {
            return false;
        }

        $base = substr($numero, 0, 8);
        $pesos = [9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;

        foreach ($pesos as $indice => $peso) {
            $soma += (int)$base[$indice] * $peso;
        }

        $resto = $soma % 11;
        $digito = 0;

        if ($resto === 1) {
            $faixa = (int)$base;
            $digito = $faixa >= 10103105 && $faixa <= 10119997 ? 1 : 0;
        } elseif ($resto > 1) {
            $digito = 11 - $resto;
        }

        return $digito === (int)$numero[8];
    }

    return strlen($numero) >= 8 && strlen($numero) <= 14;
}

function salvarAlvarasCliente(PDO $pdo, int $clienteId, string $situacaoAlvara, array $dados): void
{
    $orgaos = [
        'ibram' => 'INSTITUTO BRASÍLIA AMBIENTAL - IBRAM',
        'cbmdf' => 'CORPO DE BOMBEIROS MILITAR DO DISTRITO FEDERAL - CBMDF',
        'df_legal' => 'SECRETARIA DE ESTADO DE PROTEÇÃO DA ORDEM URBANÍSTICA DO DISTRITO FEDERAL - DF LEGAL',
        'pcdf' => 'POLÍCIA CIVIL DO DISTRITO FEDERAL - PCDF',
        'seagri' => 'SECRETARIA DE ESTADO DE AGRICULTURA, ABASTECIMENTO E DESENVOLVIMENTO RURAL - SEAGRI',
        'seedf' => 'SECRETARIA DE EDUCAÇÃO DO DISTRITO FEDERAL - SEEDF',
        'sudesc' => 'SUBSECRETARIA DO SISTEMA DE DEFESA CIVIL - SUDESC',
        'visadf' => 'VIGILÂNCIA SANITÁRIA DO DISTRITO FEDERAL - VISADF',
    ];

    $stmtDeleteAlvaras = $pdo->prepare("
        DELETE ca
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE ca.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtDeleteAlvaras->execute([$clienteId]);

    if ($situacaoAlvara !== 'possui') {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO cliente_alvaras (
            cliente_id,
            orgao_codigo,
            orgao_nome,
            situacao,
            vencimento
        ) VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($orgaos as $codigo => $nome) {
        $situacao = $dados[$codigo]['situacao'] ?? '';

        if (!in_array($situacao, ['com_vencimento', 'dispensado', 'em_estudo'], true)) {
            continue;
        }

        $vencimento = $situacao === 'com_vencimento'
            ? ($dados[$codigo]['vencimento'] ?? null)
            : null;

        if ($situacao === 'com_vencimento' && empty($vencimento)) {
            throw new InvalidArgumentException('Vencimento de alvará não informado.');
        }

        $stmt->execute([$clienteId, $codigo, $nome, $situacao, $vencimento]);
    }
}

function moedaAlvaraGoiasParaFloat(string $valor): float
{
    $normalizado = str_replace('.', '', trim($valor));
    $normalizado = str_replace(',', '.', $normalizado);

    return is_numeric($normalizado) ? (float)$normalizado : 0.0;
}

function salvarAlvarasGoiasCliente(PDO $pdo, int $clienteId, bool $usarAlvaraGoias, array $dados): void
{
    if (!logiTabelaExiste($pdo, 'cliente_alvaras_goias')) {
        return;
    }

    $orgaos = [
        'bombeiros' => 'Bombeiros',
        'vigilancia' => 'Vigilância',
        'prefeitura' => 'Prefeitura',
    ];

    $stmtDelete = $pdo->prepare("
        DELETE ag
        FROM cliente_alvaras_goias ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        WHERE ag.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtDelete->execute([$clienteId]);

    if (!$usarAlvaraGoias) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO cliente_alvaras_goias (
            cliente_id,
            orgao_codigo,
            orgao_nome,
            situacao,
            vencimento,
            taxa,
            vistoria_previa
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($orgaos as $codigo => $nome) {
        $situacao = $dados[$codigo]['situacao'] ?? 'nao_informado';

        if (!in_array($situacao, ['nao_informado', 'com_vencimento', 'dispensado', 'em_estudo'], true)) {
            $situacao = 'nao_informado';
        }

        $vencimento = $situacao === 'com_vencimento'
            ? ($dados[$codigo]['vencimento'] ?? null)
            : null;
        $vistoria = $dados[$codigo]['vistoria_previa'] ?? 'sim';

        if (!in_array($vistoria, ['sim', 'nao', 'dispensada'], true)) {
            $vistoria = 'sim';
        }

        $stmt->execute([
            $clienteId,
            $codigo,
            $nome,
            $situacao,
            $vencimento,
            moedaAlvaraGoiasParaFloat((string)($dados[$codigo]['taxa'] ?? '0')),
            $vistoria,
        ]);
    }
}

function clienteTemColuna(PDO $pdo, string $coluna): bool
{
    static $cache = [];

    if (array_key_exists($coluna, $cache)) {
        return $cache[$coluna];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM clientes LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$coluna] = false;
    }

    return $cache[$coluna];
}

function normalizarValorClienteComparacao(?string $valor): string
{
    $valor = trim((string)$valor);
    $valor = preg_replace('/\s+/', ' ', $valor) ?? $valor;
    $valor = strtr($valor, [
        'Á' => 'A',
        'À' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'Ä' => 'A',
        'á' => 'a',
        'à' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'a',
        'É' => 'E',
        'È' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'Í' => 'I',
        'Ì' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'í' => 'i',
        'ì' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'Ó' => 'O',
        'Ò' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ö' => 'O',
        'ó' => 'o',
        'ò' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'Ú' => 'U',
        'Ù' => 'U',
        'Û' => 'U',
        'Ü' => 'U',
        'ú' => 'u',
        'ù' => 'u',
        'û' => 'u',
        'ü' => 'u',
        'Ç' => 'C',
        'ç' => 'c',
    ]);

    if (function_exists('iconv')) {
        $semAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);

        if ($semAcentos !== false) {
            $valor = $semAcentos;
        }
    }

    if (function_exists('mb_strtolower')) {
        $valor = mb_strtolower($valor, 'UTF-8');
    } else {
        $valor = strtolower($valor);
    }

    $valor = preg_replace('/[^a-z0-9]+/i', ' ', $valor) ?? $valor;
    $valor = trim(preg_replace('/\s+/', ' ', $valor) ?? $valor);

    return $valor;
}

function valorClienteMudou(array $clienteAtual, string $campo, ?string $novoValor): bool
{
    $valorAtualNormalizado = normalizarValorClienteComparacao($clienteAtual[$campo] ?? '');
    $novoValorNormalizado = normalizarValorClienteComparacao($novoValor);

    if ($valorAtualNormalizado === '' || $novoValorNormalizado === '') {
        return false;
    }

    return $valorAtualNormalizado !== $novoValorNormalizado;
}

function conferenciaDadosIncorreta(array $dados, string $prefixo, bool $verificarSocio = false): bool
{
    $campos = [
        $prefixo . '_razao_social_correta',
        $prefixo . '_endereco_correto',
    ];

    if ($verificarSocio) {
        $campos[] = $prefixo . '_socio_correto';
    }

    foreach ($campos as $campo) {
        if (($dados[$campo] ?? 'sim') === 'nao') {
            return true;
        }
    }

    return false;
}

function atualizarPendenciaControleDados(PDO $pdo, int $clienteId, string $coluna, bool $pendente): void
{
    if (!clienteTemColuna($pdo, $coluna)) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$coluna} = ?
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmt->execute([$pendente ? 1 : 0, $clienteId]);
}

function normalizarSociosCnpj(array $dados, array $dadosComplementares): array
{
    $sociosOrigem = [];

    if (isset($dados['qsa']) && is_array($dados['qsa'])) {
        $sociosOrigem = $dados['qsa'];
    } elseif (isset($dados['socios']) && is_array($dados['socios'])) {
        $sociosOrigem = $dados['socios'];
    } elseif (isset($dadosComplementares['qsa']) && is_array($dadosComplementares['qsa'])) {
        $sociosOrigem = $dadosComplementares['qsa'];
    } elseif (isset($dadosComplementares['socios']) && is_array($dadosComplementares['socios'])) {
        $sociosOrigem = $dadosComplementares['socios'];
    }

    $socios = [];

    foreach ($sociosOrigem as $socio) {
        if (!is_array($socio)) {
            continue;
        }

        $nome = trim((string)(
            $socio['nome'] ??
            $socio['nome_socio'] ??
            $socio['nome_socio_razao_social'] ??
            ''
        ));

        if ($nome === '') {
            continue;
        }

        $qualificacao = trim((string)(
            $socio['qualificacao_socio']['descricao'] ??
            $socio['qualificacao_socio'] ??
            $socio['qualificacao']['descricao'] ??
            $socio['qualificacao'] ??
            $socio['qual'] ??
            ''
        ));
        $documento = trim((string)(
            $socio['cpf_cnpj_socio'] ??
            $socio['cnpj_cpf_do_socio'] ??
            $socio['cpf'] ??
            $socio['documento'] ??
            ''
        ));
        $entrada = trim((string)(
            $socio['data_entrada_sociedade'] ??
            $socio['data_entrada'] ??
            ''
        ));

        $socios[] = [
            'nome' => $nome,
            'qualificacao' => $qualificacao,
            'documento' => $documento,
            'entrada_sociedade' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $entrada) ? $entrada : null,
        ];
    }

    return $socios;
}

function primeiroValorCnpj(array $dados, array $chaves): string
{
    foreach ($chaves as $chave) {
        if (isset($dados[$chave]) && trim((string)$dados[$chave]) !== '') {
            return trim((string)$dados[$chave]);
        }
    }

    return '';
}

function valorCnpjWsEstabelecimento(array $estabelecimento, array $chaves): string
{
    foreach ($chaves as $chave) {
        if ($chave === 'cidade' && isset($estabelecimento['cidade']) && is_array($estabelecimento['cidade'])) {
            $valor = trim((string)($estabelecimento['cidade']['nome'] ?? ''));
        } elseif ($chave === 'uf' && isset($estabelecimento['estado']) && is_array($estabelecimento['estado'])) {
            $valor = trim((string)($estabelecimento['estado']['sigla'] ?? ''));
        } else {
            $valor = trim((string)($estabelecimento[$chave] ?? ''));
        }

        if ($valor !== '') {
            return $valor;
        }
    }

    return '';
}

function primeiroValorCnpjComEstabelecimento(array $dados, array $estabelecimento, array $chavesDados, array $chavesEstabelecimento = []): string
{
    $valor = primeiroValorCnpj($dados, $chavesDados);

    if ($valor !== '') {
        return $valor;
    }

    return valorCnpjWsEstabelecimento($estabelecimento, $chavesEstabelecimento !== [] ? $chavesEstabelecimento : $chavesDados);
}

function dadosCnpjConsultaOk(array $dados): bool
{
    if ($dados === []) {
        return false;
    }

    if (isset($dados['status']) && strtolower((string)$dados['status']) === 'error') {
        return false;
    }

    return !isset($dados['message'], $dados['errors']);
}

function salvarSociosCliente(PDO $pdo, int $clienteId, array $socios): void
{
    if ($clienteId <= 0 || !logiTabelaExiste($pdo, 'cliente_socios')) {
        return;
    }

    $stmtDelete = $pdo->prepare("
        DELETE cs
        FROM cliente_socios cs
        INNER JOIN clientes c ON c.id = cs.cliente_id
        WHERE cs.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtDelete->execute([$clienteId]);

    if ($socios === []) {
        return;
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO cliente_socios (
            " . empresaInsertColuna($pdo, 'cliente_socios') . "
            cliente_id,
            nome,
            qualificacao,
            documento,
            entrada_sociedade,
            criado_em,
            atualizado_em
        ) VALUES (" . empresaInsertPlaceholder($pdo, 'cliente_socios') . "?, ?, ?, ?, ?, NOW(), NOW())
    ");

    foreach ($socios as $socio) {
        $nome = trim((string)($socio['nome'] ?? ''));

        if ($nome === '') {
            continue;
        }

        $stmtInsert->execute(array_merge(
            empresaInsertValores($pdo, 'cliente_socios'),
            [
                $clienteId,
                $nome,
                trim((string)($socio['qualificacao'] ?? '')),
                trim((string)($socio['documento'] ?? '')),
                !empty($socio['entrada_sociedade']) ? $socio['entrada_sociedade'] : null,
            ]
        ));
    }
}

function consultarJsonExterno(string $url, array $headers = [], int $timeout = 10): array
{
    $resposta = false;
    $headers = array_values(array_filter(array_merge(['Accept: application/json'], $headers)));

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Logi/1.0',
        ]);

        $resposta = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($resposta === false || $status < 200 || $status >= 300) {
            return [];
        }
    } else {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => implode("\r\n", array_merge($headers, ['User-Agent: Logi/1.0'])) . "\r\n",
            ],
        ]);

        $resposta = @file_get_contents($url, false, $contexto);

        if ($resposta === false) {
            return [];
        }
    }

    $dados = json_decode($resposta, true);

    return is_array($dados) ? $dados : [];
}

function consultarCnpjReceitaWsComercial(string $cnpj): array
{
    $token = trim((string)(defined('RECEITAWS_TOKEN') ? RECEITAWS_TOKEN : ''));

    if ($token === '') {
        return [];
    }

    $dias = max(0, (int)(defined('RECEITAWS_DIAS_DEFASAGEM') ? RECEITAWS_DIAS_DEFASAGEM : 1));
    $fallback = (string)(defined('RECEITAWS_FALLBACK') ? RECEITAWS_FALLBACK : 'noCache');
    $fallback = in_array($fallback, ['noCache', 'cacheOnError'], true) ? $fallback : 'noCache';
    $url = 'https://receitaws.com.br/v1/cnpj/' . $cnpj . '/days/' . $dias . '?fallback=' . rawurlencode($fallback);
    $headers = [
        'Authorization: Bearer ' . $token,
        'x-api-token: ' . $token,
    ];

    $dados = consultarJsonExterno($url, $headers, 55);

    if (dadosCnpjConsultaOk($dados)) {
        return $dados;
    }

    $urlComToken = $url . '&token=' . rawurlencode($token);
    $dados = consultarJsonExterno($urlComToken, [], 55);

    return dadosCnpjConsultaOk($dados) ? $dados : [];
}

function consultarCnpjPublico(string $cnpj): array
{
    $dados = consultarJsonExterno('https://brasilapi.com.br/api/cnpj/v1/' . $cnpj);

    return dadosCnpjConsultaOk($dados) ? $dados : [];
}

function consultarCnpjReceitaWsPublica(string $cnpj): array
{
    $dados = consultarJsonExterno('https://receitaws.com.br/v1/cnpj/' . $cnpj);

    return dadosCnpjConsultaOk($dados) ? $dados : [];
}

function consultarCnpjWs(string $cnpj): array
{
    $dados = consultarJsonExterno('https://publica.cnpj.ws/cnpj/' . $cnpj);

    return dadosCnpjConsultaOk($dados) ? $dados : [];
}

$action = $_GET['action'] ?? '';

if ($action === 'read') {

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $ufFiltro = strtoupper(trim((string)($_GET['uf'] ?? '')));

    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;

    $offset = ($page - 1) * $limit;

    $filtroAtivos = clientesFiltroAtivos($pdo);
    $filtroEmpresa = empresaFiltroClienteDireto($pdo);
    $filtros = ["cliente_contabil = 1{$filtroAtivos}{$filtroEmpresa}"];
    $parametros = [];

    if ($busca !== '') {
        $filtros[] = "(
            codigo LIKE ?
            OR documento LIKE ?
            OR nome LIKE ?
            OR nome_fantasia LIKE ?
            OR email LIKE ?
            OR telefone LIKE ?
        )";
        $termoBusca = '%' . $busca . '%';
        array_push($parametros, $termoBusca, $termoBusca, $termoBusca, $termoBusca, $termoBusca, $termoBusca);
    }

    if ($ufFiltro !== '') {
        $filtros[] = 'UPPER(uf) = ?';
        $parametros[] = $ufFiltro;
    }

    $whereSql = implode(' AND ', $filtros);

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE {$whereSql}");
    $stmtTotal->execute($parametros);
    $total = (int)$stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * 
        FROM clientes
        WHERE {$whereSql}
        ORDER BY CAST(codigo AS UNSIGNED) ASC
        LIMIT {$limit} OFFSET {$offset}
    ");

    foreach ($parametros as $indice => $valor) {
        $stmt->bindValue($indice + 1, $valor, PDO::PARAM_STR);
    }
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        "data" => $data,
        "total" => $total,
        "page" => $page,
        "limit" => $limit
    ]);

    exit;
}

if ($action === 'print_clientes') {
    $busca = trim($_GET['busca'] ?? '');
    $ufFiltro = strtoupper(trim($_GET['uf'] ?? ''));
    $filtros = ["cliente_contabil = 1" . clientesFiltroAtivos($pdo) . empresaFiltroClienteDireto($pdo)];
    $parametros = [];

    if ($busca !== '') {
        $filtros[] = "(
            codigo LIKE ?
            OR documento LIKE ?
            OR nome LIKE ?
            OR nome_fantasia LIKE ?
            OR email LIKE ?
        )";
        $termo = '%' . $busca . '%';
        array_push($parametros, $termo, $termo, $termo, $termo, $termo);
    }

    if ($ufFiltro !== '') {
        $filtros[] = 'UPPER(uf) = ?';
        $parametros[] = $ufFiltro;
    }

    $stmt = $pdo->prepare("
        SELECT codigo, documento, nome, nome_fantasia, cidade, uf, telefone, email
        FROM clientes
        WHERE " . implode(' AND ', $filtros) . "
        ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
    ");
    $stmt->execute($parametros);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'check_documento') {
    $documento = $_GET['documento'] ?? '';
    $id = (int)($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT id, codigo, nome, documento
        FROM clientes
        WHERE documento = ?
          AND id <> ?
          " . empresaFiltroClienteDireto($pdo) . "
        LIMIT 1
    ");
    $stmt->execute([$documento, $id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'duplicado' => (bool)$cliente,
        'cliente' => $cliente ?: null,
    ]);
    exit;
}

if ($action === 'consultar_cnpj') {
    $cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');

    header('Content-Type: application/json; charset=utf-8');

    if (strlen($cnpj) !== 14) {
        echo json_encode(['ok' => false, 'mensagem' => 'CNPJ inválido.']);
        exit;
    }

    $dadosReceitaWs = consultarCnpjReceitaWsComercial($cnpj);
    $dadosCnpjWs = $dadosReceitaWs === [] ? consultarCnpjWs($cnpj) : [];
    $dadosReceitaWsPublica = ($dadosReceitaWs === [] && $dadosCnpjWs === []) ? consultarCnpjReceitaWsPublica($cnpj) : [];
    $dadosBrasilApi = ($dadosReceitaWs === [] && $dadosCnpjWs === [] && $dadosReceitaWsPublica === []) ? consultarCnpjPublico($cnpj) : [];

    if ($dadosReceitaWs !== []) {
        $dados = $dadosReceitaWs;
        $dadosComplementares = $dadosCnpjWs !== [] ? $dadosCnpjWs : ($dadosReceitaWsPublica !== [] ? $dadosReceitaWsPublica : $dadosBrasilApi);
        $fonte = 'ReceitaWS Comercial';
    } elseif ($dadosCnpjWs !== []) {
        $dados = $dadosCnpjWs;
        $dadosComplementares = $dadosReceitaWsPublica !== [] ? $dadosReceitaWsPublica : $dadosBrasilApi;
        $fonte = 'CNPJ.ws';
    } elseif ($dadosReceitaWsPublica !== []) {
        $dados = $dadosReceitaWsPublica;
        $dadosComplementares = $dadosBrasilApi;
        $fonte = 'ReceitaWS Pública';
    } else {
        $dados = $dadosBrasilApi;
        $dadosComplementares = [];
        $fonte = 'BrasilAPI';
    }

    if ($dados === []) {
        echo json_encode(['ok' => false, 'mensagem' => 'CNPJ não encontrado.']);
        exit;
    }

    $estabelecimento = is_array($dados['estabelecimento'] ?? null)
        ? $dados['estabelecimento']
        : [];
    $estabelecimentoComplementar = is_array($dadosComplementares['estabelecimento'] ?? null)
        ? $dadosComplementares['estabelecimento']
        : [];

    $email = primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['email']);
    if ($email === '') {
        $email = primeiroValorCnpjComEstabelecimento($dadosComplementares, $estabelecimentoComplementar, ['email']);
    }

    $telefone = primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['ddd_telefone_1', 'telefone'], ['ddd1']);
    if ($telefone === '') {
        $ddd = valorCnpjWsEstabelecimento($estabelecimento, ['ddd1']);
        $numeroTelefone = valorCnpjWsEstabelecimento($estabelecimento, ['telefone1']);
        $telefone = trim($ddd . ' ' . $numeroTelefone);
    }
    if ($telefone === '') {
        $telefone = primeiroValorCnpjComEstabelecimento($dadosComplementares, $estabelecimentoComplementar, ['ddd_telefone_1', 'telefone'], ['ddd1']);
    }

    $tipoLogradouro = primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['descricao_tipo_de_logradouro'], ['tipo_logradouro']);
    $logradouroBase = primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['logradouro']);
    $logradouro = trim(implode(' ', array_filter([$tipoLogradouro, $logradouroBase])));
    if ($logradouro === '') {
        $tipoLogradouro = primeiroValorCnpjComEstabelecimento($dadosComplementares, $estabelecimentoComplementar, ['descricao_tipo_de_logradouro'], ['tipo_logradouro']);
        $logradouroBase = primeiroValorCnpjComEstabelecimento($dadosComplementares, $estabelecimentoComplementar, ['logradouro']);
        $logradouro = trim(implode(' ', array_filter([$tipoLogradouro, $logradouroBase])));
    }

    $cep = preg_replace('/\D/', '', primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['cep']));
    if ($cep === '') {
        $cep = preg_replace('/\D/', '', primeiroValorCnpjComEstabelecimento($dadosComplementares, $estabelecimentoComplementar, ['cep']));
    }

    $ultimaAtualizacao = primeiroValorCnpj($dados, ['ultima_atualizacao']);
    $socios = normalizarSociosCnpj($dados, $dadosComplementares);
    $fonteQsa = (isset($dados['qsa']) && is_array($dados['qsa'])) || (isset($dados['socios']) && is_array($dados['socios']))
        ? $fonte
        : (
            (isset($dadosComplementares['socios']) && is_array($dadosComplementares['socios']))
            ? 'CNPJ.ws'
            : ((isset($dadosComplementares['qsa']) && is_array($dadosComplementares['qsa'])) ? 'ReceitaWS Pública/BrasilAPI' : $fonte)
        );

    echo json_encode([
        'ok' => true,
        'dados' => [
            'documento' => $cnpj,
            'nome' => primeiroValorCnpj($dados, ['razao_social', 'nome']),
            'nome_fantasia' => primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['nome_fantasia', 'fantasia']),
            'email' => $email,
            'telefone' => $telefone,
            'cep' => $cep,
            'endereco' => $logradouro,
            'numero_endereco' => primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['numero']),
            'complemento' => primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['complemento']),
            'bairro' => primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['bairro']),
            'cidade' => primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['municipio'], ['cidade']),
            'uf' => strtoupper(primeiroValorCnpjComEstabelecimento($dados, $estabelecimento, ['uf'], ['uf'])),
            'socios' => $socios,
            'fonte' => $fonte,
            'fonte_qsa' => $fonteQsa,
            'ultima_atualizacao' => $ultimaAtualizacao,
        ],
    ]);
    exit;
}

if ($action === 'create' || $action === 'update') {

    $id = $_POST['id'] ?? '';

    $codigo = $_POST['codigo'] ?? '';
    $cliente_contabil_enviado = $_POST['cliente_contabil'] ?? '';
    $servico_parcelamento = isset($_POST['servico_parcelamento']) && $_POST['servico_parcelamento'] === '1' ? 1 : 0;
    $servico_certificado = isset($_POST['servico_certificado']) && $_POST['servico_certificado'] === '1' ? 1 : 0;
    $documento = $_POST['documento'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $nome_fantasia = $_POST['nome_fantasia'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $numero_endereco = $_POST['numero_endereco'] ?? '';
    $complemento = $_POST['complemento'] ?? '';
    $bairro = $_POST['bairro'] ?? '';
    $cidade = $_POST['cidade'] ?? '';
    $uf = $_POST['uf'] ?? '';
    $cep = $_POST['cep'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $inscricao_estadual = $_POST['inscricao_estadual'] ?? '';
    $nire = $_POST['nire'] ?? '';
    $email = $_POST['email'] ?? '';
    $certificado_status = $_POST['certificado_status'] ?? '';
    $cadastro_df_legal = $_POST['cadastro_df_legal'] ?? '';
    $alvara = $_POST['alvara'] ?? '';
    $contador = $_POST['contador'] ?? '';
    $cadastro_crf = $_POST['cadastro_crf'] ?? '';
    $procuracao_receita_federal = $_POST['procuracao_receita_federal'] ?? '';
    $vencimento_procuracao_receita_federal = !empty($_POST['vencimento_procuracao_receita_federal']) ? $_POST['vencimento_procuracao_receita_federal'] : null;
    $procuracao_conectividade = $_POST['procuracao_conectividade'] ?? '';
    $vencimento_procuracao_conectividade = !empty($_POST['vencimento_procuracao_conectividade']) ? $_POST['vencimento_procuracao_conectividade'] : null;
    $procuracao_empregador_web = $_POST['procuracao_empregador_web'] ?? '';
    $procuracao_fgts = $_POST['procuracao_fgts'] ?? '';
    $vencimento_procuracao_fgts = !empty($_POST['vencimento_procuracao_fgts']) ? $_POST['vencimento_procuracao_fgts'] : null;
    $procuracao_particular = $_POST['procuracao_particular'] ?? '';
    $procuracao_sefaz = $_POST['procuracao_sefaz'] ?? '';
    $contrato_prestacao_servicos = $_POST['contrato_prestacao_servicos'] ?? '';
    $tributacao = $_POST['tributacao'] ?? '';
    $possui_parcelamento = in_array(
        $_POST['possui_parcelamento'] ?? '',
        ['possui', 'nao_possui', 'nao_precisa_momento'],
        true
    ) ? $_POST['possui_parcelamento'] : '';
    $alvaras = is_array($_POST['alvaras'] ?? null) ? $_POST['alvaras'] : [];
    $alvarasGoias = is_array($_POST['alvaras_goias'] ?? null) ? $_POST['alvaras_goias'] : [];
    $qsaJson = trim((string)($_POST['qsa_json'] ?? ''));
    $qsaSocios = [];

    if ($qsaJson !== '') {
        $qsaDecodificado = json_decode($qsaJson, true);
        $qsaSocios = is_array($qsaDecodificado) ? $qsaDecodificado : [];
    }

    $vencimento_certificado = !empty($_POST['vencimento_certificado'])
        ? $_POST['vencimento_certificado']
        : null;

    if (!in_array((string)$cliente_contabil_enviado, ['0', '1'], true)) {
        echo 'cliente_contabil_obrigatorio';
        exit;
    }

    $cliente_contabil = (int)$cliente_contabil_enviado;

    if ($cliente_contabil === 1 && $possui_parcelamento === '') {
        echo 'parcelamento_obrigatorio';
        exit;
    }

    if ($cliente_contabil === 1) {
        $servico_parcelamento = 0;
        $servico_certificado = 1;
    }

    if ($cliente_contabil === 0) {
        $possui_parcelamento = $servico_parcelamento ? 'possui' : 'nao_possui';
    }

    if ($cliente_contabil === 0 && !$servico_parcelamento && !$servico_certificado) {
        echo 'servico_avulso_obrigatorio';
        exit;
    }

    $tipo_atendimento = $cliente_contabil === 1
        ? 'completo'
        : ($servico_parcelamento && !$servico_certificado ? 'somente_parcelamento' : 'servico_avulso');

    if ($cliente_contabil === 0 && !$servico_certificado) {
        $vencimento_certificado = null;
        $certificado_status = '';
    }

    if ($servico_certificado || $cliente_contabil === 1) {
        if (!in_array($certificado_status, ['', 'possui', 'nao_possui', 'nao_precisa_momento'], true)) {
            $certificado_status = '';
        }

        if ($certificado_status === '' && !empty($vencimento_certificado)) {
            $certificado_status = 'possui';
        }

        if ($certificado_status === 'nao_precisa_momento' || $certificado_status === 'nao_possui') {
            $vencimento_certificado = null;
        }
    }

    if ($cliente_contabil === 0) {
        $cadastro_df_legal = '';
        $alvara = '';
        $contador = '';
        $cadastro_crf = '';
        $procuracao_receita_federal = '';
        $vencimento_procuracao_receita_federal = null;
        $procuracao_conectividade = '';
        $vencimento_procuracao_conectividade = null;
        $procuracao_empregador_web = '';
        $procuracao_fgts = '';
        $vencimento_procuracao_fgts = null;
        $procuracao_particular = '';
        $procuracao_sefaz = '';
        $contrato_prestacao_servicos = '';
        $tributacao = '';
        $alvaras = [];
        $alvarasGoias = [];
        $certificado_status = '';
    }

    if (!validarInscricaoEstadualServidor($inscricao_estadual, $uf)) {
        echo 'inscricao_estadual_invalida';
        exit;
    }

    $controlesComVencimento = [
        [$procuracao_receita_federal, $vencimento_procuracao_receita_federal],
        [$procuracao_conectividade, $vencimento_procuracao_conectividade],
        [$procuracao_fgts, $vencimento_procuracao_fgts],
    ];

    if ($cliente_contabil === 1) {
        foreach ($controlesComVencimento as [$situacao, $vencimento]) {
            if ($situacao === 'possui' && empty($vencimento)) {
                echo 'vencimento_procuracao_obrigatorio';
                exit;
            }
        }
    }

    if ($cliente_contabil === 1) {
        foreach (
            [
                $procuracao_receita_federal,
                $procuracao_conectividade,
                $procuracao_fgts,
                $procuracao_particular,
            ] as $situacao
        ) {
            if (!in_array($situacao, ['possui', 'nao_possui', 'nao_precisa_momento'], true)) {
                echo 'procuracoes_incompletas';
                exit;
            }
        }

        if (!in_array($procuracao_empregador_web, ['possui', 'nao_possui', 'nao_tem_funcionario', 'nao_precisa_momento'], true)) {
            echo 'procuracoes_incompletas';
            exit;
        }
    }

    if ($cliente_contabil === 1 && !in_array($procuracao_sefaz, ['possui', 'nao_possui', 'nao_precisa_momento', 'goias'], true)) {
        echo 'procuracoes_incompletas';
        exit;
    }

    if ($cliente_contabil === 1 && !in_array($alvara, ['possui', 'nao_possui', 'nao_precisa_momento', 'goias'], true)) {
        echo 'alvara_obrigatorio';
        exit;
    }

    if ($alvara === 'possui') {
        $codigosOrgaosAlvara = [
            'ibram',
            'cbmdf',
            'df_legal',
            'pcdf',
            'seagri',
            'seedf',
            'sudesc',
            'visadf',
        ];

        foreach ($codigosOrgaosAlvara as $codigoOrgao) {
            $situacao = $alvaras[$codigoOrgao]['situacao'] ?? '';
            $vencimento = $alvaras[$codigoOrgao]['vencimento'] ?? null;

            if (!in_array($situacao, ['com_vencimento', 'dispensado', 'em_estudo'], true)) {
                echo 'alvaras_incompletos';
                exit;
            }

            if ($situacao === 'com_vencimento' && empty($vencimento)) {
                echo 'alvaras_incompletos';
                exit;
            }
        }
    }

    if ($cliente_contabil === 1 && ($alvara === 'goias' || $cadastro_df_legal === 'goias')) {
        foreach (['bombeiros', 'vigilancia', 'prefeitura'] as $codigoOrgaoGoias) {
            $situacaoGoias = $alvarasGoias[$codigoOrgaoGoias]['situacao'] ?? 'nao_informado';
            $vencimentoGoias = $alvarasGoias[$codigoOrgaoGoias]['vencimento'] ?? null;

            if (!in_array($situacaoGoias, ['nao_informado', 'com_vencimento', 'dispensado', 'em_estudo'], true)) {
                echo 'alvaras_goias_incompletos';
                exit;
            }

            if ($situacaoGoias === 'com_vencimento' && empty($vencimentoGoias)) {
                echo 'alvaras_goias_incompletos';
                exit;
            }
        }
    }

    if ($id == '') {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes 
            WHERE documento = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ");

        $stmt->execute([$documento]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes
            WHERE documento = ?
            AND id <> ?
            " . empresaFiltroClienteDireto($pdo) . "
        ");

        $stmt->execute([$documento, $id]);
    }

    if ($stmt->rowCount() > 0) {
        echo "duplicado";
        exit;
    }

    $clienteAntesAuditoria = null;

    try {
        $pdo->beginTransaction();

        if ($id == '') {
            $empresaIdInsert = empresaIdParaInsert($pdo, 'clientes');
            $colunaEmpresaInsert = $empresaIdInsert !== null ? "empresa_id,\n                " : '';
            $marcadorEmpresaInsert = $empresaIdInsert !== null ? "?," : '';
            $valorEmpresaInsert = $empresaIdInsert !== null ? [$empresaIdInsert] : [];

            $stmt = $pdo->prepare("
            INSERT INTO clientes (
                {$colunaEmpresaInsert}
                codigo,
                tipo_atendimento,
                cliente_contabil,
                servico_parcelamento,
                servico_certificado,
                documento,
                nome,
                nome_fantasia,
                endereco,
                numero_endereco,
                complemento,
                bairro,
                cidade,
                uf,
                cep,
                telefone,
                inscricao_estadual,
                nire,
                email,
                vencimento_certificado,
                cadastro_df_legal,
                alvara,
                contador,
                cadastro_crf,
                procuracao_receita_federal,
                vencimento_procuracao_receita_federal,
                procuracao_conectividade,
                vencimento_procuracao_conectividade,
                procuracao_empregador_web,
                procuracao_fgts,
                vencimento_procuracao_fgts,
                procuracao_particular,
                procuracao_sefaz,
                contrato_prestacao_servicos,
                tributacao,
                possui_parcelamento
            )
            VALUES ({$marcadorEmpresaInsert}?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

            $ok = $stmt->execute(array_merge($valorEmpresaInsert, [
                $codigo,
                $tipo_atendimento,
                $cliente_contabil,
                $servico_parcelamento,
                $servico_certificado,
                $documento,
                $nome,
                $nome_fantasia,
                $endereco,
                $numero_endereco,
                $complemento,
                $bairro,
                $cidade,
                $uf,
                $cep,
                $telefone,
                $inscricao_estadual,
                $nire,
                $email,
                $vencimento_certificado,
                $cadastro_df_legal,
                $alvara,
                $contador,
                $cadastro_crf,
                $procuracao_receita_federal,
                $vencimento_procuracao_receita_federal,
                $procuracao_conectividade,
                $vencimento_procuracao_conectividade,
                $procuracao_empregador_web,
                $procuracao_fgts,
                $vencimento_procuracao_fgts,
                $procuracao_particular,
                $procuracao_sefaz,
                $contrato_prestacao_servicos,
                $tributacao,
                $possui_parcelamento
            ]));

            $clienteIdSalvo = (int)$pdo->lastInsertId();
        } else {
            $stmtClienteAtual = $pdo->prepare("
            SELECT *
            FROM clientes
            WHERE id = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ");
            $stmtClienteAtual->execute([$id]);
            $clienteAtual = $stmtClienteAtual->fetch(PDO::FETCH_ASSOC) ?: [];

            if (!$clienteAtual) {
                echo 'Cliente não encontrado nesta empresa.';
                exit;
            }

            $clienteAntesAuditoria = $clienteAtual;
            $eraClienteContabil = (int)($clienteAtual['cliente_contabil'] ?? 1) === 1;
            $eraServicoCertificado = (int)($clienteAtual['servico_certificado'] ?? 1) === 1;

            $mudouRazaoSocial = valorClienteMudou($clienteAtual, 'nome', $nome);
            $mudouUf = valorClienteMudou($clienteAtual, 'uf', $uf);
            $mudouEndereco = false;

            foreach (['endereco', 'numero_endereco', 'complemento', 'bairro', 'cidade', 'uf', 'cep'] as $campoEndereco) {
                $novoValorEndereco = [
                    'endereco' => $endereco,
                    'numero_endereco' => $numero_endereco,
                    'complemento' => $complemento,
                    'bairro' => $bairro,
                    'cidade' => $cidade,
                    'uf' => $uf,
                    'cep' => $cep,
                ][$campoEndereco];

                if (valorClienteMudou($clienteAtual, $campoEndereco, $novoValorEndereco)) {
                    $mudouEndereco = true;
                    break;
                }
            }

            $stmt = $pdo->prepare("
            UPDATE clientes SET
                codigo=?,
                tipo_atendimento=?,
                cliente_contabil=?,
                servico_parcelamento=?,
                servico_certificado=?,
                documento=?,
                nome=?,
                nome_fantasia=?,
                endereco=?,
                numero_endereco=?,
                complemento=?,
                bairro=?,
                cidade=?,
                uf=?,
                cep=?,
                telefone=?,
                inscricao_estadual=?,
                nire=?,
                email=?,
                vencimento_certificado=?,
                cadastro_df_legal=?,
                alvara=?,
                contador=?,
                cadastro_crf=?,
                procuracao_receita_federal=?,
                vencimento_procuracao_receita_federal=?,
                procuracao_conectividade=?,
                vencimento_procuracao_conectividade=?,
                procuracao_empregador_web=?,
                procuracao_fgts=?,
                vencimento_procuracao_fgts=?,
                procuracao_particular=?,
                procuracao_sefaz=?,
                contrato_prestacao_servicos=?,
                tributacao=?,
                possui_parcelamento=?
            WHERE id=?
            " . empresaFiltroClienteDireto($pdo) . "
        ");

            $ok = $stmt->execute([
                $codigo,
                $tipo_atendimento,
                $cliente_contabil,
                $servico_parcelamento,
                $servico_certificado,
                $documento,
                $nome,
                $nome_fantasia,
                $endereco,
                $numero_endereco,
                $complemento,
                $bairro,
                $cidade,
                $uf,
                $cep,
                $telefone,
                $inscricao_estadual,
                $nire,
                $email,
                $vencimento_certificado,
                $cadastro_df_legal,
                $alvara,
                $contador,
                $cadastro_crf,
                $procuracao_receita_federal,
                $vencimento_procuracao_receita_federal,
                $procuracao_conectividade,
                $vencimento_procuracao_conectividade,
                $procuracao_empregador_web,
                $procuracao_fgts,
                $vencimento_procuracao_fgts,
                $procuracao_particular,
                $procuracao_sefaz,
                $contrato_prestacao_servicos,
                $tributacao,
                $possui_parcelamento,
                $id
            ]);

            $clienteIdSalvo = (int)$id;

            $atualizacoesPendencias = [];

            if ($cliente_contabil === 1 && $eraClienteContabil && ($mudouRazaoSocial || $mudouEndereco) && clienteTemColuna($pdo, 'pendencia_alvara_funcionamento')) {
                $atualizacoesPendencias[] = 'pendencia_alvara_funcionamento = 1';
            }

            if ($servico_certificado === 1 && $eraServicoCertificado && ($mudouRazaoSocial || $mudouUf) && clienteTemColuna($pdo, 'pendencia_certificado_digital')) {
                $atualizacoesPendencias[] = 'pendencia_certificado_digital = 1';
            }

            if ($cliente_contabil === 0) {
                if (clienteTemColuna($pdo, 'pendencia_alvara_funcionamento')) {
                    $atualizacoesPendencias[] = 'pendencia_alvara_funcionamento = 0';
                }
            }

            if ($servico_certificado === 0) {
                if (clienteTemColuna($pdo, 'pendencia_certificado_digital')) {
                    $atualizacoesPendencias[] = 'pendencia_certificado_digital = 0';
                }
            }

            if (!empty($atualizacoesPendencias)) {
                $pdo->prepare("
                UPDATE clientes
                SET " . implode(', ', $atualizacoesPendencias) . "
                WHERE id = ?
                " . empresaFiltroClienteDireto($pdo) . "
            ")->execute([$clienteIdSalvo]);
            }
        }

        salvarAlvarasCliente($pdo, $clienteIdSalvo, $alvara, $alvaras);
        salvarAlvarasGoiasCliente($pdo, $clienteIdSalvo, $alvara === 'goias' || $cadastro_df_legal === 'goias', $alvarasGoias);

        if (clienteTemColuna($pdo, 'certificado_status')) {
            $pdo->prepare("
            UPDATE clientes
            SET certificado_status = ?
            WHERE id = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ")->execute([$certificado_status, $clienteIdSalvo]);
        }

        if ($certificado_status === 'nao_precisa_momento' && clienteTemColuna($pdo, 'pendencia_certificado_digital')) {
            $pdo->prepare("
            UPDATE clientes
            SET pendencia_certificado_digital = 0
            WHERE id = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ")->execute([$clienteIdSalvo]);
        }

        if ($qsaJson !== '') {
            salvarSociosCliente($pdo, $clienteIdSalvo, $qsaSocios);
        }

        atualizarPendenciaControleDados(
            $pdo,
            $clienteIdSalvo,
            'pendencia_df_legal_dados',
            $cadastro_df_legal === 'cadastrado' && conferenciaDadosIncorreta($_POST, 'df_legal')
        );

        atualizarPendenciaControleDados(
            $pdo,
            $clienteIdSalvo,
            'pendencia_crf_dados',
            $cadastro_crf === 'cadastrado' && conferenciaDadosIncorreta($_POST, 'crf')
        );

        atualizarPendenciaControleDados(
            $pdo,
            $clienteIdSalvo,
            'pendencia_procuracao_particular_dados',
            $procuracao_particular === 'possui' && conferenciaDadosIncorreta($_POST, 'procuracao_particular', true)
        );

        $pdo->commit();

        $stmtAuditoria = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
        $stmtAuditoria->execute([$clienteIdSalvo]);
        $clienteDepoisAuditoria = $stmtAuditoria->fetch(PDO::FETCH_ASSOC) ?: [];
        $mudancasAuditoria = auditoriaMudancas($clienteAntesAuditoria, $clienteDepoisAuditoria);
        registrarAuditoria(
            $pdo,
            'Clientes',
            $id === '' ? 'criar' : 'editar',
            'cliente',
            $clienteIdSalvo,
            ($id === '' ? 'Cadastrou' : 'Alterou') . ' o cliente ' . $codigo . ' - ' . $nome,
            $id === '' ? null : $mudancasAuditoria['antes'],
            $id === '' ? $clienteDepoisAuditoria : $mudancasAuditoria['depois']
        );

        echo $ok ? 'ok|' . $clienteIdSalvo : 'erro';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo 'erro';
    }
    exit;
}

if ($action === 'delete') {

    $id = $_POST['id'] ?? '';
    $contadorRetirado = ($_POST['contador_retirado'] ?? '') === '1';
    $procuracaoSefazRevogada = ($_POST['procuracao_sefaz_revogada'] ?? '') === '1';

    if (!clientesSituacaoDisponivel($pdo)) {
        echo 'Execute o SQL de clientes devolvidos antes de devolver clientes.';
        exit;
    }

    $stmtAntes = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtAntes->execute([$id]);
    $clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    if (!$clienteAntes) {
        echo 'Cliente não encontrado.';
        exit;
    }

    $exigeContadorRetirado = strtolower((string)($clienteAntes['contador'] ?? '')) === 'sim';
    $exigeProcuracaoSefazRevogada = strtoupper((string)($clienteAntes['uf'] ?? '')) === 'DF'
        && strtolower((string)($clienteAntes['procuracao_sefaz'] ?? '')) === 'possui';

    if ($exigeContadorRetirado && !$contadorRetirado) {
        echo 'Confirme que o contador já foi retirado antes de devolver o cliente.';
        exit;
    }

    if ($exigeProcuracaoSefazRevogada && !$procuracaoSefazRevogada) {
        echo 'Para cliente do DF, confirme que a procuração SEFAZ DF foi revogada antes de devolver.';
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET situacao_cliente = 'devolvido',
            devolvido_em = NOW(),
            motivo_devolucao = NULL
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ");

    $ok = $stmt->execute([$id]);

    if ($ok && $clienteAntes) {
        registrarAuditoria(
            $pdo,
            'Clientes',
            'devolver',
            'cliente',
            $id,
            'Devolveu o cliente ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
            $clienteAntes,
            array_merge($clienteAntes, [
                'situacao_cliente' => 'devolvido',
                'devolvido_em' => date('Y-m-d H:i:s'),
            ])
        );
    }

    echo $ok ? 'ok' : 'erro';
    exit;
}

if ($action === 'reativar_cliente') {
    $id = (int)($_POST['id'] ?? 0);

    if (!clientesSituacaoDisponivel($pdo)) {
        echo 'Execute o SQL de clientes devolvidos antes de reativar clientes.';
        exit;
    }

    $stmtAntes = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtAntes->execute([$id]);
    $clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    if (!$clienteAntes) {
        echo 'Cliente não encontrado.';
        exit;
    }

    if (($clienteAntes['situacao_cliente'] ?? '') === 'baixado') {
        echo 'Cliente baixado não pode ser reativado.';
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET situacao_cliente = 'ativo',
            devolvido_em = NULL,
            motivo_devolucao = NULL
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ");
    $ok = $stmt->execute([$id]);

    if ($ok) {
        registrarAuditoria(
            $pdo,
            'Clientes',
            'reativar',
            'cliente',
            $id,
            'Reativou o cliente ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
            $clienteAntes,
            array_merge($clienteAntes, [
                'situacao_cliente' => 'ativo',
                'devolvido_em' => null,
                'motivo_devolucao' => null,
            ])
        );
    }

    echo $ok ? 'ok' : 'erro';
    exit;
}

if ($action === 'delete_permanente') {
    $id = (int)($_POST['id'] ?? 0);

    $stmtAntes = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtAntes->execute([$id]);
    $clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    if (!$clienteAntes) {
        echo 'Cliente não encontrado.';
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM clientes
            WHERE id = ?
              " . empresaFiltroClienteDireto($pdo) . "
        ");
        $ok = $stmt->execute([$id]);
    } catch (Throwable $e) {
        echo 'Não foi possível excluir definitivamente. Verifique se existem parcelamentos, processos ou vínculos para este cliente.';
        exit;
    }

    if ($ok) {
        registrarAuditoria(
            $pdo,
            'Clientes',
            'excluir_definitivamente',
            'cliente',
            $id,
            'Excluiu definitivamente o cliente ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
            $clienteAntes,
            null
        );
    }

    echo $ok ? 'ok' : 'erro';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'error' => 'Ação inválida'
]);

exit;
