import axios from '../bootstrap';
import { $$ } from './dom';

const THEMES = ['light', 'dark', 'system'];
const root = document.documentElement;
const media = window.matchMedia('(prefers-color-scheme: dark)');

let config = {};

export function currentPreference() {
    const pref = root.dataset.themePref;
    return THEMES.includes(pref) ? pref : 'system';
}

function apply(pref) {
    const dark = pref === 'dark' || (pref === 'system' && media.matches);
    root.dataset.themePref = pref;
    root.dataset.theme = dark ? 'dark' : 'light';

    try {
        localStorage.setItem('theme', pref);
    } catch {
        /* storage unavailable */
    }

    $$('[data-theme-option]').forEach((btn) => {
        btn.setAttribute('aria-pressed', btn.dataset.themeOption === pref ? 'true' : 'false');
    });

    document.dispatchEvent(new CustomEvent('theme:change', { detail: { preference: pref, dark } }));
}

/**
 * Change the theme and persist it to the account (when signed in).
 */
export async function setTheme(pref) {
    if (!THEMES.includes(pref)) return;
    apply(pref);

    if (config.user && config.routes?.preferences) {
        try {
            await axios.patch(config.routes.preferences, { theme: pref });
        } catch {
            /* non-critical: the local preference still applies */
        }
    }
}

export function initTheme(appConfig) {
    config = appConfig;
    apply(currentPreference());

    media.addEventListener('change', () => {
        if (currentPreference() === 'system') apply('system');
    });

    document.addEventListener('click', (event) => {
        const cycle = event.target.closest('[data-theme-cycle]');
        if (cycle) {
            const next = THEMES[(THEMES.indexOf(currentPreference()) + 1) % THEMES.length];
            setTheme(next);
            return;
        }

        const option = event.target.closest('[data-theme-option]');
        if (option) setTheme(option.dataset.themeOption);
    });

    // Enable colour transitions only after the first paint.
    requestAnimationFrame(() => requestAnimationFrame(() => root.classList.remove('no-transitions')));
}
