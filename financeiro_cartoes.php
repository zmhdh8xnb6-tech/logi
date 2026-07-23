<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$cartaoSelecionadoId = (int)($_GET['cartao'] ?? $_POST['cartao_retorno'] ?? 0);
$mes = financeiroMesValido($_GET['mes'] ?? $_POST['mes'] ?? null);
$inicioMes = $mes . '-01';
$fimMes = date('Y-m-d', strtotime($inicioMes . ' +1 month'));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$proximoMes = date('Y-m', strtotime($inicioMes . ' +1 month'));
$dataPadraoCompra = date('Y-m-d');
$tabelasDisponiveis = financeiroTabelasDisponiveis(
    $pdo,
    ['financeiro_cartoes', 'financeiro_cartao_lancamentos']
);
$preferenciasDisponiveis = financeiroTabelasDisponiveis($pdo, ['financeiro_preferencias']);
$mostrarCartoesLoja = true;

if ($preferenciasDisponiveis) {
    $stmtPreferencias = $pdo->prepare("
        SELECT mostrar_cartoes_loja
        FROM financeiro_preferencias
        WHERE usuario_id = ?
    ");
    $stmtPreferencias->execute([$usuarioId]);
    $preferencias = $stmtPreferencias->fetch(PDO::FETCH_ASSOC);
    $mostrarCartoesLoja = !$preferencias || (int)$preferencias['mostrar_cartoes_loja'] === 1;
}
$temCompetenciaFatura = $tabelasDisponiveis
    && financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura');
$temContaFaturaCartao = financeiroTabelasDisponiveis($pdo, ['financeiro_contas'])
    && financeiroColunaExiste($pdo, 'financeiro_contas', 'cartao_id')
    && financeiroColunaExiste($pdo, 'financeiro_contas', 'competencia_cartao');
$expressaoCompetenciaFatura = $temCompetenciaFatura
    ? 'COALESCE(competencia_fatura, DATE_FORMAT(data_compra, \'%Y-%m-01\'))'
    : 'DATE_FORMAT(data_compra, \'%Y-%m-01\')';
$expressaoCompetenciaFaturaL = $temCompetenciaFatura
    ? 'COALESCE(l.competencia_fatura, DATE_FORMAT(l.data_compra, \'%Y-%m-01\'))'
    : 'DATE_FORMAT(l.data_compra, \'%Y-%m-01\')';
$categoriasDisponiveis = $tabelasDisponiveis && financeiroCategoriasDisponiveis($pdo);
$categoriasDespesa = [];
$todasCategoriasDespesa = [];
$categoriasPorId = [];

if ($categoriasDisponiveis) {
    financeiroGarantirCategoriasPadrao($pdo, $usuarioId);
    $categoriasDespesa = financeiroListarCategorias($pdo, $usuarioId, 'despesa');
    $todasCategoriasDespesa = financeiroListarCategorias($pdo, $usuarioId, 'despesa', false);

    foreach ($todasCategoriasDespesa as $categoria) {
        $categoriasPorId[(int)$categoria['id']] = $categoria;
    }
}

function urlCartoes(int $cartaoId = 0, ?string $mes = null): string
{
    $parametros = ['mes' => financeiroMesValido($mes)];

    if ($cartaoId > 0) {
        $parametros['cartao'] = $cartaoId;
    }

    return 'financeiro_cartoes.php?' . http_build_query($parametros);
}

function atualizarCategoriaCompraParcelada(PDO $pdo, int $usuarioId, array $lancamento, int $categoriaId): void
{
    $totalParcelas = (int)($lancamento['parcelas_total'] ?? 0);
    $quantidadeGrupo = 0;

    if (!empty($lancamento['grupo_parcelamento'])) {
        $stmt = $pdo->prepare("
            UPDATE financeiro_cartao_lancamentos
            SET categoria_id = ?
            WHERE usuario_id = ?
              AND grupo_parcelamento = ?
        ");
        $stmt->execute([
            $categoriaId,
            $usuarioId,
            $lancamento['grupo_parcelamento'],
        ]);

        $stmtQuantidade = $pdo->prepare("
            SELECT COUNT(*)
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND grupo_parcelamento = ?
        ");
        $stmtQuantidade->execute([$usuarioId, $lancamento['grupo_parcelamento']]);
        $quantidadeGrupo = (int)$stmtQuantidade->fetchColumn();
    }

    if (
        $totalParcelas > 1
        && (empty($lancamento['grupo_parcelamento']) || $quantidadeGrupo < $totalParcelas)
    ) {
        $stmt = $pdo->prepare("
            UPDATE financeiro_cartao_lancamentos
            SET categoria_id = ?
            WHERE usuario_id = ?
              AND cartao_id = ?
              AND descricao = ?
              AND parcelas_total = ?
        ");
        $stmt->execute([
            $categoriaId,
            $usuarioId,
            (int)$lancamento['cartao_id'],
            $lancamento['descricao'],
            $totalParcelas,
        ]);
    }
}

function condicaoCompraParceladaIncompleta(PDO $pdo, int $usuarioId, array $lancamento): array
{
    $totalParcelas = (int)($lancamento['parcelas_total'] ?? 0);

    if ($totalParcelas <= 1) {
        return [
            'where' => 'id = ? AND usuario_id = ?',
            'params' => [(int)$lancamento['id'], $usuarioId],
            'parcelada' => false,
        ];
    }

    if (!empty($lancamento['grupo_parcelamento'])) {
        $stmtQuantidade = $pdo->prepare("
            SELECT COUNT(*)
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND grupo_parcelamento = ?
        ");
        $stmtQuantidade->execute([$usuarioId, $lancamento['grupo_parcelamento']]);
        $quantidadeGrupo = (int)$stmtQuantidade->fetchColumn();

        if ($quantidadeGrupo >= $totalParcelas) {
            return [
                'where' => 'usuario_id = ? AND grupo_parcelamento = ?',
                'params' => [$usuarioId, $lancamento['grupo_parcelamento']],
                'parcelada' => true,
            ];
        }
    }

    return [
        'where' => 'usuario_id = ? AND cartao_id = ? AND descricao = ? AND parcelas_total = ?',
        'params' => [
            $usuarioId,
            (int)$lancamento['cartao_id'],
            $lancamento['descricao'],
            $totalParcelas,
        ],
        'parcelada' => true,
    ];
}

function completarCategoriasParceladas(PDO $pdo, int $usuarioId): void
{
    if (
        !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'categoria_id')
        || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'grupo_parcelamento')
    ) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE financeiro_cartao_lancamentos l
        INNER JOIN (
            SELECT
                usuario_id,
                grupo_parcelamento,
                MAX(categoria_id) AS categoria_id
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND grupo_parcelamento IS NOT NULL
              AND grupo_parcelamento <> ''
              AND categoria_id IS NOT NULL
              AND categoria_id > 0
            GROUP BY usuario_id, grupo_parcelamento
        ) base
            ON base.usuario_id = l.usuario_id
           AND base.grupo_parcelamento = l.grupo_parcelamento
        SET l.categoria_id = base.categoria_id
        WHERE l.usuario_id = ?
          AND (l.categoria_id IS NULL OR l.categoria_id = 0)
    ");
    $stmt->execute([$usuarioId, $usuarioId]);

    $stmt = $pdo->prepare("
        UPDATE financeiro_cartao_lancamentos l
        INNER JOIN (
            SELECT
                usuario_id,
                cartao_id,
                descricao,
                parcelas_total,
                MAX(categoria_id) AS categoria_id
            FROM financeiro_cartao_lancamentos
            WHERE usuario_id = ?
              AND parcelas_total > 1
              AND categoria_id IS NOT NULL
              AND categoria_id > 0
            GROUP BY usuario_id, cartao_id, descricao, parcelas_total
        ) base
            ON base.usuario_id = l.usuario_id
           AND base.cartao_id = l.cartao_id
           AND base.descricao = l.descricao
           AND base.parcelas_total = l.parcelas_total
        SET l.categoria_id = base.categoria_id
        WHERE l.usuario_id = ?
          AND l.parcelas_total > 1
          AND (l.categoria_id IS NULL OR l.categoria_id = 0)
    ");
    $stmt->execute([$usuarioId, $usuarioId]);
}

if ($categoriasDisponiveis) {
    completarCategoriasParceladas($pdo, $usuarioId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $urlRetorno = urlCartoes($cartaoSelecionadoId, $mes);

    if (!$tabelasDisponiveis) {
        financeiroRedirecionar($urlRetorno, 'Execute o SQL do financeiro antes de cadastrar.', 'danger');
    }

    if (!financeiroTokenValido($_POST['csrf_token'] ?? null)) {
        financeiroRedirecionar($urlRetorno, 'A sessão do formulário expirou. Tente novamente.', 'danger');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar_preferencias') {
        if (!$preferenciasDisponiveis) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das preferências financeiras antes de salvar.',
                'danger'
            );
        }

        $mostrarLojas = isset($_POST['mostrar_cartoes_loja']) ? 1 : 0;
        $stmt = $pdo->prepare("
            INSERT INTO financeiro_preferencias (
                usuario_id,
                mostrar_cartoes_loja,
                atualizado_em
            )
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                mostrar_cartoes_loja = VALUES(mostrar_cartoes_loja),
                atualizado_em = NOW()
        ");
        $stmt->execute([$usuarioId, $mostrarLojas]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'editar',
            'preferencias_financeiro',
            $usuarioId,
            'Alterou as preferências dos cartões',
            ['mostrar_cartoes_loja' => $mostrarCartoesLoja ? 1 : 0],
            ['mostrar_cartoes_loja' => $mostrarLojas]
        );
        financeiroRedirecionar($urlRetorno, 'Preferências atualizadas com sucesso.');
    }

    if ($acao === 'salvar_cartao') {
        $nome = trim($_POST['nome'] ?? '');
        $limiteInformado = $_POST['limite_total'] ?? '';
        $limite = financeiroValorEntrada($limiteInformado);
        $tipo = $mostrarCartoesLoja && ($_POST['tipo'] ?? '') === 'loja' ? 'loja' : 'credito';
        $diaVencimento = (int)($_POST['dia_vencimento'] ?? 0);
        $ativo = ($_POST['ativo'] ?? '1') === '0' ? 0 : 1;

        if (
            $nome === ''
            || !financeiroValorValido($limiteInformado)
            || $diaVencimento < 1
            || $diaVencimento > 31
        ) {
            financeiroRedirecionar($urlRetorno, 'Informe o nome, o limite e o dia de vencimento do cartão.', 'danger');
        }

        if ($id > 0) {
            $stmtCartaoAntes = $pdo->prepare("SELECT * FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
            $stmtCartaoAntes->execute([$id, $usuarioId]);
            $cartaoAntes = $stmtCartaoAntes->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare("
                UPDATE financeiro_cartoes
                SET nome = ?, limite_total = ?, tipo = ?, dia_vencimento = ?, ativo = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$nome, $limite, $tipo, $diaVencimento, $ativo, $id, $usuarioId]);
            $cartaoDepois = array_merge($cartaoAntes, [
                'nome' => $nome,
                'limite_total' => $limite,
                'tipo' => $tipo,
                'dia_vencimento' => $diaVencimento,
                'ativo' => $ativo,
            ]);
            $mudancas = auditoriaMudancas($cartaoAntes, $cartaoDepois);
            registrarAuditoria($pdo, 'Financeiro - Cartões', 'editar', 'cartao', $id, 'Alterou o cartão ' . $nome, $mudancas['antes'], $mudancas['depois']);
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(urlCartoes($id, $mes), 'Cartão atualizado com sucesso.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_cartoes
                (usuario_id, nome, limite_total, tipo, dia_vencimento, ativo)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuarioId, $nome, $limite, $tipo, $diaVencimento, $ativo]);
        $novoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'criar',
            'cartao',
            $novoId,
            'Cadastrou o cartão ' . $nome,
            null,
            [
                'nome' => $nome,
                'limite_total' => $limite,
                'tipo' => $tipo,
                'dia_vencimento' => $diaVencimento,
                'ativo' => $ativo,
            ]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes($novoId, $mes), 'Cartão cadastrado com sucesso.');
    }

    if ($acao === 'excluir_cartao') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $cartaoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                DELETE FROM financeiro_cartao_lancamentos
                WHERE cartao_id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $usuarioId]);

            $stmt = $pdo->prepare("DELETE FROM financeiro_cartoes WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $usuarioId]);
            $pdo->commit();
            if ($cartaoAntes) {
                registrarAuditoria(
                    $pdo,
                    'Financeiro - Cartões',
                    'excluir',
                    'cartao',
                    $id,
                    'Excluiu o cartão ' . $cartaoAntes['nome'] . ' e seus lançamentos',
                    $cartaoAntes,
                    null
                );
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            financeiroRedirecionar($urlRetorno, 'Não foi possível excluir o cartão.', 'danger');
        }

        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes(0, $mes), 'Cartão e lançamentos excluídos com sucesso.');
    }

    if ($acao === 'salvar_lancamento') {
        if (!$categoriasDisponiveis) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das categorias financeiras antes de salvar.',
                'danger'
            );
        }

        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $dataCompra = trim($_POST['data_compra'] ?? '');
        $mesFaturaCompra = trim($_POST['mes_fatura_compra'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $valorInformado = $_POST['valor'] ?? '';
        $valor = financeiroValorEntrada($valorInformado);
        $tipoCompraInformado = $_POST['tipo_compra'] ?? '';
        $tipoCompra = in_array($tipoCompraInformado, ['parcelada', 'recorrente'], true)
            ? $tipoCompraInformado
            : 'unica';
        $parcelasTotal = (int)($_POST['parcelas_total'] ?? 1);
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $dataMesFatura = DateTime::createFromFormat('!Y-m', $mesFaturaCompra);
        $mesFaturaValido = $dataMesFatura
            && $dataMesFatura->format('Y-m') === $mesFaturaCompra;

        $stmt = $pdo->prepare("
            SELECT id, ativo
            FROM financeiro_cartoes
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$cartaoId, $usuarioId]);
        $cartaoDestino = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cartaoDestino && $id <= 0 && (int)$cartaoDestino['ativo'] !== 1) {
            financeiroRedirecionar(
                $urlRetorno,
                'Cartões cancelados não aceitam novas compras.',
                'warning'
            );
        }

        if (
            !$cartaoDestino
            || $dataCompra === ''
            || !$mesFaturaValido
            || $descricao === ''
            || !financeiroValorValido($valorInformado)
            || !financeiroCategoriaValida($pdo, $usuarioId, $categoriaId, 'despesa')
        ) {
            financeiroRedirecionar($urlRetorno, 'Preencha os dados da compra corretamente.', 'danger');
        }

        if (!$temCompetenciaFatura) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL da competência da fatura antes de lançar a compra.',
                'danger'
            );
        }

        if (
            $tipoCompra === 'recorrente'
            && (
                !financeiroTabelasDisponiveis($pdo, ['financeiro_cartao_recorrencias'])
                || !financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'recorrencia_id')
            )
        ) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das compras recorrentes de cartão antes de lançar recorrências.',
                'danger'
            );
        }

        $competenciaFatura = $mesFaturaCompra . '-01';

        if ($id > 0) {
            $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartao_lancamentos WHERE id = ? AND usuario_id = ?");
            $stmtAntes->execute([$id, $usuarioId]);
            $lancamentoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare("
                UPDATE financeiro_cartao_lancamentos
                SET cartao_id = ?,
                    data_compra = ?,
                    competencia_fatura = ?,
                    descricao = ?,
                    valor = ?,
                    categoria_id = ?
                WHERE id = ? AND usuario_id = ? AND status = 'aberto'
            ");
            $stmt->execute([
                $cartaoId,
                $dataCompra,
                $competenciaFatura,
                $descricao,
                $valor,
                $categoriaId,
                $id,
                $usuarioId,
            ]);
            $lancamentoDepois = array_merge($lancamentoAntes, [
                'cartao_id' => $cartaoId,
                'data_compra' => $dataCompra,
                'competencia_fatura' => $competenciaFatura,
                'descricao' => $descricao,
                'valor' => $valor,
                'categoria_id' => $categoriaId,
            ]);
            atualizarCategoriaCompraParcelada($pdo, $usuarioId, $lancamentoAntes, $categoriaId);
            $mudancas = auditoriaMudancas($lancamentoAntes, $lancamentoDepois);
            registrarAuditoria($pdo, 'Financeiro - Cartões', 'editar', 'compra_cartao', $id, 'Alterou a compra ' . $descricao, $mudancas['antes'], $mudancas['depois']);
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Compra atualizada com sucesso.');
        }

        if ($tipoCompra === 'parcelada') {
            if ($parcelasTotal < 2 || $parcelasTotal > 600) {
                financeiroRedirecionar(
                    urlCartoes($cartaoId, $mes),
                    'Informe corretamente a quantidade de parcelas.',
                    'danger'
                );
            }

            $grupoParcelamento = bin2hex(random_bytes(16));
            $valorTotalCentavos = (int)round($valor * 100);
            $valorBaseCentavos = intdiv($valorTotalCentavos, $parcelasTotal);
            $centavosRestantes = $valorTotalCentavos - ($valorBaseCentavos * $parcelasTotal);
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_cartao_lancamentos (
                    usuario_id,
                    cartao_id,
                    data_compra,
                    competencia_fatura,
                    descricao,
                    valor,
                    status,
                    categoria_id,
                    grupo_parcelamento,
                    parcela_numero,
                    parcelas_total
                )
                VALUES (?, ?, ?, ?, ?, ?, 'aberto', ?, ?, ?, ?)
            ");

            $pdo->beginTransaction();

            try {
                for ($numero = 1; $numero <= $parcelasTotal; $numero++) {
                    $valorParcelaCentavos = $valorBaseCentavos + ($numero <= $centavosRestantes ? 1 : 0);
                    $valorParcela = $valorParcelaCentavos / 100;
                    $competenciaParcela = financeiroSomarMeses($competenciaFatura, $numero - 1);
                    $stmt->execute([
                        $usuarioId,
                        $cartaoId,
                        $dataCompra,
                        $competenciaParcela,
                        $descricao,
                        $valorParcela,
                        $categoriaId,
                        $grupoParcelamento,
                        $numero,
                        $parcelasTotal,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                financeiroRedirecionar(urlCartoes($cartaoId, $mes), 'Não foi possível gerar as parcelas da compra.', 'danger');
            }

            registrarAuditoria(
                $pdo,
                'Financeiro - Cartões',
                'criar_parcelas',
                'compra_cartao',
                $grupoParcelamento,
                'Lançou a compra parcelada ' . $descricao . ' em ' . $parcelasTotal . ' vezes',
                null,
                [
                    'cartao_id' => $cartaoId,
                    'data_compra' => $dataCompra,
                    'primeira_fatura' => $mesFaturaCompra,
                    'descricao' => $descricao,
                    'valor_total' => $valor,
                    'parcelas_total' => $parcelasTotal,
                    'categoria_id' => $categoriaId,
                ]
            );
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(
                urlCartoes($cartaoId, $mes),
                $parcelasTotal . ' parcelas lançadas e limite atualizado.'
            );
        }

        if ($tipoCompra === 'recorrente') {
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_cartao_recorrencias (
                    usuario_id,
                    cartao_id,
                    descricao,
                    valor,
                    categoria_id,
                    data_compra,
                    primeira_fatura,
                    ativa
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $usuarioId,
                $cartaoId,
                $descricao,
                $valor,
                $categoriaId,
                $dataCompra,
                $competenciaFatura,
            ]);
            $recorrenciaId = (int)$pdo->lastInsertId();
            financeiroSincronizarCartaoRecorrenciasAteMesAtual($pdo, $usuarioId);
            registrarAuditoria(
                $pdo,
                'Financeiro - Cartões',
                'criar_recorrencia',
                'compra_cartao_recorrente',
                $recorrenciaId,
                'Criou a compra recorrente do cartão ' . $descricao,
                null,
                [
                    'cartao_id' => $cartaoId,
                    'data_compra' => $dataCompra,
                    'primeira_fatura' => $mesFaturaCompra,
                    'descricao' => $descricao,
                    'valor' => $valor,
                    'categoria_id' => $categoriaId,
                ]
            );
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(
                urlCartoes($cartaoId, $mes),
                'Compra recorrente cadastrada e lançada na primeira fatura.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO financeiro_cartao_lancamentos (
                usuario_id,
                cartao_id,
                data_compra,
                competencia_fatura,
                descricao,
                valor,
                status,
                categoria_id,
                grupo_parcelamento,
                parcela_numero,
                parcelas_total
            )
            VALUES (?, ?, ?, ?, ?, ?, 'aberto', ?, NULL, NULL, NULL)
        ");
        $stmt->execute([
            $usuarioId,
            $cartaoId,
            $dataCompra,
            $competenciaFatura,
            $descricao,
            $valor,
            $categoriaId,
        ]);
        $novoLancamentoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'criar',
            'compra_cartao',
            $novoLancamentoId,
            $tipoCompra === 'recorrente'
                ? 'Lançou a compra recorrente ' . $descricao
                : 'Lançou a compra ' . $descricao,
            null,
            [
                'cartao_id' => $cartaoId,
                'data_compra' => $dataCompra,
                'competencia_fatura' => $competenciaFatura,
                'descricao' => $descricao,
                'valor' => $valor,
                'tipo_compra' => $tipoCompra,
                'categoria_id' => $categoriaId,
            ]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(
            urlCartoes($cartaoId, $mes),
            $tipoCompra === 'recorrente'
                ? 'Compra recorrente lançada nesta fatura.'
                : 'Compra lançada e limite atualizado.'
        );
    }

    if ($acao === 'categorizar_lancamento') {
        if (!$categoriasDisponiveis) {
            financeiroRedirecionar(
                $urlRetorno,
                'Execute o SQL das categorias financeiras antes de salvar.',
                'danger'
            );
        }

        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $stmtAntes = $pdo->prepare("
            SELECT *
            FROM financeiro_cartao_lancamentos
            WHERE id = ? AND usuario_id = ?
        ");
        $stmtAntes->execute([$id, $usuarioId]);
        $lancamentoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (
            !$lancamentoAntes
            || !financeiroCategoriaValida($pdo, $usuarioId, $categoriaId, 'despesa')
        ) {
            financeiroRedirecionar($urlRetorno, 'Selecione uma categoria válida.', 'danger');
        }

        if (
            empty($lancamentoAntes['grupo_parcelamento'])
            && (int)($lancamentoAntes['parcelas_total'] ?? 0) <= 1
        ) {
            $stmt = $pdo->prepare("
                UPDATE financeiro_cartao_lancamentos
                SET categoria_id = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$categoriaId, $id, $usuarioId]);
        } else {
            atualizarCategoriaCompraParcelada($pdo, $usuarioId, $lancamentoAntes, $categoriaId);
        }

        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'categorizar',
            'compra_cartao',
            $id,
            'Alterou a categoria da compra ' . $lancamentoAntes['descricao'],
            ['categoria_id' => $lancamentoAntes['categoria_id'] ?? null],
            ['categoria_id' => $categoriaId]
        );
        financeiroRedirecionar($urlRetorno, 'Categoria atualizada com sucesso.');
    }

    if ($acao === 'excluir_lancamento') {
        $stmtAntes = $pdo->prepare("SELECT * FROM financeiro_cartao_lancamentos WHERE id = ? AND usuario_id = ?");
        $stmtAntes->execute([$id, $usuarioId]);
        $lancamentoAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        if (!$lancamentoAntes) {
            financeiroRedirecionar($urlRetorno, 'Compra não encontrada.', 'danger');
        }

        $condicaoExclusao = condicaoCompraParceladaIncompleta($pdo, $usuarioId, $lancamentoAntes);

        if ($condicaoExclusao['parcelada']) {
            $stmtResumo = $pdo->prepare("
                SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0) AS valor_total
                FROM financeiro_cartao_lancamentos
                WHERE {$condicaoExclusao['where']}
            ");
            $stmtResumo->execute($condicaoExclusao['params']);
            $resumoExclusao = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [
                'quantidade' => 0,
                'valor_total' => 0,
            ];
            $stmt = $pdo->prepare("
                DELETE FROM financeiro_cartao_lancamentos
                WHERE {$condicaoExclusao['where']}
            ");
            $stmt->execute($condicaoExclusao['params']);
            registrarAuditoria(
                $pdo,
                'Financeiro - Cartões',
                'excluir_parcelamento',
                'compra_cartao',
                $lancamentoAntes['grupo_parcelamento'] ?: $id,
                'Excluiu todas as parcelas da compra ' . $lancamentoAntes['descricao'],
                [
                    'compra' => $lancamentoAntes,
                    'quantidade_parcelas' => (int)$resumoExclusao['quantidade'],
                    'valor_total' => (float)$resumoExclusao['valor_total'],
                ],
                null
            );
            financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
            financeiroRedirecionar(
                $urlRetorno,
                (int)$resumoExclusao['quantidade'] . ' parcelas excluídas e limite atualizado.'
            );
        }

        $stmt = $pdo->prepare("
            DELETE FROM financeiro_cartao_lancamentos
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'excluir',
            'compra_cartao',
            $id,
            'Excluiu a compra ' . $lancamentoAntes['descricao'],
            $lancamentoAntes,
            null
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar($urlRetorno, 'Lançamento excluído e limite atualizado.');
    }

    if ($acao === 'pagar_fatura') {
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $dataPagamento = trim($_POST['data_pagamento'] ?? '');
        $valorPagoInformado = $_POST['valor_pago'] ?? '';
        $valorPago = financeiroValorEntrada($valorPagoInformado);
        $mesFatura = financeiroMesValido($_POST['mes_fatura'] ?? null);
        $parcelarFatura = ($_POST['parcelar_fatura'] ?? '') === '1';
        $parcelasFatura = $parcelarFatura ? (int)($_POST['parcelas_fatura'] ?? 1) : 1;

        if (
            $cartaoId <= 0
            || $dataPagamento === ''
            || !financeiroValorValido($valorPagoInformado)
            || (!$parcelarFatura && $valorPago <= 0)
            || ($parcelarFatura && ($valorPago < 0 || $parcelasFatura < 2 || $parcelasFatura > 48))
            || !preg_match('/^\d{4}-\d{2}$/', $_POST['mes_fatura'] ?? '')
        ) {
            financeiroRedirecionar($urlRetorno, 'Informe o valor, o mês da fatura e a data de pagamento.', 'danger');
        }

        try {
            $resultadoPagamento = financeiroRegistrarPagamentoFaturaCartao(
                $pdo,
                $usuarioId,
                $cartaoId,
                $mesFatura,
                $valorPago,
                $dataPagamento,
                $parcelasFatura
            );
        } catch (Throwable $e) {
            financeiroRedirecionar($urlRetorno, 'Não foi possível registrar o pagamento da fatura.', 'danger');
        }

        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            $resultadoPagamento['pagamento_total'] ? 'pagar_fatura' : 'pagar_fatura_parcial',
            'cartao',
            $cartaoId,
            ($resultadoPagamento['pagamento_total'] ? 'Pagou' : 'Pagou parcialmente') . ' a fatura do cartão de ' . $mesFatura,
            [
                'parcelas_em_aberto' => $resultadoPagamento['parcelas_em_aberto'],
                'valor' => $resultadoPagamento['valor_total'],
            ],
            [
                'valor_pago' => $resultadoPagamento['valor_pago'],
                'restante' => $resultadoPagamento['restante'],
                'fatura_parcelada' => $resultadoPagamento['fatura_parcelada'],
                'parcelas_fatura' => $resultadoPagamento['parcelas_fatura'],
                'proxima_competencia' => $resultadoPagamento['proxima_competencia'],
                'data_pagamento' => $dataPagamento,
            ]
        );
        financeiroRedirecionar(
            urlCartoes($cartaoId, $mes),
            $resultadoPagamento['pagamento_total']
                ? 'Fatura paga e limite liberado.'
                : ($resultadoPagamento['fatura_parcelada']
                    ? 'Fatura parcelada e lançada nas próximas competências.'
                    : 'Pagamento parcial registrado. O saldo restante foi lançado na próxima fatura.')
        );
    }

    if ($acao === 'reabrir_lancamento') {
        $stmt = $pdo->prepare("
            SELECT l.valor, l.cartao_id, l.descricao
            FROM financeiro_cartao_lancamentos l
            INNER JOIN financeiro_cartoes c ON c.id = l.cartao_id AND c.usuario_id = l.usuario_id
            WHERE l.id = ? AND l.usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lancamento) {
            financeiroRedirecionar($urlRetorno, 'Compra não encontrada.', 'danger');
        }

        $stmt = $pdo->prepare("
            UPDATE financeiro_cartao_lancamentos
            SET status = 'aberto', data_pagamento = NULL
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$id, $usuarioId]);
        registrarAuditoria(
            $pdo,
            'Financeiro - Cartões',
            'reabrir',
            'compra_cartao',
            $id,
            'Reabriu a compra ' . ($lancamento['descricao'] ?? ('#' . $id)),
            ['status' => 'pago'],
            ['status' => 'aberto', 'data_pagamento' => null]
        );
        financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
        financeiroRedirecionar(urlCartoes((int)$lancamento['cartao_id'], $mes), 'Compra reaberta e limite recalculado.');
    }

    financeiroRedirecionar($urlRetorno, 'Ação de cartão inválida.', 'danger');
}

$mensagem = financeiroObterMensagem();
$cartoes = [];
$cartoesVisiveis = [];
$lancamentos = [];
$cartaoSelecionado = null;
$resumo = [
    'credito_limite' => 0.0,
    'credito_disponivel' => 0.0,
    'loja_limite' => 0.0,
    'loja_disponivel' => 0.0,
];
$alertasFinanceiros = [];

if ($tabelasDisponiveis) {
    financeiroSincronizarCartaoRecorrenciasAteMesAtual($pdo, $usuarioId);
    financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
    $mesAtualAlertas = date('Y-m');
    $proximoMesAlertas = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));

    foreach ([$mesAtualAlertas, $proximoMesAlertas] as $mesSincronizar) {
        financeiroSincronizarContasRecorrentes($pdo, $usuarioId, $mesSincronizar);
    }

    financeiroSincronizarFaturasCartoes($pdo, $usuarioId);
    $alertasFinanceiros = financeiroListarAlertasVencimento($pdo, $usuarioId, 10);
    $selectFaturaPagaMes = ', 0 AS fatura_paga_mes, 0 AS fatura_parcial_mes, 0 AS fatura_atrasada_mes, 0 AS fatura_parcelada_mes';
    $parametrosCartoes = [$inicioMes, $fimMes];

    if ($temContaFaturaCartao) {
        $selectFaturaPagaMes = ",
            COALESCE((
                SELECT CASE
                    WHEN fc.status = 'pago'
                     AND COALESCE(fc.valor_previsto, 0) > 0
                     AND COALESCE(fc.valor_pago, 0) >= COALESCE(fc.valor_previsto, 0)
                    THEN 1
                    ELSE 0
                END
                FROM financeiro_contas fc
                WHERE fc.usuario_id = c.usuario_id
                  AND fc.cartao_id = c.id
                  AND fc.competencia_cartao = ?
                ORDER BY fc.id DESC
                LIMIT 1
            ), 0) AS fatura_paga_mes,
            COALESCE((
                SELECT CASE
                    WHEN fc.status = 'pago'
                     AND COALESCE(fc.valor_pago, 0) > 0
                     AND COALESCE(fc.valor_pago, 0) < COALESCE(fc.valor_previsto, 0)
                    THEN 1
                    ELSE 0
                END
                FROM financeiro_contas fc
                WHERE fc.usuario_id = c.usuario_id
                  AND fc.cartao_id = c.id
                  AND fc.competencia_cartao = ?
                ORDER BY fc.id DESC
                LIMIT 1
            ), 0) AS fatura_parcial_mes,
            COALESCE((
                SELECT CASE
                    WHEN fc.status = 'pendente'
                     AND COALESCE(fc.valor_previsto, 0) > 0
                     AND fc.vencimento < CURDATE()
                    THEN 1
                    ELSE 0
                END
                FROM financeiro_contas fc
                WHERE fc.usuario_id = c.usuario_id
                  AND fc.cartao_id = c.id
                  AND fc.competencia_cartao = ?
                ORDER BY fc.id DESC
                LIMIT 1
            ), 0) AS fatura_atrasada_mes,
            COALESCE((
                SELECT CASE
                    WHEN COUNT(*) > 0
                    THEN 1
                    ELSE 0
                END
                FROM financeiro_cartao_lancamentos fl
                WHERE fl.usuario_id = c.usuario_id
                  AND fl.cartao_id = c.id
                  AND (
                      (
                          COALESCE(fl.competencia_fatura, DATE_FORMAT(fl.data_compra, '%Y-%m-01')) >= ?
                          AND COALESCE(fl.competencia_fatura, DATE_FORMAT(fl.data_compra, '%Y-%m-01')) < ?
                      )
                      OR fl.descricao = ?
                  )
                  AND (
                      fl.descricao LIKE 'Fatura parcelada %'
                  )
            ), 0) AS fatura_parcelada_mes";
        $parametrosCartoes[] = $mes;
        $parametrosCartoes[] = $mes;
        $parametrosCartoes[] = $mes;
        $parametrosCartoes[] = $inicioMes;
        $parametrosCartoes[] = $fimMes;
        $parametrosCartoes[] = 'Fatura parcelada ' . date('m/Y', strtotime($inicioMes));
    }

    $parametrosCartoes[] = $usuarioId;
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COALESCE(SUM(CASE WHEN l.status = 'aberto' THEN l.valor ELSE 0 END), 0) AS total_aberto,
            COALESCE(SUM(
                CASE
                    WHEN l.status = 'aberto'
                     AND {$expressaoCompetenciaFaturaL} >= ?
                     AND {$expressaoCompetenciaFaturaL} < ?
                    THEN l.valor
                    ELSE 0
                END
            ), 0) AS fatura_mes
            {$selectFaturaPagaMes}
        FROM financeiro_cartoes c
        LEFT JOIN financeiro_cartao_lancamentos l
            ON l.cartao_id = c.id
           AND l.usuario_id = c.usuario_id
        WHERE c.usuario_id = ?
        GROUP BY c.id
        ORDER BY c.ativo DESC, c.tipo ASC, c.nome ASC
    ");
    $stmt->execute($parametrosCartoes);
    $cartoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cartoes as &$cartao) {
        $cartao['disponivel'] = (float)$cartao['limite_total'] - (float)$cartao['total_aberto'];

        if ((int)$cartao['ativo'] === 1) {
            $prefixo = $cartao['tipo'] === 'loja' ? 'loja' : 'credito';
            $resumo[$prefixo . '_limite'] += (float)$cartao['limite_total'];
            $resumo[$prefixo . '_disponivel'] += (float)$cartao['disponivel'];
        }

        if ((int)$cartao['id'] === $cartaoSelecionadoId) {
            $cartaoSelecionado = $cartao;
        }
    }
    unset($cartao);

    $cartoesVisiveis = array_values(array_filter(
        $cartoes,
        static fn(array $cartao): bool => $mostrarCartoesLoja || $cartao['tipo'] !== 'loja'
    ));

    if (!$mostrarCartoesLoja && $cartaoSelecionado && $cartaoSelecionado['tipo'] === 'loja') {
        $cartaoSelecionado = null;
        $cartaoSelecionadoId = 0;
    }

    if (!$cartaoSelecionado && $cartoesVisiveis !== []) {
        $cartaoSelecionado = $cartoesVisiveis[0];
        $cartaoSelecionadoId = (int)$cartaoSelecionado['id'];
    }

    if ($cartaoSelecionado) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM financeiro_cartao_lancamentos
            WHERE cartao_id = ?
              AND usuario_id = ?
              AND {$expressaoCompetenciaFatura} >= ?
              AND {$expressaoCompetenciaFatura} < ?
            ORDER BY status ASC, data_compra DESC, id DESC
        ");
        $stmt->execute([$cartaoSelecionadoId, $usuarioId, $inicioMes, $fimMes]);
        $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

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
$vencimentoFaturaSelecionada = null;
$diasParaVencimentoFatura = null;
$faturaAtrasada = false;
$textoPrazoFatura = 'Dia de vencimento não informado';

if ($cartaoSelecionado && !empty($cartaoSelecionado['dia_vencimento'])) {
    $vencimentoFaturaSelecionada = financeiroVencimentoFatura(
        $mes,
        (int)$cartaoSelecionado['dia_vencimento']
    );
    $diasParaVencimentoFatura = (int)(new DateTimeImmutable('today'))
        ->diff(new DateTimeImmutable($vencimentoFaturaSelecionada))
        ->format('%r%a');
    $faturaAtrasada = (float)$cartaoSelecionado['fatura_mes'] > 0
        && $diasParaVencimentoFatura < 0;

    if ((float)$cartaoSelecionado['fatura_mes'] <= 0) {
        $textoPrazoFatura = 'Sem valor em aberto';
    } elseif ($diasParaVencimentoFatura < 0) {
        $diasAtraso = abs($diasParaVencimentoFatura);
        $textoPrazoFatura = 'Vencida há ' . $diasAtraso . ($diasAtraso === 1 ? ' dia' : ' dias');
    } elseif ($diasParaVencimentoFatura === 0) {
        $textoPrazoFatura = 'Vence hoje';
    } else {
        $textoPrazoFatura = ($diasParaVencimentoFatura === 1 ? 'Falta ' : 'Faltam ')
            . $diasParaVencimentoFatura
            . ($diasParaVencimentoFatura === 1 ? ' dia' : ' dias');
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Cartões de crédito</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/financeiro.css') ?>">
</head>

<body class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="financeiro-cabecalho mb-4">
                <div>
                    <h3 class="mb-1">Cartões de crédito</h3>
                    <p class="text-muted mb-0">Compras lançadas reduzem o limite disponível automaticamente</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalPreferenciasCartoes">
                        <i class="bi bi-sliders"></i> Preferências
                    </button>
                    <a href="financeiro_categorias.php" class="btn btn-outline-primary">
                        <i class="bi bi-tags"></i> Categorias
                    </a>
                    <a href="financeiro_relatorio.php?mes=<?= htmlspecialchars($mes) ?>" class="btn btn-outline-success">
                        <i class="bi bi-bar-chart"></i> Relatório
                    </a>
                    <a href="financeiro.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar ao financeiro
                    </a>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if ($tabelasDisponiveis && !$categoriasDisponiveis): ?>
                <div class="alert alert-warning">
                    Execute o SQL das categorias financeiras para classificar compras e acessar os relatórios.
                </div>
            <?php endif; ?>

            <?php if ($tabelasDisponiveis && !$preferenciasDisponiveis): ?>
                <div class="alert alert-warning">
                    Execute o SQL das preferências financeiras para personalizar a exibição dos cartões.
                </div>
            <?php endif; ?>

            <?php if (!$tabelasDisponiveis): ?>
                <div class="alert alert-warning">
                    <strong>Banco ainda não preparado.</strong>
                    Execute o SQL do financeiro no phpMyAdmin e atualize esta página.
                </div>
            <?php else: ?>
                <?php
                $financeiroAlertasContexto = 'cartoes';
                include 'includes/financeiro_alertas.php';
                ?>

                <section class="financeiro-resumo financeiro-resumo-cartoes <?= $mostrarCartoesLoja ? '' : 'financeiro-resumo-cartoes-compacto' ?> mb-4" aria-label="Resumo dos cartões">
                    <div class="financeiro-metrica metrica-cartao">
                        <span>Limite cartões</span>
                        <strong><?= financeiroMoeda($resumo['credito_limite']) ?></strong>
                    </div>
                    <div class="financeiro-metrica <?= $resumo['credito_disponivel'] < 0 ? 'metrica-negativa' : 'metrica-saldo' ?>">
                        <span>Disponível cartões</span>
                        <strong><?= financeiroMoeda($resumo['credito_disponivel']) ?></strong>
                    </div>
                    <?php if ($mostrarCartoesLoja): ?>
                        <div class="financeiro-metrica metrica-loja">
                            <span>Limite lojas</span>
                            <strong><?= financeiroMoeda($resumo['loja_limite']) ?></strong>
                        </div>
                        <div class="financeiro-metrica <?= $resumo['loja_disponivel'] < 0 ? 'metrica-negativa' : 'metrica-pendente' ?>">
                            <span>Disponível lojas</span>
                            <strong><?= financeiroMoeda($resumo['loja_disponivel']) ?></strong>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="financeiro-filtros mb-4">
                    <span class="financeiro-mes-titulo">Fatura de <?= htmlspecialchars($nomeMes) ?></span>

                    <div class="financeiro-navegacao-mes">
                        <a
                            href="<?= htmlspecialchars(urlCartoes($cartaoSelecionadoId, $mesAnterior)) ?>"
                            class="btn btn-outline-secondary"
                            title="Fatura anterior"
                            aria-label="Fatura anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <form method="get" id="formMesCartao">
                            <?php if ($cartaoSelecionadoId > 0): ?>
                                <input type="hidden" name="cartao" value="<?= $cartaoSelecionadoId ?>">
                            <?php endif; ?>
                            <label for="mesCartao" class="visually-hidden">Escolher mês da fatura</label>
                            <input
                                type="month"
                                class="form-control financeiro-calendario"
                                name="mes"
                                id="mesCartao"
                                value="<?= htmlspecialchars($mes) ?>"
                                title="Escolher outro mês">
                        </form>

                        <a
                            href="<?= htmlspecialchars(urlCartoes($cartaoSelecionadoId, date('Y-m'))) ?>"
                            class="btn btn-outline-primary"
                            title="Voltar para a fatura atual"
                            aria-label="Voltar para a fatura atual">
                            <i class="bi bi-calendar-check"></i>
                        </a>

                        <a
                            href="<?= htmlspecialchars(urlCartoes($cartaoSelecionadoId, $proximoMes)) ?>"
                            class="btn btn-outline-secondary"
                            title="Próxima fatura"
                            aria-label="Próxima fatura">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <div class="financeiro-cartoes-layout">
                    <aside class="financeiro-painel financeiro-lista-cartoes">
                        <div class="financeiro-painel-titulo">
                            <div>
                                <h5 class="mb-1">Meus cartões</h5>
                                <p class="text-muted small mb-0"><?= count($cartoesVisiveis) ?> cadastrados</p>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="btnNovoCartao" data-bs-toggle="modal" data-bs-target="#modalCartao">
                                <i class="bi bi-plus-lg"></i> Novo
                            </button>
                        </div>

                        <div class="financeiro-cartoes">
                            <?php if ($cartoesVisiveis === []): ?>
                                <div class="financeiro-vazio">Cadastre seu primeiro cartão.</div>
                            <?php endif; ?>

                            <?php foreach ($cartoesVisiveis as $cartao): ?>
                                <?php
                                $classeFaturaCartao = '';

                                if ((int)($cartao['fatura_atrasada_mes'] ?? 0) === 1) {
                                    $classeFaturaCartao = ' fatura-atrasada';
                                } elseif ((int)($cartao['fatura_parcelada_mes'] ?? 0) === 1) {
                                    $classeFaturaCartao = ' fatura-parcelada';
                                } elseif ((int)($cartao['fatura_parcial_mes'] ?? 0) === 1) {
                                    $classeFaturaCartao = ' fatura-parcial';
                                } elseif ((int)($cartao['fatura_paga_mes'] ?? 0) === 1) {
                                    $classeFaturaCartao = ' fatura-paga';
                                } elseif ((float)($cartao['fatura_mes'] ?? 0) <= 0) {
                                    $classeFaturaCartao = ' fatura-paga';
                                }
                                ?>
                                <a
                                    href="<?= htmlspecialchars(urlCartoes((int)$cartao['id'], $mes)) ?>"
                                    class="financeiro-cartao-item<?= (int)$cartao['id'] === $cartaoSelecionadoId ? ' ativo' : '' ?><?= (int)$cartao['ativo'] !== 1 ? ' cancelado' : '' ?><?= $classeFaturaCartao ?>">
                                    <span class="financeiro-cartao-icone">
                                        <i class="bi <?= $cartao['tipo'] === 'loja' ? 'bi-shop' : 'bi-credit-card' ?>"></i>
                                    </span>
                                    <span class="financeiro-cartao-dados">
                                        <strong><?= htmlspecialchars($cartao['nome']) ?></strong>
                                        <?php if ((int)$cartao['ativo'] === 1): ?>
                                            <small class="<?= (float)$cartao['disponivel'] < 0 ? 'text-danger fw-semibold' : '' ?>">
                                                <?= financeiroMoeda((float)$cartao['disponivel']) ?> disponível
                                            </small>
                                        <?php else: ?>
                                            <small>Faturas mantidas para controle</small>
                                        <?php endif; ?>
                                    </span>
                                    <span class="badge <?= (int)$cartao['ativo'] !== 1 ? 'bg-secondary' : ($cartao['tipo'] === 'loja' ? 'bg-warning text-dark' : 'bg-primary') ?>">
                                        <?= (int)$cartao['ativo'] !== 1 ? 'Cancelado' : ($cartao['tipo'] === 'loja' ? 'Loja' : 'Crédito') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </aside>

                    <section class="financeiro-painel">
                        <?php if (!$cartaoSelecionado): ?>
                            <div class="financeiro-vazio py-5">
                                Cadastre um cartão para começar a lançar suas compras.
                            </div>
                        <?php else: ?>
                            <div class="financeiro-painel-titulo financeiro-cartao-cabecalho">
                                <div>
                                    <h5 class="mb-1">
                                        <?= htmlspecialchars($cartaoSelecionado['nome']) ?>
                                        <?php if ((int)$cartaoSelecionado['ativo'] !== 1): ?>
                                            <span class="badge bg-secondary ms-1">Cancelado</span>
                                        <?php endif; ?>
                                    </h5>
                                    <p class="text-muted small mb-0">
                                        Limite <?= financeiroMoeda((float)$cartaoSelecionado['limite_total']) ?>
                                        <?php if ($vencimentoFaturaSelecionada): ?>
                                            · fatura de <?= htmlspecialchars($nomeMes) ?>
                                            vence em <?= financeiroData($vencimentoFaturaSelecionada) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a
                                        href="financeiro_fatura_imprimir.php?<?= http_build_query(['cartao' => $cartaoSelecionadoId, 'mes' => $mes]) ?>"
                                        id="btnImprimirFatura"
                                        class="btn btn-outline-secondary btn-sm"
                                        title="Imprimir fatura">
                                        <i class="bi bi-printer"></i> Imprimir
                                    </a>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm btn-editar-cartao"
                                        data-id="<?= (int)$cartaoSelecionado['id'] ?>"
                                        data-nome="<?= htmlspecialchars($cartaoSelecionado['nome']) ?>"
                                        data-limite="<?= number_format((float)$cartaoSelecionado['limite_total'], 2, ',', '.') ?>"
                                        data-tipo="<?= htmlspecialchars($cartaoSelecionado['tipo']) ?>"
                                        data-vencimento="<?= (int)($cartaoSelecionado['dia_vencimento'] ?? 0) ?>"
                                        data-ativo="<?= (int)$cartaoSelecionado['ativo'] ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalCartao">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm btn-excluir-cartao"
                                        data-id="<?= (int)$cartaoSelecionado['id'] ?>"
                                        data-nome="<?= htmlspecialchars($cartaoSelecionado['nome']) ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalExcluirCartao">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm btn-pagar-fatura"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalPagarFatura"
                                        data-cartao-id="<?= (int)$cartaoSelecionado['id'] ?>"
                                        data-mes-fatura="<?= htmlspecialchars($mes) ?>"
                                        data-descricao="Fatura <?= htmlspecialchars($cartaoSelecionado['nome']) ?>"
                                        data-valor="<?= number_format((float)$cartaoSelecionado['fatura_mes'], 2, ',', '.') ?>"
                                        <?= (float)$cartaoSelecionado['fatura_mes'] <= 0 ? 'disabled' : '' ?>>
                                        <i class="bi bi-check-lg"></i> Pagar fatura
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm"
                                        id="btnNovaCompra"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalCompra"
                                        <?= (int)$cartaoSelecionado['ativo'] !== 1 ? 'disabled title="Reative o cartão para lançar novas compras"' : '' ?>>
                                        <i class="bi bi-plus-lg"></i> Nova compra
                                    </button>
                                </div>
                            </div>

                            <div class="financeiro-limite-barra mb-4">
                                <?php
                                $percentualUsado = (float)$cartaoSelecionado['limite_total'] > 0
                                    ? min(100, ((float)$cartaoSelecionado['total_aberto'] / (float)$cartaoSelecionado['limite_total']) * 100)
                                    : 0;
                                ?>
                                <div class="d-flex flex-wrap justify-content-between gap-3 mb-2">
                                    <span>Fatura de <?= htmlspecialchars($nomeMes) ?>: <strong><?= financeiroMoeda((float)$cartaoSelecionado['fatura_mes']) ?></strong></span>
                                    <span>
                                        Vencimento:
                                        <strong><?= $vencimentoFaturaSelecionada ? financeiroData($vencimentoFaturaSelecionada) : '-' ?></strong>
                                    </span>
                                    <span class="<?= $faturaAtrasada ? 'text-danger' : '' ?>">
                                        Prazo: <strong><?= htmlspecialchars($textoPrazoFatura) ?></strong>
                                    </span>
                                    <span>Compras comprometidas: <strong><?= financeiroMoeda((float)$cartaoSelecionado['total_aberto']) ?></strong></span>
                                    <span class="<?= (float)$cartaoSelecionado['disponivel'] < 0 ? 'text-danger' : '' ?>">
                                        Disponível:
                                        <strong><?= financeiroMoeda((float)$cartaoSelecionado['disponivel']) ?></strong>
                                    </span>
                                </div>
                                <div class="progress" role="progressbar" aria-valuenow="<?= round($percentualUsado) ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar <?= $percentualUsado >= 85 ? 'bg-danger' : ($percentualUsado >= 60 ? 'bg-warning' : 'bg-primary') ?>" style="width: <?= $percentualUsado ?>%"></div>
                                </div>
                            </div>

                            <div class="financeiro-lista-filtros">
                                <div class="financeiro-campo-busca">
                                    <i class="bi bi-search"></i>
                                    <input
                                        type="search"
                                        class="form-control"
                                        id="filtroBuscaCompras"
                                        placeholder="Buscar compra..."
                                        aria-label="Buscar compra do cartão">
                                </div>
                                <select class="form-select" id="filtroStatusCompras" aria-label="Filtrar compras por situação">
                                    <option value="">Todas as situações</option>
                                    <option value="aberto">Em aberto</option>
                                    <option value="atrasado">Atrasado</option>
                                    <option value="pago">Pago</option>
                                </select>
                                <select class="form-select" id="filtroCategoriaCompras" aria-label="Filtrar compras por categoria">
                                    <option value="">Todas as categorias</option>
                                    <option value="sem_categoria">Sem categoria</option>
                                    <?php foreach ($todasCategoriasDespesa as $categoria): ?>
                                        <option value="<?= (int)$categoria['id'] ?>">
                                            <?= htmlspecialchars($categoria['nome']) ?><?= (int)$categoria['ativa'] === 1 ? '' : ' (desativada)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle financeiro-tabela" id="tabelaComprasCartao">
                                    <thead>
                                        <tr>
                                            <th>Data da compra</th>
                                            <th>Compra</th>
                                            <th class="text-end">Valor</th>
                                            <th>Status</th>
                                            <th>Pagamento</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($lancamentos === []): ?>
                                            <tr>
                                                <td colspan="6" class="financeiro-vazio">Nenhuma compra nesta fatura.</td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($lancamentos as $lancamento):
                                            $textoCompra = $lancamento['descricao'];
                                            $lancamentoAtrasado = $lancamento['status'] === 'aberto' && $faturaAtrasada;
                                            $mesFaturaLancamento = !empty($lancamento['competencia_fatura'])
                                                ? date('Y-m', strtotime($lancamento['competencia_fatura']))
                                                : date('Y-m', strtotime($lancamento['data_compra']));

                                            if (!empty($lancamento['parcela_numero']) && !empty($lancamento['parcelas_total'])) {
                                                $textoCompra .= ' ' . (int)$lancamento['parcela_numero'] . '/' . (int)$lancamento['parcelas_total'];
                                            }
                                            $statusFiltroLancamento = $lancamento['status'] === 'pago'
                                                ? 'pago'
                                                : ($lancamentoAtrasado ? 'atrasado' : 'aberto');
                                            $categoriaLancamento = $categoriasPorId[(int)($lancamento['categoria_id'] ?? 0)] ?? null;
                                        ?>
                                            <tr
                                                class="linha-filtro-compra"
                                                data-filtro="<?= htmlspecialchars($textoCompra . ' ' . ($categoriaLancamento['nome'] ?? 'Sem categoria')) ?>"
                                                data-status="<?= $statusFiltroLancamento ?>"
                                                data-categoria="<?= $categoriaLancamento ? (int)$categoriaLancamento['id'] : 'sem_categoria' ?>">
                                                <td><?= financeiroData($lancamento['data_compra']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($textoCompra) ?>
                                                    <?php if (!empty($lancamento['grupo_parcelamento'])): ?>
                                                        <span class="badge bg-light text-dark border ms-1">Parcelada</span>
                                                    <?php endif; ?>
                                                    <span
                                                        class="badge ms-1 <?= $categoriaLancamento ? 'text-white' : 'bg-secondary' ?>"
                                                        <?= $categoriaLancamento ? 'style="background-color:' . htmlspecialchars($categoriaLancamento['cor']) . '"' : '' ?>>
                                                        <?= htmlspecialchars($categoriaLancamento['nome'] ?? 'Sem categoria') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end fw-semibold"><?= financeiroMoeda((float)$lancamento['valor']) ?></td>
                                                <td>
                                                    <span class="badge <?= $lancamento['status'] === 'aberto' ? ($lancamentoAtrasado ? 'bg-danger' : 'bg-warning text-dark') : 'bg-success' ?>">
                                                        <?= $lancamento['status'] === 'aberto' ? ($lancamentoAtrasado ? 'Atrasado' : 'Em aberto') : 'Pago' ?>
                                                    </span>
                                                </td>
                                                <td><?= financeiroData($lancamento['data_pagamento']) ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <?php if ($lancamento['status'] === 'aberto'): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-primary btn-sm btn-editar-compra"
                                                                data-id="<?= (int)$lancamento['id'] ?>"
                                                                data-cartao="<?= (int)$lancamento['cartao_id'] ?>"
                                                                data-data="<?= htmlspecialchars($lancamento['data_compra']) ?>"
                                                                data-fatura="<?= htmlspecialchars($mesFaturaLancamento) ?>"
                                                                data-descricao="<?= htmlspecialchars($lancamento['descricao']) ?>"
                                                                data-valor="<?= number_format((float)$lancamento['valor'], 2, ',', '.') ?>"
                                                                data-categoria="<?= (int)($lancamento['categoria_id'] ?? 0) ?>"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modalCompra"
                                                                title="Editar compra">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                                                                <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                                                                <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                                                <input type="hidden" name="acao" value="reabrir_lancamento">
                                                                <input type="hidden" name="id" value="<?= (int)$lancamento['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Reabrir compra">
                                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-secondary btn-sm btn-categorizar-compra"
                                                            data-id="<?= (int)$lancamento['id'] ?>"
                                                            data-descricao="<?= htmlspecialchars($textoCompra) ?>"
                                                            data-categoria="<?= (int)($lancamento['categoria_id'] ?? 0) ?>"
                                                            data-parcelada="<?= !empty($lancamento['grupo_parcelamento']) ? '1' : '0' ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalCategoriaCompra"
                                                            title="Alterar categoria">
                                                            <i class="bi bi-tag"></i>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-danger btn-sm btn-excluir-lancamento"
                                                            data-id="<?= (int)$lancamento['id'] ?>"
                                                            data-descricao="<?= htmlspecialchars($textoCompra) ?>"
                                                            data-parcelada="<?= !empty($lancamento['grupo_parcelamento']) ? '1' : '0' ?>"
                                                            data-total-parcelas="<?= (int)($lancamento['parcelas_total'] ?? 1) ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalExcluirLancamento"
                                                            title="Excluir compra">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="d-none" id="semResultadoCompras">
                                            <td colspan="6" class="financeiro-vazio">Nenhuma compra corresponde aos filtros.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($tabelasDisponiveis): ?>
        <div class="modal fade" id="modalPreferenciasCartoes" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_preferencias">
                        <div class="modal-header">
                            <h5 class="modal-title">Preferências dos cartões</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    name="mostrar_cartoes_loja"
                                    id="mostrarCartoesLoja"
                                    <?= $mostrarCartoesLoja ? 'checked' : '' ?>
                                    <?= $preferenciasDisponiveis ? '' : 'disabled' ?>>
                                <label class="form-check-label" for="mostrarCartoesLoja">
                                    Mostrar cartões de lojas específicas
                                </label>
                            </div>
                            <p class="text-muted small mb-0 mt-2">
                                Desligando esta opção, os cartões de loja ficam ocultos do resumo, da lista e do cadastro.
                            </p>
                            <?php if (!$preferenciasDisponiveis): ?>
                                <div class="alert alert-warning mt-3 mb-0">
                                    Execute o SQL das preferências financeiras antes de salvar.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" <?= $preferenciasDisponiveis ? '' : 'disabled' ?>>Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalCartao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_cartao">
                        <input type="hidden" name="id" id="cartaoId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalCartao">Novo cartão</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="cartaoNome" class="form-label">Nome do cartão</label>
                                <input type="text" class="form-control" name="nome" id="cartaoNome" required>
                                <div class="invalid-feedback">Informe o nome.</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoLimite" class="form-label">Limite total</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="limite_total" id="cartaoLimite" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o limite.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoTipo" class="form-label">Tipo</label>
                                    <select class="form-select" name="tipo" id="cartaoTipo">
                                        <option value="credito">Cartão de crédito</option>
                                        <?php if ($mostrarCartoesLoja): ?>
                                            <option value="loja">Loja específica</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoVencimento" class="form-label">Dia do vencimento</label>
                                    <input type="number" min="1" max="31" class="form-control" name="dia_vencimento" id="cartaoVencimento" required>
                                    <div class="invalid-feedback">Informe um dia entre 1 e 31.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="cartaoAtivo" class="form-label">Situação</label>
                                    <select class="form-select" name="ativo" id="cartaoAtivo">
                                        <option value="1">Ativo</option>
                                        <option value="0">Cancelado</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalCompra" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="salvar_lancamento">
                        <input type="hidden" name="id" id="compraId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalCompra">Nova compra</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3" id="grupoTipoCompra">
                                <label for="compraTipo" class="form-label">Tipo de compra</label>
                                <select class="form-select" name="tipo_compra" id="compraTipo">
                                    <option value="unica">À vista</option>
                                    <option value="parcelada">Parcelada</option>
                                    <option value="recorrente">Recorrente</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="compraCartao" class="form-label">Cartão</label>
                                <select class="form-select" name="cartao_id" id="compraCartao" required>
                                    <?php foreach ($cartoes as $cartao): ?>
                                        <option value="<?= (int)$cartao['id'] ?>" <?= (int)$cartao['id'] === $cartaoSelecionadoId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cartao['nome']) ?>
                                            <?= (int)$cartao['ativo'] === 1
                                                ? ' · ' . financeiroMoeda((float)$cartao['disponivel']) . ' disponível'
                                                : ' · Cancelado' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="compraData" class="form-label">Data da compra</label>
                                    <input type="date" class="form-control financeiro-calendario" name="data_compra" id="compraData" value="<?= date('Y-m-d') ?>" required>
                                    <div class="invalid-feedback">Informe a data.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="compraMesFatura" class="form-label" id="compraMesFaturaLabel">Primeira fatura</label>
                                    <input
                                        type="month"
                                        class="form-control financeiro-calendario"
                                        name="mes_fatura_compra"
                                        id="compraMesFatura"
                                        value="<?= htmlspecialchars($mes) ?>"
                                        required>
                                    <div class="invalid-feedback">Informe o mês da fatura.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="compraValor" class="form-label" id="compraValorLabel">Valor da compra</label>
                                    <input type="text" inputmode="decimal" class="form-control campo-moeda" name="valor" id="compraValor" placeholder="0,00" required>
                                    <div class="invalid-feedback">Informe o valor.</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="compraDescricao" class="form-label">Descrição</label>
                                <input type="text" class="form-control" name="descricao" id="compraDescricao" required>
                                <div class="invalid-feedback">Informe a descrição.</div>
                            </div>
                            <div class="mb-3">
                                <label for="compraCategoria" class="form-label">Categoria</label>
                                <select class="form-select" name="categoria_id" id="compraCategoria" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($categoriasDespesa as $categoria): ?>
                                        <option value="<?= (int)$categoria['id'] ?>">
                                            <?= htmlspecialchars($categoria['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione a categoria.</div>
                            </div>
                            <div class="mb-3 d-none" id="campoParcelasCompra">
                                <label for="compraParcelasTotal" class="form-label">Quantidade de parcelas</label>
                                <input type="number" min="2" max="600" class="form-control" name="parcelas_total" id="compraParcelasTotal">
                                <div class="invalid-feedback">Informe a quantidade de parcelas.</div>
                                <small class="text-muted">
                                    O valor total será dividido e o limite será comprometido pela compra inteira.
                                </small>
                            </div>
                            <div class="alert alert-info d-none" id="avisoCompraRecorrente">
                                Assinaturas e anuidades entram somente na fatura escolhida. Não comprometem meses futuros automaticamente.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Lançar compra</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalCategoriaCompra" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" class="financeiro-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                        <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                        <input type="hidden" name="acao" value="categorizar_lancamento">
                        <input type="hidden" name="id" id="categoriaCompraId">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Alterar categoria</h5>
                                <p class="text-muted small mb-0" id="categoriaCompraDescricao"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="categoriaCompraSelect" class="form-label">Categoria da despesa</label>
                                <select class="form-select" name="categoria_id" id="categoriaCompraSelect" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($categoriasDespesa as $categoria): ?>
                                        <option value="<?= (int)$categoria['id'] ?>">
                                            <?= htmlspecialchars($categoria['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione a categoria.</div>
                            </div>
                            <div class="alert alert-info mb-0 d-none" id="avisoCategoriaParcelada">
                                A nova categoria será aplicada a todas as parcelas desta compra.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar categoria</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($cartaoSelecionado): ?>
            <div class="modal fade" id="modalPagarFatura" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" class="financeiro-form" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" id="pagarFaturaCartaoRetorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="pagar_fatura">
                            <input type="hidden" name="cartao_id" id="pagarFaturaCartaoId" value="<?= $cartaoSelecionadoId ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Pagar fatura</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <p>
                                    Pagar <strong id="pagarFaturaDescricao"><?= htmlspecialchars('Fatura ' . $cartaoSelecionado['nome']) ?></strong>
                                    de <strong id="pagarFaturaMesTexto"><?= htmlspecialchars($nomeMes) ?></strong>.
                                </p>
                                <div class="alert alert-info py-2">
                                    Valor da fatura:
                                    <strong id="pagarFaturaValorTexto"><?= financeiroMoeda((float)$cartaoSelecionado['fatura_mes']) ?></strong>.
                                    Se informar um valor menor, o restante poderá ir para a próxima fatura ou ser parcelado.
                                </div>
                                <input type="hidden" name="mes_fatura" id="pagarFaturaMes" value="<?= htmlspecialchars($mes) ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="valorPagamentoFatura" class="form-label">Valor pago</label>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            class="form-control campo-moeda"
                                            name="valor_pago"
                                            id="valorPagamentoFatura"
                                            value="<?= number_format((float)$cartaoSelecionado['fatura_mes'], 2, ',', '.') ?>"
                                            required>
                                        <div class="invalid-feedback">Informe o valor pago.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="dataPagamentoFatura" class="form-label">Data do pagamento</label>
                                        <input type="date" class="form-control financeiro-calendario" name="data_pagamento" id="dataPagamentoFatura" value="<?= date('Y-m-d') ?>" required>
                                        <div class="invalid-feedback">Informe a data do pagamento.</div>
                                    </div>
                                </div>
                                <div class="border rounded-3 p-3 d-none" id="blocoParcelarFatura">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" role="switch" name="parcelar_fatura" value="1" id="parcelarFatura">
                                        <label class="form-check-label" for="parcelarFatura">
                                            Parcelar saldo restante da fatura
                                        </label>
                                    </div>
                                    <div class="row d-none" id="camposParcelarFatura">
                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <label for="parcelasFatura" class="form-label">Quantidade de parcelas</label>
                                            <input
                                                type="number"
                                                class="form-control"
                                                name="parcelas_fatura"
                                                id="parcelasFatura"
                                                min="2"
                                                max="48"
                                                value="2">
                                            <div class="invalid-feedback">Informe pelo menos 2 parcelas.</div>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <div class="text-muted small" id="resumoParcelamentoFatura">
                                                O saldo restante será dividido nas próximas faturas.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg"></i> Confirmar pagamento
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="modalExcluirCartao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Excluir cartão</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="nomeCartaoExcluir"></strong>?</p>
                        <small class="text-danger">Todas as compras desse cartão também serão apagadas.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="excluir_cartao">
                            <input type="hidden" name="id" id="cartaoExcluirId">
                            <button type="submit" class="btn btn-danger">Sim, excluir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirLancamento" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="tituloExcluirLancamento">Excluir compra</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Tem certeza que deseja excluir <strong id="descricaoLancamentoExcluir"></strong>?</p>
                        <small class="text-danger" id="avisoExcluirLancamento">O limite do cartão será recalculado.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Não</button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeiroToken()) ?>">
                            <input type="hidden" name="cartao_retorno" value="<?= $cartaoSelecionadoId ?>">
                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                            <input type="hidden" name="acao" value="excluir_lancamento">
                            <input type="hidden" name="id" id="lancamentoExcluirId">
                            <button type="submit" class="btn btn-danger">Sim, excluir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= assetUrl('assets/financeiro.js') ?>"></script>
    <?php if ($tabelasDisponiveis): ?>
        <script>
            const cartaoSelecionado = <?= json_encode($cartaoSelecionadoId) ?>;
            const dataPadraoCompra = <?= json_encode($dataPadraoCompra) ?>;
            const mesFaturaSelecionado = <?= json_encode($mes) ?>;

            function normalizarTextoFiltro(texto) {
                return String(texto || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim();
            }

            function nomeMesFatura(mes) {
                const nomes = [
                    'Janeiro',
                    'Fevereiro',
                    'Março',
                    'Abril',
                    'Maio',
                    'Junho',
                    'Julho',
                    'Agosto',
                    'Setembro',
                    'Outubro',
                    'Novembro',
                    'Dezembro'
                ];
                const partes = String(mes || '').split('-');
                const indiceMes = Number(partes[1]) - 1;

                if (partes.length !== 2 || !nomes[indiceMes]) {
                    return mes || '';
                }

                return nomes[indiceMes] + '/' + partes[0];
            }

            document.querySelectorAll('.btn-pagar-fatura').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    const cartaoId = this.dataset.cartaoId || String(cartaoSelecionado);
                    const mesFatura = this.dataset.mesFatura || mesFaturaSelecionado;
                    const descricao = this.dataset.descricao || 'Fatura';
                    const valor = this.dataset.valor || '0,00';

                    document.getElementById('pagarFaturaCartaoRetorno').value = cartaoId;
                    document.getElementById('pagarFaturaCartaoId').value = cartaoId;
                    document.getElementById('pagarFaturaMes').value = mesFatura;
                    document.getElementById('pagarFaturaDescricao').textContent = descricao;
                    document.getElementById('pagarFaturaMesTexto').textContent = nomeMesFatura(mesFatura);
                    document.getElementById('pagarFaturaValorTexto').textContent = 'R$ ' + valor;
                    document.getElementById('valorPagamentoFatura').value = valor;
                    document.getElementById('dataPagamentoFatura').value = dataPadraoCompra;
                    const parcelarFatura = document.getElementById('parcelarFatura');
                    if (parcelarFatura) {
                        parcelarFatura.checked = false;
                        atualizarCamposParcelamentoFatura();
                    }
                });
            });

            function valorMoedaParaNumero(valor) {
                const limpo = String(valor || '')
                    .replace(/[R$\s]/g, '')
                    .replace(/\./g, '')
                    .replace(',', '.');
                const numero = Number(limpo);

                return Number.isFinite(numero) ? numero : 0;
            }

            function numeroParaMoeda(valor) {
                return valor.toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });
            }

            function atualizarCamposParcelamentoFatura() {
                const parcelarFatura = document.getElementById('parcelarFatura');
                const blocoParcelarFatura = document.getElementById('blocoParcelarFatura');
                const camposParcelarFatura = document.getElementById('camposParcelarFatura');
                const parcelasFatura = document.getElementById('parcelasFatura');
                const resumo = document.getElementById('resumoParcelamentoFatura');
                const valorTotal = valorMoedaParaNumero((document.getElementById('pagarFaturaValorTexto')?.textContent || '').replace('R$', ''));
                const valorPago = valorMoedaParaNumero(document.getElementById('valorPagamentoFatura')?.value);
                const parcelas = Math.max(2, Number(parcelasFatura?.value || 2));
                const restante = Math.max(0, valorTotal - valorPago);
                const podeParcelar = restante > 0.009;

                if (!parcelarFatura || !camposParcelarFatura || !blocoParcelarFatura) {
                    return;
                }

                blocoParcelarFatura.classList.toggle('d-none', !podeParcelar);

                if (!podeParcelar) {
                    parcelarFatura.checked = false;
                }

                camposParcelarFatura.classList.toggle('d-none', !podeParcelar || !parcelarFatura.checked);

                if (parcelasFatura) {
                    parcelasFatura.required = podeParcelar && parcelarFatura.checked;
                }

                if (resumo) {
                    resumo.textContent = restante > 0 ?
                        'Saldo de ' + numeroParaMoeda(restante) + ' em ' + parcelas + 'x de aproximadamente ' + numeroParaMoeda(restante / parcelas) + '.' :
                        'Informe um valor menor que a fatura para gerar parcelas futuras.';
                }
            }

            document.getElementById('parcelarFatura')?.addEventListener('change', atualizarCamposParcelamentoFatura);
            document.getElementById('parcelasFatura')?.addEventListener('input', atualizarCamposParcelamentoFatura);
            document.getElementById('valorPagamentoFatura')?.addEventListener('input', atualizarCamposParcelamentoFatura);

            const filtroBuscaCompras = document.getElementById('filtroBuscaCompras');
            const filtroStatusCompras = document.getElementById('filtroStatusCompras');
            const filtroCategoriaCompras = document.getElementById('filtroCategoriaCompras');
            const btnImprimirFatura = document.getElementById('btnImprimirFatura');
            const linhasCompras = Array.from(document.querySelectorAll('.linha-filtro-compra'));
            const semResultadoCompras = document.getElementById('semResultadoCompras');

            function atualizarLinkImpressaoFatura() {
                if (!btnImprimirFatura) {
                    return;
                }

                const url = new URL(btnImprimirFatura.href, window.location.href);
                const busca = filtroBuscaCompras?.value.trim() || '';
                const status = filtroStatusCompras?.value || '';
                const categoria = filtroCategoriaCompras?.value || '';

                if (busca) {
                    url.searchParams.set('busca', busca);
                } else {
                    url.searchParams.delete('busca');
                }

                if (status) {
                    url.searchParams.set('status', status);
                } else {
                    url.searchParams.delete('status');
                }

                if (categoria) {
                    url.searchParams.set('categoria', categoria);
                } else {
                    url.searchParams.delete('categoria');
                }

                btnImprimirFatura.href = url.toString();
            }

            function filtrarCompras() {
                if (!filtroBuscaCompras || !filtroStatusCompras || !filtroCategoriaCompras || !semResultadoCompras) {
                    return;
                }

                const busca = normalizarTextoFiltro(filtroBuscaCompras.value);
                const status = filtroStatusCompras.value;
                const categoria = filtroCategoriaCompras.value;
                let visiveis = 0;

                linhasCompras.forEach(function(linha) {
                    const correspondeBusca = normalizarTextoFiltro(linha.dataset.filtro).includes(busca);
                    const correspondeStatus = status === '' || linha.dataset.status === status;
                    const correspondeCategoria = categoria === '' || linha.dataset.categoria === categoria;
                    const mostrar = correspondeBusca && correspondeStatus && correspondeCategoria;

                    linha.classList.toggle('d-none', !mostrar);
                    visiveis += mostrar ? 1 : 0;
                });

                semResultadoCompras.classList.toggle('d-none', visiveis > 0);
                atualizarLinkImpressaoFatura();
            }

            filtroBuscaCompras?.addEventListener('input', filtrarCompras);
            filtroStatusCompras?.addEventListener('change', filtrarCompras);
            filtroCategoriaCompras?.addEventListener('change', filtrarCompras);
            atualizarLinkImpressaoFatura();

            document.getElementById('mesCartao').addEventListener('change', function() {
                document.getElementById('formMesCartao').submit();
            });

            document.getElementById('btnNovoCartao').addEventListener('click', function() {
                document.getElementById('tituloModalCartao').textContent = 'Novo cartão';
                document.getElementById('cartaoId').value = '';
                document.getElementById('cartaoNome').value = '';
                document.getElementById('cartaoLimite').value = '';
                document.getElementById('cartaoTipo').value = 'credito';
                document.getElementById('cartaoVencimento').value = '';
                document.getElementById('cartaoAtivo').value = '1';
            });

            document.querySelectorAll('.btn-editar-cartao').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalCartao').textContent = 'Editar cartão';
                    document.getElementById('cartaoId').value = this.dataset.id;
                    document.getElementById('cartaoNome').value = this.dataset.nome;
                    document.getElementById('cartaoLimite').value = this.dataset.limite;
                    document.getElementById('cartaoTipo').value = this.dataset.tipo;
                    document.getElementById('cartaoVencimento').value = this.dataset.vencimento || '';
                    document.getElementById('cartaoAtivo').value = this.dataset.ativo;
                });
            });

            document.querySelectorAll('.btn-excluir-cartao').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('cartaoExcluirId').value = this.dataset.id;
                    document.getElementById('nomeCartaoExcluir').textContent = this.dataset.nome;
                });
            });

            const botaoNovaCompra = document.getElementById('btnNovaCompra');

            if (botaoNovaCompra) {
                botaoNovaCompra.addEventListener('click', function() {
                    document.getElementById('tituloModalCompra').textContent = 'Nova compra';
                    document.getElementById('compraId').value = '';
                    document.getElementById('grupoTipoCompra').classList.remove('d-none');
                    document.getElementById('compraTipo').value = 'unica';
                    document.getElementById('compraCartao').value = String(cartaoSelecionado);
                    document.getElementById('compraData').value = dataPadraoCompra;
                    document.getElementById('compraMesFaturaLabel').textContent = 'Primeira fatura';
                    document.getElementById('compraMesFatura').value = mesFaturaSelecionado;
                    document.getElementById('compraDescricao').value = '';
                    document.getElementById('compraValor').value = '';
                    document.getElementById('compraCategoria').value = '';
                    document.getElementById('compraParcelasTotal').value = '';
                    atualizarCamposParcelamentoCompra();
                });
            }

            document.querySelectorAll('.btn-editar-compra').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('tituloModalCompra').textContent = 'Editar compra';
                    document.getElementById('compraId').value = this.dataset.id;
                    document.getElementById('grupoTipoCompra').classList.add('d-none');
                    document.getElementById('campoParcelasCompra').classList.add('d-none');
                    document.getElementById('avisoCompraRecorrente').classList.add('d-none');
                    document.getElementById('compraCartao').value = this.dataset.cartao;
                    document.getElementById('compraData').value = this.dataset.data;
                    document.getElementById('compraMesFaturaLabel').textContent = 'Fatura';
                    document.getElementById('compraMesFatura').value = this.dataset.fatura;
                    document.getElementById('compraDescricao').value = this.dataset.descricao;
                    document.getElementById('compraValor').value = this.dataset.valor;
                    document.getElementById('compraCategoria').value = this.dataset.categoria || '';
                });
            });

            document.querySelectorAll('.btn-categorizar-compra').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    document.getElementById('categoriaCompraId').value = this.dataset.id;
                    document.getElementById('categoriaCompraDescricao').textContent = this.dataset.descricao;
                    document.getElementById('categoriaCompraSelect').value = this.dataset.categoria || '';
                    document.getElementById('avisoCategoriaParcelada').classList.toggle(
                        'd-none',
                        this.dataset.parcelada !== '1'
                    );
                });
            });

            function atualizarCamposParcelamentoCompra() {
                const tipoCompra = document.getElementById('compraTipo').value;
                const parcelada = tipoCompra === 'parcelada';
                const recorrente = tipoCompra === 'recorrente';
                const campoParcelas = document.getElementById('campoParcelasCompra');
                const parcelasTotal = document.getElementById('compraParcelasTotal');
                const avisoRecorrente = document.getElementById('avisoCompraRecorrente');

                campoParcelas.classList.toggle('d-none', !parcelada);
                avisoRecorrente.classList.toggle('d-none', !recorrente);
                parcelasTotal.required = parcelada;
                document.getElementById('compraValorLabel').textContent = parcelada ?
                    'Valor total da compra' :
                    (recorrente ? 'Valor da cobrança' : 'Valor da compra');
                document.getElementById('compraMesFaturaLabel').textContent = recorrente ?
                    'Fatura da cobrança' :
                    'Primeira fatura';

                if (parcelada) {
                    parcelasTotal.focus();
                } else {
                    parcelasTotal.classList.remove('is-invalid');
                }
            }

            document.getElementById('compraTipo').addEventListener('change', atualizarCamposParcelamentoCompra);

            document.querySelectorAll('.btn-excluir-lancamento').forEach(function(botao) {
                botao.addEventListener('click', function() {
                    const parcelada = this.dataset.parcelada === '1';
                    document.getElementById('lancamentoExcluirId').value = this.dataset.id;
                    document.getElementById('descricaoLancamentoExcluir').textContent = this.dataset.descricao;
                    document.getElementById('tituloExcluirLancamento').textContent = parcelada ?
                        'Excluir compra parcelada' :
                        'Excluir compra';
                    document.getElementById('avisoExcluirLancamento').textContent = parcelada ?
                        'Todas as ' + this.dataset.totalParcelas + ' parcelas desta compra serão excluídas e o limite será recalculado.' :
                        'O limite do cartão será recalculado.';
                });
            });

            document.querySelectorAll('.financeiro-form').forEach(function(formulario) {
                formulario.addEventListener('submit', function(event) {
                    let primeiroInvalido = null;

                    formulario.querySelectorAll('[required]').forEach(function(campo) {
                        const invalido = campo.value.trim() === '';
                        campo.classList.toggle('is-invalid', invalido);
                        primeiroInvalido = primeiroInvalido || (invalido ? campo : null);
                    });

                    if (primeiroInvalido) {
                        event.preventDefault();
                        primeiroInvalido.focus();
                    }
                });
            });

            document.querySelectorAll('.financeiro-form input, .financeiro-form select').forEach(function(campo) {
                campo.addEventListener('input', function() {
                    this.classList.remove('is-invalid');
                });
            });

            setTimeout(function() {
                document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                    alerta.classList.remove('show');
                    setTimeout(function() {
                        alerta.remove();
                    }, 200);
                });
            }, 4000);
        </script>
    <?php endif; ?>
</body>

</html>