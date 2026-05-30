const sidebar = document.getElementById('appSidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const icon = sidebarToggle.querySelector('i');

sidebarToggle.addEventListener('click', () => {

    sidebar.classList.toggle('collapsed');

    if (sidebar.classList.contains('collapsed')) {

        icon.className = 'bi bi-layout-sidebar';

    } else {

        icon.className = 'bi bi-layout-sidebar-inset';

    }

});