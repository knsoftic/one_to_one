import './bootstrap';

import { readJsonScript } from './lib/dom';
import { initDropdowns } from './lib/dropdown';
import { confirmDialog } from './lib/modal';
import { isNativeApp } from './lib/native';
import { initTheme } from './lib/theme';
import { toast } from './lib/toast';
import { initForms } from './ui/forms';
import { initNetworkStatus } from './ui/network';
import { initSettings } from './ui/settings';

const config = readJsonScript('app-config');
window.App = { config, toast };

initTheme(config);
initDropdowns();
initForms();
initSettings(config);
initNetworkStatus(config);

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
