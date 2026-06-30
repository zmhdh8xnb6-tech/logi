<?php
require 'config.php';
require 'includes/parcelamentos_funcoes.php';

exigirPermissao('parcelamentos');

$orgaosPermitidos = [
    'Simples Nacional' => 'parcelamento_simples.php',
    'Previdência Social e Tributos' => 'parcelamento_tributos.php',
    'PGFN' => 'parcelamento_pgfn.php',
    'SEFAZ DF' => 'parcelamento_sefazdf.php',
    'SEFAZ GO' => 'parcelamento_sefazgo.php',
];

$clienteSelecionadoId = (int)($_POST['cliente_id'] ?? $_GET['cliente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteId = (int)($_POST['cliente_id'] ?? 0);
    $orgao = trim($_POST['orgao'] ?? '');
    $numeroParcelamento = trim($_POST['numero_parcelamento'] ?? '');
    $formaEnvio = trim($_POST['forma_envio'] ?? '');
    $dataPrimeiraParcela = $_POST['data_primeira_parcela'] ?? null;
    $parcelasTotal = (int)($_POST['parcelas_total'] ?? 0);
    $parcelasEmitidas = parcelasEmitidasAtual([
        'parcelas_total' => $parcelasTotal,
        'data_primeira_parcela' => $dataPrimeiraParcela,
        'parcelas_emitidas' => 0,
    ]);
    $parcelasAtrasadas = (int)($_POST['parcelas_atrasadas'] ?? 0);

    if (
        $clienteId <= 0 ||
        !array_key_exists($orgao, $orgaosPermitidos) ||
        $numeroParcelamento === '' ||
        $formaEnvio === '' ||
        empty($dataPrimeiraParcela) ||
        $parcelasTotal <= 0 ||
        $parcelasEmitidas < 0 ||
        $parcelasAtrasadas < 0
    ) {
        $erro = 'Preencha todos os campos corretamente.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO parcelamentos (
                cliente_id,
                orgao,
                numero_parcelamento,
                forma_envio,
                data_primeira_parcela,
                parcelas_total,
                parcelas_emitidas,
                parcelas_atrasadas
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $clienteId,
            $orgao,
            $numeroParcelamento,
            $formaEnvio,
            $dataPrimeiraParcela,
            $parcelasTotal,
            $parcelasEmitidas,
            $parcelasAtrasadas,
        ]);

        header('Location: ' . $orgaosPermitidos[$orgao] . '?salvo=1');
        exit;
    }
}

$stmt = $pdo->query("
SELECT id,codigo,nome
FROM clientes
ORDER BY CAST(codigo AS UNSIGNED) ASC
");

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clienteSelecionadoTexto = '';

foreach ($clientes as $clienteLista) {
    if ((int)$clienteLista['id'] === $clienteSelecionadoId) {
        $clienteSelecionadoTexto = $clienteLista['codigo'] . ' - ' . $clienteLista['nome'];
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Novo Parcelamento</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">

        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">
                        Novo Parcelamento
                    </h3>

                    <p class="text-muted mb-0">
                        Cadastre um parcelamento para um cliente
                    </p>
                </div>

                <a href="parcelamentos.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="clientes-box">

                <form id="formParcelamento" method="post">
                    <?php if (!empty($erro)): ?>
                        <div class="alert alert-danger">
                            <?= htmlspecialchars($erro) ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">

                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Cliente
                            </label>

                            <input
                                type="search"
                                class="form-control"
                                id="cliente_busca"
                                list="lista_clientes"
                                value="<?= htmlspecialchars($clienteSelecionadoTexto) ?>"
                                placeholder="Digite o código ou o nome"
                                autocomplete="off">

                            <input
                                type="hidden"
                                name="cliente_id"
                                id="cliente_id"
                                value="<?= $clienteSelecionadoId > 0 ? $clienteSelecionadoId : '' ?>">

                            <datalist id="lista_clientes">
                                <?php foreach ($clientes as $c): ?>
                                    <option
                                        value="<?= htmlspecialchars($c['codigo'] . ' - ' . $c['nome']) ?>"
                                        data-id="<?= (int)$c['id'] ?>">
                                    </option>
                                <?php endforeach; ?>
                            </datalist>

                            <div class="invalid-feedback">
                                Selecione um cliente da lista.
                            </div>

                        </div>


                        <div class="col-md-2 mb-3">

                            <label class="form-label">
                                Órgão
                            </label>

                            <select
                                class="form-select"
                                name="orgao"
                                id="orgao">

                                <option value="">Selecione</option>
                                <option value="Simples Nacional">Simples Nacional</option>
                                <option value="Previdência Social e Tributos">Previdência Social e Tributos</option>
                                <option value="PGFN">PGFN</option>
                                <option value="SEFAZ DF">SEFAZ DF</option>
                                <option value="SEFAZ GO">SEFAZ GO</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Número Parcelamento
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="numero_parcelamento"
                                id="numero_parcelamento">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Forma envio
                            </label>

                            <select
                                class="form-select"
                                name="forma_envio"
                                id="forma_envio">

                                <option value="">Selecione</option>
                                <option value="E-mail">E-mail</option>
                                <option value="WhatsApp">WhatsApp</option>
                                <option value="Em mãos">Em mãos</option>

                            </select>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Data primeira parcela
                            </label>

                            <input
                                type="date"
                                class="form-control"
                                name="data_primeira_parcela"
                                id="data_primeira_parcela">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Total Parcelas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_total"
                                id="parcelas_total"
                                min="1">

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas emitidas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_emitidas"
                                id="parcelas_emitidas"
                                min="0"
                                value="0"
                                readonly>

                        </div>


                        <div class="col-md-3 mb-3">

                            <label class="form-label">
                                Parcelas atrasadas
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="parcelas_atrasadas"
                                id="parcelas_atrasadas"
                                min="0">

                        </div>

                    </div>

                    <div class="mt-3 text-end">

                        <button
                            class="btn btn-success"
                            type="submit">

                            Salvar

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </main>

    <script>
        function atualizarParcelasEmitidas() {
            const campoData = document.getElementById('data_primeira_parcela');
            const campoTotal = document.getElementById('parcelas_total');
            const campoEmitidas = document.getElementById('parcelas_emitidas');
            const total = parseInt(campoTotal.value, 10);

            if (!campoData.value || !total || total < 1) {
                campoEmitidas.value = 0;
                return;
            }

            const partes = campoData.value.split('-').map(Number);
            const inicio = new Date(partes[0], partes[1] - 1, partes[2]);
            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            if (hoje < inicio) {
                campoEmitidas.value = 0;
                return;
            }

            const meses = (hoje.getFullYear() - inicio.getFullYear()) * 12 +
                (hoje.getMonth() - inicio.getMonth());

            campoEmitidas.value = Math.min(meses + 1, total);
        }

        document.getElementById('data_primeira_parcela').addEventListener('change', atualizarParcelasEmitidas);
        document.getElementById('parcelas_total').addEventListener('input', atualizarParcelasEmitidas);

        const camposParcelamento = [
            'cliente_id',
            'orgao',
            'numero_parcelamento',
            'forma_envio',
            'data_primeira_parcela',
            'parcelas_total',
            'parcelas_emitidas',
            'parcelas_atrasadas'
        ];

        const campoBuscaCliente = document.getElementById('cliente_busca');
        const campoClienteId = document.getElementById('cliente_id');
        const opcoesClientes = Array.from(document.querySelectorAll('#lista_clientes option'));

        function sincronizarClienteSelecionado() {
            const opcaoSelecionada = opcoesClientes.find(function(opcao) {
                return opcao.value === campoBuscaCliente.value;
            });

            campoClienteId.value = opcaoSelecionada ? opcaoSelecionada.dataset.id : '';
            campoBuscaCliente.classList.toggle(
                'is-invalid',
                campoBuscaCliente.value !== '' && !opcaoSelecionada
            );
        }

        campoBuscaCliente.addEventListener('input', sincronizarClienteSelecionado);
        campoBuscaCliente.addEventListener('change', sincronizarClienteSelecionado);

        camposParcelamento.forEach(function(id) {
            const campo = document.getElementById(id);

            if (!campo) {
                console.warn('Campo não encontrado:', id);
                return;
            }

            campo.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });

            campo.addEventListener('change', function() {
                this.classList.remove('is-invalid');
            });
        });

        document.getElementById('formParcelamento').addEventListener('submit', function(e) {
            e.preventDefault();

            let valido = true;

            camposParcelamento.forEach(function(id) {
                const campo = document.getElementById(id);

                if (!campo) {
                    console.warn('Campo não encontrado:', id);
                    valido = false;
                    return;
                }

                if (!campo.value.trim()) {
                    const campoComErro = id === 'cliente_id' ? campoBuscaCliente : campo;
                    campoComErro.classList.add('is-invalid');
                    valido = false;
                }
            });

            if (!valido) {
                return;
            }

            this.submit();
        });
    </script>

</body>

</html>