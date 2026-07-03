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

    $pdo->prepare("DELETE FROM cliente_alvaras WHERE cliente_id = ?")->execute([$clienteId]);

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

function valorClienteMudou(array $clienteAtual, string $campo, ?string $novoValor): bool
{
    return trim((string)($clienteAtual[$campo] ?? '')) !== trim((string)$novoValor);
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
    ");
    $stmt->execute([$pendente ? 1 : 0, $clienteId]);
}

$action = $_GET['action'] ?? '';

if ($action === 'read') {

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;

    $offset = ($page - 1) * $limit;

    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM clientes WHERE cliente_contabil = 1");
    $total = $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * 
        FROM clientes
        WHERE cliente_contabil = 1
        ORDER BY CAST(codigo AS UNSIGNED) ASC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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
    $filtros = ['cliente_contabil = 1'];
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
        ['possui', 'nao_possui'],
        true
    ) ? $_POST['possui_parcelamento'] : '';
    $alvaras = is_array($_POST['alvaras'] ?? null) ? $_POST['alvaras'] : [];

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

    if ($action === 'create' && $cliente_contabil === 1) {
        $servico_parcelamento = 0;
        $servico_certificado = 0;
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

    if (!$servico_certificado) {
        $vencimento_certificado = null;
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

    $procuracoesObrigatorias = [
        $procuracao_receita_federal,
        $procuracao_conectividade,
        $procuracao_empregador_web,
        $procuracao_fgts,
        $procuracao_particular,
    ];

    if ($cliente_contabil === 1) {
        foreach ($procuracoesObrigatorias as $situacao) {
            if (!in_array($situacao, ['possui', 'nao_possui'], true)) {
                echo 'procuracoes_incompletas';
                exit;
            }
        }
    }

    if ($cliente_contabil === 1 && !in_array($procuracao_sefaz, ['possui', 'nao_possui', 'goias'], true)) {
        echo 'procuracoes_incompletas';
        exit;
    }

    if ($cliente_contabil === 1 && !in_array($alvara, ['possui', 'nao_possui', 'goias'], true)) {
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

    if ($id == '') {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes 
            WHERE documento = ?
        ");

        $stmt->execute([$documento]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes
            WHERE documento = ?
            AND id <> ?
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

            $stmt = $pdo->prepare("
            INSERT INTO clientes (
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
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
                $possui_parcelamento
            ]);

            $clienteIdSalvo = (int)$pdo->lastInsertId();
        } else {
            $stmtClienteAtual = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
            $stmtClienteAtual->execute([$id]);
            $clienteAtual = $stmtClienteAtual->fetch(PDO::FETCH_ASSOC) ?: [];
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
            ")->execute([$clienteIdSalvo]);
            }
        }

        salvarAlvarasCliente($pdo, $clienteIdSalvo, $alvara, $alvaras);

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

        $stmtAuditoria = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
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

    $stmtAntes = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmtAntes->execute([$id]);
    $clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        DELETE FROM clientes
        WHERE id = ?
    ");

    $ok = $stmt->execute([$id]);

    if ($ok && $clienteAntes) {
        registrarAuditoria(
            $pdo,
            'Clientes',
            'excluir',
            'cliente',
            $id,
            'Excluiu o cliente ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
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
