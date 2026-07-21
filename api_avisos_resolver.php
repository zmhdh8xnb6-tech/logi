<?php
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

function responderAvisoHome(bool $sucesso, string $mensagem): void
{
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function avisoHomeDataValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAvisoHome(false, 'Requisição inválida.');
}

$tipo = $_POST['tipo'] ?? '';
$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
$vencimento = trim((string)($_POST['vencimento'] ?? ''));

if (!$clienteId || !avisoHomeDataValida($vencimento)) {
    responderAvisoHome(false, 'Informe um vencimento válido.');
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
    responderAvisoHome(false, 'Cliente não encontrado nesta empresa.');
}

if ($tipo === 'certificado') {
    if (!usuarioPode('certificados') && !usuarioPode('pendencias')) {
        responderAvisoHome(false, 'Você não possui permissão para resolver este aviso.');
    }

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET vencimento_certificado = ?,
            servico_certificado = 1
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $ok = $stmt->execute([$vencimento, $clienteId]);

    if ($ok) {
        $clienteDepois = $clienteAntes;
        $clienteDepois['vencimento_certificado'] = $vencimento;
        $clienteDepois['servico_certificado'] = 1;
        $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois);
        registrarAuditoria(
            $pdo,
            'Avisos',
            'editar',
            'cliente',
            $clienteId,
            'Resolveu aviso de certificado de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
            $mudancas['antes'],
            $mudancas['depois']
        );
    }

    responderAvisoHome($ok, $ok ? 'Aviso resolvido com sucesso.' : 'Não foi possível resolver o aviso.');
}

if ($tipo === 'procuracao') {
    if (!usuarioPode('procuracoes') && !usuarioPode('pendencias')) {
        responderAvisoHome(false, 'Você não possui permissão para resolver este aviso.');
    }

    $campoStatus = $_POST['campo_status'] ?? '';
    $campoVencimento = $_POST['campo_vencimento'] ?? '';
    $camposPermitidos = [
        'procuracao_receita_federal' => 'vencimento_procuracao_receita_federal',
        'procuracao_conectividade' => 'vencimento_procuracao_conectividade',
        'procuracao_fgts' => 'vencimento_procuracao_fgts',
    ];

    if (($camposPermitidos[$campoStatus] ?? '') !== $campoVencimento) {
        responderAvisoHome(false, 'Aviso inválido.');
    }

    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$campoStatus} = 'possui',
            {$campoVencimento} = ?
        WHERE id = ?
        " . empresaFiltroClienteDireto($pdo) . "
    ");
    $ok = $stmt->execute([$vencimento, $clienteId]);

    if ($ok) {
        $clienteDepois = $clienteAntes;
        $clienteDepois[$campoStatus] = 'possui';
        $clienteDepois[$campoVencimento] = $vencimento;
        $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois);
        registrarAuditoria(
            $pdo,
            'Avisos',
            'editar',
            'cliente',
            $clienteId,
            'Resolveu aviso de procuração de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
            $mudancas['antes'],
            $mudancas['depois']
        );
    }

    responderAvisoHome($ok, $ok ? 'Aviso resolvido com sucesso.' : 'Não foi possível resolver o aviso.');
}

if ($tipo === 'alvara') {
    if (!usuarioPode('alvaras') && !usuarioPode('pendencias')) {
        responderAvisoHome(false, 'Você não possui permissão para resolver este aviso.');
    }

    $orgaoCodigo = $_POST['orgao_codigo'] ?? '';
    $orgaosPermitidos = [
        'ibram',
        'cbmdf',
        'df_legal',
        'pcdf',
        'seagri',
        'seedf',
        'sudesc',
        'visadf',
    ];

    if (!in_array($orgaoCodigo, $orgaosPermitidos, true)) {
        responderAvisoHome(false, 'Órgão inválido.');
    }

    $stmtAntes = $pdo->prepare("
        SELECT ca.*
        FROM cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        WHERE ca.cliente_id = ?
          AND ca.orgao_codigo = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtAntes->execute([$clienteId, $orgaoCodigo]);
    $alvaraAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

    if (!$alvaraAntes) {
        responderAvisoHome(false, 'Alvará não encontrado para este cliente.');
    }

    $stmt = $pdo->prepare("
        UPDATE cliente_alvaras ca
        INNER JOIN clientes c ON c.id = ca.cliente_id
        SET ca.situacao = 'com_vencimento',
            ca.vencimento = ?
        WHERE ca.cliente_id = ?
          AND ca.orgao_codigo = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $ok = $stmt->execute([$vencimento, $clienteId, $orgaoCodigo]);

    if ($ok) {
        $alvaraDepois = $alvaraAntes;
        $alvaraDepois['situacao'] = 'com_vencimento';
        $alvaraDepois['vencimento'] = $vencimento;
        registrarAuditoria(
            $pdo,
            'Avisos',
            'editar',
            'cliente',
            $clienteId,
            'Resolveu aviso de alvará de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
            $alvaraAntes,
            $alvaraDepois
        );
    }

    responderAvisoHome($ok, $ok ? 'Aviso resolvido com sucesso.' : 'Não foi possível resolver o aviso.');
}

responderAvisoHome(false, 'Tipo de aviso inválido.');
