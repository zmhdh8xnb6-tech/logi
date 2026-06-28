const sidebar = document.getElementById('appSidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const icon = sidebarToggle.querySelector('i');

sidebarToggle.addEventListener('click', () => {

    sidebar.classList.toggle('collapsed');

    if (sidebar.classList.contains('collapsed')) {

        icon.className = 'bi bi-layout-sidebar';
        sidebarToggle.title = 'Expandir menu';
        sidebarToggle.setAttribute('aria-label', 'Expandir menu');

    } else {

        icon.className = 'bi bi-layout-sidebar-inset';
        sidebarToggle.title = 'Recolher menu';
        sidebarToggle.setAttribute('aria-label', 'Recolher menu');

    }

});