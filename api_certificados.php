<?php
require 'config.php';

exigirPermissao('certificados');

$id = $_POST['id'] ?? '';
$vencimento = $_POST['vencimento_certificado'] ?? '';
$certificadoStatus = $_POST['certificado_status'] ?? '';

if ($id == '') {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;
$certificadoStatusDisponivel = logiColunaExiste($pdo, 'clientes', 'certificado_status');

function certificadoStatusAceitaNaoPrecisa(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'certificado_status'");
    $coluna = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    if (!$coluna) {
        return false;
    }

    $tipo = strtolower((string)($coluna['Type'] ?? ''));
    return !str_starts_with($tipo, 'enum(') || str_contains($tipo, "'nao_precisa_momento'");
}

$stmtAntes = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    " . empresaFiltroClienteDireto($pdo) . "
");
$stmtAntes->execute([$id]);
$clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

if (!$clienteAntes) {
    echo 'Cliente não encontrado nesta empresa.';
    exit;
}

if ($certificadoStatus === 'nao_precisa_momento') {
    if (!$certificadoStatusDisponivel) {
        echo 'certificado_status_coluna_ausente';
        exit;
    }

    if (!certificadoStatusAceitaNaoPrecisa($pdo)) {
        echo 'certificado_status_coluna_desatualizada';
        exit;
    }
}

if ($certificadoStatusDisponivel) {
    if (!array_key_exists('certificado_status', $_POST)) {
        $certificadoStatus = $clienteAntes['certificado_status'] ?? ($vencimento !== null ? 'possui' : 'nao_possui');
    }

    if (!in_array($certificadoStatus, ['possui', 'nao_possui', 'nao_precisa_momento'], true)) {
        $certificadoStatus = $vencimento !== null ? 'possui' : 'nao_possui';
    }

    if (in_array($certificadoStatus, ['nao_possui', 'nao_precisa_momento'], true)) {
        $vencimento = null;
    }
}

$setCertificadoStatus = $certificadoStatusDisponivel ? ', certificado_status = ?' : '';

$stmt = $pdo->prepare("
    UPDATE clientes
    SET vencimento_certificado = ?,
        servico_certificado = 1
        {$setCertificadoStatus}
    WHERE id = ?
      " . empresaFiltroClienteDireto($pdo) . "
");

$valores = [$vencimento];

if ($certificadoStatusDisponivel) {
    $valores[] = $certificadoStatus;
}

$valores[] = $id;

try {
    $ok = $stmt->execute($valores);
} catch (Throwable $erro) {
    echo 'erro_salvar_certificado';
    exit;
}

if ($ok && $certificadoStatus === 'nao_precisa_momento' && logiColunaExiste($pdo, 'clientes', 'pendencia_certificado_digital')) {
    $pdo->prepare("
        UPDATE clientes
        SET pendencia_certificado_digital = 0
        WHERE id = ?
          " . empresaFiltroClienteDireto($pdo) . "
    ")->execute([$id]);
}

if ($ok && $clienteAntes) {
    $clienteDepois = $clienteAntes;
    $clienteDepois['vencimento_certificado'] = $vencimento;
    $clienteDepois['certificado_status'] = $certificadoStatusDisponivel ? $certificadoStatus : ($clienteDepois['certificado_status'] ?? null);
    $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois);
    registrarAuditoria(
        $pdo,
        'Certificados',
        'editar',
        'cliente',
        $id,
        'Alterou o certificado de ' . $clienteAntes['codigo'] . ' - ' . $clienteAntes['nome'],
        $mudancas['antes'],
        $mudancas['depois']
    );
}

echo $ok ? 'ok' : 'erro';
