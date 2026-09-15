/*
 * One2One Chat service worker (X3).
 *  - Push notifications while the site is closed (new messages, incoming calls),
 *    removed again when the chat is read on another device.
 *  - An offline page when the network is gone.
 * Nothing else is cached: chats always come fresh from the server.
 */
const CACHE = 'one2one-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/icons/badge-96.png', '/favicon.svg'];
const VIBRATE = { default: [180], short: [70], long: [400, 150, 400], off: [] };
const MAX_LINES = 6;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).catch(() => null).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('one2one-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.mode !== 'navigate' || request.method !== 'GET') return;
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }
    event.waitUntil(handlePush(data));
});

async function handlePush(data) {
    // Read somewhere else, or the call was answered / ended: remove the notification.
    if (data.type === 'read' || data.type === 'close') {
        const shown = await self.registration.getNotifications({ tag: data.tag });
        shown.forEach((notification) => notification.close());
        return;
    }
    if (!data.title) return;

    // The site is open and on screen: it shows the message itself.
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    if (data.type === 'message' && windows.some((client) => client.visibilityState === 'visible')) return;

    // Earlier messages of the same chat stay listed, newest last.
    const earlier = data.tag ? await self.registration.getNotifications({ tag: data.tag }) : [];
    const previous = earlier.length ? earlier[earlier.length - 1].data?.lines ?? [] : [];
    const lines = data.type === 'message' ? [...previous, data.body].slice(-MAX_LINES) : [data.body];

    await self.registration.showNotification(data.title, {
        body: lines.filter(Boolean).join('\n'),
        tag: data.tag,
        renotify: !data.silent,
        silent: Boolean(data.silent),
        icon: data.icon || '/icons/icon-192.png',
        badge: '/icons/badge-96.png',
        vibrate: VIBRATE[data.vibrate] ?? VIBRATE.default,
        requireInteraction: Boolean(data.require_interaction),
        timestamp: Date.now(),
        data: { url: data.url || '/chat', lines },
    });
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/chat', self.location.origin);

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => new URL(client.url).origin === url.origin);
        if (open) {
            await open.focus();
            open.postMessage({ type: 'open-url', url: url.href });
            return;
        }
        await self.clients.openWindow(url.href);
    })());
});
