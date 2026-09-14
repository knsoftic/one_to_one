// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { Channels, compactCount, followersLabel, keepMyChoices } from '../channels';
import { applyVote } from '../poll';
import { applyReaction } from '../reactions';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const channelConversation = (overrides = {}) => ({
    id: 70,
    type: 'channel',
    unread_count: 2,
    last_message: { type: 'text', preview: 'Rain after 4 pm', created_at: '2026-09-14T10:00:00Z' },
    channel: { name: 'Lahore Weather', description: 'Forecasts', initials: 'LW', avatar_hue: 200, followers_count: 1520, is_following: true, is_admin: false, can_send: false, link: 'https://chat.test/channel/abc' },
    ...overrides,
});

function setup({ conversations = [channelConversation()], results = [] } = {}) {
    document.body.innerHTML = `
        <aside data-sidebar data-mode="chats">
            <div data-sidebar-view="chats"></div>
            <div data-sidebar-view="channels" hidden>
                <input data-channels-search>
                <div data-channels-body></div>
            </div>
        </aside>`;
    const map = new Map(conversations.map((c) => [c.id, c]));
    const chat = {
        me: { id: 1 },
        config: { routes: { chat: '/chat' } },
        el: { headerUser: document.createElement('div') },
        api: {
            has: () => true,
            channels: vi.fn(async () => ({ data: results })),
            channel: vi.fn(async (id) => ({ id, name: 'PSL Scores', followers_count: 3, is_following: false, updates: [{ id: 1, type: 'text', preview: 'Toss won by Lahore', reactions_count: 12, created_at: '2026-09-14T09:00:00Z' }] })),
            followChannel: vi.fn(async (id) => ({ id, type: 'channel', channel: { name: 'PSL Scores', is_following: true } })),
            unfollowChannel: vi.fn(async () => ({ following: false })),
            deleteChannel: vi.fn(async () => ({ deleted: true })),
        },
        conversations: map,
        groupInvites: { openLink: vi.fn() },
        showListView: vi.fn(),
        upsertConversation: vi.fn((c) => c),
        openConversation: vi.fn(),
        forgetConversation: vi.fn(),
        activeConversation: () => null,
        active: null,
    };
    return { chat, channels: new Channels(chat) };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G11 channels', () => {
    it('shows follower counts in short form', () => {
        expect(compactCount(950)).toBe('950');
        expect(compactCount(1520)).toBe('1.5K');
        expect(compactCount(25_400)).toBe('25K');
        expect(compactCount(2_500_000)).toBe('2.5M');
        expect(followersLabel(1)).toBe('1 follower');
        expect(followersLabel(1520)).toBe('1.5K followers');
    });

    it('keeps my reaction and votes when an update without names arrives', () => {
        const current = {
            reactions: [{ emoji: '🔥', count: 1, user_ids: [1] }],
            poll: { options: [{ id: 1, count: 0, voter_ids: [] }, { id: 2, count: 1, voter_ids: [1] }] },
        };
        const incoming = {
            reactions: [{ emoji: '🔥', count: 2, user_ids: [] }, { emoji: '👍', count: 1, user_ids: [] }],
            poll: { options: [{ id: 1, count: 1, voter_ids: [] }, { id: 2, count: 1, voter_ids: [] }] },
        };
        const merged = keepMyChoices(incoming, current, 1);
        expect(merged.reactions).toEqual([{ emoji: '🔥', count: 2, user_ids: [1] }, { emoji: '👍', count: 1, user_ids: [] }]);
        expect(merged.poll.options.map((o) => o.voter_ids)).toEqual([[], [1]]);
    });

    it('counts reactions and votes from the totals, not the listed ids', () => {
        // 5 people reacted 🔥 (only I am listed); I switch to 👍.
        expect(applyReaction([{ emoji: '🔥', count: 5, user_ids: [1] }], 1, '👍')).toEqual([
            { emoji: '🔥', count: 4, user_ids: [] },
            { emoji: '👍', count: 1, user_ids: [1] },
        ]);
        const poll = { total_voters: 7, options: [{ id: 1, count: 4, voter_ids: [] }, { id: 2, count: 3, voter_ids: [1] }] };
        expect(applyVote(poll, 1, [1])).toMatchObject({ total_voters: 7, options: [{ count: 5, voter_ids: [1] }, { count: 2, voter_ids: [] }] });
        expect(applyVote(poll, 1, [])).toMatchObject({ total_voters: 6, options: [{ count: 4 }, { count: 2 }] });
        // Groups still work the same way.
        expect(applyReaction([{ emoji: '🔥', count: 2, user_ids: [1, 2] }], 1, null)).toEqual([{ emoji: '🔥', count: 1, user_ids: [2] }]);
    });

    it('lists followed channels and finds others to follow', async () => {
        const { channels, chat } = setup({ results: [{ id: 70, name: 'Lahore Weather', is_following: true }, { id: 81, name: 'PSL Scores', description: 'Live scores', followers_count: 3, is_following: false }] });
        await channels.open();

        expect(document.querySelector('[data-sidebar]').dataset.mode).toBe('channels');
        expect(document.querySelector('[data-channel-open="70"] .channel-row-meta').textContent).toBe('Rain after 4 pm');
        expect(document.querySelectorAll('[data-channel-follow]')).toHaveLength(1);
        expect(document.querySelector('[data-channel-preview="81"] .channel-row-meta').textContent).toBe('3 followers · Live scores');

        document.querySelector('[data-channel-follow="81"]').click();
        await vi.waitFor(() => expect(chat.openConversation).toHaveBeenCalledWith(81));
        expect(chat.api.followChannel).toHaveBeenCalledWith(81);
        expect(document.querySelector('[data-sidebar]').dataset.mode).toBe('chats');
    });

    it('previews a channel with its latest updates before following', async () => {
        const { channels, chat } = setup();
        await channels.preview(81);

        const dialog = document.querySelector('.channel-preview');
        expect(dialog.querySelector('.modal-title').textContent).toBe('PSL Scores');
        expect(dialog.querySelector('.channel-update-text').textContent).toBe('Toss won by Lahore');
        dialog.querySelector('[data-preview-follow]').click();
        await vi.waitFor(() => expect(document.querySelector('.channel-preview')).toBeNull());
        expect(chat.api.followChannel).toHaveBeenCalledWith(81);
    });

    it('channel info: followers unfollow, admins edit and delete, and anyone shares the link', async () => {
        const { channels, chat } = setup({ conversations: [channelConversation(), channelConversation({ id: 71, channel: { ...channelConversation().channel, name: 'My Shop', is_admin: true } })] });

        channels.openInfo(70);
        expect(document.querySelector('.group-info-count').textContent).toBe('Channel · 1.5K followers');
        expect(document.querySelector('[data-channel-edit]')).toBeNull();
        document.querySelector('[data-channel-share]').click();
        expect(chat.groupInvites.openLink).toHaveBeenCalledWith(expect.objectContaining({ kind: 'channel', name: 'Lahore Weather', reset: null }));
        await expect(chat.groupInvites.openLink.mock.calls[0][0].load()).resolves.toEqual({ url: 'https://chat.test/channel/abc' });

        const unfollowing = channels.unfollow(chat.conversations.get(70));
        await vi.waitFor(() => expect(document.querySelector('[data-modal-value="unfollow"]')).not.toBeNull());
        document.querySelector('[data-modal-value="unfollow"]').click();
        await unfollowing;
        expect(chat.api.unfollowChannel).toHaveBeenCalledWith(70);
        expect(chat.forgetConversation).toHaveBeenCalledWith(70);

        channels.openInfo(71);
        expect(document.querySelector('[data-channel-edit]')).not.toBeNull();
        expect(document.querySelector('[data-channel-delete]')).not.toBeNull();
        expect(document.querySelector('[data-channel-unfollow]')).toBeNull();
    });
});
