<?php

function legalizacaoTabelasDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;

    if ($disponivel !== null) {
        return $disponivel;
    }

    $tabelas = [
        'legalizacao_processos',
        'legalizacao_etapas',
        'legalizacao_checklist',
        'legalizacao_historico',
    ];

    try {
        $marcadores = implode(',', array_fill(0, count($tabelas), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ({$marcadores})
        ");
        $stmt->execute($tabelas);
        $disponivel = (int)$stmt->fetchColumn() === count($tabelas);
    } catch (Throwable $e) {
        $disponivel = false;
    }

    return $disponivel;
}

function legalizacaoTiposProcesso(): array
{
    return [
        'constituicao' => 'Constituição',
        'alteracao_contratual' => 'Alteração Contratual',
        'baixa' => 'Baixa',
        'alvara' => 'Alvará / Licenciamento',
        'inscricao_estadual' => 'Inscrição Estadual',
        'inscricao_municipal' => 'Inscrição Municipal',
        'regularizacao' => 'Regularização',
        'outros' => 'Outros',
    ];
}

function legalizacaoFluxosPadrao(): array
{
    return [
        'constituicao' => [
            'etapas' => [
                'Receber documentos',
                'Consulta de Viabilidade',
                'DBE',
                'Contrato Social',
                'Assinaturas',
                'Junta Comercial',
                'CNPJ',
                'Inscrição Estadual',
                'Inscrição Municipal',
                'Licenciamento',
                'Entrega ao cliente',
                'Processo concluído',
            ],
            'checklist' => [
                'RG',
                'CPF',
                'Comprovante de endereço',
                'IPTU',
                'Procuração',
                'DBE',
                'Contrato Social',
                'Assinatura GOV',
                'Taxa Junta',
                'Taxa Prefeitura',
            ],
        ],
        'alteracao_contratual' => [
            'etapas' => [
                'Recebimento dos documentos',
                'Análise da documentação',
                'Elaboração da alteração',
                'Enviar para assinatura',
                'Assinatura recebida',
                'Protocolar na Junta',
                'Aguardando deferimento',
                'Receita Federal',
                'Estado',
                'Prefeitura',
                'Alvarás/Licenças',
                'Processo concluído',
            ],
            'checklist' => [
                'Contrato atual',
                'Documentos dos sócios',
                'Alteração solicitada',
                'DBE',
                'Assinatura GOV',
                'Taxa Junta',
                'Comprovante do protocolo',
            ],
        ],
        'baixa' => [
            'etapas' => [
                'Recebimento dos documentos',
                'Análise de pendências',
                'Distrato social',
                'Assinaturas',
                'Junta Comercial',
                'Baixa CNPJ',
                'Baixa Estado',
                'Baixa Prefeitura',
                'Entrega ao cliente',
                'Processo concluído',
            ],
            'checklist' => [
                'Distrato',
                'Documentos dos sócios',
                'Certidões',
                'DBE',
                'Assinatura GOV',
                'Taxa Junta',
            ],
        ],
        'alvara' => [
            'etapas' => [
                'Recebimento das informações',
                'Análise do endereço',
                'Consulta de viabilidade',
                'Documentação do órgão',
                'Protocolo',
                'Aguardando análise',
                'Exigência',
                'Deferido',
                'Entrega ao cliente',
                'Processo concluído',
            ],
            'checklist' => [
                'IPTU',
                'Contrato social',
                'Comprovante de endereço',
                'Procuração',
                'Taxa do órgão',
                'Protocolo',
            ],
        ],
        'default' => [
            'etapas' => [
                'Novo processo',
                'Recebimento dos documentos',
                'Análise',
                'Protocolo',
                'Aguardando retorno',
                'Finalização',
                'Processo concluído',
            ],
            'checklist' => [
                'Documentos recebidos',
                'Procuração',
                'Protocolo',
                'Comprovante',
            ],
        ],
    ];
}

function legalizacaoFluxoPorTipo(string $tipo): array
{
    $fluxos = legalizacaoFluxosPadrao();
    return $fluxos[$tipo] ?? $fluxos['default'];
}

function legalizacaoTextoTipo(string $tipo): string
{
    $tipos = legalizacaoTiposProcesso();
    return $tipos[$tipo] ?? 'Outros';
}

function legalizacaoTextoStatus(string $status): string
{
    $textos = [
        'em_andamento' => 'Em andamento',
        'pendente_cliente' => 'Pendente cliente',
        'pendente_orgao' => 'Pendente órgão',
        'pausado' => 'Pausado',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    return $textos[$status] ?? 'Em andamento';
}

function legalizacaoClasseStatus(string $status): string
{
    return [
        'em_andamento' => 'bg-primary',
        'pendente_cliente' => 'bg-warning text-dark',
        'pendente_orgao' => 'bg-info text-dark',
        'pausado' => 'bg-secondary',
        'concluido' => 'bg-success',
        'cancelado' => 'bg-danger',
    ][$status] ?? 'bg-primary';
}

function legalizacaoStatusChecklist(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'recebido' => 'Recebido',
        'dispensado' => 'Dispensado',
    ][$status] ?? 'Pendente';
}

function legalizacaoClasseChecklist(string $status): string
{
    return [
        'pendente' => 'bg-warning text-dark',
        'recebido' => 'bg-success',
        'dispensado' => 'bg-secondary',
    ][$status] ?? 'bg-warning text-dark';
}

function legalizacaoRegistrarHistorico(PDO $pdo, int $processoId, string $acao, string $descricao): void
{
    $stmt = $pdo->prepare("
        INSERT INTO legalizacao_historico (
            processo_id,
            usuario_id,
            usuario_nome,
            acao,
            descricao,
            criado_em
        )
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $processoId,
        (int)($_SESSION['usuario_id'] ?? 0),
        $_SESSION['usuario_nome'] ?? 'Usuário',
        $acao,
        $descricao,
    ]);
}

function legalizacaoFlash(string $texto, string $tipo = 'success'): void
{
    $_SESSION['legalizacao_flash'] = [
        'texto' => $texto,
        'tipo' => $tipo,
    ];
}

function legalizacaoObterFlash(): ?array
{
    $flash = $_SESSION['legalizacao_flash'] ?? null;
    unset($_SESSION['legalizacao_flash']);
    return is_array($flash) ? $flash : null;
}

function legalizacaoRedirect(string $url, string $texto, string $tipo = 'success'): void
{
    legalizacaoFlash($texto, $tipo);
    header('Location: ' . $url);
    exit;
}

function legalizacaoFormatarData(?string $data): string
{
    if (!$data) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function legalizacaoFormatarDataHora(?string $data): string
{
    if (!$data) {
        return '-';
    }

    return date('d/m/Y H:i', strtotime($data));
}

function legalizacaoPrazoTexto(?string $prazo, string $status): array
{
    if (!$prazo) {
        return ['texto' => 'Sem prazo', 'classe' => 'legalizacao-prazo-neutro'];
    }

    if ($status === 'concluido') {
        return ['texto' => 'Concluído', 'classe' => 'legalizacao-prazo-ok'];
    }

    $hoje = new DateTimeImmutable('today');
    $dataPrazo = new DateTimeImmutable($prazo);
    $dias = (int)$hoje->diff($dataPrazo)->format('%r%a');

    if ($dias < 0) {
        return [
            'texto' => 'Vencido há ' . abs($dias) . ' ' . (abs($dias) === 1 ? 'dia' : 'dias'),
            'classe' => 'legalizacao-prazo-urgente',
        ];
    }

    if ($dias === 0) {
        return ['texto' => 'Vence hoje', 'classe' => 'legalizacao-prazo-alerta'];
    }

    return [
        'texto' => 'Faltam ' . $dias . ' ' . ($dias === 1 ? 'dia' : 'dias'),
        'classe' => $dias <= 3 ? 'legalizacao-prazo-urgente' : ($dias <= 7 ? 'legalizacao-prazo-alerta' : 'legalizacao-prazo-ok'),
    ];
}

function legalizacaoListarClientes(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, codigo, nome, documento
            FROM clientes
            ORDER BY CAST(codigo AS UNSIGNED), nome
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function legalizacaoListarUsuarios(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, nome
            FROM usuarios
            WHERE COALESCE(ativo, 1) = 1
            ORDER BY nome
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function legalizacaoBuscarCliente(PDO $pdo, int $clienteId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, codigo, nome, documento
        FROM clientes
        WHERE id = ?
    ");
    $stmt->execute([$clienteId]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cliente ?: null;
}

function legalizacaoBuscarProcesso(PDO $pdo, int $processoId): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.*, c.codigo AS cliente_codigo_atual, c.nome AS cliente_nome_atual, c.documento AS cliente_documento_atual
        FROM legalizacao_processos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
    ");
    $stmt->execute([$processoId]);
    $processo = $stmt->fetch(PDO::FETCH_ASSOC);
    return $processo ?: null;
}
