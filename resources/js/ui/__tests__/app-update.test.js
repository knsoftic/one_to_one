// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { interceptors: { response: { use: vi.fn(), eject: vi.fn() } } } }));

import { checkAndroidUpdate, initWebUpdateNotice, updateKind } from '../app-update';

const update = { latest_code: 5, latest_name: '1.4', min_code: 3, notes: 'Faster photos', url: 'https://chat.example/download/android' };

beforeEach(() => localStorage.clear());
afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('X4 update prompts', () => {
    it('tells optional and required updates apart', () => {
        expect(updateKind(update, 5)).toBe('none');
        expect(updateKind(update, 4)).toBe('optional');
        expect(updateKind(update, 2)).toBe('required');
        expect(updateKind({ ...update, min_code: null }, 1)).toBe('optional');
        expect(updateKind(update, 0)).toBe('none');
        expect(updateKind(null, 1)).toBe('none');
    });

    it('offers an optional update once per version and opens the download', async () => {
        const NativeApp = { getInfo: vi.fn(async () => ({ version: '1.3', build: 4 })), openExternal: vi.fn(async () => {}) };
        const config = { name: 'One2One Chat', appUpdate: { android: update } };

        expect(await checkAndroidUpdate(config, NativeApp)).toBe('optional');
        expect(document.querySelector('.app-update-dialog .modal-title').textContent).toBe('Update available');
        expect(document.querySelector('.modal-text').textContent).toContain('(you have 1.3)');
        expect(document.querySelector('.app-update-notes').textContent).toContain('Faster photos');

        document.querySelector('[data-app-update-install]').click();
        expect(NativeApp.openExternal).toHaveBeenCalledWith({ url: update.url });

        document.querySelector('button[data-app-update-later]').click();
        expect(document.querySelector('.app-update-dialog')).toBeNull();
        expect(await checkAndroidUpdate(config, NativeApp)).toBe('skipped');
    });

    it('a required update cannot be closed', async () => {
        const NativeApp = { getInfo: vi.fn(async () => ({ build: 2 })), openExternal: vi.fn(async () => { throw new Error('old app'); }) };
        expect(await checkAndroidUpdate({ appUpdate: { android: update } }, NativeApp)).toBe('required');

        const dialog = document.querySelector('.app-update-dialog.is-required');
        expect(dialog.querySelector('[data-app-update-later]')).toBeNull();
        dialog.querySelector('.modal-backdrop').click();
        expect(document.querySelector('.app-update-dialog')).not.toBeNull();
    });

    it('offers a reload when a new web version is live', () => {
        const handlers = [];
        const client = { interceptors: { response: { use: (fn) => handlers.push(fn), eject: vi.fn() } } };
        initWebUpdateNotice({ version: 'aaa111', name: 'One2One Chat' }, client);

        handlers[0]({ headers: { 'x-app-version': 'aaa111' } });
        expect(document.querySelector('.app-update-notice')).toBeNull();

        handlers[0]({ headers: { 'x-app-version': 'bbb222' } });
        handlers[0]({ headers: { 'x-app-version': 'bbb222' } });
        expect(document.querySelectorAll('.app-update-notice')).toHaveLength(1);
        expect(document.querySelector('.app-update-text').textContent).toBe('A new version of One2One Chat is ready.');

        document.querySelector('[data-app-update-close]').click();
        expect(document.querySelector('.app-update-notice')).toBeNull();
        expect(initWebUpdateNotice({ version: 'dev' }, client)).toBeNull();
    });
});
