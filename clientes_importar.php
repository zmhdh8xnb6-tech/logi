<?php
require 'config.php';

exigirPermissao('clientes');

const IMPORTACAO_CLIENTES_CHAVE = 'clientes_importacao_preview';

function importarClienteNormalizarCabecalho(string $valor): string
{
    $valor = trim($valor);
    $mapa = [
        'á' => 'a',
        'Á' => 'a',
        'à' => 'a',
        'À' => 'a',
        'â' => 'a',
        'Â' => 'a',
        'ã' => 'a',
        'Ã' => 'a',
        'ä' => 'a',
        'Ä' => 'a',
        'é' => 'e',
        'É' => 'e',
        'ê' => 'e',
        'Ê' => 'e',
        'ë' => 'e',
        'Ë' => 'e',
        'í' => 'i',
        'Í' => 'i',
        'î' => 'i',
        'Î' => 'i',
        'ï' => 'i',
        'Ï' => 'i',
        'ó' => 'o',
        'Ó' => 'o',
        'ô' => 'o',
        'Ô' => 'o',
        'õ' => 'o',
        'Õ' => 'o',
        'ö' => 'o',
        'Ö' => 'o',
        'ú' => 'u',
        'Ú' => 'u',
        'û' => 'u',
        'Û' => 'u',
        'ü' => 'u',
        'Ü' => 'u',
        'ç' => 'c',
        'Ç' => 'c',
    ];
    $valor = strtr($valor, $mapa);
    $valor = strtolower($valor);
    $valor = preg_replace('/[^a-z0-9]+/', '_', $valor) ?? $valor;

    return trim($valor, '_');
}

function importarClienteValor(array $linha, array $apelidos): string
{
    foreach ($apelidos as $apelido) {
        if (array_key_exists($apelido, $linha)) {
            return trim((string)$linha[$apelido]);
        }
    }

    return '';
}

function importarClienteData(?string $valor): ?string
{
    $valor = trim((string)$valor);

    if ($valor === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return $valor;
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $partes)) {
        return $partes[3] . '-' . $partes[2] . '-' . $partes[1];
    }

    return null;
}

function importarClienteOpcao(string $valor, array $permitidos, string $padrao = ''): string
{
    $normalizado = importarClienteNormalizarCabecalho($valor);

    if (in_array('sim', $permitidos, true) || in_array('nao', $permitidos, true)) {
        $mapaSimNao = [
            'sim' => 'sim',
            's' => 'sim',
            '1' => 'sim',
            'possui' => 'sim',
            'nao' => 'nao',
            'n' => 'nao',
            '0' => 'nao',
            'nao_possui' => 'nao',
        ];
        $valorFinal = $mapaSimNao[$normalizado] ?? $normalizado;

        return in_array($valorFinal, $permitidos, true) ? $valorFinal : $padrao;
    }

    if (in_array('cadastrado', $permitidos, true) || in_array('nao_cadastrado', $permitidos, true)) {
        $mapaCadastro = [
            'sim' => 'cadastrado',
            's' => 'cadastrado',
            '1' => 'cadastrado',
            'possui' => 'cadastrado',
            'cadastrado' => 'cadastrado',
            'nao' => 'nao_cadastrado',
            'n' => 'nao_cadastrado',
            '0' => 'nao_cadastrado',
            'nao_possui' => 'nao_cadastrado',
            'nao_cadastrado' => 'nao_cadastrado',
            'go' => 'goias',
            'goias' => 'goias',
        ];
        $valorFinal = $mapaCadastro[$normalizado] ?? $normalizado;

        return in_array($valorFinal, $permitidos, true) ? $valorFinal : $padrao;
    }

    $mapa = [
        'sim' => 'possui',
        's' => 'possui',
        '1' => 'possui',
        'possui' => 'possui',
        'nao' => 'nao_possui',
        'n' => 'nao_possui',
        '0' => 'nao_possui',
        'nao_possui' => 'nao_possui',
        'goias' => 'goias',
        'go' => 'goias',
        'lucro_presumido' => 'lucro_presumido',
        'lucro_real' => 'lucro_real',
        'simples_nacional' => 'simples_nacional',
        'mei' => 'mei',
        'micro_empreendedor_individual' => 'mei',
    ];
    $valorFinal = $mapa[$normalizado] ?? $normalizado;

    return in_array($valorFinal, $permitidos, true) ? $valorFinal : $padrao;
}

function importarClienteContabil(string $valor): int
{
    $normalizado = importarClienteNormalizarCabecalho($valor);

    if (in_array($normalizado, ['0', 'nao', 'nao_contabil', 'servico_avulso', 'avulso'], true)) {
        return 0;
    }

    return 1;
}

function importarClienteLinhaVazia(array $linha): bool
{
    foreach ($linha as $valor) {
        if (trim((string)$valor) !== '') {
            return false;
        }
    }

    return true;
}

function importarClienteDocumentoLimpo(string $documento): string
{
    return preg_replace('/\D/', '', $documento) ?? '';
}

function importarClienteCsv(string $arquivo): array
{
    $conteudo = file_get_contents($arquivo);

    if ($conteudo === false) {
        return [[], ['Não foi possível ler o arquivo enviado.']];
    }

    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;
    $primeiraLinha = strtok($conteudo, "\r\n") ?: '';
    $delimitador = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
    $temp = tmpfile();

    if ($temp === false) {
        return [[], ['Não foi possível preparar o arquivo para leitura.']];
    }

    fwrite($temp, $conteudo);
    rewind($temp);
    $cabecalho = fgetcsv($temp, 0, $delimitador);

    if (!$cabecalho) {
        fclose($temp);
        return [[], ['A planilha precisa ter uma linha de cabeçalho.']];
    }

    $cabecalho = array_map(static fn($valor) => importarClienteNormalizarCabecalho((string)$valor), $cabecalho);
    $linhas = [];
    $numero = 1;

    while (($dados = fgetcsv($temp, 0, $delimitador)) !== false) {
        $numero++;
        $linha = [];

        foreach ($cabecalho as $indice => $campo) {
            $linha[$campo] = $dados[$indice] ?? '';
        }

        if (importarClienteLinhaVazia($linha)) {
            continue;
        }

        $linhas[] = [
            'numero' => $numero,
            'dados' => $linha,
        ];
    }

    fclose($temp);

    return [$linhas, []];
}

function importarClientePreparar(array $linha): array
{
    $clienteContabil = importarClienteContabil(importarClienteValor($linha, ['cliente_contabil', 'contabil']));
    $servicoParcelamento = importarClienteOpcao(
        importarClienteValor($linha, ['servico_parcelamento', 'servico_parcelamentos']),
        ['possui', 'nao_possui'],
        'nao_possui'
    ) === 'possui' ? 1 : 0;
    $servicoCertificado = $clienteContabil === 1 ? 1 : (
        importarClienteOpcao(
            importarClienteValor($linha, ['servico_certificado', 'certificado_digital']),
            ['possui', 'nao_possui'],
            'nao_possui'
        ) === 'possui' ? 1 : 0
    );
    $possuiParcelamento = importarClienteOpcao(
        importarClienteValor($linha, ['possui_parcelamento', 'parcelamento']),
        ['possui', 'nao_possui'],
        $servicoParcelamento ? 'possui' : 'nao_possui'
    );

    if ($clienteContabil === 1) {
        $servicoParcelamento = 0;
    }

    return [
        'codigo' => importarClienteValor($linha, ['codigo', 'codigo_interno', 'cod']),
        'tipo_atendimento' => $clienteContabil === 1
            ? 'completo'
            : ($servicoParcelamento && !$servicoCertificado ? 'somente_parcelamento' : 'servico_avulso'),
        'cliente_contabil' => $clienteContabil,
        'servico_parcelamento' => $servicoParcelamento,
        'servico_certificado' => $servicoCertificado,
        'documento' => importarClienteValor($linha, ['documento', 'cnpj', 'cpf', 'cnpj_cpf', 'cpf_cnpj']),
        'nome' => importarClienteValor($linha, ['nome', 'razao_social', 'cliente']),
        'nome_fantasia' => importarClienteValor($linha, ['nome_fantasia', 'fantasia']),
        'endereco' => importarClienteValor($linha, ['endereco', 'logradouro']),
        'numero_endereco' => importarClienteValor($linha, ['numero_endereco', 'numero']),
        'complemento' => importarClienteValor($linha, ['complemento']),
        'bairro' => importarClienteValor($linha, ['bairro']),
        'cidade' => importarClienteValor($linha, ['cidade', 'municipio']),
        'uf' => strtoupper(importarClienteValor($linha, ['uf', 'estado'])),
        'cep' => importarClienteValor($linha, ['cep']),
        'telefone' => importarClienteValor($linha, ['telefone', 'celular']),
        'inscricao_estadual' => importarClienteValor($linha, ['inscricao_estadual', 'ie']),
        'nire' => importarClienteValor($linha, ['nire']),
        'email' => importarClienteValor($linha, ['email', 'e_mail']),
        'vencimento_certificado' => importarClienteData(importarClienteValor($linha, ['vencimento_certificado', 'certificado_vencimento'])),
        'cadastro_df_legal' => importarClienteOpcao(importarClienteValor($linha, ['cadastro_df_legal', 'df_legal']), ['cadastrado', 'nao_cadastrado', 'goias'], ''),
        'alvara' => importarClienteOpcao(importarClienteValor($linha, ['alvara']), ['possui', 'nao_possui', 'goias'], ''),
        'contador' => importarClienteOpcao(importarClienteValor($linha, ['contador']), ['sim', 'nao'], ''),
        'cadastro_crf' => importarClienteOpcao(importarClienteValor($linha, ['cadastro_crf', 'crf']), ['cadastrado', 'nao_cadastrado'], ''),
        'procuracao_receita_federal' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_receita_federal', 'procuracao_rf']), ['possui', 'nao_possui'], ''),
        'vencimento_procuracao_receita_federal' => importarClienteData(importarClienteValor($linha, ['vencimento_procuracao_receita_federal', 'vencimento_rf'])),
        'procuracao_conectividade' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_conectividade']), ['possui', 'nao_possui'], ''),
        'vencimento_procuracao_conectividade' => importarClienteData(importarClienteValor($linha, ['vencimento_procuracao_conectividade'])),
        'procuracao_empregador_web' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_empregador_web', 'empregador_web']), ['possui', 'nao_possui'], ''),
        'procuracao_fgts' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_fgts']), ['possui', 'nao_possui'], ''),
        'vencimento_procuracao_fgts' => importarClienteData(importarClienteValor($linha, ['vencimento_procuracao_fgts'])),
        'procuracao_particular' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_particular']), ['possui', 'nao_possui'], ''),
        'procuracao_sefaz' => importarClienteOpcao(importarClienteValor($linha, ['procuracao_sefaz']), ['possui', 'nao_possui', 'goias'], ''),
        'contrato_prestacao_servicos' => importarClienteOpcao(importarClienteValor($linha, ['contrato_prestacao_servicos', 'contrato']), ['possui', 'nao_possui'], ''),
        'tributacao' => importarClienteOpcao(importarClienteValor($linha, ['tributacao', 'regime_tributario']), ['simples_nacional', 'lucro_presumido', 'lucro_real', 'mei'], ''),
        'possui_parcelamento' => $possuiParcelamento,
    ];
}

function importarClienteValidar(PDO $pdo, array $cliente, array $documentosArquivo, array $codigosArquivo): array
{
    $erros = [];
    $documentoLimpo = importarClienteDocumentoLimpo($cliente['documento']);
    $codigo = trim($cliente['codigo']);

    if ($codigo === '') {
        $erros[] = 'Código obrigatório.';
    }

    if ($cliente['nome'] === '') {
        $erros[] = 'Razão social obrigatória.';
    }

    if ($documentoLimpo === '') {
        $erros[] = 'CPF/CNPJ obrigatório.';
    }

    if ($documentoLimpo !== '' && strlen($documentoLimpo) !== 11 && strlen($documentoLimpo) !== 14) {
        $erros[] = 'CPF/CNPJ deve ter 11 ou 14 dígitos.';
    }

    if ($codigo !== '' && ($codigosArquivo[$codigo] ?? 0) > 1) {
        $erros[] = 'Código repetido na planilha.';
    }

    if ($documentoLimpo !== '' && ($documentosArquivo[$documentoLimpo] ?? 0) > 1) {
        $erros[] = 'CPF/CNPJ repetido na planilha.';
    }

    if ($codigo !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM clientes
            WHERE codigo = ?
            " . empresaFiltroClienteDireto($pdo) . "
            LIMIT 1
        ");
        $stmt->execute([$codigo]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $erros[] = 'Código já cadastrado.';
        }
    }

    if ($documentoLimpo !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM clientes
            WHERE REPLACE(REPLACE(REPLACE(documento, '.', ''), '/', ''), '-', '') = ?
            " . empresaFiltroClienteDireto($pdo) . "
            LIMIT 1
        ");
        $stmt->execute([$documentoLimpo]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $erros[] = 'CPF/CNPJ já cadastrado.';
        }
    }

    if ($cliente['cliente_contabil'] === 0 && !$cliente['servico_parcelamento'] && !$cliente['servico_certificado']) {
        $erros[] = 'Serviço avulso precisa ter parcelamento ou certificado.';
    }

    return $erros;
}

function importarClienteInserir(PDO $pdo, array $cliente): int
{
    $empresaIdInsert = empresaIdParaInsert($pdo, 'clientes');
    $colunaEmpresaInsert = $empresaIdInsert !== null ? "empresa_id,\n            " : '';
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
    $stmt->execute(array_merge($valorEmpresaInsert, [
        $cliente['codigo'],
        $cliente['tipo_atendimento'],
        $cliente['cliente_contabil'],
        $cliente['servico_parcelamento'],
        $cliente['servico_certificado'],
        $cliente['documento'],
        $cliente['nome'],
        $cliente['nome_fantasia'],
        $cliente['endereco'],
        $cliente['numero_endereco'],
        $cliente['complemento'],
        $cliente['bairro'],
        $cliente['cidade'],
        $cliente['uf'],
        $cliente['cep'],
        $cliente['telefone'],
        $cliente['inscricao_estadual'],
        $cliente['nire'],
        $cliente['email'],
        $cliente['vencimento_certificado'],
        $cliente['cadastro_df_legal'],
        $cliente['alvara'],
        $cliente['contador'],
        $cliente['cadastro_crf'],
        $cliente['procuracao_receita_federal'],
        $cliente['vencimento_procuracao_receita_federal'],
        $cliente['procuracao_conectividade'],
        $cliente['vencimento_procuracao_conectividade'],
        $cliente['procuracao_empregador_web'],
        $cliente['procuracao_fgts'],
        $cliente['vencimento_procuracao_fgts'],
        $cliente['procuracao_particular'],
        $cliente['procuracao_sefaz'],
        $cliente['contrato_prestacao_servicos'],
        $cliente['tributacao'],
        $cliente['possui_parcelamento'],
    ]));

    return (int)$pdo->lastInsertId();
}

if (($_GET['modelo'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_clientes.csv"');
    $saida = fopen('php://output', 'w');
    fputcsv($saida, [
        'codigo',
        'nome',
        'documento',
        'nome_fantasia',
        'cliente_contabil',
        'telefone',
        'email',
        'cidade',
        'uf',
        'vencimento_certificado',
        'possui_parcelamento',
    ], ';');
    fputcsv($saida, [
        '1001',
        'Empresa Exemplo LTDA',
        '00.000.000/0001-00',
        'Empresa Exemplo',
        '1',
        '(61) 99999-9999',
        'contato@exemplo.com',
        'Brasília',
        'DF',
        '31/12/2026',
        'nao_possui',
    ], ';');
    exit;
}

$mensagem = null;
$erroArquivo = false;
$linhasPreview = $_SESSION[IMPORTACAO_CLIENTES_CHAVE] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'limpar') {
        unset($_SESSION[IMPORTACAO_CLIENTES_CHAVE]);
        header('Location: clientes_importar.php');
        exit;
    }

    if ($acao === 'previsualizar') {
        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            $erroArquivo = true;
            $mensagem = ['tipo' => 'danger', 'texto' => 'Selecione um arquivo CSV para importar.'];
        } else {
            [$linhasCsv, $errosArquivo] = importarClienteCsv($_FILES['arquivo']['tmp_name']);

            if ($errosArquivo !== []) {
                $mensagem = ['tipo' => 'danger', 'texto' => implode(' ', $errosArquivo)];
            } else {
                $preparados = [];
                $documentosArquivo = [];
                $codigosArquivo = [];

                foreach ($linhasCsv as $linhaCsv) {
                    $cliente = importarClientePreparar($linhaCsv['dados']);
                    $documentoLimpo = importarClienteDocumentoLimpo($cliente['documento']);

                    if ($documentoLimpo !== '') {
                        $documentosArquivo[$documentoLimpo] = ($documentosArquivo[$documentoLimpo] ?? 0) + 1;
                    }

                    if ($cliente['codigo'] !== '') {
                        $codigosArquivo[$cliente['codigo']] = ($codigosArquivo[$cliente['codigo']] ?? 0) + 1;
                    }

                    $preparados[] = [
                        'numero' => $linhaCsv['numero'],
                        'cliente' => $cliente,
                    ];
                }

                foreach ($preparados as &$preparado) {
                    $preparado['erros'] = importarClienteValidar(
                        $pdo,
                        $preparado['cliente'],
                        $documentosArquivo,
                        $codigosArquivo
                    );
                }
                unset($preparado);

                $_SESSION[IMPORTACAO_CLIENTES_CHAVE] = $preparados;
                $linhasPreview = $preparados;
                $mensagem = [
                    'tipo' => 'success',
                    'texto' => count($linhasPreview) . ' linhas lidas. Confira a prévia antes de importar.',
                ];
            }
        }
    }

    if ($acao === 'confirmar') {
        $linhasPreview = $_SESSION[IMPORTACAO_CLIENTES_CHAVE] ?? [];
        $linhasValidas = array_values(array_filter(
            $linhasPreview,
            static fn(array $linha): bool => empty($linha['erros'])
        ));

        if ($linhasValidas === []) {
            $mensagem = ['tipo' => 'danger', 'texto' => 'Não há linhas válidas para importar.'];
        } else {
            try {
                $pdo->beginTransaction();
                $idsImportados = [];

                foreach ($linhasValidas as $linha) {
                    $clienteId = importarClienteInserir($pdo, $linha['cliente']);
                    $idsImportados[] = $clienteId;
                    registrarAuditoria(
                        $pdo,
                        'Clientes',
                        'importar',
                        'cliente',
                        $clienteId,
                        'Importou o cliente ' . $linha['cliente']['codigo'] . ' - ' . $linha['cliente']['nome'],
                        null,
                        $linha['cliente']
                    );
                }

                $pdo->commit();
                unset($_SESSION[IMPORTACAO_CLIENTES_CHAVE]);
                $linhasPreview = [];
                $mensagem = [
                    'tipo' => 'success',
                    'texto' => count($idsImportados) . ' clientes importados com sucesso.',
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $mensagem = ['tipo' => 'danger', 'texto' => 'Não foi possível importar. Confira se a planilha tem dados válidos.'];
            }
        }
    }
}

$totalValidas = count(array_filter($linhasPreview, static fn(array $linha): bool => empty($linha['erros'])));
$totalErros = count($linhasPreview) - $totalValidas;
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Importar clientes</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Importar clientes</h3>
                    <p class="text-muted mb-0">Envie uma planilha CSV, confira os dados e importe somente as linhas válidas</p>
                </div>

                <div class="d-flex gap-2">
                    <a href="clientes_importar.php?modelo=1" class="btn btn-outline-success">
                        <i class="bi bi-download"></i> Baixar modelo
                    </a>
                    <a href="clientes.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <section class="clientes-box mb-4">
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end" id="formImportarClientes" novalidate>
                    <input type="hidden" name="acao" value="previsualizar">
                    <div class="col-lg-7">
                        <label for="arquivoImportacao" class="form-label">Arquivo CSV</label>
                        <input
                            type="file"
                            class="form-control <?= $erroArquivo ? 'is-invalid' : '' ?>"
                            name="arquivo"
                            id="arquivoImportacao"
                            accept=".csv,text/csv">
                        <div class="invalid-feedback" id="arquivoImportacaoFeedback">
                            Selecione um arquivo CSV para pré-visualizar.
                        </div>
                        <div class="form-text">
                            No Excel, salve como CSV UTF-8. Campos mínimos: código, nome e documento.
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-eye"></i> Pré-visualizar
                            </button>
                            <?php if ($linhasPreview !== []): ?>
                                <button type="submit" name="acao" value="limpar" class="btn btn-outline-secondary" formnovalidate>
                                    Limpar
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </section>

            <?php if ($linhasPreview !== []): ?>
                <section class="clientes-box">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div>
                            <h5 class="mb-1">Prévia da importação</h5>
                            <p class="text-muted mb-0">
                                <?= $totalValidas ?> válidas · <?= $totalErros ?> com erro
                            </p>
                        </div>

                        <form method="post">
                            <input type="hidden" name="acao" value="confirmar">
                            <button type="submit" class="btn btn-success" <?= $totalValidas === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Importar válidos
                            </button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Linha</th>
                                    <th>Código</th>
                                    <th>CPF/CNPJ</th>
                                    <th>Razão Social</th>
                                    <th>Vínculo</th>
                                    <th>Cidade/UF</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhasPreview as $linha):
                                    $cliente = $linha['cliente'];
                                    $temErro = !empty($linha['erros']);
                                ?>
                                    <tr class="<?= $temErro ? 'table-danger' : '' ?>">
                                        <td><?= (int)$linha['numero'] ?></td>
                                        <td><?= htmlspecialchars($cliente['codigo']) ?></td>
                                        <td><?= htmlspecialchars($cliente['documento']) ?></td>
                                        <td><?= htmlspecialchars($cliente['nome']) ?></td>
                                        <td>
                                            <span class="badge <?= $cliente['cliente_contabil'] ? 'bg-success' : 'bg-info text-dark' ?>">
                                                <?= $cliente['cliente_contabil'] ? 'Cliente contábil' : 'Serviço avulso' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(trim($cliente['cidade'] . '/' . $cliente['uf'], '/')) ?></td>
                                        <td>
                                            <?php if ($temErro): ?>
                                                <span class="badge bg-danger">Erro</span>
                                                <div class="small mt-1">
                                                    <?= htmlspecialchars(implode(' ', $linha['erros'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-success">Pronto para importar</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>
    <script>
        (function() {
            const form = document.getElementById('formImportarClientes');
            const arquivo = document.getElementById('arquivoImportacao');

            form.addEventListener('submit', function(event) {
                const acaoClicada = event.submitter?.value || 'previsualizar';

                if (acaoClicada === 'limpar') {
                    return;
                }

                if (!arquivo.files || arquivo.files.length === 0) {
                    event.preventDefault();
                    arquivo.classList.add('is-invalid');
                    arquivo.focus();
                }
            });

            arquivo.addEventListener('change', function() {
                arquivo.classList.toggle('is-invalid', !arquivo.files || arquivo.files.length === 0);
            });
        })();
    </script>
</body>

</html>