// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import axios from '../../bootstrap';
import { backupText, initChatBackup } from '../backup';

const routes = { backupShow: '/settings/backups', backupStore: '/settings/backups' };

function mount(current = null, available = true) {
    document.body.innerHTML = `
        <div data-chat-backup data-backup-available="${available ? '1' : '0'}" data-backup-current='${JSON.stringify(current)}'>
            <span data-backup-text></span>
            <a href="#" data-backup-download hidden>Download</a>
            <input type="checkbox" data-backup-media>
            <button type="button" data-backup-start>Back up now</button>
        </div>`;
    return initChatBackup({ routes }, undefined, { pollMs: 5 });
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('D8 chat backup', () => {
    it('describes each state', () => {
        expect(backupText(null)).toBe('No backup yet');
        expect(backupText({ status: 'working' })).toContain('Making your backup');
        expect(backupText({ status: 'failed', error: 'Disk full' })).toBe("The backup didn't finish: Disk full Try again.");
        const ready = backupText({ status: 'ready', finished_at: '2026-09-15T10:00:00Z', size: 2048000, include_media: true, stats: { chats: 3 }, expires_at: '2026-09-22T10:00:00Z' });
        expect(ready).toContain('2.0 MB · with media · 3 chats · download until');
    });

    it('starts a backup, polls until ready and shows the download', async () => {
        const manager = mount();
        expect(document.querySelector('[data-backup-text]').textContent).toBe('No backup yet');
        expect(document.querySelector('[data-backup-download]').hidden).toBe(true);

        axios.post.mockResolvedValue({ data: { backup: { id: 4, status: 'pending', include_media: true } } });
        axios.get.mockResolvedValue({ data: { backup: { id: 4, status: 'ready', include_media: true, size: 1024, finished_at: '2026-09-15T10:00:00Z', download_url: '/settings/backups/4/download' } } });

        document.querySelector('[data-backup-media]').checked = true;
        document.querySelector('[data-backup-start]').click();
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalledWith(routes.backupStore, { media: true }));
        await vi.waitFor(() => expect(document.querySelector('[data-backup-text]').textContent).toContain('Making your backup'));
        expect(document.querySelector('[data-backup-start]').disabled).toBe(true);

        await vi.waitFor(() => expect(document.querySelector('[data-backup-download]').hidden).toBe(false));
        expect(document.querySelector('[data-backup-download]').getAttribute('href')).toBe('/settings/backups/4/download');
        expect(document.querySelector('[data-backup-start]').disabled).toBe(false);
        expect(manager.backup.status).toBe('ready');
        manager.stop();
    });

    it('says when backups are not available', () => {
        mount(null, false);
        expect(document.querySelector('[data-backup-text]').textContent).toBe('Backups are not available on this server.');
        expect(document.querySelector('[data-backup-start]').disabled).toBe(true);
    });
});
