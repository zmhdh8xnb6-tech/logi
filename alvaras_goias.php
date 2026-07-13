<?php
require 'config.php';

exigirPermissao('alvaras');

$stmt = $pdo->query("
    SELECT id, codigo, documento, nome, uf, alvara, cadastro_df_legal
    FROM clientes
    WHERE cliente_contabil = 1
      AND (
        uf = 'GO'
        OR alvara = 'goias'
        OR cadastro_df_legal = 'goias'
      )
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");
$clientesGoias = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Alvarás Goiás</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Alvarás Goiás</h3>
                    <p class="text-muted mb-0">Clientes vinculados ao estado de Goiás</p>
                </div>

                <a href="alvaras.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row mb-3">
                <div class="col-md-5">
                    <input type="text" id="buscaAlvaraGoias" class="form-control" placeholder="Buscar por código, CNPJ/CPF ou cliente...">
                </div>
            </div>

            <div class="clientes-box">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Cliente</th>
                                <th>UF</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientesGoias)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Nenhum cliente de Goiás encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clientesGoias as $cliente): ?>
                                    <tr class="linha-cliente-goias">
                                        <td class="codigo-cliente"><?= htmlspecialchars($cliente['codigo']) ?></td>
                                        <td class="documento-cliente"><?= htmlspecialchars($cliente['documento']) ?></td>
                                        <td class="nome-cliente"><?= htmlspecialchars($cliente['nome']) ?></td>
                                        <td><?= htmlspecialchars($cliente['uf'] ?: 'GO') ?></td>
                                        <td><span class="badge bg-info text-dark">Goiás</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="alvarasGoiasVazio" class="d-none">
                                <td colspan="5" class="text-center text-muted py-4">Nenhum cliente encontrado.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3" id="paginacaoAlvarasGoias"></div>
            </div>
        </div>
    </main>

    <script>
        const alvarasGoiasPorPagina = 15;
        let alvarasGoiasPaginaAtual = 1;
        const buscaAlvaraGoias = document.getElementById('buscaAlvaraGoias');
        const linhasAlvarasGoias = Array.from(document.querySelectorAll('.linha-cliente-goias'));
        const paginacaoAlvarasGoias = document.getElementById('paginacaoAlvarasGoias');
        const alvarasGoiasVazio = document.getElementById('alvarasGoiasVazio');

        function alvarasGoiasFiltradas() {
            const busca = buscaAlvaraGoias.value.toLocaleLowerCase('pt-BR');

            return linhasAlvarasGoias.filter(function(linha) {
                const texto = [
                    linha.querySelector('.codigo-cliente').textContent,
                    linha.querySelector('.documento-cliente').textContent,
                    linha.querySelector('.nome-cliente').textContent
                ].join(' ').toLocaleLowerCase('pt-BR');

                return texto.includes(busca);
            });
        }

        function adicionarPaginaAlvarasGoias(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                alvarasGoiasPaginaAtual = pagina;
                renderizarAlvarasGoias();
            });

            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarAlvarasGoias() {
            const filtradas = alvarasGoiasFiltradas();
            const totalPaginas = Math.max(1, Math.ceil(filtradas.length / alvarasGoiasPorPagina));

            if (alvarasGoiasPaginaAtual > totalPaginas) {
                alvarasGoiasPaginaAtual = totalPaginas;
            }

            const inicio = (alvarasGoiasPaginaAtual - 1) * alvarasGoiasPorPagina;
            const visiveis = new Set(filtradas.slice(inicio, inicio + alvarasGoiasPorPagina));

            linhasAlvarasGoias.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            alvarasGoiasVazio.classList.toggle('d-none', filtradas.length > 0);
            paginacaoAlvarasGoias.innerHTML = '';

            if (filtradas.length <= alvarasGoiasPorPagina) {
                return;
            }

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            adicionarPaginaAlvarasGoias(lista, 'Anterior', Math.max(1, alvarasGoiasPaginaAtual - 1), alvarasGoiasPaginaAtual <= 1, false);

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                adicionarPaginaAlvarasGoias(lista, String(pagina), pagina, false, pagina === alvarasGoiasPaginaAtual);
            }

            adicionarPaginaAlvarasGoias(lista, 'Próxima', Math.min(totalPaginas, alvarasGoiasPaginaAtual + 1), alvarasGoiasPaginaAtual >= totalPaginas, false);

            nav.appendChild(lista);
            paginacaoAlvarasGoias.appendChild(nav);
        }

        document.getElementById('buscaAlvaraGoias').addEventListener('input', function() {
            alvarasGoiasPaginaAtual = 1;
            renderizarAlvarasGoias();
        });

        renderizarAlvarasGoias();
    </script>

</body>

</html>