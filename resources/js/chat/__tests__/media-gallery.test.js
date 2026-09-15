// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import { MediaGallery, monthLabel } from '../media-gallery';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const now = new Date().toISOString();
const photo = (id, extra = {}) => ({
    id,
    type: 'image',
    body: null,
    created_at: now,
    attachment: { url: `/messages/${id}/attachment`, download_url: `/messages/${id}/attachment?download=1`, thumbnail_url: null, name: `photo-${id}.jpg`, mime: 'image/jpeg', size: 2048 },
    ...extra,
});

function setup(responses) {
    const conversation = { id: 5, type: 'direct', participant: { id: 2, name: 'Ayesha' } };
    const gallery = vi.fn(async (id, kind, before) => responses[`${kind}:${before ?? ''}`]);
    const chat = {
        api: { has: (name) => name === 'conversationGallery', gallery },
        active: { id: 5, loaded: true },
        conversations: new Map([[5, conversation]]),
        participantOf: (c) => c.participant,
        openConversation: vi.fn(),
        jumpToMessage: vi.fn(),
    };
    return { chat, gallery, media: new MediaGallery(chat) };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('D1 media, links and docs', () => {
    it('adds a chat menu item and opens on the media tab with counts', async () => {
        const video = photo(9, { type: 'video', attachment: { ...photo(9).attachment, name: 'clip.mp4', mime: 'video/mp4', duration: 75, thumbnail_url: '/thumb/9' } });
        const { chat, gallery } = setup({
            'media:': { data: [video, photo(8, { attachment: { ...photo(8).attachment, mime: 'image/gif' } })], has_more: false, counts: { media: 2, docs: 1, links: 0 } },
        });

        const items = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation: chat.conversations.get(5), items } }));
        expect(items.join('')).toContain('data-action="open-media"');

        const locked = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation: { id: 6, is_locked_out: true }, items: locked } }));
        expect(locked).toHaveLength(0);

        document.dispatchEvent(new CustomEvent('chat:action', { detail: { action: 'open-media' } }));
        await vi.waitFor(() => expect(document.querySelectorAll('.gallery-tile')).toHaveLength(2));

        expect(gallery).toHaveBeenCalledWith(5, 'media', null);
        expect(document.querySelector('.gallery-chat-name').textContent).toBe('Ayesha');
        expect(document.querySelector('.gallery-group-label').textContent).toBe('This month');
        expect(document.querySelector('.gallery-tile.is-video .gallery-tile-badge').textContent).toBe('1:15');
        expect(document.querySelector('.gallery-tile.is-video img').getAttribute('src')).toBe('/thumb/9');
        expect(document.querySelectorAll('.gallery-tile')[1].textContent).toContain('GIF');
        expect(document.querySelector('[data-gallery-count="media"]').textContent).toBe('2');
        expect(document.querySelector('[data-gallery-count="links"]').textContent).toBe('');
        expect(document.querySelector('[data-gallery-tab="media"]').getAttribute('aria-selected')).toBe('true');
    });

    it('opens the viewer with Show in chat, and lists docs and links', async () => {
        const { chat, gallery, media } = setup({
            'media:': { data: [photo(8)], has_more: false, counts: { media: 1, docs: 1, links: 1 } },
            'docs:': { data: [{ id: 7, type: 'document', created_at: now, attachment: { name: 'Plan <b>.pdf', size: 1048576, download_url: '/messages/7/attachment?download=1' } }], has_more: false },
            'links:': {
                data: [{ id: 6, type: 'text', created_at: now, body: 'x', links: ['https://example.com/a', 'https://laravel.com'], link_preview: { url: 'https://example.com/a', title: 'Example page', image_url: '/lp/1' } }],
                has_more: false,
            },
        });

        media.open(5);
        await vi.waitFor(() => expect(document.querySelector('.gallery-tile')).not.toBeNull());

        document.querySelector('.gallery-tile').click();
        const lightbox = document.querySelector('.lightbox');
        expect(lightbox.querySelector('.lightbox-image').getAttribute('src')).toBe('/messages/8/attachment');
        lightbox.querySelector('[data-lightbox-jump]').click();
        await vi.waitFor(() => expect(chat.jumpToMessage).toHaveBeenCalledWith(8, { deep: true }));
        expect(document.querySelector('.media-gallery')).toBeNull();

        media.open(5);
        await vi.waitFor(() => expect(document.querySelector('.gallery-tile')).not.toBeNull());
        document.querySelector('[data-gallery-tab="docs"]').click();
        await vi.waitFor(() => expect(document.querySelector('.gallery-row')).not.toBeNull());
        expect(document.querySelector('.gallery-row-title').textContent).toBe('Plan <b>.pdf');
        expect(document.querySelector('.gallery-row-meta').textContent).toContain('PDF · 1.0 MB');
        expect(document.querySelector('.gallery-row-main').getAttribute('href')).toBe('/messages/7/attachment?download=1');

        document.querySelector('[data-gallery-tab="links"]').click();
        await vi.waitFor(() => expect(document.querySelector('.gallery-row.is-link')).not.toBeNull());
        const row = document.querySelector('.gallery-row.is-link');
        expect(row.querySelector('.gallery-row-title').textContent).toBe('Example page');
        expect(row.querySelector('.gallery-row-main').getAttribute('rel')).toBe('noopener noreferrer nofollow');
        expect(row.querySelector('.gallery-link-thumb img').getAttribute('src')).toBe('/lp/1');
        expect([...row.querySelectorAll('.gallery-more-links a')].map((a) => a.getAttribute('href'))).toEqual(['https://laravel.com']);

        // Tabs that were loaded once aren't fetched again.
        document.querySelector('[data-gallery-tab="docs"]').click();
        expect(gallery.mock.calls.filter(([, kind]) => kind === 'docs')).toHaveLength(1);

        document.querySelector('[data-gallery-jump="7"]').click();
        await vi.waitFor(() => expect(chat.jumpToMessage).toHaveBeenCalledWith(7, { deep: true }));
    });

    it('loads older items, shows empty and error states and closes with Escape', async () => {
        const { chat, gallery, media } = setup({
            'media:': { data: [photo(20)], has_more: true, counts: { media: 2, docs: 0, links: 0 } },
            'media:20': { data: [photo(19)], has_more: false, counts: null },
            'docs:': { data: [], has_more: false },
        });
        chat.api.gallery = vi.fn(async (id, kind, before) => {
            if (kind === 'links') throw new Error('offline');
            return gallery(id, kind, before);
        });

        media.open(5);
        await vi.waitFor(() => expect(document.querySelector('[data-gallery-more]')).not.toBeNull());
        document.querySelector('[data-gallery-more]').click();
        await vi.waitFor(() => expect(document.querySelectorAll('.gallery-tile')).toHaveLength(2));
        expect(document.querySelector('[data-gallery-more]')).toBeNull();
        expect(document.querySelector('[data-gallery-count="media"]').textContent).toBe('2');

        document.querySelector('[data-gallery-tab="docs"]').click();
        await vi.waitFor(() => expect(document.querySelector('.empty-state-title')?.textContent).toBe('No documents'));

        document.querySelector('[data-gallery-tab="links"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-gallery-retry]')).not.toBeNull());
        expect(document.querySelector('.empty-state-title').textContent).toBe("Couldn't load this list");

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(document.querySelector('.media-gallery')).toBeNull();

        media.open(5);
        document.dispatchEvent(new CustomEvent('chat:closed'));
        expect(media.isOpen).toBe(false);
    });

    it('labels months', () => {
        const today = new Date(2026, 8, 15);
        expect(monthLabel(new Date(2026, 8, 2), today)).toBe('This month');
        expect(monthLabel(new Date(2026, 7, 30), today)).toMatch(/August/);
        expect(monthLabel(new Date(2025, 11, 1), today)).toMatch(/December.*2025/);
    });
});
