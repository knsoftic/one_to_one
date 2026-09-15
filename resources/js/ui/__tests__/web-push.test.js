// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), delete: vi.fn(async () => ({ data: {} })), get: vi.fn(), patch: vi.fn() } }));

import axios from '../../bootstrap';
import { disablePush, enablePush, pushStatus, syncPush, urlBase64ToUint8Array } from '../../lib/web-push';
import { initBrowserNotificationButton } from '../settings';

const KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';
const config = { user: { id: 7 }, webPush: { publicKey: KEY }, routes: { webPushStore: '/push/subscriptions', webPushDestroy: '/push/subscriptions' } };

let subscription;
let pushManager;

function install(permission = 'default') {
    subscription = null;
    const makeSubscription = (options) => ({
        endpoint: 'https://push.example/abc',
        options,
        toJSON: () => ({ endpoint: 'https://push.example/abc', keys: { p256dh: 'p', auth: 'a' } }),
        unsubscribe: vi.fn(async () => { subscription = null; return true; }),
    });
    pushManager = {
        getSubscription: vi.fn(async () => subscription),
        subscribe: vi.fn(async (options) => { subscription = makeSubscription(options); return subscription; }),
    };
    const registration = { pushManager };
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    window.PushManager = function PushManager() {};
    window.Notification = { permission, requestPermission: vi.fn(async () => { window.Notification.permission = 'granted'; return 'granted'; }) };
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { register: vi.fn(async () => registration), ready: Promise.resolve(registration), getRegistration: vi.fn(async () => registration), addEventListener: vi.fn() },
    });
}

beforeEach(() => {
    localStorage.clear();
    install();
});

afterEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
});

describe('X3 browser push', () => {
    it('decodes the server key', () => {
        const bytes = urlBase64ToUint8Array(KEY);
        expect(bytes).toHaveLength(65);
        expect(bytes[0]).toBe(4);
    });

    it('turns on: asks permission, subscribes with the server key and saves', async () => {
        expect(await pushStatus(config)).toBe('off');

        await enablePush(config);
        expect(Notification.requestPermission).toHaveBeenCalled();
        expect(pushManager.subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(KEY) });
        expect(axios.post).toHaveBeenCalledWith('/push/subscriptions', { endpoint: 'https://push.example/abc', keys: { p256dh: 'p', auth: 'a' } });
        expect(await pushStatus(config)).toBe('on');

        await disablePush(config);
        expect(axios.delete).toHaveBeenCalledWith('/push/subscriptions', { data: { endpoint: 'https://push.example/abc' } });
        expect(await pushStatus(config)).toBe('off');
    });

    it('explains blocked or missing support', async () => {
        install('denied');
        expect(await pushStatus(config)).toBe('denied');
        expect(await pushStatus({ ...config, webPush: { publicKey: null } })).toBe('unavailable');
        await expect(enablePush({ ...config, webPush: null })).rejects.toThrow();
    });

    it('keeps the server up to date and subscribes again after new keys', async () => {
        install('granted');
        await enablePush(config);
        axios.post.mockClear();

        // Recently saved for the same account: nothing to do.
        localStorage.setItem('webpush:user', '7');
        expect(await syncPush(config)).toBe(true);
        expect(axios.post).not.toHaveBeenCalled();

        // Another account signed in on this browser.
        expect(await syncPush({ ...config, user: { id: 8 } })).toBe(true);
        expect(axios.post).toHaveBeenCalledTimes(1);

        // The server made new keys.
        const old = subscription;
        subscription.options = { applicationServerKey: new Uint8Array(65).buffer };
        await syncPush(config);
        expect(old.unsubscribe).toHaveBeenCalled();
        expect(pushManager.subscribe).toHaveBeenCalledTimes(2);
    });

    it('settings button shows the state and turns notifications on and off', async () => {
        document.body.innerHTML = '<span data-browser-permission-text></span><button data-request-browser-notifications><span data-browser-notifications-label>Turn on</span></button>';
        const ui = initBrowserNotificationButton(config);
        const button = document.querySelector('button');
        const text = document.querySelector('span');

        await vi.waitFor(() => expect(text.textContent).toContain('even when this site is closed'));
        button.click();
        await vi.waitFor(() => expect(button.textContent).toBe('Turn off'));
        expect(text.textContent).toMatch(/^On:/);

        button.click();
        await vi.waitFor(() => expect(button.textContent).toBe('Turn on'));
        expect(ui).not.toBeNull();
    });
});
