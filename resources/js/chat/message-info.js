import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatDateTime } from './format';
import * as T from './templates';

/**
 * "Message info" for your own messages: when it was sent, delivered and read.
 * In a group (G6): who read it and when, who it was delivered to, and who hasn't got it yet.
 */
export function openMessageInfo(message, chat = null) {
    const previouslyFocused = document.activeElement;
    const group = ['group', 'broadcast'].includes(chat?.conversations.get(Number(message.conversation_id))?.type);

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
            <div data-info-body>${raw(group ? '<div class="flex justify-center p-4 text-primary"><span class="spinner"></span></div>' : directRows(message))}</div>
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

    if (group) {
        chat.api.messageReceipts(message.id)
            .then((data) => {
                if (wrapper.isConnected) wrapper.querySelector('[data-info-body]').innerHTML = groupRows(message, data.data ?? [], chat);
            })
            .catch((error) => {
                if (wrapper.isConnected) wrapper.querySelector('[data-info-body]').innerHTML = html`<p class="sticker-empty">${errorMessage(error, "Couldn't load who read this message.")}</p>`;
            });
    }

    return close;
}

function directRows(message) {
    const rows = [
        { iconName: 'check-check', tone: 'is-read', label: 'Read', value: message.seen_at },
        { iconName: 'check-check', tone: '', label: 'Delivered', value: message.delivered_at ?? message.seen_at },
        { iconName: 'check', tone: '', label: 'Sent', value: message.sent_at ?? message.created_at },
    ];

    return html`
        <dl class="message-info-list">
            ${raw(rows.map((row) => html`
                <div class="message-info-row">
                    <dt><span class="message-info-icon ${row.tone}">${raw(icon(row.iconName))}</span>${row.label}</dt>
                    <dd>${row.value ? formatDateTime(row.value) : '—'}</dd>
                </div>`).join(''))}
        </dl>
    `;
}

/**
 * Split group receipts into "Read by", "Delivered to" and "Waiting".
 *
 * @param {{user: object, delivered_at: ?string, seen_at: ?string}[]} receipts
 */
export function receiptGroups(receipts) {
    const byTime = (key) => (a, b) => Date.parse(b[key]) - Date.parse(a[key]);
    return {
        read: receipts.filter((r) => r.seen_at).sort(byTime('seen_at')),
        delivered: receipts.filter((r) => r.delivered_at && !r.seen_at).sort(byTime('delivered_at')),
        waiting: receipts.filter((r) => !r.delivered_at && !r.seen_at),
    };
}

function groupRows(message, receipts, chat) {
    const { read, delivered, waiting } = receiptGroups(receipts);
    const person = (receipt, time) => {
        const user = chat.decorate({ ...receipt.user, name: receipt.user.saved_name || receipt.user.name });
        return html`
            <div class="receipt-row">
                ${raw(T.avatar(user, 'sm'))}
                <span class="receipt-name">${user.name}</span>
                <span class="receipt-time">${time ? formatDateTime(time) : ''}</span>
            </div>`;
    };
    const section = (title, iconName, tone, items, timeKey, empty) => html`
        <section class="receipt-section">
            <h3 class="receipt-title"><span class="message-info-icon ${tone}">${raw(icon(iconName))}</span>${title}</h3>
            ${raw(items.length ? items.map((r) => person(r, timeKey ? r[timeKey] : null)).join('') : html`<p class="receipt-empty">${empty}</p>`)}
        </section>`;

    return [
        section('Read by', 'check-check', 'is-read', read, 'seen_at', 'Nobody has read it yet.'),
        section('Delivered to', 'check-check', '', delivered, 'delivered_at', read.length ? 'Everyone else has read it.' : 'Not delivered yet.'),
        waiting.length ? section('Waiting', 'clock', '', waiting, null, '') : '',
        html`<p class="receipt-sent">Sent ${formatDateTime(message.sent_at ?? message.created_at)}</p>`,
    ].join('');
}
