import { html, raw } from './dom';
import { icon } from './icons';

/**
 * Accessible confirmation dialog.
 *
 * @returns {Promise<string|null>} value of the chosen action, or null when cancelled.
 */
export function confirmDialog({
    title,
    message = '',
    icon: iconName = 'circle-alert',
    tone = 'danger',
    actions = [{ label: 'Confirm', value: 'confirm', variant: 'danger' }],
    cancelLabel = 'Cancel',
}) {
    return new Promise((resolve) => {
        const previouslyFocused = document.activeElement;
        const wrapper = document.createElement('div');
        wrapper.className = 'modal';
        wrapper.setAttribute('role', 'dialog');
        wrapper.setAttribute('aria-modal', 'true');
        wrapper.setAttribute('aria-labelledby', 'modal-title');

        const iconStyle =
            tone === 'danger' ? '' : 'style="background: var(--c-primary-soft); color: var(--c-primary)"';

        wrapper.innerHTML = html`
            <div class="modal-backdrop" data-modal-cancel></div>
            <div class="modal-panel">
                <div class="modal-icon" ${raw(iconStyle)}>${raw(icon(iconName))}</div>
                <h2 class="modal-title" id="modal-title">${title}</h2>
                ${message ? raw(html`<p class="modal-text">${message}</p>`) : ''}
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-modal-cancel>${cancelLabel}</button>
                    ${raw(
                        actions
                            .map(
                                (a) =>
                                    html`<button type="button" class="btn btn-${a.variant ?? 'primary'}" data-modal-value="${a.value}">${a.label}</button>`,
                            )
                            .join(''),
                    )}
                </div>
            </div>
        `;

        const close = (value) => {
            document.removeEventListener('keydown', onKey);
            wrapper.remove();
            previouslyFocused?.focus?.();
            resolve(value);
        };

        const onKey = (event) => {
            if (event.key === 'Escape') close(null);
            if (event.key === 'Tab') {
                const focusables = wrapper.querySelectorAll('button');
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };

        wrapper.addEventListener('click', (event) => {
            if (event.target.closest('[data-modal-cancel]')) close(null);
            const action = event.target.closest('[data-modal-value]');
            if (action) close(action.dataset.modalValue);
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(wrapper);
        wrapper.querySelector('[data-modal-value]')?.focus();
    });
}
