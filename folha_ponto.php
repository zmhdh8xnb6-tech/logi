<?php
require 'config.php';
require 'includes/folha_ponto_funcoes.php';

function folhaPontoFeriadoNacional(string $data): ?string
{
    $dataValida = DateTime::createFromFormat('!Y-m-d', $data);

    if (!$dataValida || $dataValida->format('Y-m-d') !== $data) {
        return null;
    }

    $ano = (int)$dataValida->format('Y');
    $mesDia = $dataValida->format('m-d');
    $feriados = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência do Brasil',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '12-25' => 'Natal',
    ];

    if ($ano >= 1980) {
        $feriados['10-12'] = 'Nossa Senhora Aparecida';
    }

    if ($ano >= 2024) {
        $feriados['11-20'] = 'Dia Nacional de Zumbi e da Consciência Negra';
    }

    return $feriados[$mesDia] ?? null;
}

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

$empresaId = (int)(empresaAtivaId($pdo) ?? 1);
$empresaId = $empresaId > 0 ? $empresaId : 1;
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mes = folhaPontoMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$proximoMes = date('Y-m', strtotime($inicioMes . ' +1 month'));
$funcionarioId = (int)($_GET['funcionario_id'] ?? $_POST['funcionario_id'] ?? 0);
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
$nomeMes = $nomesMeses[(int)date('n', strtotime($inicioMes))]
    . '/'
    . date('Y', strtotime($inicioMes));

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

if ($estruturaDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $urlRetorno = 'folha_ponto.php?' . http_build_query([
        'mes' => $mes,
        'funcionario_id' => $funcionarioId,
    ]);

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
                'folha_ponto.php?' . http_build_query(['mes' => $mes]),
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
        $nome = trim((string)($_POST['nome'] ?? ''));
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        $horariosEntrada = is_array($_POST['horarios'] ?? null) ? $_POST['horarios'] : [];
        $horarios = [];
        $cargaSemanal = 0;
        $funcionarioAntes = $id > 0 ? $buscarFuncionario($pdo, $empresaId, $id) : null;

        if ($nome === '' || (function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome)) > 150) {
            folhaPontoRedirecionar($urlRetorno, 'Informe corretamente o nome do funcionário.', 'danger');
        }

        if ($id > 0 && !$funcionarioAntes) {
            folhaPontoRedirecionar($urlRetorno, 'Funcionário não encontrado nesta empresa.', 'danger');
        }

        try {
            for ($dia = 1; $dia <= 7; $dia++) {
                $horarios[$dia] = folhaPontoNormalizarHorario($horariosEntrada[$dia] ?? [], $dia);
                $cargaSemanal += folhaPontoMinutosMarcacoes($horarios[$dia]);
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE folha_ponto_funcionarios
                    SET nome = ?, ativo = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([$nome, $ativo, $id, $empresaId]);
                $funcionarioId = $id;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO folha_ponto_funcionarios (empresa_id, nome, ativo)
                    VALUES (?, ?, 1)
                ");
                $stmt->execute([$empresaId, $nome]);
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
                    'nome' => $nome,
                    'ativo' => $ativo,
                    'carga_semanal' => folhaPontoFormatarMinutos($cargaSemanal),
                ]
            );

            $mensagem = $id > 0
                ? 'Funcionário e jornada atualizados com sucesso.'
                : 'Funcionário cadastrado com sucesso.';

            if ($cargaSemanal > 44 * 60) {
                $mensagem .= ' Atenção: a jornada informada ultrapassa 44 horas semanais.';
            }

            folhaPontoRedirecionar(
                'folha_ponto.php?' . http_build_query([
                    'mes' => $mes,
                    'funcionario_id' => $funcionarioId,
                ]),
                $mensagem,
                $cargaSemanal > 44 * 60 ? 'warning' : 'success'
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
                    observacao = VALUES(observacao),
                    origem = 'manual',
                    usuario_id = VALUES(usuario_id),
                    atualizado_em = CURRENT_TIMESTAMP
            ");
            $stmtExcluir = $pdo->prepare("
                DELETE FROM folha_ponto_registros
                WHERE empresa_id = ? AND funcionario_id = ? AND data_registro = ?
            ");
            $quantidadeAlterada = 0;

            foreach ($registrosEntrada as $data => $dados) {
                if (!is_array($dados) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                    continue;
                }

                if ($data < $inicioMes || $data >= $fimMes || date('Y-m-d', strtotime($data)) !== $data) {
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

                $atestado = !empty($dados['atestado']);

                if ($atestado) {
                    $valores = [
                        'entrada_1' => null,
                        'saida_1' => null,
                        'entrada_2' => null,
                        'saida_2' => null,
                    ];
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

                $observacaoComplementar = trim((string)($dados['observacao'] ?? ''));
                $observacao = $atestado
                    ? 'Atestado' . ($observacaoComplementar !== '' ? ': ' . $observacaoComplementar : '')
                    : $observacaoComplementar;
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

            $pdo->commit();
            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                'editar',
                'registros_ponto',
                $funcionarioId,
                'Atualizou a folha de ' . $funcionario['nome'] . ' em ' . $nomeMes,
                null,
                ['registros_preenchidos' => $quantidadeAlterada]
            );
            folhaPontoRedirecionar($urlRetorno, 'Folha mensal salva com sucesso.');
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

        if (!is_array($registrosImportados) || $registrosImportados === [] || count($registrosImportados) > 31) {
            folhaPontoRedirecionar($urlRetorno, 'Nenhum registro válido foi encontrado no PDF.', 'danger');
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
                    entrada_1 = VALUES(entrada_1),
                    saida_1 = VALUES(saida_1),
                    entrada_2 = VALUES(entrada_2),
                    saida_2 = VALUES(saida_2),
                    observacao = VALUES(observacao),
                    origem = 'pdf',
                    usuario_id = VALUES(usuario_id),
                    atualizado_em = CURRENT_TIMESTAMP
            ");
            $datasProcessadas = [];

            foreach ($registrosImportados as $registro) {
                if (!is_array($registro)) {
                    continue;
                }

                $data = trim((string)($registro['data'] ?? ''));

                if (
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)
                    || $data < $inicioMes
                    || $data >= $fimMes
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

                $observacao = trim((string)($registro['observacao'] ?? ''));
                $observacao = function_exists('mb_substr')
                    ? mb_substr($observacao, 0, 255)
                    : substr($observacao, 0, 255);
                $feriado = preg_match('/^feriado\b/i', $observacao) === 1;
                $folga = preg_match('/^folga\b/i', $observacao) === 1;
                $atestado = preg_match('/^atestado\b/i', $observacao) === 1;

                if ($folga || $atestado) {
                    $valores = [
                        'entrada_1' => null,
                        'saida_1' => null,
                        'entrada_2' => null,
                        'saida_2' => null,
                    ];
                }

                if (array_filter($valores, static fn($valor) => $valor !== null) === [] && !$feriado && !$folga && !$atestado) {
                    continue;
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

            if ($datasProcessadas === []) {
                throw new RuntimeException('O PDF não produziu horários válidos para o mês selecionado.');
            }

            $pdo->commit();
            registrarAuditoria(
                $pdo,
                'Folha de Ponto',
                'importar',
                'registros_ponto',
                $funcionarioId,
                'Importou registros de ponto em PDF para ' . $funcionario['nome'],
                null,
                ['mes' => $mes, 'dias_importados' => count($datasProcessadas)]
            );
            folhaPontoRedirecionar(
                $urlRetorno,
                count($datasProcessadas) . ' dia' . (count($datasProcessadas) === 1 ? '' : 's') . ' importado' . (count($datasProcessadas) === 1 ? '' : 's') . ' do PDF.'
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

if ($estruturaDisponivel) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM folha_ponto_funcionarios
        WHERE empresa_id = ?
        ORDER BY ativo DESC, nome ASC, id ASC
    ");
    $stmt->execute([$empresaId]);
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $funcionarioSelecionado = $buscarFuncionario($pdo, $empresaId, $funcionarioId);

    if (!$funcionarioSelecionado) {
        $funcionarioId = 0;
    }

    if ($funcionarios !== []) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM folha_ponto_horarios
            WHERE empresa_id = ?
            ORDER BY funcionario_id, dia_semana
        ");
        $stmt->execute([$empresaId]);

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
        $stmt->execute([$empresaId, $funcionarioId, $inicioMes, $fimMes]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registro) {
            foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                $registro[$campo] = !empty($registro[$campo]) ? substr($registro[$campo], 0, 5) : '';
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
    $data = new DateTime($inicioMes);
    $limite = new DateTime($fimMes);

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
        $feriadoInformado = preg_match('/^feriado\b/i', $observacaoRegistro) === 1;
        $folgaInformada = preg_match('/^folga\b/i', $observacaoRegistro) === 1;
        $atestadoInformado = preg_match('/^atestado\b/i', $observacaoRegistro) === 1;
        $observacaoExibida = $atestadoInformado
            ? trim((string)preg_replace('/^atestado\s*:?\s*/i', '', $observacaoRegistro))
            : $observacaoRegistro;
        $feriadoNacional = folhaPontoFeriadoNacional($dataIso);
        $feriado = $feriadoInformado || $feriadoNacional !== null;
        $feriadoNome = $feriadoNacional ?? '';

        if ($feriadoInformado) {
            $feriadoNomeInformado = trim((string)preg_replace('/^feriado\s*:?\s*/i', '', $observacaoRegistro));

            if ($feriadoNomeInformado !== '') {
                $feriadoNome = $feriadoNomeInformado;
            }
        }

        $previstoJornada = !empty($horario['trabalha']) ? folhaPontoMinutosMarcacoes($horario) : 0;
        $previsto = $feriado || $folgaInformada || $atestadoInformado ? 0 : $previstoJornada;

        if ($atestadoInformado) {
            foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
                $registro[$campo] = '';
            }
        }

        $trabalhado = $atestadoInformado ? 0 : folhaPontoMinutosMarcacoes($registro);
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
        } elseif ($atestadoInformado) {
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
            'feriado_nacional' => $feriadoNacional !== null,
            'feriado_nome' => $feriadoNome,
            'atestado' => $atestadoInformado,
            'observacao_exibida' => $observacaoExibida,
            'fim_semana' => $diaSemana >= 6,
        ];
        $data->modify('+1 day');
    }
}

$saldoAteHoje = $totalTrabalhadoAteHoje - $totalPrevistoAteHoje;
$jornadaSemanalReferencia = 44 * 60;
$referenciaMensal = 220 * 60;
$horariosJson = json_encode(array_values($horarios), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    <p class="text-muted mb-0">Jornadas semanais e registros mensais dos funcionários</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= $funcionarioSelecionado ? 'folha_ponto.php?' . http_build_query(['mes' => $mes]) : 'home.php' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <?php if ($estruturaDisponivel): ?>
                        <button type="button" class="btn btn-primary" id="btnNovoFuncionario" data-bs-toggle="modal" data-bs-target="#modalFuncionario">
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
                <?php if ($mensagem): ?>
                    <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alerta-temporario no-print">
                        <?= htmlspecialchars($mensagem['texto']) ?>
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
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        </form>

                        <div class="ponto-navegacao-mes">
                            <a href="folha_ponto.php?<?= http_build_query(['mes' => $mesAnterior, 'funcionario_id' => $funcionarioId]) ?>" class="btn btn-outline-secondary ponto-nav-anterior" title="Mês anterior" aria-label="Mês anterior">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <form method="get" id="formMesPonto">
                                <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                                <label for="mesPonto" class="visually-hidden">Escolher mês</label>
                                <input type="month" class="form-control" name="mes" id="mesPonto" value="<?= htmlspecialchars($mes) ?>">
                            </form>
                            <a href="folha_ponto.php?<?= http_build_query(['mes' => $proximoMes, 'funcionario_id' => $funcionarioId]) ?>" class="btn btn-outline-secondary ponto-nav-proximo" title="Próximo mês" aria-label="Próximo mês">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="folha_ponto.php?<?= http_build_query(['mes' => date('Y-m'), 'funcionario_id' => $funcionarioId]) ?>" class="btn btn-outline-primary ponto-nav-atual" title="Ir para o mês atual" aria-label="Ir para o mês atual">
                                <i class="bi bi-calendar-check"></i>
                            </a>
                        </div>

                        <div class="ponto-acoes-filtro">
                            <button
                                type="button"
                                class="btn btn-outline-primary"
                                id="btnEditarFuncionario"
                                data-id="<?= (int)$funcionarioSelecionado['id'] ?>"
                                data-nome="<?= htmlspecialchars($funcionarioSelecionado['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                data-ativo="<?= (int)$funcionarioSelecionado['ativo'] ?>"
                                data-horarios="<?= htmlspecialchars($horariosJson, ENT_QUOTES, 'UTF-8') ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#modalFuncionario">
                                <i class="bi bi-pencil"></i> Jornada
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportarPdf">
                                <i class="bi bi-file-earmark-pdf"></i> Importar PDF
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnImprimirPonto">
                                <i class="bi bi-printer"></i> Imprimir
                            </button>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($funcionarios === []): ?>
                    <section class="ponto-painel ponto-vazio">
                        <i class="bi bi-person-badge"></i>
                        <h5>Nenhum funcionário cadastrado</h5>
                        <p>Cadastre o nome e defina a jornada semanal para começar.</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalFuncionario">
                            <i class="bi bi-person-plus"></i> Cadastrar funcionário
                        </button>
                    </section>
                <?php elseif (!$funcionarioSelecionado): ?>
                    <section class="ponto-painel">
                        <div class="ponto-painel-titulo">
                            <div>
                                <h5 class="mb-1">Funcionários</h5>
                                <p class="text-muted small mb-0">Abra um funcionário para consultar ou preencher a folha mensal.</p>
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
                                            'mes' => $mes,
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
                                            <td><?= folhaPontoFormatarMinutos($cargaLista) ?> por semana</td>
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
                                                        data-ativo="<?= (int)$funcionario['ativo'] ?>"
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
                <?php else: ?>
                    <div class="ponto-impressao-cabecalho">
                        <div>
                            <h1>Folha de Ponto</h1>
                            <p><?= htmlspecialchars($funcionarioSelecionado['nome']) ?> · <?= htmlspecialchars($nomeMes) ?></p>
                        </div>
                        <div>
                            <strong><?= htmlspecialchars(empresaAtivaNome($pdo) ?: 'Logi') ?></strong><br>
                            Emitido em <?= date('d/m/Y H:i') ?>
                        </div>
                    </div>

                    <?php if ($cargaSemanal > 44 * 60): ?>
                        <div class="alert alert-danger no-print">
                            <strong>Jornada acima do limite geral.</strong>
                            A carga cadastrada soma <?= folhaPontoFormatarMinutos($cargaSemanal) ?> por semana.
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

                    <form method="post" id="formRegistrosPonto" data-hoje="<?= htmlspecialchars($hoje) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                        <input type="hidden" name="acao" value="salvar_registros">
                        <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">

                        <section class="ponto-painel">
                            <div class="ponto-painel-titulo no-print">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($funcionarioSelecionado['nome']) ?></h5>
                                    <p class="text-muted small mb-0"><?= htmlspecialchars($nomeMes) ?> · totais atualizados durante o preenchimento</p>
                                </div>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg"></i> Salvar folha
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 ponto-tabela">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Dia</th>
                                            <th>Jornada prevista</th>
                                            <th>Entrada</th>
                                            <th>Almoço</th>
                                            <th>Retorno</th>
                                            <th>Saída</th>
                                            <th>Trabalhado</th>
                                            <th>Saldo</th>
                                            <th>Status</th>
                                            <th>Observação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diasFolha as $dia): ?>
                                            <?php
                                            $jornadaPrevistaTexto = !empty($dia['horario']['trabalha'])
                                                ? trim(implode(' / ', array_filter([
                                                    ($dia['horario']['entrada_1'] ?? '') && ($dia['horario']['saida_1'] ?? '')
                                                        ? substr((string)$dia['horario']['entrada_1'], 0, 5) . '-' . substr((string)$dia['horario']['saida_1'], 0, 5)
                                                        : '',
                                                    ($dia['horario']['entrada_2'] ?? '') && ($dia['horario']['saida_2'] ?? '')
                                                        ? substr((string)$dia['horario']['entrada_2'], 0, 5) . '-' . substr((string)$dia['horario']['saida_2'], 0, 5)
                                                        : '',
                                                ])))
                                                : 'Folga';
                                            $previstoTexto = !empty($dia['feriado'])
                                                ? 'Feriado' . (!empty($dia['feriado_nome']) ? ': ' . $dia['feriado_nome'] : '')
                                                : (!empty($dia['atestado']) ? 'Atestado' : $jornadaPrevistaTexto);
                                            $classesDia = ['ponto-registro-linha'];

                                            if ($dia['previsto'] <= 0) {
                                                $classesDia[] = 'ponto-dia-folga';
                                            }
                                            if (!empty($dia['fim_semana'])) {
                                                $classesDia[] = 'ponto-dia-fim-semana';
                                            }
                                            if (!empty($dia['atestado'])) {
                                                $classesDia[] = 'ponto-dia-atestado';
                                            }
                                            if (!empty($dia['feriado'])) {
                                                $classesDia[] = 'ponto-dia-feriado';
                                            }
                                            ?>
                                            <tr
                                                class="<?= implode(' ', $classesDia) ?>"
                                                data-data="<?= htmlspecialchars($dia['data']) ?>"
                                                data-feriado-nacional="<?= !empty($dia['feriado_nacional']) ? '1' : '0' ?>"
                                                data-feriado-nome="<?= htmlspecialchars($dia['feriado_nome'] ?? '') ?>"
                                                data-jornada-prevista="<?= htmlspecialchars($jornadaPrevistaTexto) ?>">
                                                <td class="text-nowrap"><strong><?= $dia['data_br'] ?></strong></td>
                                                <td class="text-nowrap"><?= htmlspecialchars($dia['dia_semana']) ?></td>
                                                <td class="text-nowrap ponto-previsto"><?= htmlspecialchars($previstoTexto) ?></td>
                                                <?php foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo): ?>
                                                    <td>
                                                        <input
                                                            type="time"
                                                            class="form-control form-control-sm ponto-hora-registro"
                                                            name="registros[<?= $dia['data'] ?>][<?= $campo ?>]"
                                                            value="<?= htmlspecialchars($dia['registro'][$campo] ?? '') ?>"
                                                            <?= !empty($dia['atestado']) ? 'disabled' : '' ?>
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
                                                        <label class="form-check form-switch ponto-atestado-controle no-print">
                                                            <input
                                                                type="checkbox"
                                                                class="form-check-input ponto-atestado-check"
                                                                name="registros[<?= $dia['data'] ?>][atestado]"
                                                                value="1"
                                                                <?= !empty($dia['atestado']) ? 'checked' : '' ?>>
                                                            <span>Atestado</span>
                                                        </label>
                                                        <input
                                                            type="text"
                                                            class="form-control form-control-sm ponto-observacao"
                                                            name="registros[<?= $dia['data'] ?>][observacao]"
                                                            value="<?= htmlspecialchars($dia['observacao_exibida'] ?? '') ?>"
                                                            maxlength="245"
                                                            placeholder="Observação opcional">
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="ponto-rodape no-print">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg"></i> Salvar folha
                                </button>
                            </div>
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
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="tituloModalFuncionario">Novo funcionário</h5>
                                <p class="text-muted mb-0">Cadastre somente o nome e defina os horários de cada dia</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <label for="funcionarioNome" class="form-label">Nome do funcionário</label>
                                    <input type="text" class="form-control" name="nome" id="funcionarioNome" maxlength="150" required>
                                    <div class="invalid-feedback">Informe o nome do funcionário.</div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2 d-none" id="grupoFuncionarioAtivo">
                                        <input class="form-check-input" type="checkbox" role="switch" name="ativo" value="1" id="funcionarioAtivo" checked>
                                        <label class="form-check-label" for="funcionarioAtivo">Funcionário ativo</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                                <div>
                                    <h6 class="mb-1">Jornada semanal</h6>
                                    <p class="text-muted small mb-0">A segunda marcação é opcional quando não houver intervalo.</p>
                                </div>
                                <div class="ponto-carga-semanal" id="cargaSemanalModal">44h00 por semana</div>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle ponto-jornada-tabela mb-0">
                                    <thead>
                                        <tr>
                                            <th>Dia</th>
                                            <th>Trabalha</th>
                                            <th>Entrada</th>
                                            <th>Almoço</th>
                                            <th>Retorno</th>
                                            <th>Saída</th>
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
                                                    <td>
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
                                A jornada cadastrada ultrapassa 44 horas semanais. O sistema permitirá salvar, mas manterá o alerta.
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
                            <input type="hidden" name="id" id="funcionarioExcluirId" value="<?= $funcionarioSelecionado ? $funcionarioId : '' ?>">
                            <input type="hidden" name="funcionario_id" value="<?= $funcionarioSelecionado ? $funcionarioId : '' ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
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

        <?php if ($funcionarioSelecionado): ?>
            <div class="modal fade" id="modalImportarPdf" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable ponto-modal-importacao">
                    <div class="modal-content">
                        <form method="post" id="formImportarPdf">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(folhaPontoToken()) ?>">
                            <input type="hidden" name="acao" value="importar_pdf">
                            <input type="hidden" name="funcionario_id" value="<?= $funcionarioId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>" data-mes-original="<?= htmlspecialchars($mes) ?>">
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
                                    Em folhas preenchidas à caneta, o sistema localiza os horários e sugere a jornada cadastrada do funcionário. Compare os campos com os recortes antes de confirmar.
                                </div>
                                <label for="arquivoPontoPdf" class="form-label">Arquivo PDF</label>
                                <input type="file" class="form-control" id="arquivoPontoPdf" accept="application/pdf,.pdf">
                                <div class="invalid-feedback">Selecione um arquivo PDF.</div>
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
                                        Confira os horários com os recortes. Campos em vermelho não tiveram leitura confiável e precisam ser corrigidos antes da importação. Você também pode digitar 8, 12 ou 1320.
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0 ponto-preview-tabela">
                                            <thead>
                                                <tr>
                                                    <th>Data</th>
                                                    <th>Dia da semana</th>
                                                    <th>Situação</th>
                                                    <th>Entrada</th>
                                                    <th>Almoço</th>
                                                    <th>Retorno</th>
                                                    <th>Saída</th>
                                                </tr>
                                            </thead>
                                            <tbody id="corpoImportacaoPdf"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="button" class="btn btn-outline-primary" id="btnLerPdf">
                                    <i class="bi bi-eye"></i> Ler e pré-visualizar
                                </button>
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
    <script src="<?= assetUrl('assets/folha_ponto.js') ?>"></script>
</body>

</html>