import axios from '../bootstrap';
import { $, $$, errorMessage } from '../lib/dom';
import { setTheme } from '../lib/theme';
import { toast } from '../lib/toast';

const WIDE = '(min-width: 768px)';

/**
 * WhatsApp-style settings screens: the list, and one screen per section.
 * Phones show one screen at a time (back arrow, Android back button);
 * wider screens keep the list on the left.
 */
export function initSettingsNav(root = $('[data-settings]')) {
    if (!root) return null;
    const sections = $$('[data-settings-section]', root);
    if (!sections.length) return null;
    const wide = () => window.matchMedia?.(WIDE).matches ?? false;

    const markCurrent = (name) => {
        const parent = root.querySelector(`[data-settings-section="${name}"]`)?.dataset.parent;
        $$('[data-settings-open]', root).forEach((row) => {
            if (!row.closest('.wa-settings-list')) return;
            row.setAttribute('aria-current', row.dataset.settingsOpen === name || row.dataset.settingsOpen === parent ? 'page' : 'false');
        });
    };

    const setUrl = (name, push) => {
        const url = new URL(window.location.href);
        if (name) url.searchParams.set('tab', name);
        else url.searchParams.delete('tab');
        url.hash = '';
        history[push ? 'pushState' : 'replaceState']({ settings: name ?? 'list' }, '', url);
    };

    const open = (name, { push = true } = {}) => {
        const section = root.querySelector(`[data-settings-section="${name}"]`);
        if (!section) return;
        // On phones the list is its own history step, so "back" returns to it.
        const pushed = push && root.dataset.view === 'list' && !wide();
        root.dataset.view = 'section';
        root.dataset.active = name;
        sections.forEach((s) => s.classList.toggle('is-active', s === section));
        markCurrent(name);
        section.querySelector('.wa-scroll')?.scrollTo?.(0, 0);
        setUrl(name, pushed);
        if (pushed) root.dataset.pushed = '1';
        section.querySelector('.wa-appbar-title')?.focus?.({ preventScroll: true });
    };

    const showList = () => {
        root.dataset.view = 'list';
        setUrl(null, false);
    };

    const back = () => {
        const parent = root.querySelector(`[data-settings-section="${root.dataset.active}"]`)?.dataset.parent;
        if (parent) return open(parent, { push: false });
        if (root.dataset.pushed === '1') {
            delete root.dataset.pushed;
            history.back();
            return null;
        }
        return showList();
    };

    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-settings-open]');
        if (opener) {
            event.preventDefault();
            open(opener.dataset.settingsOpen);
            return;
        }
        if (event.target.closest('[data-settings-back]')) back();
    });

    window.addEventListener('popstate', () => {
        const tab = new URL(window.location.href).searchParams.get('tab');
        delete root.dataset.pushed;
        if (tab && root.querySelector(`[data-settings-section="${tab}"]`)) open(tab, { push: false });
        else if (!wide()) root.dataset.view = 'list';
    });

    // Android app back button: a section goes back to the list first.
    document.addEventListener('app:back', (event) => {
        if (root.dataset.view !== 'section' || wide()) return;
        event.preventDefault();
        back();
    });

    // Section titles can take focus for screen readers after a switch.
    sections.forEach((section) => section.querySelector('.wa-appbar-title')?.setAttribute('tabindex', '-1'));
    return { open, back, showList };
}

/** A choice row shows the chosen option's name under its title. */
function syncChoiceLabel(select) {
    const label = select.closest('.wa-choice')?.querySelector('[data-choice-label]');
    if (label) label.textContent = select.options[select.selectedIndex]?.textContent ?? '';
}

function initPreferenceSwitches(config) {
    $$('[data-preference]').forEach((input) => {
        // Selects (privacy choices) send their value; switches send on/off.
        const isSwitch = input.type === 'checkbox';
        let saved = isSwitch ? input.checked : input.value;

        input.addEventListener('change', async () => {
            const key = input.dataset.preference;
            const value = isSwitch ? input.checked : input.value;
            if (!isSwitch) syncChoiceLabel(input);
            try {
                await axios.patch(config.routes.preferences, { [key]: value });
                saved = value;
                if (config.user) config.user[key] = value;
                toast.success('Preference saved.', { timeout: 2000 });
            } catch (error) {
                if (isSwitch) input.checked = saved;
                else {
                    input.value = saved;
                    syncChoiceLabel(input);
                }
                toast.error(errorMessage(error));
            }
        });
    });
}

/** Chats → Theme: the same light / dark / system choice as the theme button. */
function initThemeChoice() {
    const select = $('[data-theme-select]');
    if (!select) return;
    select.addEventListener('change', () => {
        syncChoiceLabel(select);
        setTheme(select.value);
    });
    document.addEventListener('theme:change', (event) => {
        if (select.value === event.detail.preference) return;
        select.value = event.detail.preference;
        syncChoiceLabel(select);
    });
}

/** Profile: "Save changes" appears once something was edited (or when there are errors). */
export function initProfileForm(form = $('[data-profile-form]')) {
    const bar = form?.querySelector('[data-profile-save]');
    if (!bar) return;
    if (!form.querySelector('.is-invalid, .form-error')) bar.hidden = true;
    const show = () => {
        bar.hidden = false;
    };
    form.addEventListener('input', show);
    form.addEventListener('change', show);
    form.querySelector('[data-avatar-clear]')?.addEventListener('click', show);

    // A new email needs the current password.
    const email = form.querySelector('input[name="email"]');
    const password = form.querySelector('[data-email-password]');
    if (email && password) {
        const original = email.defaultValue.trim().toLowerCase();
        email.addEventListener('input', () => {
            if (password.querySelector('.is-invalid')) return;
            password.hidden = email.value.trim().toLowerCase() === original;
        });
    }
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

/** QR code (A4): the QR library loads only when the code is on the page. */
export function initProfileQr(box = $('[data-profile-qr]')) {
    if (!box) return Promise.resolve(false);
    return import('../lib/qr')
        .then(({ qrSvg }) => {
            box.innerHTML = qrSvg(box.dataset.profileQr);
            return true;
        })
        .catch(() => {
            box.textContent = "Couldn't show the QR code.";
            return false;
        });
}

/** Buttons that share (on phones) or copy a link, such as the QR code link. */
function initCopyButtons(container) {
    container.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-text], [data-share-link]');
        if (!button) return;
        if (button.dataset.shareLink && navigator.share) {
            try {
                await navigator.share({ title: button.dataset.shareTitle || document.title, url: button.dataset.shareLink });
            } catch {
                /* closed the share sheet */
            }
            return;
        }
        try {
            await navigator.clipboard.writeText(button.dataset.copyText ?? button.dataset.shareLink);
            toast.success('Link copied.', { timeout: 2000 });
        } catch {
            toast.error("Couldn't copy the link. Select it and copy it yourself.");
        }
    });
}

export function initSettings(config) {
    const container = $('[data-settings-tabs]');
    if (!container) return;
    initSettingsNav(container.matches('[data-settings]') ? container : null);
    initBlockPicker();
    initPreferenceSwitches(config);
    initThemeChoice();
    initProfileForm();
    initBrowserNotificationButton();
    initCopyButtons(container);
    initProfileQr();
}
