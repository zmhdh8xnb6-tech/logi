<?php
require 'config.php';

exigirPermissao('pendencias');

const IMPORTACAO_PENDENCIAS_CHAVE = 'pendencias_importacao_preview';

$tiposImportacaoPendencias = [
    'certificado' => [
        'titulo' => 'Certificado Digital',
        'permissao' => 'certificados',
        'campo_status' => 'certificado_status',
        'campo_vencimento' => 'vencimento_certificado',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
    'procuracao_receita_federal' => [
        'titulo' => 'Procuração Receita Federal',
        'permissao' => 'procuracoes',
        'campo_status' => 'procuracao_receita_federal',
        'campo_vencimento' => 'vencimento_procuracao_receita_federal',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
    'procuracao_conectividade' => [
        'titulo' => 'Procuração Conectividade',
        'permissao' => 'procuracoes',
        'campo_status' => 'procuracao_conectividade',
        'campo_vencimento' => 'vencimento_procuracao_conectividade',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
    'procuracao_fgts' => [
        'titulo' => 'Procuração FGTS',
        'permissao' => 'procuracoes',
        'campo_status' => 'procuracao_fgts',
        'campo_vencimento' => 'vencimento_procuracao_fgts',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
    'procuracao_empregador_web' => [
        'titulo' => 'Procuração Empregador Web',
        'permissao' => 'procuracoes',
        'campo_status' => 'procuracao_empregador_web',
        'campo_vencimento' => '',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_tem_funcionario', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
    'contrato_prestacao_servicos' => [
        'titulo' => 'Contrato de Prestação de Serviços',
        'permissao' => 'contratos',
        'campo_status' => 'contrato_prestacao_servicos',
        'campo_vencimento' => '',
        'status_permitidos' => ['possui', 'nao_possui', 'nao_precisa_momento'],
        'status_possui' => 'possui',
        'status_nao' => 'nao_possui',
        'status_nao_precisa' => 'nao_precisa_momento',
    ],
];

function importarPendenciaNormalizarCabecalho(string $valor): string
{
    $valor = trim($valor);
    $valor = strtr($valor, [
        'á' => 'a',
        'Á' => 'a',
        'à' => 'a',
        'À' => 'a',
        'â' => 'a',
        'Â' => 'a',
        'ã' => 'a',
        'Ã' => 'a',
        'é' => 'e',
        'É' => 'e',
        'ê' => 'e',
        'Ê' => 'e',
        'í' => 'i',
        'Í' => 'i',
        'ó' => 'o',
        'Ó' => 'o',
        'ô' => 'o',
        'Ô' => 'o',
        'õ' => 'o',
        'Õ' => 'o',
        'ú' => 'u',
        'Ú' => 'u',
        'ç' => 'c',
        'Ç' => 'c',
    ]);
    $valor = strtolower($valor);
    $valor = preg_replace('/[^a-z0-9]+/', '_', $valor) ?? $valor;

    return trim($valor, '_');
}

function importarPendenciaValor(array $linha, array $apelidos): string
{
    foreach ($apelidos as $apelido) {
        if (array_key_exists($apelido, $linha)) {
            return trim((string)$linha[$apelido]);
        }
    }

    return '';
}

function importarPendenciaLinhaVazia(array $linha): bool
{
    foreach ($linha as $valor) {
        if (trim((string)$valor) !== '') {
            return false;
        }
    }

    return true;
}

function importarPendenciaData(?string $valor): ?string
{
    $valor = trim((string)$valor);

    if ($valor === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return checkdate((int)substr($valor, 5, 2), (int)substr($valor, 8, 2), (int)substr($valor, 0, 4))
            ? $valor
            : null;
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $partes) || preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $valor, $partes)) {
        return checkdate((int)$partes[2], (int)$partes[1], (int)$partes[3])
            ? $partes[3] . '-' . $partes[2] . '-' . $partes[1]
            : null;
    }

    if (preg_match('/^\d+$/', $valor) && (int)$valor >= 25000 && (int)$valor <= 80000) {
        $data = DateTime::createFromFormat('Y-m-d', '1899-12-30');

        if ($data !== false) {
            $data->modify('+' . (int)$valor . ' days');
            return $data->format('Y-m-d');
        }
    }

    return null;
}

function importarPendenciaDataBr(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function importarPendenciaStatus(string $valor, ?string $vencimento): string
{
    $normalizado = importarPendenciaNormalizarCabecalho($valor);

    $mapa = [
        'possui' => 'possui',
        'sim' => 'possui',
        's' => 'possui',
        '1' => 'possui',
        'nao_possui' => 'nao_possui',
        'nao' => 'nao_possui',
        'n' => 'nao_possui',
        '0' => 'nao_possui',
        'nao_precisa_momento' => 'nao_precisa_momento',
        'nao_precisa' => 'nao_precisa_momento',
        'nao_precisa_no_momento' => 'nao_precisa_momento',
        'nao_tem_funcionario' => 'nao_tem_funcionario',
        'nao_tem_funcionarios' => 'nao_tem_funcionario',
        'sem_funcionario' => 'nao_tem_funcionario',
        'sem_funcionarios' => 'nao_tem_funcionario',
    ];

    if ($normalizado !== '') {
        return $mapa[$normalizado] ?? '';
    }

    return $vencimento !== null ? 'possui' : '';
}

function importarPendenciaCsv(string $arquivo): array
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

    $cabecalho = array_map(static function ($valor): string {
        return importarPendenciaNormalizarCabecalho((string)$valor);
    }, $cabecalho);
    $linhas = [];
    $numero = 1;

    while (($dados = fgetcsv($temp, 0, $delimitador)) !== false) {
        $numero++;
        $linha = [];

        foreach ($cabecalho as $indice => $campo) {
            $linha[$campo] = $dados[$indice] ?? '';
        }

        if (importarPendenciaLinhaVazia($linha)) {
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

function importarPendenciaClientePorCodigo(PDO $pdo, string $codigo): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, codigo, nome, documento
        FROM clientes
        WHERE codigo = ?
        " . clientesFiltroAtivos($pdo) . "
        " . empresaFiltroClienteDireto($pdo) . "
        LIMIT 1
    ");
    $stmt->execute([$codigo]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    return $cliente ?: null;
}

function importarPendenciaPreparar(PDO $pdo, array $linhaCsv, array $tipoConfig): array
{
    $linha = $linhaCsv['dados'];
    $temVencimento = trim((string)($tipoConfig['campo_vencimento'] ?? '')) !== '';
    $codigo = importarPendenciaValor($linha, ['codigo', 'codigo_interno', 'cod', 'cliente']);
    $vencimentoOriginal = importarPendenciaValor($linha, ['vencimento', 'validade', 'data', 'data_validade']);
    $vencimento = importarPendenciaData($vencimentoOriginal);
    $statusOriginal = importarPendenciaValor($linha, ['status', 'situacao']);
    $status = importarPendenciaStatus($statusOriginal, $vencimento);
    $erros = [];
    $cliente = null;

    if ($codigo === '') {
        $erros[] = 'Código obrigatório.';
    } else {
        $cliente = importarPendenciaClientePorCodigo($pdo, $codigo);

        if (!$cliente) {
            $erros[] = 'Cliente não encontrado nesta empresa.';
        }
    }

    if ($vencimentoOriginal !== '' && $vencimento === null) {
        $erros[] = 'Data inválida.';
    }

    if ($status === '') {
        $erros[] = 'Status inválido ou vazio.';
    }

    if (!in_array($status, $tipoConfig['status_permitidos'] ?? [], true)) {
        $erros[] = 'Status não permitido para este tipo de importação.';
    }

    if ($temVencimento && $status === 'possui' && $vencimento === null) {
        $erros[] = 'Vencimento obrigatório quando status for possui.';
    }

    if ($tipoConfig['campo_status'] === 'certificado_status' && !logiColunaExiste($pdo, 'clientes', 'certificado_status')) {
        $erros[] = 'Rode o SQL do certificado_status antes de importar certificados.';
    }

    return [
        'numero' => $linhaCsv['numero'],
        'codigo' => $codigo,
        'cliente_id' => $cliente ? (int)$cliente['id'] : 0,
        'cliente_nome' => $cliente['nome'] ?? '',
        'documento' => $cliente['documento'] ?? '',
        'status' => $status,
        'vencimento' => $vencimento,
        'erros' => $erros,
    ];
}

function importarPendenciaAtualizar(PDO $pdo, array $linha, array $tipoConfig): void
{
    $temVencimento = trim((string)($tipoConfig['campo_vencimento'] ?? '')) !== '';
    $vencimento = $temVencimento && $linha['status'] === 'possui' ? $linha['vencimento'] : null;

    $stmtAntes = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtAntes->execute([$linha['cliente_id']]);
    $antes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    if (!$antes) {
        throw new RuntimeException('Cliente não encontrado.');
    }

    if ($temVencimento) {
        $stmt = $pdo->prepare("
            UPDATE clientes
            SET {$tipoConfig['campo_status']} = ?,
                {$tipoConfig['campo_vencimento']} = ?
            WHERE id = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ");
        $stmt->execute([
            $linha['status'],
            $vencimento,
            $linha['cliente_id'],
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE clientes
            SET {$tipoConfig['campo_status']} = ?
            WHERE id = ?
            " . empresaFiltroClienteDireto($pdo) . "
        ");
        $stmt->execute([
            $linha['status'],
            $linha['cliente_id'],
        ]);
    }

    $stmtDepois = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtDepois->execute([$linha['cliente_id']]);
    $depois = $stmtDepois->fetch(PDO::FETCH_ASSOC);

    registrarAuditoria(
        $pdo,
        'Pendências',
        'importar',
        'cliente',
        $linha['cliente_id'],
        'Importou ' . $tipoConfig['titulo'] . ' para ' . ($antes['codigo'] ?? '') . ' - ' . ($antes['nome'] ?? ''),
        $antes ?: null,
        $depois ?: null
    );
}

if (($_GET['modelo'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_pendencias.csv"');
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['codigo', 'status', 'vencimento'], ';');
    fputcsv($saida, ['105', 'possui', '31/12/2026'], ';');
    fputcsv($saida, ['1422', 'nao_precisa_momento', ''], ';');
    fputcsv($saida, ['1445', 'nao_tem_funcionario', ''], ';');
    exit;
}

$mensagem = null;
$erroArquivo = false;
$erroTipo = false;
$linhasPreview = $_SESSION[IMPORTACAO_PENDENCIAS_CHAVE]['linhas'] ?? [];
$tipoPreview = $_SESSION[IMPORTACAO_PENDENCIAS_CHAVE]['tipo'] ?? '';
$tipoSelecionado = $_POST['tipo_importacao'] ?? $tipoPreview;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $tipoSelecionado = $_POST['tipo_importacao'] ?? '';
    $tipoConfig = $tiposImportacaoPendencias[$tipoSelecionado] ?? null;

    if ($acao === 'limpar') {
        unset($_SESSION[IMPORTACAO_PENDENCIAS_CHAVE]);
        header('Location: importar_pendencias.php');
        exit;
    }

    if (!$tipoConfig) {
        $erroTipo = true;
        $mensagem = ['tipo' => 'danger', 'texto' => 'Selecione o tipo de importação.'];
    } elseif (!usuarioPode($tipoConfig['permissao'])) {
        $mensagem = ['tipo' => 'danger', 'texto' => 'Você não tem permissão para importar este tipo.'];
    } elseif ($acao === 'previsualizar') {
        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            $erroArquivo = true;
            $mensagem = ['tipo' => 'danger', 'texto' => 'Selecione um arquivo CSV para importar.'];
        } else {
            [$linhasCsv, $errosArquivo] = importarPendenciaCsv($_FILES['arquivo']['tmp_name']);

            if ($errosArquivo !== []) {
                $mensagem = ['tipo' => 'danger', 'texto' => implode(' ', $errosArquivo)];
            } else {
                $linhasPreview = array_map(
                    function (array $linha) use ($pdo, $tipoConfig): array {
                        return importarPendenciaPreparar($pdo, $linha, $tipoConfig);
                    },
                    $linhasCsv
                );
                $tipoPreview = $tipoSelecionado;

                $_SESSION[IMPORTACAO_PENDENCIAS_CHAVE] = [
                    'tipo' => $tipoSelecionado,
                    'linhas' => $linhasPreview,
                ];

                $mensagem = [
                    'tipo' => 'success',
                    'texto' => count($linhasPreview) . ' linhas lidas. Confira a prévia antes de importar.',
                ];
            }
        }
    } elseif ($acao === 'confirmar') {
        $dadosSessao = $_SESSION[IMPORTACAO_PENDENCIAS_CHAVE] ?? [];
        $linhasPreview = $dadosSessao['linhas'] ?? [];
        $tipoPreview = $dadosSessao['tipo'] ?? '';

        if ($tipoPreview !== $tipoSelecionado) {
            $mensagem = ['tipo' => 'danger', 'texto' => 'A prévia não corresponde ao tipo selecionado. Faça a pré-visualização novamente.'];
        } else {
            $linhasValidas = array_values(array_filter(
                $linhasPreview,
                static function (array $linha): bool {
                    return empty($linha['erros']);
                }
            ));

            if ($linhasValidas === []) {
                $mensagem = ['tipo' => 'danger', 'texto' => 'Não há linhas válidas para importar.'];
            } else {
                try {
                    $pdo->beginTransaction();

                    foreach ($linhasValidas as $linha) {
                        importarPendenciaAtualizar($pdo, $linha, $tipoConfig);
                    }

                    $pdo->commit();
                    unset($_SESSION[IMPORTACAO_PENDENCIAS_CHAVE]);
                    $linhasPreview = [];
                    $tipoPreview = '';
                    $mensagem = [
                        'tipo' => 'success',
                        'texto' => count($linhasValidas) . ' registros importados com sucesso.',
                    ];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $mensagem = ['tipo' => 'danger', 'texto' => 'Não foi possível importar. Confira os dados da planilha.'];
                }
            }
        }
    }
}

$totalValidas = count(array_filter($linhasPreview, static function (array $linha): bool {
    return empty($linha['erros']);
}));
$totalErros = count($linhasPreview) - $totalValidas;
$tipoPreviewTitulo = $tiposImportacaoPendencias[$tipoPreview]['titulo'] ?? '';
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Importar pendências</title>
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Importar pendências</h3>
                    <p class="text-muted mb-0">Atualize vencimentos por planilha CSV sem digitar cliente por cliente</p>
                </div>

                <div class="d-flex gap-2">
                    <a href="importar_pendencias.php?modelo=1" class="btn btn-outline-success">
                        <i class="bi bi-download"></i> Baixar modelo
                    </a>
                    <a href="pendencias.php" class="btn btn-outline-secondary">
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
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end" id="formImportarPendencias" novalidate>
                    <input type="hidden" name="acao" value="previsualizar">

                    <div class="col-xl-3 col-lg-4">
                        <label for="tipoImportacao" class="form-label">Tipo de importação</label>
                        <select
                            class="form-select <?= $erroTipo ? 'is-invalid' : '' ?>"
                            name="tipo_importacao"
                            id="tipoImportacao">
                            <option value="">Selecione</option>
                            <?php foreach ($tiposImportacaoPendencias as $chave => $config): ?>
                                <option value="<?= htmlspecialchars($chave) ?>" <?= $tipoSelecionado === $chave ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($config['titulo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Selecione o tipo de importação.</div>
                    </div>

                    <div class="col-xl-6 col-lg-5">
                        <label for="arquivoImportacaoPendencias" class="form-label">Arquivo CSV</label>
                        <input
                            type="file"
                            class="form-control <?= $erroArquivo ? 'is-invalid' : '' ?>"
                            name="arquivo"
                            id="arquivoImportacaoPendencias"
                            accept=".csv,text/csv">
                        <div class="invalid-feedback">Selecione um arquivo CSV para pré-visualizar.</div>
                    </div>

                    <div class="col-xl-3 col-lg-3">
                        <div class="d-flex flex-wrap gap-2 justify-content-lg-end align-items-center">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-eye"></i> Pré-visualizar
                            </button>
                            <?php if ($linhasPreview !== []): ?>
                                <button type="submit" name="acao" value="limpar" class="btn btn-outline-secondary" formnovalidate>
                                    Limpar
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-text mt-0">
                            Colunas aceitas: código, status e vencimento. Para Empregador Web, use só código e status.
                        </div>
                    </div>
                </form>
            </section>

            <?php if ($linhasPreview !== []): ?>
                <section class="clientes-box">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div>
                            <h5 class="mb-1">Prévia da importação<?= $tipoPreviewTitulo !== '' ? ' - ' . htmlspecialchars($tipoPreviewTitulo) : '' ?></h5>
                            <p class="text-muted mb-0">
                                <?= $totalValidas ?> válidas · <?= $totalErros ?> com erro
                            </p>
                        </div>

                        <form method="post">
                            <input type="hidden" name="acao" value="confirmar">
                            <input type="hidden" name="tipo_importacao" value="<?= htmlspecialchars($tipoPreview) ?>">
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
                                    <th>Cliente</th>
                                    <th>CPF/CNPJ</th>
                                    <th>Status</th>
                                    <th>Vencimento</th>
                                    <th>Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhasPreview as $linha):
                                    $temErro = !empty($linha['erros']);
                                    $statusTexto = [
                                        'possui' => 'Possui',
                                        'nao_possui' => 'Não possui',
                                        'nao_tem_funcionario' => 'Não tem funcionário',
                                        'nao_precisa_momento' => 'Não precisa no momento',
                                    ][$linha['status']] ?? '-';
                                ?>
                                    <tr class="<?= $temErro ? 'table-danger' : '' ?>">
                                        <td><?= (int)$linha['numero'] ?></td>
                                        <td><?= htmlspecialchars($linha['codigo']) ?></td>
                                        <td><?= htmlspecialchars($linha['cliente_nome'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($linha['documento'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($statusTexto) ?></td>
                                        <td><?= htmlspecialchars(importarPendenciaDataBr($linha['vencimento'])) ?></td>
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
            const form = document.getElementById('formImportarPendencias');
            const arquivo = document.getElementById('arquivoImportacaoPendencias');
            const tipo = document.getElementById('tipoImportacao');

            form.addEventListener('submit', function(event) {
                const acaoClicada = event.submitter?.value || 'previsualizar';

                if (acaoClicada === 'limpar') {
                    return;
                }

                let invalido = false;

                if (!tipo.value) {
                    tipo.classList.add('is-invalid');
                    invalido = true;
                }

                if (!arquivo.files || arquivo.files.length === 0) {
                    arquivo.classList.add('is-invalid');
                    invalido = true;
                }

                if (invalido) {
                    event.preventDefault();
                    (tipo.classList.contains('is-invalid') ? tipo : arquivo).focus();
                }
            });

            tipo.addEventListener('change', function() {
                tipo.classList.toggle('is-invalid', !tipo.value);
            });

            arquivo.addEventListener('change', function() {
                arquivo.classList.toggle('is-invalid', !arquivo.files || arquivo.files.length === 0);
            });
        })();
    </script>
</body>

</html>