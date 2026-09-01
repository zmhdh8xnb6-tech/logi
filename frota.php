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
$tabelasFrota = ['frota_veiculos', 'frota_obrigacoes', 'frota_multas'];
$estruturaDisponivel = true;

foreach ($tabelasFrota as $tabelaFrota) {
    if (!logiTabelaExiste($pdo, $tabelaFrota)) {
        $estruturaDisponivel = false;
        break;
    }
}

$sqlFrota = (string)@file_get_contents(__DIR__ . '/sql/frota.sql');
$tiposObrigacao = [
    'ipva' => 'IPVA',
    'licenciamento' => 'Licenciamento / CRLV',
    'seguro' => 'Seguro',
    'revisao' => 'Revisão',
    'troca_oleo' => 'Troca de óleo',
    'pneus' => 'Pneus',
    'outro' => 'Outro',
];
$situacoesVeiculo = ['ativo', 'manutencao', 'inativo', 'vendido'];
$situacoesObrigacao = ['pendente', 'pago', 'dispensado'];
$situacoesMulta = ['pendente', 'paga', 'recorrida', 'cancelada'];

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

$buscarRegistro = static function (PDO $pdo, string $tabela, int $empresaId, int $id): ?array {
    if ($id <= 0 || !in_array($tabela, ['frota_obrigacoes', 'frota_multas'], true)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$tabela} WHERE id = ? AND empresa_id = ?");
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
            $pdo->prepare('DELETE FROM frota_veiculos WHERE id = ? AND empresa_id = ?')
                ->execute([$id, $empresaId]);
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

    if ($acao === 'salvar_obrigacao') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $id > 0 ? $buscarRegistro($pdo, 'frota_obrigacoes', $empresaId, $id) : null;
        $veiculoId = (int)($_POST['veiculo_id'] ?? 0);
        $veiculo = $buscarVeiculo($pdo, $empresaId, $veiculoId);
        $tipo = (string)($_POST['tipo'] ?? 'outro');
        $titulo = frotaTexto((string)($_POST['titulo'] ?? ''), 150);
        $competencia = frotaTexto((string)($_POST['competencia'] ?? ''), 20);
        $vencimento = trim((string)($_POST['vencimento'] ?? ''));
        $valor = frotaValorEntrada((string)($_POST['valor'] ?? ''));
        $situacao = (string)($_POST['situacao'] ?? 'pendente');
        $pagoEm = trim((string)($_POST['pago_em'] ?? ''));
        $referencia = frotaTexto((string)($_POST['referencia'] ?? ''), 120);
        $observacoes = trim((string)($_POST['observacoes'] ?? ''));

        if ($id > 0 && !$antes) {
            frotaRedirecionar('Obrigação não encontrada nesta empresa.', 'danger', 'obrigacoes');
        }

        if (!$veiculo || !array_key_exists($tipo, $tiposObrigacao) || !frotaDataValida($vencimento) || $valor < 0) {
            frotaRedirecionar('Revise o veículo, o tipo, o vencimento e o valor da obrigação.', 'danger', 'obrigacoes');
        }

        if ($titulo === '') {
            $titulo = $tiposObrigacao[$tipo];
        }

        if (!in_array($situacao, $situacoesObrigacao, true)) {
            $situacao = 'pendente';
        }

        $pagoEm = $situacao === 'pago' ? ($pagoEm !== '' ? $pagoEm : date('Y-m-d')) : '';
        if (!frotaDataValida($pagoEm, false)) {
            frotaRedirecionar('Revise a data de pagamento da obrigação.', 'danger', 'obrigacoes');
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE frota_obrigacoes
                    SET veiculo_id = ?, tipo = ?, titulo = ?, competencia = ?, vencimento = ?,
                        valor = ?, situacao = ?, pago_em = ?, referencia = ?, observacoes = ?, usuario_id = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $parametros = [
                    $veiculoId,
                    $tipo,
                    $titulo,
                    $competencia ?: null,
                    $vencimento,
                    $valor,
                    $situacao,
                    $pagoEm ?: null,
                    $referencia ?: null,
                    $observacoes ?: null,
                    $usuarioId,
                    $id,
                    $empresaId,
                ];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO frota_obrigacoes (
                        empresa_id, veiculo_id, tipo, titulo, competencia, vencimento,
                        valor, situacao, pago_em, referencia, observacoes, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $parametros = [
                    $empresaId,
                    $veiculoId,
                    $tipo,
                    $titulo,
                    $competencia ?: null,
                    $vencimento,
                    $valor,
                    $situacao,
                    $pagoEm ?: null,
                    $referencia ?: null,
                    $observacoes ?: null,
                    $usuarioId,
                ];
            }

            $stmt->execute($parametros);
            if ($id <= 0) {
                $id = (int)$pdo->lastInsertId();
            }
            $depois = $buscarRegistro($pdo, 'frota_obrigacoes', $empresaId, $id);
            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                $antes ? 'editar' : 'criar',
                'obrigacao_frota',
                $id,
                ($antes ? 'Alterou' : 'Cadastrou') . ' ' . $titulo . ' de ' . frotaPlacaFormatada((string)$veiculo['placa']),
                $antes,
                $depois
            );
            frotaRedirecionar($antes ? 'Obrigação atualizada com sucesso.' : 'Obrigação cadastrada com sucesso.', 'success', 'obrigacoes');
        } catch (Throwable $e) {
            frotaRedirecionar('Não foi possível salvar a obrigação.', 'danger', 'obrigacoes');
        }
    }

    if ($acao === 'quitar_obrigacao') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $buscarRegistro($pdo, 'frota_obrigacoes', $empresaId, $id);

        if (!$antes) {
            frotaRedirecionar('Obrigação não encontrada.', 'danger', 'obrigacoes');
        }

        $pdo->prepare("
            UPDATE frota_obrigacoes
            SET situacao = 'pago', pago_em = ?, usuario_id = ?
            WHERE id = ? AND empresa_id = ?
        ")->execute([date('Y-m-d'), $usuarioId, $id, $empresaId]);
        registrarAuditoria($pdo, 'Gestão da Frota', 'quitar', 'obrigacao_frota', $id, 'Marcou uma obrigação como paga', $antes, [
            'situacao' => 'pago',
            'pago_em' => date('Y-m-d'),
        ]);
        frotaRedirecionar('Obrigação marcada como paga.', 'success', 'obrigacoes');
    }

    if ($acao === 'excluir_obrigacao') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $buscarRegistro($pdo, 'frota_obrigacoes', $empresaId, $id);
        if (!$antes) {
            frotaRedirecionar('Obrigação não encontrada.', 'danger', 'obrigacoes');
        }
        $pdo->prepare('DELETE FROM frota_obrigacoes WHERE id = ? AND empresa_id = ?')->execute([$id, $empresaId]);
        registrarAuditoria($pdo, 'Gestão da Frota', 'excluir', 'obrigacao_frota', $id, 'Excluiu a obrigação ' . $antes['titulo'], $antes, null);
        frotaRedirecionar('Obrigação excluída.', 'success', 'obrigacoes');
    }

    if ($acao === 'salvar_multa') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $id > 0 ? $buscarRegistro($pdo, 'frota_multas', $empresaId, $id) : null;
        $veiculoId = (int)($_POST['veiculo_id'] ?? 0);
        $veiculo = $buscarVeiculo($pdo, $empresaId, $veiculoId);
        $autoInfracao = frotaTexto((string)($_POST['auto_infracao'] ?? ''), 80);
        $dataInfracao = trim((string)($_POST['data_infracao'] ?? ''));
        $descricao = frotaTexto((string)($_POST['descricao'] ?? ''), 255);
        $motorista = frotaTexto((string)($_POST['motorista'] ?? ''), 150);
        $valor = frotaValorEntrada((string)($_POST['valor'] ?? ''));
        $vencimento = trim((string)($_POST['vencimento'] ?? ''));
        $pontos = max(0, min(99, (int)($_POST['pontos'] ?? 0)));
        $situacao = (string)($_POST['situacao'] ?? 'pendente');
        $pagoEm = trim((string)($_POST['pago_em'] ?? ''));
        $observacoes = trim((string)($_POST['observacoes'] ?? ''));

        if ($id > 0 && !$antes) {
            frotaRedirecionar('Multa não encontrada nesta empresa.', 'danger', 'multas');
        }

        if (!$veiculo || !frotaDataValida($dataInfracao) || !frotaDataValida($vencimento, false) || $descricao === '' || $valor < 0) {
            frotaRedirecionar('Revise o veículo, a data, a descrição, o vencimento e o valor da multa.', 'danger', 'multas');
        }

        if (!in_array($situacao, $situacoesMulta, true)) {
            $situacao = 'pendente';
        }

        $pagoEm = $situacao === 'paga' ? ($pagoEm !== '' ? $pagoEm : date('Y-m-d')) : '';
        if (!frotaDataValida($pagoEm, false)) {
            frotaRedirecionar('Revise a data de pagamento da multa.', 'danger', 'multas');
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE frota_multas
                    SET veiculo_id = ?, auto_infracao = ?, data_infracao = ?, descricao = ?,
                        motorista = ?, valor = ?, vencimento = ?, pontos = ?, situacao = ?,
                        pago_em = ?, observacoes = ?, usuario_id = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $parametros = [
                    $veiculoId,
                    $autoInfracao ?: null,
                    $dataInfracao,
                    $descricao,
                    $motorista ?: null,
                    $valor,
                    $vencimento ?: null,
                    $pontos,
                    $situacao,
                    $pagoEm ?: null,
                    $observacoes ?: null,
                    $usuarioId,
                    $id,
                    $empresaId,
                ];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO frota_multas (
                        empresa_id, veiculo_id, auto_infracao, data_infracao, descricao,
                        motorista, valor, vencimento, pontos, situacao, pago_em,
                        observacoes, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $parametros = [
                    $empresaId,
                    $veiculoId,
                    $autoInfracao ?: null,
                    $dataInfracao,
                    $descricao,
                    $motorista ?: null,
                    $valor,
                    $vencimento ?: null,
                    $pontos,
                    $situacao,
                    $pagoEm ?: null,
                    $observacoes ?: null,
                    $usuarioId,
                ];
            }

            $stmt->execute($parametros);
            if ($id <= 0) {
                $id = (int)$pdo->lastInsertId();
            }
            $depois = $buscarRegistro($pdo, 'frota_multas', $empresaId, $id);
            registrarAuditoria(
                $pdo,
                'Gestão da Frota',
                $antes ? 'editar' : 'criar',
                'multa_frota',
                $id,
                ($antes ? 'Alterou' : 'Cadastrou') . ' multa de ' . frotaPlacaFormatada((string)$veiculo['placa']),
                $antes,
                $depois
            );
            frotaRedirecionar($antes ? 'Multa atualizada com sucesso.' : 'Multa cadastrada com sucesso.', 'success', 'multas');
        } catch (Throwable $e) {
            frotaRedirecionar('Não foi possível salvar a multa.', 'danger', 'multas');
        }
    }

    if ($acao === 'quitar_multa') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $buscarRegistro($pdo, 'frota_multas', $empresaId, $id);
        if (!$antes) {
            frotaRedirecionar('Multa não encontrada.', 'danger', 'multas');
        }
        $pdo->prepare("
            UPDATE frota_multas
            SET situacao = 'paga', pago_em = ?, usuario_id = ?
            WHERE id = ? AND empresa_id = ?
        ")->execute([date('Y-m-d'), $usuarioId, $id, $empresaId]);
        registrarAuditoria($pdo, 'Gestão da Frota', 'quitar', 'multa_frota', $id, 'Marcou uma multa como paga', $antes, [
            'situacao' => 'paga',
            'pago_em' => date('Y-m-d'),
        ]);
        frotaRedirecionar('Multa marcada como paga.', 'success', 'multas');
    }

    if ($acao === 'excluir_multa') {
        $id = (int)($_POST['id'] ?? 0);
        $antes = $buscarRegistro($pdo, 'frota_multas', $empresaId, $id);
        if (!$antes) {
            frotaRedirecionar('Multa não encontrada.', 'danger', 'multas');
        }
        $pdo->prepare('DELETE FROM frota_multas WHERE id = ? AND empresa_id = ?')->execute([$id, $empresaId]);
        registrarAuditoria($pdo, 'Gestão da Frota', 'excluir', 'multa_frota', $id, 'Excluiu uma multa da frota', $antes, null);
        frotaRedirecionar('Multa excluída.', 'success', 'multas');
    }
}

$veiculos = [];
$obrigacoes = [];
$multas = [];
$resumo = [
    'veiculos_ativos' => 0,
    'vencidos' => 0,
    'proximos' => 0,
    'multas_pendentes' => 0,
];
$busca = trim((string)($_GET['busca'] ?? ''));
$situacaoVeiculoFiltro = (string)($_GET['situacao_veiculo'] ?? 'todos');
$veiculoFiltro = max(0, (int)($_GET['veiculo'] ?? 0));
$prazoFiltro = (string)($_GET['prazo'] ?? 'todos');

if ($estruturaDisponivel) {
    $stmtResumo = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM frota_veiculos WHERE empresa_id = ? AND situacao = 'ativo') AS veiculos_ativos,
            (
                (SELECT COUNT(*) FROM frota_obrigacoes WHERE empresa_id = ? AND situacao = 'pendente' AND vencimento < CURDATE())
                +
                (SELECT COUNT(*) FROM frota_multas WHERE empresa_id = ? AND situacao = 'pendente' AND vencimento IS NOT NULL AND vencimento < CURDATE())
            ) AS vencidos,
            (
                (SELECT COUNT(*) FROM frota_obrigacoes WHERE empresa_id = ? AND situacao = 'pendente' AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                +
                (SELECT COUNT(*) FROM frota_multas WHERE empresa_id = ? AND situacao = 'pendente' AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
            ) AS proximos,
            (SELECT COUNT(*) FROM frota_multas WHERE empresa_id = ? AND situacao = 'pendente') AS multas_pendentes
    ");
    $stmtResumo->execute(array_fill(0, 6, $empresaId));
    $dadosResumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];
    $resumo = [
        'veiculos_ativos' => (int)($dadosResumo['veiculos_ativos'] ?? 0),
        'vencidos' => (int)($dadosResumo['vencidos'] ?? 0),
        'proximos' => (int)($dadosResumo['proximos'] ?? 0),
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
            (SELECT COUNT(*) FROM frota_obrigacoes o WHERE o.empresa_id = v.empresa_id AND o.veiculo_id = v.id AND o.situacao = 'pendente' AND o.vencimento < CURDATE())
            +
            (SELECT COUNT(*) FROM frota_multas m WHERE m.empresa_id = v.empresa_id AND m.veiculo_id = v.id AND m.situacao = 'pendente' AND m.vencimento IS NOT NULL AND m.vencimento < CURDATE()) AS vencidos,
            (SELECT COUNT(*) FROM frota_obrigacoes o WHERE o.empresa_id = v.empresa_id AND o.veiculo_id = v.id AND o.situacao = 'pendente' AND o.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
            +
            (SELECT COUNT(*) FROM frota_multas m WHERE m.empresa_id = v.empresa_id AND m.veiculo_id = v.id AND m.situacao = 'pendente' AND m.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS proximos
        FROM frota_veiculos v
        WHERE {$filtroVeiculos}
        ORDER BY FIELD(v.situacao, 'ativo', 'manutencao', 'inativo', 'vendido'), v.modelo, v.placa
    ");
    $stmtVeiculos->execute($parametrosVeiculos);
    $veiculos = $stmtVeiculos->fetchAll(PDO::FETCH_ASSOC);

    $filtroRegistros = 'r.empresa_id = ?';
    $parametrosRegistros = [$empresaId];
    if ($veiculoFiltro > 0) {
        $filtroRegistros .= ' AND r.veiculo_id = ?';
        $parametrosRegistros[] = $veiculoFiltro;
    }
    if ($prazoFiltro === 'vencido') {
        $filtroRegistros .= " AND r.situacao = 'pendente' AND r.vencimento < CURDATE()";
    } elseif ($prazoFiltro === 'proximo') {
        $filtroRegistros .= " AND r.situacao = 'pendente' AND r.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($prazoFiltro === 'pendente') {
        $filtroRegistros .= " AND r.situacao = 'pendente'";
    } elseif ($prazoFiltro === 'pago') {
        $filtroRegistros .= " AND r.situacao = 'pago'";
    } elseif ($prazoFiltro === 'dispensado') {
        $filtroRegistros .= " AND r.situacao = 'dispensado'";
    }

    $stmtObrigacoes = $pdo->prepare("
        SELECT r.*, v.placa, v.marca, v.modelo
        FROM frota_obrigacoes r
        INNER JOIN frota_veiculos v ON v.id = r.veiculo_id AND v.empresa_id = r.empresa_id
        WHERE {$filtroRegistros}
        ORDER BY FIELD(r.situacao, 'pendente', 'pago', 'dispensado'), r.vencimento ASC, r.id DESC
    ");
    $stmtObrigacoes->execute($parametrosRegistros);
    $obrigacoes = $stmtObrigacoes->fetchAll(PDO::FETCH_ASSOC);

    $filtroMultas = 'm.empresa_id = ?';
    $parametrosMultas = [$empresaId];
    if ($veiculoFiltro > 0) {
        $filtroMultas .= ' AND m.veiculo_id = ?';
        $parametrosMultas[] = $veiculoFiltro;
    }
    if ($prazoFiltro === 'vencido') {
        $filtroMultas .= " AND m.situacao = 'pendente' AND m.vencimento IS NOT NULL AND m.vencimento < CURDATE()";
    } elseif ($prazoFiltro === 'proximo') {
        $filtroMultas .= " AND m.situacao = 'pendente' AND m.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($prazoFiltro === 'pendente') {
        $filtroMultas .= " AND m.situacao = 'pendente'";
    } elseif ($prazoFiltro === 'pago') {
        $filtroMultas .= " AND m.situacao = 'paga'";
    }

    $stmtMultas = $pdo->prepare("
        SELECT m.*, v.placa, v.marca, v.modelo
        FROM frota_multas m
        INNER JOIN frota_veiculos v ON v.id = m.veiculo_id AND v.empresa_id = m.empresa_id
        WHERE {$filtroMultas}
        ORDER BY FIELD(m.situacao, 'pendente', 'recorrida', 'paga', 'cancelada'), COALESCE(m.vencimento, m.data_infracao) ASC, m.id DESC
    ");
    $stmtMultas->execute($parametrosMultas);
    $multas = $stmtMultas->fetchAll(PDO::FETCH_ASSOC);
}

$todosVeiculos = [];
if ($estruturaDisponivel) {
    $stmtTodosVeiculos = $pdo->prepare("
        SELECT id, placa, marca, modelo, situacao
        FROM frota_veiculos
        WHERE empresa_id = ?
        ORDER BY modelo, placa
    ");
    $stmtTodosVeiculos->execute([$empresaId]);
    $todosVeiculos = $stmtTodosVeiculos->fetchAll(PDO::FETCH_ASSOC);
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
                    <p class="text-muted mb-0">Veículos, documentos, multas e vencimentos da <?= htmlspecialchars(empresaAtivaNome($pdo)) ?>.</p>
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
                        <p>Ative as tabelas de veículos, obrigações e multas para começar.</p>
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
                        <span>Vencidos</span>
                        <strong><?= (int)$resumo['vencidos'] ?></strong>
                    </div>
                    <div class="frota-metrica metrica-proximos">
                        <span>Próximos 30 dias</span>
                        <strong><?= (int)$resumo['proximos'] ?></strong>
                    </div>
                    <div class="frota-metrica metrica-multas">
                        <span>Multas pendentes</span>
                        <strong><?= (int)$resumo['multas_pendentes'] ?></strong>
                    </div>
                </section>

                <nav class="frota-abas" aria-label="Seções da frota">
                    <a href="frota.php?aba=visao-geral" class="<?= $aba === 'visao-geral' ? 'ativo' : '' ?>">
                        <i class="bi bi-car-front"></i> Veículos
                    </a>
                    <a href="frota.php?aba=obrigacoes" class="<?= $aba === 'obrigacoes' ? 'ativo' : '' ?>">
                        <i class="bi bi-file-earmark-check"></i> Obrigações
                    </a>
                    <a href="frota.php?aba=multas" class="<?= $aba === 'multas' ? 'ativo' : '' ?>">
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
                                                <?php if ((int)$veiculo['vencidos'] > 0): ?>
                                                    <span class="badge text-bg-danger"><?= (int)$veiculo['vencidos'] ?> vencido<?= (int)$veiculo['vencidos'] === 1 ? '' : 's' ?></span>
                                                <?php elseif ((int)$veiculo['proximos'] > 0): ?>
                                                    <span class="badge text-bg-warning"><?= (int)$veiculo['proximos'] ?> próximo<?= (int)$veiculo['proximos'] === 1 ? '' : 's' ?></span>
                                                <?php else: ?>
                                                    <span class="text-success"><i class="bi bi-check-circle"></i> Em dia</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end frota-acoes">
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
                                <h5>Obrigações e documentos</h5>
                                <p>IPVA, licenciamento, seguro e manutenções programadas.</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalObrigacao" <?= $todosVeiculos === [] ? 'disabled' : '' ?>><i class="bi bi-plus-lg"></i> Nova obrigação</button>
                        </div>
                        <form method="get" class="frota-filtros frota-filtros-registros">
                            <input type="hidden" name="aba" value="obrigacoes">
                            <select name="veiculo" class="form-select">
                                <option value="0">Todos os veículos</option>
                                <?php foreach ($todosVeiculos as $veiculoOpcao): ?>
                                    <option value="<?= (int)$veiculoOpcao['id'] ?>" <?= $veiculoFiltro === (int)$veiculoOpcao['id'] ? 'selected' : '' ?>><?= htmlspecialchars(frotaPlacaFormatada((string)$veiculoOpcao['placa']) . ' · ' . $veiculoOpcao['modelo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="prazo" class="form-select">
                                <option value="todos">Todos os prazos</option>
                                <option value="vencido" <?= $prazoFiltro === 'vencido' ? 'selected' : '' ?>>Vencidos</option>
                                <option value="proximo" <?= $prazoFiltro === 'proximo' ? 'selected' : '' ?>>Próximos 30 dias</option>
                                <option value="pendente" <?= $prazoFiltro === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                                <option value="pago" <?= $prazoFiltro === 'pago' ? 'selected' : '' ?>>Pagos</option>
                                <option value="dispensado" <?= $prazoFiltro === 'dispensado' ? 'selected' : '' ?>>Dispensados</option>
                            </select>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                        </form>
                        <div class="table-responsive">
                            <table class="table align-middle frota-tabela">
                                <thead>
                                    <tr>
                                        <th>Veículo</th>
                                        <th>Obrigação</th>
                                        <th>Competência</th>
                                        <th>Vencimento</th>
                                        <th>Valor</th>
                                        <th>Situação</th>
                                        <th>Referência</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($obrigacoes === []): ?><tr>
                                            <td colspan="8" class="frota-vazio">Nenhuma obrigação encontrada.</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($obrigacoes as $obrigacao): ?>
                                        <?php
                                        $situacaoPrazo = frotaSituacaoPrazo($obrigacao);
                                        $dadosObrigacao = htmlspecialchars(json_encode($obrigacao, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr>
                                            <td><span class="frota-placa frota-placa-menor"><?= htmlspecialchars(frotaPlacaFormatada((string)$obrigacao['placa'])) ?></span><small><?= htmlspecialchars($obrigacao['modelo']) ?></small></td>
                                            <td><strong><?= htmlspecialchars($obrigacao['titulo']) ?></strong><small><?= htmlspecialchars(frotaTipoObrigacao((string)$obrigacao['tipo'])) ?></small></td>
                                            <td><?= htmlspecialchars($obrigacao['competencia'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars(frotaData((string)$obrigacao['vencimento'])) ?></td>
                                            <td><?= htmlspecialchars(frotaMoeda((float)$obrigacao['valor'])) ?></td>
                                            <td><span class="badge text-bg-<?= $statusClasse($situacaoPrazo) ?>"><?= htmlspecialchars($statusRotulo($situacaoPrazo)) ?></span></td>
                                            <td><?= htmlspecialchars($obrigacao['referencia'] ?: '-') ?></td>
                                            <td class="text-end frota-acoes">
                                                <?php if ($obrigacao['situacao'] === 'pendente'): ?>
                                                    <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" value="quitar_obrigacao"><input type="hidden" name="aba" value="obrigacoes"><input type="hidden" name="id" value="<?= (int)$obrigacao['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-success" title="Marcar como paga"><i class="bi bi-check-lg"></i></button></form>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-obrigacao" data-bs-toggle="modal" data-bs-target="#modalObrigacao" data-registro="<?= $dadosObrigacao ?>" title="Editar obrigação"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-registro" data-bs-toggle="modal" data-bs-target="#modalExcluirFrota" data-acao="excluir_obrigacao" data-aba="obrigacoes" data-id="<?= (int)$obrigacao['id'] ?>" data-nome="<?= htmlspecialchars($obrigacao['titulo'], ENT_QUOTES) ?>" title="Excluir obrigação"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($aba === 'multas'): ?>
                    <section class="frota-painel">
                        <div class="frota-painel-titulo">
                            <div>
                                <h5>Multas</h5>
                                <p>Infrações, responsáveis, pontos, vencimentos e pagamentos.</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalMulta" <?= $todosVeiculos === [] ? 'disabled' : '' ?>><i class="bi bi-plus-lg"></i> Nova multa</button>
                        </div>
                        <form method="get" class="frota-filtros frota-filtros-registros">
                            <input type="hidden" name="aba" value="multas">
                            <select name="veiculo" class="form-select">
                                <option value="0">Todos os veículos</option>
                                <?php foreach ($todosVeiculos as $veiculoOpcao): ?>
                                    <option value="<?= (int)$veiculoOpcao['id'] ?>" <?= $veiculoFiltro === (int)$veiculoOpcao['id'] ? 'selected' : '' ?>><?= htmlspecialchars(frotaPlacaFormatada((string)$veiculoOpcao['placa']) . ' · ' . $veiculoOpcao['modelo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="prazo" class="form-select">
                                <option value="todos">Todas as situações</option>
                                <option value="vencido" <?= $prazoFiltro === 'vencido' ? 'selected' : '' ?>>Vencidas</option>
                                <option value="proximo" <?= $prazoFiltro === 'proximo' ? 'selected' : '' ?>>Próximos 30 dias</option>
                                <option value="pendente" <?= $prazoFiltro === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                                <option value="pago" <?= $prazoFiltro === 'pago' ? 'selected' : '' ?>>Pagas</option>
                            </select>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                        </form>
                        <div class="table-responsive">
                            <table class="table align-middle frota-tabela">
                                <thead>
                                    <tr>
                                        <th>Veículo</th>
                                        <th>Infração</th>
                                        <th>Data</th>
                                        <th>Motorista</th>
                                        <th>Vencimento</th>
                                        <th>Valor</th>
                                        <th>Pontos</th>
                                        <th>Situação</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($multas === []): ?><tr>
                                            <td colspan="9" class="frota-vazio">Nenhuma multa encontrada.</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($multas as $multa): ?>
                                        <?php
                                        $situacaoPrazo = frotaSituacaoPrazo($multa);
                                        $dadosMulta = htmlspecialchars(json_encode($multa, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr>
                                            <td><span class="frota-placa frota-placa-menor"><?= htmlspecialchars(frotaPlacaFormatada((string)$multa['placa'])) ?></span><small><?= htmlspecialchars($multa['modelo']) ?></small></td>
                                            <td><strong><?= htmlspecialchars($multa['descricao']) ?></strong><small><?= htmlspecialchars($multa['auto_infracao'] ? 'Auto ' . $multa['auto_infracao'] : '') ?></small></td>
                                            <td><?= htmlspecialchars(frotaData((string)$multa['data_infracao'])) ?></td>
                                            <td><?= htmlspecialchars($multa['motorista'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars(frotaData($multa['vencimento'])) ?></td>
                                            <td><?= htmlspecialchars(frotaMoeda((float)$multa['valor'])) ?></td>
                                            <td><?= (int)$multa['pontos'] ?></td>
                                            <td><span class="badge text-bg-<?= $statusClasse($situacaoPrazo) ?>"><?= htmlspecialchars($statusRotulo($situacaoPrazo)) ?></span></td>
                                            <td class="text-end frota-acoes">
                                                <?php if ($multa['situacao'] === 'pendente'): ?>
                                                    <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" value="quitar_multa"><input type="hidden" name="aba" value="multas"><input type="hidden" name="id" value="<?= (int)$multa['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-success" title="Marcar como paga"><i class="bi bi-check-lg"></i></button></form>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-multa" data-bs-toggle="modal" data-bs-target="#modalMulta" data-registro="<?= $dadosMulta ?>" title="Editar multa"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-registro" data-bs-toggle="modal" data-bs-target="#modalExcluirFrota" data-acao="excluir_multa" data-aba="multas" data-id="<?= (int)$multa['id'] ?>" data-nome="multa de <?= htmlspecialchars(frotaPlacaFormatada((string)$multa['placa']), ENT_QUOTES) ?>" title="Excluir multa"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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

        <div class="modal fade" id="modalObrigacao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" id="formObrigacao" class="frota-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" value="salvar_obrigacao"><input type="hidden" name="aba" value="obrigacoes"><input type="hidden" name="id" id="obrigacaoId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalObrigacaoTitulo">Nova obrigação</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-7"><label class="form-label" for="obrigacaoVeiculo">Veículo</label><select class="form-select" name="veiculo_id" id="obrigacaoVeiculo" required>
                                        <option value="">Selecione</option><?php foreach ($todosVeiculos as $veiculoOpcao): ?><option value="<?= (int)$veiculoOpcao['id'] ?>"><?= htmlspecialchars(frotaPlacaFormatada((string)$veiculoOpcao['placa']) . ' · ' . $veiculoOpcao['marca'] . ' ' . $veiculoOpcao['modelo']) ?></option><?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Selecione o veículo.</div>
                                </div>
                                <div class="col-md-5"><label class="form-label" for="obrigacaoTipo">Tipo</label><select class="form-select" name="tipo" id="obrigacaoTipo"><?php foreach ($tiposObrigacao as $chaveTipo => $rotuloTipo): ?><option value="<?= htmlspecialchars($chaveTipo) ?>"><?= htmlspecialchars($rotuloTipo) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-8"><label class="form-label" for="obrigacaoTitulo">Título</label><input type="text" class="form-control" name="titulo" id="obrigacaoTitulo" maxlength="150" placeholder="Preenchido automaticamente pelo tipo"></div>
                                <div class="col-md-4"><label class="form-label" for="obrigacaoCompetencia">Competência</label><input type="text" class="form-control" name="competencia" id="obrigacaoCompetencia" maxlength="20" placeholder="Ex.: 2026"></div>
                                <div class="col-md-4"><label class="form-label" for="obrigacaoVencimento">Vencimento</label><input type="date" class="form-control" name="vencimento" id="obrigacaoVencimento" required>
                                    <div class="invalid-feedback">Informe o vencimento.</div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="obrigacaoValor">Valor</label>
                                    <div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor" id="obrigacaoValor" value="0,00"></div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="obrigacaoSituacao">Situação</label><select class="form-select campo-situacao-pagamento" name="situacao" id="obrigacaoSituacao" data-alvo="#grupoObrigacaoPago">
                                        <option value="pendente">Pendente</option>
                                        <option value="pago">Pago</option>
                                        <option value="dispensado">Dispensado</option>
                                    </select></div>
                                <div class="col-md-4 d-none" id="grupoObrigacaoPago"><label class="form-label" for="obrigacaoPagoEm">Pago em</label><input type="date" class="form-control" name="pago_em" id="obrigacaoPagoEm">
                                    <div class="invalid-feedback">Informe a data do pagamento.</div>
                                </div>
                                <div class="col-md-8"><label class="form-label" for="obrigacaoReferencia">Número ou referência do documento</label><input type="text" class="form-control" name="referencia" id="obrigacaoReferencia" maxlength="120"></div>
                                <div class="col-12"><label class="form-label" for="obrigacaoObservacoes">Observações</label><textarea class="form-control" name="observacoes" id="obrigacaoObservacoes" rows="3"></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar obrigação</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalMulta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" id="formMulta" class="frota-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(frotaToken()) ?>"><input type="hidden" name="acao" value="salvar_multa"><input type="hidden" name="aba" value="multas"><input type="hidden" name="id" id="multaId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalMultaTitulo">Nova multa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-7"><label class="form-label" for="multaVeiculo">Veículo</label><select class="form-select" name="veiculo_id" id="multaVeiculo" required>
                                        <option value="">Selecione</option><?php foreach ($todosVeiculos as $veiculoOpcao): ?><option value="<?= (int)$veiculoOpcao['id'] ?>"><?= htmlspecialchars(frotaPlacaFormatada((string)$veiculoOpcao['placa']) . ' · ' . $veiculoOpcao['marca'] . ' ' . $veiculoOpcao['modelo']) ?></option><?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Selecione o veículo.</div>
                                </div>
                                <div class="col-md-5"><label class="form-label" for="multaAuto">Auto de infração</label><input type="text" class="form-control" name="auto_infracao" id="multaAuto" maxlength="80"></div>
                                <div class="col-md-8"><label class="form-label" for="multaDescricao">Descrição da infração</label><input type="text" class="form-control" name="descricao" id="multaDescricao" maxlength="255" required>
                                    <div class="invalid-feedback">Informe a descrição da infração.</div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="multaData">Data da infração</label><input type="date" class="form-control" name="data_infracao" id="multaData" required>
                                    <div class="invalid-feedback">Informe a data da infração.</div>
                                </div>
                                <div class="col-md-6"><label class="form-label" for="multaMotorista">Motorista responsável</label><input type="text" class="form-control" name="motorista" id="multaMotorista" maxlength="150"></div>
                                <div class="col-md-3"><label class="form-label" for="multaVencimento">Vencimento</label><input type="date" class="form-control" name="vencimento" id="multaVencimento"></div>
                                <div class="col-md-3"><label class="form-label" for="multaPontos">Pontos</label><input type="number" class="form-control" name="pontos" id="multaPontos" min="0" max="99" value="0"></div>
                                <div class="col-md-4"><label class="form-label" for="multaValor">Valor</label>
                                    <div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor" id="multaValor" value="0,00"></div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="multaSituacao">Situação</label><select class="form-select campo-situacao-pagamento" name="situacao" id="multaSituacao" data-alvo="#grupoMultaPago">
                                        <option value="pendente">Pendente</option>
                                        <option value="paga">Paga</option>
                                        <option value="recorrida">Recorrida</option>
                                        <option value="cancelada">Cancelada</option>
                                    </select></div>
                                <div class="col-md-4 d-none" id="grupoMultaPago"><label class="form-label" for="multaPagoEm">Pago em</label><input type="date" class="form-control" name="pago_em" id="multaPagoEm">
                                    <div class="invalid-feedback">Informe a data do pagamento.</div>
                                </div>
                                <div class="col-12"><label class="form-label" for="multaObservacoes">Observações</label><textarea class="form-control" name="observacoes" id="multaObservacoes" rows="3"></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar multa</button></div>
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