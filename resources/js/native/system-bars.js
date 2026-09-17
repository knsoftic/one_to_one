import { SystemBarsStyle } from '@capacitor/core';

/**
 * Status and navigation bars in the Android app.
 *
 * From app 1.2 (build 3) the page is drawn behind both bars (Android 8+) and the app sets
 * --safe-area-inset-* and html[data-native-insets="edge"|"fitted"] before the first paint.
 * App 1.1 and older on many phones (Android 14 and older, WebView below 140) put the page below
 * a plain status bar and above a plain navigation bar, while the page still left room for them:
 * an empty band above the header, and white status icons on the white bar. Those pages get
 * html.has-bars-outside, which removes that room (native.css). The layout sets the class before
 * the first paint with the same check (components/layouts/base.blade.php).
 */

/** App versions from 1.2 draw the page behind the bars themselves. */
export const FIRST_EDGE_TO_EDGE_BUILD = 3;

/** Largest gap the bars alone can leave: status bar plus a 3-button navigation bar or taskbar (CSS px). */
const MAX_BARS_GAP = 160;

const PHONE_LAYOUT = '(max-width: 767px)';

/** Dark full-screen views that cover the page (calls, photos, status updates, camera…). */
const DARK_OVERLAYS =
    '.call-screen:not([hidden]), .lightbox, .status-viewer, .status-composer, .camera, .image-editor, .qr-scanner';

function isEditing(doc) {
    const active = doc.activeElement;
    if (!active || active === doc.body) return false;
    return active.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName);
}

/**
 * true when the page is below the status bar and above the navigation bar, false when it fills
 * the screen, null when that can't be told right now (keyboard open, split screen, not laid out).
 */
export function barsOutside(win = window, doc = document) {
    const width = Number(win.innerWidth);
    const height = Number(win.innerHeight);
    const sides = [Number(win.screen?.width ?? 0), Number(win.screen?.height ?? 0)];
    if (!(width > 0) || !(height > 0) || !(sides[0] > 0) || !(sides[1] > 0)) return null;
    if (doc && isEditing(doc)) return null;

    // The screen side across the page's width; the other one is the screen's height.
    const across = Math.abs(sides[0] - width) <= Math.abs(sides[1] - width) ? 0 : 1;
    if (Math.abs(sides[across] - width) > MAX_BARS_GAP) return null; // a window narrower than the screen
    const gap = sides[1 - across] - height;
    if (gap > MAX_BARS_GAP) return null; // a keyboard or a window shorter than the screen
    return gap >= 20;
}

/** Set or clear html.has-bars-outside when it can be told; returns whether the page is marked. */
export function updateBarsOutside(doc = document, win = window) {
    const root = doc.documentElement;
    if (root.hasAttribute('data-native-insets')) {
        root.classList.remove('has-bars-outside');
        return false;
    }
    const outside = barsOutside(win, doc);
    if (outside !== null) root.classList.toggle('has-bars-outside', outside);
    return root.classList.contains('has-bars-outside');
}

/** True for app 1.2 and newer, which handle the bars natively (the page is never second-guessed). */
export async function handlesBarsNatively(nativeApp, doc = document) {
    if (doc.documentElement.hasAttribute('data-native-insets')) return true;
    const info = await Promise.resolve(nativeApp?.getInfo?.()).catch(() => ({}));
    return Number(info?.build ?? 0) >= FIRST_EDGE_TO_EDGE_BUILD;
}

/**
 * Icon colours for both bars. "Dark" = light icons (for a dark or indigo background),
 * "Light" = dark icons, "Default" = follow the phone's dark mode (the app's own plain bars).
 *
 * @returns {{ status: string, navigation: string }}
 */
export function systemBarsStyle(doc = document, win = window) {
    const root = doc.documentElement;
    if (root.getAttribute('data-native-insets') === 'fitted') {
        // Android 7: the page is between an indigo status bar and a black navigation bar.
        return { status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Dark };
    }
    if (root.classList.contains('has-bars-outside')) {
        // The bars show the app's window background, which follows the phone's dark mode.
        return { status: SystemBarsStyle.Default, navigation: SystemBarsStyle.Default };
    }

    const dark = root.dataset.theme === 'dark';
    const page = dark ? SystemBarsStyle.Dark : SystemBarsStyle.Light;
    if (doc.querySelector(DARK_OVERLAYS)) {
        return { status: SystemBarsStyle.Dark, navigation: SystemBarsStyle.Dark };
    }

    // Sign-in has an indigo band at the top at every width; chats and settings only in the phone layout.
    const phoneLayout = win.matchMedia?.(PHONE_LAYOUT).matches ?? true;
    const indigoTop = Boolean(doc.querySelector('.auth-shell') || (phoneLayout && doc.querySelector('[data-chat-app], [data-settings]')));
    return { status: dark || indigoTop ? SystemBarsStyle.Dark : SystemBarsStyle.Light, navigation: page };
}

/**
 * Keep the bars right while the app runs: after rotation, keyboard, split screen, theme changes
 * and when full-screen views open or close.
 *
 * @param {{ SystemBars: object, NativeApp: object }} plugins
 * @returns {Promise<() => void>} stops watching
 */
export async function watchSystemBars({ SystemBars, NativeApp }, doc = document, win = window) {
    let applied = { status: null, navigation: null };
    const apply = () => {
        const style = systemBarsStyle(doc, win);
        for (const [bar, name] of [['status', 'StatusBar'], ['navigation', 'NavigationBar']]) {
            if (style[bar] === applied[bar]) continue;
            applied[bar] = style[bar];
            SystemBars.setStyle({ bar: name, style: style[bar] })?.catch?.(() => {});
        }
    };

    const native = await handlesBarsNatively(NativeApp, doc);
    if (native) doc.documentElement.classList.remove('has-bars-outside');

    let timer = 0;
    const refresh = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!native) updateBarsOutside(doc, win);
            apply();
        }, 120);
    };

    if (!native) updateBarsOutside(doc, win);
    apply();

    const dark = win.matchMedia?.('(prefers-color-scheme: dark)');
    const observer = typeof MutationObserver === 'function' ? new MutationObserver(refresh) : null;
    // Full-screen views are added to <body>; the call screen is shown and hidden in place.
    observer?.observe(doc.body, { childList: true });
    observer?.observe(doc.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-native-insets'] });
    const shown = typeof MutationObserver === 'function' ? new MutationObserver(refresh) : null;
    shown?.observe(doc.body, { subtree: true, attributes: true, attributeFilter: ['hidden'] });

    win.addEventListener('resize', refresh);
    doc.addEventListener('focusout', refresh);
    doc.addEventListener('theme:change', refresh);
    // Capacitor keeps the style it resolved for "Default", so set it again when the phone's mode changes.
    const darkModeChanged = () => {
        applied = { status: null, navigation: null };
        refresh();
    };
    dark?.addEventListener?.('change', darkModeChanged);

    return () => {
        clearTimeout(timer);
        observer?.disconnect();
        shown?.disconnect();
        win.removeEventListener('resize', refresh);
        doc.removeEventListener('focusout', refresh);
        doc.removeEventListener('theme:change', refresh);
        dark?.removeEventListener?.('change', darkModeChanged);
    };
}
