import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

export const isMac = () => /Mac|iPhone|iPad|iPod/.test(globalThis.navigator?.platform ?? '');

/** Keys as people see them on their keyboard. */
export function keyLabel(key) {
    const mac = isMac();
    return {
        Mod: mac ? '⌘' : 'Ctrl',
        Alt: mac ? '⌥' : 'Alt',
        Shift: mac ? '⇧' : 'Shift',
        ArrowUp: '↑',
        ArrowDown: '↓',
        Escape: 'Esc',
    }[key] ?? key;
}

/** X6 — desktop keyboard shortcuts (same ideas as WhatsApp Web). */
export const SHORTCUTS = [
    { id: 'search', keys: ['Mod', 'K'], label: 'Search chats' },
    { id: 'previous', keys: ['Alt', 'ArrowUp'], label: 'Previous chat' },
    { id: 'next', keys: ['Alt', 'ArrowDown'], label: 'Next chat' },
    { id: 'chat-search', keys: ['Mod', 'Shift', 'F'], label: 'Search in this chat' },
    { id: 'new-chat', keys: ['Mod', 'Alt', 'N'], label: 'New chat' },
    { id: 'new-group', keys: ['Mod', 'Alt', 'Shift', 'N'], label: 'New group' },
    { id: 'archive', keys: ['Mod', 'Alt', 'E'], label: 'Archive or unarchive chat' },
    { id: 'mute', keys: ['Mod', 'Alt', 'Shift', 'M'], label: 'Mute or unmute chat' },
    { id: 'pin', keys: ['Mod', 'Alt', 'Shift', 'P'], label: 'Pin or unpin chat' },
    { id: 'unread', keys: ['Mod', 'Alt', 'Shift', 'U'], label: 'Mark as unread or read' },
    { id: 'settings', keys: ['Mod', 'Alt', ','], label: 'Settings' },
    { id: 'sections', keys: ['Alt', '1 – 5'], label: 'Chats, Status, Channels, Communities, Calls' },
    { id: 'close', keys: ['Escape'], label: 'Close a menu or panel, then the chat' },
    { id: 'help', keys: ['Mod', '/'], label: 'Keyboard shortcuts' },
];

const SECTIONS = ['chats', 'status', 'channels', 'communities', 'calls'];

/** Anything on screen that Escape should close before the chat itself. */
const OVERLAYS = '.modal, .lightbox:not(.is-closing), .group-info, .dropdown-menu:not([hidden]), .emoji-panel:not([hidden]), .sheet-backdrop, .call-screen, .status-viewer, .status-composer';

/** Which shortcut a key press is, or null. */
export function matchShortcut(event, mac = isMac()) {
    const mod = mac ? event.metaKey : event.ctrlKey;
    const code = event.code ?? '';
    const key = (event.key ?? '').toLowerCase();
    const letter = code.startsWith('Key') ? code.slice(3).toLowerCase() : key;

    if (event.key === 'Escape') return 'close';
    if (!mod && event.altKey && !event.shiftKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) return event.key === 'ArrowUp' ? 'previous' : 'next';
    if (!mod && event.altKey && !event.shiftKey && /^Digit[1-5]$/.test(code)) return `section:${SECTIONS[Number(code.slice(5)) - 1]}`;
    if (!mod) return null;

    if (!event.altKey && !event.shiftKey && letter === 'k') return 'search';
    if (!event.altKey && !event.shiftKey && (key === '/' || code === 'Slash')) return 'help';
    if (!event.altKey && event.shiftKey && letter === 'f') return 'chat-search';
    if (event.altKey && !event.shiftKey && letter === 'n') return 'new-chat';
    if (event.altKey && event.shiftKey && letter === 'n') return 'new-group';
    if (event.altKey && !event.shiftKey && letter === 'e') return 'archive';
    if (event.altKey && event.shiftKey && letter === 'm') return 'mute';
    if (event.altKey && event.shiftKey && letter === 'p') return 'pin';
    if (event.altKey && event.shiftKey && letter === 'u') return 'unread';
    if (event.altKey && !event.shiftKey && (key === ',' || code === 'Comma')) return 'settings';
    return null;
}

export class KeyboardShortcuts {
    constructor(chat) {
        this.chat = chat;
        this.dialog = null;

        // Capture: decide before menus and panels react to the same key.
        this.handler = (event) => this.onKey(event);
        window.addEventListener('keydown', this.handler, true);
        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'shortcuts') this.open();
        });
    }

    destroy() {
        window.removeEventListener('keydown', this.handler, true);
        this.close();
    }

    onKey(event) {
        if (event.defaultPrevented || event.isComposing) return;
        const id = matchShortcut(event);
        if (!id) return;

        if (id === 'close') {
            this.escape(event);
            return;
        }

        // While a dialog is open only the shortcuts list itself can be toggled.
        if (document.querySelector('.modal') && id !== 'help') return;

        const done = this.run(id);
        if (done !== false) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    run(id) {
        const chat = this.chat;
        const conversation = chat.activeConversation?.();

        if (id.startsWith('section:')) {
            const tab = document.querySelector(`[data-mobile-tab="${id.slice(8)}"]`);
            if (!tab) return false;
            tab.click();
            return true;
        }

        switch (id) {
            case 'search':
                this.clickTab('chats');
                chat.el.searchInput?.focus();
                chat.el.searchInput?.select?.();
                return true;
            case 'previous':
            case 'next':
                return this.step(id === 'next' ? 1 : -1);
            case 'chat-search':
                if (!conversation) return false;
                document.dispatchEvent(new CustomEvent('chat:action', { detail: { action: 'chat-search', chat } }));
                return true;
            case 'new-chat':
                return this.clickAction('new-chat');
            case 'new-group':
                return this.clickAction('new-group');
            case 'settings':
                if (!chat.config?.routes?.settings) return false;
                window.location.assign(chat.config.routes.settings);
                return true;
            case 'help':
                if (this.dialog) this.close();
                else this.open();
                return true;
            default:
                return this.chatAction(id, conversation);
        }
    }

    /** Archive, mute, pin and unread act on the open chat. */
    chatAction(id, conversation) {
        const list = this.chat.chatList;
        if (!conversation || !list) return false;
        const s = conversation.settings ?? {};
        const muted = Boolean(s.muted);
        const action = {
            archive: s.locked ? null : (s.archived ? 'unarchive' : 'archive'),
            mute: muted ? 'unmute' : 'mute',
            pin: s.archived || s.locked ? null : (s.pinned ? 'unpin' : 'pin'),
            unread: s.marked_unread ? 'read' : 'unread',
        }[id];
        if (!action) return false;
        list.handle(action, conversation);
        return true;
    }

    step(direction) {
        const items = [...(this.chat.el.conversationList?.querySelectorAll('[data-conversation-id]') ?? [])];
        if (!items.length) return false;
        const current = items.findIndex((item) => Number(item.dataset.conversationId) === Number(this.chat.active?.id));
        const next = items[current === -1 ? (direction > 0 ? 0 : items.length - 1) : Math.min(items.length - 1, Math.max(0, current + direction))];
        if (!next || Number(next.dataset.conversationId) === Number(this.chat.active?.id)) return true;
        this.chat.openConversation(Number(next.dataset.conversationId));
        next.scrollIntoView?.({ block: 'nearest' });
        return true;
    }

    escape(event) {
        if (this.dialog) {
            event.preventDefault();
            this.close();
            return;
        }
        // Menus, panels, replies, recordings and in-chat search close themselves first.
        if (document.querySelector(OVERLAYS)) return;
        const chat = this.chat;
        if (!chat.active || chat.actions?.mode || (chat.voice?.state && chat.voice.state !== 'idle')) return;
        if (chat.el.panel?.classList.contains('is-searching')) return;
        const target = event.target;
        if (target?.matches?.('input, textarea') && target !== chat.el.composerInput) return;

        event.preventDefault();
        chat.closeConversation();
    }

    clickAction(action) {
        const button = this.chat.el.app?.querySelector(`[data-action="${action}"]`) ?? document.querySelector(`[data-action="${action}"]`);
        if (!button) return false;
        button.click();
        return true;
    }

    clickTab(name) {
        const tab = document.querySelector(`[data-mobile-tab="${name}"]:not(.is-active)`);
        tab?.click();
    }

    open() {
        if (this.dialog) return;
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal shortcuts-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'shortcuts-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-shortcuts-close></div>
            <div class="modal-panel shortcuts-panel">
                <div class="shortcuts-head">
                    <h2 class="modal-title" id="shortcuts-title">${raw(icon('keyboard'))} Keyboard shortcuts</h2>
                    <button type="button" class="btn-icon" data-shortcuts-close aria-label="Close">${raw(icon('x'))}</button>
                </div>
                <dl class="shortcuts-list">
                    ${raw(SHORTCUTS.map((shortcut) => html`
                        <div class="shortcuts-row">
                            <dt>${shortcut.label}</dt>
                            <dd>${raw(shortcut.keys.map((key) => html`<kbd>${keyLabel(key)}</kbd>`).join('<span class="shortcuts-plus">+</span>'))}</dd>
                        </div>`).join(''))}
                </dl>
            </div>
        `;
        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-shortcuts-close]')) this.close();
        });
        document.body.appendChild(overlay);
        this.dialog = { overlay, previouslyFocused };
        overlay.querySelector('.btn-icon')?.focus();
    }

    close() {
        if (!this.dialog) return;
        this.dialog.overlay.remove();
        this.dialog.previouslyFocused?.focus?.();
        this.dialog = null;
    }
}
