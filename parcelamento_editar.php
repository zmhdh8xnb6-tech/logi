<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: parcelamentos.php');
    exit;
}

$parcelamento = buscarParcelamentoPorId($pdo, $id);

if (!$parcelamento) {
    header('Location: parcelamentos.php');
    exit;
}

$orgaosPermitidos = orgaosParcelamento();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';
    $urlRetorno = urlOrgaoParcelamento($parcelamento['orgao']);

    if ($acao === 'excluir') {
        $stmt = $pdo->prepare("DELETE FROM parcelamentos WHERE id = ?");
        $stmt->execute([$id]);

        header('Location: ' . $urlRetorno . '?excluido=1');
        exit;
    }

    $orgao = trim($_POST['orgao'] ?? '');
    $numeroParcelamento = trim($_POST['numero_parcelamento'] ?? '');
    $formaEnvio = trim($_POST['forma_envio'] ?? '');
    $dataPrimeiraParcela = $_POST['data_primeira_parcela'] ?: null;
    $parcelasTotal = (int)($_POST['parcelas_total'] ?? 0);
    $parcelasEmitidas = (int)($_POST['parcelas_emitidas'] ?? 0);
    $parcelasAtrasadas = (int)($_POST['parcelas_atrasadas'] ?? 0);

    if (
        !array_key_exists($orgao, $orgaosPermitidos) ||
        $numeroParcelamento === '' ||
        $formaEnvio === '' ||
        $parcelasTotal <= 0 ||
        $parcelasEmitidas < 0 ||
        $parcelasAtrasadas < 0
    ) {
        $erro = 'Preencha todos os campos corretamente.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE parcelamentos SET
                orgao = ?,
                numero_parcelamento = ?,
                forma_envio = ?,
                data_primeira_parcela = ?,
                parcelas_total = ?,
                parcelas_emitidas = ?,
                parcelas_atrasadas = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $orgao,
            $numeroParcelamento,
            $formaEnvio,
            $dataPrimeiraParcela,
            $parcelasTotal,
            $parcelasEmitidas,
            $parcelasAtrasadas,
            $id,
        ]);

        header('Location: ' . urlOrgaoParcelamento($orgao) . '?editado=1');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Editar Parcelamento</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Editar Parcelamento</h3>
                    <p class="text-muted mb-0">
                        <?= htmlspecialchars($parcelamento['cliente_codigo']) ?>
                        -
                        <?= htmlspecialchars($parcelamento['cliente_nome']) ?>
                    </p>
                </div>

                <a href="<?= htmlspecialchars(urlOrgaoParcelamento($parcelamento['orgao'])) ?>" class="btn btn-outline-secondary">
                    Voltar
                </a>
            </div>

            <div class="clientes-box">
                <?php if (!empty($erro)): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>

                <form id="formParcelamentoEditar" method="post">
                    <div class="row">

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Órgão</label>
                            <select class="form-select campo-obrigatorio" name="orgao" id="orgao">
                                <?php foreach ($orgaosPermitidos as $orgao => $url): ?>
                                    <option
                                        value="<?= htmlspecialchars($orgao) ?>"
                                        <?= $parcelamento['orgao'] === $orgao ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($orgao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Número Parcelamento</label>
                            <input
                                type="text"
                                class="form-control campo-obrigatorio"
                                name="numero_parcelamento"
                                id="numero_parcelamento"
                                value="<?= htmlspecialchars($parcelamento['numero_parcelamento']) ?>">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Forma envio</label>
                            <select class="form-select campo-obrigatorio" name="forma_envio" id="forma_envio">
                                <?php foreach (['E-mail', 'WhatsApp', 'Em mãos'] as $forma): ?>
                                    <option
                                        value="<?= htmlspecialchars($forma) ?>"
                                        <?= $parcelamento['forma_envio'] === $forma ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($forma) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Total Parcelas</label>
                            <input
                                type="number"
                                class="form-control campo-obrigatorio"
                                name="parcelas_total"
                                id="parcelas_total"
                                min="1"
                                value="<?= (int)$parcelamento['parcelas_total'] ?>">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Data primeira parcela</label>
                            <input
                                type="date"
                                class="form-control"
                                name="data_primeira_parcela"
                                id="data_primeira_parcela"
                                value="<?= htmlspecialchars($parcelamento['data_primeira_parcela'] ?? '') ?>">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Parcelas emitidas manual</label>
                            <input
                                type="number"
                                class="form-control campo-obrigatorio"
                                name="parcelas_emitidas"
                                id="parcelas_emitidas"
                                min="0"
                                value="<?= (int)$parcelamento['parcelas_emitidas'] ?>">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Parcelas atrasadas</label>
                            <input
                                type="number"
                                class="form-control campo-obrigatorio"
                                name="parcelas_atrasadas"
                                id="parcelas_atrasadas"
                                min="0"
                                value="<?= (int)$parcelamento['parcelas_atrasadas'] ?>">
                        </div>

                    </div>

                    <div class="d-flex justify-content-between gap-2 mt-3">
                        <button
                            type="submit"
                            name="acao"
                            value="excluir"
                            class="btn btn-danger"
                            onclick="return confirm('Tem certeza que deseja excluir este parcelamento?');">
                            Excluir
                        </button>

                        <button type="submit" name="acao" value="salvar" class="btn btn-success">
                            Salvar alterações
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </main>

    <script>
        document.getElementById('formParcelamentoEditar').addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.value === 'excluir') {
                return;
            }

            let valido = true;

            document.querySelectorAll('.campo-obrigatorio').forEach(function(campo) {
                if (!campo.value.trim()) {
                    campo.classList.add('is-invalid');
                    valido = false;
                } else {
                    campo.classList.remove('is-invalid');
                }
            });

            if (!valido) {
                e.preventDefault();
            }
        });
    </script>

</body>

</html>