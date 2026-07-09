<?php
require 'config.php';
require 'includes/financeiro_funcoes.php';

exigirPermissao('financeiro');

header('Content-Type: application/json; charset=utf-8');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$termo = trim((string)($_GET['q'] ?? ''));
$resposta = ['grupos' => []];
$limitesBusca = [
    'recebimentos' => 8,
    'contas' => 8,
    'metas' => 8,
    'cartoes' => 10,
];
$paginasBusca = [];

foreach ($limitesBusca as $chaveBusca => $limiteBusca) {
    $paginasBusca[$chaveBusca] = max(1, (int)($_GET['pagina_' . $chaveBusca] ?? 1));
}

$tamanhoTermo = function_exists('mb_strlen')
    ? mb_strlen($termo, 'UTF-8')
    : strlen($termo);

if ($tamanhoTermo < 2) {
    echo json_encode($resposta);
    exit;
}

$like = '%' . $termo . '%';
$tabelasBaseDisponiveis = financeiroTabelasDisponiveis($pdo, ['financeiro_recebimentos', 'financeiro_contas']);

function financeiroBuscaAdicionarGrupo(
    array &$resposta,
    string $chave,
    string $titulo,
    array $itens,
    int $pagina,
    int $limite,
    int $total
): void {
    if ($itens !== [] || $pagina > 1) {
        $resposta['grupos'][] = [
            'chave' => $chave,
            'titulo' => $titulo,
            'itens' => $itens,
            'pagina' => $pagina,
            'limite' => $limite,
            'total' => $total,
            'temMais' => ($pagina * $limite) < $total,
        ];
    }
}

if ($tabelasBaseDisponiveis) {
    $pagina = $paginasBusca['recebimentos'];
    $limite = $limitesBusca['recebimentos'];
    $quantidade = $pagina * $limite;

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND (descricao LIKE ? OR recebido_de LIKE ?)
    ");
    $stmtTotal->execute([$usuarioId, $like, $like]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, descricao, recebido_de, valor, data_recebimento
        FROM financeiro_recebimentos
        WHERE usuario_id = ?
          AND (descricao LIKE ? OR recebido_de LIKE ?)
        ORDER BY data_recebimento DESC, id DESC
        LIMIT {$quantidade}
    ");
    $stmt->execute([$usuarioId, $like, $like]);
    $itens = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recebimento) {
        $mes = date('Y-m', strtotime($recebimento['data_recebimento']));
        $itens[] = [
            'titulo' => $recebimento['descricao'],
            'detalhe' => 'Recebido de ' . ($recebimento['recebido_de'] ?: '-') . ' em ' . financeiroData($recebimento['data_recebimento']),
            'valor' => financeiroMoeda((float)$recebimento['valor']),
            'url' => 'financeiro.php?mes=' . urlencode($mes),
        ];
    }

    financeiroBuscaAdicionarGrupo($resposta, 'recebimentos', 'Recebimentos', $itens, $pagina, $limite, $total);

    $pagina = $paginasBusca['contas'];
    $limite = $limitesBusca['contas'];
    $quantidade = $pagina * $limite;

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND descricao LIKE ?
    ");
    $stmtTotal->execute([$usuarioId, $like]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, descricao, valor_previsto, valor_pago, vencimento, data_pagamento, status
        FROM financeiro_contas
        WHERE usuario_id = ?
          AND descricao LIKE ?
        ORDER BY vencimento DESC, id DESC
        LIMIT {$quantidade}
    ");
    $stmt->execute([$usuarioId, $like]);
    $itens = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $conta) {
        $mes = date('Y-m', strtotime($conta['vencimento']));
        $valor = $conta['status'] === 'pago'
            ? (float)($conta['valor_pago'] ?? 0)
            : (float)$conta['valor_previsto'];
        $itens[] = [
            'titulo' => $conta['descricao'],
            'detalhe' => ucfirst($conta['status']) . ' | vencimento ' . financeiroData($conta['vencimento']),
            'valor' => financeiroMoeda($valor),
            'url' => 'financeiro.php?mes=' . urlencode($mes),
        ];
    }

    financeiroBuscaAdicionarGrupo($resposta, 'contas', 'Contas a pagar', $itens, $pagina, $limite, $total);
}

if (financeiroTabelasDisponiveis($pdo, ['financeiro_metas', 'financeiro_meta_movimentos'])) {
    $pagina = $paginasBusca['metas'];
    $limite = $limitesBusca['metas'];
    $quantidade = $pagina * $limite;

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_metas
        WHERE usuario_id = ?
          AND descricao LIKE ?
    ");
    $stmtTotal->execute([$usuarioId, $like]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.descricao,
            m.valor_alvo,
            m.prazo,
            m.status,
            COALESCE(SUM(CASE WHEN mm.tipo = 'deposito' THEN mm.valor ELSE -mm.valor END), 0) AS saldo_atual
        FROM financeiro_metas m
        LEFT JOIN financeiro_meta_movimentos mm
            ON mm.meta_id = m.id
           AND mm.usuario_id = m.usuario_id
        WHERE m.usuario_id = ?
          AND m.descricao LIKE ?
        GROUP BY m.id
        ORDER BY FIELD(m.status, 'andamento', 'pausada', 'concluida'), m.prazo IS NULL, m.prazo
        LIMIT {$quantidade}
    ");
    $stmt->execute([$usuarioId, $like]);
    $itens = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $meta) {
        $itens[] = [
            'titulo' => $meta['descricao'],
            'detalhe' => 'Meta ' . financeiroMoeda((float)$meta['valor_alvo']) . ' | guardado ' . financeiroMoeda((float)$meta['saldo_atual']),
            'valor' => $meta['prazo'] ? financeiroData($meta['prazo']) : ucfirst($meta['status']),
            'url' => 'financeiro.php',
        ];
    }

    financeiroBuscaAdicionarGrupo($resposta, 'metas', 'Metas financeiras', $itens, $pagina, $limite, $total);
}

if (financeiroTabelasDisponiveis($pdo, ['financeiro_cartoes', 'financeiro_cartao_lancamentos'])) {
    $pagina = $paginasBusca['cartoes'];
    $limite = $limitesBusca['cartoes'];
    $quantidade = $pagina * $limite;
    $temCompetenciaFatura = financeiroColunaExiste($pdo, 'financeiro_cartao_lancamentos', 'competencia_fatura');
    $expressaoCompetencia = $temCompetenciaFatura
        ? "COALESCE(l.competencia_fatura, DATE_FORMAT(l.data_compra, '%Y-%m-01'))"
        : "DATE_FORMAT(l.data_compra, '%Y-%m-01')";
    $categoriasDisponiveis = financeiroCategoriasDisponiveis($pdo);
    $joinCategoria = $categoriasDisponiveis
        ? 'LEFT JOIN financeiro_categorias cat
               ON cat.id = l.categoria_id
              AND cat.usuario_id = l.usuario_id'
        : '';
    $condicaoCategoria = $categoriasDisponiveis ? ' OR cat.nome LIKE ?' : '';
    $params = [$usuarioId, $like, $like];

    if ($categoriasDisponiveis) {
        $params[] = $like;
    }

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_cartao_lancamentos l
        INNER JOIN financeiro_cartoes c
            ON c.id = l.cartao_id
           AND c.usuario_id = l.usuario_id
        {$joinCategoria}
        WHERE l.usuario_id = ?
          AND (l.descricao LIKE ? OR c.nome LIKE ?{$condicaoCategoria})
    ");
    $stmtTotal->execute($params);
    $total = (int)$stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.descricao,
            l.valor,
            l.data_compra,
            l.status,
            l.parcela_numero,
            l.parcelas_total,
            c.id AS cartao_id,
            c.nome AS cartao_nome,
            DATE_FORMAT({$expressaoCompetencia}, '%Y-%m') AS mes_fatura
        FROM financeiro_cartao_lancamentos l
        INNER JOIN financeiro_cartoes c
            ON c.id = l.cartao_id
           AND c.usuario_id = l.usuario_id
        {$joinCategoria}
        WHERE l.usuario_id = ?
          AND (l.descricao LIKE ? OR c.nome LIKE ?{$condicaoCategoria})
        ORDER BY {$expressaoCompetencia} DESC, l.data_compra DESC, l.id DESC
        LIMIT {$quantidade}
    ");
    $stmt->execute($params);
    $itens = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $compra) {
        $parcela = '';

        if (!empty($compra['parcela_numero']) && !empty($compra['parcelas_total'])) {
            $parcela = ' | ' . (int)$compra['parcela_numero'] . '/' . (int)$compra['parcelas_total'];
        }

        $itens[] = [
            'titulo' => $compra['descricao'],
            'detalhe' => $compra['cartao_nome'] . ' | fatura ' . date('m/Y', strtotime($compra['mes_fatura'] . '-01')) . $parcela,
            'valor' => financeiroMoeda((float)$compra['valor']),
            'url' => 'financeiro_cartoes.php?' . http_build_query([
                'cartao' => (int)$compra['cartao_id'],
                'mes' => $compra['mes_fatura'],
            ]),
        ];
    }

    financeiroBuscaAdicionarGrupo($resposta, 'cartoes', 'Compras nos cartões', $itens, $pagina, $limite, $total);
}

echo json_encode($resposta);
