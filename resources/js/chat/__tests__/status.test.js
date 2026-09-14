// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { Statuses, firstUnseen, statusRing, statusSections, statusTime } from '../status';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: (id) => ({ 2: 'Ayesha' })[id] ?? '' });

const minutesAgo = (n) => new Date(Date.now() - n * 60_000).toISOString();
const user = (id, name) => ({ id, name, initials: name[0], avatar_hue: id * 40 });
const text = (id, userId, viewed = false, extra = {}) => ({ id, user_id: userId, type: 'text', text: `Update ${id}`, background: 'rose', font: 1, viewed, created_at: minutesAgo(30 - id), ...extra });

function feed() {
    return {
        privacy: 'contacts',
        mine: [text(1, 1, true, { views_count: 2 })],
        updates: [
            { user: user(2, 'Ayesha'), statuses: [text(10, 2, true), text(11, 2)], latest_at: minutesAgo(5), viewed: false, muted: false },
            { user: user(3, 'Bilal'), statuses: [text(20, 3, true)], latest_at: minutesAgo(40), viewed: true, muted: false },
            { user: user(4, 'Sara'), statuses: [text(30, 4)], latest_at: minutesAgo(2), viewed: false, muted: true },
        ],
    };
}

function setup(data = feed()) {
    document.body.innerHTML = `
        <aside data-sidebar data-mode="chats">
            <div data-sidebar-view="chats"></div>
            <div data-sidebar-view="status" hidden><div data-status-body></div></div>
        </aside>
        <span data-status-dot hidden></span>`;
    const chat = {
        me: user(1, 'Me'),
        config: { statuses: { maxVideoSeconds: 60 } },
        api: {
            has: () => true,
            statuses: vi.fn(async () => data),
            viewStatus: vi.fn(async () => ({ viewed: true })),
            replyStatus: vi.fn(async () => ({ id: 99, conversation_id: 7, sender_id: 1 })),
            reactStatus: vi.fn(async () => ({ reaction: '🔥', message: { id: 100, conversation_id: 7, sender_id: 1 } })),
            statusViewers: vi.fn(async () => ({ data: [{ user: user(3, 'Bilal'), viewed_at: minutesAgo(1), reaction: '😍' }, { user: user(4, 'Sara'), viewed_at: minutesAgo(3), reaction: null }] })),
            deleteStatus: vi.fn(async () => ({ deleted: true })),
            createStatus: vi.fn(async (payload) => ({ ...text(5, 1), text: payload.text, views_count: 0 })),
            statusPrivacy: vi.fn(async () => ({ mode: 'contacts', except_ids: [3], only_ids: [] })),
            updateStatusPrivacy: vi.fn(async (payload) => ({ mode: payload.mode, except_ids: payload.except_ids, only_ids: payload.only_ids })),
            muteStatus: vi.fn(async () => ({ muted: true })),
            unmuteStatus: vi.fn(async () => ({ muted: false })),
        },
        displayName: (id, name) => name,
        showListView: vi.fn(),
        normalizeMessage: (message) => ({ ...message, is_mine: true }),
        onOwnMessageStored: vi.fn(),
        channels: { open: vi.fn() },
    };
    return { chat, statuses: new Statuses(chat) };
}

afterEach(() => {
    document.querySelectorAll('.status-viewer, .status-composer, .modal').forEach((el) => el.remove());
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('Phase 5 status helpers', () => {
    it('says how long ago an update was posted', () => {
        const now = Date.parse('2026-09-14T12:00:00Z');
        expect(statusTime('2026-09-14T11:59:40Z', now)).toBe('Just now');
        expect(statusTime('2026-09-14T11:59:00Z', now)).toBe('1 minute ago');
        expect(statusTime('2026-09-14T11:15:00Z', now)).toBe('45 minutes ago');
        expect(statusTime('2026-09-14T09:00:00Z', now)).toMatch(/,/);
    });

    it('draws one ring segment per update and greys out seen ones', () => {
        const el = document.createElement('div');
        el.innerHTML = statusRing([{ viewed: true }, { viewed: false }, { viewed: false }]);
        expect(el.querySelectorAll('circle')).toHaveLength(3);
        expect(el.querySelectorAll('circle.is-viewed')).toHaveLength(1);
        expect(firstUnseen([{ viewed: true }, { viewed: false }])).toBe(1);
        expect(firstUnseen([{ viewed: true }])).toBe(0);
    });

    it('splits the feed into recent, viewed and muted', () => {
        const parts = statusSections(feed().updates);
        expect(parts.recent.map((e) => e.user.name)).toEqual(['Ayesha']);
        expect(parts.viewed.map((e) => e.user.name)).toEqual(['Bilal']);
        expect(parts.muted.map((e) => e.user.name)).toEqual(['Sara']);
    });

    it('shows the status an answer is about in the chat bubble', () => {
        const el = document.createElement('div');
        el.innerHTML = T.messageBubble({ id: 5, sender_id: 3, is_mine: false, type: 'text', body: '😍', status: 'sent', created_at: minutesAgo(1), reactions: [], status_quote: { id: 9, owner_id: 1, type: 'text', text: 'New job!', background: 'violet', reaction: true, available: true, thumbnail_url: null } });
        expect(el.querySelector('.status-quote-author').textContent).toContain('You · Status reaction');
        expect(el.querySelector('.status-quote-text').textContent).toBe('New job!');
        expect(el.querySelector('.status-quote-thumb.is-bg-violet')).not.toBeNull();

        el.innerHTML = T.messageBubble({ id: 6, sender_id: 1, is_mine: true, type: 'text', body: 'Wow', status: 'sent', created_at: minutesAgo(1), reactions: [], status_quote: { id: 8, owner_id: 2, type: 'image', text: null, available: false, thumbnail_url: null } });
        expect(el.querySelector('.status-quote.is-expired .status-quote-author').textContent).toContain('Ayesha · Status');
        expect(el.querySelector('.status-quote-text').textContent).toBe('Photo');
    });
});

describe('Phase 5 status view', () => {
    it('lists my status and the updates in recent, viewed and muted parts, with a dot for new ones', async () => {
        const { statuses, chat } = setup();
        await statuses.open();

        expect(document.querySelector('[data-sidebar]').dataset.mode).toBe('status');
        expect(document.querySelector('[data-status-mine] .status-row-meta').textContent).toContain('1 update');
        const titles = [...document.querySelectorAll('.sidebar-section-title')].map((el) => el.textContent);
        expect(titles).toEqual(['Recent updates', 'Viewed updates', 'Muted updates (1)', 'Channels']);
        document.querySelector('[data-status-channels]').click();
        expect(chat.channels.open).toHaveBeenCalled();
        expect(document.querySelector('[data-status-dot]').hidden).toBe(false);
        expect(document.querySelector('.status-privacy-row').textContent).toContain('My contacts');
    });

    it('plays from the first unseen update, marks it seen and moves on to the next person', async () => {
        const { statuses, chat } = setup();
        await statuses.open();

        document.querySelector('[data-status-user="2"]').click();
        expect(document.querySelector('.status-text p').textContent).toBe('Update 11');
        expect(chat.api.viewStatus).toHaveBeenCalledWith(11);
        expect(document.querySelectorAll('.status-progress-bar')).toHaveLength(2);

        statuses.next();
        // Ayesha was the only recent one: the viewer closes and she moves to "Viewed updates".
        expect(document.querySelector('.status-viewer')).toBeNull();
        expect(document.querySelector('[data-status-dot]').hidden).toBe(true);
        expect([...document.querySelectorAll('.sidebar-section-title')].map((el) => el.textContent)).toEqual(['Viewed updates', 'Muted updates (1)', 'Channels']);
    });

    it('replies and reacts to an update', async () => {
        const { statuses, chat } = setup();
        await statuses.open();
        statuses.openUser(3);

        const input = document.querySelector('[data-viewer-input]');
        input.value = 'Nice!';
        document.querySelector('[data-viewer-reply]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(chat.onOwnMessageStored).toHaveBeenCalledWith(expect.objectContaining({ id: 99 })));
        expect(chat.api.replyStatus).toHaveBeenCalledWith(20, 'Nice!');

        document.querySelector('[data-viewer-react="🔥"]').click();
        await vi.waitFor(() => expect(chat.api.reactStatus).toHaveBeenCalledWith(20, '🔥'));

        document.querySelector('[data-viewer-mute]').click();
        await vi.waitFor(() => expect(chat.api.muteStatus).toHaveBeenCalledWith(3));
    });

    it('shows who saw my update and deletes it', async () => {
        const { statuses, chat } = setup();
        await statuses.open();
        document.querySelector('[data-status-mine]').click();

        expect(document.querySelector('[data-viewer-count]').textContent).toBe('2');
        document.querySelector('[data-viewer-viewers]').click();
        await vi.waitFor(() => expect(document.querySelectorAll('.status-viewer-row')).toHaveLength(2));
        expect(document.querySelector('.status-viewer-reaction').textContent).toBe('😍');
        statuses.closeViewers();

        statuses.onViewed({ status_id: 1, views_count: 3 });
        expect(document.querySelector('[data-viewer-count]').textContent).toBe('3');

        const removing = statuses.remove(statuses.feed.mine[0]);
        await vi.waitFor(() => expect(document.querySelector('[data-modal-value="delete"]')).not.toBeNull());
        document.querySelector('[data-modal-value="delete"]').click();
        await removing;
        expect(chat.api.deleteStatus).toHaveBeenCalledWith(1);
        expect(document.querySelector('.status-viewer')).toBeNull();
        expect(document.querySelector('[data-status-new-text] .status-row-meta')?.textContent).toBe('Tap to add a status update');
    });

    it('posts a text status with the chosen colour and font', async () => {
        const { statuses, chat } = setup();
        await statuses.open();
        statuses.textComposer();

        const composer = document.querySelector('.status-composer');
        const firstBackground = [...composer.classList].find((c) => c.startsWith('is-bg-'));
        composer.querySelector('[data-composer-color]').click();
        composer.querySelector('[data-composer-font]').click();
        expect(composer.classList.contains('is-font-1')).toBe(true);
        expect(composer.classList.contains(firstBackground)).toBe(false);

        const textarea = composer.querySelector('[data-composer-text]');
        textarea.value = 'Off to Murree ❄️';
        textarea.dispatchEvent(new Event('input'));
        composer.querySelector('[data-composer-send]').click();

        await vi.waitFor(() => expect(document.querySelector('.status-composer')).toBeNull());
        expect(chat.api.createStatus).toHaveBeenCalledWith(expect.objectContaining({ text: 'Off to Murree ❄️', font: 1 }));
        expect(document.querySelector('[data-status-mine] .status-row-meta').textContent).toContain('2 updates');
    });

    it('saves status privacy', async () => {
        const { statuses, chat } = setup();
        await statuses.privacyDialog();

        expect(document.querySelector('.status-privacy-option small')).not.toBeNull();
        expect(document.querySelectorAll('.status-privacy-option')[1].textContent).toContain('1 excluded');
        const except = document.querySelector('input[value="except"]');
        except.checked = true;
        except.dispatchEvent(new Event('change', { bubbles: true }));
        document.querySelector('.status-privacy').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(chat.api.updateStatusPrivacy).toHaveBeenCalledWith({ mode: 'except', except_ids: [3], only_ids: [] }));
        expect(statuses.feed.privacy).toBe('except');
    });
});
