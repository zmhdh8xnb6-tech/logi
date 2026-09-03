<?php
require 'config.php';
require_once __DIR__ . '/includes/frota_funcoes.php';

exigirPermissao('frota');

$empresaId = max(1, (int)(empresaAtivaId($pdo) ?? 1));
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$abasPermitidas = ['visao-geral', 'obrigacoes', 'multas'];
$aba = $_GET['aba'] ?? $_POST['aba'] ?? 'visao-geral';
$aba = in_array($aba, $abasPermitidas, true) ? $aba : 'visao-geral';
$mensagem = trim((string)($_GET['msg'] ?? ''));
$tipoMensagem = (string)($_GET['tipo'] ?? 'success');
$tipoMensagem = in_array($tipoMensagem, ['success', 'warning', 'danger', 'info'], true)
    ? $tipoMensagem
    : 'success';
$tabelasFrota = ['frota_veiculos', 'frota_controles_anuais', 'frota_documentos'];
$estruturaDisponivel = true;

foreach ($tabelasFrota as $tabelaFrota) {
    if (!logiTabelaExiste($pdo, $tabelaFrota)) {
        $estruturaDisponivel = false;
        break;
    }
}

$sqlFrota = (string)@file_get_contents(__DIR__ . '/sql/frota.sql');
$situacoesVeiculo = ['ativo', 'manutencao', 'inativo', 'vendido'];
$anoAtual = (int)date('Y');
$anoControle = (int)($_GET['ano'] ?? $_POST['ano'] ?? $anoAtual);
if ($anoControle < $anoAtual - 5 || $anoControle > $anoAtual + 1) {
    $anoControle = $anoAtual;
}

if (
    !$estruturaDisponivel
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'instalar_estrutura'
) {
    if (!usuarioEhAdmin()) {
        frotaRedirecionar('Somente um administrador pode ativar a estrutura da frota.', 'danger');
    }

    if (!frotaTokenValido($_POST['csrf_token'] ?? null)) {
        frotaRedirecionar('A sessão do formulário expirou. Tente novamente.', 'danger');
    }

    try {
        $comandos = preg_split('/;\s*(?:\r?\n|$)/', trim($sqlFrota)) ?: [];
        foreach ($comandos as $comando) {
            if (trim($comando) !== '') {
                $pdo->exec($comando);
            }
        }
        frotaRedirecionar('Estrutura da frota ativada com sucesso.');
    } catch (Throwable $e) {
        frotaRedirecionar('Não foi possível criar a estrutura automaticamente. Confira o acesso ao banco ou use o SQL exibido na página.', 'danger');
    }
}

$buscarVeiculo = static function (PDO $pdo, int $empresaId, int $id): ?array {
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM frota_veiculos WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($estruturaDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!frotaTokenValido($_POST['csrf_token'] ?? null)) {
        frotaRedirecionar('A sessão do formulário expirou. Tente novamente.', 'danger', $aba);
    }

    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar_veiculo') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $id > 0 ? $buscarVeiculo($pdo, $empresaId, $id) : null;
        $placa = frotaPlaca((string)($_POST['placa'] ?? ''));
        $marca = frotaTexto((string)($_POST['marca'] ?? ''), 80);
        $modelo = frotaTexto((string)($_POST['modelo'] ?? ''), 120);
        $renavam = frotaRenavam((string)($_POST['renavam'] ?? ''));
        $anoFabricacao = (int)($_POST['ano_fabricacao'] ?? 0);
        $anoModelo = (int)($_POST['ano_modelo'] ?? 0);
        $cor = frotaTexto((string)($_POST['cor'] ?? ''), 50);
        $responsavel = frotaTexto((string)($_POST['responsavel'] ?? ''), 150);
        $situacao = (string)($_POST['situacao'] ?? 'ativo');
        $observacoes = trim((string)($_POST['observacoes'] ?? ''));
        $anoLimite = (int)date('Y') + 1;

        if ($id > 0 && !$antes) {
            frotaRedirecionar('Veículo não encontrado nesta empresa.', 'danger', 'visao-geral');
        }

        if (!frotaPlacaValida($placa) || $marca === '' || $modelo === '') {
            frotaRedirecionar('Informe uma placa válida, a marca e o modelo do veículo.', 'danger', 'visao-geral');
        }

        if (preg_match('/^\d{9,11}$/', $renavam) !== 1) {
            frotaRedirecionar('O RENAVAM deve possuir entre 9 e 11 números.', 'danger', 'visao-geral');
        }

        foreach ([$anoFabricacao, $anoModelo] as $anoInformado) {
            if ($anoInformado < 1900 || $anoInformado > $anoLimite) {
                frotaRedirecionar('Revise os anos informados para o veículo.', 'danger', 'visao-geral');
            }
        }

        if (!in_array($situacao, $situacoesVeiculo, true)) {
            $situacao = 'ativo';
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE frota_veiculos
                    SET placa = ?, marca = ?, modelo = ?, renavam = ?,
                        ano_fabricacao = ?, ano_modelo = ?, cor = ?,
                        responsavel = ?, situacao = ?, observacoes = ?, usuario_id = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([
                    $placa,
                    $marca,
                    $modelo,
                    $renavam !== '' ? $renavam : null,
                    $anoFabricacao ?: null,
                    $anoModelo ?: null,
                    $cor !== '' ? $cor : null,
                    $responsavel !== '' ? $responsavel : null,
                    $situacao,
                    $observacoes !== '' ? $observacoes : null,
                    $usuarioId,
                    $id,
                    $empresaId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO frota_veiculos (
                        empresa_id, placa, marca, modelo, renavam,
                        ano_fabricacao, ano_modelo, cor,
                        responsavel, situacao, observacoes, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $empresaId,
                    $placa,
                    $marca,
                    $modelo,
                    $renavam !== '' ? $renavam : null,
                    $anoFabricacao ?: null,
                    $anoModelo ?: null,
                    $cor !== '' ? $cor : null,
                    $responsavel !== '' ? $responsavel : null,
                    $situacao,
                    $observacoes !== '' ? $observacoes : null,
                    $usuarioId,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            $depois = $buscarVeiculo($pdo, $empresaId, $id);
            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                $antes ? 'editar' : 'criar',
                'veiculo',
                $id,
                ($antes ? 'Alterou' : 'Cadastrou') . ' o veículo ' . frotaPlacaFormatada($placa),
                $antes,
                $depois
            );
            frotaRedirecionar($antes ? 'Veículo atualizado com sucesso.' : 'Veículo cadastrado com sucesso.');
        } catch (PDOException $e) {
            $mensagemErro = (string)$e->getCode() === '23000'
                ? 'Já existe um veículo com esta placa ou RENAVAM nesta empresa.'
                : 'Não foi possível salvar o veículo.';
            frotaRedirecionar($mensagemErro, 'danger', 'visao-geral');
        }
    }

    if ($acao === 'excluir_veiculo') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $buscarVeiculo($pdo, $empresaId, $id);

        if (!$antes) {
            frotaRedirecionar('Veículo não encontrado nesta empresa.', 'danger', 'visao-geral');
        }

        try {
            $stmtArquivos = $pdo->prepare('SELECT caminho_arquivo FROM frota_documentos WHERE veiculo_id = ? AND empresa_id = ?');
            $stmtArquivos->execute([$id, $empresaId]);
            $arquivosVeiculo = $stmtArquivos->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM frota_veiculos WHERE id = ? AND empresa_id = ?')
                ->execute([$id, $empresaId]);
            $raizArmazenamento = realpath(__DIR__ . '/storage/frota');
            foreach ($arquivosVeiculo as $arquivoVeiculo) {
                $caminhoArquivo = realpath(__DIR__ . '/' . ltrim((string)$arquivoVeiculo, '/'));
                if (
                    $raizArmazenamento !== false
                    && $caminhoArquivo !== false
                    && str_starts_with($caminhoArquivo, $raizArmazenamento . DIRECTORY_SEPARATOR)
                    && is_file($caminhoArquivo)
                ) {
                    @unlink($caminhoArquivo);
                }
            }
            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                'excluir',
                'veiculo',
                $id,
                'Excluiu o veículo ' . frotaPlacaFormatada((string)$antes['placa']) . ' e seus registros',
                $antes,
                null
            );
            frotaRedirecionar('Veículo e registros vinculados excluídos com sucesso.');
        } catch (Throwable $e) {
            frotaRedirecionar('Não foi possível excluir o veículo.', 'danger', 'visao-geral');
        }
    }

    if ($acao === 'enviar_documento_veiculo') {
        $veiculoId = (int)($_POST['veiculo_id'] ?? 0);
        $veiculo = $buscarVeiculo($pdo, $empresaId, $veiculoId);
        $arquivo = $_FILES['documento_veiculo'] ?? null;

        if (!$veiculo) {
            frotaRedirecionar('Veículo não encontrado nesta empresa.', 'danger', 'visao-geral', ['ano' => $anoControle]);
        }

        if (!is_array($arquivo) || (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $erroUpload = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
            $mensagemUpload = match ($erroUpload) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo ultrapassa o limite permitido de 10 MB.',
                UPLOAD_ERR_NO_FILE => 'Escolha um documento para enviar.',
                default => 'Não foi possível receber o documento. Tente novamente.',
            };
            frotaRedirecionar($mensagemUpload, 'danger', 'visao-geral', ['ano' => $anoControle]);
        }

        $caminhoTemporario = (string)($arquivo['tmp_name'] ?? '');
        $tamanhoArquivo = (int)($arquivo['size'] ?? 0);
        if (!is_uploaded_file($caminhoTemporario) || $tamanhoArquivo <= 0 || $tamanhoArquivo > 10 * 1024 * 1024) {
            frotaRedirecionar('O documento deve possuir até 10 MB.', 'danger', 'visao-geral', ['ano' => $anoControle]);
        }

        $tipoMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $tipoMime = $finfo ? (string)finfo_file($finfo, $caminhoTemporario) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $tipoMime = (string)mime_content_type($caminhoTemporario);
        }
        $extensoesPermitidas = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!isset($extensoesPermitidas[$tipoMime])) {
            frotaRedirecionar('Envie o documento em PDF, JPG ou PNG.', 'danger', 'visao-geral', ['ano' => $anoControle]);
        }

        $nomeOriginal = frotaTexto(basename(str_replace(["\r", "\n"], '', (string)($arquivo['name'] ?? 'documento'))), 255);
        if ($nomeOriginal === '') {
            $nomeOriginal = 'documento.' . $extensoesPermitidas[$tipoMime];
        }
        $diretorioRelativo = 'storage/frota/' . $empresaId . '/' . $veiculoId . '/' . $anoControle;
        $diretorioAbsoluto = __DIR__ . '/' . $diretorioRelativo;
        if (!is_dir($diretorioAbsoluto) && !mkdir($diretorioAbsoluto, 0750, true) && !is_dir($diretorioAbsoluto)) {
            frotaRedirecionar('Não foi possível preparar a pasta do documento.', 'danger', 'visao-geral', ['ano' => $anoControle]);
        }

        try {
            $nomeArmazenado = bin2hex(random_bytes(18)) . '.' . $extensoesPermitidas[$tipoMime];
            $caminhoRelativo = $diretorioRelativo . '/' . $nomeArmazenado;
            $caminhoAbsoluto = __DIR__ . '/' . $caminhoRelativo;
            if (!move_uploaded_file($caminhoTemporario, $caminhoAbsoluto)) {
                throw new RuntimeException('Falha ao mover o arquivo enviado.');
            }

            $stmtAnterior = $pdo->prepare('SELECT caminho_arquivo FROM frota_documentos WHERE empresa_id = ? AND veiculo_id = ? AND ano = ?');
            $stmtAnterior->execute([$empresaId, $veiculoId, $anoControle]);
            $caminhoAnterior = (string)($stmtAnterior->fetchColumn() ?: '');

            $pdo->beginTransaction();
            $stmtDocumento = $pdo->prepare("
                INSERT INTO frota_documentos (
                    empresa_id, veiculo_id, ano, nome_original, caminho_arquivo,
                    tipo_mime, tamanho_bytes, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nome_original = VALUES(nome_original),
                    caminho_arquivo = VALUES(caminho_arquivo),
                    tipo_mime = VALUES(tipo_mime),
                    tamanho_bytes = VALUES(tamanho_bytes),
                    usuario_id = VALUES(usuario_id),
                    enviado_em = CURRENT_TIMESTAMP
            ");
            $stmtDocumento->execute([
                $empresaId,
                $veiculoId,
                $anoControle,
                $nomeOriginal,
                $caminhoRelativo,
                $tipoMime,
                $tamanhoArquivo,
                $usuarioId,
            ]);

            $stmtControle = $pdo->prepare("
                INSERT INTO frota_controles_anuais (empresa_id, veiculo_id, ano, documento_emitido, usuario_id)
                VALUES (?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE documento_emitido = 1, usuario_id = VALUES(usuario_id)
            ");
            $stmtControle->execute([$empresaId, $veiculoId, $anoControle, $usuarioId]);
            $pdo->commit();

            if ($caminhoAnterior !== '' && $caminhoAnterior !== $caminhoRelativo) {
                $raizArmazenamento = realpath(__DIR__ . '/storage/frota');
                $arquivoAnterior = realpath(__DIR__ . '/' . ltrim($caminhoAnterior, '/'));
                if (
                    $raizArmazenamento !== false
                    && $arquivoAnterior !== false
                    && str_starts_with($arquivoAnterior, $raizArmazenamento . DIRECTORY_SEPARATOR)
                    && is_file($arquivoAnterior)
                ) {
                    @unlink($arquivoAnterior);
                }
            }

            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                'enviar_documento',
                'veiculo',
                $veiculoId,
                'Anexou o documento de ' . $anoControle . ' do veículo ' . frotaPlacaFormatada((string)$veiculo['placa']),
                null,
                ['ano' => $anoControle, 'arquivo' => $nomeOriginal, 'tipo' => $tipoMime]
            );
            frotaRedirecionar('Documento anexado e veículo marcado como concluído.', 'success', 'visao-geral', ['ano' => $anoControle]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!empty($caminhoAbsoluto) && is_file($caminhoAbsoluto)) {
                @unlink($caminhoAbsoluto);
            }
            frotaRedirecionar('Não foi possível salvar o documento do veículo.', 'danger', 'visao-geral', ['ano' => $anoControle]);
        }
    }

    if (in_array($acao, ['salvar_controle_obrigacoes', 'salvar_controle_multas'], true)) {
        $veiculosInformados = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['veiculos'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));

        if ($veiculosInformados === []) {
            frotaRedirecionar('Cadastre ao menos um veículo para fazer o acompanhamento.', 'warning', $aba, ['ano' => $anoControle]);
        }

        $marcadores = implode(',', array_fill(0, count($veiculosInformados), '?'));
        $stmtPermitidos = $pdo->prepare("SELECT id FROM frota_veiculos WHERE empresa_id = ? AND id IN ({$marcadores})");
        $stmtPermitidos->execute(array_merge([$empresaId], $veiculosInformados));
        $veiculosPermitidos = array_map('intval', $stmtPermitidos->fetchAll(PDO::FETCH_COLUMN));

        if (count($veiculosPermitidos) !== count($veiculosInformados)) {
            frotaRedirecionar('Um dos veículos informados não pertence a esta empresa.', 'danger', $aba, ['ano' => $anoControle]);
        }

        try {
            $pdo->beginTransaction();

            if ($acao === 'salvar_controle_obrigacoes') {
                $documentos = (array)($_POST['documento_emitido'] ?? []);
                $boletos = (array)($_POST['boletos_enviados'] ?? []);
                $stmtSalvar = $pdo->prepare("
                    INSERT INTO frota_controles_anuais (
                        empresa_id, veiculo_id, ano, documento_emitido, boletos_enviados, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        documento_emitido = VALUES(documento_emitido),
                        boletos_enviados = VALUES(boletos_enviados),
                        usuario_id = VALUES(usuario_id)
                ");

                foreach ($veiculosPermitidos as $veiculoId) {
                    $stmtSalvar->execute([
                        $empresaId,
                        $veiculoId,
                        $anoControle,
                        isset($documentos[$veiculoId]) ? 1 : 0,
                        isset($boletos[$veiculoId]) ? 1 : 0,
                        $usuarioId,
                    ]);
                }
                $descricaoAuditoria = "Atualizou o acompanhamento de documentos da frota de {$anoControle}";
            } else {
                $possuiMultas = (array)($_POST['possui_multas'] ?? []);
                $quantidades = (array)($_POST['quantidade_multas'] ?? []);
                $stmtSalvar = $pdo->prepare("
                    INSERT INTO frota_controles_anuais (
                        empresa_id, veiculo_id, ano, possui_multas, quantidade_multas, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        possui_multas = VALUES(possui_multas),
                        quantidade_multas = VALUES(quantidade_multas),
                        usuario_id = VALUES(usuario_id)
                ");

                foreach ($veiculosPermitidos as $veiculoId) {
                    $temMulta = isset($possuiMultas[$veiculoId]);
                    $quantidade = $temMulta ? max(0, min(9999, (int)($quantidades[$veiculoId] ?? 0))) : 0;
                    if ($temMulta && $quantidade < 1) {
                        throw new InvalidArgumentException('Informe a quantidade de multas dos veículos marcados com multa.');
                    }
                    $stmtSalvar->execute([$empresaId, $veiculoId, $anoControle, $temMulta ? 1 : 0, $quantidade, $usuarioId]);
                }
                $descricaoAuditoria = "Atualizou o acompanhamento de multas da frota de {$anoControle}";
            }

            $pdo->commit();
            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                'editar',
                'controle_anual_frota',
                null,
                $descricaoAuditoria,
                null,
                ['ano' => $anoControle, 'veiculos' => count($veiculosPermitidos)]
            );
            frotaRedirecionar('Acompanhamento atualizado com sucesso.', 'success', $aba, ['ano' => $anoControle]);
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            frotaRedirecionar($e->getMessage(), 'danger', $aba, ['ano' => $anoControle]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            frotaRedirecionar('Não foi possível salvar o acompanhamento da frota.', 'danger', $aba, ['ano' => $anoControle]);
        }
    }
}

$veiculos = [];
$controles = [];
$resumo = [
    'veiculos_ativos' => 0,
    'documentos_pendentes' => 0,
    'boletos_pendentes' => 0,
    'multas_pendentes' => 0,
];
$busca = trim((string)($_GET['busca'] ?? ''));
$situacaoVeiculoFiltro = (string)($_GET['situacao_veiculo'] ?? 'todos');

if ($estruturaDisponivel) {
    $stmtResumo = $pdo->prepare("
        SELECT
            COUNT(*) AS veiculos_ativos,
            COALESCE(SUM(CASE WHEN COALESCE(c.documento_emitido, 0) = 0 THEN 1 ELSE 0 END), 0) AS documentos_pendentes,
            COALESCE(SUM(CASE WHEN COALESCE(c.boletos_enviados, 0) = 0 THEN 1 ELSE 0 END), 0) AS boletos_pendentes,
            COALESCE(SUM(CASE WHEN COALESCE(c.possui_multas, 0) = 1 THEN COALESCE(c.quantidade_multas, 0) ELSE 0 END), 0) AS multas_pendentes
        FROM frota_veiculos v
        LEFT JOIN frota_controles_anuais c
            ON c.empresa_id = v.empresa_id AND c.veiculo_id = v.id AND c.ano = ?
        WHERE v.empresa_id = ? AND v.situacao = 'ativo'
    ");
    $stmtResumo->execute([$anoControle, $empresaId]);
    $dadosResumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];
    $resumo = [
        'veiculos_ativos' => (int)($dadosResumo['veiculos_ativos'] ?? 0),
        'documentos_pendentes' => (int)($dadosResumo['documentos_pendentes'] ?? 0),
        'boletos_pendentes' => (int)($dadosResumo['boletos_pendentes'] ?? 0),
        'multas_pendentes' => (int)($dadosResumo['multas_pendentes'] ?? 0),
    ];

    $filtroVeiculos = 'v.empresa_id = ?';
    $parametrosVeiculos = [$empresaId];
    if ($situacaoVeiculoFiltro !== 'todos' && in_array($situacaoVeiculoFiltro, $situacoesVeiculo, true)) {
        $filtroVeiculos .= ' AND v.situacao = ?';
        $parametrosVeiculos[] = $situacaoVeiculoFiltro;
    }
    if ($busca !== '') {
        $filtroVeiculos .= ' AND (v.placa LIKE ? OR v.marca LIKE ? OR v.modelo LIKE ? OR v.renavam LIKE ? OR v.responsavel LIKE ?)';
        $termoBusca = '%' . $busca . '%';
        array_push($parametrosVeiculos, $termoBusca, $termoBusca, $termoBusca, $termoBusca, $termoBusca);
    }

    $stmtVeiculos = $pdo->prepare("
        SELECT v.*,
            COALESCE(c.documento_emitido, 0) AS documento_emitido,
            COALESCE(c.boletos_enviados, 0) AS boletos_enviados,
            COALESCE(c.possui_multas, 0) AS possui_multas,
            COALESCE(c.quantidade_multas, 0) AS quantidade_multas,
            d.id AS documento_id,
            d.nome_original AS documento_nome,
            d.enviado_em AS documento_enviado_em
        FROM frota_veiculos v
        LEFT JOIN frota_controles_anuais c
            ON c.empresa_id = v.empresa_id AND c.veiculo_id = v.id AND c.ano = ?
        LEFT JOIN frota_documentos d
            ON d.empresa_id = v.empresa_id AND d.veiculo_id = v.id AND d.ano = ?
        WHERE {$filtroVeiculos}
        ORDER BY FIELD(v.situacao, 'ativo', 'manutencao', 'inativo', 'vendido'), v.modelo, v.placa
    ");
    $stmtVeiculos->execute(array_merge([$anoControle, $anoControle], $parametrosVeiculos));
    $veiculos = $stmtVeiculos->fetchAll(PDO::FETCH_ASSOC);

    $stmtControles = $pdo->prepare("
        SELECT v.id, v.placa, v.marca, v.modelo, v.renavam, v.situacao,
            COALESCE(c.documento_emitido, 0) AS documento_emitido,
            COALESCE(c.boletos_enviados, 0) AS boletos_enviados,
            COALESCE(c.possui_multas, 0) AS possui_multas,
            COALESCE(c.quantidade_multas, 0) AS quantidade_multas,
            c.atualizado_em,
            d.id AS documento_id,
            d.nome_original AS documento_nome,
            d.enviado_em AS documento_enviado_em
        FROM frota_veiculos v
        LEFT JOIN frota_controles_anuais c
            ON c.empresa_id = v.empresa_id AND c.veiculo_id = v.id AND c.ano = ?
        LEFT JOIN frota_documentos d
            ON d.empresa_id = v.empresa_id AND d.veiculo_id = v.id AND d.ano = ?
        WHERE v.empresa_id = ? AND v.situacao <> 'vendido'
        ORDER BY FIELD(v.situacao, 'ativo', 'manutencao', 'inativo'), v.modelo, v.placa
    ");
    $stmtControles->execute([$anoControle, $anoControle, $empresaId]);
    $controles = $stmtControles->fetchAll(PDO::FETCH_ASSOC);
}

$statusClasse = static function (string $status): string {
    return match ($status) {
        'vencido' => 'danger',
        'proximo' => 'warning',
        'pago', 'paga', 'ativo' => 'success',
        'recorrida' => 'info',
        'cancelada', 'dispensado', 'inativo', 'vendido' => 'secondary',
        'manutencao' => 'primary',
        default => 'light',
    };
};

$statusRotulo = static function (string $status): string {
    return match ($status) {
        'vencido' => 'Vencido',
        'proximo' => 'Vence em até 30 dias',
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'paga' => 'Paga',
        'dispensado' => 'Dispensado',
        'recorrida' => 'Recorrida',
        'cancelada' => 'Cancelada',
        default => ucfirst($status),
    };
};
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Gestão da Frota</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/frota.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid frota-pagina">
            <header class="frota-cabecalho">
                <div>
                    <h3 class="mb-1">Gestão da Frota</h3>
                    <p class="text-muted mb-0">Acompanhamento anual de documentos e multas da <?= htmlspecialchars(empresaAtivaNome($pdo)) ?>.</p>
                </div>
                <div class="frota-cabecalho-acoes">
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <?php if ($estruturaDisponivel): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalVeiculo">
                            <i class="bi bi-plus-lg"></i> Novo veículo
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($mensagem !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($tipoMensagem) ?> alerta-temporario">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <?php if (!$estruturaDisponivel): ?>
                <section class="frota-painel frota-ativacao">
                    <i class="bi bi-database-add"></i>
                    <div>
                        <h5>Estrutura da frota ainda não criada</h5>
                        <p>Ative as tabelas de veículos e acompanhamento anual para começar.</p>
                    </div>
                    <?php if (usuarioEhAdmin()): ?>
                        <form method="post" class="frota-ativacao-acoes">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>">
                            <input type="hidden" name="acao" value="instalar_estrutura">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-database-check"></i> Ativar gestão da frota
                            </button>
                        </form>
                    <?php endif; ?>
                    <details>
                        <summary>Ver SQL para instalação manual</summary>
                        <pre><?= htmlspecialchars($sqlFrota) ?></pre>
                    </details>
                </section>
            <?php else: ?>
                <section class="frota-resumo" aria-label="Resumo da frota">
                    <div class="frota-metrica metrica-veiculos">
                        <span>Veículos ativos</span>
                        <strong><?= (int)$resumo['veiculos_ativos'] ?></strong>
                    </div>
                    <div class="frota-metrica metrica-vencidos">
                        <span>Documentos pendentes</span>
                        <strong><?= (int)$resumo['documentos_pendentes'] ?></strong>
                    </div>
                    <div class="frota-metrica metrica-proximos">
                        <span>Boletos não enviados</span>
                        <strong><?= (int)$resumo['boletos_pendentes'] ?></strong>
                    </div>
                    <div class="frota-metrica metrica-multas">
                        <span>Multas pendentes</span>
                        <strong><?= (int)$resumo['multas_pendentes'] ?></strong>
                    </div>
                </section>

                <nav class="frota-abas" aria-label="Seções da frota">
                    <a href="frota.php?aba=visao-geral&amp;ano=<?= $anoControle ?>" class="<?= $aba === 'visao-geral' ? 'ativo' : '' ?>">
                        <i class="bi bi-car-front"></i> Veículos
                    </a>
                    <a href="frota.php?aba=obrigacoes&amp;ano=<?= $anoControle ?>" class="<?= $aba === 'obrigacoes' ? 'ativo' : '' ?>">
                        <i class="bi bi-file-earmark-check"></i> Obrigações
                    </a>
                    <a href="frota.php?aba=multas&amp;ano=<?= $anoControle ?>" class="<?= $aba === 'multas' ? 'ativo' : '' ?>">
                        <i class="bi bi-sign-stop"></i> Multas
                    </a>
                </nav>

                <?php if ($aba === 'visao-geral'): ?>
                    <section class="frota-painel">
                        <form method="get" class="frota-filtros">
                            <input type="hidden" name="aba" value="visao-geral">
                            <div class="frota-busca">
                                <i class="bi bi-search"></i>
                                <input type="search" class="form-control" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar placa, modelo, RENAVAM ou responsável...">
                            </div>
                            <select name="ano" class="form-select" aria-label="Ano dos documentos">
                                <?php for ($anoOpcao = $anoAtual + 1; $anoOpcao >= $anoAtual - 5; $anoOpcao--): ?>
                                    <option value="<?= $anoOpcao ?>" <?= $anoOpcao === $anoControle ? 'selected' : '' ?>>Ano <?= $anoOpcao ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="situacao_veiculo" class="form-select" aria-label="Filtrar situação">
                                <option value="todos">Todas as situações</option>
                                <?php foreach ($situacoesVeiculo as $situacaoOpcao): ?>
                                    <option value="<?= htmlspecialchars($situacaoOpcao) ?>" <?= $situacaoVeiculoFiltro === $situacaoOpcao ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(frotaSituacaoVeiculo($situacaoOpcao)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                        </form>

                        <div class="table-responsive">
                            <table class="table align-middle frota-tabela">
                                <thead>
                                    <tr>
                                        <th>Placa</th>
                                        <th>Veículo</th>
                                        <th>RENAVAM</th>
                                        <th>Ano</th>
                                        <th>Responsável</th>
                                        <th>Situação</th>
                                        <th>Alertas</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($veiculos === []): ?>
                                        <tr>
                                            <td colspan="8" class="frota-vazio">Nenhum veículo encontrado.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($veiculos as $veiculo): ?>
                                        <?php $dadosVeiculo = htmlspecialchars(json_encode($veiculo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                                        <tr>
                                            <td><span class="frota-placa"><?= htmlspecialchars(frotaPlacaFormatada((string)$veiculo['placa'])) ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars($veiculo['marca'] . ' ' . $veiculo['modelo']) ?></strong>
                                                <?php if (!empty($veiculo['cor'])): ?><small><?= htmlspecialchars($veiculo['cor']) ?></small><?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($veiculo['renavam'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars(($veiculo['ano_fabricacao'] ?: '-') . ($veiculo['ano_modelo'] ? '/' . $veiculo['ano_modelo'] : '')) ?></td>
                                            <td><?= htmlspecialchars($veiculo['responsavel'] ?: '-') ?></td>
                                            <td><span class="badge text-bg-<?= $statusClasse((string)$veiculo['situacao']) ?>"><?= htmlspecialchars(frotaSituacaoVeiculo((string)$veiculo['situacao'])) ?></span></td>
                                            <td>
                                                <?php
                                                $pendenciasVeiculo = ((int)$veiculo['documento_emitido'] === 0 ? 1 : 0)
                                                    + ((int)$veiculo['boletos_enviados'] === 0 ? 1 : 0)
                                                    + ((int)$veiculo['possui_multas'] === 1 ? 1 : 0);
                                                ?>
                                                <?php if ($veiculo['situacao'] !== 'ativo'): ?>
                                                    <span class="badge text-bg-secondary">Fora de operação</span>
                                                <?php elseif ($pendenciasVeiculo > 0): ?>
                                                    <span class="badge text-bg-danger"><?= $pendenciasVeiculo ?> pendência<?= $pendenciasVeiculo === 1 ? '' : 's' ?> em <?= $anoControle ?></span>
                                                <?php else: ?>
                                                    <span class="text-success"><i class="bi bi-check-circle"></i> Em dia em <?= $anoControle ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end frota-acoes">
                                                <?php if ((int)($veiculo['documento_id'] ?? 0) > 0): ?>
                                                    <a href="frota_documento.php?id=<?= (int)$veiculo['documento_id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Abrir documento de <?= $anoControle ?>"><i class="bi bi-file-earmark-check"></i></a>
                                                <?php endif; ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-secondary btn-documento-veiculo"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalDocumentoVeiculo"
                                                    data-veiculo-id="<?= (int)$veiculo['id'] ?>"
                                                    data-veiculo-nome="<?= htmlspecialchars(frotaPlacaFormatada((string)$veiculo['placa']) . ' · ' . $veiculo['marca'] . ' ' . $veiculo['modelo'], ENT_QUOTES) ?>"
                                                    data-documento-nome="<?= htmlspecialchars((string)($veiculo['documento_nome'] ?? ''), ENT_QUOTES) ?>"
                                                    title="<?= (int)($veiculo['documento_id'] ?? 0) > 0 ? 'Substituir documento de ' . $anoControle : 'Anexar documento de ' . $anoControle ?>">
                                                    <i class="bi bi-paperclip"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-veiculo" data-bs-toggle="modal" data-bs-target="#modalVeiculo" data-registro="<?= $dadosVeiculo ?>" title="Editar veículo"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-registro" data-bs-toggle="modal" data-bs-target="#modalExcluirFrota" data-acao="excluir_veiculo" data-aba="visao-geral" data-id="<?= (int)$veiculo['id'] ?>" data-nome="<?= htmlspecialchars(frotaPlacaFormatada((string)$veiculo['placa']), ENT_QUOTES) ?>" title="Excluir veículo"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($aba === 'obrigacoes'): ?>
                    <section class="frota-painel">
                        <div class="frota-painel-titulo">
                            <div>
                                <h5>Documentos do ano</h5>
                                <p>Marque o que já foi concluído para cada veículo.</p>
                            </div>
                            <form method="get" class="frota-seletor-ano">
                                <input type="hidden" name="aba" value="obrigacoes">
                                <label for="anoObrigacoes">Ano</label>
                                <select class="form-select" name="ano" id="anoObrigacoes" onchange="this.form.submit()">
                                    <?php for ($anoOpcao = $anoAtual + 1; $anoOpcao >= $anoAtual - 5; $anoOpcao--): ?>
                                        <option value="<?= $anoOpcao ?>" <?= $anoOpcao === $anoControle ? 'selected' : '' ?>><?= $anoOpcao ?></option>
                                    <?php endfor; ?>
                                </select>
                            </form>
                        </div>
                        <form method="post" class="frota-acompanhamento-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>">
                            <input type="hidden" name="acao" value="salvar_controle_obrigacoes">
                            <input type="hidden" name="aba" value="obrigacoes">
                            <input type="hidden" name="ano" value="<?= $anoControle ?>">
                            <div class="table-responsive">
                                <table class="table align-middle frota-tabela frota-tabela-controle">
                                    <thead>
                                        <tr>
                                            <th>Veículo</th>
                                            <th>Tirou o documento de <?= $anoControle ?>?</th>
                                            <th>Mandou os boletos de IPVA e licenciamento?</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($controles === []): ?><tr>
                                                <td colspan="4" class="frota-vazio">Nenhum veículo disponível para acompanhamento.</td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($controles as $controle): ?>
                                            <?php $pendenteDocumento = (int)$controle['documento_emitido'] === 0 || (int)$controle['boletos_enviados'] === 0; ?>
                                            <tr class="frota-linha-controle <?= $pendenteDocumento ? 'tem-pendencia' : '' ?>" data-tipo-controle="obrigacoes">
                                                <td>
                                                    <input type="hidden" name="veiculos[]" value="<?= (int)$controle['id'] ?>">
                                                    <span class="frota-placa frota-placa-menor"><?= htmlspecialchars(frotaPlacaFormatada((string)$controle['placa'])) ?></span>
                                                    <strong><?= htmlspecialchars($controle['marca'] . ' ' . $controle['modelo']) ?></strong>
                                                </td>
                                                <td>
                                                    <div class="form-check form-switch frota-controle-chave">
                                                        <input class="form-check-input frota-switch-documento" type="checkbox" role="switch" name="documento_emitido[<?= (int)$controle['id'] ?>]" id="documento<?= (int)$controle['id'] ?>" value="1" <?= (int)$controle['documento_emitido'] === 1 ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="documento<?= (int)$controle['id'] ?>"><?= (int)$controle['documento_emitido'] === 1 ? 'Sim, concluído' : 'Não, pendente' ?></label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="form-check form-switch frota-controle-chave">
                                                        <input class="form-check-input frota-switch-boletos" type="checkbox" role="switch" name="boletos_enviados[<?= (int)$controle['id'] ?>]" id="boletos<?= (int)$controle['id'] ?>" value="1" <?= (int)$controle['boletos_enviados'] === 1 ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="boletos<?= (int)$controle['id'] ?>"><?= (int)$controle['boletos_enviados'] === 1 ? 'Sim, enviados' : 'Não, pendente' ?></label>
                                                    </div>
                                                </td>
                                                <td><span class="badge frota-status-controle <?= $pendenteDocumento ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $pendenteDocumento ? 'Com pendência' : 'Em dia' ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="frota-salvar-controle"><button type="submit" class="btn btn-primary" <?= $controles === [] ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i> Salvar acompanhamento</button></div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($aba === 'multas'): ?>
                    <section class="frota-painel">
                        <div class="frota-painel-titulo">
                            <div>
                                <h5>Controle de multas</h5>
                                <p>Informe se o veículo possui multas e a quantidade existente.</p>
                            </div>
                            <form method="get" class="frota-seletor-ano">
                                <input type="hidden" name="aba" value="multas">
                                <label for="anoMultas">Ano</label>
                                <select class="form-select" name="ano" id="anoMultas" onchange="this.form.submit()">
                                    <?php for ($anoOpcao = $anoAtual + 1; $anoOpcao >= $anoAtual - 5; $anoOpcao--): ?>
                                        <option value="<?= $anoOpcao ?>" <?= $anoOpcao === $anoControle ? 'selected' : '' ?>><?= $anoOpcao ?></option>
                                    <?php endfor; ?>
                                </select>
                            </form>
                        </div>
                        <form method="post" class="frota-acompanhamento-form frota-form-multas" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>">
                            <input type="hidden" name="acao" value="salvar_controle_multas">
                            <input type="hidden" name="aba" value="multas">
                            <input type="hidden" name="ano" value="<?= $anoControle ?>">
                            <div class="table-responsive">
                                <table class="table align-middle frota-tabela frota-tabela-controle">
                                    <thead>
                                        <tr>
                                            <th>Veículo</th>
                                            <th>Tem multas?</th>
                                            <th>Quantidade</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($controles === []): ?><tr>
                                                <td colspan="4" class="frota-vazio">Nenhum veículo disponível para acompanhamento.</td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($controles as $controle): ?>
                                            <?php $temMulta = (int)$controle['possui_multas'] === 1; ?>
                                            <tr class="frota-linha-controle <?= $temMulta ? 'tem-pendencia' : '' ?>" data-tipo-controle="multas">
                                                <td>
                                                    <input type="hidden" name="veiculos[]" value="<?= (int)$controle['id'] ?>">
                                                    <span class="frota-placa frota-placa-menor"><?= htmlspecialchars(frotaPlacaFormatada((string)$controle['placa'])) ?></span>
                                                    <strong><?= htmlspecialchars($controle['marca'] . ' ' . $controle['modelo']) ?></strong>
                                                </td>
                                                <td>
                                                    <div class="form-check form-switch frota-controle-chave">
                                                        <input class="form-check-input frota-switch-multas" type="checkbox" role="switch" name="possui_multas[<?= (int)$controle['id'] ?>]" id="multas<?= (int)$controle['id'] ?>" value="1" <?= $temMulta ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="multas<?= (int)$controle['id'] ?>"><?= $temMulta ? 'Sim' : 'Não' ?></label>
                                                    </div>
                                                </td>
                                                <td><input type="number" class="form-control frota-quantidade-multas" name="quantidade_multas[<?= (int)$controle['id'] ?>]" min="1" max="9999" value="<?= $temMulta ? max(1, (int)$controle['quantidade_multas']) : 0 ?>" <?= $temMulta ? '' : 'disabled' ?> aria-label="Quantidade de multas"></td>
                                                <td><span class="badge frota-status-controle <?= $temMulta ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $temMulta ? ((int)$controle['quantidade_multas'] . ' multa' . ((int)$controle['quantidade_multas'] === 1 ? '' : 's') . ' pendente' . ((int)$controle['quantidade_multas'] === 1 ? '' : 's')) : 'Sem multas' ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="frota-salvar-controle"><button type="submit" class="btn btn-primary" <?= $controles === [] ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i> Salvar acompanhamento</button></div>
                        </form>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalVeiculo" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" id="formVeiculo" class="frota-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" value="salvar_veiculo"><input type="hidden" name="aba" value="visao-geral"><input type="hidden" name="id" id="veiculoId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalVeiculoTitulo">Novo veículo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="veiculoPlaca">Placa</label><input type="text" class="form-control text-uppercase" name="placa" id="veiculoPlaca" maxlength="8" pattern="[A-Za-z]{3}-?[0-9][A-Za-z0-9][0-9]{2}" placeholder="ABC-1D23" required>
                                    <div class="invalid-feedback">Informe uma placa válida.</div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="veiculoRenavam">RENAVAM</label><input type="text" inputmode="numeric" class="form-control" name="renavam" id="veiculoRenavam" minlength="9" maxlength="11" pattern="[0-9]{9,11}" required>
                                    <div class="invalid-feedback">Informe um RENAVAM válido.</div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="veiculoSituacao">Situação</label><select class="form-select" name="situacao" id="veiculoSituacao"><?php foreach ($situacoesVeiculo as $situacaoOpcao): ?><option value="<?= htmlspecialchars($situacaoOpcao) ?>"><?= htmlspecialchars(frotaSituacaoVeiculo($situacaoOpcao)) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label" for="veiculoMarca">Marca</label><input type="text" class="form-control" name="marca" id="veiculoMarca" maxlength="80" required>
                                    <div class="invalid-feedback">Informe a marca.</div>
                                </div>
                                <div class="col-md-8"><label class="form-label" for="veiculoModelo">Modelo</label><input type="text" class="form-control" name="modelo" id="veiculoModelo" maxlength="120" required>
                                    <div class="invalid-feedback">Informe o modelo.</div>
                                </div>
                                <div class="col-md-3"><label class="form-label" for="veiculoAnoFabricacao">Ano fabricação</label><input type="number" class="form-control" name="ano_fabricacao" id="veiculoAnoFabricacao" min="1900" max="<?= date('Y') + 1 ?>" required>
                                    <div class="invalid-feedback">Informe o ano de fabricação.</div>
                                </div>
                                <div class="col-md-3"><label class="form-label" for="veiculoAnoModelo">Ano modelo</label><input type="number" class="form-control" name="ano_modelo" id="veiculoAnoModelo" min="1900" max="<?= date('Y') + 1 ?>" required>
                                    <div class="invalid-feedback">Informe o ano do modelo.</div>
                                </div>
                                <div class="col-md-6"><label class="form-label" for="veiculoCor">Cor</label><input type="text" class="form-control" name="cor" id="veiculoCor" maxlength="50"></div>
                                <div class="col-12"><label class="form-label" for="veiculoResponsavel">Responsável pelo veículo</label><input type="text" class="form-control" name="responsavel" id="veiculoResponsavel" maxlength="150"></div>
                                <div class="col-12"><label class="form-label" for="veiculoObservacoes">Observações</label><textarea class="form-control" name="observacoes" id="veiculoObservacoes" rows="3"></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar veículo</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalDocumentoVeiculo" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" enctype="multipart/form-data" id="formDocumentoVeiculo" class="frota-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>">
                        <input type="hidden" name="acao" value="enviar_documento_veiculo">
                        <input type="hidden" name="aba" value="visao-geral">
                        <input type="hidden" name="ano" value="<?= $anoControle ?>">
                        <input type="hidden" name="veiculo_id" id="documentoVeiculoId">
                        <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Documento do veículo</h5>
                                <p class="text-muted mb-0" id="documentoVeiculoNome"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="frota-documento-ano"><i class="bi bi-calendar3"></i><span>Documento de <strong><?= $anoControle ?></strong></span></div>
                            <div class="alert alert-info py-2 d-none" id="documentoVeiculoAtual"></div>
                            <label class="form-label" for="documentoVeiculoArquivo">Arquivo</label>
                            <input type="file" class="form-control" name="documento_veiculo" id="documentoVeiculoArquivo" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                            <div class="invalid-feedback">Escolha um arquivo PDF, JPG ou PNG.</div>
                            <small class="text-muted d-block mt-2">Tamanho máximo: 10 MB.</small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Salvar documento</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirFrota" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" id="excluirFrotaAcao"><input type="hidden" name="aba" id="excluirFrotaAba"><input type="hidden" name="id" id="excluirFrotaId">
                        <div class="modal-header">
                            <h5 class="modal-title">Confirmar exclusão</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">Deseja excluir <strong id="excluirFrotaNome">este registro</strong>?</p>
                            <div class="alert alert-danger mb-0" id="excluirFrotaAviso">Esta ação não poderá ser desfeita.</div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Excluir</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= assetUrl('assets/frota.js') ?>"></script>
</body>

</html>