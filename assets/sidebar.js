const sidebar = document.getElementById('appSidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

if (sidebar && sidebarToggle) {
    const icon = sidebarToggle.querySelector('i');
    const mobileSidebar = window.matchMedia('(max-width: 768px)');

    function atualizarSidebar() {
        if (sidebar.classList.contains('collapsed')) {
            icon.className = 'bi bi-layout-sidebar';
            sidebarToggle.title = 'Expandir menu';
            sidebarToggle.setAttribute('aria-label', 'Expandir menu');
        } else {
            icon.className = 'bi bi-layout-sidebar-inset';
            sidebarToggle.title = 'Recolher menu';
            sidebarToggle.setAttribute('aria-label', 'Recolher menu');
        }

        const menuMovelAberto = mobileSidebar.matches
            && !sidebar.classList.contains('collapsed');

        document.body.classList.toggle('sidebar-mobile-open', menuMovelAberto);
        sidebarBackdrop?.classList.toggle('visible', menuMovelAberto);
        sidebarToggle.setAttribute('aria-expanded', menuMovelAberto ? 'true' : 'false');
    }

    function fecharSidebarMovel() {
        if (!mobileSidebar.matches) {
            return;
        }

        sidebar.classList.add('collapsed');
        atualizarSidebar();
    }

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        atualizarSidebar();
    });

    sidebarBackdrop?.addEventListener('click', fecharSidebarMovel);

    sidebar.querySelectorAll('a.sidebar-link').forEach((link) => {
        link.addEventListener('click', fecharSidebarMovel);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            fecharSidebarMovel();
        }
    });

    mobileSidebar.addEventListener('change', atualizarSidebar);
    atualizarSidebar();
}

const notificationCenter = document.getElementById('appNotificationCenter');

if (notificationCenter) {
    const notificationBell = document.getElementById('notificationBell');
    const notificationCount = document.getElementById('notificationCount');
    const notificationPanel = document.getElementById('notificationPanel');
    const notificationPanelTitle = document.getElementById('notificationPanelTitle');
    const notificationPanelText = document.getElementById('notificationPanelText');
    const notificationToast = document.getElementById('notificationToast');
    const notificationToastTitle = document.getElementById('notificationToastTitle');
    const notificationToastClose = document.getElementById('notificationToastClose');
    const userId = notificationCenter.dataset.userId;
    const storagePrefix = `logi_pendencias_${userId}`;
    const intervaloAviso = 4 * 60 * 60 * 1000;
    const intervaloConsulta = 30000;
    let timerToast = null;
    let consultandoPendencias = false;

    function textoPendencias(total) {
        return total === 1 ? '1 pendência' : `${total} pendências`;
    }

    function esconderToast() {
        notificationToast.classList.add('d-none');

        if (timerToast) {
            clearTimeout(timerToast);
            timerToast = null;
        }
    }

    function mostrarToast(total) {
        notificationToastTitle.textContent = `Você possui ${textoPendencias(total)}.`;
        notificationToast.classList.remove('d-none');

        timerToast = setTimeout(esconderToast, 8000);
    }

    function atualizarInterface(total) {
        notificationCount.textContent = total > 99 ? '99+' : String(total);
        notificationCount.classList.toggle('d-none', total === 0);
        notificationBell.classList.toggle('has-pending', total > 0);

        if (total > 0) {
            notificationPanelTitle.textContent = `Você possui ${textoPendencias(total)}.`;
            notificationPanelText.textContent = 'Confira os itens vencidos, ausentes ou próximos do vencimento.';
        } else {
            notificationPanelTitle.textContent = 'Tudo em dia.';
            notificationPanelText.textContent = 'Nenhuma pendência encontrada neste momento.';
        }
    }

    const totalEmCache = Number(localStorage.getItem(`${storagePrefix}_total`) || 0);

    if (Number.isFinite(totalEmCache) && totalEmCache >= 0) {
        atualizarInterface(totalEmCache);
    }

    function posicionarPainel() {
        const botao = notificationBell.getBoundingClientRect();
        const margem = 12;

        if (window.innerWidth <= 768) {
            notificationPanel.style.left = '16px';
            notificationPanel.style.top = `${botao.bottom + 8}px`;
            return;
        }

        const larguraPainel = Math.min(340, window.innerWidth - 32);
        let esquerda = botao.right + margem;

        if (esquerda + larguraPainel > window.innerWidth - 16) {
            esquerda = Math.max(16, botao.left - larguraPainel - margem);
        }

        notificationPanel.style.left = `${esquerda}px`;
        notificationPanel.style.top = `${Math.max(16, botao.top)}px`;
    }

    async function consultarPendencias() {
        if (consultandoPendencias) {
            return;
        }

        consultandoPendencias = true;

        try {
            const separador = notificationCenter.dataset.apiUrl.includes('?') ? '&' : '?';
            const resposta = await fetch(notificationCenter.dataset.apiUrl + separador + '_=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!resposta.ok) {
                return;
            }

            const dados = await resposta.json();
            const total = Number(dados.total || 0);
            const totalAnterior = Number(localStorage.getItem(`${storagePrefix}_total`) || 0);
            const ultimoAviso = Number(localStorage.getItem(`${storagePrefix}_aviso`) || 0);
            const agora = Date.now();
            const estaNaTelaPendencias = window.location.pathname.endsWith('/pendencias.php');
            const deveAvisar = total > 0
                && !estaNaTelaPendencias
                && (ultimoAviso === 0 || agora - ultimoAviso >= intervaloAviso || total > totalAnterior);

            atualizarInterface(total);
            localStorage.setItem(`${storagePrefix}_total`, String(total));

            if (deveAvisar) {
                mostrarToast(total);
                localStorage.setItem(`${storagePrefix}_aviso`, String(agora));
            }
        } catch (erro) {
            notificationPanelTitle.textContent = 'Não foi possível atualizar.';
            notificationPanelText.textContent = 'Tente novamente em alguns instantes.';
        } finally {
            consultandoPendencias = false;
        }
    }

    notificationBell.addEventListener('click', function (event) {
        event.stopPropagation();
        const abrir = notificationPanel.classList.contains('d-none');

        notificationPanel.classList.toggle('d-none', !abrir);
        notificationBell.setAttribute('aria-expanded', abrir ? 'true' : 'false');

        if (abrir) {
            posicionarPainel();
            consultarPendencias();
        }
    });

    notificationPanel.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function () {
        notificationPanel.classList.add('d-none');
        notificationBell.setAttribute('aria-expanded', 'false');
    });

    notificationToastClose.addEventListener('click', esconderToast);
    window.addEventListener('resize', function () {
        if (!notificationPanel.classList.contains('d-none')) {
            posicionarPainel();
        }
    });
    window.addEventListener('focus', consultarPendencias);
    window.addEventListener('pageshow', consultarPendencias);
    window.addEventListener('online', consultarPendencias);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            consultarPendencias();
        }
    });
    window.addEventListener('pendencias:atualizar', consultarPendencias);

    consultarPendencias();
    setInterval(consultarPendencias, intervaloConsulta);
}