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

        if ($clienteContabil && $clienteEnderecoIncompleto($cliente)) {
            $total++;
        }

        if ($controlaCertificado && !empty($cliente['pendencia_certificado_digital'])) {
            $total++;
        }

        if ($controlaCertificado) {
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
            $status = $cliente[$procuracao['status']] ?? '';

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

        if (($cliente['alvara'] ?? '') === '' || ($cliente['alvara'] ?? '') === 'nao_possui') {
            $total++;
        }

        if (!empty($cliente['pendencia_alvara_funcionamento'])) {
            $total++;
        }

        if (($cliente['contador'] ?? '') === '' || ($cliente['contador'] ?? '') === 'nao') {
            $total++;
        }

        if (($cliente['cadastro_crf'] ?? '') === '' || ($cliente['cadastro_crf'] ?? '') === 'nao_cadastrado') {
            $total++;
        }

        if (!empty($cliente['pendencia_crf_dados'])) {
            $total++;
        }

        if (($cliente['cadastro_df_legal'] ?? '') === '' || ($cliente['cadastro_df_legal'] ?? '') === 'nao_cadastrado') {
            $total++;
        }

        if (!empty($cliente['pendencia_df_legal_dados'])) {
            $total++;
        }

        if (!empty($cliente['pendencia_procuracao_particular_dados'])) {
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
        ");
        $stmt->execute([$hoje]);
        $total += (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
    }

    return $total;
}
