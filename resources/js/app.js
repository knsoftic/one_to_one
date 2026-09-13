import '@fontsource-variable/inter';
import './bootstrap';

import { readJsonScript } from './lib/dom';
import { initDropdowns } from './lib/dropdown';
import { confirmDialog } from './lib/modal';
import { initTheme } from './lib/theme';
import { toast } from './lib/toast';
import { initForms } from './ui/forms';
import { initSettings } from './ui/settings';

const config = readJsonScript('app-config');
window.App = { config, toast };

initTheme(config);
initDropdowns();
initForms();
initSettings(config);

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

if (config.flash?.status) toast.success(config.flash.status);
if (config.flash?.error) toast.error(config.flash.error);
