// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { Broadcasts, broadcastName } from '../broadcasts';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const user = (id, name) => ({ id, name, username: name.toLowerCase(), initials: name[0], avatar_hue: id * 40 });

function setup(overrides = {}) {
    const conversation = { id: 20, type: 'broadcast', broadcast: { name: null, recipient_count: 2, is_owner: true, max_recipients: 256, recipients: [user(2, 'Bilal'), user(3, 'Sara')] } };
    const chat = {
        me: { id: 1 },
        config: { groups: { maxBroadcastRecipients: 256 } },
        api: {
            has: () => true,
            createBroadcast: vi.fn(async () => ({ ...conversation })),
            updateBroadcast: vi.fn(async (id, changes) => ({ ...conversation, broadcast: { ...conversation.broadcast, ...changes } })),
            deleteBroadcast: vi.fn(async () => ({ deleted: true })),
        },
        el: { headerUser: document.createElement('div') },
        conversations: new Map([[20, conversation]]),
        contactsPanel: { contacts: [{ name: 'Hina Office', user: user(4, 'Hina') }] },
        sortedConversations: () => [],
        participantOf: () => ({ id: 'broadcast-20', name: broadcastName(conversation.broadcast), avatar_icon: 'megaphone' }),
        displayName: (id, name) => name,
        presenceOf: (u) => u,
        upsertConversation: vi.fn((c) => c),
        openConversation: vi.fn(),
        forgetConversation: vi.fn(),
        renderHeader: vi.fn(),
        refreshConversation: vi.fn(async () => conversation),
        active: null,
        ...overrides,
    };
    return { chat, conversation, broadcasts: new Broadcasts(chat) };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G9 broadcast lists', () => {
    it('names a list by its name or its number of recipients', () => {
        expect(broadcastName({ name: 'Customers', recipient_count: 9 })).toBe('Customers');
        expect(broadcastName({ recipient_count: 3 })).toBe('3 recipients');
        expect(broadcastName({ recipient_count: 1 })).toBe('1 recipient');
    });

    it('shows a megaphone instead of letters', () => {
        const el = document.createElement('div');
        el.innerHTML = T.avatar({ id: 'broadcast-20', name: '2 recipients', avatar_icon: 'megaphone' }, 'md');
        expect(el.querySelector('.avatar-fallback.is-accent svg')).not.toBeNull();
    });

    it('shows recipients in list info and edits them with the current ones ticked', async () => {
        const { broadcasts, chat } = setup();
        await broadcasts.openInfo(20);

        expect(document.querySelector('.group-info-name').textContent).toBe('2 recipients');
        expect([...document.querySelectorAll('.group-member-name')].map((el) => el.textContent)).toEqual(['Bilal', 'Sara']);

        document.querySelector('[data-broadcast-edit]').click();
        await vi.waitFor(() => expect(document.querySelector('.people-picker')).not.toBeNull());
        const boxes = [...document.querySelectorAll('.people-picker-row input')];
        expect(boxes.map((box) => [box.value, box.checked])).toEqual([['2', true], ['4', false], ['3', true]]);

        boxes[1].click();
        document.querySelector('.people-picker [type="submit"]').click();
        await vi.waitFor(() => expect(chat.api.updateBroadcast).toHaveBeenCalledWith(20, { user_ids: [2, 4, 3] }));
    });

    it('deletes the whole list', async () => {
        const { broadcasts, chat, conversation } = setup();
        const removing = broadcasts.remove(conversation);
        await vi.waitFor(() => expect(document.querySelector('[data-modal-value="delete"]')).not.toBeNull());
        document.querySelector('[data-modal-value="delete"]').click();
        await removing;
        expect(chat.api.deleteBroadcast).toHaveBeenCalledWith(20);
        expect(chat.forgetConversation).toHaveBeenCalledWith(20);
    });
});
