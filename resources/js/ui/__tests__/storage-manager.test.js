// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));
vi.mock('../../lib/modal', () => ({ confirmDialog: vi.fn(async () => 'delete') }));

import axios from '../../bootstrap';
import { confirmDialog } from '../../lib/modal';
import { initStorageManager } from '../storage-manager';

const routes = { storageSummary: '/settings/storage', storageFiles: '/settings/storage/files', storageDelete: '/settings/storage/delete' };

const summary = {
    total: { bytes: 9_470_000, files: 5 },
    kinds: { photos: { bytes: 2_000_000, files: 1 }, videos: { bytes: 7_000_000, files: 1 }, documents: { bytes: 300_000, files: 1 }, voice: { bytes: 120_000, files: 1 }, gifs: { bytes: 50_000, files: 1 } },
    large: { bytes: 7_000_000, files: 1 },
    chats: [{ id: 3, type: 'direct', name: 'Ayesha <b>', initials: 'AK', avatar_hue: 10, bytes: 9_350_000, files: 4 }],
};

const file = (id, size, extra = {}) => ({ id, conversation_id: 3, kind: 'documents', type: 'document', name: `File ${id}.pdf`, size, is_mine: false, created_at: '2026-09-10T10:00:00Z', thumbnail_url: null, url: `/m/${id}`, download_url: `/m/${id}?download=1`, ...extra });

function mount({ active = true } = {}) {
    document.body.innerHTML = `
        <section data-settings-section="storage" class="${active ? 'is-active' : ''}">
            <div data-storage-manager><div data-storage-body></div></div>
        </section>`;
    return initStorageManager({ routes });
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('D6 manage storage', () => {
    it('waits until Storage and data is opened, then shows usage and chats', async () => {
        axios.get.mockResolvedValue({ data: summary });
        mount({ active: false });
        expect(axios.get).not.toHaveBeenCalled();

        document.dispatchEvent(new CustomEvent('settings:section', { detail: { name: 'storage' } }));
        await vi.waitFor(() => expect(document.querySelector('.storage-total')).not.toBeNull());

        expect(document.querySelector('.storage-total').textContent).toContain('9.0 MB');
        expect(document.querySelector('.storage-total').textContent).toContain('5 files');
        expect(document.querySelectorAll('.storage-bar-part')).toHaveLength(5);
        expect(document.querySelector('[data-storage-large] .wa-row-text').textContent).toBe('1 file · 6.7 MB');
        expect(document.querySelector('[data-storage-chat="3"] .wa-row-title').textContent).toBe('Ayesha <b>');
    });

    it('lists a chat’s files, selects them and deletes them for me', async () => {
        axios.get.mockImplementation(async (url) => (url === routes.storageSummary
            ? { data: summary }
            : { data: { data: [file(11, 3_000_000), file(12, 1_000_000, { kind: 'photos', thumbnail_url: '/t/12' })], has_more: false } }));
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-storage-chat="3"]')).not.toBeNull());

        document.querySelector('[data-storage-chat="3"]').click();
        await vi.waitFor(() => expect(document.querySelectorAll('.storage-file')).toHaveLength(2));
        expect(axios.get).toHaveBeenLastCalledWith(routes.storageFiles, { params: { sort: 'size', page: 1, conversation: 3 } });
        expect(document.querySelector('.storage-panel .group-info-title').textContent).toBe('Ayesha <b>');
        expect(document.querySelector('.storage-file-thumb img').getAttribute('src')).toBe('/t/12');

        document.querySelector('[data-files-select-all]').click();
        expect(document.querySelector('[data-files-footer]').hidden).toBe(false);
        expect(document.querySelector('[data-files-selection]').textContent).toBe('2 selected · 3.8 MB');

        const box = document.querySelector('[data-file-select][value="12"]');
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.querySelector('[data-files-selection]').textContent).toBe('1 selected · 2.9 MB');

        axios.post.mockResolvedValue({ data: { deleted: 1, bytes: 3_000_000 } });
        document.querySelector('[data-files-delete]').click();
        await vi.waitFor(() => expect(document.querySelectorAll('.storage-file')).toHaveLength(1));
        expect(confirmDialog).toHaveBeenCalled();
        expect(axios.post).toHaveBeenCalledWith(routes.storageDelete, { message_ids: [11] });
        expect(document.querySelector('[data-files-footer]').hidden).toBe(true);

        document.querySelector('[data-files-sort="newest"]').click();
        await vi.waitFor(() => expect(axios.get).toHaveBeenLastCalledWith(routes.storageFiles, { params: { sort: 'newest', page: 1, conversation: 3 } }));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(document.querySelector('.storage-panel')).toBeNull();
    });

    it('shows an empty state and a retry after errors', async () => {
        axios.get.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: { ...summary, total: { bytes: 0, files: 0 } } });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-storage-retry]')).not.toBeNull());
        document.querySelector('[data-storage-retry]').click();
        await vi.waitFor(() => expect(document.querySelector('.storage-empty')).not.toBeNull());
    });
});
