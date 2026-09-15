import { $, $$ } from '../lib/dom';

const CONFIRM = {
    logout: (n) => `${n} will be signed out of every browser and phone.`,
    suspend: (n) => `${n} will be signed out and can't sign in until activated.`,
    activate: (n) => `${n} will be able to sign in and use the app.`,
    ban: (n) => `${n} will be signed out and see a ban screen with your reason.`,
    unban: (n) => `Banned accounts among ${n} can use the app again.`,
    delete: (n) => `${n} will be deleted for good with their chats, messages and files. This cannot be undone.`,
};

/**
 * Admin → Users: select accounts (or the whole page) and change them at once.
 */
export function initAdminBulk(root = $('[data-admin-bulk]')) {
    if (!root) return null;

    const all = $('[data-bulk-all]', root);
    const count = $('[data-bulk-count]', root);
    const controls = $('[data-bulk-controls]', root);
    const action = $('[data-bulk-action]', root);
    const ban = $('[data-bulk-ban]', root);
    const submit = $('[data-bulk-submit]', root);
    const form = submit.form;
    const items = () => $$('[data-bulk-item]', root);

    const sync = () => {
        const boxes = items();
        const selected = boxes.filter((box) => box.checked).length;
        const people = selected === 1 ? '1 account' : `${selected} accounts`;

        count.textContent = selected ? `${people} selected` : 'Select';
        controls.hidden = selected === 0;
        all.checked = selected > 0 && selected === boxes.length;
        all.indeterminate = selected > 0 && selected < boxes.length;
        all.disabled = boxes.length === 0;

        const kind = action.value;
        ban.hidden = kind !== 'ban';
        ban.querySelector('[name="reason"]').required = kind === 'ban';
        const risky = ['ban', 'delete', 'suspend'].includes(kind);
        // The app's confirmation dialog reads these from the form before it is sent.
        form.dataset.confirm = (CONFIRM[kind] ?? CONFIRM.logout)(people);
        form.dataset.confirmTitle = 'Change the selected accounts?';
        form.dataset.confirmLabel = action.options[action.selectedIndex]?.textContent.replace('…', '') ?? 'Apply';
        form.toggleAttribute('data-confirm-danger', kind === 'delete');
        submit.classList.toggle('btn-danger', risky);
        submit.classList.toggle('btn-primary', !risky);
    };

    all.addEventListener('change', () => {
        items().forEach((box) => { box.checked = all.checked; });
        sync();
    });
    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-bulk-item], [data-bulk-action]')) sync();
    });

    sync();
    return { sync };
}
