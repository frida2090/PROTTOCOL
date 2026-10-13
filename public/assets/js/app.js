document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('#main-nav');
    if (toggle && nav) {
        const setOpen = (open) => {
            nav.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', String(open));
        };
        toggle.addEventListener('click', () => setOpen(!nav.classList.contains('is-open')));
        nav.addEventListener('click', (event) => { if (event.target.closest('a')) setOpen(false); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
    }

    // Los avisos se pueden cerrar y los de éxito desaparecen solos
    document.querySelectorAll('.page-shell > .alert').forEach((alert) => {
        if (alert.classList.contains('alert-success')) {
            setTimeout(() => { alert.hidden = true; }, 6000);
        }
    });

    // Evita el scroll de fondo mientras hay un modal abierto
    if (document.querySelector('.dashboard-modal.is-open')) {
        document.body.classList.add('modal-open');
    }
});
