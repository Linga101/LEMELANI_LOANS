// Sidebar toggle behaviour
(function(){
    // helper to apply or remove collapsed styles
    function updateLayout(collapsed) {
        const sidebar = document.getElementById('sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');
        if (!sidebar) return;
        if (collapsed) {
            sidebar.classList.add('collapsed');
            mainWrapper && mainWrapper.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
            mainWrapper && mainWrapper.classList.remove('collapsed');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        // restore stored state
        try {
            const saved = localStorage.getItem('sidebarCollapsed');
            if (saved === 'true') {
                updateLayout(true);
            }
        } catch(e) {}

        // hook click handlers on all toggle buttons (should normally be one)
        const toggles = document.querySelectorAll('.sidebar-toggle');
        toggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const collapsed = !sidebar.classList.contains('collapsed');
                updateLayout(collapsed);
                try {
                    localStorage.setItem('sidebarCollapsed', collapsed);
                } catch(e) {}
            });
        });

        // add title attributes to sidebar links for tooltip when collapsed
        document.querySelectorAll('.sidebar-menu a').forEach(a => {
            if (!a.title) {
                const text = a.innerText || a.textContent;
                if (text) a.title = text.trim();
            }
        });
    });
})();