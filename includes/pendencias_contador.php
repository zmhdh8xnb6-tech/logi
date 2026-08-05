<?php

function contarPendenciasSistema(PDO $pdo): int
{
    $hoje = date('Y-m-d');
    $total = 0;

    $filtroEmpresaClientes = empresaFiltroClienteDireto($pdo);
    $filtroEmpresaClientesAlias = empresaFiltroClienteDireto($pdo, 'c');

    $stmt = $pdo->query("SELECT * FROM clientes WHERE 1=1" . clientesFiltroAtivos($pdo) . "{$filtroEmpresaClientes}");
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clienteEnderecoIncompleto = static function (array $cliente): bool {
        foreach (['cep', 'endereco', 'numero_endereco', 'bairro', 'cidade', 'uf'] as $campo) {
            if (trim((string)($cliente[$campo] ?? '')) === '') {
                return true;
            }
        }

        return false;
    };
    $clienteDocumentoEhCnpj = static function (array $cliente): bool {
        return strlen(preg_replace('/\D/', '', (string)($cliente['documento'] ?? ''))) === 14;
    };

    $sociosPorCliente = [];
    $qsaDisponivel = logiTabelaExiste($pdo, 'cliente_socios');
    $paralisacaoDisponivel = logiColunaExiste($pdo, 'clientes', 'paralisacao_status')
        && logiColunaExiste($pdo, 'clientes', 'paralisacao_fim');
    $alvaraGoiasDisponivel = logiTabelaExiste($pdo, 'cliente_alvaras_goias');
    $certificadoStatusDisponivel = logiColunaExiste($pdo, 'clientes', 'certificado_status');
    if ($qsaDisponivel) {
        try {
            $stmtSocios = $pdo->query("
                SELECT cs.cliente_id, COUNT(*) AS total_socios
                FROM cliente_socios cs
                INNER JOIN clientes c ON c.id = cs.cliente_id
                WHERE 1 = 1
                {$filtroEmpresaClientesAlias}
                GROUP BY cs.cliente_id
            ");

            foreach ($stmtSocios->fetchAll(PDO::FETCH_ASSOC) as $sociosCliente) {
                $sociosPorCliente[(int)$sociosCliente['cliente_id']] = (int)$sociosCliente['total_socios'];
            }
        } catch (Throwable $e) {
        }
    }

    $procuracoes = [
        ['status' => 'procuracao_receita_federal', 'vencimento' => 'vencimento_procuracao_receita_federal'],
        ['status' => 'procuracao_conectividade', 'vencimento' => 'vencimento_procuracao_conectividade'],
        ['status' => 'procuracao_fgts', 'vencimento' => 'vencimento_procuracao_fgts'],
        ['status' => 'procuracao_empregador_web', 'vencimento' => null],
        ['status' => 'procuracao_particular', 'vencimento' => null],
        ['status' => 'procuracao_sefaz', 'vencimento' => null],
    ];

    foreach ($clientes as $cliente) {
        $clienteContabil = (int)($cliente['cliente_contabil'] ?? 1) === 1;
        $controlaCertificado = $clienteContabil || (int)($cliente['servico_certificado'] ?? 1) === 1;
        $clienteParalisado = $paralisacaoDisponivel
            && ($cliente['paralisacao_status'] ?? '') === 'paralisada'
            && (empty($cliente['paralisacao_fim']) || $cliente['paralisacao_fim'] >= $hoje);
        $certificadoNaoPrecisa = $certificadoStatusDisponivel
            && ($cliente['certificado_status'] ?? '') === 'nao_precisa_momento';

        if ($clienteContabil && $clienteEnderecoIncompleto($cliente)) {
            $total++;
        }

        if ($qsaDisponivel && $clienteContabil && $clienteDocumentoEhCnpj($cliente) && (($sociosPorCliente[(int)$cliente['id']] ?? 0) <= 0)) {
            $total++;
        }

        if (!$clienteParalisado && !$certificadoNaoPrecisa && $controlaCertificado && !empty($cliente['pendencia_certificado_digital'])) {
            $total++;
        }

        if (!$clienteParalisado && !$certificadoNaoPrecisa && $controlaCertificado) {
            $vencimentoCertificado = $cliente['vencimento_certificado'] ?? '';

            if (
                $vencimentoCertificado === '' ||
                $vencimentoCertificado < $hoje
            ) {
                $total++;
            }
        }

        if (!$clienteContabil) {
            continue;
        }

        foreach ($procuracoes as $procuracao) {
            if (
                $clienteParalisado
                && in_array($procuracao['status'], [
                    'procuracao_conectividade',
                    'procuracao_empregador_web',
                    'procuracao_sefaz',
                ], true)
            ) {
                continue;
            }

            $status = $cliente[$procuracao['status']] ?? '';

            if ($status === 'nao_precisa_momento') {
                continue;
            }

            if ($status === '' || $status === 'nao_possui') {
                $total++;
                continue;
            }

            if ($procuracao['vencimento'] !== null && $status === 'possui') {
                $vencimento = $cliente[$procuracao['vencimento']] ?? '';

                if ($vencimento === '' || $vencimento < $hoje) {
                    $total++;
                }
            }
        }

        $clienteGoias = strtoupper((string)($cliente['uf'] ?? '')) === 'GO'
            || ($cliente['alvara'] ?? '') === 'goias'
            || ($cliente['cadastro_df_legal'] ?? '') === 'goias';

        if (!$clienteParalisado && !$clienteGoias && (($cliente['alvara'] ?? '') === '' || ($cliente['alvara'] ?? '') === 'nao_possui')) {
            $total++;
        }

        if (!$clienteParalisado && ($cliente['alvara'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_alvara_funcionamento'])) {
            $total++;
        }

        if (!$clienteParalisado && $clienteGoias && $alvaraGoiasDisponivel) {
            try {
                $stmtGoias = $pdo->prepare("
                    SELECT orgao_codigo, situacao, vencimento
                    FROM cliente_alvaras_goias
                    WHERE cliente_id = ?
                ");
                $stmtGoias->execute([(int)$cliente['id']]);
                $orgaosGoias = [];

                foreach ($stmtGoias->fetchAll(PDO::FETCH_ASSOC) as $orgaoGoias) {
                    $orgaosGoias[$orgaoGoias['orgao_codigo']] = $orgaoGoias;
                }

                foreach (['bombeiros', 'vigilancia', 'prefeitura'] as $codigoOrgao) {
                    $orgao = $orgaosGoias[$codigoOrgao] ?? [];
                    $situacao = $orgao['situacao'] ?? '';
                    $vencimento = $orgao['vencimento'] ?? '';

                    if (in_array($situacao, ['', 'nao_informado', 'em_estudo'], true)) {
                        $total++;
                    } elseif ($situacao === 'com_vencimento' && ($vencimento === '' || $vencimento < $hoje)) {
                        $total++;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (($cliente['contador'] ?? '') === '' || ($cliente['contador'] ?? '') === 'nao') {
            $total++;
        }

        if (!$clienteParalisado && (($cliente['cadastro_crf'] ?? '') === '' || ($cliente['cadastro_crf'] ?? '') === 'nao_cadastrado')) {
            $total++;
        }

        if (!$clienteParalisado && ($cliente['cadastro_crf'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_crf_dados'])) {
            $total++;
        }

        if (!$clienteParalisado && (($cliente['cadastro_df_legal'] ?? '') === '' || ($cliente['cadastro_df_legal'] ?? '') === 'nao_cadastrado')) {
            $total++;
        }

        if (!$clienteParalisado && ($cliente['cadastro_df_legal'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_df_legal_dados'])) {
            $total++;
        }

        if (($cliente['procuracao_particular'] ?? '') !== 'nao_precisa_momento' && !empty($cliente['pendencia_procuracao_particular_dados'])) {
            $total++;
        }

        if (
            ($cliente['contrato_prestacao_servicos'] ?? '') === '' ||
            ($cliente['contrato_prestacao_servicos'] ?? '') === 'nao_possui'
        ) {
            $total++;
        }
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT c.id
                FROM clientes c
                LEFT JOIN cliente_alvaras ca ON ca.cliente_id = c.id
                WHERE c.alvara = 'possui'
                  AND c.cliente_contabil = 1
                  " . clientesFiltroAtivos($pdo, 'c') . "
                  {$filtroEmpresaClientesAlias}
                  " . ($paralisacaoDisponivel ? " AND NOT (COALESCE(c.paralisacao_status, 'ativa') = 'paralisada' AND (c.paralisacao_fim IS NULL OR c.paralisacao_fim >= CURDATE())) " : "") . "
                GROUP BY c.id
                HAVING COUNT(DISTINCT CASE
                    WHEN ca.orgao_codigo IN ('ibram', 'cbmdf', 'df_legal', 'pcdf', 'seagri', 'seedf', 'sudesc', 'visadf')
                     AND (
                        ca.situacao = 'dispensado'
                        OR (ca.situacao = 'com_vencimento' AND ca.vencimento IS NOT NULL)
                     )
                    THEN ca.orgao_codigo
                END) < 8
            ) alvaras_incompletos
        ");
        $total += (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM cliente_alvaras ca
            INNER JOIN clientes c ON c.id = ca.cliente_id
            WHERE ca.situacao = 'com_vencimento'
              AND ca.vencimento IS NOT NULL
              AND ca.vencimento < ?
              AND c.cliente_contabil = 1
              " . clientesFiltroAtivos($pdo, 'c') . "
              {$filtroEmpresaClientesAlias}
              " . ($paralisacaoDisponivel ? " AND NOT (COALESCE(c.paralisacao_status, 'ativa') = 'paralisada' AND (c.paralisacao_fim IS NULL OR c.paralisacao_fim >= CURDATE())) " : "") . "
        ");
        $stmt->execute([$hoje]);
        $total += (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
    }

    if (logiTabelaExiste($pdo, 'antivirus_controles')) {
        try {
            $stmtAntivirus = $pdo->query("
                SELECT COUNT(*)
                FROM antivirus_controles
                WHERE (
                    status = 'nao_possui'
                    OR (status = 'possui' AND (vencimento IS NULL OR vencimento < " . $pdo->quote($hoje) . "))
                )
                " . empresaFiltro($pdo, 'antivirus_controles') . "
            ");
            $total += (int)$stmtAntivirus->fetchColumn();
        } catch (Throwable $e) {
        }
    }

    return $total;
}
