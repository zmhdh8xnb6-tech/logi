<?php
require 'config.php';

exigirLogin();

$resumoTarefas = [
    'pendentes' => 0,
    'concluidas_hoje' => 0,
];

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

</body>

</html>