import axios from '../bootstrap';
import { $, $$, errorMessage } from '../lib/dom';
import { toast } from '../lib/toast';

function initTabs() {
    const container = $('[data-settings-tabs]');
    if (!container) return;

    const activate = (name, updateUrl = true) => {
        $$('[data-tab]', container).forEach((tab) => tab.setAttribute('aria-selected', tab.dataset.tab === name ? 'true' : 'false'));
        $$('[data-tab-panel]', container).forEach((panel) => panel.classList.toggle('is-active', panel.dataset.tabPanel === name));

        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            history.replaceState(null, '', url);
        }
    };

    container.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');
        if (tab) activate(tab.dataset.tab);
    });
}

function initPreferenceSwitches(config) {
    $$('[data-preference]').forEach((input) => {
        // Selects (privacy choices) send their value; switches send on/off.
        const isSwitch = input.type === 'checkbox';
        let saved = isSwitch ? input.checked : input.value;

        input.addEventListener('change', async () => {
            const key = input.dataset.preference;
            const value = isSwitch ? input.checked : input.value;
            try {
                await axios.patch(config.routes.preferences, { [key]: value });
                saved = value;
                if (config.user) config.user[key] = value;
                toast.success('Preference saved.', { timeout: 2000 });
            } catch (error) {
                if (isSwitch) input.checked = saved;
                else input.value = saved;
                toast.error(errorMessage(error));
            }
        });
    });
}

function initBrowserNotificationButton() {
    const button = $('[data-request-browser-notifications]');
    const text = $('[data-browser-permission-text]');
    if (!button) return;

    const render = () => {
        if (!('Notification' in window)) {
            button.disabled = true;
            text.textContent = 'Your browser does not support desktop notifications.';
            return;
        }
        if (Notification.permission === 'granted') {
            button.disabled = true;
            button.lastChild.textContent = ' Enabled';
            text.textContent = 'Desktop notifications are enabled for this browser.';
        } else if (Notification.permission === 'denied') {
            button.disabled = true;
            text.textContent = 'Notifications are blocked. Allow them in your browser site settings.';
        }
    };

    render();

    button.addEventListener('click', async () => {
        if (!('Notification' in window)) return;
        const result = await Notification.requestPermission();
        if (result === 'granted') toast.success('Desktop notifications enabled.');
        render();
    });
}

/** "Block someone": filter the people you chat with (P5). */
function initBlockPicker() {
    const search = $('[data-block-search]');
    if (!search) return;
    search.addEventListener('input', () => {
        const term = search.value.trim().toLowerCase();
        $$('[data-block-candidate]').forEach((row) => {
            row.hidden = Boolean(term) && !row.dataset.name.includes(term);
        });
    });
}

export function initSettings(config) {
    if (!$('[data-settings-tabs]')) return;
    initTabs();
    initBlockPicker();
    initPreferenceSwitches(config);
    initBrowserNotificationButton();
}
