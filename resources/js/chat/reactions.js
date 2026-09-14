import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { EmojiPicker } from './emoji';
import * as T from './templates';

export const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

/**
 * Reactions after `userId` picks `emoji` (null removes theirs), grouped like the server does.
 */
export function applyReaction(reactions, userId, emoji) {
    const id = Number(userId);
    const next = [];

    for (const reaction of reactions ?? []) {
        const userIds = reaction.user_ids.filter((uid) => Number(uid) !== id);
        // Channels (G11) only list me, so the count is not the number of ids.
        const count = Number(reaction.count ?? reaction.user_ids.length) - (userIds.length < reaction.user_ids.length ? 1 : 0);
        if (count > 0) next.push({ emoji: reaction.emoji, count, user_ids: userIds });
    }

    if (emoji) {
        const existing = next.find((reaction) => reaction.emoji === emoji);
        if (existing) {
            existing.user_ids.push(id);
            existing.count++;
        } else {
            next.push({ emoji, count: 1, user_ids: [id] });
        }
    }

    return next;
}

/** The emoji `userId` reacted with, or null. */
export function reactionOf(reactions, userId) {
    return (reactions ?? []).find((reaction) => reaction.user_ids.some((uid) => Number(uid) === Number(userId)))?.emoji ?? null;
}

/** Quick reaction row used in the message menu and the hover popover. */
export function reactionBar(message, meId) {
    const mine = reactionOf(message.reactions, meId);
    return html`
        <div class="reaction-bar" role="group" aria-label="React">
            ${raw(
                QUICK_REACTIONS.map(
                    (emoji) => html`<button type="button" class="reaction-option${emoji === mine ? ' is-active' : ''}" data-react-emoji="${emoji}" aria-label="React ${emoji}" aria-pressed="${emoji === mine ? 'true' : 'false'}">${emoji}</button>`,
                ).join(''),
            )}
            <button type="button" class="reaction-option reaction-more${mine && !QUICK_REACTIONS.includes(mine) ? ' is-active' : ''}" data-react-more aria-label="More reactions">${raw(mine && !QUICK_REACTIONS.includes(mine) ? mine : icon('smile-plus'))}</button>
        </div>
    `;
}

/**
 * Emoji reactions: quick bar, full picker, reaction pill with who reacted.
 */
export class Reactions {
    constructor(chat) {
        this.chat = chat;
        this.popover = null;
        this.pickerHost = null;
        this.picker = null;

        const list = chat.el.messageList;

        list.addEventListener('click', (event) => {
            const pill = event.target.closest('[data-reactions]');
            if (pill) {
                event.stopPropagation();
                const message = this.messageFor(pill);
                if (message) this.openDetails(message, pill);
                return;
            }

            const trigger = event.target.closest('[data-react-open]');
            if (trigger) {
                event.stopPropagation();
                const message = this.messageFor(trigger);
                if (message) this.openBar(message, trigger);
            }
        });

        document.addEventListener('click', (event) => {
            if (this.popover && !this.popover.contains(event.target)) this.closePopover();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closePopover();
                this.closePicker();
            }
        });
        chat.el.messages.addEventListener('scroll', () => this.closePopover(), { passive: true });
        document.addEventListener('chat:opened', () => this.closeAll());
        document.addEventListener('chat:closed', () => this.closeAll());
    }

    messageFor(element) {
        const row = element.closest('[data-message-id]');
        const message = row ? this.chat.active?.byId.get(row.dataset.messageId) : null;
        return message && typeof message.id === 'number' ? message : null;
    }

    canReact(message) {
        const conversation = this.chat.activeConversation();
        return Boolean(
            this.chat.api.has('messageReaction') &&
                typeof message.id === 'number' &&
                !message.is_deleted &&
                message.type !== 'call' &&
                conversation?.type !== 'broadcast' &&
                !conversation?.blocked_by_me &&
                !conversation?.blocked_me,
        );
    }

    /** Toggle: the same emoji again removes the reaction. */
    async toggle(message, emoji) {
        const meId = this.chat.me.id;
        const current = this.chat.active?.byId.get(String(message.id)) ?? message;
        const next = reactionOf(current.reactions, meId) === emoji ? null : emoji;
        const previous = current.reactions ?? [];

        this.chat.updateMessage({ id: message.id, reactions: applyReaction(previous, meId, next) });
        if (next) navigator.vibrate?.(8);

        try {
            const data = next ? await this.chat.api.react(message.id, next) : await this.chat.api.unreact(message.id);
            this.chat.updateMessage({ id: message.id, reactions: data.reactions });
        } catch (error) {
            this.chat.updateMessage({ id: message.id, reactions: previous });
            toast.error(errorMessage(error, 'Could not save your reaction.'));
        }
    }

    /** Hover / tap button next to a bubble: quick reactions only. */
    openBar(message, anchor) {
        this.closeAll();
        if (!this.canReact(message)) return;

        const popover = document.createElement('div');
        popover.className = 'reaction-popover';
        popover.innerHTML = reactionBar(message, this.chat.me.id);
        this.place(popover, anchor, message.is_mine);

        popover.addEventListener('click', (event) => {
            event.stopPropagation();
            const option = event.target.closest('[data-react-emoji]');
            if (option) {
                this.closePopover();
                this.toggle(message, option.dataset.reactEmoji);
            } else if (event.target.closest('[data-react-more]')) {
                this.closePopover();
                this.openPicker(message);
            }
        });
    }

    /** Full emoji list for any reaction. */
    openPicker(message) {
        this.closePicker();

        this.pickerHost = document.createElement('div');
        this.pickerHost.className = 'reaction-picker-host';
        this.pickerHost.innerHTML = '<div class="reaction-picker-backdrop" data-reaction-picker-close></div>';
        this.pickerHost.addEventListener('click', (event) => {
            if (event.target.closest('[data-reaction-picker-close]')) this.closePicker();
        });
        document.body.appendChild(this.pickerHost);

        this.picker = new EmojiPicker(this.pickerHost, (emoji) => {
            this.closePicker();
            this.toggle(message, emoji);
        });
        // Opened by a click that must not immediately close it.
        setTimeout(() => this.picker?.open(), 0);
    }

    /** Who reacted with what; your own row removes your reaction. */
    openDetails(message, anchor) {
        this.closeAll();

        const meId = Number(this.chat.me.id);
        const rows = [];
        // Channels (G11): how many reacted with what; only your own reaction is yours to see.
        if (this.chat.activeConversation()?.type === 'channel') {
            return this.openCounts(message, anchor, meId);
        }
        for (const reaction of message.reactions ?? []) {
            for (const userId of reaction.user_ids) {
                const mine = Number(userId) === meId;
                const conversation = this.chat.activeConversation();
                const known = this.chat.users.get(Number(userId)) ?? { id: userId };
                const user = mine
                    ? this.chat.me
                    : conversation?.type === 'group'
                      ? this.chat.decorate(known)
                      : this.chat.participantOf(conversation) ?? {};
                rows.push({ mine, user, emoji: reaction.emoji });
            }
        }
        rows.sort((a, b) => Number(b.mine) - Number(a.mine));

        const popover = document.createElement('div');
        popover.className = 'reaction-popover reaction-details';
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', 'Reactions');
        popover.innerHTML = rows
            .map((row) =>
                row.mine && this.canReact(message)
                    ? html`<button type="button" class="reaction-row is-mine" data-reaction-remove>
                          ${raw(T.avatar(row.user, 'sm'))}
                          <span class="reaction-row-name">You<small>Tap to remove</small></span>
                          <span class="reaction-row-emoji">${row.emoji}</span>
                      </button>`
                    : html`<div class="reaction-row">
                          ${raw(T.avatar(row.user, 'sm'))}
                          <span class="reaction-row-name">${row.mine ? 'You' : row.user.name ?? ''}</span>
                          <span class="reaction-row-emoji">${row.emoji}</span>
                      </div>`,
            )
            .join('');

        this.place(popover, anchor, message.is_mine);

        popover.addEventListener('click', (event) => {
            event.stopPropagation();
            if (event.target.closest('[data-reaction-remove]')) {
                this.closePopover();
                const mine = reactionOf(message.reactions, meId);
                if (mine) this.toggle(message, mine);
            }
        });
    }

    openCounts(message, anchor, meId) {
        const mine = reactionOf(message.reactions, meId);
        const popover = document.createElement('div');
        popover.className = 'reaction-popover reaction-details';
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', 'Reactions');
        popover.innerHTML = (message.reactions ?? [])
            .map((reaction) => (reaction.emoji === mine && this.canReact(message)
                ? html`<button type="button" class="reaction-row is-mine" data-reaction-remove>
                      <span class="reaction-row-emoji">${reaction.emoji}</span>
                      <span class="reaction-row-name">${reaction.count}<small>Includes you · tap to remove</small></span>
                  </button>`
                : html`<div class="reaction-row">
                      <span class="reaction-row-emoji">${reaction.emoji}</span>
                      <span class="reaction-row-name">${reaction.count}</span>
                  </div>`))
            .join('');

        this.place(popover, anchor, message.is_mine);
        popover.addEventListener('click', (event) => {
            event.stopPropagation();
            if (event.target.closest('[data-reaction-remove]') && mine) {
                this.closePopover();
                this.toggle(message, mine);
            }
        });
    }

    place(popover, anchor, alignRight) {
        document.body.appendChild(popover);
        const rect = anchor.getBoundingClientRect();
        const { width, height } = popover.getBoundingClientRect();
        let left = alignRight ? rect.right - width : rect.left;
        let top = rect.top - height - 8;
        if (top < 8) top = rect.bottom + 8;
        left = Math.min(Math.max(8, left), window.innerWidth - width - 8);
        Object.assign(popover.style, { left: `${left}px`, top: `${Math.min(top, window.innerHeight - height - 8)}px` });
        this.popover = popover;
        this.popoverOpenedAt = Date.now();
    }

    closePopover() {
        this.popover?.remove();
        this.popover = null;
    }

    closePicker() {
        this.picker?.close();
        this.picker = null;
        this.pickerHost?.remove();
        this.pickerHost = null;
    }

    closeAll() {
        this.closePopover();
        this.closePicker();
    }
}
