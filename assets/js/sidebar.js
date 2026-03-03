// Sidebar toggle behaviour
(function(){
    const SIDEBAR_STATE_KEY = 'sidebarCollapsedV2';

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

    function applyEntranceAnimations() {
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReducedMotion) return;

        const contentBlocks = document.querySelectorAll('.main-content > .card, .main-content > .stats-grid, .main-content > .alert, .main-content > div');
        contentBlocks.forEach((el, index) => {
            if (index > 10) return;
            el.style.animationDelay = `${index * 55}ms`;
        });

        const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
        sidebarLinks.forEach((link, index) => {
            link.style.opacity = '0';
            link.style.transform = 'translateX(-8px)';
            link.style.transition = `opacity 320ms ease ${index * 40}ms, transform 320ms ease ${index * 40}ms`;
            requestAnimationFrame(() => {
                link.style.opacity = '1';
                link.style.transform = 'translateX(0)';
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        // restore stored state
        try {
            const saved = localStorage.getItem(SIDEBAR_STATE_KEY);
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
                    localStorage.setItem(SIDEBAR_STATE_KEY, collapsed);
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

        applyEntranceAnimations();
    });
})();
