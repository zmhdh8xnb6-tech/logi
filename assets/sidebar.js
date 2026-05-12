document.addEventListener("DOMContentLoaded", function () {
    const btn = document.getElementById("sidebarToggle");
    const sidebar = document.getElementById("appSidebar");

    if (btn && sidebar) {
        btn.addEventListener("click", function () {
            sidebar.classList.toggle("collapsed");
        });
    }
});