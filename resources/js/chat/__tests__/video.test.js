// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { attachmentPreview, messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

const stored = {
    id: 7,
    sender_id: 2,
    type: 'video',
    body: null,
    status: 'seen',
    created_at: '2026-09-13T10:00:00Z',
    attachment: {
        url: '/messages/7/attachment',
        download_url: '/messages/7/attachment?download=1',
        thumbnail_url: '/messages/7/attachment?variant=thumbnail',
        name: 'beach "trip".mp4',
        size: 1024,
        duration: 125,
        width: 480,
        height: 270,
    },
};

function render(markup) {
    const host = document.createElement('div');
    host.innerHTML = markup;
    return host;
}

describe('video messages', () => {
    it('shows the poster, duration and a playable button', () => {
        const bubble = render(messageBubble(stored)).querySelector('.message');
        const button = bubble.querySelector('.message-video');

        expect(bubble.classList.contains('is-media-only')).toBe(true);
        expect(button.dataset.video).toBe('/messages/7/attachment');
        expect(button.dataset.videoName).toBe('beach "trip".mp4');
        expect(button.style.aspectRatio.replace(/\s/g, '')).toBe('480/270');
        expect(button.querySelector('img').getAttribute('src')).toBe('/messages/7/attachment?variant=thumbnail');
        expect(button.querySelector('.message-video-info').textContent).toContain('2:05');
    });

    it('cannot be played while uploading and falls back without a poster', () => {
        const uploading = render(messageBubble({ ...stored, id: 'tmp-1', uploading: true, attachment: { ...stored.attachment, thumbnail_url: null, local_url: 'blob:x' } }));
        const button = uploading.querySelector('.message-video');

        expect(button.hasAttribute('disabled')).toBe(true);
        expect(button.dataset.video).toBeUndefined();
        expect(button.querySelector('.message-video-placeholder')).not.toBeNull();
    });

    it('has chat list and composer previews', () => {
        expect(previewOf(stored)).toBe('🎥 Video');
        expect(previewOf({ ...stored, body: 'Sea view' })).toBe('🎥 Sea view');
        expect(render(attachmentPreview({ type: 'video', name: 'a.mp4', size: 2048, url: 'blob:poster', duration: 42 })).textContent).toContain('Video · 0:42 · 2');
    });
});
