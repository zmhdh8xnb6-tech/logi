<?php
require 'config.php';

exigirPermissao('clientes');

$filtroAtivos = clientesFiltroAtivos($pdo);

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE cliente_contabil = 0
    {$filtroAtivos}
    " . empresaFiltroClienteDireto($pdo) . "
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$cadastrosAvulsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Serviços Avulsos</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Serviços Avulsos</h3>
                    <p class="text-muted mb-0">Empresas que ainda não são clientes contábeis</p>
                </div>

                <a href="clientes.php" class="btn btn-outline-secondary">
                    <i class="bi bi-people"></i> Clientes contábeis
                </a>
            </div>

            <div class="row mb-3">
                <div class="col-md-5">
                    <input
                        type="text"
                        id="buscaServicoAvulso"
                        class="form-control"
                        placeholder="Buscar por código, CNPJ/CPF ou empresa...">
                </div>
            </div>

            <div class="clientes-box">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Empresa</th>
                                <th>Serviços</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cadastrosAvulsos)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Nenhum serviço avulso cadastrado.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cadastrosAvulsos as $cadastro): ?>
                                    <tr class="linha-servico-avulso">
                                        <td class="codigo-avulso"><?= htmlspecialchars($cadastro['codigo']) ?></td>
                                        <td class="documento-avulso"><?= htmlspecialchars($cadastro['documento']) ?></td>
                                        <td class="nome-avulso">
                                            <strong><?= htmlspecialchars($cadastro['nome']) ?></strong>
                                            <?php if (!empty($cadastro['nome_fantasia'])): ?>
                                                <small class="text-muted d-block"><?= htmlspecialchars($cadastro['nome_fantasia']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php if (!empty($cadastro['servico_parcelamento'])): ?>
                                                    <span class="badge bg-primary">Parcelamento</span>
                                                <?php endif; ?>

                                                <?php if (!empty($cadastro['servico_certificado'])): ?>
                                                    <span class="badge bg-success">Certificado Digital</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="cliente.php?id=<?= (int)$cadastro['id'] ?>" class="btn btn-outline-primary btn-sm" title="Visualizar">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="cliente_editar.php?id=<?= (int)$cadastro['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="servicosAvulsosVazio" class="d-none">
                                <td colspan="5" class="text-center text-muted py-4">
                                    Nenhum serviço avulso encontrado.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacaoServicosAvulsos"></div>
            </div>
        </div>
    </main>

    <script>
        let servicosAvulsosPorPagina = Number(localStorage.getItem('servicosAvulsosPorPagina') || 15);
        servicosAvulsosPorPagina = [15, 30, 60, 90].includes(servicosAvulsosPorPagina) ? servicosAvulsosPorPagina : 15;
        let servicosAvulsosPaginaAtual = 1;
        const buscaServicoAvulso = document.getElementById('buscaServicoAvulso');
        const linhasServicosAvulsos = Array.from(document.querySelectorAll('.linha-servico-avulso'));
        const paginacaoServicosAvulsos = document.getElementById('paginacaoServicosAvulsos');
        const servicosAvulsosVazio = document.getElementById('servicosAvulsosVazio');

        function servicosAvulsosFiltrados() {
            const busca = buscaServicoAvulso.value.toLocaleLowerCase('pt-BR');

            return linhasServicosAvulsos.filter(function(linha) {
                const texto = [
                    linha.querySelector('.codigo-avulso').textContent,
                    linha.querySelector('.documento-avulso').textContent,
                    linha.querySelector('.nome-avulso').textContent
                ].join(' ').toLocaleLowerCase('pt-BR');

                return texto.includes(busca);
            });
        }

        function adicionarPaginaServicosAvulsos(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                servicosAvulsosPaginaAtual = pagina;
                renderizarServicosAvulsos();
            });

            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarServicosAvulsos() {
            const filtrados = servicosAvulsosFiltrados();
            const totalPaginas = Math.max(1, Math.ceil(filtrados.length / servicosAvulsosPorPagina));

            if (servicosAvulsosPaginaAtual > totalPaginas) {
                servicosAvulsosPaginaAtual = totalPaginas;
            }

            const inicio = (servicosAvulsosPaginaAtual - 1) * servicosAvulsosPorPagina;
            const visiveis = new Set(filtrados.slice(inicio, inicio + servicosAvulsosPorPagina));

            linhasServicosAvulsos.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            servicosAvulsosVazio.classList.toggle('d-none', filtrados.length > 0);
            paginacaoServicosAvulsos.innerHTML = '';

            const seletorLimite = document.createElement('div');
            seletorLimite.className = 'd-flex justify-content-end mb-2';
            seletorLimite.innerHTML = `
                <select class="form-select form-select-sm w-auto" aria-label="Itens por página">
                    <option value="15">Mostrar 15</option>
                    <option value="30">Mostrar 30</option>
                    <option value="60">Mostrar 60</option>
                    <option value="90">Mostrar 90</option>
                </select>
            `;
            const campoLimite = seletorLimite.querySelector('select');
            campoLimite.value = String(servicosAvulsosPorPagina);
            campoLimite.addEventListener('change', function() {
                servicosAvulsosPorPagina = Number(campoLimite.value);
                localStorage.setItem('servicosAvulsosPorPagina', String(servicosAvulsosPorPagina));
                servicosAvulsosPaginaAtual = 1;
                renderizarServicosAvulsos();
            });
            paginacaoServicosAvulsos.appendChild(seletorLimite);

            if (filtrados.length <= servicosAvulsosPorPagina) {
                return;
            }

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            adicionarPaginaServicosAvulsos(lista, 'Anterior', Math.max(1, servicosAvulsosPaginaAtual - 1), servicosAvulsosPaginaAtual <= 1, false);

            const paginasVisiveis = [];
            let ultimaPagina = 0;

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - servicosAvulsosPaginaAtual) <= 2) {
                    if (ultimaPagina && pagina - ultimaPagina > 1) {
                        paginasVisiveis.push('...');
                    }

                    paginasVisiveis.push(pagina);
                    ultimaPagina = pagina;
                }
            }

            paginasVisiveis.forEach(function(pagina) {
                if (pagina === '...') {
                    adicionarPaginaServicosAvulsos(lista, '...', servicosAvulsosPaginaAtual, true, false);
                    return;
                }

                adicionarPaginaServicosAvulsos(lista, String(pagina), pagina, false, pagina === servicosAvulsosPaginaAtual);
            });

            adicionarPaginaServicosAvulsos(lista, 'Próxima', Math.min(totalPaginas, servicosAvulsosPaginaAtual + 1), servicosAvulsosPaginaAtual >= totalPaginas, false);

            nav.appendChild(lista);
            paginacaoServicosAvulsos.appendChild(nav);
        }

        document.getElementById('buscaServicoAvulso').addEventListener('input', function() {
            servicosAvulsosPaginaAtual = 1;
            renderizarServicosAvulsos();
        });

        renderizarServicosAvulsos();
    </script>

</body>

</html>