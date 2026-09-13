import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * M21 — Disappearing messages: per-chat timer, notices, and removing expired
 * messages from the screen (the server removes them for both people).
 */

export const DISAPPEARING_OPTIONS = [
    { seconds: 0, label: 'Off' },
    { seconds: 86400, label: '24 hours' },
    { seconds: 604800, label: '7 days' },
    { seconds: 7776000, label: '90 days' },
];

export function timerLabel(seconds) {
    return DISAPPEARING_OPTIONS.find((option) => option.seconds === Number(seconds || 0))?.label ?? `${Math.round(seconds / 3600)} hours`;
}

/** Ids of messages whose time is up. */
export function expiredIds(messages, now = Date.now()) {
    return messages.filter((message) => message.expires_at && Date.parse(message.expires_at) <= now).map((message) => message.id);
}

export class DisappearingMessages {
    constructor(chat) {
        this.chat = chat;

        document.addEventListener('chat:menu', (event) => {
            if (!chat.api.has('disappearing')) return;
            const seconds = event.detail.conversation?.disappearing_seconds;
            event.detail.items.push(html`<button type="button" class="dropdown-item" data-action="disappearing" role="menuitem">${raw(icon('timer'))} Disappearing messages<span class="dropdown-item-hint">${seconds ? timerLabel(seconds) : 'Off'}</span></button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'disappearing') this.open();
        });

        // A small timer next to the name while it is on.
        document.addEventListener('chat:header', (event) => this.decorateHeader(event.detail.conversation));

        setInterval(() => this.removeExpired(), 15_000);
    }

    decorateHeader(conversation) {
        const name = this.chat.el.headerUser.querySelector('.chat-header-name');
        if (!name) return;
        name.querySelector('.chat-header-timer')?.remove();
        if (conversation?.disappearing_seconds) {
            name.insertAdjacentHTML('beforeend', html`<span class="chat-header-timer" title="Disappearing messages: ${timerLabel(conversation.disappearing_seconds)}">${raw(icon('timer'))}</span>`);
        }
    }

    open() {
        const conversation = this.chat.activeConversation();
        if (!conversation) return;

        const current = Number(conversation.disappearing_seconds || 0);
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal disappearing-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'disappearing-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-disappearing-cancel></div>
            <form class="modal-panel disappearing-panel" data-disappearing-form>
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('timer'))}</div>
                <h2 class="modal-title" id="disappearing-title">Disappearing messages</h2>
                <p class="modal-text">New messages in this chat disappear for both of you after the time you choose. Messages sent before are not affected.</p>
                <div class="disappearing-options" role="radiogroup" aria-label="Timer">
                    ${raw(DISAPPEARING_OPTIONS.map((option) => html`
                        <label class="disappearing-option">
                            <input type="radio" name="seconds" value="${option.seconds}" ${raw(option.seconds === current ? 'checked' : '')}>
                            <span>${option.label}</span>
                        </label>`).join(''))}
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-disappearing-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        `;

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-disappearing-cancel]')) close();
        });
        overlay.querySelector('[data-disappearing-form]').addEventListener('submit', (event) => {
            event.preventDefault();
            const seconds = Number(new FormData(event.target).get('seconds') || 0);
            close();
            if (seconds !== current) this.save(conversation.id, seconds);
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input:checked')?.focus();
    }

    async save(conversationId, seconds) {
        try {
            const result = await this.chat.api.setDisappearing(conversationId, seconds);
            const merged = this.chat.upsertConversation({ id: conversationId, disappearing_seconds: result.disappearing_seconds });

            if (result.message) {
                const message = this.chat.normalizeMessage(result.message);
                if (this.chat.active?.id === conversationId) this.chat.appendMessage(message);
                this.chat.onOwnMessageStored(message);
            }
            if (this.chat.active?.id === conversationId) this.chat.renderHeader(merged);
        } catch (error) {
            toast.error(errorMessage(error, 'The setting could not be changed.'));
        }
    }

    /** A notice arrived from the other person: refresh the chat settings. */
    onNotice(message) {
        if (message.type === 'system' && message.system?.event === 'disappearing') {
            this.chat.refreshConversation(Number(message.conversation_id));
        }
    }

    /** The server removed expired messages. */
    onExpired({ conversation_id: conversationId, ids }) {
        for (const id of ids ?? []) this.chat.onMessageHidden({ id, conversation_id: conversationId });
        this.chat.refreshConversation(Number(conversationId));
    }

    removeExpired() {
        const messages = this.chat.active?.messages ?? [];
        for (const id of expiredIds(messages)) {
            this.chat.onMessageHidden({ id, conversation_id: this.chat.active.id });
        }
    }
}
