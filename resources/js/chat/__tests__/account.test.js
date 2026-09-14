// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import { canScanQr, startQrCamera } from '../../lib/qr-scanner';
import { initProfileQr } from '../../ui/settings';
import { ProfileQr, profileTokenFromQr } from '../profile-qr';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const TOKEN = 'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z';

afterEach(() => {
    document.body.innerHTML = '';
    delete window.BarcodeDetector;
    vi.useRealTimers();
});

const clickModal = async (value) => {
    await vi.waitFor(() => expect(document.querySelector(`[data-modal-value="${value}"]`)).not.toBeNull());
    document.querySelector(`[data-modal-value="${value}"]`).click();
};

function setup(overrides = {}) {
    const chat = {
        me: { id: 1, name: 'Ayesha Khan', username: 'ayesha', initials: 'AK', avatar_hue: 47 },
        config: { appName: 'One2One Chat', routes: { chat: '/chat' }, ...overrides.config },
        api: {
            has: () => true,
            profileQr: vi.fn(async () => ({ url: `https://chat.example.com/u/${TOKEN}`, token: TOKEN })),
            resetProfileQr: vi.fn(async () => ({ url: 'https://chat.example.com/u/Zz3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z', token: 'Zz3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z', message: 'Your QR code has been reset. Old codes no longer work.' })),
            lookupProfileQr: vi.fn(async () => ({ user: { id: 2, name: 'Bilal Ahmed', username: 'bilal', about: 'Cricket 🏏' }, saved_name: 'Bilal Cousin', self: false })),
            ...overrides.api,
        },
        rememberUser: vi.fn(),
        startConversationWith: vi.fn(async () => {}),
    };
    return { chat, qr: new ProfileQr(chat) };
}

describe('A4 profile QR code', () => {
    it('reads the token from a scanned code or a pasted link', () => {
        expect(profileTokenFromQr(`https://chat.example.com/u/${TOKEN}`)).toBe(TOKEN);
        expect(profileTokenFromQr(` https://chat.example.com/u/${TOKEN}?from=share `)).toBe(TOKEN);
        expect(profileTokenFromQr(`https://chat.example.com/link-device/${TOKEN}`)).toBeNull();
        expect(profileTokenFromQr('https://chat.example.com/u/short')).toBeNull();
        expect(profileTokenFromQr(null)).toBeNull();
    });

    it('shows my code, copies the link and resets the code', async () => {
        const { chat, qr } = setup();
        const writeText = vi.fn(async () => {});
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });

        document.dispatchEvent(new CustomEvent('chat:action', { detail: { action: 'open-qr-code' } }));
        await vi.waitFor(() => expect(document.querySelector('.profile-qr-code svg')).not.toBeNull());
        expect(document.querySelector('.group-info-name').textContent).toBe('Ayesha Khan');
        expect(document.querySelector('.group-info-description').textContent).toContain('scan it with One2One Chat');

        document.querySelector('[data-qr-share]').click();
        await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith(`https://chat.example.com/u/${TOKEN}`));

        document.querySelector('[data-qr-reset]').click();
        await clickModal('reset');
        await vi.waitFor(() => expect(chat.api.resetProfileQr).toHaveBeenCalled());
        expect(qr.code.token).toBe('Zz3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z');

        document.querySelector('[data-qr-close]').click();
        expect(document.querySelector('.profile-qr-panel')).toBeNull();
    });

    it('scans a code (or takes a pasted link) and starts the chat', async () => {
        const { chat } = setup();

        const opening = (async () => {
            await new ProfileQr(chat).scan();
        })();
        await opening;
        // No camera QR support here: the link box is offered.
        const scanner = document.querySelector('.qr-scanner');
        expect(scanner.classList.contains('is-code-only')).toBe(true);

        const input = scanner.querySelector('#profile-qr-link');
        input.value = 'https://example.com/not-a-code';
        scanner.querySelector('[data-scan-link]').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(chat.api.lookupProfileQr).not.toHaveBeenCalled();

        input.value = `https://chat.example.com/u/${TOKEN}`;
        scanner.querySelector('[data-scan-link]').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(document.querySelector('.modal-title')?.textContent).toBe('Chat with Bilal Cousin?'));
        expect(document.querySelector('.modal-text').textContent).toBe('@bilal · Cricket 🏏');
        expect(document.querySelector('.qr-scanner')).toBeNull();
        await clickModal('chat');
        await vi.waitFor(() => expect(chat.startConversationWith).toHaveBeenCalledWith(2));
        expect(chat.api.lookupProfileQr).toHaveBeenCalledWith(TOKEN);
    });

    it('opens from a code scanned with the phone camera; own and old codes', async () => {
        vi.useFakeTimers();
        const replaceState = vi.spyOn(history, 'replaceState');
        const { chat } = setup({ config: { profileQr: { token: TOKEN } } });
        expect(replaceState).toHaveBeenCalledWith(history.state, '', '/chat');
        await vi.advanceTimersByTimeAsync(300);
        vi.useRealTimers();
        await vi.waitFor(() => expect(document.querySelector('.modal-title')?.textContent).toBe('Chat with Bilal Cousin?'));
        await clickModal('chat');
        await vi.waitFor(() => expect(chat.startConversationWith).toHaveBeenCalledWith(2));

        const own = setup({ api: { lookupProfileQr: vi.fn(async () => ({ user: { id: 1, name: 'Ayesha' }, self: true })) } });
        expect(await own.qr.openChat(TOKEN)).toBe(false);
        expect(own.chat.startConversationWith).not.toHaveBeenCalled();

        const old = setup({ api: { lookupProfileQr: vi.fn(async () => Promise.reject(Object.assign(new Error('404'), { response: { data: { message: "This QR code isn't valid anymore. Ask for a new one." } } }))) } });
        expect(await old.qr.openChat(TOKEN)).toBe(false);
    });

    it('removes the menu item when the server has no QR code routes', () => {
        document.body.innerHTML = '<button data-action="open-qr-code">QR code</button>';
        setup({ api: { has: () => false } });
        expect(document.querySelector('[data-action="open-qr-code"]')).toBeNull();
    });
});

describe('Shared QR camera scanning', () => {
    it('reads codes from the camera until one is accepted', async () => {
        const stopTrack = vi.fn();
        Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: vi.fn(async () => ({ getTracks: () => [{ stop: stopTrack }] })) }, configurable: true });
        const detect = vi.fn()
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([{ rawValue: 'hello' }])
            .mockResolvedValueOnce([{ rawValue: `https://x/u/${TOKEN}` }]);
        window.BarcodeDetector = function BarcodeDetector() {
            return { detect };
        };
        expect(canScanQr()).toBe(true);

        const video = { srcObject: null, play: vi.fn(async () => {}) };
        const seen = [];
        await startQrCamera(video, (values) => {
            seen.push(...values);
            return values.some((value) => profileTokenFromQr(value));
        }, { interval: 1 });

        await vi.waitFor(() => expect(stopTrack).toHaveBeenCalled());
        expect(seen).toEqual(['hello', `https://x/u/${TOKEN}`]);
        expect(detect).toHaveBeenCalledTimes(3);
    });
});

describe('Settings → Account', () => {
    it('draws the profile QR code', async () => {
        document.body.innerHTML = `<div data-profile-qr="https://chat.example.com/u/${TOKEN}"><span class="spinner"></span></div>`;
        expect(await initProfileQr()).toBe(true);
        expect(document.querySelector('[data-profile-qr] svg')).not.toBeNull();
        expect(await initProfileQr(null)).toBe(false);
    });
});
