// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { viewOnceState } from '../view-once';
import { attachmentPreview, messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

const base = { id: 8, type: 'image', status: 'seen', created_at: '2026-09-13T10:00:00Z', attachment: { url: null, name: 'p.jpg', view_once: true } };

describe('view once', () => {
    it('lets only the receiver open an unopened message', () => {
        expect(viewOnceState({ ...base, sender_id: 2, is_mine: false, view_once: { opened_at: null, available: true } })).toMatchObject({ canOpen: true, text: 'Photo' });
        expect(viewOnceState({ ...base, sender_id: 2, is_mine: false, view_once: { opened_at: '2026-09-13T10:05:00Z', available: false } })).toMatchObject({ canOpen: false, text: 'Opened' });
        expect(viewOnceState({ ...base, sender_id: 1, is_mine: true, view_once: { opened_at: null, available: true } })).toMatchObject({ canOpen: false, text: 'Photo' });
        expect(viewOnceState({ ...base, type: 'voice', sender_id: 1, is_mine: true, view_once: { opened_at: '2026-09-13T10:05:00Z' } }).text).toBe('Opened');
    });

    it('never renders the media, only an open button or a label', () => {
        const host = document.createElement('div');

        host.innerHTML = messageBubble({ ...base, sender_id: 2, is_mine: false, view_once: { opened_at: null, available: true } });
        expect(host.querySelector('img')).toBeNull();
        expect(host.querySelector('[data-view-once="8"]')).not.toBeNull();
        expect(host.querySelector('.message').classList.contains('is-media-only')).toBe(false);

        host.innerHTML = messageBubble({ ...base, sender_id: 1, is_mine: true, view_once: { opened_at: '2026-09-13T10:05:00Z', available: false } });
        expect(host.querySelector('[data-view-once]')).toBeNull();
        expect(host.querySelector('.view-once.is-opened').textContent).toContain('Opened');

        expect(previewOf({ ...base, type: 'video' })).toBe('🎥 View once video');
    });

    it('offers the "1" switch for photos and videos but not GIFs or files', () => {
        const host = document.createElement('div');
        host.innerHTML = attachmentPreview({ type: 'video', name: 'v.mp4', size: 10, url: 'blob:x', viewOnce: true });
        expect(host.querySelector('[data-view-once-toggle]').getAttribute('aria-pressed')).toBe('true');

        host.innerHTML = attachmentPreview({ type: 'image', name: 'a.gif', size: 10, url: 'blob:x' });
        expect(host.querySelector('[data-view-once-toggle]')).toBeNull();

        host.innerHTML = attachmentPreview({ type: 'document', name: 'a.pdf', size: 10 });
        expect(host.querySelector('[data-view-once-toggle]')).toBeNull();
    });
});
