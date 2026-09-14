// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { Groups, groupSummary } from '../groups';
import * as T from '../templates';

const saved = new Map([[2, 'Bilal Bhai']]);
T.setTemplateContext({ meId: 1, nameOf: (id) => saved.get(Number(id)) ?? { 3: 'Sara' }[id] ?? '' });

const member = (id, name, role = 'member', extra = {}) => ({
    user: { id, name, username: name.toLowerCase(), initials: name[0], avatar_hue: id * 40 },
    role,
    active: true,
    is_creator: false,
    ...extra,
});

const groupConversation = (overrides = {}) => ({
    id: 9,
    type: 'group',
    participant: null,
    unread_count: 2,
    settings: {},
    last_message: { id: 5, sender_id: 3, sender_name: 'Sara', is_mine: false, type: 'text', preview: 'Dinner at 8?', created_at: new Date().toISOString() },
    group: {
        name: 'Family',
        initials: 'F',
        avatar_hue: 40,
        member_count: 3,
        is_member: true,
        my_role: 'member',
        can_send: true,
        can_edit_info: true,
        max_members: 256,
        members: [member(1, 'Ayesha', 'admin', { is_creator: true }), member(2, 'Bilal'), member(3, 'Sara')],
        ...overrides,
    },
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G1 group chats in the UI', () => {
    it('words group notices for the reader', () => {
        expect(T.systemNoticeText({ event: 'group_created', actor: { id: 1, name: 'Ayesha' }, name: 'Family' })).toBe('You created group "Family"');
        expect(T.systemNoticeText({ event: 'members_added', actor: { id: 3, name: 'Sara' }, users: [{ id: 1, name: 'Ayesha' }, { id: 2, name: 'Bilal' }] })).toBe('Sara added you, Bilal Bhai');
        expect(T.systemNoticeText({ event: 'settings_changed', actor: { id: 2, name: 'Bilal' }, only_admins_send: true })).toBe("Bilal Bhai changed this group's settings to allow only admins to send messages");
        expect(T.systemNoticeText({ event: 'member_left', actor: { id: 44, name: 'Hina' } })).toBe('Hina left');
        expect(T.systemNoticeText({ event: 'disappearing', text: '⏱️ Disappearing messages turned off' })).toBe('⏱️ Disappearing messages turned off');
    });

    it('shows who wrote the last message and who is typing in the chat list', () => {
        const conversation = { ...groupConversation(), participant: { id: 'group-9', name: 'Family', initials: 'F', is_group: true } };
        const item = document.createElement('div');

        item.innerHTML = T.conversationItem(conversation);
        expect(item.querySelector('.conversation-preview-text').textContent).toBe('Sara: Dinner at 8?');
        expect(item.querySelector('.avatar-status')).toBeNull();

        item.innerHTML = T.conversationItem({ ...conversation, last_message: { ...conversation.last_message, sender_id: 2 } });
        expect(item.querySelector('.conversation-preview-text').textContent).toBe('Bilal Bhai: Dinner at 8?');

        item.innerHTML = T.conversationItem(conversation, { typing: { action: 'typing', name: 'Sara' } });
        expect(item.querySelector('.conversation-preview-text').textContent).toBe('Sara is typing…');
    });

    it('labels group messages with their writer', () => {
        const el = document.createElement('div');
        el.innerHTML = T.messageBubble({ id: 7, type: 'text', body: 'Hello', sender_id: 3, is_mine: false, created_at: new Date().toISOString(), sender_label: 'Sara', sender_hue: 40 });
        expect(el.querySelector('.message-sender').textContent).toBe('Sara');

        el.innerHTML = T.messageBubble({ id: 8, type: 'text', body: 'Hi', sender_id: 1, is_mine: true, created_at: new Date().toISOString() });
        expect(el.querySelector('.message-sender')).toBeNull();
    });

    it('sums up the people in the header', () => {
        const group = groupConversation().group;
        expect(groupSummary(group, 2, (id, name) => saved.get(Number(id)) ?? name)).toBe('You, Ayesha, Sara');
        expect(groupSummary({ member_count: 12 }, 1, () => '')).toBe('12 members');
    });

    it('group info shows members and admin tools only to admins', () => {
        const chat = {
            me: { id: 2 },
            api: { has: () => true },
            el: { headerUser: document.createElement('div') },
            conversations: new Map(),
            config: {},
            participantOf: (c) => ({ id: `group-${c.id}`, name: c.group.name, initials: 'F', is_group: true }),
            displayName: (id, name) => saved.get(Number(id)) ?? name,
            presenceOf: (user) => user,
        };
        const groups = new Groups(chat);
        const conversation = groupConversation();
        chat.conversations.set(9, conversation);

        groups.panel = document.createElement('div');
        groups.panel.innerHTML = '<aside data-info-body></aside>';
        document.body.appendChild(groups.panel);
        groups.panelConversationId = 9;

        groups.renderInfo(conversation);
        const panel = groups.panel;
        expect(panel.querySelector('.group-info-name').textContent).toBe('Family');
        expect([...panel.querySelectorAll('.group-member-name')].map((el) => el.textContent)).toEqual(['Ayesha', 'You', 'Sara']);
        expect(panel.querySelectorAll('.group-admin-badge')).toHaveLength(1);
        expect(panel.querySelector('[data-info-setting]')).toBeNull();
        expect(panel.querySelector('[data-info-add]')).not.toBeNull();
        expect(panel.querySelector('[data-info-exit]')).not.toBeNull();
        expect(panel.querySelector('[data-info-delete]')).toBeNull();

        groups.renderInfo(groupConversation({ my_role: 'admin', only_admins_send: true }));
        expect(panel.querySelector('[data-info-setting="only_admins_send"]').checked).toBe(true);
        expect(panel.querySelector('[data-info-delete]')).not.toBeNull();

        groups.renderInfo(groupConversation({ is_member: false, can_edit_info: false, my_role: null }));
        expect(panel.querySelector('.group-info-notice').textContent).toContain('no longer a member');
        expect(panel.querySelector('[data-info-add]')).toBeNull();
        expect(panel.querySelector('[data-info-delete-chat]')).not.toBeNull();
    });
});
