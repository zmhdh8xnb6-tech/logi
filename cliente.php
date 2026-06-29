<?php
require 'config.php';

exigirPermissao('clientes');

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: clientes.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

$stmtAlvaras = $pdo->prepare("
    SELECT orgao_nome, situacao, vencimento
    FROM cliente_alvaras
    WHERE cliente_id = ?
    ORDER BY orgao_nome
");
$stmtAlvaras->execute([$id]);
$alvarasCliente = $stmtAlvaras->fetchAll(PDO::FETCH_ASSOC);

$rotulosControle = [
    'possui' => 'Possui',
    'nao_possui' => 'Não possui',
    'goias' => 'Goiás',
    'cadastrado' => 'Cadastrado',
    'nao_cadastrado' => 'Não cadastrado',
    'sim' => 'Sim',
    'nao' => 'Não',
    'simples_nacional' => 'Simples Nacional',
    'lucro_presumido' => 'Lucro Presumido',
    'lucro_real' => 'Lucro Real',
    'mei' => 'Microempreendedor Individual',
];

$formatarControle = static function ($valor) use ($rotulosControle): string {
    return $rotulosControle[$valor] ?? 'Não informado';
};

$formatarData = static function ($data): string {
    return !empty($data) ? date('d/m/Y', strtotime($data)) : 'Não informado';
};

$clienteContabil = (int)($cliente['cliente_contabil'] ?? 1) === 1;
$servicoParcelamento = (int)($cliente['servico_parcelamento'] ?? 0) === 1;
$servicoCertificado = (int)($cliente['servico_certificado'] ?? 1) === 1;
$paginaRetorno = $clienteContabil ? 'clientes.php' : 'servicos_avulsos.php';
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title><?= htmlspecialchars($cliente['nome']) ?> - Cliente</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <a href="<?= $paginaRetorno ?>" class="btn btn-outline-secondary mb-3">Voltar</a>

            <h3><?= htmlspecialchars($cliente['nome']) ?></h3>
            <p class="text-muted"><?= htmlspecialchars($cliente['documento']) ?></p>
            <span class="badge <?= $clienteContabil ? 'bg-success' : 'bg-info text-dark' ?> mb-3">
                <?= $clienteContabil ? 'Cliente contábil' : 'Serviço avulso' ?>
            </span>

            <div class="d-flex gap-2 mb-4">
                <a href="cliente_editar.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Editar
                </a>

                <button class="btn btn-danger" onclick="excluirCliente(<?= (int)$cliente['id'] ?>)">
                    <i class="bi bi-trash"></i> Excluir
                </button>
            </div>

            <div class="clientes-box mt-4">
                <h5>Dados principais</h5>
                <p><strong>Código:</strong> <?= htmlspecialchars($cliente['codigo'] ?? '') ?></p>
                <p><strong>Nome Fantasia:</strong> <?= htmlspecialchars($cliente['nome_fantasia'] ?? '') ?></p>
                <p><strong>E-mail:</strong> <?= htmlspecialchars($cliente['email'] ?? '') ?></p>
                <p><strong>Telefone:</strong> <?= htmlspecialchars($cliente['telefone'] ?? '') ?></p>
                <p><strong>Inscrição Estadual:</strong> <?= htmlspecialchars($cliente['inscricao_estadual'] ?? '') ?></p>
                <p><strong>NIRE:</strong> <?= htmlspecialchars($cliente['nire'] ?? '') ?></p>
                <?php if ($servicoCertificado): ?>
                    <p>
                        <strong>Vencimento Certificado Digital:</strong>
                        <?= !empty($cliente['vencimento_certificado'])
                            ? date('d/m/Y', strtotime($cliente['vencimento_certificado']))
                            : 'Não cadastrado'; ?>
                    </p>
                <?php endif; ?>
                <hr>

                <?php if (!$clienteContabil): ?>
                    <h6 class="mt-3 mb-2">Serviços acompanhados</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($servicoParcelamento): ?>
                            <span class="badge bg-primary">Parcelamento</span>
                        <?php endif; ?>
                        <?php if ($servicoCertificado): ?>
                            <span class="badge bg-success">Certificado Digital</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <h6 class="mt-3 mb-3">Controles internos</h6>

                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Cadastro DF Legal</small>
                            <?= htmlspecialchars($formatarControle($cliente['cadastro_df_legal'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Alvará</small>
                            <?= htmlspecialchars($formatarControle($cliente['alvara'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Contador</small>
                            <?= htmlspecialchars($formatarControle($cliente['contador'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Cadastro CRF</small>
                            <?= htmlspecialchars($formatarControle($cliente['cadastro_crf'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Receita Federal</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_receita_federal'] ?? '')) ?>
                            <?php if (($cliente['procuracao_receita_federal'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_receita_federal'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Conectividade</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_conectividade'] ?? '')) ?>
                            <?php if (($cliente['procuracao_conectividade'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_conectividade'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Empregador Web</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_empregador_web'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração FGTS</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_fgts'] ?? '')) ?>
                            <?php if (($cliente['procuracao_fgts'] ?? '') === 'possui'): ?>
                                - <?= htmlspecialchars($formatarData($cliente['vencimento_procuracao_fgts'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração Particular</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_particular'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Procuração SEFAZ</small>
                            <?= htmlspecialchars($formatarControle($cliente['procuracao_sefaz'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Contrato de Prestação de Serviços</small>
                            <?= htmlspecialchars($formatarControle($cliente['contrato_prestacao_servicos'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Tributação</small>
                            <?= htmlspecialchars($formatarControle($cliente['tributacao'] ?? '')) ?>
                        </div>

                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Parcelamentos</small>
                            <?= htmlspecialchars($formatarControle($cliente['possui_parcelamento'] ?? '')) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($clienteContabil && !empty($alvarasCliente)): ?>
                <div class="clientes-box mt-4">
                    <h5>Alvarás e licenças</h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Órgão</th>
                                    <th>Situação</th>
                                    <th>Vencimento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alvarasCliente as $alvaraCliente): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($alvaraCliente['orgao_nome']) ?></td>
                                        <td><?= $alvaraCliente['situacao'] === 'dispensado' ? 'Dispensado' : 'Com vencimento' ?></td>
                                        <td><?= $alvaraCliente['situacao'] === 'dispensado' ? '-' : htmlspecialchars($formatarData($alvaraCliente['vencimento'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="clientes-box mt-4">
                <h5>Endereço</h5>
                <p><strong>CEP:</strong> <?= htmlspecialchars($cliente['cep'] ?? '') ?></p>
                <p><strong>Endereço:</strong> <?= htmlspecialchars($cliente['endereco'] ?? '') ?>, <?= htmlspecialchars($cliente['numero_endereco'] ?? '') ?></p>
                <p><strong>Complemento:</strong> <?= htmlspecialchars($cliente['complemento'] ?? '') ?></p>
                <p><strong>Bairro:</strong> <?= htmlspecialchars($cliente['bairro'] ?? '') ?></p>
                <p><strong>Cidade/UF:</strong> <?= htmlspecialchars($cliente['cidade'] ?? '') ?> / <?= htmlspecialchars($cliente['uf'] ?? '') ?></p>
            </div>

        </div>
    </main>

    <?php include 'includes/modal_confirmar.php'; ?>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

    <script>
        const clienteAtual = <?= json_encode($cliente, JSON_UNESCAPED_UNICODE) ?>;
    </script>

</body>

</html>