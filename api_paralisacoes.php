<?php
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

exigirLogin();

function responderParalisacao(bool $sucesso, string $mensagem, array $dados = []): void
{
    echo json_encode(
        array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $dados),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function dataParalisacaoValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function senhaAdministradorValida(PDO $pdoUsuarios, string $senha): bool
{
    if ($senha === '') {
        return false;
    }

    $stmt = $pdoUsuarios->query("
        SELECT senha
        FROM usuarios
        WHERE tipo = 'admin'
          AND COALESCE(ativo, 1) = 1
    ");

    while ($usuario = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (password_verify($senha, (string)($usuario['senha'] ?? ''))) {
            return true;
        }
    }

    return false;
}

if (!usuarioPode('paralisacoes')) {
    http_response_code(403);
    responderParalisacao(false, 'Você não possui permissão para alterar paralisações.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderParalisacao(false, 'Requisição inválida.');
}

$colunasObrigatorias = [
    'paralisacao_status',
    'paralisacao_inicio',
    'paralisacao_fim',
    'paralisacao_reativada_em',
    'paralisacao_bloqueio_ate',
];

foreach ($colunasObrigatorias as $coluna) {
    if (!logiColunaExiste($pdo, 'clientes', $coluna)) {
        responderParalisacao(false, 'Rode o SQL de paralisações antes de usar este controle.');
    }
}

$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
$acao = $_POST['acao'] ?? '';
$data = $_POST['data'] ?? '';
$senhaAdmin = (string)($_POST['senha_admin'] ?? '');

if (!$clienteId || !in_array($acao, ['paralisar', 'reativar', 'desfazer'], true)) {
    responderParalisacao(false, 'Dados inválidos.');
}

if (in_array($acao, ['paralisar', 'reativar'], true) && !dataParalisacaoValida($data)) {
    responderParalisacao(false, 'Dados inválidos.');
}

if ($acao === 'desfazer' && !senhaAdministradorValida($authPdo ?? $pdo, $senhaAdmin)) {
    responderParalisacao(false, 'Informe a senha de um administrador para desfazer a paralisação.');
}

$stmtCliente = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    " . empresaFiltroClienteDireto($pdo) . "
");
$stmtCliente->execute([$clienteId]);
$clienteAntes = $stmtCliente->fetch(PDO::FETCH_ASSOC);

if (!$clienteAntes) {
    responderParalisacao(false, 'Cliente não encontrado nesta empresa.');
}

$statusAtual = $clienteAntes['paralisacao_status'] ?? 'ativa';
$bloqueioAtual = $clienteAntes['paralisacao_bloqueio_ate'] ?? '';

if ($acao === 'desfazer') {
    if ($statusAtual !== 'paralisada') {
        responderParalisacao(false, 'Só é possível desfazer uma empresa que está paralisada.');
    }

    $inicio = null;
    $fim = null;
    $reativadaEm = null;
    $bloqueioAte = null;
    $novoStatus = 'ativa';
} elseif ($acao === 'paralisar') {
    if ($statusAtual === 'paralisada') {
        responderParalisacao(false, 'Esta empresa já está paralisada.');
    }

    if (!empty($bloqueioAtual) && $bloqueioAtual >= date('Y-m-d')) {
        responderParalisacao(false, 'Esta empresa só poderá ser paralisada novamente após ' . date('d/m/Y', strtotime($bloqueioAtual)) . '.');
    }

    $inicio = $data;
    $fim = date('Y-m-d', strtotime($inicio . ' +5 years'));
    $reativadaEm = null;
    $bloqueioAte = null;
    $novoStatus = 'paralisada';
} else {
    if ($statusAtual !== 'paralisada') {
        responderParalisacao(false, 'Esta empresa não está paralisada.');
    }

    $inicio = $clienteAntes['paralisacao_inicio'] ?? null;
    $fim = $clienteAntes['paralisacao_fim'] ?? null;
    $reativadaEm = $data;
    $bloqueioAte = date('Y-m-d', strtotime($reativadaEm . ' +3 years'));
    $novoStatus = 'ativa';
}

try {
    $stmt = $pdo->prepare("
        UPDATE clientes
        SET paralisacao_status = ?,
            paralisacao_inicio = ?,
            paralisacao_fim = ?,
            paralisacao_reativada_em = ?,
            paralisacao_bloqueio_ate = ?
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ");
    $ok = $stmt->execute([
        $novoStatus,
        $inicio,
        $fim,
        $reativadaEm,
        $bloqueioAte,
        $clienteId,
    ]);

    if (!$ok) {
        responderParalisacao(false, 'Não foi possível salvar.');
    }

    $stmtDepois = $pdo->prepare("
        SELECT *
        FROM clientes
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $stmtDepois->execute([$clienteId]);
    $clienteDepois = $stmtDepois->fetch(PDO::FETCH_ASSOC) ?: [];
    $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois);

    registrarAuditoria(
        $pdo,
        'Paralisações',
        $acao,
        'cliente',
        $clienteId,
        (
            $acao === 'paralisar'
            ? 'Paralisou '
            : ($acao === 'reativar' ? 'Reativou ' : 'Desfez paralisação de ')
        ) . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
        $mudancas['antes'],
        $mudancas['depois']
    );

    responderParalisacao(true, 'Paralisação salva com sucesso.', [
        'paralisacao' => [
            'status' => $novoStatus,
            'inicio' => $inicio,
            'fim' => $fim,
            'reativada_em' => $reativadaEm,
            'bloqueio_ate' => $bloqueioAte,
        ],
    ]);
} catch (Throwable $e) {
    responderParalisacao(false, 'Não foi possível salvar a paralisação.');
}
