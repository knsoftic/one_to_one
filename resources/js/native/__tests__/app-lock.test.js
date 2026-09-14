// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AppLock, HIDDEN_AT_KEY, SETTINGS_KEY, readSettings, shouldLock } from '../app-lock';

const memory = () => {
    const data = new Map();
    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        removeItem: (key) => data.delete(key),
    };
};

function setup({ available = true, unlocked = true, settings = null, hiddenAt = null } = {}) {
    const storage = memory();
    if (settings) storage.setItem(SETTINGS_KEY, JSON.stringify(settings));
    if (hiddenAt) storage.setItem(HIDDEN_AT_KEY, hiddenAt);
    let stateListener = null;
    const NativeApp = {
        getAppLockStatus: vi.fn(async () => ({ available, reason: available ? 'ready' : 'no_screen_lock' })),
        unlockApp: vi.fn(async () => ({ unlocked })),
    };
    const App = { addListener: vi.fn((name, fn) => { stateListener = fn; }) };
    const lock = new AppLock({ NativeApp, App, storage, appName: 'One2One' });
    return { lock, NativeApp, storage, fire: (isActive) => stateListener?.({ isActive }) };
}

afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.classList.remove('is-app-locked');
});

describe('P8 app lock', () => {
    it('reads settings safely and decides when to lock', () => {
        const storage = memory();
        expect(readSettings(storage)).toEqual({ enabled: false, timeout: 0 });
        storage.setItem(SETTINGS_KEY, '{bad json');
        expect(readSettings(storage)).toEqual({ enabled: false, timeout: 0 });
        storage.setItem(SETTINGS_KEY, JSON.stringify({ enabled: true, timeout: 999 }));
        expect(readSettings(storage)).toEqual({ enabled: true, timeout: 0 });

        const now = 1_000_000;
        expect(shouldLock({ enabled: false, timeout: 0 }, 0, now)).toBe(false);
        expect(shouldLock({ enabled: true, timeout: 60 }, 0, now)).toBe(true);
        expect(shouldLock({ enabled: true, timeout: 60 }, now - 30_000, now)).toBe(false);
        expect(shouldLock({ enabled: true, timeout: 60 }, now - 61_000, now)).toBe(true);
    });

    it('locks when the app opens and when it comes back, and unlocks with the phone', async () => {
        const { lock, NativeApp, storage, fire } = setup({ settings: { enabled: true, timeout: 0 } });
        await lock.init();

        await vi.waitFor(() => expect(NativeApp.unlockApp).toHaveBeenCalled());
        await vi.waitFor(() => expect(document.querySelector('.app-lock')).toBeNull());

        fire(false);
        expect(storage.getItem(HIDDEN_AT_KEY)).not.toBeNull();
        NativeApp.unlockApp.mockResolvedValueOnce({ unlocked: false, error: 'Cancelled' });
        fire(true);
        await vi.waitFor(() => expect(NativeApp.unlockApp).toHaveBeenCalledTimes(2));
        expect(document.querySelector('.app-lock .app-lock-title').textContent).toBe('One2One is locked');

        document.querySelector('[data-app-unlock]').click();
        await vi.waitFor(() => expect(document.querySelector('.app-lock')).toBeNull());
    });

    it('does nothing when off, signed out or not supported', async () => {
        const off = setup({ settings: { enabled: false, timeout: 0 } });
        await off.lock.init();
        expect(document.querySelector('.app-lock')).toBeNull();

        const signedOut = setup({ settings: { enabled: true, timeout: 0 } });
        await signedOut.lock.init({ signedIn: false });
        expect(document.querySelector('.app-lock')).toBeNull();

        document.body.innerHTML = `<div data-app-lock-row hidden><p data-app-lock-text></p><select data-app-lock-timeout><option value="0">Now</option><option value="60">1 min</option></select><input type="checkbox" data-app-lock-toggle></div>`;
        const unsupported = setup({ available: false, settings: { enabled: true, timeout: 0 } });
        await unsupported.lock.init();
        expect(document.querySelector('.app-lock')).toBeNull();
        expect(document.querySelector('[data-app-lock-row]').hidden).toBe(false);
        expect(document.querySelector('[data-app-lock-toggle]').disabled).toBe(true);
        expect(document.querySelector('[data-app-lock-text]').textContent).toContain('Set a screen lock');
    });

    it('turning it on from settings asks for the fingerprint first', async () => {
        document.body.innerHTML = `<div data-app-lock-row hidden><p data-app-lock-text></p><select data-app-lock-timeout><option value="0">Now</option><option value="60">1 min</option></select><input type="checkbox" data-app-lock-toggle></div>`;
        const { lock, NativeApp, storage } = setup({ unlocked: false });
        await lock.init();

        const toggle = document.querySelector('[data-app-lock-toggle]');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toggle.checked).toBe(false));
        expect(readSettings(storage).enabled).toBe(false);

        NativeApp.unlockApp.mockResolvedValue({ unlocked: true });
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(readSettings(storage).enabled).toBe(true));

        const timeout = document.querySelector('[data-app-lock-timeout]');
        expect(timeout.disabled).toBe(false);
        timeout.value = '60';
        timeout.dispatchEvent(new Event('change'));
        expect(readSettings(storage)).toEqual({ enabled: true, timeout: 60 });
    });
});
