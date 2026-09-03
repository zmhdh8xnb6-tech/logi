<?php
require 'config.php';
require_once __DIR__ . '/includes/folha_ponto_funcoes.php';

exigirPermissao('folha_ponto');

$tabelas = [
    'folha_ponto_funcionarios',
    'folha_ponto_horarios',
    'folha_ponto_registros',
];
$estruturaDisponivel = true;

foreach ($tabelas as $tabela) {
    if (!logiTabelaExiste($pdo, $tabela)) {
        $estruturaDisponivel = false;
        break;
    }
}

$cargaSemanalDisponivel = $estruturaDisponivel
    && logiColunaExiste($pdo, 'folha_ponto_funcionarios', 'carga_semanal');

$empresaId = (int)(empresaAtivaId($pdo) ?? 1);
$empresaId = $empresaId > 0 ? $empresaId : 1;
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$vinculoClienteDisponivel = $estruturaDisponivel
    && logiTabelaExiste($pdo, 'clientes')
    && folhaPontoGarantirVinculoCliente($pdo);
$clientesFolha = [];
$clientesFolhaPorId = [];
$funcionariosSemCliente = 0;
$clienteId = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);

if ($vinculoClienteDisponivel) {
    $stmt = $pdo->query("
        SELECT id, codigo, documento, nome, nome_fantasia, email
        FROM clientes
        WHERE cliente_contabil = 1
        " . clientesFiltroAtivos($pdo) . "
        " . empresaFiltroClienteDireto($pdo) . "
        ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC, id ASC
    ");
    $clientesFolha = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesFolha as &$clienteFolha) {
        $codigoCliente = trim((string)($clienteFolha['codigo'] ?? ''));
        $razaoSocial = trim((string)$clienteFolha['nome']);
        $clienteFolha['nome_exibicao'] = ($codigoCliente !== '' ? $codigoCliente . ' - ' : '')
            . $razaoSocial;
        $clientesFolhaPorId[(int)$clienteFolha['id']] = $clienteFolha;
    }
    unset($clienteFolha);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM folha_ponto_funcionarios
        WHERE empresa_id = ? AND cliente_id IS NULL
    ");
    $stmt->execute([$empresaId]);
    $funcionariosSemCliente = (int)$stmt->fetchColumn();
}

$folhasSalvasDisponivel = $estruturaDisponivel && folhaPontoGarantirTabelaFolhas($pdo);
$folhaId = (int)($_GET['folha_id'] ?? $_POST['folha_id'] ?? 0);
$folhaSelecionada = null;

if ($folhasSalvasDisponivel && $folhaId > 0) {
    $sqlFolhaSelecionada = $vinculoClienteDisponivel
        ? "
            SELECT folhas.*, funcionarios.cliente_id
            FROM folha_ponto_folhas folhas
            INNER JOIN folha_ponto_funcionarios funcionarios
                ON funcionarios.id = folhas.funcionario_id
               AND funcionarios.empresa_id = folhas.empresa_id
            WHERE folhas.id = ? AND folhas.empresa_id = ?
        "
        : "
            SELECT *
            FROM folha_ponto_folhas
            WHERE id = ? AND empresa_id = ?
        ";
    $stmt = $pdo->prepare($sqlFolhaSelecionada);
    $stmt->execute([$folhaId, $empresaId]);
    $folhaSelecionada = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$folhaSelecionada) {
    $folhaId = 0;
}

$mesLegado = folhaPontoMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$dataInicioInformada = folhaPontoDataValida(
    $folhaSelecionada['data_inicio'] ?? $_GET['data_inicio'] ?? $_POST['data_inicio'] ?? null
);
$dataFimInformada = folhaPontoDataValida(
    $folhaSelecionada['data_fim'] ?? $_GET['data_fim'] ?? $_POST['data_fim'] ?? null
);
$inicioPeriodo = $dataInicioInformada ?? ($mesLegado . '-01');
$fimPeriodo = $dataFimInformada ?? date('Y-m-t', strtotime($inicioPeriodo));

if ($fimPeriodo < $inicioPeriodo) {
    [$inicioPeriodo, $fimPeriodo] = [$fimPeriodo, $inicioPeriodo];
}

$inicioPeriodoData = new DateTimeImmutable($inicioPeriodo);
$fimPeriodoData = new DateTimeImmutable($fimPeriodo);
$quantidadeDiasPeriodo = ((int)$inicioPeriodoData->diff($fimPeriodoData)->days) + 1;

if ($quantidadeDiasPeriodo > 366) {
    $fimPeriodoData = $inicioPeriodoData->modify('+365 days');
    $fimPeriodo = $fimPeriodoData->format('Y-m-d');
    $quantidadeDiasPeriodo = 366;
}

$fimPeriodoExclusivo = $fimPeriodoData->modify('+1 day')->format('Y-m-d');
$fimPeriodoAnterior = $inicioPeriodoData->modify('-1 day');
$inicioPeriodoAnterior = $fimPeriodoAnterior->modify('-' . ($quantidadeDiasPeriodo - 1) . ' days');
$inicioPeriodoProximo = $fimPeriodoData->modify('+1 day');
$fimPeriodoProximo = $inicioPeriodoProximo->modify('+' . ($quantidadeDiasPeriodo - 1) . ' days');
$mes = substr($inicioPeriodo, 0, 7);
$funcionarioId = (int)($folhaSelecionada['funcionario_id'] ?? $_GET['funcionario_id'] ?? $_POST['funcionario_id'] ?? 0);
$modoEdicaoFolha = !$folhaSelecionada || (int)($_GET['editar'] ?? $_POST['editar'] ?? 0) === 1;
$nomesMeses = [
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
$nomeMes = $nomesMeses[(int)date('n', strtotime($inicioPeriodo))]
    . '/'
    . date('Y', strtotime($inicioPeriodo));
$nomePeriodo = date('d/m/Y', strtotime($inicioPeriodo))
    . ' a '
    . date('d/m/Y', strtotime($fimPeriodo));

$buscarFuncionario = static function (PDO $pdo, int $empresaId, int $id): ?array {
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM folha_ponto_funcionarios
        WHERE id = ? AND empresa_id = ?
    ");
    $stmt->execute([$id, $empresaId]);
    $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);
    return $funcionario ?: null;
};

if ($vinculoClienteDisponivel && $funcionarioId > 0) {
    $funcionarioContexto = $buscarFuncionario($pdo, $empresaId, $funcionarioId);

    if ($funcionarioContexto) {
        $clienteFuncionario = (int)($funcionarioContexto['cliente_id'] ?? 0);
        $clienteId = $clienteFuncionario > 0 ? $clienteFuncionario : -1;
    }
}

if ($clienteId > 0 && !isset($clientesFolhaPorId[$clienteId])) {
    $clienteId = 0;
} elseif ($clienteId === -1 && $funcionariosSemCliente === 0) {
    $clienteId = 0;
} elseif ($clienteId < -1) {
    $clienteId = 0;
}

$clienteSelecionado = $clienteId > 0 ? ($clientesFolhaPorId[$clienteId] ?? null) : null;
$clienteNomeFolha = $clienteSelecionado['nome_exibicao']
    ?? ($clienteId === -1 ? 'Sem empresa vinculada' : 'Selecione uma empresa cliente');

if ($estruturaDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parametrosRetorno = [
        'data_inicio' => $inicioPeriodo,
        'data_fim' => $fimPeriodo,
        'funcionario_id' => $funcionarioId,
    ];

    if ($clienteId !== 0) {
        $parametrosRetorno['cliente_id'] = $clienteId;
    }

    if ($folhaId > 0) {
        $parametrosRetorno['folha_id'] = $folhaId;

        if ($modoEdicaoFolha) {
            $parametrosRetorno['editar'] = 1;
        }
    }

    $urlRetorno = 'folha_ponto.php?' . http_build_query($parametrosRetorno);

    if (!folhaPontoTokenValido($_POST['csrf_token'] ?? null)) {
        folhaPontoRedirecionar(
            $urlRetorno,
            'A sessão do formulário expirou. Tente novamente.',
            'danger'
        );
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'excluir_funcionario') {
        $id = (int)($_POST['id'] ?? 0);
        $funcionario = $buscarFuncionario($pdo, $empresaId, $id);

        if (!$funcionario) {
            folhaPontoRedirecionar($urlRetorno, 'Funcionário não encontrado nesta empresa.', 'danger');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM folha_ponto_registros WHERE empresa_id = ? AND funcionario_id = ?");
            $stmt->execute([$empresaId, $id]);

            $stmt = $pdo->prepare("DELETE FROM folha_ponto_horarios WHERE empresa_id = ? AND funcionario_id = ?");
            $stmt->execute([$empresaId, $id]);

            if ($folhasSalvasDisponivel) {
                $stmt = $pdo->prepare("DELETE FROM folha_ponto_folhas WHERE empresa_id = ? AND funcionario_id = ?");
                $stmt->execute([$empresaId, $id]);
            }

            $stmt = $pdo->prepare("DELETE FROM folha_ponto_funcionarios WHERE empresa_id = ? AND id = ?");
            $stmt->execute([$empresaId, $id]);

            $pdo->commit();

            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                'excluir',
                'funcionario_ponto',
                $id,
                'Excluiu o funcionário ' . $funcionario['nome'] . ' e seus registros de ponto',
                $funcionario,
                null
            );

            folhaPontoRedirecionar(
                'folha_ponto.php?' . http_build_query([
                    'data_inicio' => $inicioPeriodo,
                    'data_fim' => $fimPeriodo,
                    'cliente_id' => !empty($funcionario['cliente_id']) ? (int)$funcionario['cliente_id'] : -1,
                ]),
                'Funcionário e registros de ponto excluídos com sucesso.'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            folhaPontoRedirecionar($urlRetorno, 'Não foi possível excluir o funcionário.', 'danger');
        }
    }

    if ($acao === 'salvar_funcionario') {
        $id = (int)($_POST['id'] ?? 0);
        $clienteFuncionarioId = (int)($_POST['cliente_id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        $cargaContratual = (int)($_POST['carga_semanal'] ?? 44);
        $horariosEntrada = is_array($_POST['horarios'] ?? null) ? $_POST['horarios'] : [];
        $horarios = [];
        $cargaSemanal = 0;
        $funcionarioAntes = $id > 0 ? $buscarFuncionario($pdo, $empresaId, $id) : null;

        $parametrosRetorno['cliente_id'] = $clienteFuncionarioId;
        $urlRetorno = 'folha_ponto.php?' . http_build_query($parametrosRetorno);

        if (!$vinculoClienteDisponivel) {
            folhaPontoRedirecionar(
                $urlRetorno,
                'Atualize o banco com o arquivo sql/folha_ponto_clientes.sql antes de vincular funcionários.',
                'danger'
            );
        }

        if ($clienteFuncionarioId <= 0 || !isset($clientesFolhaPorId[$clienteFuncionarioId])) {
            folhaPontoRedirecionar($urlRetorno, 'Selecione uma empresa válida do cadastro de Clientes.', 'danger');
        }

        if ($nome === '' || (function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome)) > 150) {
            folhaPontoRedirecionar($urlRetorno, 'Informe corretamente o nome do funcionário.', 'danger');
        }

        if ($id > 0 && !$funcionarioAntes) {
            folhaPontoRedirecionar($urlRetorno, 'Funcionário não encontrado nesta empresa.', 'danger');
        }

        if (!in_array($cargaContratual, [36, 44], true)) {
            $cargaContratual = 44;
        }

        if (!$cargaSemanalDisponivel && $cargaContratual === 36) {
            folhaPontoRedirecionar(
                $urlRetorno,
                'Atualize o banco com o arquivo sql/folha_ponto_carga_semanal.sql antes de salvar a jornada.',
                'danger'
            );
        }

        try {
            for ($dia = 1; $dia <= 7; $dia++) {
                $horarioEntrada = $horariosEntrada[$dia] ?? [];

                if ($cargaContratual === 36) {
                    $horarioEntrada['entrada_2'] = '';
                    $horarioEntrada['saida_2'] = '';
                }

                $horarios[$dia] = folhaPontoNormalizarHorario($horarioEntrada, $dia);
                $cargaSemanal += folhaPontoMinutosMarcacoes($horarios[$dia]);
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                if ($cargaSemanalDisponivel) {
                    $stmt = $pdo->prepare("
                        UPDATE folha_ponto_funcionarios
                        SET cliente_id = ?, nome = ?, ativo = ?, carga_semanal = ?
                        WHERE id = ? AND empresa_id = ?
                    ");
                    $stmt->execute([$clienteFuncionarioId, $nome, $ativo, $cargaContratual, $id, $empresaId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE folha_ponto_funcionarios
                        SET cliente_id = ?, nome = ?, ativo = ?
                        WHERE id = ? AND empresa_id = ?
                    ");
                    $stmt->execute([$clienteFuncionarioId, $nome, $ativo, $id, $empresaId]);
                }

                $funcionarioId = $id;
            } else {
                if ($cargaSemanalDisponivel) {
                    $stmt = $pdo->prepare("
                        INSERT INTO folha_ponto_funcionarios (empresa_id, cliente_id, nome, ativo, carga_semanal)
                        VALUES (?, ?, ?, 1, ?)
                    ");
                    $stmt->execute([$empresaId, $clienteFuncionarioId, $nome, $cargaContratual]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO folha_ponto_funcionarios (empresa_id, cliente_id, nome, ativo)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([$empresaId, $clienteFuncionarioId, $nome]);
                }

                $funcionarioId = (int)$pdo->lastInsertId();
                $ativo = 1;
            }

            $stmt = $pdo->prepare("
                DELETE FROM folha_ponto_horarios
                WHERE funcionario_id = ? AND empresa_id = ?
            ");
            $stmt->execute([$funcionarioId, $empresaId]);

            $stmt = $pdo->prepare("
                INSERT INTO folha_ponto_horarios (
                    empresa_id, funcionario_id, dia_semana, trabalha,
                    entrada_1, saida_1, entrada_2, saida_2
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($horarios as $horario) {
                $stmt->execute([
                    $empresaId,
                    $funcionarioId,
                    $horario['dia_semana'],
                    $horario['trabalha'],
                    $horario['entrada_1'] !== '' ? $horario['entrada_1'] : null,
                    $horario['saida_1'] !== '' ? $horario['saida_1'] : null,
                    $horario['entrada_2'] !== '' ? $horario['entrada_2'] : null,
                    $horario['saida_2'] !== '' ? $horario['saida_2'] : null,
                ]);
            }

            $pdo->commit();

            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                $id > 0 ? 'editar' : 'criar',
                'funcionario_ponto',
                $funcionarioId,
                ($id > 0 ? 'Alterou' : 'Cadastrou') . ' o funcionário ' . $nome,
                $funcionarioAntes,
                [
                    'cliente_id' => $clienteFuncionarioId,
                    'empresa_cliente' => $clientesFolhaPorId[$clienteFuncionarioId]['nome_exibicao'],
                    'nome' => $nome,
                    'ativo' => $ativo,
                    'carga_contratual' => $cargaContratual . 'h semanais',
                    'carga_semanal' => folhaPontoFormatarMinutos($cargaSemanal),
                ]
            );

            $mensagem = $id > 0
                ? 'Funcionário e jornada atualizados com sucesso.'
                : 'Funcionário cadastrado com sucesso.';

            if ($cargaSemanal > $cargaContratual * 60) {
                $mensagem .= ' Atenção: a jornada informada ultrapassa ' . $cargaContratual . ' horas semanais.';
            }

            folhaPontoRedirecionar(
                'folha_ponto.php?' . http_build_query([
                    'data_inicio' => $inicioPeriodo,
                    'data_fim' => $fimPeriodo,
                    'cliente_id' => $clienteFuncionarioId,
                    'funcionario_id' => $funcionarioId,
                ]),
                $mensagem,
                $cargaSemanal > $cargaContratual * 60 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            folhaPontoRedirecionar($urlRetorno, $e->getMessage(), 'danger');
        }
    }

    if ($acao === 'salvar_registros') {
        $funcionario = $buscarFuncionario($pdo, $empresaId, $funcionarioId);
        $registrosEntrada = is_array($_POST['registros'] ?? null) ? $_POST['registros'] : [];

        if (!$funcionario) {
            folhaPontoRedirecionar($urlRetorno, 'Selecione um funcionário válido.', 'danger');
        }

        if ($folhaSelecionada && !$modoEdicaoFolha) {
            folhaPontoRedirecionar($urlRetorno, 'Clique em Editar folha antes de alterar este registro.', 'warning');
        }

        $jornadaFuncionario36 = $cargaSemanalDisponivel
            && (int)($funcionario['carga_semanal'] ?? 44) === 36;

        try {
            $pdo->beginTransaction();
            $stmtSalvar = $pdo->prepare("
                INSERT INTO folha_ponto_registros (
                    empresa_id, funcionario_id, data_registro,
                    entrada_1, saida_1, entrada_2, saida_2,
                    observacao, origem, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?)
                ON DUPLICATE KEY UPDATE
                    entrada_1 = VALUES(entrada_1),
                    saida_1 = VALUES(saida_1),
                    entrada_2 = VALUES(entrada_2),
                    saida_2 = VALUES(saida_2),
                    observacao = COALESCE(VALUES(observacao), observacao),
                    origem = 'manual',
                    usuario_id = VALUES(usuario_id),
                    atualizado_em = CURRENT_TIMESTAMP
            ");
            $stmtExcluir = $pdo->prepare("
                DELETE FROM folha_ponto_registros
                WHERE empresa_id = ? AND funcionario_id = ? AND data_registro = ?
            ");
            $quantidadeAlterada = 0;
            $folhaSalvaId = $folhaId;

            foreach ($registrosEntrada as $data => $dados) {
                if (!is_array($dados) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                    continue;
                }

                if ($data < $inicioPeriodo || $data > $fimPeriodo || date('Y-m-d', strtotime($data)) !== $data) {
                    throw new RuntimeException('Existe uma data inválida nos registros enviados.');
                }

                $valores = [];

                foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                    $valor = trim((string)($dados[$campo] ?? ''));

                    if (!folhaPontoHoraValida($valor)) {
                        throw new RuntimeException('Existe um horário inválido no dia ' . date('d/m/Y', strtotime($data)) . '.');
                    }

                    $valores[$campo] = $valor !== '' ? $valor : null;
                }

                if ($jornadaFuncionario36) {
                    $valores['entrada_2'] = null;
                    $valores['saida_2'] = null;
                }

                $atestado = !empty($dados['atestado']);

                if ($atestado) {
                    foreach ($valores as $campo => $valor) {
                        $valores[$campo] = null;
                    }
                }

                if (
                    $valores['entrada_1'] !== null
                    && $valores['saida_1'] !== null
                    && folhaPontoMinutosIntervalo($valores['entrada_1'], $valores['saida_1']) <= 0
                ) {
                    throw new RuntimeException('Revise a primeira saída do dia ' . date('d/m/Y', strtotime($data)) . '.');
                }

                if (
                    $valores['entrada_2'] !== null
                    && $valores['saida_2'] !== null
                    && folhaPontoMinutosIntervalo($valores['entrada_2'], $valores['saida_2']) <= 0
                ) {
                    throw new RuntimeException('Revise a saída final do dia ' . date('d/m/Y', strtotime($data)) . '.');
                }

                $observacao = trim((string)($dados['observacao'] ?? ''));

                if ($atestado) {
                    $observacao = trim((string)preg_replace('/^atestado\s*:?\s*/i', '', $observacao));
                    $observacao = 'Atestado' . ($observacao !== '' ? ': ' . $observacao : '');
                }

                $observacao = function_exists('mb_substr')
                    ? mb_substr($observacao, 0, 255)
                    : substr($observacao, 0, 255);
                $possuiConteudo = array_filter($valores, static fn($valor) => $valor !== null) !== []
                    || $observacao !== '';

                if (!$possuiConteudo) {
                    $stmtExcluir->execute([$empresaId, $funcionarioId, $data]);
                    continue;
                }

                $stmtSalvar->execute([
                    $empresaId,
                    $funcionarioId,
                    $data,
                    $valores['entrada_1'],
                    $valores['saida_1'],
                    $valores['entrada_2'],
                    $valores['saida_2'],
                    $observacao !== '' ? $observacao : null,
                    $usuarioId,
                ]);
                $quantidadeAlterada++;
            }

            if ($folhasSalvasDisponivel) {
                $stmtFolha = $pdo->prepare("
                    INSERT INTO folha_ponto_folhas (
                        empresa_id, funcionario_id, data_inicio, data_fim, usuario_id
                    ) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        id = LAST_INSERT_ID(id),
                        usuario_id = VALUES(usuario_id),
                        atualizado_em = CURRENT_TIMESTAMP
                ");
                $stmtFolha->execute([
                    $empresaId,
                    $funcionarioId,
                    $inicioPeriodo,
                    $fimPeriodo,
                    $usuarioId,
                ]);
                $folhaSalvaId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                'editar',
                'registros_ponto',
                $funcionarioId,
                'Atualizou a folha de ' . $funcionario['nome'] . ' no período de ' . $nomePeriodo,
                null,
                ['registros_preenchidos' => $quantidadeAlterada]
            );
            $urlFolhaSalva = $folhaSalvaId > 0
                ? 'folha_ponto.php?' . http_build_query(['folha_id' => $folhaSalvaId])
                : $urlRetorno;
            folhaPontoRedirecionar(
                $urlFolhaSalva,
                $folhaSalvaId > 0
                    ? 'Folha salva e bloqueada para edição.'
                    : 'Folha salva. O histórico de períodos não está disponível neste banco.',
                $folhaSalvaId > 0 ? 'success' : 'warning'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            folhaPontoRedirecionar($urlRetorno, $e->getMessage(), 'danger');
        }
    }

    if ($acao === 'importar_pdf') {
        $funcionario = $buscarFuncionario($pdo, $empresaId, $funcionarioId);
        $registrosImportados = json_decode((string)($_POST['registros_pdf'] ?? ''), true);

        if (!$funcionario) {
            folhaPontoRedirecionar($urlRetorno, 'Selecione um funcionário válido.', 'danger');
        }

        if ($folhaSelecionada && !$modoEdicaoFolha) {
            folhaPontoRedirecionar($urlRetorno, 'Clique em Editar folha antes de importar horários neste registro.', 'warning');
        }

        $jornadaFuncionario36 = $cargaSemanalDisponivel
            && (int)($funcionario['carga_semanal'] ?? 44) === 36;

        if (!is_array($registrosImportados) || $registrosImportados === [] || count($registrosImportados) > 366) {
            folhaPontoRedirecionar($urlRetorno, 'Nenhum registro válido foi encontrado no arquivo.', 'danger');
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO folha_ponto_registros (
                    empresa_id, funcionario_id, data_registro,
                    entrada_1, saida_1, entrada_2, saida_2,
                    observacao, origem, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pdf', ?)
                ON DUPLICATE KEY UPDATE
                    entrada_1 = CASE
                        WHEN VALUES(observacao) LIKE 'Atestado%' THEN NULL
                        ELSE COALESCE(VALUES(entrada_1), entrada_1)
                    END,
                    saida_1 = CASE
                        WHEN VALUES(observacao) LIKE 'Atestado%' THEN NULL
                        ELSE COALESCE(VALUES(saida_1), saida_1)
                    END,
                    entrada_2 = CASE
                        WHEN VALUES(observacao) LIKE 'Atestado%' THEN NULL
                        ELSE COALESCE(VALUES(entrada_2), entrada_2)
                    END,
                    saida_2 = CASE
                        WHEN VALUES(observacao) LIKE 'Atestado%' THEN NULL
                        ELSE COALESCE(VALUES(saida_2), saida_2)
                    END,
                    observacao = VALUES(observacao),
                    origem = 'pdf',
                    usuario_id = VALUES(usuario_id),
                    atualizado_em = CURRENT_TIMESTAMP
            ");
            $datasProcessadas = [];
            $diasComHorarios = 0;

            foreach ($registrosImportados as $registro) {
                if (!is_array($registro)) {
                    continue;
                }

                $data = trim((string)($registro['data'] ?? ''));

                if (
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)
                    || $data < $inicioPeriodo
                    || $data > $fimPeriodo
                    || date('Y-m-d', strtotime($data)) !== $data
                    || isset($datasProcessadas[$data])
                ) {
                    continue;
                }

                $valores = [];

                foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                    $valor = trim((string)($registro[$campo] ?? ''));
                    $valores[$campo] = folhaPontoHoraValida($valor) && $valor !== '' ? $valor : null;
                }

                if ($jornadaFuncionario36) {
                    $valores['entrada_2'] = null;
                    $valores['saida_2'] = null;
                }

                $observacao = trim((string)($registro['observacao'] ?? ''));
                $observacao = function_exists('mb_substr')
                    ? mb_substr($observacao, 0, 255)
                    : substr($observacao, 0, 255);
                $situacaoEspecial = preg_match('/^(?:feriado|folga|atestado)\b/i', $observacao) === 1;

                if (preg_match('/^atestado\b/i', $observacao) === 1) {
                    foreach ($valores as $campo => $valor) {
                        $valores[$campo] = null;
                    }
                }

                $camposObrigatorios = $jornadaFuncionario36
                    ? ['entrada_1', 'saida_1']
                    : ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'];
                $possuiHorario = array_filter($valores, static fn($valor) => $valor !== null) !== [];
                $importacaoPendente = !$situacaoEspecial && array_filter(
                    $camposObrigatorios,
                    static fn($campo) => $valores[$campo] === null
                ) !== [];

                if ($importacaoPendente && $observacao === '') {
                    $observacao = 'Importacao pendente';
                }

                if ($possuiHorario) {
                    $diasComHorarios++;
                }

                $stmt->execute([
                    $empresaId,
                    $funcionarioId,
                    $data,
                    $valores['entrada_1'],
                    $valores['saida_1'],
                    $valores['entrada_2'],
                    $valores['saida_2'],
                    $observacao !== '' ? $observacao : null,
                    $usuarioId,
                ]);
                $datasProcessadas[$data] = true;
            }

            $pdo->commit();

            if ($diasComHorarios === 0) {
                folhaPontoRedirecionar(
                    $urlRetorno,
                    'A folha foi importada, mas os horários não reconhecidos permaneceram em branco para preenchimento manual.',
                    'warning'
                );
            }

            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                'importar',
                'registros_ponto',
                $funcionarioId,
                'Importou registros de ponto por arquivo para ' . $funcionario['nome'],
                null,
                [
                    'data_inicio' => $inicioPeriodo,
                    'data_fim' => $fimPeriodo,
                    'dias_analisados' => count($datasProcessadas),
                    'dias_com_horarios' => $diasComHorarios,
                ]
            );
            folhaPontoRedirecionar(
                $urlRetorno,
                $diasComHorarios . ' dia' . ($diasComHorarios === 1 ? '' : 's')
                    . ' com horários reconhecidos. Os campos restantes ficaram sinalizados para preenchimento manual.'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            folhaPontoRedirecionar($urlRetorno, $e->getMessage(), 'danger');
        }
    }
}

$mensagem = folhaPontoObterMensagem();
$funcionarios = [];
$funcionarioSelecionado = null;
$horarios = folhaPontoHorarioPadrao();
$horariosFuncionarios = [];
$registros = [];
$folhasSalvas = [];
$cargaContratualSelecionada = 44;

if ($estruturaDisponivel && $vinculoClienteDisponivel && $clienteId !== 0) {
    $filtroClienteFuncionario = $clienteId > 0 ? 'cliente_id = ?' : 'cliente_id IS NULL';
    $parametrosFuncionarios = $clienteId > 0 ? [$empresaId, $clienteId] : [$empresaId];
    $stmt = $pdo->prepare("
        SELECT *
        FROM folha_ponto_funcionarios
        WHERE empresa_id = ?
          AND {$filtroClienteFuncionario}
        ORDER BY ativo DESC, nome ASC, id ASC
    ");
    $stmt->execute($parametrosFuncionarios);
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($folhasSalvasDisponivel) {
        $filtroClienteFolha = $clienteId > 0
            ? 'funcionarios.cliente_id = ?'
            : 'funcionarios.cliente_id IS NULL';
        $parametrosFolhas = $clienteId > 0 ? [$empresaId, $clienteId] : [$empresaId];
        $stmt = $pdo->prepare("
            SELECT folhas.*, funcionarios.nome AS funcionario_nome
            FROM folha_ponto_folhas folhas
            INNER JOIN folha_ponto_funcionarios funcionarios
                ON funcionarios.id = folhas.funcionario_id
               AND funcionarios.empresa_id = folhas.empresa_id
            WHERE folhas.empresa_id = ?
              AND {$filtroClienteFolha}
            ORDER BY folhas.data_fim DESC, folhas.atualizado_em DESC, folhas.id DESC
            LIMIT 100
        ");
        $stmt->execute($parametrosFolhas);
        $folhasSalvas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $funcionarioSelecionado = $buscarFuncionario($pdo, $empresaId, $funcionarioId);

    if ($funcionarioSelecionado) {
        $clienteFuncionarioSelecionado = (int)($funcionarioSelecionado['cliente_id'] ?? 0);
        $pertenceAoCliente = $clienteId > 0
            ? $clienteFuncionarioSelecionado === $clienteId
            : $clienteFuncionarioSelecionado === 0;

        if (!$pertenceAoCliente) {
            $funcionarioSelecionado = null;
        }
    }

    if (!$funcionarioSelecionado) {
        $funcionarioId = 0;
    }

    if ($funcionarios !== []) {
        $idsFuncionariosCliente = array_map(
            static fn(array $funcionario): int => (int)$funcionario['id'],
            $funcionarios
        );
        $marcadoresFuncionarios = implode(',', array_fill(0, count($idsFuncionariosCliente), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM folha_ponto_horarios
            WHERE empresa_id = ?
              AND funcionario_id IN ({$marcadoresFuncionarios})
            ORDER BY funcionario_id, dia_semana
        ");
        $stmt->execute(array_merge([$empresaId], $idsFuncionariosCliente));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $horario) {
            foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                $horario[$campo] = !empty($horario[$campo]) ? substr($horario[$campo], 0, 5) : null;
            }

            $idHorario = (int)$horario['funcionario_id'];

            if (!isset($horariosFuncionarios[$idHorario])) {
                $horariosFuncionarios[$idHorario] = folhaPontoHorarioPadrao();
            }

            $horariosFuncionarios[$idHorario][(int)$horario['dia_semana']] = $horario;
        }
    }

    if ($funcionarioSelecionado) {
        $cargaContratualSelecionada = $cargaSemanalDisponivel
            ? (int)($funcionarioSelecionado['carga_semanal'] ?? 44)
            : 44;
        $cargaContratualSelecionada = in_array($cargaContratualSelecionada, [36, 44], true)
            ? $cargaContratualSelecionada
            : 44;
        $horarios = $horariosFuncionarios[$funcionarioId] ?? folhaPontoHorarioPadrao();

        $stmt = $pdo->prepare("
            SELECT *
            FROM folha_ponto_registros
            WHERE empresa_id = ?
              AND funcionario_id = ?
              AND data_registro >= ?
              AND data_registro < ?
            ORDER BY data_registro
        ");
        $stmt->execute([$empresaId, $funcionarioId, $inicioPeriodo, $fimPeriodoExclusivo]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registro) {
            foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                $registro[$campo] = !empty($registro[$campo]) ? substr($registro[$campo], 0, 5) : '';
            }

            if ($cargaContratualSelecionada === 36) {
                $registro['entrada_2'] = '';
                $registro['saida_2'] = '';
            }

            $registros[$registro['data_registro']] = $registro;
        }
    }
}

$cargaSemanal = 0;

foreach ($horarios as $horario) {
    if (!empty($horario['trabalha'])) {
        $cargaSemanal += folhaPontoMinutosMarcacoes($horario);
    }
}

$diasFolha = [];
$totalPrevistoMes = 0;
$totalPrevistoAteHoje = 0;
$totalTrabalhadoMes = 0;
$totalTrabalhadoAteHoje = 0;
$diasSemRegistro = 0;
$hoje = date('Y-m-d');

if ($funcionarioSelecionado) {
    $data = new DateTime($inicioPeriodo);
    $limite = new DateTime($fimPeriodoExclusivo);

    while ($data < $limite) {
        $dataIso = $data->format('Y-m-d');
        $diaSemana = (int)$data->format('N');
        $horario = $horarios[$diaSemana] ?? [
            'trabalha' => 0,
            'entrada_1' => null,
            'saida_1' => null,
            'entrada_2' => null,
            'saida_2' => null,
        ];
        $registro = $registros[$dataIso] ?? [
            'entrada_1' => '',
            'saida_1' => '',
            'entrada_2' => '',
            'saida_2' => '',
            'observacao' => '',
            'origem' => 'manual',
        ];
        $observacaoRegistro = trim((string)($registro['observacao'] ?? ''));
        $importacaoPendente = strcasecmp($observacaoRegistro, 'Importacao pendente') === 0;
        $feriadoInformado = preg_match('/^feriado\b/i', $observacaoRegistro) === 1;
        $atestado = preg_match('/^atestado\b/i', $observacaoRegistro) === 1;
        $folgaInformada = preg_match('/^folga\b/i', $observacaoRegistro) === 1;
        $observacaoExibicao = $importacaoPendente
            ? ''
            : ($atestado
                ? trim((string)preg_replace('/^atestado\s*:?\s*/i', '', $observacaoRegistro))
                : $observacaoRegistro);
        $feriadoNacional = FolhaPontoCalendario::feriadoNacional($dataIso);
        $feriado = $feriadoInformado || $feriadoNacional !== null;
        $feriadoNome = $feriadoNacional ?? '';

        if ($feriadoInformado) {
            $feriadoNomeInformado = trim((string)preg_replace('/^feriado\s*:?\s*/i', '', $observacaoRegistro));

            if ($feriadoNomeInformado !== '') {
                $feriadoNome = $feriadoNomeInformado;
            }
        }

        $previstoJornada = !empty($horario['trabalha']) ? folhaPontoMinutosMarcacoes($horario) : 0;
        $previsto = ($feriado || $atestado || $folgaInformada) ? 0 : $previstoJornada;
        $trabalhado = folhaPontoMinutosMarcacoes($registro);
        $marcacoes = array_filter([
            $registro['entrada_1'] ?? '',
            $registro['saida_1'] ?? '',
            $registro['entrada_2'] ?? '',
            $registro['saida_2'] ?? '',
        ], static fn($valor) => $valor !== '');
        $possuiMarcacao = $marcacoes !== [];
        $incompleto = $possuiMarcacao && (
            empty($registro['entrada_1'])
            || empty($registro['saida_1'])
            || ((!empty($registro['entrada_2']) || !empty($registro['saida_2']))
                && (empty($registro['entrada_2']) || empty($registro['saida_2'])))
        );

        if ($feriado) {
            $status = ['Feriado', 'bg-primary'];
        } elseif ($atestado) {
            $status = ['Atestado', 'bg-info text-dark'];
        } elseif ($folgaInformada) {
            $status = ['Folga', 'bg-secondary'];
        } elseif (!$possuiMarcacao && $previsto <= 0) {
            $status = ['Folga', 'bg-secondary'];
        } elseif (!$possuiMarcacao && $dataIso > $hoje) {
            $status = ['Aguardando', 'bg-light text-dark border'];
        } elseif (!$possuiMarcacao) {
            $status = ['Sem registro', 'bg-danger'];
            $diasSemRegistro++;
        } elseif ($incompleto) {
            $status = ['Incompleto', 'bg-warning text-dark'];
        } else {
            $status = ['Registrado', 'bg-success'];
        }

        $totalPrevistoMes += $previsto;
        $totalTrabalhadoMes += $trabalhado;

        if ($dataIso <= $hoje) {
            $totalPrevistoAteHoje += $previsto;
            $totalTrabalhadoAteHoje += $trabalhado;
        }

        $diasFolha[] = [
            'data' => $dataIso,
            'data_br' => $data->format('d/m/Y'),
            'dia_semana' => folhaPontoNomesDias()[$diaSemana],
            'horario' => $horario,
            'registro' => $registro,
            'previsto' => $previsto,
            'previsto_jornada' => $previstoJornada,
            'trabalhado' => $trabalhado,
            'saldo' => $trabalhado - $previsto,
            'status' => $status,
            'feriado' => $feriado,
            'atestado' => $atestado,
            'folga_informada' => $folgaInformada,
            'importacao_pendente' => $importacaoPendente,
            'observacao_exibicao' => $observacaoExibicao,
            'feriado_nacional' => $feriadoNacional !== null,
            'feriado_nome' => $feriadoNome,
            'fim_semana' => $diaSemana >= 6,
        ];
        $data->modify('+1 day');
    }
}

$saldoAteHoje = $totalTrabalhadoAteHoje - $totalPrevistoAteHoje;
$jornadaSemanalReferencia = $cargaContratualSelecionada * 60;
$referenciaMensal = ($cargaContratualSelecionada === 36 ? 180 : 220) * 60;
$horariosJson = json_encode(array_values($horarios), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$urlFolhaAtual = $folhaId > 0
    ? 'folha_ponto.php?' . http_build_query(['folha_id' => $folhaId])
    : '';
$urlEditarFolhaAtual = $folhaId > 0
    ? 'folha_ponto.php?' . http_build_query(['folha_id' => $folhaId, 'editar' => 1])
    : '';
$urlListaFuncionarios = 'folha_ponto.php?' . http_build_query([
    'data_inicio' => $inicioPeriodo,
    'data_fim' => $fimPeriodo,
    'cliente_id' => $clienteId,
]);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Folha de Ponto</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/folha_ponto.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="ponto-cabecalho mb-4 no-print">
                <div>
                    <h3 class="mb-1">Folha de Ponto</h3>
                    <p class="text-muted mb-0">Jornadas semanais e registros por período dos funcionários</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= $funcionarioSelecionado ? htmlspecialchars($urlListaFuncionarios) : 'home.php' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <?php if ($estruturaDisponivel): ?>
                        <button
                            type="button"
                            class="btn btn-primary"
                            id="btnNovoFuncionario"
                            data-cliente-id="<?= $clienteId > 0 ? $clienteId : '' ?>"
                            <?= $clienteId > 0 && $vinculoClienteDisponivel ? 'data-bs-toggle="modal" data-bs-target="#modalFuncionario"' : 'disabled title="Selecione uma empresa cliente"' ?>>
                            <i class="bi bi-person-plus"></i> Novo funcionário
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$estruturaDisponivel): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o arquivo <code>sql/folha_ponto.sql</code> no phpMyAdmin.
                </div>
            <?php else: ?>
                <?php if ($vinculoClienteDisponivel): ?>
                    <section class="ponto-empresa-contexto mb-4 no-print" aria-label="Empresa cliente da folha de ponto">
                        <div class="ponto-empresa-identidade">
                            <span class="ponto-empresa-icone" aria-hidden="true"><i class="bi bi-buildings"></i></span>
                            <div>
                                <span>Empresa cliente</span>
                                <strong><?= htmlspecialchars($clienteNomeFolha) ?></strong>
                            </div>
                        </div>
                        <form method="get" action="folha_ponto.php" id="formClienteFolhaPonto">
                            <label for="clienteBuscaFolhaPonto" class="visually-hidden">Pesquisar empresa cliente</label>
                            <div class="cliente-seletor" id="clienteSeletorFolhaPonto">
                                <i class="bi bi-search cliente-seletor-icone" aria-hidden="true"></i>
                                <input
                                    type="search"
                                    class="form-control cliente-seletor-input"
                                    id="clienteBuscaFolhaPonto"
                                    placeholder="Buscar por código, nome, CPF/CNPJ ou e-mail..."
                                    value="<?= $clienteId !== 0 ? htmlspecialchars($clienteNomeFolha) : '' ?>"
                                    autocomplete="off"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    <?= $clientesFolha === [] && $funcionariosSemCliente === 0 ? 'disabled' : '' ?>>
                                <div class="cliente-seletor-menu d-none" id="clienteSeletorFolhaPontoMenu">
                                    <div class="cliente-seletor-opcoes" id="clienteOpcoesFolhaPonto" role="listbox">
                                        <?php foreach ($clientesFolha as $clienteFolha): ?>
                                            <button
                                                type="button"
                                                class="cliente-seletor-opcao<?= (int)$clienteFolha['id'] === $clienteId ? ' selecionado' : '' ?>"
                                                data-id="<?= (int)$clienteFolha['id'] ?>"
                                                data-texto="<?= htmlspecialchars($clienteFolha['nome_exibicao'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-busca="<?= htmlspecialchars(trim(implode(' ', [
                                                                $clienteFolha['codigo'] ?? '',
                                                                $clienteFolha['documento'] ?? '',
                                                                $clienteFolha['nome'] ?? '',
                                                                $clienteFolha['nome_fantasia'] ?? '',
                                                                $clienteFolha['email'] ?? '',
                                                            ])), ENT_QUOTES, 'UTF-8') ?>"
                                                role="option"
                                                aria-selected="<?= (int)$clienteFolha['id'] === $clienteId ? 'true' : 'false' ?>">
                                                <strong><?= htmlspecialchars($clienteFolha['codigo'] ?: '-') ?></strong>
                                                <span><?= htmlspecialchars($clienteFolha['nome']) ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                        <?php if ($funcionariosSemCliente > 0): ?>
                                            <button
                                                type="button"
                                                class="cliente-seletor-opcao<?= $clienteId === -1 ? ' selecionado' : '' ?>"
                                                data-id="-1"
                                                data-texto="Sem empresa vinculada"
                                                data-busca="sem empresa vinculada antigos"
                                                role="option"
                                                aria-selected="<?= $clienteId === -1 ? 'true' : 'false' ?>">
                                                <strong>-</strong>
                                                <span>Sem empresa vinculada (<?= $funcionariosSemCliente ?>)</span>
                                            </button>
                                        <?php endif; ?>
                                        <div class="cliente-seletor-vazio d-none" id="clienteVazioFolhaPonto">
                                            Nenhum cliente encontrado.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="cliente_id" id="clienteFolhaPontoId" value="<?= $clienteId !== 0 ? $clienteId : '' ?>">
                            <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                            <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($mensagem): ?>
                    <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alerta-temporario no-print">
                        <?= htmlspecialchars($mensagem['texto']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$cargaSemanalDisponivel): ?>
                    <div class="alert alert-warning no-print">
                        Para liberar a jornada de 36 horas, execute <code>sql/folha_ponto_carga_semanal.sql</code> no banco de dados.
                    </div>
                <?php endif; ?>

                <?php if (!$vinculoClienteDisponivel): ?>
                    <div class="alert alert-warning no-print">
                        Para vincular funcionários às empresas de Clientes, execute <code>sql/folha_ponto_clientes.sql</code> no banco de dados.
                    </div>
                <?php endif; ?>

                <?php if ($funcionarioSelecionado): ?>
                    <section class="ponto-filtros mb-4 no-print">
                        <form method="get" id="formFiltrosPonto" class="ponto-filtros-form">
                            <div>
                                <label for="funcionarioPonto" class="form-label">Funcionário</label>
                                <select class="form-select" name="funcionario_id" id="funcionarioPonto">
                                    <?php if ($funcionarios === []): ?>
                                        <option value="">Nenhum funcionário cadastrado</option>
                                    <?php endif; ?>
                                    <?php foreach ($funcionarios as $funcionario): ?>
                                        <option value="<?= (int)$funcionario['id'] ?>" <?= (int)$funcionario['id'] === $funcionarioId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($funcionario['nome']) ?><?= (int)$funcionario['ativo'] === 1 ? '' : ' (inativo)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                            <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                            <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                        </form>

                        <div class="ponto-periodo-controle">
                            <label class="form-label" for="dataInicioPonto">Período</label>
                            <div class="ponto-navegacao-mes">
                                <a href="folha_ponto.php?<?= http_build_query([
                                                                'data_inicio' => $inicioPeriodoAnterior->format('Y-m-d'),
                                                                'data_fim' => $fimPeriodoAnterior->format('Y-m-d'),
                                                                'cliente_id' => $clienteId,
                                                                'funcionario_id' => $funcionarioId,
                                                            ]) ?>" class="btn btn-outline-secondary ponto-nav-anterior" title="Período anterior" aria-label="Período anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                                <form method="get" id="formPeriodoPonto">
                                    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                                    <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                                    <input type="date" class="form-control" name="data_inicio" id="dataInicioPonto" value="<?= htmlspecialchars($inicioPeriodo) ?>" aria-label="Data inicial">
                                    <span class="ponto-periodo-separador">a</span>
                                    <input type="date" class="form-control" name="data_fim" id="dataFimPonto" value="<?= htmlspecialchars($fimPeriodo) ?>" aria-label="Data final">
                                    <button type="submit" class="btn btn-primary ponto-periodo-aplicar">
                                        <i class="bi bi-check-lg"></i> Aplicar
                                    </button>
                                </form>
                                <a href="folha_ponto.php?<?= http_build_query([
                                                                'data_inicio' => $inicioPeriodoProximo->format('Y-m-d'),
                                                                'data_fim' => $fimPeriodoProximo->format('Y-m-d'),
                                                                'cliente_id' => $clienteId,
                                                                'funcionario_id' => $funcionarioId,
                                                            ]) ?>" class="btn btn-outline-secondary ponto-nav-proximo" title="Próximo período" aria-label="Próximo período">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                                <a href="folha_ponto.php?<?= http_build_query([
                                                                'data_inicio' => date('Y-m-01'),
                                                                'data_fim' => date('Y-m-t'),
                                                                'cliente_id' => $clienteId,
                                                                'funcionario_id' => $funcionarioId,
                                                            ]) ?>" class="btn btn-outline-primary ponto-nav-atual" title="Ir para o período atual" aria-label="Ir para o período atual">
                                    <i class="bi bi-calendar-check"></i>
                                </a>
                            </div>
                        </div>

                        <div class="ponto-acoes-filtro">
                            <?php if ($modoEdicaoFolha): ?>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary"
                                    id="btnEditarFuncionario"
                                    data-id="<?= (int)$funcionarioSelecionado['id'] ?>"
                                    data-nome="<?= htmlspecialchars($funcionarioSelecionado['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-cliente-id="<?= (int)($funcionarioSelecionado['cliente_id'] ?? 0) ?>"
                                    data-ativo="<?= (int)$funcionarioSelecionado['ativo'] ?>"
                                    data-carga-semanal="<?= $cargaContratualSelecionada ?>"
                                    data-horarios="<?= htmlspecialchars($horariosJson, ENT_QUOTES, 'UTF-8') ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalFuncionario">
                                    <i class="bi bi-pencil"></i> Jornada
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="btnSelecionarArquivoPonto">
                                    <i class="bi bi-file-earmark-arrow-up"></i> Importar PDF/Word
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-secondary" id="btnImprimirPonto">
                                <i class="bi bi-printer"></i> Imprimir
                            </button>
                        </div>
                    </section>
                    <?php if ($modoEdicaoFolha): ?>
                        <div class="alert alert-info d-none mb-4 no-print" id="avisoImportacaoDireta" role="status">
                            <span class="spinner-border spinner-border-sm me-2" id="spinnerImportacaoDireta" aria-hidden="true"></span>
                            <span id="textoImportacaoDireta">Lendo o arquivo...</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$vinculoClienteDisponivel): ?>
                    <section class="ponto-painel ponto-vazio">
                        <i class="bi bi-database-exclamation"></i>
                        <h5>Vínculo com Clientes indisponível</h5>
                        <p>Atualize o banco para organizar os funcionários pelas empresas cadastradas em Clientes.</p>
                    </section>
                <?php elseif ($clientesFolha === [] && $funcionariosSemCliente === 0): ?>
                    <section class="ponto-painel ponto-vazio">
                        <i class="bi bi-buildings"></i>
                        <h5>Nenhuma empresa cliente cadastrada</h5>
                        <p>Cadastre primeiro uma empresa na tela Clientes para incluir seus funcionários.</p>
                        <a href="clientes.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Ir para Clientes
                        </a>
                    </section>
                <?php elseif ($clienteId === 0): ?>
                    <section class="ponto-painel ponto-vazio">
                        <i class="bi bi-building-check"></i>
                        <h5>Selecione uma empresa cliente</h5>
                        <p>Escolha uma empresa acima para ver seus funcionários e folhas salvas.</p>
                    </section>
                <?php elseif ($funcionarios === []): ?>
                    <section class="ponto-painel ponto-vazio">
                        <i class="bi bi-person-badge"></i>
                        <h5>Nenhum funcionário cadastrado</h5>
                        <p><?= $clienteId > 0
                                ? 'Cadastre o primeiro funcionário de ' . htmlspecialchars($clienteNomeFolha) . '.'
                                : 'Edite um funcionário antigo para vinculá-lo a uma empresa cliente.' ?></p>
                        <?php if ($clienteId > 0): ?>
                            <button type="button" class="btn btn-primary" data-cliente-id="<?= $clienteId ?>" data-bs-toggle="modal" data-bs-target="#modalFuncionario">
                                <i class="bi bi-person-plus"></i> Cadastrar funcionário
                            </button>
                        <?php endif; ?>
                    </section>
                <?php elseif (!$funcionarioSelecionado): ?>
                    <section class="ponto-painel">
                        <div class="ponto-painel-titulo">
                            <div>
                                <h5 class="mb-1">Funcionários de <?= htmlspecialchars($clienteNomeFolha) ?></h5>
                                <p class="text-muted small mb-0">Abra um funcionário para consultar ou preencher a folha do período.</p>
                            </div>
                            <span class="badge bg-light text-dark border">
                                <?= count($funcionarios) ?> cadastrado<?= count($funcionarios) === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 ponto-funcionarios-tabela">
                                <thead>
                                    <tr>
                                        <th>Funcionário</th>
                                        <th>Status</th>
                                        <th>Jornada cadastrada</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($funcionarios as $funcionario): ?>
                                        <?php
                                        $idFuncionarioLista = (int)$funcionario['id'];
                                        $cargaContratualLista = $cargaSemanalDisponivel
                                            ? (int)($funcionario['carga_semanal'] ?? 44)
                                            : 44;
                                        $cargaContratualLista = in_array($cargaContratualLista, [36, 44], true)
                                            ? $cargaContratualLista
                                            : 44;
                                        $horariosLista = $horariosFuncionarios[$idFuncionarioLista] ?? folhaPontoHorarioPadrao();
                                        $cargaLista = 0;

                                        foreach ($horariosLista as $horarioLista) {
                                            if (!empty($horarioLista['trabalha'])) {
                                                $cargaLista += folhaPontoMinutosMarcacoes($horarioLista);
                                            }
                                        }

                                        $horariosListaJson = json_encode(
                                            array_values($horariosLista),
                                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                        );
                                        $urlFuncionario = 'folha_ponto.php?' . http_build_query([
                                            'data_inicio' => $inicioPeriodo,
                                            'data_fim' => $fimPeriodo,
                                            'cliente_id' => $clienteId,
                                            'funcionario_id' => $idFuncionarioLista,
                                        ]);
                                        ?>
                                        <tr>
                                            <td>
                                                <a class="ponto-funcionario-link" href="<?= htmlspecialchars($urlFuncionario) ?>">
                                                    <?= htmlspecialchars($funcionario['nome']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge <?= (int)$funcionario['ativo'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= (int)$funcionario['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= $cargaContratualLista ?>h contratuais
                                                <span class="text-muted">· <?= folhaPontoFormatarMinutos($cargaLista) ?> configuradas</span>
                                            </td>
                                            <td>
                                                <div class="ponto-acoes-funcionario">
                                                    <a
                                                        href="<?= htmlspecialchars($urlFuncionario) ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                        title="Abrir folha"
                                                        aria-label="Abrir folha de <?= htmlspecialchars($funcionario['nome'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-primary"
                                                        title="Editar funcionário"
                                                        aria-label="Editar <?= htmlspecialchars($funcionario['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-id="<?= $idFuncionarioLista ?>"
                                                        data-nome="<?= htmlspecialchars($funcionario['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-cliente-id="<?= (int)($funcionario['cliente_id'] ?? 0) ?>"
                                                        data-ativo="<?= (int)$funcionario['ativo'] ?>"
                                                        data-carga-semanal="<?= $cargaContratualLista ?>"
                                                        data-horarios="<?= htmlspecialchars($horariosListaJson, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalFuncionario">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        title="Excluir funcionário"
                                                        aria-label="Excluir <?= htmlspecialchars($funcionario['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-id="<?= $idFuncionarioLista ?>"
                                                        data-nome="<?= htmlspecialchars($funcionario['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalExcluirFuncionario">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($folhasSalvasDisponivel): ?>
                        <section class="ponto-painel mt-4">
                            <div class="ponto-painel-titulo">
                                <div>
                                    <h5 class="mb-1">Folhas salvas</h5>
                                    <p class="text-muted small mb-0">Períodos registrados de <?= htmlspecialchars($clienteNomeFolha) ?></p>
                                </div>
                                <span class="badge bg-light text-dark border">
                                    <?= count($folhasSalvas) ?> folha<?= count($folhasSalvas) === 1 ? '' : 's' ?>
                                </span>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 ponto-folhas-salvas-tabela">
                                    <thead>
                                        <tr>
                                            <th>Funcionário</th>
                                            <th>Período</th>
                                            <th>Última atualização</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($folhasSalvas === []): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">Nenhuma folha salva ainda.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($folhasSalvas as $folhaSalva): ?>
                                            <?php
                                            $urlFolhaSalva = 'folha_ponto.php?' . http_build_query([
                                                'folha_id' => (int)$folhaSalva['id'],
                                            ]);
                                            $urlEditarFolhaSalva = 'folha_ponto.php?' . http_build_query([
                                                'folha_id' => (int)$folhaSalva['id'],
                                                'editar' => 1,
                                            ]);
                                            $urlImprimirFolhaSalva = 'folha_ponto.php?' . http_build_query([
                                                'folha_id' => (int)$folhaSalva['id'],
                                                'imprimir' => 1,
                                            ]);
                                            ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($folhaSalva['funcionario_nome']) ?></strong></td>
                                                <td class="text-nowrap">
                                                    <?= date('d/m/Y', strtotime($folhaSalva['data_inicio'])) ?>
                                                    a
                                                    <?= date('d/m/Y', strtotime($folhaSalva['data_fim'])) ?>
                                                </td>
                                                <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($folhaSalva['atualizado_em'])) ?></td>
                                                <td>
                                                    <div class="ponto-acoes-funcionario">
                                                        <a href="<?= htmlspecialchars($urlFolhaSalva) ?>" class="btn btn-sm btn-outline-primary" title="Abrir folha" aria-label="Abrir folha">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?= htmlspecialchars($urlEditarFolhaSalva) ?>" class="btn btn-sm btn-outline-primary" title="Editar folha" aria-label="Editar folha">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="<?= htmlspecialchars($urlImprimirFolhaSalva) ?>" class="btn btn-sm btn-outline-secondary" title="Imprimir ou salvar em PDF" aria-label="Imprimir ou salvar em PDF">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ponto-impressao-cabecalho">
                        <div>
                            <h1>Folha de Ponto</h1>
                            <p><?= htmlspecialchars($clienteNomeFolha) ?> · <?= htmlspecialchars($funcionarioSelecionado['nome']) ?> · <?= htmlspecialchars($nomePeriodo) ?></p>
                        </div>
                        <div>
                            <strong><?= htmlspecialchars($clienteNomeFolha) ?></strong><br>
                            Emitido em <?= date('d/m/Y H:i') ?>
                        </div>
                    </div>

                    <?php if ($cargaSemanal > $cargaContratualSelecionada * 60): ?>
                        <div class="alert alert-danger no-print">
                            <strong>Jornada acima da carga contratual.</strong>
                            A carga cadastrada soma <?= folhaPontoFormatarMinutos($cargaSemanal) ?> para um contrato de <?= $cargaContratualSelecionada ?> horas semanais.
                        </div>
                    <?php endif; ?>

                    <section class="ponto-resumo mb-4">
                        <div class="ponto-metrica metrica-jornada">
                            <span>Jornada semanal</span>
                            <strong><?= folhaPontoFormatarMinutos($jornadaSemanalReferencia) ?></strong>
                        </div>
                        <div class="ponto-metrica metrica-prevista">
                            <span>Referência mensal</span>
                            <strong><?= folhaPontoFormatarMinutos($referenciaMensal) ?></strong>
                        </div>
                        <div class="ponto-metrica metrica-trabalhada">
                            <span>Horas registradas</span>
                            <strong id="totalHorasRegistradas"><?= folhaPontoFormatarMinutos($totalTrabalhadoMes) ?></strong>
                        </div>
                        <div class="ponto-metrica <?= $saldoAteHoje < 0 ? 'metrica-negativa' : 'metrica-positiva' ?>" id="metricaSaldoAteHoje">
                            <span>Saldo até hoje</span>
                            <strong id="totalSaldoAteHoje"><?= folhaPontoFormatarMinutos($saldoAteHoje, true) ?></strong>
                        </div>
                        <div class="ponto-metrica <?= $diasSemRegistro > 0 ? 'metrica-negativa' : 'metrica-positiva' ?>" id="metricaDiasSemRegistro">
                            <span>Dias sem registro</span>
                            <strong id="totalDiasSemRegistro"><?= $diasSemRegistro ?></strong>
                        </div>
                    </section>

                    <form
                        method="post"
                        id="formRegistrosPonto"
                        class="<?= !$modoEdicaoFolha ? 'folha-modo-leitura' : '' ?>"
                        data-hoje="<?= htmlspecialchars($hoje) ?>"
                        data-modo-leitura="<?= !$modoEdicaoFolha ? '1' : '0' ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                        <input type="hidden" name="acao" value="salvar_registros">
                        <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                        <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                        <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                        <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                        <?php if ($folhaId > 0): ?>
                            <input type="hidden" name="folha_id" value="<?= $folhaId ?>">
                            <?php if ($modoEdicaoFolha): ?>
                                <input type="hidden" name="editar" value="1">
                            <?php endif; ?>
                        <?php endif; ?>

                        <section class="ponto-painel">
                            <div class="ponto-painel-titulo no-print">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <h5 class="mb-0"><?= htmlspecialchars($funcionarioSelecionado['nome']) ?></h5>
                                        <?php if ($folhaSelecionada): ?>
                                            <span class="badge <?= $modoEdicaoFolha ? 'bg-warning text-dark' : 'bg-success' ?>">
                                                <?= $modoEdicaoFolha ? 'Editando folha salva' : 'Folha salva' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted small mb-0">
                                        <?= htmlspecialchars($nomePeriodo) ?>
                                        <?= $modoEdicaoFolha ? ' · totais atualizados durante o preenchimento' : ' · somente leitura' ?>
                                    </p>
                                </div>
                                <div class="ponto-painel-acoes">
                                    <?php if ($folhaSelecionada && !$modoEdicaoFolha): ?>
                                        <a href="<?= htmlspecialchars($urlEditarFolhaAtual) ?>" class="btn btn-primary">
                                            <i class="bi bi-pencil"></i> Editar folha
                                        </a>
                                    <?php else: ?>
                                        <?php if ($folhaSelecionada): ?>
                                            <a href="<?= htmlspecialchars($urlFolhaAtual) ?>" class="btn btn-outline-secondary">
                                                <i class="bi bi-x-lg"></i> Cancelar edição
                                            </a>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary"
                                            id="btnCompletarPelaJornada"
                                            title="Preenche somente horários vazios, sem alterar os já informados">
                                            <i class="bi bi-calendar2-check"></i> Completar pela jornada
                                        </button>
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-lg"></i> Salvar folha
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($modoEdicaoFolha): ?>
                                <div class="alert d-none mx-3 mt-3 mb-0 no-print" id="avisoCompletarPelaJornada" role="status"></div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 ponto-tabela">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Dia</th>
                                            <th>Jornada prevista</th>
                                            <th>Entrada</th>
                                            <th><?= $cargaContratualSelecionada === 36 ? 'Saída' : 'Almoço' ?></th>
                                            <?php if ($cargaContratualSelecionada === 44): ?>
                                                <th>Retorno</th>
                                                <th>Saída</th>
                                            <?php endif; ?>
                                            <th>Trabalhado</th>
                                            <th>Saldo</th>
                                            <th>Status</th>
                                            <th>Observação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diasFolha as $dia): ?>
                                            <?php
                                            $previstoTexto = !empty($dia['feriado'])
                                                ? 'Feriado' . (!empty($dia['feriado_nome']) ? ': ' . $dia['feriado_nome'] : '')
                                                : (!empty($dia['atestado'])
                                                    ? 'Atestado'
                                                    : (!empty($dia['folga_informada'])
                                                        ? 'Folga'
                                                        : (!empty($dia['horario']['trabalha'])
                                                            ? trim(implode(' / ', array_filter([
                                                                ($dia['horario']['entrada_1'] ?? '') && ($dia['horario']['saida_1'] ?? '')
                                                                    ? substr((string)$dia['horario']['entrada_1'], 0, 5) . '-' . substr((string)$dia['horario']['saida_1'], 0, 5)
                                                                    : '',
                                                                ($dia['horario']['entrada_2'] ?? '') && ($dia['horario']['saida_2'] ?? '')
                                                                    ? substr((string)$dia['horario']['entrada_2'], 0, 5) . '-' . substr((string)$dia['horario']['saida_2'], 0, 5)
                                                                    : '',
                                                            ])))
                                                            : 'Folga')));
                                            ?>
                                            <tr
                                                class="ponto-registro-linha<?= $dia['previsto'] <= 0 ? ' ponto-dia-folga' : '' ?><?= !empty($dia['fim_semana']) ? ' ponto-dia-fim-semana' : '' ?><?= !empty($dia['feriado']) ? ' ponto-dia-feriado' : '' ?><?= !empty($dia['atestado']) ? ' ponto-dia-atestado' : '' ?>"
                                                data-data="<?= htmlspecialchars($dia['data']) ?>"
                                                data-feriado-nacional="<?= !empty($dia['feriado_nacional']) ? '1' : '0' ?>"
                                                data-feriado-nome="<?= htmlspecialchars($dia['feriado_nome'] ?? '') ?>"
                                                data-trabalha="<?= !empty($dia['horario']['trabalha']) ? '1' : '0' ?>"
                                                data-jornada-prevista="<?= htmlspecialchars(!empty($dia['horario']['trabalha'])
                                                                            ? trim(implode(' / ', array_filter([
                                                                                ($dia['horario']['entrada_1'] ?? '') && ($dia['horario']['saida_1'] ?? '')
                                                                                    ? substr((string)$dia['horario']['entrada_1'], 0, 5) . '-' . substr((string)$dia['horario']['saida_1'], 0, 5)
                                                                                    : '',
                                                                                ($dia['horario']['entrada_2'] ?? '') && ($dia['horario']['saida_2'] ?? '')
                                                                                    ? substr((string)$dia['horario']['entrada_2'], 0, 5) . '-' . substr((string)$dia['horario']['saida_2'], 0, 5)
                                                                                    : '',
                                                                            ])))
                                                                            : 'Folga') ?>">
                                                <td class="text-nowrap"><strong><?= $dia['data_br'] ?></strong></td>
                                                <td class="text-nowrap"><?= htmlspecialchars($dia['dia_semana']) ?></td>
                                                <td class="text-nowrap ponto-previsto"><?= htmlspecialchars($previstoTexto) ?></td>
                                                <?php $camposRegistro = $cargaContratualSelecionada === 36
                                                    ? ['entrada_1', 'saida_1']
                                                    : ['entrada_1', 'saida_1', 'entrada_2', 'saida_2']; ?>
                                                <?php foreach ($camposRegistro as $campo): ?>
                                                    <?php
                                                    $valorRegistroCampo = (string)($dia['registro'][$campo] ?? '');
                                                    $valorJornadaCampo = substr((string)($dia['horario'][$campo] ?? ''), 0, 5);
                                                    $horarioNaoReconhecido = !empty($dia['importacao_pendente'])
                                                        && empty($dia['feriado'])
                                                        && empty($dia['atestado'])
                                                        && empty($dia['folga_informada'])
                                                        && $valorJornadaCampo !== ''
                                                        && $valorRegistroCampo === '';
                                                    ?>
                                                    <td>
                                                        <input
                                                            type="time"
                                                            class="form-control form-control-sm ponto-hora-registro<?= $horarioNaoReconhecido ? ' ponto-hora-nao-reconhecida' : '' ?>"
                                                            name="registros[<?= $dia['data'] ?>][<?= $campo ?>]"
                                                            data-campo="<?= htmlspecialchars($campo) ?>"
                                                            data-horario-jornada="<?= htmlspecialchars($valorJornadaCampo) ?>"
                                                            data-importacao-pendente="<?= $horarioNaoReconhecido ? '1' : '0' ?>"
                                                            value="<?= htmlspecialchars($valorRegistroCampo) ?>"
                                                            <?= !$modoEdicaoFolha ? 'readonly' : '' ?>
                                                            <?= $horarioNaoReconhecido ? 'title="Horário não reconhecido. Preencha manualmente ou use Completar pela jornada."' : '' ?>
                                                            aria-label="<?= htmlspecialchars($campo . ' de ' . $dia['data_br']) ?>">
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="text-nowrap ponto-total-dia" data-previsto="<?= $dia['previsto'] ?>" data-previsto-jornada="<?= $dia['previsto_jornada'] ?>">
                                                    <?= folhaPontoFormatarMinutos($dia['trabalhado']) ?>
                                                </td>
                                                <td class="text-nowrap fw-semibold ponto-saldo-dia <?= $dia['saldo'] < 0 ? 'text-danger' : 'text-success' ?>">
                                                    <?= folhaPontoFormatarMinutos($dia['saldo'], true) ?>
                                                </td>
                                                <td><span class="badge ponto-status-dia <?= $dia['status'][1] ?>"><?= $dia['status'][0] ?></span></td>
                                                <td>
                                                    <div class="ponto-observacao-grupo">
                                                        <div class="form-check ponto-atestado-controle">
                                                            <input
                                                                type="checkbox"
                                                                class="form-check-input ponto-atestado"
                                                                name="registros[<?= $dia['data'] ?>][atestado]"
                                                                value="1"
                                                                id="atestado-<?= htmlspecialchars($dia['data']) ?>"
                                                                <?= !$modoEdicaoFolha ? 'disabled' : '' ?>
                                                                <?= !empty($dia['atestado']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="atestado-<?= htmlspecialchars($dia['data']) ?>">Atestado</label>
                                                        </div>
                                                        <input
                                                            type="text"
                                                            class="form-control form-control-sm ponto-observacao"
                                                            name="registros[<?= $dia['data'] ?>][observacao]"
                                                            value="<?= htmlspecialchars($dia['observacao_exibicao'] ?? '') ?>"
                                                            maxlength="245"
                                                            <?= !$modoEdicaoFolha ? 'readonly' : '' ?>
                                                            placeholder="Observação opcional">
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($modoEdicaoFolha): ?>
                                <div class="ponto-rodape no-print">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-lg"></i> Salvar folha
                                    </button>
                                </div>
                            <?php endif; ?>
                        </section>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalFuncionario" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" id="formFuncionario" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                        <input type="hidden" name="acao" value="salvar_funcionario">
                        <input type="hidden" name="id" id="funcionarioIdModal">
                        <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                        <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                        <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="tituloModalFuncionario">Novo funcionário</h5>
                                <p class="text-muted mb-0">Cadastre somente o nome e defina os horários de cada dia</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="funcionarioClienteBusca" class="form-label">Empresa cliente</label>
                                    <div class="cliente-seletor" id="funcionarioClienteSeletor">
                                        <i class="bi bi-search cliente-seletor-icone" aria-hidden="true"></i>
                                        <input
                                            type="search"
                                            class="form-control cliente-seletor-input"
                                            id="funcionarioClienteBusca"
                                            placeholder="Digite o código ou a razão social"
                                            autocomplete="off"
                                            aria-haspopup="listbox"
                                            aria-expanded="false">
                                        <div class="cliente-seletor-menu d-none" id="funcionarioClienteMenu">
                                            <div class="cliente-seletor-opcoes" id="funcionarioClienteOpcoes" role="listbox">
                                                <?php foreach ($clientesFolha as $clienteFolha): ?>
                                                    <button
                                                        type="button"
                                                        class="cliente-seletor-opcao"
                                                        data-id="<?= (int)$clienteFolha['id'] ?>"
                                                        data-texto="<?= htmlspecialchars($clienteFolha['nome_exibicao'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-busca="<?= htmlspecialchars(trim(implode(' ', [
                                                                        $clienteFolha['codigo'] ?? '',
                                                                        $clienteFolha['documento'] ?? '',
                                                                        $clienteFolha['nome'] ?? '',
                                                                        $clienteFolha['nome_fantasia'] ?? '',
                                                                        $clienteFolha['email'] ?? '',
                                                                    ])), ENT_QUOTES, 'UTF-8') ?>"
                                                        role="option"
                                                        aria-selected="false">
                                                        <strong><?= htmlspecialchars($clienteFolha['codigo'] ?: '-') ?></strong>
                                                        <span><?= htmlspecialchars($clienteFolha['nome']) ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                                <div class="cliente-seletor-vazio d-none" id="funcionarioClienteVazio">
                                                    Nenhum cliente encontrado.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="cliente_id" id="funcionarioCliente" value="">
                                    <div class="invalid-feedback" id="funcionarioClienteFeedback">Selecione a empresa do funcionário.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="funcionarioNome" class="form-label">Nome do funcionário</label>
                                    <input type="text" class="form-control" name="nome" id="funcionarioNome" maxlength="150" required>
                                    <div class="invalid-feedback">Informe o nome do funcionário.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch mb-2 d-none" id="grupoFuncionarioAtivo">
                                        <input class="form-check-input" type="checkbox" role="switch" name="ativo" value="1" id="funcionarioAtivo" checked>
                                        <label class="form-check-label" for="funcionarioAtivo">Funcionário ativo</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                                <div>
                                    <h6 class="mb-1">Jornada semanal</h6>
                                    <div class="btn-group mt-2" role="group" aria-label="Carga semanal contratual">
                                        <input type="radio" class="btn-check jornada-carga-opcao" name="carga_semanal" id="jornadaCarga44" value="44" checked autocomplete="off">
                                        <label class="btn btn-outline-primary" for="jornadaCarga44">44 horas</label>

                                        <input type="radio" class="btn-check jornada-carga-opcao" name="carga_semanal" id="jornadaCarga36" value="36" autocomplete="off" <?= !$cargaSemanalDisponivel ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-primary" for="jornadaCarga36">36 horas</label>
                                    </div>
                                </div>
                                <div class="ponto-jornada-acoes">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        id="btnReplicarJornada"
                                        title="Copiar os horários do primeiro dia preenchido">
                                        <i class="bi bi-files"></i> Repetir horários
                                    </button>
                                    <div class="ponto-carga-semanal" id="cargaSemanalModal">44h00 por semana</div>
                                </div>
                            </div>
                            <div class="text-danger small fw-semibold d-none mb-3" id="avisoReplicarJornada" role="alert">
                                Preencha corretamente os horários do primeiro dia trabalhado antes de copiar.
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle ponto-jornada-tabela mb-0">
                                    <thead>
                                        <tr>
                                            <th>Dia</th>
                                            <th>Trabalha</th>
                                            <th>Entrada</th>
                                            <th id="cabecalhoSaidaPrimeiroPeriodo">Almoço</th>
                                            <th class="jornada-intervalo-coluna">Retorno</th>
                                            <th class="jornada-intervalo-coluna">Saída</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (folhaPontoNomesDias() as $numeroDia => $nomeDia): ?>
                                            <tr class="jornada-linha" data-dia="<?= $numeroDia ?>">
                                                <td><strong><?= htmlspecialchars($nomeDia) ?></strong></td>
                                                <td>
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input jornada-trabalha" type="checkbox" role="switch" name="horarios[<?= $numeroDia ?>][trabalha]" value="1">
                                                    </div>
                                                </td>
                                                <?php foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo): ?>
                                                    <td class="<?= in_array($campo, ['entrada_2', 'saida_2'], true) ? 'jornada-intervalo-coluna' : '' ?>">
                                                        <input type="time" class="form-control form-control-sm jornada-hora" name="horarios[<?= $numeroDia ?>][<?= $campo ?>]" data-campo="<?= $campo ?>">
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="text-nowrap fw-semibold jornada-total">0h00</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="alert alert-warning mt-3 mb-0 d-none" id="avisoCargaSemanal">
                                A jornada cadastrada ultrapassa a carga semanal escolhida. O sistema permitirá salvar, mas manterá o alerta.
                            </div>
                        </div>
                        <div class="modal-footer justify-content-between">
                            <button type="button" class="btn btn-outline-danger d-none" id="btnExcluirFuncionario">
                                <i class="bi bi-trash"></i> Excluir funcionário
                            </button>
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg"></i> Salvar funcionário
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($funcionarios !== []): ?>
            <div class="modal fade" id="modalExcluirFuncionario" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                            <input type="hidden" name="acao" value="excluir_funcionario">
                            <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                            <input type="hidden" name="id" id="funcionarioExcluirId" value="<?= $funcionarioSelecionado ? $funcionarioId : '' ?>">
                            <input type="hidden" name="funcionario_id" value="<?= $funcionarioSelecionado ? $funcionarioId : '' ?>">
                            <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                            <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Excluir funcionário</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <p>Tem certeza que deseja excluir <strong id="funcionarioExcluirNome"><?= htmlspecialchars($funcionarioSelecionado['nome'] ?? 'este funcionário') ?></strong>?</p>
                                <div class="alert alert-danger mb-0">
                                    Os horários e todas as folhas mensais desse funcionário também serão excluídos. Esta ação não poderá ser desfeita.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Não, voltar</button>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-trash"></i> Sim, excluir
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($funcionarioSelecionado && $modoEdicaoFolha): ?>
            <div class="modal fade" id="modalImportarPdf" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable ponto-modal-importacao">
                    <div class="modal-content">
                        <form method="post" id="formImportarPdf">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                            <input type="hidden" name="acao" value="importar_pdf">
                            <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                            <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>" data-mes-original="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($inicioPeriodo) ?>">
                            <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimPeriodo) ?>">
                            <?php if ($folhaId > 0): ?>
                                <input type="hidden" name="folha_id" value="<?= $folhaId ?>">
                                <input type="hidden" name="editar" value="1">
                            <?php endif; ?>
                            <input type="hidden" name="registros_pdf" id="registrosPdf">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title">Importar folha de ponto</h5>
                                    <p class="text-muted mb-0"><?= htmlspecialchars($funcionarioSelecionado['nome']) ?> · <span id="mesImportacaoPdf"><?= htmlspecialchars($nomeMes) ?></span></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    Aceita PDF de ponto eletrônico e Word com texto ou tabela. Em PDFs digitalizados ou fotografados, o sistema mostra o recorte de cada marcação e só preenche horários realmente reconhecidos.
                                </div>
                                <label for="arquivoPontoPdf" class="form-label">Arquivo PDF ou Word</label>
                                <input type="file" class="form-control" id="arquivoPontoPdf" accept="application/pdf,.pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,.docx">
                                <div class="invalid-feedback">Selecione um arquivo PDF ou Word (.docx).</div>
                                <div class="alert alert-primary d-none mt-3" id="avisoMesImportacaoPdf"></div>
                                <div class="alert alert-danger d-none mt-3" id="erroImportacaoPdf"></div>
                                <div class="ponto-importacao-status d-none mt-3" id="statusImportacaoPdf">
                                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                    <span id="statusImportacaoTexto">Lendo o arquivo...</span>
                                </div>
                                <div class="mt-4 d-none" id="previewImportacaoPdf">
                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                        <h6 class="mb-0">Pré-visualização</h6>
                                        <span class="badge bg-primary" id="quantidadeImportacaoPdf"></span>
                                    </div>
                                    <div class="alert alert-warning py-2 d-none" id="avisoRevisaoOcr">
                                        Este PDF não possui horários em texto. Os campos amarelos foram reconhecidos a partir dos recortes; os campos vermelhos permaneceram vazios e podem ser preenchidos manualmente com 8, 12 ou 1320.
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0 ponto-preview-tabela">
                                            <thead>
                                                <tr>
                                                    <th>Data</th>
                                                    <th>Dia da semana</th>
                                                    <th>Situação</th>
                                                    <th>Entrada</th>
                                                    <th><?= $cargaContratualSelecionada === 36 ? 'Saída' : 'Almoço' ?></th>
                                                    <?php if ($cargaContratualSelecionada === 44): ?>
                                                        <th>Retorno</th>
                                                        <th>Saída</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody id="corpoImportacaoPdf"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-success" id="btnConfirmarImportacaoPdf" disabled>
                                    <i class="bi bi-upload"></i> Confirmar importação
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@7.0.0/dist/tesseract.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.11.0/mammoth.browser.min.js"></script>
    <script src="<?= assetUrl('assets/folha_ponto.js') ?>"></script>
</body>

</html>