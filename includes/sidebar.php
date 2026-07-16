<div class="app-sidebar collapsed" id="appSidebar">

    <div class="sidebar-header">

        <button class="sidebar-toggle" id="sidebarToggle" type="button" title="Expandir menu" aria-label="Expandir menu">
            <i class="bi bi-layout-sidebar"></i>
        </button>

        <span class="sidebar-title">
            Menu
        </span>

    </div>

    <nav class="sidebar-menu">
        <?php if (usuarioPode('pendencias')): ?>
            <?php $urlBaseNotificacoes = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); ?>
            <div
                class="sidebar-notification-item"
                id="appNotificationCenter"
                data-api-url="<?= htmlspecialchars($urlBaseNotificacoes . '/api_notificacoes.php') ?>"
                data-user-id="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>">
                <button
                    type="button"
                    class="sidebar-link sidebar-notification-link"
                    id="notificationBell"
                    data-label="Notificações"
                    title="Notificações"
                    aria-label="Abrir notificações"
                    aria-expanded="false">
                    <i class="bi bi-bell"></i>
                    <span>Notificações</span>
                    <strong class="notification-count d-none" id="notificationCount">0</strong>
                </button>
            </div>
        <?php endif; ?>

        <a href="home.php" class="sidebar-link" data-label="Início">
            <i class="bi bi-house"></i>
            <span>Início</span>
        </a>

        <?php if (usuarioPode('tarefas')): ?>
            <a href="tarefas.php" class="sidebar-link" data-label="Tarefas">
                <i class="bi bi-check2-square"></i>
                <span>Tarefas</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('pendencias')): ?>
            <a href="pendencias.php" class="sidebar-link" data-label="Pendências">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Pendências</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('parcelamentos')): ?>
            <a href="parcelamentos.php" class="sidebar-link" data-label="Parcelamentos">
                <i class="bi bi-cash-coin"></i>
                <span>Parcelamentos</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('financeiro')): ?>
            <a href="financeiro.php" class="sidebar-link" data-label="Financeiro">
                <i class="bi bi-wallet2"></i>
                <span>Financeiro</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('legalizacao')): ?>
            <a href="legalizacao.php" class="sidebar-link" data-label="Legalização">
                <i class="bi bi-diagram-3"></i>
                <span>Legalização</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('certificados')): ?>
            <a href="certificados.php" class="sidebar-link" data-label="Certificado Digital">
                <i class="bi bi-file-earmark-text"></i>
                <span>Certificado Digital</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('alvaras')): ?>
            <a href="alvaras.php" class="sidebar-link" data-label="Alvarás">
                <i class="bi bi-building"></i>
                <span>Alvarás</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('procuracoes')): ?>
            <a href="procuracoes.php" class="sidebar-link" data-label="Procurações">
                <i class="bi bi-journal-text"></i>
                <span>Procurações</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('paralisacoes')): ?>
            <a href="paralisadas.php" class="sidebar-link" data-label="Paralisações">
                <i class="bi bi-folder2-open"></i>
                <span>Paralisações</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('clientes')): ?>
            <a href="clientes.php" class="sidebar-link" data-label="Clientes">
                <i class="bi bi-people"></i>
                <span>Clientes</span>
            </a>

            <a href="servicos_avulsos.php" class="sidebar-link" data-label="Serviços Avulsos">
                <i class="bi bi-briefcase"></i>
                <span>Serviços Avulsos</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('usuarios')): ?>
            <a href="usuarios.php" class="sidebar-link" data-label="Usuários">
                <i class="bi bi-person-gear"></i>
                <span>Usuários</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioEhAdmin()): ?>
            <a href="auditoria.php" class="sidebar-link" data-label="Auditoria">
                <i class="bi bi-activity"></i>
                <span>Auditoria</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="sidebar-link text-danger" data-label="Sair">
            <i class="bi bi-box-arrow-right"></i>
            <span>Sair</span>
        </a>
    </div>
</div>

<button
    type="button"
    class="sidebar-backdrop"
    id="sidebarBackdrop"
    aria-label="Fechar menu"
    tabindex="-1"></button>

<?php if (usuarioPode('pendencias')): ?>
    <div class="notification-panel d-none" id="notificationPanel">
        <div class="notification-panel-header">Notificações</div>
        <div class="notification-panel-body">
            <strong id="notificationPanelTitle">Consultando...</strong>
            <span id="notificationPanelText">Aguarde a atualização.</span>
        </div>
        <a href="pendencias.php" class="notification-panel-link">
            Ver pendências
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="notification-toast d-none" id="notificationToast" role="status" aria-live="polite">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong id="notificationToastTitle"></strong>
            <span>Consulte a lista para verificar o que precisa ser resolvido.</span>
        </div>
        <a href="pendencias.php">Ver</a>
        <button type="button" id="notificationToastClose" title="Fechar" aria-label="Fechar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
<?php endif; ?>