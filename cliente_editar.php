<?php
require 'config.php';

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: index.php");
    exit;
}

$stmtAlvaras = $pdo->prepare("
    SELECT orgao_codigo, situacao, vencimento
    FROM cliente_alvaras
    WHERE cliente_id = ?
");
$stmtAlvaras->execute([$id]);

$alvarasCliente = [];

foreach ($stmtAlvaras->fetchAll(PDO::FETCH_ASSOC) as $alvaraCliente) {
    $alvarasCliente[$alvaraCliente['orgao_codigo']] = $alvaraCliente;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Editar Cliente</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="mb-4">
                <h3 class="mb-1">Editar Cliente</h3>
                <p class="text-muted mb-0">
                    Atualize os dados do cliente
                </p>
            </div>

            <div class="clientes-box">
                <form id="clienteForm" novalidate>
                    <input type="hidden" name="id" id="id" value="<?= htmlspecialchars($cliente['id']) ?>">

                    <?php include 'includes/formulario_cliente.php'; ?>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="cliente.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-secondary">
                            Cancelar
                        </a>

                        <button type="submit" class="btn btn-success" id="btnSalvarCliente">
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>

    <?php include 'includes/modal_aviso.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="<?= assetUrl('assets/script.js') ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $('#codigo').val(<?= json_encode($cliente['codigo'] ?? '') ?>);
            $('#documento').val(<?= json_encode($cliente['documento'] ?? '') ?>);
            $('#nome').val(<?= json_encode($cliente['nome'] ?? '') ?>);
            $('#nome_fantasia').val(<?= json_encode($cliente['nome_fantasia'] ?? '') ?>);
            $('#email').val(<?= json_encode($cliente['email'] ?? '') ?>);
            $('#telefone').val(<?= json_encode($cliente['telefone'] ?? '') ?>);
            $('#inscricao_estadual').val(<?= json_encode($cliente['inscricao_estadual'] ?? '') ?>);
            $('#nire').val(<?= json_encode($cliente['nire'] ?? '') ?>);
            $('#vencimento_certificado').val(<?= json_encode($cliente['vencimento_certificado'] ?? '') ?>);
            $('#cadastro_df_legal').val(<?= json_encode($cliente['cadastro_df_legal'] ?? '') ?>);
            $('#alvara').val(<?= json_encode($cliente['alvara'] ?? '') ?>);
            $('#contador').val(<?= json_encode($cliente['contador'] ?? '') ?>);
            $('#cadastro_crf').val(<?= json_encode($cliente['cadastro_crf'] ?? '') ?>);
            $('#procuracao_receita_federal').val(<?= json_encode($cliente['procuracao_receita_federal'] ?? '') ?>);
            $('#vencimento_procuracao_receita_federal').val(<?= json_encode($cliente['vencimento_procuracao_receita_federal'] ?? '') ?>);
            $('#procuracao_conectividade').val(<?= json_encode($cliente['procuracao_conectividade'] ?? '') ?>);
            $('#vencimento_procuracao_conectividade').val(<?= json_encode($cliente['vencimento_procuracao_conectividade'] ?? '') ?>);
            $('#procuracao_empregador_web').val(<?= json_encode($cliente['procuracao_empregador_web'] ?? '') ?>);
            $('#procuracao_fgts').val(<?= json_encode($cliente['procuracao_fgts'] ?? '') ?>);
            $('#vencimento_procuracao_fgts').val(<?= json_encode($cliente['vencimento_procuracao_fgts'] ?? '') ?>);
            $('#procuracao_particular').val(<?= json_encode($cliente['procuracao_particular'] ?? '') ?>);
            $('#procuracao_sefaz').val(<?= json_encode($cliente['procuracao_sefaz'] ?? '') ?>);
            $('#contrato_prestacao_servicos').val(<?= json_encode($cliente['contrato_prestacao_servicos'] ?? '') ?>);
            $('#tributacao').val(<?= json_encode($cliente['tributacao'] ?? '') ?>);
            $('#possui_parcelamento').val(<?= json_encode($cliente['possui_parcelamento'] ?? '') ?>);

            document.querySelectorAll('.controle-com-vencimento').forEach(function(campo) {
                atualizarCampoVencimentoControle(campo);
            });

            $('#vencimento_procuracao_receita_federal').val(<?= json_encode($cliente['vencimento_procuracao_receita_federal'] ?? '') ?>);
            $('#vencimento_procuracao_conectividade').val(<?= json_encode($cliente['vencimento_procuracao_conectividade'] ?? '') ?>);
            $('#vencimento_procuracao_fgts').val(<?= json_encode($cliente['vencimento_procuracao_fgts'] ?? '') ?>);

            $('#cep').val(<?= json_encode($cliente['cep'] ?? '') ?>);
            $('#endereco').val(<?= json_encode($cliente['endereco'] ?? '') ?>);
            $('#numero_endereco').val(<?= json_encode($cliente['numero_endereco'] ?? '') ?>);
            $('#complemento').val(<?= json_encode($cliente['complemento'] ?? '') ?>);
            $('#bairro').val(<?= json_encode($cliente['bairro'] ?? '') ?>);
            $('#cidade').val(<?= json_encode($cliente['cidade'] ?? '') ?>);
            $('#uf').val(<?= json_encode($cliente['uf'] ?? '') ?>);
        });
    </script>

</body>

</html>