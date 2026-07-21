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
                " . empresaInsertColuna($pdo, 'parcelamentos') . "
                cliente_id,
                orgao,
                numero_parcelamento,
                forma_envio,
                data_primeira_parcela,
                parcelas_total,
                parcelas_emitidas,
                parcelas_atrasadas
            )
            VALUES (" . empresaInsertPlaceholder($pdo, 'parcelamentos') . "?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute(array_merge(
            empresaInsertValores($pdo, 'parcelamentos'),
            [
                $clienteId,
                $orgao,
                $numeroParcelamento,
                $formaEnvio,
                $dataPrimeiraParcela,
                $parcelasTotal,
                $parcelasEmitidas,
                $parcelasAtrasadas,
            ]
        ));

        $parcelamentoId = (int)$pdo->lastInsertId();
        registrarAuditoria(
            $pdo,
            'Parcelamentos',
            'criar',
            'parcelamento',
            $parcelamentoId,
            'Cadastrou parcelamento de ' . $orgao . ' para o cliente #' . $clienteId,
            null,
            [
                'cliente_id' => $clienteId,
                'orgao' => $orgao,
                'numero_parcelamento' => $numeroParcelamento,
                'forma_envio' => $formaEnvio,
                'data_primeira_parcela' => $dataPrimeiraParcela,
                'parcelas_total' => $parcelasTotal,
                'parcelas_emitidas' => $parcelasEmitidas,
                'parcelas_atrasadas' => $parcelasAtrasadas,
            ]
        );

        header('Location: ' . $orgaosPermitidos[$orgao] . '?salvo=1');
        exit;
    }
}

$stmt = $pdo->query("
SELECT id,codigo,nome
FROM clientes
WHERE 1 = 1
" . empresaFiltroClienteDireto($pdo) . "
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

                            <div class="cliente-seletor" id="clienteSeletor">
                                <i class="bi bi-search cliente-seletor-icone"></i>
                                <input
                                    type="search"
                                    class="form-control cliente-seletor-input"
                                    id="cliente_busca"
                                    placeholder="Digite o código ou a razão social"
                                    value="<?= htmlspecialchars($clienteSelecionadoTexto) ?>"
                                    autocomplete="off"
                                    aria-haspopup="listbox"
                                    aria-expanded="false">

                                <div class="cliente-seletor-menu d-none" id="clienteSeletorMenu">
                                    <div class="cliente-seletor-opcoes" id="clienteSeletorOpcoes" role="listbox">
                                        <?php foreach ($clientes as $c):
                                            $textoCliente = $c['codigo'] . ' - ' . $c['nome'];
                                        ?>
                                            <button
                                                type="button"
                                                class="cliente-seletor-opcao<?= (int)$c['id'] === $clienteSelecionadoId ? ' selecionado' : '' ?>"
                                                data-id="<?= (int)$c['id'] ?>"
                                                data-texto="<?= htmlspecialchars($textoCliente) ?>"
                                                role="option"
                                                aria-selected="<?= (int)$c['id'] === $clienteSelecionadoId ? 'true' : 'false' ?>">
                                                <strong><?= htmlspecialchars($c['codigo']) ?></strong>
                                                <span><?= htmlspecialchars($c['nome']) ?></span>
                                            </button>
                                        <?php endforeach; ?>

                                        <div class="cliente-seletor-vazio d-none" id="clienteSeletorVazio">
                                            Nenhum cliente encontrado.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <input
                                type="hidden"
                                name="cliente_id"
                                id="cliente_id"
                                value="<?= $clienteSelecionadoId > 0 ? $clienteSelecionadoId : '' ?>">

                            <div class="invalid-feedback" id="clienteFeedback">
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

        const seletorCliente = document.getElementById('clienteSeletor');
        const campoBuscaCliente = document.getElementById('cliente_busca');
        const menuCliente = document.getElementById('clienteSeletorMenu');
        const campoClienteId = document.getElementById('cliente_id');
        const feedbackCliente = document.getElementById('clienteFeedback');
        const avisoClienteVazio = document.getElementById('clienteSeletorVazio');
        const opcoesClientes = Array.from(document.querySelectorAll('.cliente-seletor-opcao'));

        function normalizarBusca(texto) {
            return texto
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        }

        function filtrarClientes() {
            const busca = normalizarBusca(campoBuscaCliente.value);
            let totalVisivel = 0;

            opcoesClientes.forEach(function(opcao) {
                const visivel = normalizarBusca(opcao.dataset.texto).includes(busca);
                opcao.classList.toggle('d-none', !visivel);
                totalVisivel += visivel ? 1 : 0;
            });

            avisoClienteVazio.classList.toggle('d-none', totalVisivel > 0);
        }

        function abrirListaClientes() {
            menuCliente.classList.remove('d-none');
            campoBuscaCliente.setAttribute('aria-expanded', 'true');
            filtrarClientes();
        }

        function fecharListaClientes() {
            menuCliente.classList.add('d-none');
            campoBuscaCliente.setAttribute('aria-expanded', 'false');
        }

        function selecionarCliente(opcao) {
            campoClienteId.value = opcao.dataset.id;
            campoBuscaCliente.value = opcao.dataset.texto;
            campoBuscaCliente.classList.remove('is-invalid');
            feedbackCliente.classList.remove('d-block');

            opcoesClientes.forEach(function(item) {
                const selecionado = item === opcao;
                item.classList.toggle('selecionado', selecionado);
                item.setAttribute('aria-selected', selecionado ? 'true' : 'false');
            });

            fecharListaClientes();
        }

        campoBuscaCliente.addEventListener('focus', abrirListaClientes);

        campoBuscaCliente.addEventListener('input', function() {
            campoClienteId.value = '';
            campoBuscaCliente.classList.remove('is-invalid');
            feedbackCliente.classList.remove('d-block');
            abrirListaClientes();
        });

        campoBuscaCliente.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                fecharListaClientes();
                campoBuscaCliente.blur();
            }
        });

        opcoesClientes.forEach(function(opcao) {
            opcao.addEventListener('click', function() {
                selecionarCliente(opcao);
            });
        });

        document.addEventListener('click', function(event) {
            if (!seletorCliente.contains(event.target)) {
                fecharListaClientes();
            }
        });

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

                    if (id === 'cliente_id') {
                        feedbackCliente.classList.add('d-block');
                    }

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