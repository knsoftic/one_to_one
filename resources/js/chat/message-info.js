import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatDateTime } from './format';
import * as T from './templates';

/**
 * "Message info" for your own messages: when it was sent, delivered and read.
 */
export function openMessageInfo(message) {
    const previouslyFocused = document.activeElement;
    const rows = [
        { iconName: 'check-check', tone: 'is-read', label: 'Read', value: message.seen_at },
        { iconName: 'check-check', tone: '', label: 'Delivered', value: message.delivered_at ?? message.seen_at },
        { iconName: 'check', tone: '', label: 'Sent', value: message.sent_at ?? message.created_at },
    ];

    const wrapper = document.createElement('div');
    wrapper.className = 'modal';
    wrapper.setAttribute('role', 'dialog');
    wrapper.setAttribute('aria-modal', 'true');
    wrapper.setAttribute('aria-labelledby', 'message-info-title');
    wrapper.innerHTML = html`
        <div class="modal-backdrop" data-info-close></div>
        <div class="modal-panel message-info-panel">
            <div class="forward-head">
                <h2 class="modal-title" id="message-info-title">Message info</h2>
                <button type="button" class="btn-icon" data-info-close aria-label="Close">${raw(icon('x'))}</button>
            </div>
            <p class="message-info-preview">${T.previewOf(message) || 'Message'}</p>
            <dl class="message-info-list">
                ${raw(
                    rows
                        .map(
                            (row) => html`
                                <div class="message-info-row">
                                    <dt><span class="message-info-icon ${row.tone}">${raw(icon(row.iconName))}</span>${row.label}</dt>
                                    <dd>${row.value ? formatDateTime(row.value) : '—'}</dd>
                                </div>
                            `,
                        )
                        .join(''),
                )}
            </dl>
        </div>
    `;

    const close = () => {
        document.removeEventListener('keydown', onKey);
        wrapper.remove();
        previouslyFocused?.focus?.();
    };
    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };

    wrapper.addEventListener('click', (event) => {
        if (event.target.closest('[data-info-close]')) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrapper);
    wrapper.querySelector('[data-info-close].btn-icon')?.focus();

    return close;
}
