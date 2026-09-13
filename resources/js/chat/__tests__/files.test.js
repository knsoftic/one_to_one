// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { fitSize, jpegName } from '../image-resize';
import { attachmentPreview, fileKind, messageBubble, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

describe('photo sizes', () => {
    it('fits the longest edge without enlarging', () => {
        expect(fitSize(4000, 3000, 1600)).toEqual({ width: 1600, height: 1200, scale: 0.4 });
        expect(fitSize(3000, 4000, 3072)).toEqual({ width: 2304, height: 3072, scale: 0.768 });
        expect(fitSize(800, 600, 1600)).toEqual({ width: 800, height: 600, scale: 1 });
        expect(jpegName('Screenshot 1.png')).toBe('Screenshot 1.jpg');
    });
});

describe('file types', () => {
    it('groups extensions for icons and colours', () => {
        expect(fileKind('xlsx')).toEqual({ kind: 'sheet', icon: 'file-spreadsheet' });
        expect(fileKind('pptx')).toEqual({ kind: 'slides', icon: 'presentation' });
        expect(fileKind('rar')).toEqual({ kind: 'archive', icon: 'file-archive' });
        expect(fileKind('mp3')).toEqual({ kind: 'audio', icon: 'file-music' });
        expect(fileKind('txt')).toEqual({ kind: 'text', icon: 'file-text' });
    });

    it('labels documents and marks HD photos', () => {
        const host = document.createElement('div');
        host.innerHTML = messageBubble({ id: 1, sender_id: 2, type: 'document', status: 'seen', created_at: '2026-09-13T10:00:00Z', attachment: { name: 'Budget.xlsx', size: 2048, download_url: '/d' } });
        expect(host.querySelector('.message-file-meta').textContent).toContain('Excel');
        expect(host.querySelector('.message-file-icon').dataset.kind).toBe('sheet');

        host.innerHTML = messageBubble({ id: 2, sender_id: 2, type: 'image', status: 'seen', created_at: '2026-09-13T10:00:00Z', attachment: { url: '/i', name: 'a.jpg', hd: true } });
        expect(host.querySelector('.message-image-hd')).not.toBeNull();

        host.innerHTML = attachmentPreview({ type: 'image', name: 'a.jpg', size: 10, url: 'blob:x', hd: true });
        expect(host.querySelector('[data-hd-toggle]').getAttribute('aria-pressed')).toBe('true');
    });
});
