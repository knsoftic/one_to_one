import './bootstrap';

import { readJsonScript } from './lib/dom';
import { initDropdowns } from './lib/dropdown';
import { confirmDialog } from './lib/modal';
import { isNativeApp } from './lib/native';
import { initTheme } from './lib/theme';
import { initI18n } from './lib/i18n';
import { toast } from './lib/toast';
import { initForms } from './ui/forms';
import { initNetworkStatus } from './ui/network';
import { initAdminBulk } from './ui/admin-bulk';
import { initAdminDocs } from './ui/admin-docs';
import { initAdminBrand } from './ui/admin-brand';
import { initAdminTurn } from './ui/admin-turn';
import { initAdminUi } from './ui/admin-ui';
import { initWebUpdateNotice } from './ui/app-update';
import { initSettings } from './ui/settings';
import { initInstallPrompt, listenForNotificationClicks, registerServiceWorker, syncPush } from './lib/web-push';

const config = readJsonScript('app-config');

// X1: Urdu text is swapped in by the browser (the dictionary is its own chunk).
const dictionaries = { ur: () => import('../../lang/ur.json') };
initI18n(async (locale) => (await (dictionaries[locale] ?? dictionaries.ur)()).default);
window.App = { config, toast };

initTheme(config);
initDropdowns();
initForms();
initSettings(config);
initNetworkStatus(config);
initAdminBulk();
initAdminDocs();
initAdminBrand();
initAdminTurn();
initAdminUi();
// X4: offer a reload when a new version was deployed while this tab was open.
initWebUpdateNotice(config);

// X3: installable app, offline page and push notifications (browsers only).
if (!isNativeApp()) {
    initInstallPrompt();
    registerServiceWorker().then(() => syncPush(config));
    listenForNotificationClicks((url) => {
        const match = url.pathname.match(/^\/chat\/(\d+)$/);
        if (!match || !window.Chat) return false;
        window.Chat.openConversation(Number(match[1]));
        return true;
    });
}

// Mobile app only: back button, status bar and push notifications (separate chunk).
if (isNativeApp()) {
    import('./native/app').then(({ initNativeApp }) => initNativeApp(config));
}

// Forms with data-confirm ask for confirmation before submitting (admin actions, etc.).
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;

    event.preventDefault();
    const choice = await confirmDialog({
        title: form.dataset.confirmTitle || 'Are you sure?',
        message: form.dataset.confirm,
        icon: form.hasAttribute('data-confirm-danger') ? 'trash-2' : 'circle-alert',
        tone: form.hasAttribute('data-confirm-danger') ? 'danger' : 'primary',
        actions: [{
            label: form.dataset.confirmLabel || 'Confirm',
            value: 'yes',
            variant: form.hasAttribute('data-confirm-danger') ? 'danger' : 'primary',
        }],
    });

    if (choice === 'yes') {
        form.dataset.confirmed = '1';
        form.requestSubmit();
    }
});

// Notice for everyone (admin panel): hidden in this browser once closed.
document.querySelectorAll('[data-app-notice]').forEach((notice) => {
    const key = `app-notice:${notice.dataset.appNotice}`;
    try {
        if (localStorage.getItem(key)) notice.remove();
    } catch {
        /* storage unavailable: keep showing it */
    }
    notice.querySelector('[data-app-notice-close]')?.addEventListener('click', () => {
        notice.remove();
        try {
            localStorage.setItem(key, '1');
        } catch {
            /* storage unavailable */
        }
    });
});

if (config.flash?.status) toast.success(config.flash.status);
if (config.flash?.error) toast.error(config.flash.error);
