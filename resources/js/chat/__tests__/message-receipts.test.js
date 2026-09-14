// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { openMessageInfo, receiptGroups } from '../message-info';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const receipt = (id, name, delivered, seen, saved = null) => ({
    user: { id, name, saved_name: saved, initials: name[0], avatar_hue: id * 30 },
    delivered_at: delivered,
    seen_at: seen,
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G6 read by / delivered to', () => {
    it('sorts people into read, delivered and waiting, newest first', () => {
        const groups = receiptGroups([
            receipt(2, 'Bilal', '2026-09-20T10:00:00Z', '2026-09-20T10:05:00Z'),
            receipt(3, 'Sara', '2026-09-20T10:01:00Z', null),
            receipt(4, 'Hina', null, null),
            receipt(5, 'Saad', '2026-09-20T10:00:00Z', '2026-09-20T10:09:00Z'),
        ]);
        expect(groups.read.map((r) => r.user.name)).toEqual(['Saad', 'Bilal']);
        expect(groups.delivered.map((r) => r.user.name)).toEqual(['Sara']);
        expect(groups.waiting.map((r) => r.user.name)).toEqual(['Hina']);
    });

    it('loads group receipts into message info with saved names', async () => {
        const chat = {
            conversations: new Map([[9, { type: 'group' }]]),
            decorate: (user) => user,
            api: {
                messageReceipts: vi.fn(async () => ({
                    data: [receipt(2, 'Bilal', '2026-09-20T10:00:00Z', '2026-09-20T10:05:00Z', 'Bilal Bhai'), receipt(3, 'Sara', '2026-09-20T10:01:00Z', null)],
                })),
            },
        };

        openMessageInfo({ id: 44, conversation_id: 9, type: 'text', body: 'Dinner at 8?', is_mine: true, created_at: '2026-09-20T09:59:00Z' }, chat);
        await vi.waitFor(() => expect(document.querySelectorAll('.receipt-row')).toHaveLength(2));

        const sections = [...document.querySelectorAll('.receipt-section')].map((section) => ({
            title: section.querySelector('.receipt-title').textContent.trim(),
            names: [...section.querySelectorAll('.receipt-name')].map((el) => el.textContent),
        }));
        expect(sections).toEqual([
            { title: 'Read by', names: ['Bilal Bhai'] },
            { title: 'Delivered to', names: ['Sara'] },
        ]);
        expect(chat.api.messageReceipts).toHaveBeenCalledWith(44);
    });

    it('keeps the simple sent / delivered / read list in one-to-one chats', () => {
        openMessageInfo({ id: 5, conversation_id: 3, type: 'text', body: 'hi', is_mine: true, created_at: '2026-09-20T09:59:00Z', delivered_at: '2026-09-20T10:00:00Z' }, { conversations: new Map([[3, { type: 'direct' }]]) });
        expect([...document.querySelectorAll('.message-info-row dt')].map((el) => el.textContent.trim())).toEqual(['Read', 'Delivered', 'Sent']);
    });
});
