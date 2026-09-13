/**
 * Declarative dropdowns:
 *   <div class="dropdown">
 *     <button data-dropdown-toggle>…</button>
 *     <div class="dropdown-menu" hidden>…</div>
 *   </div>
 */

export function closeAllDropdowns(except = null) {
    document.querySelectorAll('.dropdown-menu:not([hidden])').forEach((menu) => {
        if (menu === except) return;
        menu.hidden = true;
        menu.parentElement?.querySelector('[data-dropdown-toggle]')?.setAttribute('aria-expanded', 'false');
    });
}

export function initDropdowns() {
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-dropdown-toggle]');

        if (toggle) {
            event.preventDefault();
            const menu = toggle.parentElement.querySelector('.dropdown-menu');
            if (!menu) return;
            const willOpen = menu.hidden;
            closeAllDropdowns(menu);
            menu.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) menu.querySelector('.dropdown-item')?.focus({ preventScroll: true });
            return;
        }

        if (event.target.closest('.dropdown-item') || !event.target.closest('.dropdown-menu')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAllDropdowns();
    });
}
