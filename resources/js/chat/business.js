import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/**
 * X8 — business tools in the chat: quick replies ("/" in the typing box), label colours
 * for chat lists, and a business's details in the contact info.
 */

export const LABEL_COLORS = ['indigo', 'green', 'amber', 'red', 'pink', 'sky', 'teal', 'slate'];

export const MAX_SUGGESTIONS = 8;

/** The "/shortcut" being typed right before the cursor, or null ("http://…" doesn't count). */
export function quickReplyQuery(text, caret) {
    const before = String(text ?? '').slice(0, caret);
    const match = before.match(/(^|\s)\/([A-Za-z0-9_-]{0,24})$/);
    return match ? { query: match[2].toLowerCase(), start: caret - match[2].length - 1 } : null;
}

/** Quick replies for a query: shortcuts starting with it first, then ones whose text mentions it. */
export function quickReplyMatches(replies, query) {
    const q = String(query ?? '').toLowerCase();
    return (replies ?? [])
        .map((reply) => {
            const shortcut = reply.shortcut.toLowerCase();
            const rank = !q || shortcut.startsWith(q) ? 0 : shortcut.includes(q) ? 1 : reply.message.toLowerCase().includes(q) ? 2 : -1;
            return { reply, rank };
        })
        .filter((entry) => entry.rank >= 0)
        .sort((a, b) => a.rank - b.rank || a.reply.shortcut.localeCompare(b.reply.shortcut))
        .slice(0, MAX_SUGGESTIONS)
        .map((entry) => entry.reply);
}

/** Coloured lists (labels) a chat is in. */
export function labelsFor(conversationId, lists) {
    const id = Number(conversationId);
    return (lists ?? []).filter((list) => list.color && LABEL_COLORS.includes(list.color) && list.conversation_ids.includes(id));
}

/** Small coloured dots for a chat row. */
export function labelDots(labels) {
    if (!labels?.length) return '';
    const names = labels.map((label) => label.name).join(', ');
    return html`<span class="label-dots" title="${names}" aria-label="Labels: ${names}">${raw(labels.slice(0, 3).map((label) => `<span class="label-dot is-${label.color}"></span>`).join(''))}</span>`;
}

/** Only http(s) links are shown as links. */
export function safeWebsite(url) {
    try {
        const parsed = new URL(String(url));
        return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : null;
    } catch {
        return null;
    }
}

/** "Open now", "Closed now", "Open 24 hours", "By appointment only". */
export function openLabel(business) {
    if (business.hours_mode === 'always') return { text: 'Open 24 hours', state: 'open' };
    if (business.hours_mode === 'appointment') return { text: 'By appointment only', state: 'neutral' };
    if (business.open_now === true) return { text: 'Open now', state: 'open' };
    if (business.open_now === false) return { text: 'Closed now', state: 'closed' };
    return null;
}

/** The business section of the contact info. */
export function businessSection(business) {
    if (!business) return '';
    const status = openLabel(business);
    const website = safeWebsite(business.website);
    const hours = (business.hours ?? [])
        .map((day) => html`<li><span>${day.day}</span><span>${day.open ? `${day.from} – ${day.to}` : 'Closed'}</span></li>`)
        .join('');

    return html`
        <section class="group-info-section business-info">
            <span class="business-info-badge">${raw(icon('briefcase-business'))} Business account · ${business.category_label}</span>
            ${raw(business.description ? html`<p class="group-info-description">${business.description}</p>` : '')}
            ${raw(business.address ? html`<p class="business-info-row">${raw(icon('map-pinned'))}<span>${business.address}</span></p>` : '')}
            ${raw(status ? html`
                <details class="business-info-hours"${raw(hours ? '' : ' data-empty')}>
                    <summary class="business-info-row">${raw(icon('clock-8'))}<span class="business-open is-${status.state}">${status.text}</span>${raw(hours ? icon('chevron-down', 'business-info-more') : '')}</summary>
                    ${raw(hours ? `<ul class="business-hours-list">${hours}</ul>` : '')}
                </details>` : '')}
            ${raw(business.email ? html`<a class="business-info-row" href="mailto:${business.email}">${raw(icon('mail'))}<span>${business.email}</span></a>` : '')}
            ${raw(website ? html`<a class="business-info-row" href="${website}" target="_blank" rel="noopener noreferrer nofollow">${raw(icon('globe'))}<span>${business.website}</span></a>` : '')}
        </section>`;
}

/** Quick replies popup above the typing box. */
export class QuickReplies {
    constructor(chat) {
        this.chat = chat;
        this.replies = null;
        this.loading = null;
        this.state = null;

        const { composerInput, composerForm } = chat.el;
        if (!chat.config.business?.enabled || !chat.api.has('quickReplies') || !composerInput || !composerForm) return;

        this.popup = document.createElement('div');
        this.popup.className = 'mention-popup quick-reply-popup';
        this.popup.setAttribute('role', 'listbox');
        this.popup.setAttribute('aria-label', 'Quick replies');
        this.popup.hidden = true;
        composerForm.parentElement.insertBefore(this.popup, composerForm);

        composerInput.addEventListener('input', () => this.update());
        composerInput.addEventListener('blur', () => setTimeout(() => this.close(), 150));
        // Capture: runs before the composer's own Enter-to-send.
        composerForm.addEventListener('keydown', (event) => this.onKey(event), true);

        this.popup.addEventListener('mousedown', (event) => event.preventDefault());
        this.popup.addEventListener('click', (event) => {
            const option = event.target.closest('[data-quick-reply]');
            if (option) this.insert(Number(option.dataset.quickReply));
        });
        document.addEventListener('chat:opened', () => this.close());
    }

    async load() {
        if (this.replies) return this.replies;
        this.loading ??= this.chat.api.quickReplies()
            .then((response) => {
                this.replies = response.data ?? [];
                return this.replies;
            })
            .catch(() => [])
            .finally(() => {
                this.loading = null;
            });
        return this.loading;
    }

    async update() {
        const input = this.chat.el.composerInput;
        const found = quickReplyQuery(input.value, input.selectionStart ?? input.value.length);
        if (!found || !this.chat.activeConversation()) return this.close();

        const replies = await this.load();
        // The text may have changed while loading.
        const current = quickReplyQuery(input.value, input.selectionStart ?? input.value.length);
        if (!current || current.query !== found.query) return null;

        const matches = quickReplyMatches(replies, found.query);
        const index = this.state && this.state.query === found.query ? Math.min(this.state.index, Math.max(matches.length - 1, 0)) : 0;
        this.state = { ...found, matches, index };
        this.render();
        return null;
    }

    render() {
        const { matches, index } = this.state;
        const settings = this.chat.config.routes?.settings;
        const manage = settings ? html`<a class="quick-reply-manage" href="${settings}?tab=business">${raw(icon('pencil'))} Manage quick replies</a>` : '';
        this.popup.hidden = false;
        this.popup.innerHTML = (matches.length
            ? matches.map((reply, i) => html`
                <button type="button" class="mention-option quick-reply-option${i === index ? ' is-active' : ''}" data-quick-reply="${reply.id}" role="option" aria-selected="${i === index ? 'true' : 'false'}">
                    <span class="quick-reply-option-shortcut">/${reply.shortcut}</span>
                    <span class="quick-reply-option-text">${reply.message}</span>
                </button>`).join('')
            : html`<p class="quick-reply-empty">${raw(icon('zap'))} ${this.replies?.length ? 'No quick reply matches.' : 'No quick replies yet.'}</p>`) + manage;
    }

    onKey(event) {
        if (!this.state || this.popup.hidden) return;
        const { matches } = this.state;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.close();
            return;
        }
        if (!matches.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.state.index = (this.state.index + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            this.render();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.insert(matches[this.state.index].id);
        }
    }

    insert(id) {
        const reply = this.state?.matches.find((entry) => entry.id === id);
        if (!reply) return;
        const input = this.chat.el.composerInput;
        const caret = input.selectionStart ?? input.value.length;
        input.setRangeText(reply.message, this.state.start, caret, 'end');
        this.close();
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    close() {
        this.state = null;
        if (!this.popup) return;
        this.popup.hidden = true;
        this.popup.innerHTML = '';
    }
}
