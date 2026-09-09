<?php

function orgaosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcelamento_simples.php',
        'Previdência Social e Tributos' => 'parcelamento_tributos.php',
        'PGFN' => 'parcelamento_pgfn.php',
        'SEFAZ DF' => 'parcelamento_sefazdf.php',
        'SEFAZ GO' => 'parcelamento_sefazgo.php',
    ];
}

function orgaosCanceladosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcecancelados_simples.php',
        'Previdência Social e Tributos' => 'parcecancelados_tributos.php',
        'PGFN' => 'parcecancelados_pgfn.php',
        'SEFAZ DF' => 'parcecancelados_sefazdf.php',
        'SEFAZ GO' => 'parcecancelados_sefazgo.php',
    ];
}

function orgaosLiquidadosParcelamento(): array
{
    return [
        'Simples Nacional' => 'parcliquidados_simples.php',
        'Previdência Social e Tributos' => 'parcliquidados_tributos.php',
        'PGFN' => 'parcliquidados_pgfn.php',
        'SEFAZ DF' => 'parcliquidados_sefazdf.php',
        'SEFAZ GO' => 'parcliquidados_sefazgo.php',
    ];
}

function urlOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function urlCanceladosOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosCanceladosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function urlLiquidadosOrgaoParcelamento(string $orgao): string
{
    $orgaos = orgaosLiquidadosParcelamento();

    return $orgaos[$orgao] ?? 'parcelamentos.php';
}

function parcelamentosTemColuna(PDO $pdo, string $coluna): bool
{
    static $cache = [];

    if (array_key_exists($coluna, $cache)) {
        return $cache[$coluna];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM parcelamentos LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$coluna] = false;
    }

    return $cache[$coluna];
}

function parcelamentosImpressoesTabelaExiste(PDO $pdo): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'parcelamentos_impressoes'");
        $existe = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $existe = false;
    }

    return $existe;
}

function competenciaAtualParcelamentos(): string
{
    return date('Y-m');
}

function competenciaInicioControleImpressaoParcelamentos(): string
{
    return '2026-07';
}

function rotuloCompetenciaParcelamentos(?string $competencia = null): string
{
    $competencia = $competencia ?: competenciaAtualParcelamentos();
    $data = DateTime::createFromFormat('Y-m-d', $competencia . '-01');

    return $data ? $data->format('m/Y') : date('m/Y');
}

function parcelamentosImpressaoRegistrada(PDO $pdo, string $orgao, ?string $competencia = null): bool
{
    if (!parcelamentosImpressoesTabelaExiste($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM parcelamentos_impressoes
        WHERE orgao = ?
          AND competencia = ?
          " . empresaFiltro($pdo, 'parcelamentos_impressoes') . "
        LIMIT 1
    ");
    $stmt->execute([$orgao, $competencia ?: competenciaAtualParcelamentos()]);

    return (bool)$stmt->fetchColumn();
}

function registrarImpressaoParcelamentos(PDO $pdo, string $orgao, int $usuarioId, ?string $competencia = null): bool
{
    if (!parcelamentosImpressoesTabelaExiste($pdo)) {
        return false;
    }

    $competencia = $competencia ?: competenciaAtualParcelamentos();

    $stmt = $pdo->prepare("
        INSERT INTO parcelamentos_impressoes (" . empresaInsertColuna($pdo, 'parcelamentos_impressoes') . "orgao, competencia, usuario_id, impresso_em)
        VALUES (" . empresaInsertPlaceholder($pdo, 'parcelamentos_impressoes') . "?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            usuario_id = VALUES(usuario_id),
            impresso_em = NOW()
    ");
    $stmt->execute(array_merge(
        empresaInsertValores($pdo, 'parcelamentos_impressoes'),
        [$orgao, $competencia, $usuarioId]
    ));

    registrarAuditoria(
        $pdo,
        'Parcelamentos',
        'imprimir',
        'parcelamento',
        null,
        'Registrou impressão dos parcelamentos de ' . $orgao . ' em ' . rotuloCompetenciaParcelamentos($competencia),
        null,
        ['orgao' => $orgao, 'competencia' => $competencia]
    );

    return true;
}

function contarParcelamentosAtivosPorOrgao(PDO $pdo, string $orgao): int
{
    $temLiquidadoEm = parcelamentosTemColuna($pdo, 'liquidado_em');
    $filtroSituacao = $temLiquidadoEm
        ? 'p.cancelado_em IS NULL AND p.liquidado_em IS NULL'
        : 'p.cancelado_em IS NULL';

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
          AND {$filtroSituacao}
          " . empresaFiltro($pdo, 'clientes', 'c') . "
    ");
    $stmt->execute([$orgao]);

    return (int)$stmt->fetchColumn();
}

function primeiraCompetenciaParcelamentoAtivo(PDO $pdo, string $orgao): ?string
{
    $temLiquidadoEm = parcelamentosTemColuna($pdo, 'liquidado_em');
    $filtroSituacao = $temLiquidadoEm
        ? 'p.cancelado_em IS NULL AND p.liquidado_em IS NULL'
        : 'p.cancelado_em IS NULL';

    $stmt = $pdo->prepare("
        SELECT MIN(COALESCE(p.data_primeira_parcela, DATE(p.criado_em), CURDATE()))
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
          AND {$filtroSituacao}
          " . empresaFiltro($pdo, 'clientes', 'c') . "
    ");
    $stmt->execute([$orgao]);
    $data = $stmt->fetchColumn();

    if (!$data) {
        return null;
    }

    return date('Y-m', strtotime((string)$data));
}

function competenciasParcelamentosPendentesImpressao(PDO $pdo, string $orgao): array
{
    $primeiraCompetencia = primeiraCompetenciaParcelamentoAtivo($pdo, $orgao);

    if ($primeiraCompetencia === null) {
        return [];
    }

    $primeiraCompetencia = max($primeiraCompetencia, competenciaInicioControleImpressaoParcelamentos());
    $inicio = DateTime::createFromFormat('Y-m-d', $primeiraCompetencia . '-01');
    $fim = DateTime::createFromFormat('Y-m-d', competenciaAtualParcelamentos() . '-01');

    if (!$inicio || !$fim) {
        return [];
    }

    $pendentes = [];

    while ($inicio <= $fim) {
        $competencia = $inicio->format('Y-m');

        if (!parcelamentosImpressaoRegistrada($pdo, $orgao, $competencia)) {
            $pendentes[] = $competencia;
        }

        $inicio->modify('+1 month');
    }

    return $pendentes;
}

function avisosParcelamentosImpressao(PDO $pdo): array
{
    if (!parcelamentosImpressoesTabelaExiste($pdo)) {
        return [];
    }

    $avisos = [];

    foreach (orgaosParcelamento() as $orgao => $url) {
        $totalAtivos = contarParcelamentosAtivosPorOrgao($pdo, $orgao);
        $competenciasPendentes = $totalAtivos > 0
            ? competenciasParcelamentosPendentesImpressao($pdo, $orgao)
            : [];

        if ($totalAtivos <= 0 || $competenciasPendentes === []) {
            continue;
        }

        $avisos[] = [
            'tipo' => 'parcelamentos_impressao',
            'titulo' => 'Impressão de parcelamentos pendente',
            'texto' => $orgao . ' possui ' . $totalAtivos . ' parcelamento' . ($totalAtivos === 1 ? '' : 's') . ' ativo' . ($totalAtivos === 1 ? '' : 's') . ' com impressão pendente em ' . implode(', ', array_map('rotuloCompetenciaParcelamentos', $competenciasPendentes)) . '.',
            'url' => $url,
            'quantidade' => count($competenciasPendentes),
        ];
    }

    return $avisos;
}

function renderizarBotaoImpressaoParcelamentos(string $orgao): void
{
?>
    <button
        type="button"
        class="btn btn-sm btn-outline-secondary btn-imprimir-parcelamentos"
        data-orgao="<?= htmlspecialchars($orgao) ?>"
        title="Imprimir dados">
        <i class="bi bi-printer"></i> Imprimir
    </button>
<?php
}

function renderizarScriptImpressaoParcelamentos(): void
{
?>
    <script>
        document.querySelectorAll('.btn-imprimir-parcelamentos').forEach(function(botao) {
            botao.addEventListener('click', function() {
                const orgao = botao.dataset.orgao || '';
                const dados = new URLSearchParams();
                dados.append('orgao', orgao);

                fetch('registrar_impressao_parcelamentos.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: dados.toString()
                }).finally(function() {
                    window.print();
                });
            });
        });
    </script>
<?php
}

function parcelamentosToken(): string
{
    if (empty($_SESSION['parcelamentos_csrf_token'])) {
        $_SESSION['parcelamentos_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['parcelamentos_csrf_token'];
}

function parcelamentosTokenValido(?string $token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['parcelamentos_csrf_token'])
        && hash_equals((string)$_SESSION['parcelamentos_csrf_token'], $token);
}

function buscarParcelamentosPendentesLiquidacao(
    PDO $pdo,
    string $orgao,
    ?array $ids = null,
    bool $bloquear = false
): array {
    if (!parcelamentosTemColuna($pdo, 'liquidado_em')) {
        return [];
    }

    $parametros = [$orgao];
    $filtroIds = '';

    if ($ids !== null) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $filtroIds = ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $parametros = array_merge($parametros, $ids);
    }

    $sufixoBloqueio = $bloquear ? ' FOR UPDATE' : '';

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.numero_parcelamento,
            p.parcelas_total,
            p.parcelas_emitidas,
            p.parcelas_atrasadas,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
          " . empresaFiltro($pdo, 'clientes', 'c') . "
          AND p.liquidado_em IS NULL
          AND p.cancelado_em IS NULL
          AND p.parcelas_total > 0
          AND p.parcelas_atrasadas = 0
          AND (
              p.parcelas_emitidas >= p.parcelas_total
              OR (
                  p.data_primeira_parcela IS NOT NULL
                  AND PERIOD_DIFF(
                      EXTRACT(YEAR_MONTH FROM CURDATE()),
                      EXTRACT(YEAR_MONTH FROM p.data_primeira_parcela)
                  ) + 1 >= p.parcelas_total
              )
          )
          {$filtroIds}
        ORDER BY CAST(c.codigo AS UNSIGNED), c.nome, p.id
        {$sufixoBloqueio}
    ");
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarParcelamentosPorOrgao(
    PDO $pdo,
    string $orgao,
    bool $cancelados = false,
    bool $liquidados = false,
    string $busca = '',
    ?int $limite = null,
    int $offset = 0
): array {
    if (!$cancelados && !$liquidados) {
        $GLOBALS['parcelamentos_pendentes_liquidacao'] = buscarParcelamentosPendentesLiquidacao($pdo, $orgao);
    }

    $temLiquidadoEm = parcelamentosTemColuna($pdo, 'liquidado_em');

    if ($liquidados) {
        $filtroSituacao = $temLiquidadoEm
            ? 'p.liquidado_em IS NOT NULL'
            : '1 = 0';
    } elseif ($cancelados) {
        $filtroSituacao = 'p.cancelado_em IS NOT NULL';
    } else {
        $filtroSituacao = $temLiquidadoEm
            ? 'p.cancelado_em IS NULL AND p.liquidado_em IS NULL'
            : 'p.cancelado_em IS NULL';
    }

    $parametros = [$orgao];
    $filtroBusca = parcelamentosFiltroBusca($busca, $parametros, $temLiquidadoEm);
    $limiteSql = '';

    if ($limite !== null) {
        $limite = max(1, min(100, $limite));
        $offset = max(0, $offset);
        $limiteSql = " LIMIT {$limite} OFFSET {$offset}";
    }

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome,
            c.documento AS cliente_documento
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
        AND {$filtroSituacao}
        " . empresaFiltro($pdo, 'clientes', 'c') . "
        {$filtroBusca}
        ORDER BY CAST(c.codigo AS UNSIGNED) ASC, c.nome ASC, p.id DESC
        {$limiteSql}
    ");

    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function parcelamentosFiltroBusca(string $busca, array &$parametros, bool $temLiquidadoEm): string
{
    $busca = trim($busca);
    if ($busca === '') {
        return '';
    }

    $termo = '%' . $busca . '%';
    $statusLiquidado = $temLiquidadoEm ? 'WHEN p.liquidado_em IS NOT NULL THEN \'Liquidado\'' : '';
    $campos = [
        'CAST(c.codigo AS CHAR) LIKE ?',
        'c.nome LIKE ?',
        'c.documento LIKE ?',
        'CAST(p.numero_parcelamento AS CHAR) LIKE ?',
        'p.forma_envio LIKE ?',
        "CASE
            WHEN p.cancelado_em IS NOT NULL THEN 'Cancelado'
            {$statusLiquidado}
            WHEN p.parcelas_atrasadas > 0 THEN 'Atrasado'
            ELSE 'Em dia'
        END LIKE ?",
    ];

    array_push($parametros, $termo, $termo, $termo, $termo, $termo, $termo);
    return 'AND (' . implode(' OR ', $campos) . ')';
}

function contarParcelamentosPorOrgao(
    PDO $pdo,
    string $orgao,
    bool $cancelados = false,
    bool $liquidados = false,
    string $busca = ''
): int {
    $temLiquidadoEm = parcelamentosTemColuna($pdo, 'liquidado_em');

    if ($liquidados) {
        $filtroSituacao = $temLiquidadoEm ? 'p.liquidado_em IS NOT NULL' : '1 = 0';
    } elseif ($cancelados) {
        $filtroSituacao = 'p.cancelado_em IS NOT NULL';
    } else {
        $filtroSituacao = $temLiquidadoEm
            ? 'p.cancelado_em IS NULL AND p.liquidado_em IS NULL'
            : 'p.cancelado_em IS NULL';
    }

    $parametros = [$orgao];
    $filtroBusca = parcelamentosFiltroBusca($busca, $parametros, $temLiquidadoEm);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.orgao = ?
          AND {$filtroSituacao}
          " . empresaFiltro($pdo, 'clientes', 'c') . "
          {$filtroBusca}
    ");
    $stmt->execute($parametros);

    return (int)$stmt->fetchColumn();
}

function paginarParcelamentosPorOrgao(
    PDO $pdo,
    string $orgao,
    bool $cancelados = false,
    bool $liquidados = false
): array {
    $porPagina = 15;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $total = contarParcelamentosPorOrgao($pdo, $orgao, $cancelados, $liquidados, $busca);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min($totalPaginas, max(1, (int)($_GET['pagina'] ?? 1)));
    $offset = ($pagina - 1) * $porPagina;

    return [
        'registros' => buscarParcelamentosPorOrgao(
            $pdo,
            $orgao,
            $cancelados,
            $liquidados,
            $busca,
            $porPagina,
            $offset
        ),
        'busca' => $busca,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total' => $total,
        'total_paginas' => $totalPaginas,
    ];
}

function renderizarBuscaParcelamentos(string $busca, int $total): void
{
?>
    <form method="get" class="row g-2 mb-3 busca-parcelamentos align-items-center">
        <div class="col-md-6 col-lg-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input
                    type="search"
                    class="form-control"
                    name="busca"
                    value="<?= htmlspecialchars($busca) ?>"
                    placeholder="Buscar por código, cliente, número ou status...">
                <button type="submit" class="btn btn-outline-primary">Buscar</button>
            </div>
        </div>
        <?php if ($busca !== ''): ?>
            <div class="col-auto">
                <a href="?" class="btn btn-outline-secondary">Limpar</a>
            </div>
        <?php endif; ?>
        <div class="col text-md-end text-muted small">
            <?= $total ?> registro<?= $total === 1 ? '' : 's' ?> · 15 por página
        </div>
    </form>
<?php
}

function renderizarPaginacaoParcelamentos(int $pagina, int $totalPaginas, string $busca = ''): void
{
    if ($totalPaginas <= 1) {
        return;
    }

    $criarUrl = static function (int $destino) use ($busca): string {
        $parametros = ['pagina' => $destino];
        if ($busca !== '') {
            $parametros['busca'] = $busca;
        }
        return '?' . http_build_query($parametros);
    };
?>
    <nav class="mt-3" aria-label="Paginação dos parcelamentos">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagina <= 1 ? '#' : htmlspecialchars($criarUrl($pagina - 1)) ?>">Anterior</a>
            </li>
            <?php
            $ultima = 0;
            for ($numero = 1; $numero <= $totalPaginas; $numero++):
                if ($numero !== 1 && $numero !== $totalPaginas && abs($numero - $pagina) > 2) {
                    continue;
                }
                if ($ultima > 0 && $numero - $ultima > 1):
            ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php
                endif;
                $ultima = $numero;
                ?>
                <li class="page-item <?= $numero === $pagina ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($criarUrl($numero)) ?>"><?= $numero ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagina >= $totalPaginas ? '#' : htmlspecialchars($criarUrl($pagina + 1)) ?>">Próxima</a>
            </li>
        </ul>
    </nav>
    <?php
}

function renderizarResultadoRevisaoLiquidacoes(): void
{
    if (isset($_GET['revisao_liquidacao'])) {
    ?>
        <div class="alert alert-success alert-auto-dismiss fade show">
            Decisões salvas. Somente os parcelamentos confirmados foram enviados para Liquidados.
        </div>
    <?php
        return;
    }

    if (isset($_GET['revisao_liquidacao_desatualizada'])) {
    ?>
        <div class="alert alert-warning fade show">
            A lista mudou antes da confirmação. Revise as informações novamente; nenhum parcelamento foi alterado.
        </div>
    <?php
        return;
    }

    if (isset($_GET['erro_revisao_liquidacao'])) {
    ?>
        <div class="alert alert-danger fade show">
            Não foi possível salvar as decisões. Nenhum parcelamento foi alterado.
        </div>
    <?php
    }
}

function renderizarRevisaoLiquidacoesPendentes(string $orgao): void
{
    $pendentes = $GLOBALS['parcelamentos_pendentes_liquidacao'] ?? [];

    if (!$pendentes) {
        return;
    }
    ?>
    <div
        class="modal fade"
        id="modalLiquidacoesAutomaticas"
        tabindex="-1"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        aria-labelledby="tituloRevisaoLiquidacoes"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="parcelamentos_revisar_liquidacao.php" id="formRevisaoLiquidacoes" novalidate>
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="tituloRevisaoLiquidacoes">
                                <i class="bi bi-question-circle text-primary me-2"></i>
                                Confirmar parcelamentos concluídos
                            </h5>
                            <p class="text-muted small mb-0 mt-1">Nada será alterado até você salvar as respostas.</p>
                        </div>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            Estes parcelamentos chegaram ao final. Informe se cada cliente realizou o pagamento:
                        </p>

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(parcelamentosToken()) ?>">
                        <input type="hidden" name="orgao" value="<?= htmlspecialchars($orgao) ?>">

                        <div class="list-group revisao-liquidacoes-lista">
                            <?php foreach ($pendentes as $parcelamento):
                                $id = (int)$parcelamento['id'];
                            ?>
                                <div class="list-group-item revisao-liquidacao-item">
                                    <input type="hidden" name="parcelamentos[]" value="<?= $id ?>">
                                    <div class="revisao-liquidacao-dados">
                                        <div class="h6 mb-1">
                                            <?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>
                                        </div>
                                        <span class="text-muted small">
                                            Nº <?= htmlspecialchars($parcelamento['numero_parcelamento']) ?>
                                            · <?= (int)$parcelamento['parcelas_total'] ?>/<?= (int)$parcelamento['parcelas_total'] ?> parcelas
                                        </span>
                                    </div>
                                    <div class="revisao-liquidacao-opcoes" role="group" aria-label="O cliente pagou este parcelamento?">
                                        <input
                                            type="radio"
                                            class="btn-check"
                                            name="decisoes[<?= $id ?>]"
                                            id="liquidacaoNao<?= $id ?>"
                                            value="nao"
                                            required>
                                        <label class="btn btn-outline-danger" for="liquidacaoNao<?= $id ?>">
                                            <i class="bi bi-x-circle"></i> Não pagou
                                        </label>

                                        <input
                                            type="radio"
                                            class="btn-check"
                                            name="decisoes[<?= $id ?>]"
                                            id="liquidacaoSim<?= $id ?>"
                                            value="sim"
                                            required>
                                        <label class="btn btn-outline-success" for="liquidacaoSim<?= $id ?>">
                                            <i class="bi bi-check-circle"></i> Sim, pagou
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="alert alert-danger d-none mt-3 mb-0" id="avisoRevisaoLiquidacoes" role="alert">
                            Responda Sim ou Não para todos os parcelamentos.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <span class="text-muted small me-auto">A pergunta reaparecerá se você sair sem responder.</span>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2"></i> Salvar decisões
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('modalLiquidacoesAutomaticas');
            const formulario = document.getElementById('formRevisaoLiquidacoes');
            const aviso = document.getElementById('avisoRevisaoLiquidacoes');

            bootstrap.Modal.getOrCreateInstance(modal, {
                backdrop: 'static',
                keyboard: false
            }).show();

            formulario.addEventListener('submit', function(event) {
                const grupos = new Map();

                formulario.querySelectorAll('input[type="radio"]').forEach(function(campo) {
                    if (!grupos.has(campo.name)) {
                        grupos.set(campo.name, []);
                    }

                    grupos.get(campo.name).push(campo);
                });

                const grupoIncompleto = Array.from(grupos.values()).find(function(campos) {
                    return !campos.some(function(campo) {
                        return campo.checked;
                    });
                });

                if (grupoIncompleto) {
                    event.preventDefault();
                    aviso.classList.remove('d-none');
                    grupoIncompleto[0].focus();
                    return;
                }

                aviso.classList.add('d-none');
            });
        });
    </script>
<?php
}

function renderizarModalQuitarParcelamento(): void
{
?>
    <div class="modal fade" id="modalQuitarParcelamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="parcelamento_quitar.php" id="formQuitarParcelamento" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">Quitar parcelamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <strong id="clienteParcelamentoQuitar" class="d-block mb-3"></strong>

                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between gap-3 mb-2">
                                <span class="text-muted">Parcela atual</span>
                                <strong id="parcelaAtualQuitar"></strong>
                            </div>
                            <div class="d-flex justify-content-between gap-3">
                                <span class="text-muted">Parcelas atrasadas</span>
                                <strong id="parcelasAtrasadasQuitar"></strong>
                            </div>
                        </div>

                        <input type="hidden" name="id" id="parcelamentoIdQuitar">

                        <div class="mb-3">
                            <label for="dataQuitacao" class="form-label">Data da quitação</label>
                            <input
                                type="date"
                                class="form-control"
                                name="data_quitacao"
                                id="dataQuitacao"
                                value="<?= date('Y-m-d') ?>"
                                max="<?= date('Y-m-d') ?>">
                            <div class="invalid-feedback">Informe uma data válida.</div>
                        </div>

                        <div>
                            <label for="observacaoQuitacao" class="form-label">Observação <span class="text-muted">(opcional)</span></label>
                            <textarea
                                class="form-control"
                                name="observacao"
                                id="observacaoQuitacao"
                                rows="3"
                                maxlength="500"
                                placeholder="Ex.: cliente realizou o pagamento integral"></textarea>
                        </div>

                        <p class="text-muted small mt-3 mb-0">
                            O parcelamento será enviado para Liquidados como quitado antecipadamente.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar quitação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('modalQuitarParcelamento').addEventListener('show.bs.modal', function(event) {
            const botao = event.relatedTarget;

            document.getElementById('parcelamentoIdQuitar').value = botao.dataset.parcelamentoId;
            document.getElementById('clienteParcelamentoQuitar').textContent = botao.dataset.cliente;
            document.getElementById('parcelaAtualQuitar').textContent = botao.dataset.parcelas;
            document.getElementById('parcelasAtrasadasQuitar').textContent = botao.dataset.atrasadas;
        });

        document.getElementById('formQuitarParcelamento').addEventListener('submit', function(event) {
            const campoData = document.getElementById('dataQuitacao');

            if (!campoData.value || campoData.value > campoData.max) {
                event.preventDefault();
                campoData.classList.add('is-invalid');
                campoData.focus();
                return;
            }

            campoData.classList.remove('is-invalid');
        });
    </script>
    <?php
}

function buscarParcelamentoPorId(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.codigo AS cliente_codigo,
            c.nome AS cliente_nome
        FROM parcelamentos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
          " . empresaFiltro($pdo, 'clientes', 'c') . "
    ");

    $stmt->execute([$id]);
    $parcelamento = $stmt->fetch(PDO::FETCH_ASSOC);

    return $parcelamento ?: null;
}

function statusParcelamento(array $parcelamento): string
{
    if (!empty($parcelamento['cancelado_em'])) {
        return 'Cancelado';
    }

    if (!empty($parcelamento['liquidado_em'])) {
        return ($parcelamento['liquidacao_tipo'] ?? '') === 'antecipada'
            ? 'Quitado antecipadamente'
            : 'Liquidado';
    }

    if ((int)$parcelamento['parcelas_atrasadas'] > 0) {
        return 'Atrasado';
    }

    return 'Em dia';
}

function parcelasEmitidasAtual(array $parcelamento): int
{
    $dataReferencia = new DateTime(date('Y-m-d'));

    if (!empty($parcelamento['cancelado_em'])) {
        $dataReferencia = new DateTime($parcelamento['cancelado_em']);
    } elseif (!empty($parcelamento['liquidado_em'])) {
        $dataReferencia = new DateTime($parcelamento['liquidado_em']);
    }

    return parcelasEmitidasNaData($parcelamento, $dataReferencia);
}

function parcelasEmitidasNaData(array $parcelamento, DateTimeInterface $dataReferencia): int
{
    $parcelasTotal = (int)$parcelamento['parcelas_total'];

    if ($parcelasTotal <= 0) {
        return 0;
    }

    if (empty($parcelamento['data_primeira_parcela'])) {
        return min((int)$parcelamento['parcelas_emitidas'], $parcelasTotal);
    }

    $inicio = new DateTime($parcelamento['data_primeira_parcela']);
    $referencia = new DateTime($dataReferencia->format('Y-m-d'));

    if ($referencia < $inicio) {
        return 0;
    }

    $meses = (($referencia->format('Y') - $inicio->format('Y')) * 12)
        + ($referencia->format('n') - $inicio->format('n'));

    return min($meses + 1, $parcelasTotal);
}

function diasDesdeCancelamento(array $parcelamento): int
{
    if (empty($parcelamento['cancelado_em'])) {
        return 0;
    }

    $cancelamento = new DateTime($parcelamento['cancelado_em']);
    $hoje = new DateTime(date('Y-m-d'));

    if ($hoje <= $cancelamento) {
        return 0;
    }

    return (int)$cancelamento->diff($hoje)->days;
}

function renderizarLinhasParcelamentos(
    array $parcelamentos,
    bool $mostrarAcoes = true,
    bool $mostrarReativar = false,
    bool $mostrarVoltarLiquidado = false
): void {
    if (count($parcelamentos) === 0): ?>
        <tr>
            <td colspan="<?= $mostrarAcoes ? 9 : 8 ?>" class="text-center text-muted py-4">
                Nenhum parcelamento cadastrado ainda.
            </td>
        </tr>
    <?php return;
    endif;

    foreach ($parcelamentos as $parcelamento):
        $parcelasEmitidas = parcelasEmitidasAtual($parcelamento);
        $parcelasAtrasadas = max(0, (int)$parcelamento['parcelas_atrasadas']);
        $parcelasPagas = max(0, $parcelasEmitidas - $parcelasAtrasadas);
        $parcelasRestantes = max(0, (int)$parcelamento['parcelas_total'] - $parcelasPagas);
        $parcelasAoReativar = parcelasEmitidasNaData(
            $parcelamento,
            new DateTime(date('Y-m-d'))
        );
        $status = statusParcelamento($parcelamento);
        $badge = 'success';

        if ($status === 'Atrasado') {
            $badge = 'danger';
        } elseif ($status === 'Liquidado') {
            $badge = 'success';
        } elseif ($status === 'Cancelado') {
            $badge = 'dark';
        }
    ?>
        <tr
            class="linha-cliente linha-parcelamento linha-parcelamento-detalhes"
            role="button"
            tabindex="0"
            title="Consultar detalhes do parcelamento"
            data-id="<?= (int)$parcelamento['id'] ?>"
            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>"
            data-documento="<?= htmlspecialchars($parcelamento['cliente_documento'] ?? '-') ?>"
            data-orgao="<?= htmlspecialchars($parcelamento['orgao']) ?>"
            data-numero="<?= htmlspecialchars($parcelamento['numero_parcelamento']) ?>"
            data-forma-envio="<?= htmlspecialchars($parcelamento['forma_envio']) ?>"
            data-primeira-parcela="<?= !empty($parcelamento['data_primeira_parcela']) ? (new DateTime($parcelamento['data_primeira_parcela']))->format('d/m/Y') : '-' ?>"
            data-total="<?= (int)$parcelamento['parcelas_total'] ?>"
            data-emitidas="<?= $parcelasEmitidas ?>"
            data-pagas="<?= $parcelasPagas ?>"
            data-atrasadas="<?= $parcelasAtrasadas ?>"
            data-restantes="<?= $parcelasRestantes ?>"
            data-status="<?= htmlspecialchars($status) ?>">
            <td>
                <?= htmlspecialchars($parcelamento['cliente_codigo']) ?>
                -
                <?= htmlspecialchars($parcelamento['cliente_nome']) ?>
                <?php if (!empty($parcelamento['liquidado_em'])): ?>
                    <div class="small text-muted mt-1">
                        Liquidado em <?= (new DateTime($parcelamento['liquidado_em']))->format('d/m/Y') ?>
                        <?php if (!empty($parcelamento['liquidacao_observacao'])): ?>
                            · <?= htmlspecialchars($parcelamento['liquidacao_observacao']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td class="coluna-documento"><?= htmlspecialchars($parcelamento['cliente_documento'] ?? '-') ?></td>
            <td><?= htmlspecialchars($parcelamento['orgao']) ?></td>
            <td class="text-end"><?= htmlspecialchars($parcelamento['numero_parcelamento']) ?></td>
            <td class="text-end"><?= htmlspecialchars($parcelamento['forma_envio']) ?></td>
            <td class="text-end">
                <?= $parcelasEmitidas ?>
                /
                <?= (int)$parcelamento['parcelas_total'] ?>
            </td>
            <td class="text-end">
                <?php if ((int)$parcelamento['parcelas_atrasadas'] > 0): ?>
                    <span class="badge text-bg-danger">
                        <?= (int)$parcelamento['parcelas_atrasadas'] ?>
                    </span>
                <?php else: ?>
                    <span class="text-muted">0</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge text-bg-<?= $badge ?>">
                    <?= $status ?>
                </span>
            </td>
            <?php if ($mostrarAcoes): ?>
                <td class="text-end coluna-acoes">
                    <?php if ($mostrarReativar): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-success"
                            data-bs-toggle="modal"
                            data-bs-target="#modalReativarParcelamento"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>"
                            data-parcela-cancelada="<?= $parcelasEmitidas ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-parcela-reativada="<?= $parcelasAoReativar ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-dias-cancelado="<?= diasDesdeCancelamento($parcelamento) ?>"
                            data-cancelado-em="<?= !empty($parcelamento['cancelado_em']) ? (new DateTime($parcelamento['cancelado_em']))->format('d/m/Y') : '' ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Reativar
                        </button>
                    <?php elseif ($mostrarVoltarLiquidado): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning"
                            data-bs-toggle="modal"
                            data-bs-target="#modalVoltarLiquidado"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Voltar
                        </button>
                    <?php else: ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-success"
                            data-bs-toggle="modal"
                            data-bs-target="#modalQuitarParcelamento"
                            data-parcelamento-id="<?= (int)$parcelamento['id'] ?>"
                            data-cliente="<?= htmlspecialchars($parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome']) ?>"
                            data-parcelas="<?= $parcelasEmitidas ?>/<?= (int)$parcelamento['parcelas_total'] ?>"
                            data-atrasadas="<?= (int)$parcelamento['parcelas_atrasadas'] ?>"
                            title="Quitar parcelamento">
                            <i class="bi bi-check2-circle"></i>
                        </button>
                        <a
                            href="parcelamento_editar.php?id=<?= (int)$parcelamento['id'] ?>"
                            class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach;
}

function renderizarModalDetalhesParcelamento(): void
{
    ?>
    <div class="modal fade" id="modalDetalhesParcelamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Detalhes do parcelamento</h5>
                        <small class="text-muted" id="detalhesParcelamentoCliente"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3" id="detalhesParcelamentoImpressao">
                        <div class="col-md-6">
                            <span class="text-muted small d-block">Órgão</span>
                            <strong data-detalhe="orgao"></strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted small d-block">Número do parcelamento</span>
                            <strong data-detalhe="numero"></strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted small d-block">Forma de envio</span>
                            <strong data-detalhe="formaEnvio"></strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted small d-block">Primeira parcela</span>
                            <strong data-detalhe="primeiraParcela"></strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted small d-block">Status</span>
                            <strong data-detalhe="status"></strong>
                        </div>
                        <div class="col-12">
                            <hr class="my-1">
                        </div>
                        <div class="col-6 col-md">
                            <span class="text-muted small d-block">Total</span>
                            <strong data-detalhe="total"></strong>
                        </div>
                        <div class="col-6 col-md">
                            <span class="text-muted small d-block">Emitidas</span>
                            <strong data-detalhe="emitidas"></strong>
                        </div>
                        <div class="col-6 col-md">
                            <span class="text-muted small d-block">Pagas/regularizadas</span>
                            <strong class="text-success" data-detalhe="pagas"></strong>
                        </div>
                        <div class="col-6 col-md">
                            <span class="text-muted small d-block">Atrasadas</span>
                            <strong class="text-danger" data-detalhe="atrasadas"></strong>
                        </div>
                        <div class="col-6 col-md">
                            <span class="text-muted small d-block">Faltam</span>
                            <strong data-detalhe="restantes"></strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" id="btnImprimirDetalhesParcelamento">
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const modal = document.getElementById('modalDetalhesParcelamento');
            const modalBootstrap = bootstrap.Modal.getOrCreateInstance(modal);
            const cliente = document.getElementById('detalhesParcelamentoCliente');
            let dadosAtuais = null;

            modal.addEventListener('show.bs.modal', function(event) {
                const linha = event.relatedTarget;
                dadosAtuais = {
                    ...linha.dataset
                };
                cliente.textContent = dadosAtuais.cliente + ' · ' + (dadosAtuais.documento || '-');

                modal.querySelectorAll('[data-detalhe]').forEach(function(campo) {
                    campo.textContent = dadosAtuais[campo.dataset.detalhe] || '-';
                });
            });

            document.getElementById('btnImprimirDetalhesParcelamento').addEventListener('click', function() {
                if (!dadosAtuais) {
                    return;
                }

                const escapar = function(valor) {
                    const elemento = document.createElement('div');
                    elemento.textContent = valor || '-';
                    return elemento.innerHTML;
                };
                const janela = window.open('', '_blank', 'width=900,height=700');

                if (!janela) {
                    return;
                }

                janela.document.write(
                    '<!doctype html><html><head><meta charset="utf-8"><title>Parcelamento</title>' +
                    '<style>body{font-family:Arial,sans-serif;color:#111827;margin:32px}h1{font-size:22px;margin:0 0 6px}' +
                    'p{color:#4b5563;margin:0 0 24px}.dados{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}' +
                    '.item{border-bottom:1px solid #d1d5db;padding:10px 0}.item span{display:block;color:#6b7280;font-size:12px;margin-bottom:4px}' +
                    '.item strong{font-size:15px}@media print{body{margin:12mm}}</style></head><body>' +
                    '<h1>Parcelamento</h1><p>' + escapar(dadosAtuais.cliente) + '</p><div class="dados">' +
                    '<div class="item"><span>CPF/CNPJ</span><strong>' + escapar(dadosAtuais.documento) + '</strong></div>' +
                    '<div class="item"><span>Órgão</span><strong>' + escapar(dadosAtuais.orgao) + '</strong></div>' +
                    '<div class="item"><span>Número</span><strong>' + escapar(dadosAtuais.numero) + '</strong></div>' +
                    '<div class="item"><span>Forma de envio</span><strong>' + escapar(dadosAtuais.formaEnvio) + '</strong></div>' +
                    '<div class="item"><span>Primeira parcela</span><strong>' + escapar(dadosAtuais.primeiraParcela) + '</strong></div>' +
                    '<div class="item"><span>Status</span><strong>' + escapar(dadosAtuais.status) + '</strong></div>' +
                    '<div class="item"><span>Total de parcelas</span><strong>' + escapar(dadosAtuais.total) + '</strong></div>' +
                    '<div class="item"><span>Emitidas</span><strong>' + escapar(dadosAtuais.emitidas) + '</strong></div>' +
                    '<div class="item"><span>Pagas/regularizadas</span><strong>' + escapar(dadosAtuais.pagas) + '</strong></div>' +
                    '<div class="item"><span>Atrasadas</span><strong>' + escapar(dadosAtuais.atrasadas) + '</strong></div>' +
                    '<div class="item"><span>Faltam</span><strong>' + escapar(dadosAtuais.restantes) + '</strong></div>' +
                    '</div><script>window.onload=function(){window.print();window.onafterprint=function(){window.close()}};<\/script></body></html>'
                );
                janela.document.close();
            });

            document.querySelectorAll('.linha-parcelamento-detalhes').forEach(function(linha) {
                linha.addEventListener('click', function(event) {
                    if (event.target.closest('.coluna-acoes')) {
                        return;
                    }

                    modalBootstrap.show(linha);
                });

                linha.addEventListener('keydown', function(event) {
                    if (
                        (event.key === 'Enter' || event.key === ' ') &&
                        !event.target.closest('.coluna-acoes')
                    ) {
                        event.preventDefault();
                        modalBootstrap.show(linha);
                    }
                });
            });

            const linhasParcelamento = Array.from(document.querySelectorAll('.linha-parcelamento'));
            let impressaoParcelamentosAtiva = false;
            const cabecalhoImpressaoParcelamentos = document.querySelector('.orgao-impressao');
            const textoOriginalCabecalhoImpressao = cabecalhoImpressaoParcelamentos ?
                cabecalhoImpressaoParcelamentos.textContent.trim() :
                '';

            function linhasParcelamentoFiltradas() {
                return linhasParcelamento;
            }

            function formatarDataHoraImpressao() {
                const agora = new Date();

                return agora.toLocaleDateString('pt-BR') + ' às ' + agora.toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function atualizarCabecalhoImpressao() {
                if (!cabecalhoImpressaoParcelamentos) {
                    return;
                }

                const total = linhasParcelamentoFiltradas().length;
                const orgao = textoOriginalCabecalhoImpressao.replace(/^Órgão:\s*/i, '');

                cabecalhoImpressaoParcelamentos.innerHTML = `
                    <div>
                        <strong>Relatório de Parcelamentos</strong>
                        <span>Controle de parcelamentos dos clientes</span>
                    </div>
                    <div>
                        <strong>Órgão: ${orgao}</strong>
                        <span>Emitido em ${formatarDataHoraImpressao()} · Total: ${total}</span>
                    </div>
                `;
            }

            function restaurarCabecalhoImpressao() {
                if (cabecalhoImpressaoParcelamentos) {
                    cabecalhoImpressaoParcelamentos.textContent = textoOriginalCabecalhoImpressao;
                }
            }

            function renderizarParcelamentosPaginados() {
                if (!impressaoParcelamentosAtiva) {
                    return;
                }

                linhasParcelamento.forEach(function(linha) {
                    linha.classList.remove('d-none');
                });
            }

            window.addEventListener('beforeprint', function() {
                impressaoParcelamentosAtiva = true;
                document.body.classList.add('impressao-parcelamentos');
                atualizarCabecalhoImpressao();
                renderizarParcelamentosPaginados();
            });

            window.addEventListener('afterprint', function() {
                impressaoParcelamentosAtiva = false;
                document.body.classList.remove('impressao-parcelamentos');
                restaurarCabecalhoImpressao();
                renderizarParcelamentosPaginados();
            });

            renderizarParcelamentosPaginados();
        })();
    </script>
<?php
}
