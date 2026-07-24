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
                'CNH',
                'Comprovante de endereço',
                'Comprovante de endereço dos sócios',
                'E-mail dos sócios',
                'Nome da razão social',
                'Nome fantasia',
                'IPTU',
                'Certidão de casamento',
                'Termo de ciência',
                'Viabilidade',
                'DBE',
                'Taxa Junta',
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
                'Mudou endereço dos sócios?',
                'E-mail dos sócios',
                'Certidão de casamento',
                'Termo de ciência',
                'Viabilidade',
                'DBE',
                'Taxa Junta',
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
                'Baixa Prefeitura / DF Legal',
                'Entrega ao cliente',
                'Processo concluído',
            ],
            'checklist' => [
                'Distrato',
                'Documentos dos sócios',
                'Certidões',
                'DBE',
                'Taxa Junta',
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

function legalizacaoFluxoPorTipoECliente(string $tipo, array $cliente): array
{
    $fluxo = legalizacaoFluxoPorTipo($tipo);
    $ufCliente = strtoupper(trim((string)($cliente['uf'] ?? '')));

    if ($ufCliente !== 'GO') {
        $fluxo['etapas'] = array_values(array_filter(
            $fluxo['etapas'],
            static fn(string $etapa): bool => strcasecmp($etapa, 'Prefeitura') !== 0
        ));
        $fluxo['checklist'] = array_values(array_filter(
            $fluxo['checklist'],
            static fn(string $item): bool => strcasecmp($item, 'Taxa Prefeitura') !== 0
        ));
    }

    if ($ufCliente === 'DF') {
        $fluxo['etapas'] = array_values(array_filter(
            $fluxo['etapas'],
            static fn(string $etapa): bool => strcasecmp($etapa, 'Inscrição Municipal') !== 0
        ));
    }

    return $fluxo;
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
        'em_andamento' => 'bg-primary legalizacao-status-andamento',
        'pendente_cliente' => 'bg-warning text-dark',
        'pendente_orgao' => 'bg-info text-dark',
        'pausado' => 'bg-secondary legalizacao-status-pausado',
        'concluido' => 'bg-success legalizacao-status-concluido',
        'cancelado' => 'bg-danger legalizacao-status-cancelado',
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
            SELECT id, codigo, nome, documento, uf
            FROM clientes
            WHERE 1 = 1
            " . clientesFiltroAtivos($pdo) . "
            " . empresaFiltroClienteDireto($pdo) . "
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
        SELECT id, codigo, nome, documento, uf
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmt->execute([$clienteId]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cliente ?: null;
}

function legalizacaoBuscarProcesso(PDO $pdo, int $processoId): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.*, c.codigo AS cliente_codigo_atual, c.nome AS cliente_nome_atual, c.documento AS cliente_documento_atual, c.uf AS cliente_uf_atual
        FROM legalizacao_processos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmt->execute([$processoId]);
    $processo = $stmt->fetch(PDO::FETCH_ASSOC);
    return $processo ?: null;
}

function legalizacaoTemBaixaAtivaCliente(PDO $pdo, int $clienteId, int $ignorarProcessoId = 0): bool
{
    if ($clienteId <= 0 || !legalizacaoTabelasDisponiveis($pdo)) {
        return false;
    }

    $sqlIgnorar = $ignorarProcessoId > 0 ? 'AND id <> ?' : '';
    $parametros = [$clienteId];

    if ($ignorarProcessoId > 0) {
        $parametros[] = $ignorarProcessoId;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM legalizacao_processos
        WHERE cliente_id = ?
          AND tipo = 'baixa'
          AND status NOT IN ('concluido', 'cancelado')
          {$sqlIgnorar}
          " . empresaFiltro($pdo, 'legalizacao_processos') . "
    ");
    $stmt->execute($parametros);

    return (int)$stmt->fetchColumn() > 0;
}

function legalizacaoAtualizarSituacaoCliente(PDO $pdo, int $clienteId, string $situacao, ?string $motivo = null): void
{
    if ($clienteId <= 0 || !clientesSituacaoDisponivel($pdo)) {
        return;
    }

    if (!in_array($situacao, ['ativo', 'em_baixa', 'devolvido', 'baixado'], true)) {
        return;
    }

    $devolvidoEmSql = in_array($situacao, ['devolvido', 'baixado'], true)
        ? 'COALESCE(devolvido_em, NOW())'
        : 'NULL';

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET situacao_cliente = ?,
            devolvido_em = {$devolvidoEmSql},
            motivo_devolucao = ?
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmt->execute([
        $situacao,
        in_array($situacao, ['devolvido', 'baixado'], true) ? $motivo : null,
        $clienteId,
    ]);
}

function legalizacaoSincronizarSituacaoClienteBaixa(PDO $pdo, array $processo, string $evento = ''): void
{
    if (($processo['tipo'] ?? '') !== 'baixa') {
        return;
    }

    $clienteId = (int)($processo['cliente_id'] ?? 0);

    if ($clienteId <= 0) {
        return;
    }

    $status = $processo['status'] ?? 'em_andamento';
    $processoId = (int)($processo['id'] ?? 0);

    if ($status === 'concluido') {
        legalizacaoAtualizarSituacaoCliente($pdo, $clienteId, 'baixado', 'Baixa concluída pela legalização');
        return;
    }

    if ($status === 'cancelado' || $evento === 'excluir') {
        if (!legalizacaoTemBaixaAtivaCliente($pdo, $clienteId, $processoId)) {
            legalizacaoAtualizarSituacaoCliente($pdo, $clienteId, 'ativo');
        }
        return;
    }

    legalizacaoAtualizarSituacaoCliente($pdo, $clienteId, 'em_baixa');
}
