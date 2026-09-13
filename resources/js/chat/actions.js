import { errorMessage } from '../lib/dom';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import * as T from './templates';
import { previewOf } from './templates';
import { openMessageInfo } from './message-info';
import { reactionBar } from './reactions';

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
            // A long press already opened the menu on touch devices.
            if (this.menu && Date.now() - this.menuOpenedAt < 800) return;
            this.openMenu(row, bubble, { x: event.clientX, y: event.clientY });
        });

        this.bindSwipeToReply(list);

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
            // Ignore the click synthesised right after a long press.
            if (this.menu && Date.now() - this.menuOpenedAt < 400) return;
            if (this.menu && !this.menu.contains(event.target)) this.closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.closeMenu();
        });
        this.chat.el.messages.addEventListener('scroll', () => this.closeMenu(), { passive: true });
    }

    /** Swipe a message to the right to reply (touch devices, like WhatsApp). */
    bindSwipeToReply(list) {
        const THRESHOLD = 56;
        let gesture = null;

        list.addEventListener('touchstart', (event) => {
            const bubble = event.target.closest('.message-bubble');
            if (!bubble || event.touches.length !== 1) return;
            const row = bubble.closest('[data-message-id]');
            const message = this.messageFor(row);
            if (!message || message.is_deleted || message.type === 'call' || this.conversationBlocked()) return;

            const touch = event.touches[0];
            gesture = { x: touch.clientX, y: touch.clientY, row, message, dx: 0, horizontal: null };
        }, { passive: true });

        list.addEventListener('touchmove', (event) => {
            if (!gesture) return;
            const touch = event.touches[0];
            const dx = touch.clientX - gesture.x;
            const dy = touch.clientY - gesture.y;

            if (gesture.horizontal === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) {
                gesture.horizontal = dx > 0 && Math.abs(dx) > Math.abs(dy) * 1.5;
            }
            if (!gesture.horizontal) return;

            gesture.dx = Math.max(0, Math.min(dx, 84));
            gesture.row.classList.add('is-swiping');
            gesture.row.classList.toggle('is-swipe-ready', gesture.dx > THRESHOLD);
            gesture.row.style.setProperty('--swipe', `${gesture.dx}px`);
        }, { passive: true });

        const finish = () => {
            if (!gesture) return;
            const { row, dx, message } = gesture;
            gesture = null;
            row.classList.remove('is-swiping', 'is-swipe-ready');
            row.style.removeProperty('--swipe');
            if (dx > THRESHOLD) {
                navigator.vibrate?.(10);
                this.setMode('reply', message);
            }
        };

        list.addEventListener('touchend', finish, { passive: true });
        list.addEventListener('touchcancel', finish, { passive: true });
    }

    /** Bottom sheet instead of a floating menu on phones and touch screens. */
    useSheet() {
        return window.matchMedia('(max-width: 767px), (pointer: coarse)').matches;
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
        return message.is_mine && !message.is_deleted && message.type !== 'call'
            && this.withinWindow(message, this.chat.config.limits.deleteWindowMinutes);
    }

    openMenu(row, anchor, point = null) {
        const message = this.messageFor(row);
        if (!message || message.type === 'system') return;
        this.closeMenu();

        const items = [];
        // View once media cannot be forwarded, starred, pinned or downloaded (M22).
        const isCall = message.type === 'call' || Boolean(message.attachment?.view_once);
        if (!message.is_deleted && !isCall && !this.conversationBlocked()) items.push({ action: 'reply', icon: 'corner-up-left', label: 'Reply' });
        if (!message.is_deleted && !isCall && this.chat.api.has('messageForward')) items.push({ action: 'forward', icon: 'forward', label: 'Forward' });
        if (!message.is_deleted && !isCall && this.chat.api.has('messageStar')) {
            items.push(message.is_starred ? { action: 'unstar', icon: 'star-off', label: 'Unstar' } : { action: 'star', icon: 'star', label: 'Star' });
        }
        if (!message.is_deleted && !isCall && !this.conversationBlocked() && this.chat.api.has('messagePin')) {
            items.push(this.chat.pins.isPinned(message) ? { action: 'unpin', icon: 'pin-off', label: 'Unpin' } : { action: 'pin', icon: 'pin', label: 'Pin' });
        }
        if (!message.is_deleted && message.body) items.push({ action: 'copy', icon: 'copy', label: 'Copy text' });
        if (message.type === 'sticker' && !message.is_mine && !message.is_deleted && typeof message.id === 'number' && this.chat.api.has('messageSaveSticker')) {
            items.push({ action: 'save-sticker', icon: 'sticker', label: 'Save to my stickers' });
        }
        if (message.attachment?.download_url && !message.is_deleted && message.type !== 'sticker') items.push({ action: 'download', icon: 'download', label: 'Download' });
        if (this.canEdit(message)) items.push({ action: 'edit', icon: 'pencil', label: 'Edit' });
        if (message.is_mine && !message.is_deleted && !isCall) items.push({ action: 'info', icon: 'info', label: 'Info' });
        if (items.length) items.push('-');
        items.push({ action: 'delete', icon: 'trash-2', label: 'Delete', danger: true });

        const sheet = this.useSheet();
        const menu = document.createElement('div');
        menu.className = `dropdown-menu message-menu${sheet ? ' is-sheet' : ''}`;
        menu.setAttribute('role', 'menu');
        const reactions = this.chat.reactions?.canReact(message) ? reactionBar(message, this.chat.me.id) : '';
        menu.innerHTML = (sheet ? T.messageSheetHeader(message) : '') + reactions + T.messageMenu(items);

        if (sheet) {
            this.backdrop = document.createElement('div');
            this.backdrop.className = 'sheet-backdrop';
            this.backdrop.addEventListener('click', () => this.closeMenu());
            document.body.appendChild(this.backdrop);
        }

        document.body.appendChild(menu);

        if (!sheet) {
            // Position next to the bubble / pointer, kept inside the viewport.
            const rect = anchor.getBoundingClientRect();
            const { width, height } = menu.getBoundingClientRect();
            let left = point ? point.x : message.is_mine ? rect.right - width : rect.left;
            let top = point ? point.y : rect.bottom + 6;
            if (top + height > window.innerHeight - 8) top = Math.max(8, (point ? point.y : rect.top) - height - 6);
            left = Math.min(Math.max(8, left), window.innerWidth - width - 8);
            Object.assign(menu.style, { position: 'fixed', left: `${left}px`, top: `${top}px` });
        }

        this.menuOpenedAt = Date.now();

        menu.addEventListener('click', (event) => {
            const reaction = event.target.closest('[data-react-emoji]');
            if (reaction || event.target.closest('[data-react-more]')) {
                event.stopPropagation();
                this.closeMenu();
                if (reaction) this.chat.reactions.toggle(message, reaction.dataset.reactEmoji);
                else this.chat.reactions.openPicker(message);
                return;
            }

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
        this.backdrop?.remove();
        this.backdrop = null;
        this.menuRow?.classList.remove('is-menu-open');
        this.menuRow = null;
    }

    async handle(action, message) {
        switch (action) {
            case 'reply':
                return this.setMode('reply', message);
            case 'edit':
                return this.setMode('edit', message);
            case 'forward':
                return this.chat.forwardDialog.open(message);
            case 'save-sticker':
                try {
                    await this.chat.api.saveSticker(message.id);
                    this.chat.stickerPanel.stickers = null;
                    toast.success('Saved to your stickers.');
                } catch (error) {
                    toast.error(errorMessage(error, 'The sticker could not be saved.'));
                }
                return;
            case 'star':
            case 'unstar':
                return this.chat.starred.toggle(message);
            case 'info':
                return openMessageInfo(message);
            case 'pin':
                return this.chat.pins.pin(message);
            case 'unpin':
                return this.chat.pins.unpin(message);
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
