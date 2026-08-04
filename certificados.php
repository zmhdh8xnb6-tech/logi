<?php
require 'config.php';

exigirPermissao('certificados');

$paralisacaoCertificadoDisponivel = logiColunaExiste($pdo, 'clientes', 'paralisacao_status')
    && logiColunaExiste($pdo, 'clientes', 'paralisacao_fim');
$certificadoStatusDisponivel = logiColunaExiste($pdo, 'clientes', 'certificado_status');

$stmt = $pdo->query("
    SELECT *
    FROM clientes
    WHERE (servico_certificado = 1
       OR cliente_contabil = 1)
    " . clientesFiltroAtivos($pdo) . "
    " . empresaFiltroClienteDireto($pdo) . "
    ORDER BY CAST(codigo AS UNSIGNED) ASC, nome ASC
");

$certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);

function certificadoClienteParalisado(array $cliente): bool
{
    return ($cliente['paralisacao_status'] ?? '') === 'paralisada'
        && (empty($cliente['paralisacao_fim']) || $cliente['paralisacao_fim'] >= date('Y-m-d'));
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Certificados</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Certificados Digitais</h3>
                    <p class="text-muted mb-0">Acompanhe os vencimentos dos certificados digitais dos clientes</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="row mb-3">

                <div class="col-md-4">
                    <input
                        type="text"
                        id="buscaCertificado"
                        class="form-control"
                        placeholder="Buscar por código ou CNPJ...">
                </div>

            </div>

            <div class="clientes-box">

                <div class="table-responsive">

                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>CNPJ/CPF</th>
                                <th>Cliente</th>
                                <th>Vínculo</th>
                                <th>Status</th>
                                <th>Vencimento</th>
                                <th>Dias restantes</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($certificados as $cliente):

                                $temCertificado = !empty($cliente['vencimento_certificado']);
                                $certificadoStatus = $certificadoStatusDisponivel
                                    ? ($cliente['certificado_status'] ?? ($temCertificado ? 'possui' : 'nao_possui'))
                                    : ($temCertificado ? 'possui' : 'nao_possui');
                                $certificadoNaoPrecisa = $certificadoStatus === 'nao_precisa_momento';
                                $clienteParalisado = $paralisacaoCertificadoDisponivel && certificadoClienteParalisado($cliente);
                                $diasRestantes = null;
                                $certificadoVencido = false;

                                if ($temCertificado) {
                                    $hoje = new DateTime(date('Y-m-d'));
                                    $vencimento = new DateTime($cliente['vencimento_certificado']);

                                    $dias = $hoje->diff($vencimento);
                                    $diasRestantes = (int)$dias->format('%r%a');
                                    $certificadoVencido = $diasRestantes < 0;
                                }

                            ?>

                                <tr class="linha-cliente">

                                    <td class="codigo-cliente">
                                        <?= htmlspecialchars($cliente['codigo']) ?>
                                    </td>

                                    <td class="doc-cliente">
                                        <?= htmlspecialchars($cliente['documento']) ?>
                                    </td>

                                    <td class="nome-cliente">
                                        <?= htmlspecialchars($cliente['nome']) ?>
                                    </td>

                                    <td>
                                        <span class="badge <?= !empty($cliente['cliente_contabil']) ? 'bg-success' : 'bg-info text-dark' ?>">
                                            <?= !empty($cliente['cliente_contabil']) ? 'Cliente contábil' : 'Serviço avulso' ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge certificado-status <?= $clienteParalisado ? 'bg-secondary' : ($certificadoNaoPrecisa ? 'bg-info text-dark' : ($certificadoVencido ? 'bg-danger' : ($temCertificado ? 'bg-success' : 'bg-danger'))) ?>">
                                            <?= $clienteParalisado ? 'Empresa paralisada' : ($certificadoNaoPrecisa ? 'Não precisa no momento' : ($certificadoVencido ? 'Vencido' : ($temCertificado ? 'Possui' : 'Não possui'))) ?>
                                        </span>
                                    </td>

                                    <td class="vencimento-certificado" data-valor="<?= htmlspecialchars($cliente['vencimento_certificado'] ?? '') ?>">
                                        <?= $temCertificado ? date('d/m/Y', strtotime($cliente['vencimento_certificado'])) : '-' ?>
                                    </td>

                                    <td class="dias-certificado">
                                        <?php if ($clienteParalisado): ?>

                                            <span class="badge bg-secondary">
                                                Não se aplica
                                            </span>

                                        <?php elseif ($certificadoNaoPrecisa): ?>

                                            <span class="badge bg-info text-dark">
                                                Não se aplica
                                            </span>

                                        <?php elseif (!$temCertificado): ?>

                                            <span class="badge bg-secondary">
                                                Sem vencimento
                                            </span>

                                        <?php elseif ($diasRestantes < 0): ?>

                                            <span class="badge bg-danger">
                                                Vencido há <?= abs($diasRestantes) ?> dias
                                            </span>

                                        <?php elseif ($diasRestantes == 0): ?>

                                            <span class="badge bg-danger">
                                                Vence hoje
                                            </span>

                                        <?php elseif ($diasRestantes <= 15): ?>

                                            <span class="badge bg-danger-subtle text-dark">
                                                <?= $diasRestantes ?>
                                                <?= $diasRestantes === 1 ? 'dia para vencer' : 'dias para vencer' ?>
                                            </span>

                                        <?php elseif ($diasRestantes <= 30): ?>

                                            <span class="badge bg-warning text-dark">
                                                <?= $diasRestantes ?>
                                                <?= $diasRestantes === 1 ? 'dia para vencer' : 'dias para vencer' ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-success">
                                                <?= $diasRestantes ?>
                                                <?= $diasRestantes === 1 ? 'dia para vencer' : 'dias para vencer' ?>
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm btn-editar-certificado"
                                            data-id="<?= (int)$cliente['id'] ?>"
                                            data-cliente="<?= htmlspecialchars($cliente['codigo'] . ' - ' . $cliente['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($certificadoStatus) ?>"
                                            data-vencimento="<?= htmlspecialchars($cliente['vencimento_certificado'] ?? '') ?>"
                                            data-paralisada="<?= $clienteParalisado ? '1' : '0' ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            <tr id="certificadosVazio" class="d-none">
                                <td colspan="8" class="text-center text-muted py-4">
                                    Nenhum certificado encontrado.
                                </td>
                            </tr>

                        </tbody>

                    </table>

                </div>

                <div class="mt-3" id="paginacaoCertificados"></div>

            </div>

        </div>
    </main>

    <div class="modal fade" id="modalEditarCertificado" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar certificado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="modalCertificadoId">

                    <div class="mb-3">
                        <label class="form-label">Cliente</label>
                        <input type="text" class="form-control" id="modalCertificadoCliente" disabled>
                    </div>

                    <?php if ($certificadoStatusDisponivel): ?>
                        <div class="mb-3">
                            <label for="modalCertificadoStatus" class="form-label">Status</label>
                            <select class="form-select" id="modalCertificadoStatus">
                                <option value="possui">Possui</option>
                                <option value="nao_possui">Não possui</option>
                                <option value="nao_precisa_momento">Não precisa no momento</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3" id="grupoModalCertificadoVencimento">
                        <label for="modalCertificadoVencimento" class="form-label">Vencimento</label>
                        <input type="date" class="form-control" id="modalCertificadoVencimento">
                        <div class="form-text">Deixe em branco para marcar como não possui.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnSalvarModalCertificado">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let certificadosPorPagina = Number(localStorage.getItem('certificadosPorPagina') || 15);
        certificadosPorPagina = [15, 30, 60, 90].includes(certificadosPorPagina) ? certificadosPorPagina : 15;
        let certificadosPaginaAtual = 1;
        const buscaCertificado = document.getElementById('buscaCertificado');
        const linhasCertificados = Array.from(document.querySelectorAll('.linha-cliente'));
        const paginacaoCertificados = document.getElementById('paginacaoCertificados');
        const certificadosVazio = document.getElementById('certificadosVazio');
        const modalEditarCertificado = document.getElementById('modalEditarCertificado');
        const grupoModalCertificadoVencimento = document.getElementById('grupoModalCertificadoVencimento');
        const campoModalCertificadoVencimento = document.getElementById('modalCertificadoVencimento');
        const campoModalCertificadoStatus = document.getElementById('modalCertificadoStatus');
        const botaoSalvarModalCertificado = document.getElementById('btnSalvarModalCertificado');
        let salvandoCertificado = false;
        let vencimentoCertificadoInicial = '';

        function certificadosFiltrados() {
            const valor = buscaCertificado.value.trim().toLowerCase();

            if (!valor) {
                return linhasCertificados;
            }

            return linhasCertificados.filter(function(linha) {
                const codigo = linha.querySelector('.codigo-cliente').textContent.toLowerCase();
                const nome = linha.querySelector('.nome-cliente').textContent.toLowerCase();
                const documento = linha.querySelector('.doc-cliente').textContent.toLowerCase();

                return nome.includes(valor) || documento.includes(valor) || codigo.includes(valor);
            });
        }

        function adicionarPaginaCertificado(lista, rotulo, pagina, desabilitado, ativo) {
            const item = document.createElement('li');
            item.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');

            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'page-link';
            botao.textContent = rotulo;
            botao.disabled = desabilitado;
            botao.addEventListener('click', function() {
                certificadosPaginaAtual = pagina;
                renderizarCertificados();
            });

            item.appendChild(botao);
            lista.appendChild(item);
        }

        function renderizarCertificados() {
            const filtradas = certificadosFiltrados();
            const totalPaginas = Math.max(1, Math.ceil(filtradas.length / certificadosPorPagina));

            if (certificadosPaginaAtual > totalPaginas) {
                certificadosPaginaAtual = totalPaginas;
            }

            const inicio = (certificadosPaginaAtual - 1) * certificadosPorPagina;
            const visiveis = new Set(filtradas.slice(inicio, inicio + certificadosPorPagina));

            linhasCertificados.forEach(function(linha) {
                linha.classList.toggle('d-none', !visiveis.has(linha));
            });

            certificadosVazio.classList.toggle('d-none', filtradas.length > 0);
            paginacaoCertificados.innerHTML = '';

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
            campoLimite.value = String(certificadosPorPagina);
            campoLimite.addEventListener('change', function() {
                certificadosPorPagina = Number(campoLimite.value);
                localStorage.setItem('certificadosPorPagina', String(certificadosPorPagina));
                certificadosPaginaAtual = 1;
                renderizarCertificados();
            });
            if (filtradas.length <= certificadosPorPagina) {

                return;

            }


            paginacaoCertificados.appendChild(seletorLimite);

            const nav = document.createElement('nav');
            const lista = document.createElement('ul');
            lista.className = 'pagination justify-content-center mt-3';

            adicionarPaginaCertificado(lista, 'Anterior', Math.max(1, certificadosPaginaAtual - 1), certificadosPaginaAtual <= 1, false);

            const paginasVisiveis = [];
            let ultimaPagina = 0;

            for (let pagina = 1; pagina <= totalPaginas; pagina++) {
                if (pagina === 1 || pagina === totalPaginas || Math.abs(pagina - certificadosPaginaAtual) <= 2) {
                    if (ultimaPagina && pagina - ultimaPagina > 1) {
                        paginasVisiveis.push('...');
                    }

                    paginasVisiveis.push(pagina);
                    ultimaPagina = pagina;
                }
            }

            paginasVisiveis.forEach(function(pagina) {
                if (pagina === '...') {
                    adicionarPaginaCertificado(lista, '...', certificadosPaginaAtual, true, false);
                    return;
                }

                adicionarPaginaCertificado(lista, String(pagina), pagina, false, pagina === certificadosPaginaAtual);
            });

            adicionarPaginaCertificado(lista, 'Próxima', Math.min(totalPaginas, certificadosPaginaAtual + 1), certificadosPaginaAtual >= totalPaginas, false);

            nav.appendChild(lista);
            paginacaoCertificados.appendChild(nav);
        }

        buscaCertificado.addEventListener('input', function() {
            certificadosPaginaAtual = 1;
            renderizarCertificados();
        });

        renderizarCertificados();
    </script>

    <script>
        let linhaCertificadoAtual = null;
        let botaoCertificadoAtual = null;

        function textoDiasCertificado(vencimento) {
            if (!vencimento) {
                return '<span class="badge bg-secondary">Sem vencimento</span>';
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
            const diferenca = Math.round((data - hoje) / 86400000);

            if (diferenca < 0) {
                return '<span class="badge bg-danger">Vencido há ' + Math.abs(diferenca) + ' dias</span>';
            }

            if (diferenca === 0) {
                return '<span class="badge bg-danger">Vence hoje</span>';
            }

            if (diferenca <= 15) {
                return '<span class="badge bg-danger-subtle text-dark">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
            }

            if (diferenca <= 30) {
                return '<span class="badge bg-warning text-dark">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
            }

            return '<span class="badge bg-success">' + diferenca + (diferenca === 1 ? ' dia para vencer' : ' dias para vencer') + '</span>';
        }

        function formatarDataCertificado(data) {
            if (!data) {
                return '-';
            }

            const partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }

        function certificadoEstaVencido(vencimento) {
            if (!vencimento) {
                return false;
            }

            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);
            const partes = vencimento.split('-');
            const data = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));

            return data < hoje;
        }

        function atualizarCampoVencimentoCertificado() {
            if (!campoModalCertificadoStatus) {
                return;
            }

            const possui = campoModalCertificadoStatus.value === 'possui';
            grupoModalCertificadoVencimento.classList.toggle('d-none', !possui);
            campoModalCertificadoVencimento.disabled = !possui;

            if (!possui) {
                campoModalCertificadoVencimento.value = '';
            }
        }

        if (campoModalCertificadoStatus) {
            campoModalCertificadoStatus.addEventListener('change', atualizarCampoVencimentoCertificado);
        }

        document.querySelectorAll('.btn-editar-certificado').forEach(function(botao) {
            botao.addEventListener('click', function() {
                linhaCertificadoAtual = this.closest('tr');
                botaoCertificadoAtual = this;

                document.getElementById('modalCertificadoId').value = this.dataset.id;
                document.getElementById('modalCertificadoCliente').value = this.dataset.cliente;
                vencimentoCertificadoInicial = this.dataset.vencimento || '';
                campoModalCertificadoVencimento.value = vencimentoCertificadoInicial;
                if (campoModalCertificadoStatus) {
                    campoModalCertificadoStatus.value = this.dataset.status || (vencimentoCertificadoInicial ? 'possui' : 'nao_possui');
                    atualizarCampoVencimentoCertificado();
                }

                if (window.sincronizarCalendarioCampo) {
                    window.sincronizarCalendarioCampo(campoModalCertificadoVencimento);
                }

                const modal = new bootstrap.Modal(modalEditarCertificado);
                modal.show();
            });
        });

        function salvarModalCertificado() {
            if (salvandoCertificado) {
                return;
            }

            const id = document.getElementById('modalCertificadoId').value;
            const vencimento = campoModalCertificadoVencimento.value;
            const certificadoStatus = campoModalCertificadoStatus ? campoModalCertificadoStatus.value : (vencimento ? 'possui' : 'nao_possui');
            const botao = botaoSalvarModalCertificado;

            salvandoCertificado = true;
            botao.disabled = true;
            botao.innerHTML = 'Salvando...';

            fetch('api_certificados.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id) +
                        '&certificado_status=' + encodeURIComponent(certificadoStatus) +
                        '&vencimento_certificado=' + encodeURIComponent(vencimento)
                })
                .then(response => response.text())
                .then(resp => {
                    if (resp.trim() === 'ok') {
                        const status = linhaCertificadoAtual.querySelector('.certificado-status');
                        const vencimentoTexto = linhaCertificadoAtual.querySelector('.vencimento-certificado');
                        const dias = linhaCertificadoAtual.querySelector('.dias-certificado');
                        const clienteParalisado = botaoCertificadoAtual.dataset.paralisada === '1';

                        if (clienteParalisado) {
                            status.className = 'badge certificado-status bg-secondary';
                            status.textContent = 'Empresa paralisada';
                        } else if (certificadoStatus === 'nao_precisa_momento') {
                            status.className = 'badge certificado-status bg-info text-dark';
                            status.textContent = 'Não precisa no momento';
                        } else if (certificadoEstaVencido(vencimento)) {
                            status.className = 'badge certificado-status bg-danger';
                            status.textContent = 'Vencido';
                        } else if (vencimento) {
                            status.className = 'badge certificado-status bg-success';
                            status.textContent = 'Possui';
                        } else {
                            status.className = 'badge certificado-status bg-danger';
                            status.textContent = 'Não possui';
                        }

                        vencimentoTexto.dataset.valor = vencimento;
                        vencimentoTexto.textContent = formatarDataCertificado(vencimento);
                        dias.innerHTML = clienteParalisado ?
                            '<span class="badge bg-secondary">Não se aplica</span>' :
                            certificadoStatus === 'nao_precisa_momento' ?
                            '<span class="badge bg-info text-dark">Não se aplica</span>' :
                            textoDiasCertificado(vencimento);
                        botaoCertificadoAtual.dataset.vencimento = vencimento;
                        botaoCertificadoAtual.dataset.status = certificadoStatus;
                        vencimentoCertificadoInicial = vencimento;

                        bootstrap.Modal.getInstance(modalEditarCertificado).hide();
                    } else {
                        botao.innerHTML = 'Erro';
                        const mensagem = resp.trim();

                        if (mensagem === 'certificado_status_coluna_ausente') {
                            alert('O banco ainda não tem a coluna certificado_status. Rode o SQL de atualização antes de usar "Não precisa no momento" em certificados.');
                        } else if (mensagem === 'certificado_status_coluna_desatualizada') {
                            alert('A coluna certificado_status existe, mas ainda não aceita "Não precisa no momento". Rode o SQL de atualização do certificado_status.');
                        } else if (mensagem === 'erro_salvar_certificado') {
                            alert('Não foi possível salvar o certificado. Verifique se o banco está atualizado.');
                        }
                    }
                })
                .catch(() => {
                    botao.innerHTML = 'Erro';
                })
                .finally(() => {
                    setTimeout(() => {
                        salvandoCertificado = false;
                        botao.disabled = false;
                        botao.innerHTML = 'Salvar';
                    }, 600);
                });
        }

        botaoSalvarModalCertificado.addEventListener('click', salvarModalCertificado);

        modalEditarCertificado.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                salvarModalCertificado();
            }
        });
    </script>

</body>

</html>