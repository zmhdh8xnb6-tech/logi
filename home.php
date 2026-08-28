<?php
require 'config.php';
require_once 'includes/parcelamentos_funcoes.php';
require_once 'includes/avisos_vencimentos.php';

exigirLogin();

$empresaSomenteServicoAvulso = strcasecmp(trim(empresaAtivaNome($pdo)), 'MAXWELL') === 0;

$resumoTarefas = [
    'pendentes' => 0,
    'concluidas_hoje' => 0,
];
$avisosSistema = [];

if (usuarioPode('tarefas')) {
    try {
        $stmtTabelaTarefas = $pdo->query("SHOW TABLES LIKE 'tarefas'");

        if ($stmtTabelaTarefas->fetchColumn()) {
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
            $hoje = date('Y-m-d');

            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN concluida = 0 THEN 1 ELSE 0 END) AS pendentes,
                    SUM(CASE WHEN concluida = 1 AND DATE(concluida_em) = ? THEN 1 ELSE 0 END) AS concluidas_hoje
                FROM tarefas
                WHERE usuario_id = ?
                " . empresaFiltro($pdo, 'tarefas') . "
            ");
            $stmt->execute([$hoje, $usuarioId]);
            $resumoTarefas = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $resumoTarefas);
        }
    } catch (Throwable $e) {
        $resumoTarefas = [
            'pendentes' => 0,
            'concluidas_hoje' => 0,
        ];
    }
}

if (usuarioPode('parcelamentos')) {
    try {
        $avisosSistema = array_merge($avisosSistema, avisosParcelamentosImpressao($pdo));
    } catch (Throwable $e) {
        $avisosSistema = $avisosSistema;
    }
}

if (usuarioPode('pendencias')) {
    try {
        $avisosSistema = array_merge($avisosSistema, listarAvisosVencimentosSistema($pdo));
    } catch (Throwable $e) {
        $avisosSistema = $avisosSistema;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Logi - Início</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/home.css') ?>">
</head>

<body class="app-layout">

    <!-- SIDEBAR -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- CONTEÚDO -->
    <main class="app-main">

        <div class="container-fluid">

            <div class="mb-4">
                <h3>Bem-vindo ao sistema Logi 👋</h3>
                <p class="text-muted">Escolha o serviço que deseja acessar</p>
            </div>

            <?php if ($avisosSistema): ?>
                <section class="avisos-home mb-4" id="avisosHome" data-total="<?= count($avisosSistema) ?>">
                    <div class="avisos-home-cabecalho">
                        <div>
                            <h5 class="mb-1">
                                <i class="bi bi-bell"></i>
                                <span id="avisosHomeQuantidade"><?= count($avisosSistema) ?></span>
                                <span id="avisosHomeRotulo">aviso<?= count($avisosSistema) === 1 ? '' : 's' ?> importante<?= count($avisosSistema) === 1 ? '' : 's' ?></span>
                            </h5>
                            <p class="mb-0">Rotinas que precisam de atenção antes de seguir o mês.</p>
                        </div>
                        <button
                            class="btn btn-sm btn-outline-warning avisos-home-toggle"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#avisosHomeDetalhes"
                            aria-expanded="false"
                            aria-controls="avisosHomeDetalhes">
                            Ver detalhes
                        </button>
                    </div>

                    <div class="collapse" id="avisosHomeDetalhes">
                        <div class="avisos-home-lista">
                            <?php foreach ($avisosSistema as $aviso): ?>
                                <div class="aviso-home-item">
                                    <i class="bi <?= htmlspecialchars($aviso['icone'] ?? 'bi-bell') ?>"></i>
                                    <a href="<?= htmlspecialchars($aviso['url']) ?>" class="aviso-home-dados">
                                        <strong><?= htmlspecialchars($aviso['titulo']) ?></strong>
                                        <small><?= htmlspecialchars($aviso['texto']) ?></small>
                                    </a>
                                    <span class="badge bg-warning text-dark">
                                        <?= (int)($aviso['quantidade'] ?? 1) ?>
                                    </span>
                                    <?php if (!empty($aviso['resolver_modal'])): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-success aviso-home-resolver"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalResolverAvisoHome"
                                            data-aviso="<?= htmlspecialchars(json_encode($aviso['resolver_modal'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-check2-circle"></i> Resolver
                                        </button>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($aviso['resolver_url'] ?? $aviso['url']) ?>" class="btn btn-sm btn-outline-success aviso-home-resolver">
                                            <i class="bi bi-check2-circle"></i> Resolver
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <div class="row g-4">

                <?php if (usuarioPode('tarefas')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-tarefas" onclick="location.href='tarefas.php'">
                            <div class="icon"><i class="bi bi-check2-square"></i></div>
                            <h5>Minhas Tarefas</h5>
                            <p>
                                Pendentes: <?= (int)$resumoTarefas['pendentes'] ?>
                                · Concluídas hoje: <?= (int)$resumoTarefas['concluidas_hoje'] ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('pendencias')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-pendencias" onclick="location.href='pendencias.php'">
                            <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
                            <h5>Pendências</h5>
                            <p>Resumo do que falta ou venceu</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('clientes')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-clientes" onclick="location.href='<?= $empresaSomenteServicoAvulso ? 'servicos_avulsos.php' : 'clientes.php' ?>'">
                            <div class="icon"><i class="bi bi-people"></i></div>
                            <h5>Clientes</h5>
                            <p>Consulte e gerencie os clientes</p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card-servico card-devolvidos" onclick="location.href='clientes_devolvidos.php'">
                            <div class="icon"><i class="bi bi-archive"></i></div>
                            <h5>Clientes Devolvidos</h5>
                            <p>Consulte e reative cadastros devolvidos</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('parcelamentos')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-parcelamentos" onclick="location.href='parcelamentos.php'">
                            <div class="icon"><i class="bi bi-cash-coin"></i></div>
                            <h5>Parcelamentos</h5>
                            <p>Acompanhe tributos e dívidas</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('financeiro')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-financeiro" onclick="location.href='financeiro.php'">
                            <div class="icon"><i class="bi bi-wallet2"></i></div>
                            <h5>Financeiro</h5>
                            <p>Receitas, contas e cartões</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('folha_ponto')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-folha-ponto" onclick="location.href='folha_ponto.php'">
                            <div class="icon"><i class="bi bi-clock-history"></i></div>
                            <h5>Folha de Ponto</h5>
                            <p>Jornadas, horários e registros mensais</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('legalizacao')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-legalizacao" onclick="location.href='legalizacao.php'">
                            <div class="icon"><i class="bi bi-diagram-3"></i></div>
                            <h5>Legalização</h5>
                            <p>Processos, etapas e checklists</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('certificados')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-certificados" onclick="location.href='certificados.php'">
                            <div class="icon"><i class="bi bi-shield-lock"></i></div>
                            <h5>Certificado Digital</h5>
                            <p>Controle de certificados digitais</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('alvaras')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-alvaras" onclick="location.href='alvaras.php'">
                            <div class="icon"><i class="bi bi-building"></i></div>
                            <h5>Alvarás</h5>
                            <p>Gerencie licenças e alvarás</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('procuracoes')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-procuracoes" onclick="location.href='procuracoes.php'">
                            <div class="icon"><i class="bi bi-journal-text"></i></div>
                            <h5>Procurações</h5>
                            <p>Controle de autorizações</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('paralisacoes')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-paralisacoes" onclick="location.href='paralisadas.php'">
                            <div class="icon"><i class="bi bi-folder2-open"></i></div>
                            <h5>Paralisações</h5>
                            <p>Gerencie Empresas Paralisadas</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('contador')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-contador" onclick="location.href='contador.php'">
                            <div class="icon"><i class="bi bi-briefcase"></i></div>
                            <h5>Contador</h5>
                            <p>Controle de Inclusão e Exclusão</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('crf')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-crf" onclick="location.href='crf.php'">
                            <div class="icon"><i class="bi bi-clipboard2-data"></i></div>
                            <h5>Cadastro CRF</h5>
                            <p>Controle Cadastro FGTS</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('contratos')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-contratos" onclick="location.href='prestacao_servico.php'">
                            <div class="icon"><i class="bi bi-receipt"></i></div>
                            <h5>Contrato de Prestação de Serviços</h5>
                            <p>Controle de Contratos</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('outros_servicos')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-outros-servicos" onclick="location.href='outros_servicos.php'">
                            <div class="icon"><i class="bi bi-grid"></i></div>
                            <h5>Outros Serviços</h5>
                            <p>Controles internos adicionais</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioPode('usuarios')): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-usuarios" onclick="location.href='usuarios.php'">
                            <div class="icon"><i class="bi bi-person-gear"></i></div>
                            <h5>Usuários e Permissões</h5>
                            <p>Gerencie acessos do sistema</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (usuarioEhAdmin()): ?>
                    <div class="col-md-4">
                        <div class="card-servico card-auditoria" onclick="location.href='auditoria.php'">
                            <div class="icon"><i class="bi bi-search"></i></div>
                            <h5>Auditoria</h5>
                            <p>Acompanhe ações dos usuários</p>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </div>

    </main>

    <div class="modal fade" id="modalResolverAvisoHome" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="formResolverAvisoHome">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="modalAvisoHomeTitulo">Resolver aviso</h5>
                            <p class="text-muted mb-0" id="modalAvisoHomeCliente"></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="modalAvisoHomeErro"></div>
                        <p class="text-muted" id="modalAvisoHomeDescricao"></p>
                        <input type="hidden" name="tipo" id="modalAvisoHomeTipo">
                        <input type="hidden" name="cliente_id" id="modalAvisoHomeClienteId">
                        <input type="hidden" name="campo_status" id="modalAvisoHomeCampoStatus">
                        <input type="hidden" name="campo_vencimento" id="modalAvisoHomeCampoVencimento">
                        <input type="hidden" name="orgao_codigo" id="modalAvisoHomeOrgaoCodigo">

                        <label for="modalAvisoHomeVencimento" class="form-label">Novo vencimento</label>
                        <input type="date" class="form-control" name="vencimento" id="modalAvisoHomeVencimento" required>
                        <div class="invalid-feedback">Informe o novo vencimento.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="btnSalvarAvisoHome">
                            <i class="bi bi-check2-circle"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (usuarioLogado()): ?>
        <div class="modal fade" id="modalTutorialInicial" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Bem-vindo ao Logi</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Separei um manual rápido para explicar onde ficam as principais rotinas do sistema.</p>
                        <p class="text-muted mb-0">Você pode abrir esse tutorial novamente pelo menu em <strong>Ajuda</strong>.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Ver depois</button>
                        <a href="ajuda.php" class="btn btn-primary" id="btnAbrirManualInicial">Abrir manual</a>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const modalResolverAviso = document.getElementById('modalResolverAvisoHome');
                const formResolverAviso = document.getElementById('formResolverAvisoHome');
                const erroResolverAviso = document.getElementById('modalAvisoHomeErro');
                const btnSalvarAvisoHome = document.getElementById('btnSalvarAvisoHome');
                let botaoAvisoAtual = null;

                if (modalResolverAviso && formResolverAviso) {
                    modalResolverAviso.addEventListener('show.bs.modal', function(evento) {
                        botaoAvisoAtual = evento.relatedTarget;
                        const dados = JSON.parse(botaoAvisoAtual.dataset.aviso || '{}');

                        erroResolverAviso.classList.add('d-none');
                        erroResolverAviso.textContent = '';
                        formResolverAviso.reset();

                        document.getElementById('modalAvisoHomeTitulo').textContent = dados.titulo || 'Resolver aviso';
                        document.getElementById('modalAvisoHomeCliente').textContent = dados.cliente || '';
                        document.getElementById('modalAvisoHomeDescricao').textContent = dados.descricao || '';
                        document.getElementById('modalAvisoHomeTipo').value = dados.tipo || '';
                        document.getElementById('modalAvisoHomeClienteId').value = dados.cliente_id || '';
                        document.getElementById('modalAvisoHomeCampoStatus').value = dados.campo_status || '';
                        document.getElementById('modalAvisoHomeCampoVencimento').value = dados.campo_vencimento || '';
                        document.getElementById('modalAvisoHomeOrgaoCodigo').value = dados.orgao_codigo || '';
                        document.getElementById('modalAvisoHomeVencimento').value = dados.vencimento || '';

                        window.sincronizarCalendarioCampo?.('modalAvisoHomeVencimento');
                    });

                    formResolverAviso.addEventListener('submit', function(evento) {
                        evento.preventDefault();

                        const vencimento = document.getElementById('modalAvisoHomeVencimento');
                        vencimento.classList.remove('is-invalid');

                        if (!vencimento.value) {
                            vencimento.classList.add('is-invalid');
                            window.focarCalendarioCampo?.(vencimento);
                            return;
                        }

                        const textoOriginal = btnSalvarAvisoHome.innerHTML;
                        btnSalvarAvisoHome.disabled = true;
                        btnSalvarAvisoHome.innerHTML = 'Salvando...';

                        fetch('api_avisos_resolver.php', {
                                method: 'POST',
                                body: new FormData(formResolverAviso),
                                credentials: 'same-origin',
                            })
                            .then(function(resposta) {
                                return resposta.json();
                            })
                            .then(function(resposta) {
                                if (!resposta.sucesso) {
                                    throw new Error(resposta.mensagem || 'Não foi possível resolver o aviso.');
                                }

                                const linha = botaoAvisoAtual?.closest('.aviso-home-item');
                                linha?.remove();
                                bootstrap.Modal.getInstance(modalResolverAviso).hide();
                                window.dispatchEvent(new Event('pendencias:atualizar'));

                                const avisosRestantes = document.querySelectorAll('.aviso-home-item').length;
                                const secaoAvisos = document.getElementById('avisosHome');
                                const quantidadeAvisos = document.getElementById('avisosHomeQuantidade');
                                const rotuloAvisos = document.getElementById('avisosHomeRotulo');

                                if (avisosRestantes === 0) {
                                    secaoAvisos?.remove();
                                } else {
                                    if (quantidadeAvisos) {
                                        quantidadeAvisos.textContent = String(avisosRestantes);
                                    }

                                    if (rotuloAvisos) {
                                        rotuloAvisos.textContent = avisosRestantes === 1 ? 'aviso importante' : 'avisos importantes';
                                    }
                                }
                            })
                            .catch(function(erro) {
                                erroResolverAviso.textContent = erro.message;
                                erroResolverAviso.classList.remove('d-none');
                            })
                            .finally(function() {
                                btnSalvarAvisoHome.disabled = false;
                                btnSalvarAvisoHome.innerHTML = textoOriginal;
                            });
                    });
                }

                if (localStorage.getItem('logiTutorialInicialVisto') === '1') {
                    return;
                }

                const modal = document.getElementById('modalTutorialInicial');
                const btnAbrirManualInicial = document.getElementById('btnAbrirManualInicial');
                const instancia = bootstrap.Modal.getOrCreateInstance(modal);
                instancia.show();

                btnAbrirManualInicial?.addEventListener('click', function() {
                    localStorage.setItem('logiTutorialInicialVisto', '1');
                });

                modal.addEventListener('hidden.bs.modal', function() {
                    localStorage.setItem('logiTutorialInicialVisto', '1');
                }, {
                    once: true
                });
            });
        </script>
    <?php endif; ?>

</body>

</html>