// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import axios from '../../bootstrap';
import { ChatWallpaper } from '../chat-wallpaper';
import { applyWallpaper, openWallpaperPicker, wallpaperLabel } from '../../ui/wallpaper';
import { initSettings, initWallpaperChoice } from '../../ui/settings';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:photo');
    URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
    document.body.innerHTML = '';
    delete document.documentElement.dataset.fontSize;
    vi.clearAllMocks();
});

describe('D2 wallpaper', () => {
    it('paints presets and photos', () => {
        const el = document.createElement('div');
        applyWallpaper(el, { key: 'ocean' }, 30);
        expect(el.classList.contains('chat-wallpaper')).toBe(true);
        expect(el.dataset.wallpaper).toBe('ocean');
        expect(el.style.getPropertyValue('--wallpaper-dim')).toBe('0.3');

        applyWallpaper(el, { key: 'custom', url: '/settings/wallpaper?v=1"x' });
        expect(el.dataset.wallpaper).toBe('custom');
        expect(el.style.getPropertyValue('--wallpaper-photo')).toBe('url("/settings/wallpaper?v=1\\"x")');

        // Unknown keys and a photo without a URL fall back to the default.
        applyWallpaper(el, { key: 'custom', url: null });
        expect(el.dataset.wallpaper).toBe('default');
        applyWallpaper(el, { key: 'neon' });
        expect(el.dataset.wallpaper).toBe('default');
        expect(wallpaperLabel({ key: 'sunset' })).toBe('Sunset');
        expect(wallpaperLabel({ key: 'custom', url: '/x' })).toBe('Your photo');
    });

    it('picker previews choices, uploads a photo and sends dimming', async () => {
        const onSave = vi.fn(async () => {});
        openWallpaperPicker({ current: { key: 'mint' }, dim: 20, onSave });

        const preview = document.querySelector('[data-wallpaper-preview]');
        expect(preview.dataset.wallpaper).toBe('mint');
        expect(document.querySelector('input[value="custom"]').disabled).toBe(true);

        const sunset = document.querySelector('input[value="sunset"]');
        sunset.checked = true;
        sunset.dispatchEvent(new Event('change', { bubbles: true }));
        expect(preview.dataset.wallpaper).toBe('sunset');

        const file = new File(['x'], 'beach.jpg', { type: 'image/jpeg' });
        const input = document.querySelector('[data-wallpaper-file]');
        Object.defineProperty(input, 'files', { value: [file] });
        input.dispatchEvent(new Event('change'));
        expect(document.querySelector('input[value="custom"]').checked).toBe(true);
        expect(preview.dataset.wallpaper).toBe('custom');

        const range = document.querySelector('input[name="dim"]');
        range.value = '45';
        range.dispatchEvent(new Event('input'));
        expect(document.querySelector('[data-wallpaper-dim-value]').textContent).toBe('45%');

        document.querySelector('[data-wallpaper-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        const form = onSave.mock.calls[0][0];
        expect(form.get('wallpaper')).toBe('custom');
        expect(form.get('photo').name).toBe('beach.jpg');
        expect(form.get('dim')).toBe('45');
        expect(document.querySelector('.wallpaper-dialog')).toBeNull();
    });

    it('keeps the dialog open when saving fails', async () => {
        openWallpaperPicker({ current: null, onSave: vi.fn(async () => { throw new Error('nope'); }) });
        expect(document.querySelector('input[name="dim"]')).toBeNull();
        document.querySelector('[data-wallpaper-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        expect(document.querySelector('.wallpaper-dialog')).not.toBeNull();
        expect(document.querySelector('[data-wallpaper-save]').disabled).toBe(false);
    });

    it('chat uses its own wallpaper or the default, and saves from the menu', async () => {
        document.body.innerHTML = '<main class="chat-main"></main>';
        const conversation = { id: 5, type: 'direct', settings: { wallpaper: null } };
        const chat = {
            active: { id: 5 },
            api: { has: () => true, url: (name, id) => `/conversations/${id}/wallpaper` },
            activeConversation: () => conversation,
            upsertConversation: vi.fn((c) => c),
        };
        const wallpaper = new ChatWallpaper(chat, { user: { wallpaper: { key: 'custom', url: '/settings/wallpaper?v=2', dim: 40 } } });
        const main = document.querySelector('.chat-main');
        expect(main.dataset.wallpaper).toBe('custom');
        expect(main.style.getPropertyValue('--wallpaper-dim')).toBe('0.4');

        document.dispatchEvent(new CustomEvent('chat:header', { detail: { conversation: { ...conversation, settings: { wallpaper: { key: 'forest', url: null } } } } }));
        expect(main.dataset.wallpaper).toBe('forest');
        document.dispatchEvent(new CustomEvent('chat:closed'));
        expect(main.dataset.wallpaper).toBe('custom');

        const items = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation: { ...conversation, settings: { wallpaper: { key: 'rose' } } }, items } }));
        expect(items.join('')).toContain('Rose');

        axios.post.mockResolvedValue({ data: { id: 5, settings: { wallpaper: { key: 'sky', url: null } } } });
        wallpaper.open();
        document.querySelector('[data-wallpaper-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        await flush();
        expect(axios.post).toHaveBeenCalledWith('/conversations/5/wallpaper', expect.any(FormData));
        expect(chat.upsertConversation).toHaveBeenCalled();
        expect(main.dataset.wallpaper).toBe('sky');
    });
});

describe('Settings → Chats', () => {
    it('saves the default wallpaper and changes the font size right away', async () => {
        document.body.innerHTML = `
            <div data-settings-tabs>
                <button type="button" data-wallpaper-open data-wallpaper-current='{"key":"plain","url":null,"dim":10}'>
                    <span data-wallpaper-label></span><span data-wallpaper-thumb></span>
                </button>
                <label class="wa-choice"><span data-choice-label>Medium</span>
                    <select data-preference="font_size"><option value="small">Small</option><option value="medium" selected>Medium</option><option value="large">Large</option></select>
                </label>
            </div>`;
        const config = { routes: { preferences: '/settings/preferences', wallpaper: '/settings/wallpaper' }, user: {} };
        initSettings(config);

        expect(document.querySelector('[data-wallpaper-label]').textContent).toBe('Plain');
        expect(document.querySelector('[data-wallpaper-thumb]').dataset.wallpaper).toBe('plain');

        axios.patch.mockResolvedValue({ data: {} });
        const select = document.querySelector('[data-preference="font_size"]');
        select.value = 'large';
        select.dispatchEvent(new Event('change'));
        expect(document.documentElement.dataset.fontSize).toBe('large');
        expect(document.querySelector('[data-choice-label]').textContent).toBe('Large');
        expect(axios.patch).toHaveBeenCalledWith('/settings/preferences', { font_size: 'large' });

        axios.post.mockResolvedValue({ data: { wallpaper: { key: 'aurora', url: null, dim: 10 } } });
        document.querySelector('[data-wallpaper-open]').click();
        expect(document.querySelector('input[name="dim"]').value).toBe('10');
        document.querySelector('[data-wallpaper-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        await flush();
        expect(axios.post).toHaveBeenCalledWith('/settings/wallpaper', expect.any(FormData));
        expect(document.querySelector('[data-wallpaper-label]').textContent).toBe('Aurora');
        expect(config.user.wallpaper.key).toBe('aurora');
        expect(initWallpaperChoice).toBeTypeOf('function');
    });
});
