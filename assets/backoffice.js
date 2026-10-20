/*
 * Backoffice de Suscripta — comportamiento del shell.
 *
 * Se aparta del backoffice de Orian en un punto: alli el script va bundled con
 * Encore y engancha a `turbo:load`; aqui los assets se sirven con AssetMapper,
 * asi que el script es un modulo ES nativo importado por `app.js`.
 *
 * El enganche a `turbo:load` se conserva, y es importante: Turbo Drive
 * reemplaza el `<body>` en cada navegacion SIN volver a disparar
 * `DOMContentLoaded`, que solo ocurre en la primera carga completa. Si el
 * sidebar se inicializara solo ahi, tras navegar desde el menu quedaria
 * escuchando en elementos ya destruidos y **la hamburguesa dejaria de
 * responder** (el boton se ve, pero no hace nada).
 */

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    autoHideFlashAlerts();
});

document.addEventListener('turbo:load', () => {
    initSidebar();
    autoHideFlashAlerts();
});

/** Abre/cierra el sidebar en mobile. */
function initSidebar() {
    const sidebar = document.getElementById('boSidebar');
    const overlay = document.getElementById('boOverlay');
    const toggleBtn = document.getElementById('boSidebarToggle');
    const closeBtn = document.getElementById('boSidebarClose');

    if (!sidebar || !overlay) return;

    /** Abre el sidebar en mobile */
    const openSidebar = () => {
        sidebar.classList.add('bo-sidebar--open');
        overlay.classList.add('bo-overlay--visible');
        document.body.style.overflow = 'hidden';
    };

    /** Cierra el sidebar en mobile */
    const closeSidebar = () => {
        sidebar.classList.remove('bo-sidebar--open');
        overlay.classList.remove('bo-overlay--visible');
        document.body.style.overflow = '';
    };

    // Idempotencia: `turbo:load` puede volver a dispararse en la misma pagina
    // (restauracion desde cache). Se guarda una marca en los propios nodos para
    // no registrar los listeners dos veces y alternar el estado con un solo clic.
    if (sidebar.dataset.sidebarReady === '1') return;
    sidebar.dataset.sidebarReady = '1';

    // El boton se llama "toggle" y debe alternar: si el sidebar esta abierto lo
    // cierra, y si esta cerrado lo abre.
    toggleBtn?.addEventListener('click', () => {
        if (sidebar.classList.contains('bo-sidebar--open')) {
            closeSidebar();

            return;
        }

        openSidebar();
    });
    closeBtn?.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    // Cerrar con Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('bo-sidebar--open')) {
            closeSidebar();
        }
    });
}

/** Auto-ocultar alertas flash tras 5 segundos. */
function autoHideFlashAlerts() {
    document.querySelectorAll('.alert-dismissible').forEach((alert) => {
        if (alert.dataset.autoHideReady === '1') return;
        alert.dataset.autoHideReady = '1';

        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) closeBtn.click();
        }, 5000);
    });
}
