// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import axios from '../../bootstrap';
import { AutoDownload, downloadKind, networkType } from '../auto-download';
import * as T from '../templates';
import { initSettings } from '../../ui/settings';

const photo = (extra = {}) => ({
    id: 7,
    type: 'image',
    sender_id: 2,
    is_mine: false,
    created_at: new Date().toISOString(),
    reactions: [],
    attachment: { url: '/messages/7/attachment', thumbnail_url: '/thumb/7', name: 'a.jpg', mime: 'image/jpeg', size: 1536000, width: 800, height: 600 },
    ...extra,
});

afterEach(() => {
    document.body.innerHTML = '';
    T.setTemplateContext({ meId: 1, nameOf: () => '' });
    vi.clearAllMocks();
});

describe('D5 auto-download', () => {
    it('knows the network and the kind of media', () => {
        expect(networkType({})).toBe('wifi');
        expect(networkType({ connection: { type: 'wifi' } })).toBe('wifi');
        expect(networkType({ connection: { type: 'cellular' } })).toBe('mobile');
        expect(networkType({ connection: { type: 'wifi', saveData: true } })).toBe('mobile');

        expect(downloadKind(photo())).toBe('photos');
        expect(downloadKind(photo({ attachment: { mime: 'image/gif' } }))).toBe('gifs');
        expect(downloadKind({ type: 'sticker', attachment: {} })).toBe('gifs');
        expect(downloadKind({ type: 'video', attachment: {} })).toBe('videos');
        expect(downloadKind({ type: 'voice', attachment: {} })).toBeNull();
        expect(downloadKind({ type: 'text' })).toBeNull();
    });

    it('follows the choices for the current network', () => {
        const nav = { connection: { type: 'cellular' } };
        const prefs = { wifi: ['photos', 'gifs', 'videos'], mobile: ['gifs'] };
        const auto = new AutoDownload(() => prefs, nav);

        expect(auto.allows(photo())).toBe(false);
        expect(auto.allows(photo({ is_mine: true }))).toBe(true);
        expect(auto.allows(photo({ attachment: { mime: 'image/gif' } }))).toBe(true);
        expect(auto.allows({ type: 'voice', attachment: {} })).toBe(true);

        auto.markLoaded(7);
        expect(auto.allows(photo())).toBe(true);

        nav.connection.type = 'wifi';
        expect(auto.allows(photo({ id: 8 }))).toBe(true);
        // Without saved choices the defaults apply (photos on mobile data).
        nav.connection.type = 'cellular';
        expect(new AutoDownload(() => undefined, nav).allows(photo({ id: 9 }))).toBe(true);
    });

    it('shows the size and a download button instead of the picture', () => {
        T.setTemplateContext({ meId: 1, nameOf: () => 'Ayesha', autoDownload: () => false });

        const held = T.messageBubble(photo());
        const box = document.createElement('div');
        box.innerHTML = held;
        const button = box.querySelector('[data-media-load]');
        expect(button.dataset.mediaLoad).toBe('7');
        expect(button.getAttribute('aria-label')).toBe('Download photo, 1.5 MB');
        expect(box.querySelector('img')).toBeNull();

        box.innerHTML = T.messageBubble({ ...photo(), id: 8, type: 'video', attachment: { url: '/v', thumbnail_url: '/poster', size: 4096000, duration: 12 } });
        expect(box.querySelector('.message-video img')).toBeNull();
        expect(box.querySelector('[data-video]')).not.toBeNull();
        expect(box.querySelector('.message-video-info').textContent).toContain('3.9 MB');

        T.setTemplateContext({ meId: 1, nameOf: () => 'Ayesha', autoDownload: () => true });
        box.innerHTML = T.messageBubble(photo());
        expect(box.querySelector('img').getAttribute('src')).toBe('/thumb/7');
    });

    it('settings save the ticked kinds per network', async () => {
        document.body.innerHTML = `
            <div data-settings-tabs>
                <fieldset data-auto-download-group="mobile">
                    <input type="checkbox" data-auto-download="mobile" value="photos" checked>
                    <input type="checkbox" data-auto-download="mobile" value="gifs" checked>
                    <input type="checkbox" data-auto-download="mobile" value="videos">
                </fieldset>
            </div>`;
        const config = { routes: { preferences: '/settings/preferences' }, user: { auto_download: { wifi: ['photos'], mobile: ['photos', 'gifs'] } } };
        axios.patch.mockResolvedValueOnce({ data: { preferences: { auto_download: { wifi: ['photos'], mobile: ['gifs'] } } } });
        initSettings(config);

        const photos = document.querySelector('input[value="photos"]');
        photos.checked = false;
        photos.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.waitFor(() => expect(config.user.auto_download.mobile).toEqual(['gifs']));
        expect(axios.patch).toHaveBeenCalledWith('/settings/preferences', { auto_download: { mobile: ['gifs'] } });

        // A failed save puts the ticks back.
        axios.patch.mockRejectedValueOnce(new Error('offline'));
        const videos = document.querySelector('input[value="videos"]');
        videos.checked = true;
        videos.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.waitFor(() => expect(videos.checked).toBe(false));
    });
});
