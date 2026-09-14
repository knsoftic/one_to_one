// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { Communities, linkableGroups } from '../communities';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const community = (overrides = {}) => ({
    id: 5,
    name: 'Model Town Society',
    description: 'Everything about our block',
    avatar_url: null,
    initials: 'MT',
    avatar_hue: 120,
    is_member: true,
    is_admin: true,
    member_count: 3,
    announcement_id: 40,
    groups: [
        { id: 41, name: 'Cricket', member_count: 2, is_member: true, initials: 'C', avatar_hue: 10 },
        { id: 42, name: 'Parking', member_count: 1, is_member: false, initials: 'P', avatar_hue: 20 },
    ],
    ...overrides,
});

function setup({ items = [community()], conversations = [] } = {}) {
    document.body.innerHTML = `
        <aside data-sidebar data-mode="chats">
            <div data-sidebar-view="chats"></div>
            <div data-sidebar-view="communities" hidden>
                <div data-communities-title></div>
                <div data-communities-body></div>
            </div>
        </aside>`;
    const chat = {
        config: { routes: { chat: '/chat' } },
        api: {
            has: () => true,
            communities: vi.fn(async () => ({ data: items })),
            joinCommunityGroup: vi.fn(async () => ({ id: 42, type: 'group', group: { name: 'Parking' } })),
            linkCommunityGroup: vi.fn(async (id, groupId) => ({ id: groupId, type: 'group', group: { name: 'x' } })),
            leaveCommunity: vi.fn(async () => ({ left: true })),
            joinCommunity: vi.fn(async () => community()),
            communityInvite: vi.fn(),
            resetCommunityInvite: vi.fn(),
        },
        conversations: new Map(conversations.map((c) => [c.id, c])),
        groupInvites: { openLink: vi.fn() },
        showListView: vi.fn(),
        upsertConversation: vi.fn((c) => c),
        openConversation: vi.fn(),
        loadConversations: vi.fn(),
        active: null,
    };
    return { chat, communities: new Communities(chat) };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G10 communities', () => {
    it('only offers groups I run that are not in a community yet', () => {
        const group = (id, group) => ({ id, type: 'group', group: { name: `G${id}`, is_member: true, my_role: 'admin', ended: false, community: null, ...group } });
        const list = [
            group(1),
            group(2, { my_role: 'member' }),
            group(3, { community: { id: 9, name: 'Other' } }),
            group(4, { ended: true }),
            { id: 5, type: 'direct' },
        ];
        expect(linkableGroups(list)).toEqual([{ id: 1, name: 'G1' }]);
    });

    it('lists communities and shows announcements, my groups and groups to join', async () => {
        const { communities } = setup();
        await communities.open();

        expect(document.querySelector('[data-sidebar]').dataset.mode).toBe('communities');
        expect(document.querySelector('.community-card-meta').textContent).toBe('2 groups · 3 members');

        document.querySelector('[data-community="5"]').click();
        expect(document.querySelector('[data-communities-title]').textContent).toBe('Model Town Society');
        expect(document.querySelector('.community-announcements').dataset.communityOpen).toBe('40');
        expect(document.querySelector('[data-community-open="41"]')).not.toBeNull();
        expect(document.querySelector('[data-community-join="42"]')).not.toBeNull();
        expect(document.querySelector('[data-community-add-group]')).not.toBeNull();
    });

    it('hides admin tools from members', async () => {
        const { communities } = setup({ items: [community({ is_admin: false })] });
        await communities.open(5);
        expect(document.querySelector('[data-community-add-group]')).toBeNull();
        expect(document.querySelector('[data-community-delete]')).toBeNull();
        expect(document.querySelector('[data-community-leave]')).not.toBeNull();
    });

    it('joins a group, opens chats, and reuses the invite dialog', async () => {
        const { communities, chat } = setup();
        await communities.open(5);

        document.querySelector('[data-community-join="42"]').click();
        await vi.waitFor(() => expect(chat.api.joinCommunityGroup).toHaveBeenCalledWith(5, 42));
        await vi.waitFor(() => expect(chat.upsertConversation).toHaveBeenCalled());

        document.querySelector('[data-community-invite]').click();
        expect(chat.groupInvites.openLink).toHaveBeenCalledWith(expect.objectContaining({ name: 'Model Town Society', kind: 'community' }));
        chat.groupInvites.openLink.mock.calls[0][0].load();
        expect(chat.api.communityInvite).toHaveBeenCalledWith(5);

        document.querySelector('[data-community-open="40"]').click();
        expect(chat.openConversation).toHaveBeenCalledWith(40);
        expect(document.querySelector('[data-sidebar]').dataset.mode).toBe('chats');
        expect(document.querySelector('[data-sidebar-view="communities"]').hidden).toBe(true);
    });

    it('asks before joining from an invite link', async () => {
        const { communities, chat } = setup();
        const joining = communities.offerToJoin({ valid: true, token: 'abc', name: 'Old Students', member_count: 4, is_member: false });
        await vi.waitFor(() => expect(document.querySelector('[data-modal-value="join"]')).not.toBeNull());
        expect(document.querySelector('.modal').textContent).toContain('4 members');
        document.querySelector('[data-modal-value="join"]').click();
        await joining;
        expect(chat.api.joinCommunity).toHaveBeenCalledWith('abc');
        expect(chat.loadConversations).toHaveBeenCalled();
    });
});
