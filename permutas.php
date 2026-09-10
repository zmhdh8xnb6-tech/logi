<?php
require 'config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

exigirPermissao('outros_servicos');

function permutasRedirecionar(string $mensagem, string $tipo, string $competencia, array $dados = []): never
{
    $_SESSION['permutas_flash'] = array_merge([
        'mensagem' => $mensagem,
        'tipo' => in_array($tipo, ['success', 'warning', 'danger', 'info'], true) ? $tipo : 'info',
    ], $dados);
    header('Location: permutas.php?' . http_build_query(['competencia' => permutasCompetenciaNormalizar($competencia)]));
    exit;
}

$competencia = permutasCompetenciaNormalizar($_GET['competencia'] ?? $_POST['competencia'] ?? null);
$empresaId = permutasEmpresaId($pdo);
$empresaNome = empresaAtivaNome($pdo);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$usuarioNome = trim((string)($_SESSION['usuario_nome'] ?? ''));
$tabelasPermutas = ['permutas_competencias', 'permutas_itens', 'permutas_anexos', 'permutas_envios'];
$estruturaDisponivel = true;

foreach ($tabelasPermutas as $tabelaPermuta) {
    if (!logiTabelaExiste($pdo, $tabelaPermuta)) {
        $estruturaDisponivel = false;
        break;
    }
}
$multiplosAnexosDisponiveis = $estruturaDisponivel
    ? permutasHabilitarMultiplosAnexos($pdo)
    : false;
$destinatariosPadraoDisponiveis = $estruturaDisponivel
    ? permutasHabilitarDestinatariosPadrao($pdo)
    : false;
$statusEnvioNormalizado = true;
if ($estruturaDisponivel) {
    try {
        permutasNormalizarStatusEnvio($pdo);
    } catch (Throwable $e) {
        $statusEnvioNormalizado = false;
    }
}
$compartilhamentoDisponivel = $estruturaDisponivel
    ? permutasHabilitarCompartilhamentos($authPdo)
    : false;

$sqlPermutas = (string)@file_get_contents(__DIR__ . '/sql/permutas.sql');
$flash = $_SESSION['permutas_flash'] ?? null;
unset($_SESSION['permutas_flash']);

if (
    !$estruturaDisponivel
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'instalar_estrutura'
) {
    if (!usuarioEhAdmin()) {
        permutasRedirecionar('Somente um administrador pode ativar a estrutura de permutas.', 'danger', $competencia);
    }

    if (!permutasTokenValido($_POST['csrf_token'] ?? null)) {
        permutasRedirecionar('A sessão do formulário expirou. Tente novamente.', 'danger', $competencia);
    }

    try {
        $comandos = preg_split('/;\s*(?:\r?\n|$)/', trim($sqlPermutas)) ?: [];
        foreach ($comandos as $comando) {
            if (trim($comando) !== '') {
                $pdo->exec($comando);
            }
        }
        permutasRedirecionar('Controle de permutas ativado com sucesso.', 'success', $competencia);
    } catch (Throwable $e) {
        permutasRedirecionar('Não foi possível criar as tabelas. Confira o acesso ao banco ou execute o SQL mostrado na página.', 'danger', $competencia);
    }
}

if ($estruturaDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!permutasTokenValido($_POST['csrf_token'] ?? null)) {
        permutasRedirecionar('A sessão do formulário expirou. Tente novamente.', 'danger', $competencia);
    }

    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar_item') {
            $id = (int)($_POST['id'] ?? 0);
            $dataRetirada = $competencia . '-01';
            $quantidade = permutasNumero($_POST['quantidade'] ?? '');
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $destino = trim((string)($_POST['destino'] ?? ''));
            $valorUnitario = permutasNumero($_POST['valor_unitario'] ?? '');
            $observacao = trim((string)($_POST['observacao'] ?? ''));
            if ($quantidade <= 0 || $quantidade > 99999) {
                throw new RuntimeException('Informe uma quantidade válida.');
            }
            if ($descricao === '' || mb_strlen($descricao) > 255) {
                throw new RuntimeException('Informe a descrição do item com até 255 caracteres.');
            }
            if ($valorUnitario <= 0 || $valorUnitario > 9999999999) {
                throw new RuntimeException('Informe um valor unitário válido.');
            }

            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, true);
            if (!$competenciaRegistro) {
                throw new RuntimeException('Não foi possível abrir a competência selecionada.');
            }

            $antes = $id > 0 ? permutasBuscarItem($pdo, $id) : null;
            if ($id > 0 && (!$antes || (int)$antes['competencia_id'] !== (int)$competenciaRegistro['id'])) {
                throw new RuntimeException('Item não encontrado nesta competência.');
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare('
                    UPDATE permutas_itens
                    SET quantidade = ?, descricao = ?, destino = ?,
                        valor_unitario = ?, observacao = ?, atualizado_em = NOW()
                    WHERE id = ? AND empresa_id = ? AND competencia_id = ?
                ');
                $stmt->execute([
                    $quantidade,
                    $descricao,
                    $destino !== '' ? $destino : null,
                    $valorUnitario,
                    $observacao !== '' ? $observacao : null,
                    $id,
                    $empresaId,
                    (int)$competenciaRegistro['id'],
                ]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO permutas_itens
                        (empresa_id, competencia_id, data_retirada, quantidade, descricao, destino, valor_unitario, observacao)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $empresaId,
                    (int)$competenciaRegistro['id'],
                    $dataRetirada,
                    $quantidade,
                    $descricao,
                    $destino !== '' ? $destino : null,
                    $valorUnitario,
                    $observacao !== '' ? $observacao : null,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            permutasMarcarAlterada($pdo, (int)$competenciaRegistro['id']);
            $depois = [
                'quantidade' => $quantidade,
                'descricao' => $descricao,
                'destino' => $destino,
                'valor_unitario' => $valorUnitario,
                'observacao' => $observacao,
            ];
            $mudancas = auditoriaMudancas($antes, $depois);
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                $antes ? 'editar_permuta' : 'cadastrar_permuta',
                'permuta_item',
                $id,
                ($antes ? 'Alterou' : 'Cadastrou') . ' item da permuta DF Cartuchos: ' . $descricao,
                $antes ? $mudancas['antes'] : null,
                $antes ? $mudancas['depois'] : $depois
            );
            $pdo->commit();
            permutasRedirecionar($antes ? 'Item atualizado com sucesso.' : 'Item adicionado com sucesso.', 'success', $competencia);
        }

        if ($acao === 'excluir_item') {
            $id = (int)($_POST['id'] ?? 0);
            $item = permutasBuscarItem($pdo, $id);
            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);

            if (!$item || !$competenciaRegistro || (int)$item['competencia_id'] !== (int)$competenciaRegistro['id']) {
                throw new RuntimeException('Item não encontrado nesta competência.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM permutas_itens WHERE id = ? AND empresa_id = ? AND competencia_id = ?');
            $stmt->execute([$id, $empresaId, (int)$competenciaRegistro['id']]);
            permutasMarcarAlterada($pdo, (int)$competenciaRegistro['id']);
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'excluir_permuta',
                'permuta_item',
                $id,
                'Excluiu item da permuta DF Cartuchos: ' . $item['descricao'],
                $item,
                null
            );
            $pdo->commit();
            permutasRedirecionar('Item excluído com sucesso.', 'success', $competencia);
        }

        if ($acao === 'anexar_relatorio') {
            if (!$multiplosAnexosDisponiveis) {
                throw new RuntimeException('Não foi possível atualizar a estrutura para vários anexos. Entre como administrador e tente novamente.');
            }

            $arquivos = permutasNormalizarArquivosUpload(
                $_FILES['relatorios_digitalizados'] ?? $_FILES['relatorio_digitalizado'] ?? null
            );
            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, true);

            if (!$competenciaRegistro) {
                throw new RuntimeException('Não foi possível abrir a competência selecionada.');
            }
            if ($arquivos === []) {
                throw new RuntimeException('Escolha pelo menos um relatório digitalizado.');
            }
            if (count($arquivos) > 10) {
                throw new RuntimeException('Envie no máximo 10 arquivos por vez.');
            }

            $extensoesPermitidas = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ];
            $arquivosPreparados = [];
            foreach ($arquivos as $indice => $arquivo) {
                $erroUpload = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($erroUpload !== UPLOAD_ERR_OK) {
                    $mensagemUpload = match ($erroUpload) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo ' . ($indice + 1) . ' ultrapassa o limite permitido de 10 MB.',
                        UPLOAD_ERR_NO_FILE => 'Escolha pelo menos um relatório digitalizado.',
                        default => 'Não foi possível receber o arquivo ' . ($indice + 1) . '. Tente novamente.',
                    };
                    throw new RuntimeException($mensagemUpload);
                }

                $caminhoTemporario = (string)($arquivo['tmp_name'] ?? '');
                $tamanhoArquivo = (int)($arquivo['size'] ?? 0);
                if (!is_uploaded_file($caminhoTemporario) || $tamanhoArquivo <= 0 || $tamanhoArquivo > 10 * 1024 * 1024) {
                    throw new RuntimeException('Cada relatório deve possuir até 10 MB.');
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
                if (!isset($extensoesPermitidas[$tipoMime])) {
                    throw new RuntimeException('O arquivo ' . ($indice + 1) . ' deve estar em PDF, JPG ou PNG.');
                }

                $nomeOriginal = mb_substr(basename(str_replace(["\r", "\n"], '', (string)($arquivo['name'] ?? 'relatorio'))), 0, 255);
                if ($nomeOriginal === '') {
                    $nomeOriginal = 'relatorio-digitalizado-' . ($indice + 1) . '.' . $extensoesPermitidas[$tipoMime];
                }

                $arquivosPreparados[] = [
                    'temporario' => $caminhoTemporario,
                    'tamanho' => $tamanhoArquivo,
                    'tipo' => $tipoMime,
                    'extensao' => $extensoesPermitidas[$tipoMime],
                    'nome' => $nomeOriginal,
                ];
            }

            $diretorio = permutasAnexoPrepararDiretorio($empresaId, (int)$competenciaRegistro['id']);
            if ($diretorio === null) {
                throw new RuntimeException('Não foi possível acessar uma pasta gravável. No teste local, confira storage; no servidor, confira logi_storage.');
            }

            $arquivosSalvos = [];
            try {
                foreach ($arquivosPreparados as $arquivoPreparado) {
                    $nomeArmazenado = bin2hex(random_bytes(18)) . '.' . $arquivoPreparado['extensao'];
                    $caminhoRelativo = $diretorio['relativo'] . '/' . $nomeArmazenado;
                    $caminhoAbsoluto = $diretorio['absoluto'] . DIRECTORY_SEPARATOR . $nomeArmazenado;

                    if (!move_uploaded_file($arquivoPreparado['temporario'], $caminhoAbsoluto)) {
                        throw new RuntimeException('Falha ao mover o relatório ' . $arquivoPreparado['nome'] . '.');
                    }
                    @chmod($caminhoAbsoluto, 0640);
                    clearstatcache(true, $caminhoAbsoluto);
                    $tamanhoSalvo = filesize($caminhoAbsoluto);
                    if (
                        !is_file($caminhoAbsoluto)
                        || !is_readable($caminhoAbsoluto)
                        || $tamanhoSalvo === false
                        || $tamanhoSalvo !== $arquivoPreparado['tamanho']
                        || permutasAnexoCaminhoAbsoluto($caminhoRelativo) === null
                    ) {
                        throw new RuntimeException('O relatório ' . $arquivoPreparado['nome'] . ' não pôde ser validado no armazenamento.');
                    }

                    $arquivosSalvos[] = array_merge($arquivoPreparado, [
                        'relativo' => $caminhoRelativo,
                        'absoluto' => $caminhoAbsoluto,
                    ]);
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('
                    INSERT INTO permutas_anexos
                        (empresa_id, competencia_id, nome_original, caminho_arquivo, tipo_mime, tamanho_bytes, usuario_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                foreach ($arquivosSalvos as $arquivoSalvo) {
                    $stmt->execute([
                        $empresaId,
                        (int)$competenciaRegistro['id'],
                        $arquivoSalvo['nome'],
                        $arquivoSalvo['relativo'],
                        $arquivoSalvo['tipo'],
                        $arquivoSalvo['tamanho'],
                        $usuarioId ?: null,
                    ]);
                }
                permutasMarcarAlterada($pdo, (int)$competenciaRegistro['id']);
                registrarAuditoria(
                    $pdo,
                    'Outros Serviços',
                    'anexar_relatorio_permuta',
                    'permuta_competencia',
                    (int)$competenciaRegistro['id'],
                    'Anexou ' . count($arquivosSalvos) . ' arquivo(s) digitalizado(s) da permuta de ' . permutasCompetenciaRotulo($competencia),
                    null,
                    ['arquivos' => array_column($arquivosSalvos, 'nome')]
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($arquivosSalvos as $arquivoSalvo) {
                    if (is_file($arquivoSalvo['absoluto'])) {
                        @unlink($arquivoSalvo['absoluto']);
                    }
                }
                throw $e;
            }

            $quantidadeAnexada = count($arquivosSalvos);
            permutasRedirecionar(
                $quantidadeAnexada === 1
                    ? 'Relatório digitalizado anexado com sucesso.'
                    : $quantidadeAnexada . ' relatórios digitalizados anexados com sucesso.',
                'success',
                $competencia
            );
        }

        if ($acao === 'excluir_relatorio') {
            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);
            $anexoId = (int)($_POST['anexo_id'] ?? 0);
            $anexo = $competenciaRegistro
                ? permutasBuscarAnexoPorId($pdo, $anexoId, (int)$competenciaRegistro['id'])
                : null;

            if (!$anexo) {
                throw new RuntimeException('Relatório digitalizado não encontrado nesta competência.');
            }

            $caminhoArquivo = permutasAnexoCaminhoAbsoluto((string)$anexo['caminho_arquivo']);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM permutas_anexos WHERE id = ? AND empresa_id = ? AND competencia_id = ?');
            $stmt->execute([$anexoId, $empresaId, (int)$competenciaRegistro['id']]);
            permutasMarcarAlterada($pdo, (int)$competenciaRegistro['id']);
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'excluir_relatorio_permuta',
                'permuta_competencia',
                (int)$competenciaRegistro['id'],
                'Excluiu o relatório digitalizado da permuta de ' . permutasCompetenciaRotulo($competencia),
                $anexo,
                null
            );
            $pdo->commit();

            $arquivoRemovido = $caminhoArquivo === null || @unlink($caminhoArquivo);
            permutasRedirecionar(
                $arquivoRemovido ? 'Relatório digitalizado excluído.' : 'O registro foi excluído, mas o arquivo não pôde ser removido do servidor.',
                $arquivoRemovido ? 'success' : 'warning',
                $competencia
            );
        }

        if ($acao === 'criar_compartilhamento') {
            if (!$compartilhamentoDisponivel) {
                throw new RuntimeException('Não foi possível ativar os links públicos. Confira a permissão de criação de tabelas no banco.');
            }

            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);
            $itensCompartilhados = permutasBuscarItens($pdo, (int)($competenciaRegistro['id'] ?? 0));
            if (!$competenciaRegistro || $itensCompartilhados === []) {
                throw new RuntimeException('Adicione pelo menos um item antes de criar o link.');
            }

            $anexosCompartilhados = permutasBuscarAnexos($pdo, (int)$competenciaRegistro['id']);
            $origemChave = permutasCompartilhamentoOrigemChave($db, $empresaId, (int)$competenciaRegistro['id']);
            $snapshot = permutasCompartilhamentoSnapshot(
                $competenciaRegistro,
                $itensCompartilhados,
                $anexosCompartilhados,
                $empresaNome,
                $usuarioNome
            );
            $compartilhamento = permutasCriarCompartilhamento(
                $authPdo,
                $origemChave,
                $empresaId,
                (int)$competenciaRegistro['id'],
                $snapshot,
                $usuarioId,
                (int)($_POST['dias_validade'] ?? 30)
            );
            $urlCompartilhamento = permutasUrlCompartilhamento($compartilhamento['token'], $baseUrl);
            $_SESSION['permutas_links'][$origemChave] = [
                'id' => (int)$compartilhamento['id'],
                'url' => $urlCompartilhamento,
                'expira_em' => (string)$compartilhamento['expira_em'],
            ];

            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'compartilhar_permuta',
                'permuta_competencia',
                (int)$competenciaRegistro['id'],
                'Criou link somente leitura da permuta de ' . permutasCompetenciaRotulo($competencia),
                null,
                ['expira_em' => $compartilhamento['expira_em']]
            );
            permutasRedirecionar(
                'Link de visualização criado. Ele não permite nenhuma alteração.',
                'success',
                $competencia,
                ['destacar_link' => true]
            );
        }

        if ($acao === 'revogar_compartilhamento') {
            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);
            if (!$competenciaRegistro || !$compartilhamentoDisponivel) {
                throw new RuntimeException('Nenhum link ativo foi encontrado para esta competência.');
            }

            $origemChave = permutasCompartilhamentoOrigemChave($db, $empresaId, (int)$competenciaRegistro['id']);
            $revogados = permutasRevogarCompartilhamentos($authPdo, $origemChave);
            unset($_SESSION['permutas_links'][$origemChave]);
            if ($revogados === 0) {
                throw new RuntimeException('Nenhum link ativo foi encontrado para esta competência.');
            }

            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'revogar_compartilhamento_permuta',
                'permuta_competencia',
                (int)$competenciaRegistro['id'],
                'Revogou o link público da permuta de ' . permutasCompetenciaRotulo($competencia)
            );
            permutasRedirecionar('O link público foi revogado e não pode mais ser aberto.', 'success', $competencia);
        }

        if ($acao === 'enviar_email') {
            require_once __DIR__ . '/mailer.php';

            $destinatarios = permutasEmailsNormalizar($_POST['destinatarios'] ?? '');
            $destinatariosTexto = permutasEmailsTexto($destinatarios);
            $salvarDestinatariosPadrao = isset($_POST['salvar_destinatarios_padrao']);
            $assunto = trim((string)($_POST['assunto'] ?? ''));
            $mensagemEmail = trim((string)($_POST['mensagem_email'] ?? ''));
            $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);

            if ($destinatarios === []) {
                throw new RuntimeException('Informe pelo menos um e-mail válido para o financeiro.');
            }
            if (count($destinatarios) > 1 && !$destinatariosPadraoDisponiveis) {
                throw new RuntimeException('Não foi possível ativar o envio para vários e-mails. Confira a permissão de alteração do banco de dados.');
            }
            if ($assunto === '' || mb_strlen($assunto) > 255) {
                throw new RuntimeException('Informe o assunto do e-mail.');
            }
            if ($mensagemEmail === '') {
                throw new RuntimeException('Escreva a mensagem que acompanhará o relatório.');
            }
            if (!$competenciaRegistro) {
                throw new RuntimeException('Esta competência ainda não possui itens.');
            }

            $itensEmail = permutasBuscarItens($pdo, (int)$competenciaRegistro['id']);
            if ($itensEmail === []) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar o relatório.');
            }
            if (!$compartilhamentoDisponivel) {
                throw new RuntimeException('Não foi possível preparar o link de visualização. Confira a permissão de criação de tabelas no banco.');
            }

            $totalEmail = permutasTotal($itensEmail);
            $pdfConteudo = permutasGerarPdf($competenciaRegistro, $itensEmail, $empresaNome, $usuarioNome);
            $anexosEmail = [[
                'conteudo' => $pdfConteudo,
                'nome' => permutasNomeArquivo($competenciaRegistro),
                'tipo' => 'application/pdf',
            ]];
            $anexosDigitalizadosEmail = permutasBuscarAnexos($pdo, (int)$competenciaRegistro['id']);
            foreach ($anexosDigitalizadosEmail as $anexoDigitalizadoEmail) {
                $caminhoDigitalizado = permutasAnexoCaminhoAbsoluto((string)$anexoDigitalizadoEmail['caminho_arquivo']);
                if ($caminhoDigitalizado === null) {
                    throw new RuntimeException('O arquivo ' . $anexoDigitalizadoEmail['nome_original'] . ' não foi localizado. Exclua o registro ou anexe-o novamente antes de enviar.');
                }
                $anexosEmail[] = [
                    'caminho' => $caminhoDigitalizado,
                    'nome' => (string)$anexoDigitalizadoEmail['nome_original'],
                    'tipo' => (string)$anexoDigitalizadoEmail['tipo_mime'],
                ];
            }

            $origemChave = permutasCompartilhamentoOrigemChave($db, $empresaId, (int)$competenciaRegistro['id']);
            $snapshot = permutasCompartilhamentoSnapshot(
                $competenciaRegistro,
                $itensEmail,
                $anexosDigitalizadosEmail,
                $empresaNome,
                $usuarioNome
            );
            $compartilhamentoEmail = permutasCriarCompartilhamento(
                $authPdo,
                $origemChave,
                $empresaId,
                (int)$competenciaRegistro['id'],
                $snapshot,
                $usuarioId,
                30
            );
            $urlCompartilhamentoEmail = permutasUrlCompartilhamento($compartilhamentoEmail['token'], $baseUrl);
            $erroEmail = null;
            $enviado = enviarEmailComAnexos(
                $destinatarios,
                'Financeiro',
                $assunto,
                permutasCorpoEmail(
                    $mensagemEmail,
                    $competenciaRegistro,
                    $totalEmail,
                    $urlCompartilhamentoEmail,
                    (string)$compartilhamentoEmail['expira_em']
                ),
                $anexosEmail,
                $erroEmail
            );

            if (!$enviado) {
                permutasRevogarCompartilhamentoPorId($authPdo, (int)$compartilhamentoEmail['id']);
                throw new RuntimeException('O servidor de e-mail não confirmou o envio. ' . trim((string)$erroEmail));
            }

            $_SESSION['permutas_links'][$origemChave] = [
                'id' => (int)$compartilhamentoEmail['id'],
                'url' => $urlCompartilhamentoEmail,
                'expira_em' => (string)$compartilhamentoEmail['expira_em'],
            ];

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE permutas_competencias
                SET status = 'enviado', email_destinatario = ?, assunto_email = ?,
                    enviado_em = NOW(), enviado_por = ?, atualizado_em = NOW()
                WHERE id = ? AND empresa_id = ?
            ");
            $stmt->execute([$destinatariosTexto, $assunto, $usuarioId ?: null, (int)$competenciaRegistro['id'], $empresaId]);

            $stmt = $pdo->prepare('
                INSERT INTO permutas_envios
                    (empresa_id, competencia_id, destinatario, assunto, total, enviado_por, enviado_por_nome)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $empresaId,
                (int)$competenciaRegistro['id'],
                $destinatariosTexto,
                $assunto,
                $totalEmail,
                $usuarioId ?: null,
                $usuarioNome !== '' ? $usuarioNome : null,
            ]);
            if ($salvarDestinatariosPadrao && $destinatariosPadraoDisponiveis) {
                permutasSalvarDestinatariosPadrao($pdo, $destinatarios);
            }
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'enviar_permuta',
                'permuta_competencia',
                (int)$competenciaRegistro['id'],
                'Enviou por e-mail a permuta DF Cartuchos de ' . permutasCompetenciaRotulo($competencia),
                null,
                ['destinatarios' => $destinatarios, 'total' => $totalEmail]
            );
            $pdo->commit();
            $quantidadeDestinatarios = count($destinatarios);
            permutasRedirecionar(
                'Relatório enviado para ' . $quantidadeDestinatarios
                    . ($quantidadeDestinatarios === 1 ? ' destinatário' : ' destinatários')
                    . ' com o PDF e o link de visualização válido por 30 dias.',
                'success',
                $competencia
            );
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        permutasRedirecionar($e->getMessage(), 'danger', $competencia);
    }
}

$competenciaRegistro = null;
$itens = [];
$competenciasRecentes = [];
$envios = [];
$ultimoDestinatario = '';
$destinatariosPadrao = [];
$itensDisponiveis = permutasItensPadrao();
$anexosDigitalizados = [];
$compartilhamentoAtivo = null;
$linkCompartilhamentoSessao = null;
$origemCompartilhamento = '';

if ($estruturaDisponivel) {
    $competenciaRegistro = permutasBuscarCompetencia($pdo, $competencia, false);
    $itens = permutasBuscarItens($pdo, (int)($competenciaRegistro['id'] ?? 0));
    $itensDisponiveis = permutasItensDisponiveis($pdo);
    $anexosDigitalizados = permutasBuscarAnexos($pdo, (int)($competenciaRegistro['id'] ?? 0));
    $competenciasRecentes = permutasBuscarCompetenciasRecentes($pdo);
    $envios = permutasBuscarEnvios($pdo, (int)($competenciaRegistro['id'] ?? 0));
    if ($destinatariosPadraoDisponiveis) {
        $destinatariosPadrao = permutasDestinatariosPadrao($pdo);
    }
    if ($empresaId === 1) {
        $ultimoDestinatario = permutasEmailsTexto(permutasDestinatariosIniciaisFecon());
    } elseif ($destinatariosPadrao !== []) {
        $ultimoDestinatario = permutasEmailsTexto($destinatariosPadrao);
    } else {
        $ultimoDestinatario = trim((string)($competenciaRegistro['email_destinatario'] ?? '')) ?: permutasUltimoDestinatario($pdo);
    }

    if ($compartilhamentoDisponivel && $competenciaRegistro) {
        $origemCompartilhamento = permutasCompartilhamentoOrigemChave($db, $empresaId, (int)$competenciaRegistro['id']);
        $compartilhamentoAtivo = permutasBuscarCompartilhamentoAtivo($authPdo, $origemCompartilhamento);
        $linkSessao = $_SESSION['permutas_links'][$origemCompartilhamento] ?? null;
        if (
            is_array($linkSessao)
            && $compartilhamentoAtivo
            && (int)($linkSessao['id'] ?? 0) === (int)$compartilhamentoAtivo['id']
            && filter_var($linkSessao['url'] ?? '', FILTER_VALIDATE_URL)
        ) {
            $linkCompartilhamentoSessao = $linkSessao;
        } elseif (isset($_SESSION['permutas_links'][$origemCompartilhamento])) {
            unset($_SESSION['permutas_links'][$origemCompartilhamento]);
        }
    }
}

$total = permutasTotal($itens);
$status = (string)($competenciaRegistro['status'] ?? 'em_preenchimento');
$dataCompetencia = DateTime::createFromFormat('!Y-m', $competencia) ?: new DateTime('first day of this month');
$competenciaAnterior = (clone $dataCompetencia)->modify('-1 month')->format('Y-m');
$competenciaSeguinte = (clone $dataCompetencia)->modify('+1 month')->format('Y-m');
$urlPdfPermuta = 'permutas_pdf.php?' . http_build_query(['competencia' => $competencia]);
$assuntoPadrao = 'Permuta DF Cartuchos - ' . permutasCompetenciaRotulo($competencia);
$mensagemPadrao = "Olá,\n\nSegue o relatório dos itens retirados por permuta na competência "
    . permutasCompetenciaRotulo($competencia)
    . ', totalizando ' . permutasMoeda($total) . ".\n\nAtenciosamente,";
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Permutas DF Cartuchos</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/permutas.css') ?>">
</head>

<body class="app-layout permutas-page">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Permutas DF Cartuchos</h3>
                    <p class="text-muted mb-0">Controle mensal das retiradas e do envio ao financeiro</p>
                </div>
                <a href="outros_servicos.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (is_array($flash)): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?> alert-dismissible alert-auto-dismiss fade show" role="alert">
                    <?= htmlspecialchars($flash['mensagem']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?php if ($estruturaDisponivel && !$multiplosAnexosDisponiveis): ?>
                <div class="alert alert-danger" role="alert">
                    Não foi possível atualizar a estrutura para vários anexos. Confira a permissão de alteração do banco de dados.
                </div>
            <?php endif; ?>

            <?php if ($estruturaDisponivel && !$destinatariosPadraoDisponiveis): ?>
                <div class="alert alert-danger" role="alert">
                    Não foi possível ativar os vários destinatários. Confira a permissão de alteração do banco de dados.
                </div>
            <?php endif; ?>

            <?php if ($estruturaDisponivel && !$compartilhamentoDisponivel): ?>
                <div class="alert alert-danger" role="alert">
                    Não foi possível ativar os links públicos. Confira a permissão de criação de tabelas no banco de dados.
                </div>
            <?php endif; ?>

            <?php if ($estruturaDisponivel && !$statusEnvioNormalizado): ?>
                <div class="alert alert-danger" role="alert">
                    Não foi possível atualizar as situações antigas das permutas. Confira a permissão de alteração do banco de dados.
                </div>
            <?php endif; ?>

            <?php if (!$estruturaDisponivel): ?>
                <section class="permutas-instalacao">
                    <div>
                        <h5><i class="bi bi-database-add"></i> Ativar controle de permutas</h5>
                        <p>As tabelas do novo módulo ainda não existem neste banco de dados.</p>
                    </div>
                    <?php if (usuarioEhAdmin()): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                            <input type="hidden" name="acao" value="instalar_estrutura">
                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check2-circle"></i> Ativar agora
                            </button>
                        </form>
                    <?php endif; ?>
                </section>
                <details class="mt-3">
                    <summary>Ver SQL para instalação manual</summary>
                    <pre class="permutas-sql mt-2"><?= htmlspecialchars($sqlPermutas) ?></pre>
                </details>
            <?php else: ?>
                <div class="permutas-toolbar">
                    <div class="permutas-periodo">
                        <a href="?competencia=<?= htmlspecialchars($competenciaAnterior) ?>" class="btn btn-outline-secondary btn-icon" title="Competência anterior" aria-label="Competência anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <form method="get" id="formCompetenciaPermuta">
                            <label for="competenciaPermuta" class="visually-hidden">Competência</label>
                            <input type="month" class="form-control" name="competencia" id="competenciaPermuta" value="<?= htmlspecialchars($competencia) ?>">
                        </form>
                        <a href="?competencia=<?= htmlspecialchars($competenciaSeguinte) ?>" class="btn btn-outline-secondary btn-icon" title="Próxima competência" aria-label="Próxima competência">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($itens !== []): ?>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalPdfPermuta">
                                <i class="bi bi-file-earmark-pdf"></i> Abrir PDF
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEnviarPermuta">
                                <i class="bi bi-envelope-arrow-up"></i> Enviar ao financeiro
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCompartilharPermuta" <?= !$compartilhamentoDisponivel ? 'disabled' : '' ?>>
                                <i class="bi bi-link-45deg"></i> Compartilhar
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary" id="btnNovoItemPermuta" data-bs-toggle="modal" data-bs-target="#modalItemPermuta">
                            <i class="bi bi-plus-lg"></i> Adicionar item
                        </button>
                    </div>
                </div>

                <section class="permutas-anexo <?= $anexosDigitalizados !== [] ? 'tem-anexo' : '' ?>">
                    <div class="permutas-anexo-cabecalho">
                        <div class="permutas-anexo-info">
                            <i class="bi <?= $anexosDigitalizados !== [] ? 'bi-files' : 'bi-file-earmark-arrow-up' ?>"></i>
                            <span>
                                <strong>Relatórios digitalizados</strong>
                                <small><?= $anexosDigitalizados !== []
                                            ? count($anexosDigitalizados) . (count($anexosDigitalizados) === 1 ? ' arquivo anexado' : ' arquivos anexados')
                                            : 'Nenhum arquivo anexado nesta competência.' ?></small>
                            </span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAnexoPermuta" <?= !$multiplosAnexosDisponiveis ? 'disabled' : '' ?>>
                            <i class="bi bi-paperclip"></i> Anexar arquivos
                        </button>
                    </div>

                    <?php if ($anexosDigitalizados !== []): ?>
                        <div class="permutas-anexos-lista">
                            <?php foreach ($anexosDigitalizados as $anexoDigitalizado): ?>
                                <div class="permutas-anexo-item">
                                    <i class="bi <?= $anexoDigitalizado['tipo_mime'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' ?>"></i>
                                    <span>
                                        <strong title="<?= htmlspecialchars((string)$anexoDigitalizado['nome_original']) ?>"><?= htmlspecialchars((string)$anexoDigitalizado['nome_original']) ?></strong>
                                        <small><?= htmlspecialchars(number_format((int)$anexoDigitalizado['tamanho_bytes'] / 1024, 0, ',', '.')) ?> KB · <?= htmlspecialchars(permutasDataBr($anexoDigitalizado['enviado_em'], true)) ?></small>
                                    </span>
                                    <div class="permutas-anexo-acoes">
                                        <a href="permuta_anexo.php?id=<?= (int)$anexoDigitalizado['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success btn-icon" title="Abrir arquivo" aria-label="Abrir <?= htmlspecialchars((string)$anexoDigitalizado['nome_original']) ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger btn-icon btn-excluir-anexo-permuta"
                                            data-id="<?= (int)$anexoDigitalizado['id'] ?>"
                                            data-nome="<?= htmlspecialchars((string)$anexoDigitalizado['nome_original'], ENT_QUOTES, 'UTF-8') ?>"
                                            title="Excluir arquivo"
                                            aria-label="Excluir <?= htmlspecialchars((string)$anexoDigitalizado['nome_original']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($compartilhamentoAtivo): ?>
                    <section class="permutas-compartilhamento <?= !empty($flash['destacar_link']) ? 'destacado' : '' ?>" aria-label="Link público da competência">
                        <div class="permutas-compartilhamento-info">
                            <i class="bi bi-shield-check"></i>
                            <span>
                                <strong>Visualização somente leitura ativa</strong>
                                <small>Disponível até <?= htmlspecialchars(permutasDataBr($compartilhamentoAtivo['expira_em'], true)) ?>. A pessoa não precisa entrar no sistema e não pode alterar dados.</small>
                            </span>
                        </div>
                        <div class="permutas-compartilhamento-acoes">
                            <?php if ($linkCompartilhamentoSessao): ?>
                                <label class="visually-hidden" for="linkPublicoPermuta">Link público</label>
                                <input type="text" class="form-control" id="linkPublicoPermuta" value="<?= htmlspecialchars((string)$linkCompartilhamentoSessao['url']) ?>" readonly>
                                <button type="button" class="btn btn-outline-primary" id="btnCopiarLinkPermuta">
                                    <i class="bi bi-copy"></i> Copiar link
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCompartilharPermuta">
                                    <i class="bi bi-arrow-repeat"></i> Gerar novo link
                                </button>
                            <?php endif; ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                                <input type="hidden" name="acao" value="revogar_compartilhamento">
                                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-link-45deg"></i> Revogar
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="permutas-resumo" aria-label="Resumo da competência">
                    <div>
                        <span>Competência</span>
                        <strong><?= htmlspecialchars(permutasCompetenciaRotulo($competencia)) ?></strong>
                    </div>
                    <div>
                        <span>Itens registrados</span>
                        <strong><?= count($itens) ?></strong>
                    </div>
                    <div>
                        <span>Total do mês</span>
                        <strong class="permutas-total-destaque"><?= htmlspecialchars(permutasMoeda($total)) ?></strong>
                    </div>
                    <div>
                        <span>Situação</span>
                        <strong><span class="badge <?= permutasStatusClasse($status) ?>"><?= htmlspecialchars(permutasStatusRotulo($status)) ?></span></strong>
                    </div>
                    <?php if (!empty($competenciaRegistro['enviado_em'])): ?>
                        <div>
                            <span>Último envio</span>
                            <strong><?= htmlspecialchars(permutasDataBr($competenciaRegistro['enviado_em'], true)) ?></strong>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="permutas-lista">
                    <div class="permutas-secao-titulo">
                        <div>
                            <h5>Itens da competência</h5>
                            <p>O total é calculado pela quantidade multiplicada pelo valor unitário.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">Qtd.</th>
                                    <th>Descrição</th>
                                    <th>Destino</th>
                                    <th class="text-end">Valor unitário</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($itens === []): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">Nenhum item registrado nesta competência.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($itens as $item): ?>
                                    <?php $valorItem = (float)$item['quantidade'] * (float)$item['valor_unitario']; ?>
                                    <tr class="permuta-item"
                                        data-id="<?= (int)$item['id'] ?>"
                                        data-quantidade="<?= htmlspecialchars((string)$item['quantidade']) ?>"
                                        data-descricao="<?= htmlspecialchars($item['descricao'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-destino="<?= htmlspecialchars((string)$item['destino'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-valor="<?= htmlspecialchars(number_format((float)$item['valor_unitario'], 2, ',', '.')) ?>"
                                        data-observacao="<?= htmlspecialchars((string)$item['observacao'], ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="text-center"><?= htmlspecialchars(permutasQuantidadeRotulo((float)$item['quantidade'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['descricao']) ?></strong>
                                            <?php if (!empty($item['observacao'])): ?><small><?= htmlspecialchars($item['observacao']) ?></small><?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($item['destino'] ?: '-') ?></td>
                                        <td class="text-end"><?= htmlspecialchars(permutasMoeda((float)$item['valor_unitario'])) ?></td>
                                        <td class="text-end fw-bold"><?= htmlspecialchars(permutasMoeda($valorItem)) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-permuta" title="Editar item" aria-label="Editar item"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-permuta" title="Excluir item" aria-label="Excluir item"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($itens !== []): ?>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-end">Total da competência</td>
                                        <td class="text-end"><?= htmlspecialchars(permutasMoeda($total)) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </section>

                <div class="row g-4 mt-1">
                    <div class="col-lg-7">
                        <section class="permutas-historico">
                            <div class="permutas-secao-titulo">
                                <div>
                                    <h5>Competências recentes</h5>
                                    <p>Acesse rapidamente os relatórios anteriores.</p>
                                </div>
                            </div>
                            <div class="permutas-competencias-lista">
                                <?php if ($competenciasRecentes === []): ?><p class="text-muted mb-0">Nenhuma competência registrada.</p><?php endif; ?>
                                <?php foreach ($competenciasRecentes as $historico): ?>
                                    <?php $mesHistorico = date('Y-m', strtotime($historico['competencia'])); ?>
                                    <a href="?competencia=<?= htmlspecialchars($mesHistorico) ?>" class="permutas-competencia-item <?= $mesHistorico === $competencia ? 'ativo' : '' ?>">
                                        <span><strong><?= htmlspecialchars(permutasCompetenciaRotulo($mesHistorico)) ?></strong><small><?= (int)$historico['itens_total'] ?> item(ns)</small></span>
                                        <span class="text-end"><strong><?= htmlspecialchars(permutasMoeda((float)$historico['valor_total'])) ?></strong><small><?= htmlspecialchars(permutasStatusRotulo($historico['status'])) ?></small></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>
                    <div class="col-lg-5">
                        <section class="permutas-historico">
                            <div class="permutas-secao-titulo">
                                <div>
                                    <h5>Histórico de envios</h5>
                                    <p>Comprovantes do relatório selecionado.</p>
                                </div>
                            </div>
                            <div class="permutas-envios-lista">
                                <?php if ($envios === []): ?><p class="text-muted mb-0">Este mês ainda não foi enviado.</p><?php endif; ?>
                                <?php foreach ($envios as $envio): ?>
                                    <div class="permutas-envio-item">
                                        <i class="bi bi-envelope-check"></i>
                                        <span><strong><?= htmlspecialchars($envio['destinatario']) ?></strong><small><?= htmlspecialchars(permutasDataBr($envio['enviado_em'], true)) ?> por <?= htmlspecialchars($envio['enviado_por_nome'] ?: 'Usuário') ?></small></span>
                                        <strong><?= htmlspecialchars(permutasMoeda((float)$envio['total'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalPdfPermuta" tabindex="-1" aria-labelledby="tituloModalPdfPermuta" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered permutas-pdf-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="tituloModalPdfPermuta">Relatório de permuta</h5>
                            <p class="text-muted small mb-0"><?= htmlspecialchars(permutasCompetenciaRotulo($competencia)) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <iframe
                            class="permutas-pdf-frame"
                            id="visualizadorPdfPermuta"
                            src="about:blank"
                            data-src="<?= htmlspecialchars($urlPdfPermuta) ?>"
                            title="Relatório de permuta em PDF"></iframe>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                        <a href="<?= htmlspecialchars($urlPdfPermuta . '&baixar=1') ?>" class="btn btn-danger">
                            <i class="bi bi-download"></i> Baixar PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalItemPermuta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" id="formItemPermuta" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                        <input type="hidden" name="acao" value="salvar_item">
                        <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                        <input type="hidden" name="id" id="permutaItemId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalItemPermuta">Adicionar item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="permutaItemSelecionado" class="form-label">Item</label>
                                    <select class="form-select" id="permutaItemSelecionado" required>
                                        <option value="">Selecione um item</option>
                                        <?php foreach ($itensDisponiveis as $itemDisponivel): ?>
                                            <option value="<?= htmlspecialchars($itemDisponivel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($itemDisponivel) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__outro__">Adicionar outro item...</option>
                                    </select>
                                    <div class="invalid-feedback">Escolha um item.</div>
                                    <input type="hidden" name="descricao" id="permutaDescricao">
                                </div>
                                <div class="col-12 d-none" id="grupoPermutaOutroItem">
                                    <label for="permutaOutroItem" class="form-label">Nome do novo item</label>
                                    <input type="text" class="form-control" id="permutaOutroItem" maxlength="255" placeholder="Digite o nome do item">
                                    <div class="invalid-feedback">Informe o nome do novo item.</div>
                                </div>
                                <div class="col-sm-4">
                                    <label for="permutaQuantidade" class="form-label">Quantidade</label>
                                    <input type="text" inputmode="decimal" class="form-control" name="quantidade" id="permutaQuantidade" value="1" required>
                                    <div class="invalid-feedback">Informe a quantidade.</div>
                                </div>
                                <div class="col-sm-4">
                                    <label for="permutaValor" class="form-label">Valor unitário</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda text-end" name="valor_unitario" id="permutaValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o valor.</div>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label">Total</label>
                                    <div class="permuta-total-previa" id="permutaTotalPrevia">R$ 0,00</div>
                                </div>
                                <div class="col-12">
                                    <label for="permutaDestino" class="form-label">Destino</label>
                                    <input type="text" class="form-control" name="destino" id="permutaDestino" list="destinosPermuta" placeholder="Ex.: FECON INVESTING">
                                    <datalist id="destinosPermuta">
                                        <option value="FECON CONTABILIDADE">
                                        <option value="FECON INVESTING">
                                    </datalist>
                                </div>
                                <div class="col-12">
                                    <label for="permutaObservacao" class="form-label">Observação</label>
                                    <textarea class="form-control" name="observacao" id="permutaObservacao" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Salvar item</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalAnexoPermuta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" enctype="multipart/form-data" id="formAnexosPermuta" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                        <input type="hidden" name="acao" value="anexar_relatorio">
                        <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Anexar relatórios digitalizados</h5>
                                <p class="text-muted small mb-0"><?= htmlspecialchars(permutasCompetenciaRotulo($competencia)) ?></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <label for="relatoriosDigitalizados" class="form-label">Arquivos</label>
                            <input type="file" class="form-control" name="relatorios_digitalizados[]" id="relatoriosDigitalizados" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required>
                            <div class="invalid-feedback" id="erroRelatoriosDigitalizados">Escolha pelo menos um arquivo.</div>
                            <div class="form-text">Selecione até 10 arquivos em PDF, JPG ou PNG, com até 10 MB cada.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Anexar arquivos</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($anexosDigitalizados !== []): ?>
            <div class="modal fade" id="modalExcluirAnexoPermuta" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                            <input type="hidden" name="acao" value="excluir_relatorio">
                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                            <input type="hidden" name="anexo_id" id="excluirAnexoPermutaId">
                            <div class="modal-header">
                                <h5 class="modal-title">Excluir relatório</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-1">Deseja excluir este arquivo?</p><strong id="excluirAnexoPermutaNome"></strong>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Excluir</button></div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="modalExcluirPermuta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                        <input type="hidden" name="acao" value="excluir_item">
                        <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                        <input type="hidden" name="id" id="excluirPermutaId">
                        <div class="modal-header">
                            <h5 class="modal-title">Excluir item</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-1">Deseja excluir este item?</p><strong id="excluirPermutaDescricao"></strong>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Excluir</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalCompartilharPermuta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                        <input type="hidden" name="acao" value="criar_compartilhamento">
                        <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Compartilhar permuta</h5>
                                <p class="text-muted small mb-0">Acesso sem login e somente para visualização.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="permutas-compartilhar-aviso">
                                <i class="bi bi-shield-lock"></i>
                                <span>O link mostra apenas esta competência, o PDF e seus anexos. Não permite cadastrar, editar ou excluir nada.</span>
                            </div>
                            <label for="permutaDiasValidade" class="form-label mt-3">Validade do link</label>
                            <select class="form-select" name="dias_validade" id="permutaDiasValidade">
                                <option value="7">7 dias</option>
                                <option value="15">15 dias</option>
                                <option value="30" selected>30 dias</option>
                                <option value="60">60 dias</option>
                                <option value="90">90 dias</option>
                            </select>
                            <?php if ($compartilhamentoAtivo): ?>
                                <p class="form-text mb-0">Ao gerar um novo link, o atual será revogado automaticamente.</p>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Gerar link</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalEnviarPermuta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="post" id="formEnviarPermuta" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(permutasToken()) ?>">
                        <input type="hidden" name="acao" value="enviar_email">
                        <input type="hidden" name="competencia" value="<?= htmlspecialchars($competencia) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Enviar relatório ao financeiro</h5>
                                <p class="text-muted small mb-0"><?= $anexosDigitalizados !== [] ? 'O PDF, o link de visualização e todos os arquivos digitalizados serão enviados.' : 'O PDF e o link de visualização serão enviados automaticamente.' ?></p>
                            </div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="permutas-email-resumo"><span><i class="bi bi-file-earmark-pdf"></i> <?= htmlspecialchars(permutasNomeArquivo($competenciaRegistro ?: ['competencia' => $competencia . '-01'])) ?></span><strong><?= htmlspecialchars(permutasMoeda($total)) ?></strong></div>
                            <div class="mb-3">
                                <label for="permutaDestinatarios" class="form-label">E-mails do financeiro</label>
                                <textarea class="form-control" name="destinatarios" id="permutaDestinatarios" rows="2" required placeholder="financeiro@empresa.com; responsavel@empresa.com"><?= htmlspecialchars($ultimoDestinatario) ?></textarea>
                                <div class="form-text">Separe os endereços por vírgula, ponto e vírgula ou linha. Máximo de 10.</div>
                                <div class="invalid-feedback" id="permutaDestinatariosErro">Informe pelo menos um e-mail válido.</div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="salvar_destinatarios_padrao" value="1" id="salvarDestinatariosPermuta" checked>
                                <label class="form-check-label" for="salvarDestinatariosPermuta">Usar estes e-mails nos próximos envios</label>
                            </div>
                            <div class="mb-3"><label for="permutaAssunto" class="form-label">Assunto</label><input type="text" class="form-control" name="assunto" id="permutaAssunto" value="<?= htmlspecialchars($assuntoPadrao) ?>" maxlength="255" required></div>
                            <div><label for="permutaMensagemEmail" class="form-label">Mensagem</label><textarea class="form-control" name="mensagem_email" id="permutaMensagemEmail" rows="6" required><?= htmlspecialchars($mensagemPadrao) ?></textarea></div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="btnEnviarPermuta"><i class="bi bi-send"></i> Enviar PDF e link</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($estruturaDisponivel): ?>
        <script>
            (function() {
                window.setTimeout(function() {
                    document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                        alerta.classList.remove('show');
                        window.setTimeout(function() {
                            alerta.remove();
                        }, 200);
                    });
                }, 4000);

                const modalItemElemento = document.getElementById('modalItemPermuta');
                const modalItem = modalItemElemento ? bootstrap.Modal.getOrCreateInstance(modalItemElemento) : null;
                const modalExcluirElemento = document.getElementById('modalExcluirPermuta');
                const modalExcluir = modalExcluirElemento ? bootstrap.Modal.getOrCreateInstance(modalExcluirElemento) : null;
                const modalExcluirAnexoElemento = document.getElementById('modalExcluirAnexoPermuta');
                const modalExcluirAnexo = modalExcluirAnexoElemento ? bootstrap.Modal.getOrCreateInstance(modalExcluirAnexoElemento) : null;
                const modalPdfElemento = document.getElementById('modalPdfPermuta');
                const visualizadorPdf = document.getElementById('visualizadorPdfPermuta');
                const campoQuantidade = document.getElementById('permutaQuantidade');
                const campoValor = document.getElementById('permutaValor');

                modalPdfElemento?.addEventListener('show.bs.modal', function() {
                    if (visualizadorPdf && visualizadorPdf.getAttribute('src') === 'about:blank') {
                        visualizadorPdf.src = visualizadorPdf.dataset.src;
                    }
                });

                modalPdfElemento?.addEventListener('hidden.bs.modal', function() {
                    if (visualizadorPdf) {
                        visualizadorPdf.src = 'about:blank';
                    }
                });
                const itemSelecionado = document.getElementById('permutaItemSelecionado');
                const outroItem = document.getElementById('permutaOutroItem');
                const descricaoItem = document.getElementById('permutaDescricao');
                const grupoOutroItem = document.getElementById('grupoPermutaOutroItem');

                function numero(valor) {
                    let texto = String(valor || '').replace(/[^0-9,.-]/g, '');
                    if (texto.includes(',')) {
                        texto = texto.replace(/\./g, '').replace(',', '.');
                    }
                    const resultado = Number(texto);
                    return Number.isFinite(resultado) ? resultado : 0;
                }

                function moeda(valor) {
                    return valor.toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });
                }

                function atualizarTotal() {
                    document.getElementById('permutaTotalPrevia').textContent = moeda(numero(campoQuantidade?.value) * numero(campoValor?.value));
                }

                function formatarCampoMoeda(campo) {
                    const digitos = campo.value.replace(/\D/g, '');
                    if (digitos === '') {
                        campo.value = '';
                        return;
                    }

                    campo.value = (Number(digitos) / 100).toLocaleString('pt-BR', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }

                function atualizarItemSelecionado() {
                    const personalizado = itemSelecionado.value === '__outro__';
                    grupoOutroItem.classList.toggle('d-none', !personalizado);
                    descricaoItem.value = personalizado ? outroItem.value.trim() : itemSelecionado.value;
                    outroItem.required = personalizado;
                }

                function selecionarItem(descricao) {
                    const existe = Array.from(itemSelecionado.options).some((opcao) => opcao.value === descricao);
                    itemSelecionado.value = existe ? descricao : '__outro__';
                    outroItem.value = existe ? '' : descricao;
                    atualizarItemSelecionado();
                }

                function limparItem() {
                    document.getElementById('tituloModalItemPermuta').textContent = 'Adicionar item';
                    document.getElementById('permutaItemId').value = '';
                    itemSelecionado.value = '';
                    outroItem.value = '';
                    atualizarItemSelecionado();
                    document.getElementById('permutaQuantidade').value = '1';
                    document.getElementById('permutaValor').value = '';
                    document.getElementById('permutaDestino').value = '';
                    document.getElementById('permutaObservacao').value = '';
                    document.querySelectorAll('#formItemPermuta .is-invalid').forEach((campo) => campo.classList.remove('is-invalid'));
                    atualizarTotal();
                }

                document.getElementById('btnNovoItemPermuta')?.addEventListener('click', limparItem);
                campoQuantidade?.addEventListener('input', atualizarTotal);
                campoValor?.addEventListener('input', function() {
                    formatarCampoMoeda(campoValor);
                    atualizarTotal();
                });
                itemSelecionado?.addEventListener('change', atualizarItemSelecionado);
                outroItem?.addEventListener('input', atualizarItemSelecionado);

                document.getElementById('btnCopiarLinkPermuta')?.addEventListener('click', async function() {
                    const botao = this;
                    const campo = document.getElementById('linkPublicoPermuta');
                    if (!campo) {
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(campo.value);
                    } catch (erro) {
                        campo.focus();
                        campo.select();
                        document.execCommand('copy');
                    }

                    botao.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                    window.setTimeout(function() {
                        botao.innerHTML = '<i class="bi bi-copy"></i> Copiar link';
                    }, 1800);
                });

                document.querySelectorAll('.btn-editar-permuta').forEach(function(botao) {
                    botao.addEventListener('click', function() {
                        const linha = botao.closest('.permuta-item');
                        document.getElementById('tituloModalItemPermuta').textContent = 'Editar item';
                        document.getElementById('permutaItemId').value = linha.dataset.id || '';
                        selecionarItem(linha.dataset.descricao || '');
                        document.getElementById('permutaQuantidade').value = String(numero(linha.dataset.quantidade)).replace('.', ',');
                        document.getElementById('permutaValor').value = linha.dataset.valor || '';
                        document.getElementById('permutaDestino').value = linha.dataset.destino || '';
                        document.getElementById('permutaObservacao').value = linha.dataset.observacao || '';
                        atualizarTotal();
                        modalItem?.show();
                    });
                });

                document.querySelectorAll('.btn-excluir-permuta').forEach(function(botao) {
                    botao.addEventListener('click', function() {
                        const linha = botao.closest('.permuta-item');
                        document.getElementById('excluirPermutaId').value = linha.dataset.id || '';
                        document.getElementById('excluirPermutaDescricao').textContent = linha.dataset.descricao || '';
                        modalExcluir?.show();
                    });
                });

                document.querySelectorAll('.btn-excluir-anexo-permuta').forEach(function(botao) {
                    botao.addEventListener('click', function() {
                        document.getElementById('excluirAnexoPermutaId').value = botao.dataset.id || '';
                        document.getElementById('excluirAnexoPermutaNome').textContent = botao.dataset.nome || '';
                        modalExcluirAnexo?.show();
                    });
                });

                document.getElementById('formAnexosPermuta')?.addEventListener('submit', function(evento) {
                    const campo = document.getElementById('relatoriosDigitalizados');
                    const erro = document.getElementById('erroRelatoriosDigitalizados');
                    const arquivos = Array.from(campo.files || []);
                    let mensagem = '';

                    if (arquivos.length === 0) {
                        mensagem = 'Escolha pelo menos um arquivo.';
                    } else if (arquivos.length > 10) {
                        mensagem = 'Selecione no máximo 10 arquivos por vez.';
                    } else if (arquivos.some((arquivo) => arquivo.size <= 0 || arquivo.size > 10 * 1024 * 1024)) {
                        mensagem = 'Cada arquivo deve possuir até 10 MB.';
                    }

                    campo.classList.toggle('is-invalid', mensagem !== '');
                    erro.textContent = mensagem || 'Escolha pelo menos um arquivo.';
                    if (mensagem !== '') {
                        evento.preventDefault();
                    }
                });

                document.getElementById('competenciaPermuta')?.addEventListener('change', function() {
                    document.getElementById('formCompetenciaPermuta')?.requestSubmit();
                });

                document.getElementById('formItemPermuta')?.addEventListener('submit', function(evento) {
                    const quantidade = document.getElementById('permutaQuantidade');
                    const valor = document.getElementById('permutaValor');
                    atualizarItemSelecionado();
                    const itemValido = descricaoItem.value.trim() !== '';
                    itemSelecionado.classList.toggle('is-invalid', itemSelecionado.value === '');
                    outroItem.classList.toggle('is-invalid', itemSelecionado.value === '__outro__' && !itemValido);
                    const validacoes = [
                        [quantidade, numero(quantidade.value) > 0],
                        [valor, numero(valor.value) > 0]
                    ];
                    validacoes.forEach(([campo, valido]) => campo.classList.toggle('is-invalid', !valido));
                    if (!itemValido || validacoes.some(([, valido]) => !valido)) {
                        evento.preventDefault();
                    }
                });

                document.getElementById('formEnviarPermuta')?.addEventListener('submit', function(evento) {
                    const campoEmails = document.getElementById('permutaDestinatarios');
                    const erroEmails = document.getElementById('permutaDestinatariosErro');
                    const emails = campoEmails.value
                        .split(/[\s,;]+/)
                        .map((email) => email.trim())
                        .filter(Boolean);
                    const unicos = [...new Map(emails.map((email) => [email.toLowerCase(), email])).values()];
                    const emailValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    const valido = unicos.length > 0 &&
                        unicos.length <= 10 &&
                        unicos.every((email) => emailValido.test(email));
                    campoEmails.classList.toggle('is-invalid', !valido);
                    if (!valido) {
                        evento.preventDefault();
                        erroEmails.textContent = unicos.length > 10 ?
                            'Informe no máximo 10 e-mails.' :
                            'Revise os e-mails informados.';
                        campoEmails.focus();
                        return;
                    }

                    campoEmails.value = unicos.join(', ');

                    const botao = document.getElementById('btnEnviarPermuta');
                    botao.disabled = true;
                    botao.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Enviando...';
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>