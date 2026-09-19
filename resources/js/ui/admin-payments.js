import axios from '../bootstrap';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';

/**
 * Admin → Payments & settings (Y2): the per-gateway "Test connection" buttons in App settings,
 * click-to-copy for webhook URLs and transfer ids, and the confirm step before a rejection or a
 * refund (both take a note the person or the audit log will see).
 */

const escape = (text) =>
    String(text).replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c],
    );

/** POST the check URL of one gateway and show {ok, message} under the buttons. */
export async function runPayCheck(button, output, { http = axios } = {}) {
    if (!button || !output) return null;
    const label = button.dataset.payCheck || 'gateway';
    button.disabled = true;
    output.hidden = false;
    output.dataset.state = 'busy';
    output.innerHTML = `${icon('loader-circle')} Checking ${escape(label)}…`;
    try {
        const { data } = await http.post(button.dataset.url);
        const ok = Boolean(data?.ok);
        output.dataset.state = ok ? 'ok' : 'bad';
        output.innerHTML = `${icon(ok ? 'check' : 'circle-alert')} ${escape(data?.message ?? (ok ? 'Works.' : 'Failed.'))}`;
        return data;
    } catch (error) {
        const message =
            error?.response?.status === 429
                ? 'Too many checks — wait a minute.'
                : error?.response?.data?.message || 'The check could not run. Save the settings first, then try again.';
        output.dataset.state = 'bad';
        output.innerHTML = `${icon('circle-alert')} ${escape(message)}`;
        return null;
    } finally {
        button.disabled = false;
    }
}

/** Copy the text next to a [data-copy] button ([data-copy-source] sibling, or the element itself when it holds text). */
export function bindCopy(root = document) {
    root.querySelectorAll('[data-copy]').forEach((button) => {
        if (button.dataset.copyBound) return;
        button.dataset.copyBound = '1';
        button.addEventListener('click', async () => {
            const source =
                button.parentElement?.querySelector('[data-copy-source]') ?? (button.matches('code, [data-copy-source]') ? button : null);
            const text = source?.textContent?.trim() ?? '';
            if (!text) return;
            const original = button.innerHTML;
            try {
                await navigator.clipboard.writeText(text);
                button.innerHTML = button.matches('code') ? text : `${icon('check')} Copied`;
                if (button.matches('code')) button.classList.add('is-copied');
            } catch {
                button.innerHTML = button.matches('code') ? text : `${icon('circle-alert')} Select and copy`;
            }
            setTimeout(() => {
                button.innerHTML = original;
                button.classList.remove('is-copied');
            }, 1800);
        });
    });
}

/** Reject / refund forms: the note is required and the action is confirmed once. */
export function bindNoteForms(root = document, { confirm = confirmDialog } = {}) {
    root.querySelectorAll('form[data-pay-note]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === '1') return;
            event.preventDefault();
            const note = form.querySelector('[name="note"]');
            const kind = form.dataset.payNote;
            if (note && !note.value.trim()) {
                note.classList.add('is-invalid');
                note.focus();
                return;
            }
            const choice = await confirm({
                title: kind === 'reject' ? 'Reject this payment?' : 'Refund this payment?',
                message:
                    kind === 'reject'
                        ? 'The person is told it was not approved, with your note. Nothing is credited.'
                        : 'Coins are taken back (or the plan ended). This cannot be undone.',
                icon: kind === 'reject' ? 'x' : 'banknote',
                tone: 'danger',
                actions: [
                    {
                        label: kind === 'reject' ? 'Reject' : 'Refund',
                        value: 'yes',
                        variant: 'danger',
                    },
                ],
            });
            if (choice === 'yes') {
                form.dataset.confirmed = '1';
                form.requestSubmit();
            }
        });
    });
}

export function initAdminPayments(root = document, { http = axios, confirm = confirmDialog } = {}) {
    const buttons = [...root.querySelectorAll('[data-pay-check]')];
    const output = root.querySelector('[data-pay-check-result]');
    buttons.forEach((button) => button.addEventListener('click', () => runPayCheck(button, output, { http })));

    bindCopy(root);
    bindNoteForms(root, { confirm });

    return { buttons, output };
}
