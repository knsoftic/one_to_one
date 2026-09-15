import axios from '../bootstrap';
import { isNativeApp } from './native';

/**
 * X3 — browser push notifications and the installable app (PWA).
 */

const SYNC_KEY = 'webpush:synced';
const SYNC_EVERY = 12 * 60 * 60 * 1000;

let registration = null;
let installPrompt = null;

export function pushSupported(win = globalThis.window) {
    return Boolean(win?.isSecureContext && 'serviceWorker' in win.navigator && 'PushManager' in win && 'Notification' in win) && !isNativeApp();
}

export function urlBase64ToUint8Array(value) {
    const padded = `${value}${'='.repeat((4 - (value.length % 4)) % 4)}`.replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

/** Register the service worker once (also used for the offline page). */
export async function registerServiceWorker() {
    if (registration) return registration;
    if (!globalThis.navigator?.serviceWorker || isNativeApp()) return null;
    try {
        registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        return registration;
    } catch {
        return null;
    }
}

async function readyRegistration() {
    await registerServiceWorker();
    return navigator.serviceWorker.ready;
}

/** 'unsupported' | 'unavailable' | 'denied' | 'on' | 'off' */
export async function pushStatus(config) {
    if (!pushSupported()) return 'unsupported';
    if (!config?.webPush?.publicKey) return 'unavailable';
    if (Notification.permission === 'denied') return 'denied';
    const reg = await readyRegistration();
    const subscription = await reg.pushManager.getSubscription();
    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

function sameKey(subscription, publicKey) {
    const current = subscription?.options?.applicationServerKey;
    if (!current) return true;
    const expected = urlBase64ToUint8Array(publicKey);
    const actual = new Uint8Array(current);
    return actual.length === expected.length && actual.every((byte, i) => byte === expected[i]);
}

async function save(config, subscription) {
    await axios.post(config.routes.webPushStore, subscription.toJSON());
    try {
        localStorage.setItem(SYNC_KEY, String(Date.now()));
    } catch {
        /* storage unavailable */
    }
}

/** Ask for permission (when needed), subscribe and tell the server. */
export async function enablePush(config) {
    if (!pushSupported() || !config?.webPush?.publicKey) throw new Error('This browser cannot show notifications from this site.');

    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') throw new Error(permission === 'denied'
        ? 'Notifications are blocked. Allow them in your browser\'s site settings.'
        : 'Notifications were not allowed.');

    const reg = await readyRegistration();
    let subscription = await reg.pushManager.getSubscription();
    // The server made new keys: subscribe again.
    if (subscription && !sameKey(subscription, config.webPush.publicKey)) {
        await subscription.unsubscribe();
        subscription = null;
    }
    subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(config.webPush.publicKey) });
    await save(config, subscription);
    return subscription;
}

export async function disablePush(config) {
    if (!pushSupported()) return;
    const reg = await readyRegistration();
    const subscription = await reg.pushManager.getSubscription();
    if (!subscription) return;
    try {
        await axios.delete(config.routes.webPushDestroy, { data: { endpoint: subscription.endpoint } });
    } finally {
        await subscription.unsubscribe();
        try {
            localStorage.removeItem(SYNC_KEY);
        } catch {
            /* storage unavailable */
        }
    }
}

/**
 * On every page: keep this browser's subscription known to the server (it may have
 * signed in with another account, or the server forgot it after signing out).
 */
export async function syncPush(config, { force = false } = {}) {
    if (!config?.user || !pushSupported() || !config.webPush?.publicKey || Notification.permission !== 'granted') return false;
    try {
        const last = Number(localStorage.getItem(SYNC_KEY) || 0);
        const reg = await readyRegistration();
        const subscription = await reg.pushManager.getSubscription();
        if (!subscription) {
            await enablePush(config);
            return true;
        }
        if (!sameKey(subscription, config.webPush.publicKey)) {
            await enablePush(config);
            return true;
        }
        if (force || Date.now() - last > SYNC_EVERY || localStorage.getItem('webpush:user') !== String(config.user.id)) {
            await save(config, subscription);
            localStorage.setItem('webpush:user', String(config.user.id));
        }
        return true;
    } catch {
        return false;
    }
}

/** True when this browser gets push notifications (the page then skips its own pop-ups). */
export async function pushActive() {
    if (!pushSupported() || Notification.permission !== 'granted') return false;
    try {
        const reg = await navigator.serviceWorker.getRegistration('/');
        return Boolean(await reg?.pushManager.getSubscription());
    } catch {
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Install the app                                                     */
/* ------------------------------------------------------------------ */

export function initInstallPrompt(doc = document) {
    const show = () => doc.querySelectorAll('[data-install-app]').forEach((el) => { el.hidden = !installPrompt; });

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        show();
    });
    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        show();
    });

    doc.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-install-app]');
        if (!button || !installPrompt) return;
        const prompt = installPrompt;
        installPrompt = null;
        show();
        await prompt.prompt();
    });
    show();
}

/** A notification was clicked while the site is open: go to that chat. */
export function listenForNotificationClicks(onOpen) {
    if (!globalThis.navigator?.serviceWorker) return;
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type !== 'open-url') return;
        const url = new URL(event.data.url, window.location.origin);
        if (url.origin !== window.location.origin) return;
        if (!onOpen?.(url)) window.location.assign(url.href);
    });
}
