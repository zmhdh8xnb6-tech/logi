<?php

function avisoVencimentoDataBr(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function adicionarAvisoVencimento(array &$avisos, string $titulo, string $texto, string $url, string $icone = 'bi-calendar-event', ?string $resolverUrl = null, array $resolverModal = []): void
{
    $avisos[] = [
        'titulo' => $titulo,
        'texto' => $texto,
        'url' => $url,
        'resolver_url' => $resolverUrl ?? $url,
        'resolver_modal' => $resolverModal,
        'icone' => $icone,
        'quantidade' => 1,
    ];
}

function listarAvisosVencimentosSistema(PDO $pdo): array
{
    $hoje = date('Y-m-d');
    $limiteAlerta = date('Y-m-d', strtotime('+30 days'));
    $avisos = [];
    $paralisacaoDisponivel = logiColunaExiste($pdo, 'clientes', 'paralisacao_status');

    $stmt = $pdo->query("
        SELECT *
        FROM clientes
        WHERE 1 = 1
        " . clientesFiltroAtivos($pdo) . "
        " . empresaFiltroClienteDireto($pdo) . "
        ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
    ");
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $procuracoes = [
        [
            'status' => 'procuracao_receita_federal',
            'vencimento' => 'vencimento_procuracao_receita_federal',
            'nome' => 'Procuração Receita Federal',
        ],
        [
            'status' => 'procuracao_conectividade',
            'vencimento' => 'vencimento_procuracao_conectividade',
            'nome' => 'Procuração Conectividade',
        ],
        [
            'status' => 'procuracao_fgts',
            'vencimento' => 'vencimento_procuracao_fgts',
            'nome' => 'Procuração FGTS',
        ],
    ];

    foreach ($clientes as $cliente) {
        $clienteContabil = (int)($cliente['cliente_contabil'] ?? 1) === 1;
        $controlaCertificado = $clienteContabil || (int)($cliente['servico_certificado'] ?? 1) === 1;
        $clienteNome = trim(($cliente['codigo'] ?? '') . ' - ' . ($cliente['nome'] ?? ''));
        $clienteParalisado = $paralisacaoDisponivel
            && ($cliente['paralisacao_status'] ?? '') === 'paralisada';

        if (!$clienteParalisado && $controlaCertificado) {
            $vencimentoCertificado = $cliente['vencimento_certificado'] ?? '';

            if ($vencimentoCertificado >= $hoje && $vencimentoCertificado <= $limiteAlerta) {
                adicionarAvisoVencimento(
                    $avisos,
                    'Certificado digital a vencer',
                    $clienteNome . ' vence em ' . avisoVencimentoDataBr($vencimentoCertificado),
                    'certificados.php',
                    'bi-shield-lock',
                    null,
                    [
                        'tipo' => 'certificado',
                        'cliente_id' => (int)$cliente['id'],
                        'titulo' => 'Resolver certificado digital',
                        'cliente' => $clienteNome,
                        'descricao' => 'Informe a nova data de vencimento do certificado.',
                        'vencimento' => $vencimentoCertificado,
                    ]
                );
            }
        }

        if (!$clienteContabil) {
            continue;
        }

        foreach ($procuracoes as $procuracao) {
            if ($clienteParalisado && $procuracao['status'] === 'procuracao_conectividade') {
                continue;
            }

            $status = $cliente[$procuracao['status']] ?? '';
            $vencimento = $cliente[$procuracao['vencimento']] ?? '';

            if ($status === 'possui' && $vencimento >= $hoje && $vencimento <= $limiteAlerta) {
                adicionarAvisoVencimento(
                    $avisos,
                    $procuracao['nome'] . ' a vencer',
                    $clienteNome . ' vence em ' . avisoVencimentoDataBr($vencimento),
                    'procuracoes.php',
                    'bi-journal-text',
                    null,
                    [
                        'tipo' => 'procuracao',
                        'cliente_id' => (int)$cliente['id'],
                        'campo_status' => $procuracao['status'],
                        'campo_vencimento' => $procuracao['vencimento'],
                        'titulo' => 'Resolver ' . $procuracao['nome'],
                        'cliente' => $clienteNome,
                        'descricao' => 'Informe a nova data de vencimento da procuração.',
                        'vencimento' => $vencimento,
                    ]
                );
            }
        }
    }

    try {
        $stmtAlvaras = $pdo->query("
            SELECT ca.cliente_id, ca.orgao_codigo, ca.orgao_nome, ca.vencimento, c.codigo, c.nome
            FROM cliente_alvaras ca
            INNER JOIN clientes c ON c.id = ca.cliente_id
            WHERE ca.situacao = 'com_vencimento'
              AND ca.vencimento IS NOT NULL
              AND ca.vencimento >= " . $pdo->quote($hoje) . "
              AND ca.vencimento <= " . $pdo->quote($limiteAlerta) . "
              AND c.cliente_contabil = 1
              " . clientesFiltroAtivos($pdo, 'c') . "
              " . empresaFiltroClienteDireto($pdo, 'c') . "
              " . ($paralisacaoDisponivel ? " AND COALESCE(c.paralisacao_status, 'ativa') <> 'paralisada' " : "") . "
            ORDER BY ca.vencimento ASC
        ");

        foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvara) {
            adicionarAvisoVencimento(
                $avisos,
                'Alvará a vencer',
                trim(($alvara['codigo'] ?? '') . ' - ' . ($alvara['nome'] ?? '')) . ' | ' . ($alvara['orgao_nome'] ?? 'Órgão') . ' vence em ' . avisoVencimentoDataBr($alvara['vencimento'] ?? ''),
                'alvaras.php',
                'bi-building',
                null,
                [
                    'tipo' => 'alvara',
                    'cliente_id' => (int)$alvara['cliente_id'],
                    'orgao_codigo' => $alvara['orgao_codigo'] ?? '',
                    'titulo' => 'Resolver alvará',
                    'cliente' => trim(($alvara['codigo'] ?? '') . ' - ' . ($alvara['nome'] ?? '')),
                    'descricao' => ($alvara['orgao_nome'] ?? 'Órgão') . ': informe a nova data de vencimento.',
                    'vencimento' => $alvara['vencimento'] ?? '',
                ]
            );
        }
    } catch (Throwable $e) {
    }

    if (logiTabelaExiste($pdo, 'cliente_alvaras_goias')) {
        try {
            $stmtAlvarasGoias = $pdo->query("
                SELECT ag.cliente_id, ag.orgao_codigo, ag.orgao_nome, ag.vencimento, c.codigo, c.nome
                FROM cliente_alvaras_goias ag
                INNER JOIN clientes c ON c.id = ag.cliente_id
                WHERE ag.situacao = 'com_vencimento'
                  AND ag.vencimento IS NOT NULL
                  AND ag.vencimento >= " . $pdo->quote($hoje) . "
                  AND ag.vencimento <= " . $pdo->quote($limiteAlerta) . "
                  AND c.cliente_contabil = 1
                  " . clientesFiltroAtivos($pdo, 'c') . "
                  " . empresaFiltroClienteDireto($pdo, 'c') . "
                  " . ($paralisacaoDisponivel ? " AND COALESCE(c.paralisacao_status, 'ativa') <> 'paralisada' " : "") . "
                ORDER BY ag.vencimento ASC
            ");

            foreach ($stmtAlvarasGoias->fetchAll(PDO::FETCH_ASSOC) as $alvara) {
                adicionarAvisoVencimento(
                    $avisos,
                    'Alvará Goiás a vencer',
                    trim(($alvara['codigo'] ?? '') . ' - ' . ($alvara['nome'] ?? '')) . ' | ' . ($alvara['orgao_nome'] ?? 'Órgão') . ' vence em ' . avisoVencimentoDataBr($alvara['vencimento'] ?? ''),
                    'alvaras_goias.php',
                    'bi-buildings'
                );
            }
        } catch (Throwable $e) {
        }
    }

    return $avisos;
}
