import axios from '../bootstrap';
import { icon } from '../lib/icons';

/**
 * Connection status bar shown at the top of every page:
 *   offline      – the device has no internet
 *   unreachable  – internet is on but the server doesn't answer
 *   slow         – requests take long or the network reports 2G speeds
 *   restored     – briefly, when the connection comes back
 */

const SLOW_REQUEST_MS = 3500;
const FAST_REQUEST_MS = 1500;
const PING_INTERVAL_MS = 8000;
const SLOW_RECHECK_MS = 20000;
const PING_TIMEOUT_MS = 10000;
const RESTORED_VISIBLE_MS = 2500;

const MESSAGES = {
    offline: { icon: 'wifi-off', text: 'No internet connection', hint: 'Waiting for network…' },
    unreachable: { icon: 'cloud-off', text: "Can't reach the server", hint: 'Retrying…' },
    slow: { icon: 'signal-low', text: 'Slow internet connection', hint: 'Messages may take longer to send' },
    restored: { icon: 'wifi', text: 'Back online', hint: '' },
};

let bar = null;
let state = 'online';
let pingTimer = null;
let restoredTimer = null;
let slowStreak = 0;
let fastStreak = 0;
let pingUrl = '/up';

export function initNetworkStatus(config = {}) {
    pingUrl = config.routes?.health ?? '/up';

    bar = document.createElement('div');
    bar.className = 'network-bar';
    bar.setAttribute('role', 'status');
    bar.setAttribute('aria-live', 'polite');
    bar.hidden = true;
    document.body.appendChild(bar);

    window.addEventListener('offline', () => setState('offline'));
    window.addEventListener('online', () => verifyServer());

    navigator.connection?.addEventListener?.('change', onNetworkChanged);

    watchRequests();

    if (!navigator.onLine) setState('offline');
}

export function networkState() {
    return state;
}

function setState(next) {
    if (next === state) return;

    const previous = state;
    state = next;
    clearTimeout(restoredTimer);

    stopPinging();
    if (next === 'offline' || next === 'unreachable') startPinging(PING_INTERVAL_MS);
    else if (next === 'slow') startPinging(SLOW_RECHECK_MS);

    if (next === 'online') {
        // Only announce recovery from a real outage.
        if (previous === 'offline' || previous === 'unreachable') {
            render('restored');
            restoredTimer = setTimeout(() => render(null), RESTORED_VISIBLE_MS);
        } else {
            render(null);
        }
    } else {
        render(next);
    }

    document.dispatchEvent(new CustomEvent('network:change', { detail: { state: next, previous } }));
}

function render(kind) {
    if (!bar) return;

    const root = document.documentElement;
    if (!kind) {
        bar.hidden = true;
        root.classList.remove('has-network-bar');
        return;
    }

    const message = MESSAGES[kind];
    bar.dataset.state = kind;
    bar.innerHTML = `
        <span class="network-bar-icon">${icon(message.icon)}</span>
        <span class="network-bar-text">${message.text}</span>
        ${message.hint ? `<span class="network-bar-hint">${message.hint}</span>` : ''}
        ${kind === 'offline' || kind === 'unreachable' ? '<span class="network-bar-dots" aria-hidden="true"><i></i><i></i><i></i></span>' : ''}
    `;
    bar.hidden = false;
    root.classList.add('has-network-bar');
}

/* ---------------------------------------------------------------------- */
/* Detection                                                               */
/* ---------------------------------------------------------------------- */

/**
 * The network changed (e.g. Wi-Fi ↔ mobile data). Speed estimates from the
 * Network Information API are unreliable, so re-measure instead of trusting them.
 */
function onNetworkChanged() {
    if (!navigator.onLine) {
        setState('offline');
    } else if (state !== 'online') {
        verifyServer();
    }
}

/** Measure how long API calls take and notice network failures. */
function watchRequests() {
    axios.interceptors.request.use((request) => {
        request.metadata = { startedAt: performance.now() };
        return request;
    });

    axios.interceptors.response.use(
        (response) => {
            recordDuration(response.config);
            return response;
        },
        (error) => {
            if (error?.response) {
                recordDuration(error.config);
            } else if (error?.code !== 'ERR_CANCELED' && error?.name !== 'CanceledError') {
                // No response at all: offline, or the server is down.
                if (!navigator.onLine) setState('offline');
                else verifyServer();
            }
            return Promise.reject(error);
        },
    );
}

function recordDuration(request) {
    const startedAt = request?.metadata?.startedAt;
    if (!startedAt || isUpload(request)) return;

    const duration = performance.now() - startedAt;

    if (state === 'offline' || state === 'unreachable') {
        setState('online');
    }

    if (duration > SLOW_REQUEST_MS) {
        slowStreak++;
        fastStreak = 0;
        if (slowStreak >= 2 && state === 'online') setState('slow');
    } else if (duration < FAST_REQUEST_MS) {
        fastStreak++;
        if (fastStreak >= 3) {
            slowStreak = 0;
            if (state === 'slow') setState('online');
        }
    }
}

function isUpload(request) {
    return typeof FormData !== 'undefined' && request?.data instanceof FormData;
}

/* ---------------------------------------------------------------------- */
/* Recovery checks                                                         */
/* ---------------------------------------------------------------------- */

async function verifyServer() {
    if (!navigator.onLine) {
        setState('offline');
        return;
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), PING_TIMEOUT_MS);
    const startedAt = performance.now();

    try {
        const response = await fetch(pingUrl, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin', signal: controller.signal });
        if (!response.ok && response.status >= 500) throw new Error('Server error');

        setState(performance.now() - startedAt > SLOW_REQUEST_MS ? 'slow' : 'online');
    } catch {
        setState(navigator.onLine ? 'unreachable' : 'offline');
    } finally {
        clearTimeout(timeout);
    }
}

function startPinging(interval) {
    pingTimer = setInterval(verifyServer, interval);
}

function stopPinging() {
    clearInterval(pingTimer);
    pingTimer = null;
}
