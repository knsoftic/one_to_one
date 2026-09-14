import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import * as T from './templates';

/**
 * Phase 2 — the chat list: pin (C1), mute (C2), archive (C3), mark unread/read
 * (C4), clear and delete (C5). Settings are private to each person.
 */

const LONG_PRESS_MS = 450;

/** Time used to order a chat: its last message, or when it was cleared. */
function activityTime(conversation) {
    return Date.parse(conversation.last_message?.created_at ?? conversation.settings?.cleared_at ?? '') || 0;
}

/**
 * Chats for a list view: `chats` (not archived, pinned first), `archived` or `locked` (C9).
 */
export function chatListOrder(conversations, mode = 'chats') {
    return [...conversations]
        .filter((c) => (c.last_message || c.settings?.cleared_at) && !c.settings?.hidden)
        .filter((c) => (mode === 'locked') === Boolean(c.settings?.locked))
        .filter((c) => mode === 'locked' || (mode === 'archived') === Boolean(c.settings?.archived))
        .sort((a, b) => {
            const pinA = mode === 'chats' && a.settings?.pinned ? Date.parse(a.settings.pinned_at) || 1 : 0;
            const pinB = mode === 'chats' && b.settings?.pinned ? Date.parse(b.settings.pinned_at) || 1 : 0;
            if (pinA || pinB) return pinB - pinA;
            return activityTime(b) - activityTime(a) || (b.last_message?.id ?? 0) - (a.last_message?.id ?? 0);
        });
}

export function hasUnread(conversation) {
    return (conversation.unread_count || 0) > 0 || Boolean(conversation.settings?.marked_unread);
}

/** Locked chats (C9) for the folder row, including ones whose details are still hidden. */
export function lockedChats(conversations) {
    return [...conversations].filter((c) => c.settings?.locked && !c.settings?.hidden && (c.is_locked_out || c.last_message || c.settings?.cleared_at));
}

/** Unread messages shown in the title and badges: muted, archived and locked chats do not count. */
export function unreadTotal(conversations) {
    return [...conversations]
        .filter((c) => !c.settings?.archived && !c.settings?.hidden && !c.settings?.locked && !T.isChatMuted(c))
        .reduce((sum, c) => sum + (c.unread_count || 0), 0);
}

export class ChatListActions {
    constructor(chat) {
        this.chat = chat;
        this.menu = null;
        this.bind();
    }

    bind() {
        const list = this.chat.el.conversationList;

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-chat-menu]');
            if (button) {
                event.stopPropagation();
                this.open(Number(button.dataset.chatMenu), button);
            } else if (event.target.closest('[data-open-archived]')) {
                this.chat.setListMode('archived');
            } else if (event.target.closest('[data-close-archived]')) {
                this.chat.setListMode('chats');
            } else if (event.target.closest('[data-open-locked]')) {
                this.chat.chatLock?.openFolder();
            } else if (event.target.closest('[data-close-locked]')) {
                this.chat.chatLock?.closeFolder();
            }
        });

        // Right click (desktop) or long press (touch) on a chat.
        list.addEventListener('contextmenu', (event) => {
            const item = event.target.closest('[data-conversation-id]');
            if (!item) return;
            event.preventDefault();
            this.open(Number(item.dataset.conversationId), item, { x: event.clientX, y: event.clientY });
        });

        let timer = null;
        list.addEventListener('touchstart', (event) => {
            const item = event.target.closest('[data-conversation-id]');
            if (!item || event.touches.length !== 1) return;
            const touch = event.touches[0];
            timer = setTimeout(() => {
                navigator.vibrate?.(15);
                this.suppressClickUntil = Date.now() + 600;
                this.open(Number(item.dataset.conversationId), item, { x: touch.clientX, y: touch.clientY });
            }, LONG_PRESS_MS);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach((type) => list.addEventListener(type, () => clearTimeout(timer), { passive: true }));

        // The click that follows a long press must not open the chat.
        list.addEventListener('click', (event) => {
            if (this.suppressClickUntil && Date.now() < this.suppressClickUntil) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        document.addEventListener('click', (event) => {
            if (this.menu && !this.menu.contains(event.target) && Date.now() - this.openedAt > 300) this.close();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.close();
        });
        this.chat.el.sidebarScroll.addEventListener('scroll', () => this.close(), { passive: true });

        // The same actions in the open chat's menu.
        document.addEventListener('chat:menu', (event) => {
            const conversation = event.detail.conversation;
            if (!conversation || !this.chat.api.has('conversationSettings')) return;
            const muted = T.isChatMuted(conversation);
            const locked = Boolean(conversation.settings?.locked);
            event.detail.items.push(
                html`<button type="button" class="dropdown-item" data-action="chat-list:${muted ? 'unmute' : 'mute'}" role="menuitem">${raw(icon(muted ? 'bell' : 'bell-off'))} ${muted ? 'Unmute notifications' : 'Mute notifications'}</button>`,
                ...(this.chat.chatLock?.available && !conversation.is_self
                    ? [html`<button type="button" class="dropdown-item" data-action="chat-list:${locked ? 'unlock' : 'lock'}" role="menuitem">${raw(icon(locked ? 'lock-open' : 'lock'))} ${locked ? 'Unlock chat' : 'Lock chat'}</button>`]
                    : []),
                html`<button type="button" class="dropdown-item" data-action="chat-list:clear" role="menuitem">${raw(icon('eraser'))} Clear chat</button>`,
                // A group you are in is left first (G8); a channel is unfollowed or deleted (G11).
                conversation.type === 'channel' && conversation.channel?.is_following
                    ? (conversation.channel.is_admin
                        ? html`<button type="button" class="dropdown-item is-danger" data-action="channel:delete" role="menuitem">${raw(icon('trash-2'))} Delete channel</button>`
                        : html`<button type="button" class="dropdown-item is-danger" data-action="channel:unfollow" role="menuitem">${raw(icon('user-minus'))} Unfollow channel</button>`)
                    : conversation.type === 'group' && conversation.group?.is_member
                    ? html`<button type="button" class="dropdown-item is-danger" data-action="group:exit" role="menuitem">${raw(icon('log-out'))} Exit group</button>`
                    : html`<button type="button" class="dropdown-item is-danger" data-action="chat-list:delete" role="menuitem">${raw(icon('trash-2'))} Delete chat</button>`,
            );
        });
        document.addEventListener('chat:action', (event) => {
            const action = event.detail.action;
            if (!action?.startsWith('chat-list:')) return;
            const conversation = this.chat.activeConversation();
            if (conversation) this.handle(action.slice('chat-list:'.length), conversation);
        });
    }

    items(conversation) {
        const s = conversation.settings ?? {};
        const muted = T.isChatMuted(conversation);
        const lock = this.chat.chatLock?.available && !conversation.is_self
            ? [s.locked ? { action: 'unlock', icon: 'lock-open', label: 'Unlock chat' } : { action: 'lock', icon: 'lock', label: 'Lock chat' }]
            : [];
        return [
            // Locked chats (C9) are never archived or pinned.
            ...(s.locked ? [] : [{ action: s.archived ? 'unarchive' : 'archive', icon: s.archived ? 'archive-restore' : 'archive', label: s.archived ? 'Unarchive chat' : 'Archive chat' }]),
            { action: muted ? 'unmute' : 'mute', icon: muted ? 'bell' : 'bell-off', label: muted ? 'Unmute notifications' : 'Mute notifications' },
            ...(s.archived || s.locked ? [] : [{ action: s.pinned ? 'unpin' : 'pin', icon: s.pinned ? 'pin-off' : 'pin', label: s.pinned ? 'Unpin chat' : 'Pin chat' }]),
            ...lock,
            hasUnread(conversation)
                ? { action: 'read', icon: 'check-check', label: 'Mark as read' }
                : { action: 'unread', icon: 'message-circle', label: 'Mark as unread' },
            s.favorite
                ? { action: 'unfavorite', icon: 'heart-off', label: 'Remove from Favorites' }
                : { action: 'favorite', icon: 'heart', label: 'Add to Favorites' },
            ...(this.chat.chatLists ? [{ action: 'lists', icon: 'list-plus', label: 'Add to list' }] : []),
            '-',
            { action: 'clear', icon: 'eraser', label: 'Clear chat' },
            conversation.type === 'channel' && conversation.channel?.is_following
                ? (conversation.channel.is_admin
                    ? { action: 'delete-channel', icon: 'trash-2', label: 'Delete channel', danger: true }
                    : { action: 'unfollow', icon: 'user-minus', label: 'Unfollow channel', danger: true })
                : conversation.type === 'group' && conversation.group?.is_member
                ? { action: 'exit', icon: 'log-out', label: 'Exit group', danger: true }
                : { action: 'delete', icon: 'trash-2', label: 'Delete chat', danger: true },
        ];
    }

    open(id, anchor, point = null) {
        const conversation = this.chat.conversations.get(id);
        if (!conversation) return;
        this.close();

        const sheet = window.matchMedia('(max-width: 767px), (pointer: coarse)').matches;
        const menu = document.createElement('div');
        menu.className = `dropdown-menu chat-list-menu${sheet ? ' is-sheet' : ''}`;
        menu.setAttribute('role', 'menu');
        menu.innerHTML = (sheet ? html`<div class="sheet-preview">${this.chat.participantOf(conversation)?.name ?? 'Chat'}</div>` : '')
            + this.items(conversation)
                .map((item) => (item === '-'
                    ? '<div class="dropdown-divider"></div>'
                    : html`<button type="button" class="dropdown-item${item.danger ? ' is-danger' : ''}" data-chat-action="${item.action}" role="menuitem">${raw(icon(item.icon))} ${item.label}</button>`))
                .join('');

        if (sheet) {
            this.backdrop = document.createElement('div');
            this.backdrop.className = 'sheet-backdrop';
            this.backdrop.addEventListener('click', () => this.close());
            document.body.appendChild(this.backdrop);
        }
        document.body.appendChild(menu);

        if (!sheet) {
            const rect = anchor.getBoundingClientRect();
            const { width, height } = menu.getBoundingClientRect();
            let left = point ? point.x : rect.right - width;
            let top = point ? point.y : rect.bottom + 4;
            if (top + height > window.innerHeight - 8) top = Math.max(8, (point ? point.y : rect.top) - height - 4);
            left = Math.min(Math.max(8, left), window.innerWidth - width - 8);
            Object.assign(menu.style, { position: 'fixed', left: `${left}px`, top: `${top}px` });
        }

        menu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-chat-action]');
            if (!item) return;
            event.stopPropagation();
            this.close();
            this.handle(item.dataset.chatAction, conversation);
        });

        this.menu = menu;
        this.openedAt = Date.now();
        menu.querySelector('.dropdown-item')?.focus({ preventScroll: true });
    }

    close() {
        this.menu?.remove();
        this.menu = null;
        this.backdrop?.remove();
        this.backdrop = null;
    }

    async handle(action, conversation) {
        switch (action) {
            case 'pin': return this.apply(conversation, { pinned: true });
            case 'unpin': return this.apply(conversation, { pinned: false });
            case 'archive': return this.apply(conversation, { archived: true }, 'Chat archived.');
            case 'unarchive': return this.apply(conversation, { archived: false }, 'Chat unarchived.');
            case 'unmute': return this.apply(conversation, { muted: null });
            case 'unread': return this.apply(conversation, { unread: true });
            case 'read': return this.apply(conversation, { unread: false });
            case 'favorite': return this.apply(conversation, { favorite: true }, 'Added to Favorites.');
            case 'unfavorite': return this.apply(conversation, { favorite: false });
            case 'lists': return this.chat.chatLists?.choose(conversation);
            case 'lock': return this.chat.chatLock?.lockChat(conversation);
            case 'unlock': return this.chat.chatLock?.unlockChat(conversation);
            case 'mute': return this.mute(conversation);
            case 'clear': return this.clear(conversation);
            case 'delete': return this.remove(conversation);
            case 'exit': return this.chat.groups?.leave(conversation);
            case 'unfollow': return this.chat.channels?.unfollow(conversation);
            case 'delete-channel': return this.chat.channels?.remove(conversation);
            default: return null;
        }
    }

    /** Change settings with instant feedback; the server's answer wins. */
    async apply(conversation, changes, success = '') {
        const previous = { ...conversation, settings: { ...(conversation.settings ?? {}) } };
        const optimistic = { ...previous.settings };
        if ('pinned' in changes) Object.assign(optimistic, { pinned: changes.pinned, pinned_at: changes.pinned ? new Date().toISOString() : null }, changes.pinned ? { archived: false } : {});
        if ('archived' in changes) Object.assign(optimistic, { archived: changes.archived }, changes.archived ? { pinned: false } : {});
        if ('muted' in changes) Object.assign(optimistic, { muted: Boolean(changes.muted), muted_until: null });
        if ('unread' in changes) optimistic.marked_unread = changes.unread;
        if ('favorite' in changes) optimistic.favorite = changes.favorite;
        if ('locked' in changes) Object.assign(optimistic, { locked: changes.locked }, changes.locked ? { pinned: false, archived: false } : {});

        this.chat.upsertConversation({ id: conversation.id, settings: optimistic, ...(changes.unread === false ? { unread_count: 0 } : {}) });

        try {
            const fresh = await this.chat.api.updateChatSettings(conversation.id, changes);
            this.chat.upsertConversation(fresh);
            if (success) toast.success(success);
            if (this.chat.active?.id === conversation.id) this.chat.renderHeader(this.chat.activeConversation());
        } catch (error) {
            this.chat.upsertConversation({ id: conversation.id, settings: previous.settings, unread_count: previous.unread_count });
            toast.error(errorMessage(error, 'The chat could not be changed.'));
        }
    }

    async mute(conversation) {
        const choice = await confirmDialog({
            title: 'Mute notifications',
            message: 'You will not be notified about new messages in this chat. The other person is not told.',
            icon: 'bell-off',
            tone: 'primary',
            actions: [
                { label: '8 hours', value: '8h', variant: 'secondary' },
                { label: '1 week', value: '1w', variant: 'secondary' },
                { label: 'Always', value: 'always', variant: 'primary' },
            ],
        });
        if (choice) this.apply(conversation, { muted: choice });
    }

    async clear(conversation) {
        const choice = await confirmDialog({
            title: 'Clear this chat?',
            message: 'All messages disappear from this chat for you. The other person still has them.',
            icon: 'eraser',
            actions: [
                { label: 'Keep starred', value: 'keep', variant: 'secondary' },
                { label: 'Clear chat', value: 'clear', variant: 'danger' },
            ],
        });
        if (!choice) return;

        try {
            const fresh = await this.chat.api.clearChat(conversation.id, choice === 'keep');
            this.chat.upsertConversation({ ...fresh, last_message: fresh.last_message ?? null });
            if (this.chat.active?.id === conversation.id) {
                this.chat.active.hasMore = false;
                this.chat.renderMessages([]);
                this.chat.pins?.set?.([]);
            }
            toast.success('Chat cleared.');
        } catch (error) {
            toast.error(errorMessage(error, 'The chat could not be cleared.'));
        }
    }

    async remove(conversation) {
        // A broadcast list is deleted as a list (G9).
        if (conversation.type === 'broadcast') return this.chat.broadcasts?.remove(conversation);
        const name = this.chat.participantOf(conversation)?.name ?? 'this person';
        const choice = await confirmDialog({
            title: `Delete chat with ${name}?`,
            message: 'The chat and its messages are removed for you only. It comes back if a new message arrives.',
            icon: 'trash-2',
            actions: [{ label: 'Delete chat', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;

        try {
            await this.chat.api.deleteChat(conversation.id);
            this.chat.forgetConversation(conversation.id);
            toast.success('Chat deleted.');
        } catch (error) {
            toast.error(errorMessage(error, 'The chat could not be deleted.'));
        }
    }
}
