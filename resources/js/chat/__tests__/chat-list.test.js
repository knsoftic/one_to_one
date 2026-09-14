// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { chatListOrder, hasUnread, unreadTotal } from '../chat-list';
import { conversationItem, isChatMuted, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

const at = (minutes) => new Date(Date.parse('2026-09-14T12:00:00Z') + minutes * 60_000).toISOString();
const chat = (id, minutes, settings = {}, extra = {}) => ({
    id,
    participant: { id: id + 100, name: `Person ${id}` },
    last_message: { id: id * 10, created_at: at(minutes), preview: 'hi' },
    unread_count: 0,
    settings,
    ...extra,
});

describe('chat list (Phase 2)', () => {
    it('puts pinned chats first, keeps archived and deleted chats out of the main list', () => {
        const chats = [
            chat(1, 10),
            chat(2, 5, { pinned: true, pinned_at: at(-100) }),
            chat(3, 20, { archived: true }),
            chat(4, 30, { hidden: true }),
            chat(5, 1, { pinned: true, pinned_at: at(-50) }),
            { ...chat(6, 0), last_message: null, settings: { cleared_at: at(15) } },
        ];

        expect(chatListOrder(chats).map((c) => c.id)).toEqual([5, 2, 6, 1]);
        expect(chatListOrder(chats, 'archived').map((c) => c.id)).toEqual([3]);
    });

    it('counts unread marks and leaves muted and archived chats out of the total', () => {
        const future = at(24 * 60 * 365 * 10);
        const chats = [
            chat(1, 0, {}, { unread_count: 2 }),
            chat(2, 0, { muted: true, muted_until: future }, { unread_count: 5 }),
            chat(3, 0, { archived: true }, { unread_count: 7 }),
            chat(4, 0, { marked_unread: true }),
        ];

        expect(unreadTotal(chats)).toBe(2);
        expect(chats.filter(hasUnread).map((c) => c.id)).toEqual([1, 2, 3, 4]);
    });

    it('treats an ended mute as not muted', () => {
        expect(isChatMuted({ settings: { muted: true, muted_until: '2000-01-01T00:00:00Z' } })).toBe(false);
        expect(isChatMuted({ settings: { muted: true, muted_until: null } })).toBe(true);
    });

    it('shows pin, mute and unread-mark flags plus an options button', () => {
        const host = document.createElement('div');
        host.innerHTML = conversationItem(chat(7, 0, { pinned: true, muted: true, marked_unread: true }));

        expect(host.querySelector('[title="Pinned"]')).not.toBeNull();
        expect(host.querySelector('[title="Muted"]')).not.toBeNull();
        expect(host.querySelector('.badge-dot')).not.toBeNull();
        expect(host.querySelector('.conversation-item').classList.contains('has-unread')).toBe(true);
        expect(host.querySelector('[data-chat-menu="7"]')).not.toBeNull();

        host.innerHTML = conversationItem(chat(8, 0, { muted: true }, { unread_count: 3 }));
        expect(host.querySelector('.badge-muted').textContent).toBe('3');
    });
});
