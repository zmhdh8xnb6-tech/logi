<div class="app-sidebar collapsed" id="appSidebar">

    <div class="sidebar-header">

        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-layout-sidebar"></i>
        </button>

        <span class="sidebar-title">
            Menu
        </span>

    </div>

    <nav class="sidebar-menu">
        <a href="home.php" class="sidebar-link">
            <i class="bi bi-house"></i>
            <span>Início</span>
        </a>

        <?php if (usuarioPode('pendencias')): ?>
            <a href="pendencias.php" class="sidebar-link">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Pendências</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('parcelamentos')): ?>
            <a href="parcelamentos.php" class="sidebar-link">
                <i class="bi bi-cash-coin"></i>
                <span>Parcelamentos</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('certificados')): ?>
            <a href="certificados.php" class="sidebar-link" title="Certificado Digital">
                <i class="bi bi-file-earmark-text"></i>
                <span>Certificado Digital</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('alvaras')): ?>
            <a href="alvaras.php" class="sidebar-link">
                <i class="bi bi-building"></i>
                <span>Alvarás</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('procuracoes')): ?>
            <a href="procuracoes.php" class="sidebar-link">
                <i class="bi bi-journal-text"></i>
                <span>Procurações</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('clientes')): ?>
            <a href="clientes.php" class="sidebar-link">
                <i class="bi bi-people"></i>
                <span>Clientes</span>
            </a>
        <?php endif; ?>

        <?php if (usuarioPode('usuarios')): ?>
            <a href="usuarios.php" class="sidebar-link">
                <i class="bi bi-person-gear"></i>
                <span>Usuários</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="sidebar-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span>Sair</span>
        </a>
    </div>
</div>