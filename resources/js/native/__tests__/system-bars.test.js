// @vitest-environment happy-dom
import { SystemBarsStyle } from '@capacitor/core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { barsOutside, FIRST_EDGE_TO_EDGE_BUILD, handlesBarsNatively, systemBarsStyle, updateBarsOutside, watchSystemBars } from '../system-bars';

/** A phone of 360 × 806 CSS px (720 × 1612 at 2x), like the one in the bug report. */
const phone = ({ screenWidth = 360, screenHeight = 806, innerWidth = 360, innerHeight = 806, dark = false, wide = false } = {}) => ({
    screen: { width: screenWidth, height: screenHeight },
    innerWidth,
    innerHeight,
    matchMedia: (query) => ({
        matches: query.includes('prefers-color-scheme') ? dark : !wide,
        addEventListener() {},
        removeEventListener() {},
    }),
    addEventListener() {},
    removeEventListener() {},
});

const reset = () => {
    const root = document.documentElement;
    root.className = '';
    root.removeAttribute('data-native-insets');
    delete root.dataset.theme;
    document.body.innerHTML = '';
};

describe('Android bars: is the page below them? (app 1.1)', () => {
    afterEach(reset);

    it('tells a page between the bars from one drawn behind them', () => {
        // Drawn behind the bars (edge to edge).
        expect(barsOutside(phone())).toBe(false);
        // Below a 24dp status bar and above a 24dp gesture bar, or a 48dp button bar.
        expect(barsOutside(phone({ innerHeight: 758 }))).toBe(true);
        expect(barsOutside(phone({ innerHeight: 734 }))).toBe(true);
        // Landscape, with the button bar at the side.
        expect(barsOutside(phone({ screenWidth: 806, screenHeight: 360, innerWidth: 758, innerHeight: 336 }))).toBe(true);
        expect(barsOutside(phone({ screenWidth: 806, screenHeight: 360, innerWidth: 806, innerHeight: 360 }))).toBe(false);
    });

    it("doesn't guess while the keyboard is open, in split screen or before layout", () => {
        // Keyboard: far more than the bars.
        expect(barsOutside(phone({ innerHeight: 450 }))).toBeNull();
        // Keyboard opening with a field focused.
        document.body.innerHTML = '<input>';
        document.querySelector('input').focus();
        expect(barsOutside(phone({ innerHeight: 758 }), document)).toBeNull();
        document.body.innerHTML = '';
        // Split screen, top and bottom or side by side.
        expect(barsOutside(phone({ innerHeight: 390 }))).toBeNull();
        expect(barsOutside(phone({ screenWidth: 806, screenHeight: 360, innerWidth: 400, innerHeight: 336 }))).toBeNull();
        // Not laid out yet.
        expect(barsOutside(phone({ innerHeight: 0 }))).toBeNull();
        expect(barsOutside({ screen: {}, innerWidth: 360, innerHeight: 700 })).toBeNull();
    });

    it('keeps the mark up to date and never marks app 1.2 pages', () => {
        const root = document.documentElement;

        expect(updateBarsOutside(document, phone({ innerHeight: 758 }))).toBe(true);
        // Keyboard opens: unknown, so the mark stays.
        expect(updateBarsOutside(document, phone({ innerHeight: 420 }))).toBe(true);
        // The page later fills the screen: the mark goes.
        expect(updateBarsOutside(document, phone())).toBe(false);
        expect(root.classList.contains('has-bars-outside')).toBe(false);

        root.classList.add('has-bars-outside');
        root.setAttribute('data-native-insets', 'edge');
        expect(updateBarsOutside(document, phone({ innerHeight: 758 }))).toBe(false);
        expect(root.classList.contains('has-bars-outside')).toBe(false);
    });

    it('knows which app versions handle the bars themselves', async () => {
        expect(await handlesBarsNatively({ getInfo: async () => ({ build: 2 }) })).toBe(false);
        expect(await handlesBarsNatively({ getInfo: async () => ({ build: FIRST_EDGE_TO_EDGE_BUILD }) })).toBe(true);
        expect(await handlesBarsNatively({ getInfo: () => Promise.reject(new Error('no')) })).toBe(false);
        document.documentElement.setAttribute('data-native-insets', 'edge');
        expect(await handlesBarsNatively({ getInfo: async () => ({ build: 1 }) })).toBe(true);
    });
});

describe('Android bars: icon colours', () => {
    afterEach(reset);

    it('uses light status icons on the indigo header and dark ones on light pages', () => {
        const root = document.documentElement;
        root.setAttribute('data-native-insets', 'edge');

        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Light, navigation: SystemBarsStyle.Light });
        document.body.innerHTML = '<div data-chat-app></div>';
        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Light });
        // Landscape or tablet: the chat header is light, so dark icons.
        expect(systemBarsStyle(document, phone({ wide: true })).status).toBe(SystemBarsStyle.Light);
        // Sign-in keeps its indigo band at every width.
        document.body.innerHTML = '<div class="auth-shell"></div>';
        expect(systemBarsStyle(document, phone({ wide: true })).status).toBe(SystemBarsStyle.Dark);
        // Dark theme: light icons on both bars.
        root.dataset.theme = 'dark';
        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Dark });
    });

    it('uses light icons over calls, photos and status updates', () => {
        document.documentElement.setAttribute('data-native-insets', 'edge');
        document.body.innerHTML = '<div class="call-screen" hidden></div>';
        expect(systemBarsStyle(document, phone()).navigation).toBe(SystemBarsStyle.Light);
        document.querySelector('.call-screen').hidden = false;
        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Dark });
        document.body.innerHTML = '<div class="lightbox"></div>';
        expect(systemBarsStyle(document, phone()).navigation).toBe(SystemBarsStyle.Dark);
    });

    it("follows the phone's own mode on app 1.1's plain bars, and stays light on Android 7's dark bars", () => {
        const root = document.documentElement;
        document.body.innerHTML = '<div data-chat-app></div>';
        root.classList.add('has-bars-outside');
        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Default, navigation: SystemBarsStyle.Default });

        root.classList.remove('has-bars-outside');
        root.setAttribute('data-native-insets', 'fitted');
        expect(systemBarsStyle(document, phone())).toEqual({ status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Dark });
    });

    it('sets each bar only when its colour changes', async () => {
        vi.useFakeTimers();
        const setStyle = vi.fn(() => Promise.resolve());
        const win = phone({ innerHeight: 758 });
        document.body.innerHTML = '<div data-chat-app></div>';

        const stop = await watchSystemBars({ SystemBars: { setStyle }, NativeApp: { getInfo: async () => ({ build: 2 }) } }, document, win);
        // App 1.1 below the bars: marked, and both bars follow the phone.
        expect(document.documentElement.classList.contains('has-bars-outside')).toBe(true);
        expect(setStyle.mock.calls.map(([options]) => options)).toEqual([
            { bar: 'StatusBar', style: SystemBarsStyle.Default },
            { bar: 'NavigationBar', style: SystemBarsStyle.Default },
        ]);

        // A photo opens: light icons on both bars. Nothing else changes, so nothing more is sent.
        document.body.insertAdjacentHTML('beforeend', '<div class="lightbox"></div>');
        document.documentElement.classList.remove('has-bars-outside');
        win.innerHeight = 806;
        await vi.advanceTimersByTimeAsync(200);
        expect(setStyle).toHaveBeenCalledTimes(4);
        expect(setStyle).toHaveBeenLastCalledWith({ bar: 'NavigationBar', style: SystemBarsStyle.Dark });

        document.body.insertAdjacentHTML('beforeend', '<p></p>');
        await vi.advanceTimersByTimeAsync(200);
        expect(setStyle).toHaveBeenCalledTimes(4);

        stop();
        vi.useRealTimers();
    });
});
