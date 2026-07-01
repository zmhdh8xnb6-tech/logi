<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parcelamentos.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$dataQuitacao = trim($_POST['data_quitacao'] ?? '');
$observacao = trim($_POST['observacao'] ?? '');
$parcelamento = buscarParcelamentoPorId($pdo, $id);

if (
    !$parcelamento ||
    !empty($parcelamento['cancelado_em']) ||
    !empty($parcelamento['liquidado_em'])
) {
    header('Location: parcelamentos.php');
    exit;
}

$data = DateTime::createFromFormat('!Y-m-d', $dataQuitacao);
$hoje = new DateTime(date('Y-m-d'));
$dataValida = $data && $data->format('Y-m-d') === $dataQuitacao && $data <= $hoje;

if (!$dataValida) {
    header('Location: ' . urlOrgaoParcelamento($parcelamento['orgao']) . '?erro_quitacao=1');
    exit;
}

$parcelasNaQuitacao = parcelasEmitidasNaData($parcelamento, $data);
$horario = date('H:i:s');
$liquidadoEm = $dataQuitacao . ' ' . $horario;
$campos = [
    'liquidado_em = ?',
    'parcelas_emitidas = ?',
    'parcelas_atrasadas = 0',
];
$valores = [$liquidadoEm, $parcelasNaQuitacao];

if (parcelamentosTemColuna($pdo, 'liquidacao_tipo')) {
    $campos[] = 'liquidacao_tipo = ?';
    $valores[] = 'antecipada';
}

if (parcelamentosTemColuna($pdo, 'liquidacao_observacao')) {
    $campos[] = 'liquidacao_observacao = ?';
    $valores[] = $observacao !== '' ? $observacao : null;
}

$valores[] = $id;
$stmt = $pdo->prepare("
    UPDATE parcelamentos
    SET " . implode(', ', $campos) . "
    WHERE id = ?
      AND cancelado_em IS NULL
      AND liquidado_em IS NULL
");
$stmt->execute($valores);

registrarAuditoria(
    $pdo,
    'Parcelamentos',
    'quitar',
    'parcelamento',
    $id,
    'Quitou antecipadamente o parcelamento de ' . $parcelamento['cliente_codigo'] . ' - ' . $parcelamento['cliente_nome'],
    [
        'liquidado_em' => $parcelamento['liquidado_em'] ?? null,
        'parcelas_emitidas' => $parcelamento['parcelas_emitidas'],
        'parcelas_atrasadas' => $parcelamento['parcelas_atrasadas'],
    ],
    [
        'liquidado_em' => $liquidadoEm,
        'parcelas_emitidas' => $parcelasNaQuitacao,
        'parcelas_atrasadas' => 0,
        'observacao' => $observacao,
    ]
);

header('Location: ' . urlLiquidadosOrgaoParcelamento($parcelamento['orgao']) . '?quitado=1');
exit;
