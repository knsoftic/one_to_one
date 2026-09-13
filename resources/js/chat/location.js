import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * M18 — Share your current location or a live location (15 min / 1 h / 8 h).
 *
 * Live positions are sent by this device while the chat is open in this tab
 * or app; sharing ends automatically at the chosen time or with "Stop sharing".
 */

export const LIVE_DURATIONS = [
    { minutes: 15, label: '15 minutes' },
    { minutes: 60, label: '1 hour' },
    { minutes: 480, label: '8 hours' },
];

/** Send a new live position at most this often… */
export const MIN_UPDATE_MS = 15_000;
/** …and, when not moving, only this often. */
export const IDLE_UPDATE_MS = 60_000;
export const MIN_MOVE_METRES = 15;

export function mapsUrl(lat, lng) {
    return `https://www.google.com/maps/search/?api=1&query=${Number(lat).toFixed(6)},${Number(lng).toFixed(6)}`;
}

/** Great-circle distance in metres. */
export function distanceMetres(a, b) {
    const rad = (deg) => (deg * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6_371_000 * Math.asin(Math.sqrt(h));
}

/** Whether a live location is still being shared right now. */
export function isLiveActive(location, now = Date.now()) {
    return Boolean(location?.live && !location.stopped_at && location.live_until && Date.parse(location.live_until) > now);
}

/** Should a new position be sent, given the last one sent? */
export function shouldSendUpdate(last, next, now = Date.now()) {
    if (!last) return true;
    const elapsed = now - last.at;
    if (elapsed < MIN_UPDATE_MS) return false;
    return elapsed >= IDLE_UPDATE_MS || distanceMetres(last, next) >= MIN_MOVE_METRES;
}

function currentPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('This device cannot share its location.'));
            return;
        }
        navigator.geolocation.getCurrentPosition(resolve, (error) => reject(positionError(error)), {
            enableHighAccuracy: true,
            timeout: 20_000,
            maximumAge: 30_000,
        });
    });
}

function positionError(error) {
    if (error?.code === 1) return new Error('Location access is blocked. Allow location for this site (or in the app settings) and try again.');
    if (error?.code === 3) return new Error('Finding your location took too long. Try again outdoors or with Wi-Fi on.');
    return new Error('Your location could not be found.');
}

export class LocationSharing {
    constructor(chat) {
        this.chat = chat;
        /** @type {Map<string, {watchId: number, last: {lat: number, lng: number, at: number}|null}>} */
        this.sessions = new Map();

        chat.el.messageList.addEventListener('click', (event) => {
            const stop = event.target.closest('[data-location-stop]');
            if (stop) this.stop(Number(stop.dataset.locationStop));
        });

        // Continue sharing after reopening the chat; refresh "ended" states.
        document.addEventListener('chat:opened', () => this.resume());
        setInterval(() => this.tick(), 30_000);
    }

    /* ---- dialog ---- */

    async open() {
        const conversationId = this.chat.active?.id;
        if (!conversationId) return;

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal location-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'location-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-location-cancel></div>
            <div class="modal-panel location-panel">
                <h2 class="modal-title" id="location-title">Share location</h2>
                <div class="location-status" data-location-status>
                    <span class="spinner"></span><span>Finding your location…</span>
                </div>
                <div class="location-choices" data-location-choices hidden>
                    <button type="button" class="location-choice" data-location-send>
                        <span class="location-choice-icon">${raw(icon('map-pin'))}</span>
                        <span><strong>Send your current location</strong><small data-location-accuracy></small></span>
                    </button>
                    <div class="location-choice is-live">
                        <span class="location-choice-icon">${raw(icon('navigation'))}</span>
                        <span><strong>Share live location</strong><small>Updates while this chat is open on this device</small>
                            <span class="location-durations">
                                ${raw(LIVE_DURATIONS.map((d) => html`<button type="button" class="chip" data-location-live="${d.minutes}">${d.label}</button>`).join(''))}
                            </span>
                        </span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-location-cancel>Cancel</button>
                </div>
            </div>
        `;

        let position = null;
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-location-cancel]')) {
                close();
            } else if (position && event.target.closest('[data-location-send]')) {
                close();
                this.send(conversationId, position, null);
            } else if (position && event.target.closest('[data-location-live]')) {
                close();
                this.send(conversationId, position, Number(event.target.closest('[data-location-live]').dataset.locationLive));
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-location-cancel].btn').focus();

        try {
            position = await currentPosition();
        } catch (error) {
            if (!overlay.isConnected) return;
            overlay.querySelector('[data-location-status]').innerHTML = html`<span class="location-error">${raw(icon('circle-alert'))}${error.message}</span>`;
            return;
        }

        if (!overlay.isConnected) return;
        const accuracy = Math.round(position.coords.accuracy || 0);
        overlay.querySelector('[data-location-status]').hidden = true;
        overlay.querySelector('[data-location-choices]').hidden = false;
        overlay.querySelector('[data-location-accuracy]').textContent = accuracy ? `Accurate to ${accuracy} m` : '';
        overlay.querySelector('[data-location-send]').focus();
    }

    async send(conversationId, position, liveMinutes) {
        const { latitude: lat, longitude: lng, accuracy } = position.coords;
        const location = {
            lat: Number(lat.toFixed(6)),
            lng: Number(lng.toFixed(6)),
            accuracy: accuracy ? Math.round(accuracy) : null,
            ...(liveMinutes ? { live_minutes: liveMinutes } : {}),
        };

        const message = await this.chat.sendSpecial(conversationId, {
            type: 'location',
            fields: { location },
            attachment: null,
            extra: {
                location: {
                    lat: location.lat,
                    lng: location.lng,
                    accuracy: location.accuracy,
                    live: Boolean(liveMinutes),
                    live_active: Boolean(liveMinutes),
                    live_until: liveMinutes ? new Date(Date.now() + liveMinutes * 60_000).toISOString() : null,
                    updated_at: new Date().toISOString(),
                },
            },
        });

        if (message && liveMinutes) {
            this.start(message, { lat: location.lat, lng: location.lng, at: Date.now() });
        }
    }

    /* ---- live sharing ---- */

    start(message, last = null) {
        const id = String(message.id);
        if (!navigator.geolocation || this.sessions.has(id) || !message.is_mine || !isLiveActive(message.location)) return;

        const watchId = navigator.geolocation.watchPosition(
            (position) => this.onPosition(message.id, position),
            (error) => {
                if (error?.code === 1) {
                    toast.error('Live location paused: location access is blocked.');
                    this.end(message.id);
                }
            },
            { enableHighAccuracy: true, maximumAge: 10_000, timeout: 60_000 },
        );

        this.sessions.set(id, { watchId, last, until: Date.parse(message.location.live_until) });
    }

    async onPosition(messageId, position) {
        const session = this.sessions.get(String(messageId));
        if (!session) return;

        if (Date.now() >= session.until) {
            this.end(messageId);
            return;
        }

        const next = { lat: position.coords.latitude, lng: position.coords.longitude, at: Date.now() };
        if (!shouldSendUpdate(session.last, next) || session.sending) return;

        session.sending = true;
        try {
            const result = await this.chat.api.updateLocation(messageId, {
                lat: Number(next.lat.toFixed(6)),
                lng: Number(next.lng.toFixed(6)),
                accuracy: position.coords.accuracy ? Math.round(position.coords.accuracy) : null,
            });
            session.last = next;
            this.apply(messageId, result.location);
        } catch (error) {
            // Ended elsewhere (stopped on another device, expired or deleted).
            if ([403, 404].includes(error?.response?.status)) this.end(messageId);
        } finally {
            session.sending = false;
        }
    }

    async stop(messageId) {
        this.end(messageId);
        try {
            const result = await this.chat.api.stopLocation(messageId);
            this.apply(messageId, result.location);
        } catch (error) {
            toast.error(errorMessage(error, 'Live location could not be stopped.'));
        }
    }

    end(messageId) {
        const session = this.sessions.get(String(messageId));
        if (!session) return;
        navigator.geolocation?.clearWatch(session.watchId);
        this.sessions.delete(String(messageId));
    }

    apply(messageId, location) {
        const current = this.chat.active?.byId.get(String(messageId));
        if (current && location) this.chat.updateMessage({ ...current, location });
    }

    resume() {
        for (const message of this.chat.active?.messages ?? []) {
            if (message.type === 'location' && message.is_mine && isLiveActive(message.location)) this.start(message);
        }
    }

    /** Stop expired sessions and redraw live bubbles whose time ran out. */
    tick() {
        for (const [id, session] of this.sessions) {
            if (Date.now() >= session.until) this.end(id);
        }

        for (const message of this.chat.active?.messages ?? []) {
            if (message.type === 'location' && message.location?.live_active && !isLiveActive(message.location)) {
                this.chat.updateMessage({ ...message, location: { ...message.location, live_active: false } });
            }
        }
    }
}
