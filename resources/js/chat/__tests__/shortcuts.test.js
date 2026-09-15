// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

import { KeyboardShortcuts, keyLabel, matchShortcut } from '../shortcuts';

const key = (init) => new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init });

function setup() {
    document.body.innerHTML = `
        <div data-chat-app>
            <input data-search-input>
            <button data-action="new-chat"></button>
            <button data-mobile-tab="chats" class="is-active"></button>
            <button data-mobile-tab="calls"></button>
            <div data-conversation-list>
                <button data-conversation-id="1"></button>
                <button data-conversation-id="2"></button>
                <button data-conversation-id="3"></button>
            </div>
            <section data-chat-panel></section>
            <textarea data-composer></textarea>
        </div>`;
    const conversation = { id: 2, settings: { archived: false, pinned: true, muted: false } };
    const chat = {
        active: { id: 2 },
        el: {
            app: document.querySelector('[data-chat-app]'),
            searchInput: document.querySelector('[data-search-input]'),
            conversationList: document.querySelector('[data-conversation-list]'),
            panel: document.querySelector('[data-chat-panel]'),
            composerInput: document.querySelector('[data-composer]'),
        },
        config: { routes: {} },
        activeConversation: () => conversation,
        openConversation: vi.fn(),
        closeConversation: vi.fn(),
        chatList: { handle: vi.fn() },
    };
    const shortcuts = new KeyboardShortcuts(chat);
    instances.push(shortcuts);
    return { chat, shortcuts, conversation };
}

const instances = [];

afterEach(() => {
    instances.splice(0).forEach((instance) => instance.destroy());
    document.body.innerHTML = '';
});

describe('X6 keyboard shortcuts', () => {
    it('matches keys on Windows and Mac', () => {
        expect(matchShortcut(key({ key: 'k', code: 'KeyK', ctrlKey: true }), false)).toBe('search');
        expect(matchShortcut(key({ key: 'k', code: 'KeyK', metaKey: true }), true)).toBe('search');
        expect(matchShortcut(key({ key: 'k', code: 'KeyK', ctrlKey: true }), true)).toBeNull();
        expect(matchShortcut(key({ key: 'ArrowDown', altKey: true }), false)).toBe('next');
        expect(matchShortcut(key({ key: 'N', code: 'KeyN', ctrlKey: true, altKey: true, shiftKey: true }), false)).toBe('new-group');
        // Letters by key position, so other keyboard layouts work too.
        expect(matchShortcut(key({ key: 'ø', code: 'KeyE', ctrlKey: true, altKey: true }), false)).toBe('archive');
        expect(matchShortcut(key({ key: '5', code: 'Digit5', altKey: true }), false)).toBe('section:calls');
        expect(matchShortcut(key({ key: 'a', code: 'KeyA' }), false)).toBeNull();
        expect(keyLabel('ArrowUp')).toBe('↑');
    });

    it('searches, moves between chats and acts on the open chat', () => {
        const { chat } = setup();

        window.dispatchEvent(key({ key: 'k', code: 'KeyK', ctrlKey: true }));
        expect(document.activeElement).toBe(chat.el.searchInput);

        window.dispatchEvent(key({ key: 'ArrowDown', altKey: true }));
        expect(chat.openConversation).toHaveBeenLastCalledWith(3);
        window.dispatchEvent(key({ key: 'ArrowUp', altKey: true }));
        expect(chat.openConversation).toHaveBeenLastCalledWith(1);

        window.dispatchEvent(key({ key: 'p', code: 'KeyP', ctrlKey: true, altKey: true, shiftKey: true }));
        expect(chat.chatList.handle).toHaveBeenLastCalledWith('unpin', expect.objectContaining({ id: 2 }));
        window.dispatchEvent(key({ key: 'e', code: 'KeyE', ctrlKey: true, altKey: true }));
        expect(chat.chatList.handle).toHaveBeenLastCalledWith('archive', expect.anything());

        const newChat = vi.fn();
        document.querySelector('[data-action="new-chat"]').addEventListener('click', newChat);
        window.dispatchEvent(key({ key: 'n', code: 'KeyN', ctrlKey: true, altKey: true }));
        expect(newChat).toHaveBeenCalled();

        const calls = vi.fn();
        document.querySelector('[data-mobile-tab="calls"]').addEventListener('click', calls);
        window.dispatchEvent(key({ key: '5', code: 'Digit5', altKey: true }));
        expect(calls).toHaveBeenCalled();
    });

    it('Escape closes open things first, then the chat', () => {
        const { chat } = setup();

        document.body.insertAdjacentHTML('beforeend', '<div class="group-info"></div>');
        window.dispatchEvent(key({ key: 'Escape' }));
        expect(chat.closeConversation).not.toHaveBeenCalled();

        document.querySelector('.group-info').remove();
        chat.actions = { mode: { type: 'reply' } };
        window.dispatchEvent(key({ key: 'Escape' }));
        expect(chat.closeConversation).not.toHaveBeenCalled();

        chat.actions = null;
        window.dispatchEvent(key({ key: 'Escape' }));
        expect(chat.closeConversation).toHaveBeenCalledTimes(1);
    });

    it('shows the list of shortcuts', () => {
        const { shortcuts } = setup();
        window.dispatchEvent(key({ key: '/', code: 'Slash', ctrlKey: true }));
        expect(document.querySelectorAll('.shortcuts-row').length).toBeGreaterThan(10);
        expect(document.querySelector('.shortcuts-row kbd').textContent).toMatch(/Ctrl|⌘/);

        window.dispatchEvent(key({ key: 'Escape' }));
        expect(document.querySelector('.shortcuts-dialog')).toBeNull();

        document.dispatchEvent(new CustomEvent('chat:action', { detail: { action: 'shortcuts' } }));
        expect(shortcuts.dialog).not.toBeNull();
    });
});
