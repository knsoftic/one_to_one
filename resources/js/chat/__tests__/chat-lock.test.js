// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { chatListOrder, lockedChats, unreadTotal } from '../chat-list';
import { ChatLock } from '../chat-lock';
import { lockedRow } from '../templates';

const at = (minutes) => new Date(Date.now() - minutes * 60_000).toISOString();
const chats = [
    { id: 1, last_message: { id: 1, created_at: at(1) }, unread_count: 2, settings: {} },
    { id: 2, last_message: { id: 2, created_at: at(2) }, unread_count: 1, settings: { locked: true } },
    // Details still hidden: no participant or last message until the code is entered.
    { id: 3, participant: null, last_message: null, is_locked_out: true, unread_count: 4, settings: { locked: true } },
    { id: 4, last_message: { id: 4, created_at: at(3) }, unread_count: 0, settings: { archived: true } },
];

function fakeChat(api) {
    return {
        config: { chatLock: { enabled: true, unlockedUntil: null } },
        api: { has: () => true, ...api },
        listMode: 'chats',
        setListMode: vi.fn(function (mode) { this.listMode = mode; }),
        loadConversations: vi.fn(async () => {}),
        activeConversation: () => null,
        closeConversation: vi.fn(),
    };
}

async function submitCode(code) {
    await vi.waitFor(() => expect(document.querySelector('.chat-lock-modal')).not.toBeNull());
    document.querySelector('#chat-lock-pin').value = code;
    document.querySelector('.chat-lock-modal form').dispatchEvent(new Event('submit', { cancelable: true }));
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('C9 chat lock', () => {
    it('keeps locked chats out of the chat list, archive and unread total', () => {
        expect(chatListOrder(chats, 'chats').map((c) => c.id)).toEqual([1]);
        expect(chatListOrder(chats, 'archived').map((c) => c.id)).toEqual([4]);
        expect(chatListOrder(chats, 'locked').map((c) => c.id)).toEqual([2]);
        expect(lockedChats(chats).map((c) => c.id)).toEqual([2, 3]);
        expect(unreadTotal(chats)).toBe(2);
    });

    it('shows only a count on the Locked chats row', () => {
        const host = document.createElement('div');
        host.innerHTML = lockedRow(2, 1);
        expect(host.textContent.replace(/\s+/g, ' ').trim()).toBe('Locked chats 1');
        expect(host.querySelector('[data-open-locked]')).not.toBeNull();
    });

    it('opens the folder only with a valid code', async () => {
        const unlockChats = vi.fn(async (pin) => {
            if (pin !== '2580') throw { response: { status: 422, data: { message: 'Wrong secret code.' } } };
            return { enabled: true, unlocked_until: new Date(Date.now() + 600_000).toISOString() };
        });
        const chat = fakeChat({ unlockChats, lockChats: vi.fn(async () => ({})) });
        const lock = new ChatLock(chat);

        const opening = lock.openFolder();
        await submitCode('12');
        expect(document.querySelector('[data-lock-error]').textContent).toBe('The secret code must be 4 to 8 digits.');
        expect(unlockChats).not.toHaveBeenCalled();

        await submitCode('1111');
        await vi.waitFor(() => expect(document.querySelector('[data-lock-error]').textContent).toBe('Wrong secret code.'));

        await submitCode('2580');
        await opening;
        expect(lock.isUnlocked()).toBe(true);
        expect(chat.loadConversations).toHaveBeenCalled();
        expect(chat.setListMode).toHaveBeenCalledWith('locked');

        // Going back locks the folder again.
        await lock.closeFolder();
        expect(lock.isUnlocked()).toBe(false);
        expect(chat.api.lockChats).toHaveBeenCalled();
        expect(chat.listMode).toBe('chats');
        clearInterval(lock.timer);
    });

    it('asks to create a code before the first chat is locked', async () => {
        const setChatLockPin = vi.fn(async () => ({ enabled: true, unlocked_until: new Date(Date.now() + 600_000).toISOString() }));
        const chat = { ...fakeChat({ setChatLockPin, lockChats: vi.fn(async () => ({})) }), chatList: { apply: vi.fn(async () => {}) }, active: null };
        chat.config.chatLock.enabled = false;
        const lock = new ChatLock(chat);

        const locking = lock.lockChat({ id: 9 });
        await vi.waitFor(() => expect(document.querySelector('#chat-lock-confirm')).not.toBeNull());
        document.querySelector('#chat-lock-pin').value = '2580';
        document.querySelector('#chat-lock-confirm').value = '2581';
        document.querySelector('.chat-lock-modal form').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(document.querySelector('[data-lock-error]').textContent).toBe('The two codes do not match.');

        document.querySelector('#chat-lock-confirm').value = '2580';
        document.querySelector('.chat-lock-modal form').dispatchEvent(new Event('submit', { cancelable: true }));
        await locking;

        expect(setChatLockPin).toHaveBeenCalledWith({ pin: '2580', pin_confirmation: '2580' });
        expect(chat.chatList.apply).toHaveBeenCalledWith({ id: 9 }, { locked: true }, expect.any(String));
        // The folder is not left open after creating the code.
        expect(lock.isUnlocked()).toBe(false);
        clearInterval(lock.timer);
    });
});
