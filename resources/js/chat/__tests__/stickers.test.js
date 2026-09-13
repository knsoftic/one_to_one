// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { EMOJI_STICKERS, stickerPlacement } from '../stickers';
import { messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

describe('stickers and GIFs', () => {
    it('fills a square or fits the whole photo', () => {
        expect(stickerPlacement(1000, 500, 'fill')).toEqual({ x: -256, y: 0, w: 1024, h: 512 });
        expect(stickerPlacement(1000, 500, 'fit')).toEqual({ x: 0, y: 128, w: 512, h: 256 });
        expect(stickerPlacement(500, 500, 'fit', 512, 24)).toEqual({ x: 24, y: 24, w: 464, h: 464 });
        expect(new Set(EMOJI_STICKERS).size).toBe(EMOJI_STICKERS.length);
    });

    it('shows stickers without a bubble and GIFs with a badge', () => {
        const host = document.createElement('div');
        const base = { sender_id: 2, status: 'seen', created_at: '2026-09-13T10:00:00Z' };

        host.innerHTML = messageBubble({ ...base, id: 1, type: 'sticker', attachment: { url: '/messages/1/attachment', mime: 'image/webp' } });
        expect(host.querySelector('.message').classList.contains('is-media-only')).toBe(true);
        expect(host.querySelector('.message-sticker img').getAttribute('src')).toBe('/messages/1/attachment');

        const gif = { ...base, id: 2, type: 'image', attachment: { url: '/messages/2/attachment', mime: 'image/gif', animated: true } };
        host.innerHTML = messageBubble(gif);
        expect(host.querySelector('.message-image-hd.is-gif').textContent).toBe('GIF');
        expect(host.querySelector('.message-image img').getAttribute('src')).toBe('/messages/2/attachment');

        expect(previewOf(gif)).toBe('👾 GIF');
        expect(previewOf({ ...base, type: 'sticker' })).toBe('💟 Sticker');
    });
});
