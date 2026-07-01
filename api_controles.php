<?php
require 'config.php';

exigirLogin();

$id = $_POST['id'] ?? '';
$campoStatus = $_POST['campo_status'] ?? '';
$status = $_POST['status'] ?? '';
$campoVencimento = $_POST['campo_vencimento'] ?? '';
$vencimento = $_POST['vencimento'] ?? '';
$razaoSocialCorreta = $_POST['razao_social_correta'] ?? 'sim';
$enderecoCorreto = $_POST['endereco_correto'] ?? 'sim';
$socioCorreto = $_POST['socio_correto'] ?? 'sim';

$permissaoPorCampo = [
    'cadastro_df_legal' => 'pendencias',
    'contador' => 'contador',
    'cadastro_crf' => 'crf',
    'contrato_prestacao_servicos' => 'contratos',
    'procuracao_receita_federal' => 'procuracoes',
    'procuracao_conectividade' => 'procuracoes',
    'procuracao_empregador_web' => 'procuracoes',
    'procuracao_fgts' => 'procuracoes',
    'procuracao_particular' => 'procuracoes',
    'procuracao_sefaz' => 'procuracoes',
];

$camposStatusPermitidos = [
    'cadastro_df_legal' => ['cadastrado', 'nao_cadastrado', 'goias'],
    'contador' => ['sim', 'nao'],
    'cadastro_crf' => ['cadastrado', 'nao_cadastrado'],
    'contrato_prestacao_servicos' => ['possui', 'nao_possui'],
    'procuracao_receita_federal' => ['possui', 'nao_possui'],
    'procuracao_conectividade' => ['possui', 'nao_possui'],
    'procuracao_empregador_web' => ['possui', 'nao_possui'],
    'procuracao_fgts' => ['possui', 'nao_possui'],
    'procuracao_particular' => ['possui', 'nao_possui'],
    'procuracao_sefaz' => ['possui', 'nao_possui', 'goias'],
];

$pendenciasConferenciaDados = [
    'cadastro_df_legal' => [
        'coluna' => 'pendencia_df_legal_dados',
        'status_ok' => 'cadastrado',
        'verificar_socio' => false,
    ],
    'cadastro_crf' => [
        'coluna' => 'pendencia_crf_dados',
        'status_ok' => 'cadastrado',
        'verificar_socio' => false,
    ],
    'procuracao_particular' => [
        'coluna' => 'pendencia_procuracao_particular_dados',
        'status_ok' => 'possui',
        'verificar_socio' => true,
    ],
];

function controleTemColuna(PDO $pdo, string $coluna): bool
{
    static $cache = [];

    if (array_key_exists($coluna, $cache)) {
        return $cache[$coluna];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM clientes LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$coluna] = false;
    }

    return $cache[$coluna];
}

$camposVencimentoPermitidos = [
    'vencimento_procuracao_receita_federal',
    'vencimento_procuracao_conectividade',
    'vencimento_procuracao_fgts',
];

$relacaoStatusVencimento = [
    'procuracao_receita_federal' => 'vencimento_procuracao_receita_federal',
    'procuracao_conectividade' => 'vencimento_procuracao_conectividade',
    'procuracao_fgts' => 'vencimento_procuracao_fgts',
];

if ($id === '' || !array_key_exists($campoStatus, $camposStatusPermitidos)) {
    echo 'erro';
    exit;
}

if (!usuarioPode($permissaoPorCampo[$campoStatus] ?? '')) {
    echo 'nao_autorizado';
    exit;
}

if (!in_array($status, $camposStatusPermitidos[$campoStatus], true)) {
    echo 'erro';
    exit;
}

$vencimento = $vencimento !== '' ? $vencimento : null;

if ($campoVencimento !== '') {
    if (!in_array($campoVencimento, $camposVencimentoPermitidos, true)) {
        echo 'erro';
        exit;
    }

    if (($relacaoStatusVencimento[$campoStatus] ?? '') !== $campoVencimento) {
        echo 'erro';
        exit;
    }
}

if ($campoVencimento !== '' && $status !== 'possui') {
    $vencimento = null;
}

if ($campoVencimento !== '' && $status === 'possui' && $vencimento === null) {
    echo 'vencimento_obrigatorio';
    exit;
}

$stmtAntes = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmtAntes->execute([$id]);
$clienteAntes = $stmtAntes->fetch(PDO::FETCH_ASSOC);

if ($campoVencimento !== '') {
    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$campoStatus} = ?, {$campoVencimento} = ?
        WHERE id = ?
    ");

    $ok = $stmt->execute([
        $status,
        $vencimento,
        $id,
    ]);
} else {
    $stmt = $pdo->prepare("
        UPDATE clientes
        SET {$campoStatus} = ?
        WHERE id = ?
    ");

    $ok = $stmt->execute([
        $status,
        $id,
    ]);
}

if ($ok && isset($pendenciasConferenciaDados[$campoStatus])) {
    $configuracaoPendencia = $pendenciasConferenciaDados[$campoStatus];

    if (controleTemColuna($pdo, $configuracaoPendencia['coluna'])) {
        $pendente = $status === $configuracaoPendencia['status_ok']
            && (
                $razaoSocialCorreta === 'nao'
                || $enderecoCorreto === 'nao'
                || ($configuracaoPendencia['verificar_socio'] && $socioCorreto === 'nao')
            );

        $stmtPendencia = $pdo->prepare("
            UPDATE clientes
            SET {$configuracaoPendencia['coluna']} = ?
            WHERE id = ?
        ");
        $stmtPendencia->execute([$pendente ? 1 : 0, $id]);
    }
}

if ($ok && $clienteAntes) {
    $stmtDepois = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmtDepois->execute([$id]);
    $clienteDepois = $stmtDepois->fetch(PDO::FETCH_ASSOC);
    $mudancas = auditoriaMudancas($clienteAntes, $clienteDepois ?: []);
    registrarAuditoria(
        $pdo,
        'Controles internos',
        'editar',
        'cliente',
        $id,
        'Alterou ' . str_replace('_', ' ', $campoStatus) . ' de ' . ($clienteAntes['codigo'] ?? '') . ' - ' . ($clienteAntes['nome'] ?? ''),
        $mudancas['antes'],
        $mudancas['depois']
    );
}

echo $ok ? 'ok' : 'erro';
