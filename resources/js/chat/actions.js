import { errorMessage } from '../lib/dom';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import * as T from './templates';
import { previewOf } from './templates';

const LONG_PRESS_MS = 450;

/**
 * Per-message actions: reply, copy, edit, delete (for me / for everyone).
 */
export class MessageActions {
    constructor(chat) {
        this.chat = chat;
        this.mode = null; // { type: 'reply' | 'edit', message }
        this.menu = null;
        this.longPressTimer = null;

        this.bindMessageList();
        this.bindComposer();
    }

    /* ------------------------------------------------------------------ */
    /* Menu                                                                */
    /* ------------------------------------------------------------------ */

    bindMessageList() {
        const list = this.chat.el.messageList;

        list.addEventListener('click', (event) => {
            const menuButton = event.target.closest('[data-message-menu]');
            if (menuButton) {
                event.stopPropagation();
                this.openMenu(menuButton.closest('[data-message-id]'), menuButton);
                return;
            }

            const jump = event.target.closest('[data-jump-to]');
            if (jump) this.chat.jumpToMessage(Number(jump.dataset.jumpTo));
        });

        list.addEventListener('contextmenu', (event) => {
            const bubble = event.target.closest('.message-bubble');
            if (!bubble || event.target.closest('a')) return;
            const row = bubble.closest('[data-message-id]');
            if (!this.messageFor(row)) return;
            event.preventDefault();
            this.openMenu(row, bubble, { x: event.clientX, y: event.clientY });
        });

        // Long press on touch devices.
        list.addEventListener('touchstart', (event) => {
            const bubble = event.target.closest('.message-bubble');
            if (!bubble) return;
            const touch = event.touches[0];
            this.longPressTimer = setTimeout(() => {
                const row = bubble.closest('[data-message-id]');
                if (this.messageFor(row)) {
                    navigator.vibrate?.(15);
                    this.openMenu(row, bubble, { x: touch.clientX, y: touch.clientY });
                }
            }, LONG_PRESS_MS);
        }, { passive: true });

        ['touchend', 'touchmove', 'touchcancel'].forEach((type) =>
            list.addEventListener(type, () => clearTimeout(this.longPressTimer), { passive: true }),
        );

        document.addEventListener('click', (event) => {
            if (this.menu && !this.menu.contains(event.target)) this.closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.closeMenu();
        });
        this.chat.el.messages.addEventListener('scroll', () => this.closeMenu(), { passive: true });
    }

    messageFor(row) {
        if (!row) return null;
        const message = this.chat.active?.byId.get(row.dataset.messageId);
        return message && typeof message.id === 'number' ? message : null;
    }

    conversationBlocked() {
        const conversation = this.chat.activeConversation();
        return Boolean(conversation?.blocked_by_me || conversation?.blocked_me);
    }

    withinWindow(message, minutes) {
        return !minutes || Date.now() - new Date(message.created_at).getTime() < minutes * 60_000;
    }

    canEdit(message) {
        return message.is_mine && !message.is_deleted && message.type === 'text' && !this.conversationBlocked()
            && this.withinWindow(message, this.chat.config.limits.editWindowMinutes);
    }

    canDeleteForEveryone(message) {
        return message.is_mine && !message.is_deleted && this.withinWindow(message, this.chat.config.limits.deleteWindowMinutes);
    }

    openMenu(row, anchor, point = null) {
        const message = this.messageFor(row);
        if (!message) return;
        this.closeMenu();

        const items = [];
        if (!message.is_deleted && !this.conversationBlocked()) items.push({ action: 'reply', icon: 'corner-up-left', label: 'Reply' });
        if (!message.is_deleted && message.body) items.push({ action: 'copy', icon: 'copy', label: 'Copy text' });
        if (message.attachment?.download_url && !message.is_deleted) items.push({ action: 'download', icon: 'download', label: 'Download' });
        if (this.canEdit(message)) items.push({ action: 'edit', icon: 'pencil', label: 'Edit' });
        if (items.length) items.push('-');
        items.push({ action: 'delete', icon: 'trash-2', label: 'Delete', danger: true });

        const menu = document.createElement('div');
        menu.className = 'dropdown-menu message-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = T.messageMenu(items);
        document.body.appendChild(menu);

        // Position next to the bubble / pointer, kept inside the viewport.
        const rect = anchor.getBoundingClientRect();
        const { width, height } = menu.getBoundingClientRect();
        let left = point ? point.x : message.is_mine ? rect.right - width : rect.left;
        let top = point ? point.y : rect.bottom + 6;
        if (top + height > window.innerHeight - 8) top = Math.max(8, (point ? point.y : rect.top) - height - 6);
        left = Math.min(Math.max(8, left), window.innerWidth - width - 8);
        Object.assign(menu.style, { position: 'fixed', left: `${left}px`, top: `${top}px` });

        menu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-message-action]');
            if (!item) return;
            event.stopPropagation();
            this.closeMenu();
            this.handle(item.dataset.messageAction, message);
        });

        this.menu = menu;
        row.classList.add('is-menu-open');
        this.menuRow = row;
        menu.querySelector('.dropdown-item')?.focus({ preventScroll: true });
    }

    closeMenu() {
        this.menu?.remove();
        this.menu = null;
        this.menuRow?.classList.remove('is-menu-open');
        this.menuRow = null;
    }

    async handle(action, message) {
        switch (action) {
            case 'reply':
                return this.setMode('reply', message);
            case 'edit':
                return this.setMode('edit', message);
            case 'copy':
                return this.copy(message.body);
            case 'download':
                return this.download(message.attachment);
            case 'delete':
                return this.confirmDelete(message);
        }
    }

    async copy(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        toast.success('Message copied to clipboard.', { timeout: 2000 });
    }

    download(attachment) {
        const link = document.createElement('a');
        link.href = attachment.download_url;
        link.download = attachment.name || '';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    /* ------------------------------------------------------------------ */
    /* Delete                                                              */
    /* ------------------------------------------------------------------ */

    async confirmDelete(message) {
        const everyone = this.canDeleteForEveryone(message);
        const choice = await confirmDialog({
            title: 'Delete message?',
            message: everyone
                ? '"Delete for everyone" removes the message for both of you. "Delete for me" only hides it from your chat.'
                : 'This message will be removed from your chat only.',
            icon: 'trash-2',
            actions: [
                { label: 'Delete for me', value: 'me', variant: everyone ? 'secondary' : 'danger' },
                ...(everyone ? [{ label: 'Delete for everyone', value: 'everyone', variant: 'danger' }] : []),
            ],
        });

        if (!choice) return;
        if (this.mode?.message.id === message.id) this.clearMode();

        try {
            const result = await this.chat.api.deleteMessage(message.id, choice);

            if (choice === 'everyone') {
                this.chat.onMessageUpdated(result.message);
                toast.success('Message deleted for everyone.', { timeout: 2500 });
            } else {
                this.chat.onMessageHidden({ id: message.id, conversation_id: message.conversation_id });
                toast.success('Message deleted for you.', { timeout: 2500 });
            }
        } catch (error) {
            toast.error(errorMessage(error, 'Could not delete the message.'));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reply & edit modes                                                  */
    /* ------------------------------------------------------------------ */

    bindComposer() {
        const { composerInput, composerExtras } = this.chat.el;

        composerExtras.addEventListener('click', (event) => {
            if (event.target.closest('[data-cancel-context]')) this.clearMode({ restoreText: true });
        });

        composerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.mode) {
                event.preventDefault();
                this.clearMode({ restoreText: true });
            }

            // ArrowUp in an empty composer edits your last message.
            if (event.key === 'ArrowUp' && !composerInput.value && !this.mode) {
                const last = [...(this.chat.active?.messages ?? [])].reverse().find((m) => this.canEdit(m));
                if (last) {
                    event.preventDefault();
                    this.setMode('edit', last);
                }
            }
        });

        document.addEventListener('chat:before-submit', (event) => {
            if (this.mode?.type !== 'edit') return;
            event.preventDefault();
            this.submitEdit();
        });

        document.addEventListener('chat:compose-payload', (event) => {
            if (this.mode?.type !== 'reply') return;
            const { message } = this.mode;
            event.detail.payload.reply_to_id = message.id;
            event.detail.payload.reply_preview = {
                id: message.id,
                sender_id: message.sender_id,
                type: message.type,
                preview: previewOf(message),
                is_deleted: false,
            };
            this.clearMode();
        });

        document.addEventListener('chat:opened', () => this.clearMode());
        document.addEventListener('chat:closed', () => this.clearMode());
    }

    setMode(type, message) {
        const { composerInput } = this.chat.el;
        const draft = this.mode?.type === 'edit' ? this.mode.draft : composerInput.value;

        this.mode = { type, message, draft };

        const author = message.is_mine ? 'yourself' : this.chat.participantOf(this.chat.activeConversation())?.name ?? 'message';
        this.chat.el.composerExtras.querySelector('[data-composer-context]').innerHTML = T.composerContext({
            mode: type,
            title: type === 'edit' ? 'Edit message' : `Replying to ${author}`,
            preview: previewOf(message),
        });

        if (type === 'edit') {
            composerInput.value = message.body ?? '';
        }

        this.chat.autosize();
        this.chat.updateSendState();
        composerInput.focus();
        composerInput.setSelectionRange(composerInput.value.length, composerInput.value.length);
    }

    clearMode({ restoreText = false } = {}) {
        if (!this.mode) return;
        const { type, draft } = this.mode;
        this.mode = null;
        const container = this.chat.el.composerExtras.querySelector('[data-composer-context]');
        if (container) container.innerHTML = '';

        if (type === 'edit' && restoreText) {
            this.chat.el.composerInput.value = draft ?? '';
            this.chat.autosize();
            this.chat.updateSendState();
        }
    }

    async submitEdit() {
        const { message } = this.mode;
        const input = this.chat.el.composerInput;
        const text = input.value.trim();

        if (!text) {
            toast.error('A message cannot be empty. Use Delete to remove it.');
            return;
        }

        const draft = this.mode.draft;
        this.clearMode();
        input.value = draft ?? '';
        this.chat.autosize();
        this.chat.updateSendState();

        if (text === message.body) return;

        // Optimistic update, rolled back on failure.
        const previous = { ...message };
        this.chat.updateMessage({ id: message.id, body: text, is_edited: true });

        try {
            const updated = await this.chat.api.updateMessage(message.id, text);
            this.chat.onMessageUpdated(updated);
        } catch (error) {
            this.chat.updateMessage(previous);
            toast.error(errorMessage(error, 'Could not edit the message.'));
        }
    }
}
