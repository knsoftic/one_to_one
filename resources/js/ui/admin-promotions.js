import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/**
 * Admin → Promotions (Y2): review actions. Rejecting asks for the note the person will read
 * (the server requires it too); stopping goes through the shared data-confirm handler with the
 * "refund unused coins" tick in the form.
 */

/** A small dialog with one textarea; resolves with the text, or null when cancelled. */
export function noteDialog({ title, label = 'Note', placeholder = '', confirmLabel = 'Send', maxlength = 200 }) {
    return new Promise((resolve) => {
        const previouslyFocused = document.activeElement;
        const wrapper = document.createElement('div');
        wrapper.className = 'modal';
        wrapper.setAttribute('role', 'dialog');
        wrapper.setAttribute('aria-modal', 'true');
        wrapper.setAttribute('aria-labelledby', 'note-dialog-title');
        wrapper.innerHTML = html`
            <div class="modal-backdrop" data-note-cancel></div>
            <form class="modal-panel" data-note-form novalidate>
                <div class="modal-icon">${raw(icon('circle-alert'))}</div>
                <h2 class="modal-title" id="note-dialog-title">${title}</h2>
                <label class="form-group modal-text">
                    <span class="form-label">${label}</span>
                    <textarea class="form-control" rows="3" maxlength="${maxlength}" placeholder="${placeholder}" data-note-input required></textarea>
                    <p class="form-error" data-note-error hidden>${raw(icon('circle-alert'))} Write a short note first.</p>
                </label>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-note-cancel>Cancel</button>
                    <button type="submit" class="btn btn-danger">${confirmLabel}</button>
                </div>
            </form>
        `;
        document.body.appendChild(wrapper);

        const input = wrapper.querySelector('[data-note-input]');
        const close = (value) => {
            wrapper.remove();
            document.removeEventListener('keydown', onKey);
            previouslyFocused?.focus?.();
            resolve(value);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close(null);
        };
        document.addEventListener('keydown', onKey);
        wrapper.addEventListener('click', (event) => {
            if (event.target.closest('[data-note-cancel]')) close(null);
        });
        wrapper.querySelector('[data-note-form]').addEventListener('submit', (event) => {
            event.preventDefault();
            const value = input.value.trim();
            if (!value) {
                wrapper.querySelector('[data-note-error]').hidden = false;
                input.focus();
                return;
            }
            close(value);
        });
        input.focus();
    });
}

export function initAdminPromotions(root = document) {
    const reject = root.querySelector('[data-promotion-reject]');
    if (!reject) return null;

    reject.addEventListener('submit', async (event) => {
        const note = reject.querySelector('input[name="note"]');
        if (note?.value.trim() || reject.dataset.confirmed === '1') return;
        event.preventDefault();

        const text = await noteDialog({
            title: 'Reject this promotion?',
            label: 'Why? The person will read this and every coin goes back to them.',
            placeholder: 'e.g. The link goes to a page that asks for payment details.',
            confirmLabel: 'Reject and refund',
        });
        if (!text) return;
        note.value = text;
        reject.dataset.confirmed = '1';
        reject.requestSubmit();
    });

    return { reject };
}
