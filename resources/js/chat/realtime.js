import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Realtime transport for the chat.
 *
 *  - Laravel Echo + Reverb (WebSockets) when available.
 *  - Automatic AJAX polling fallback while the socket is down, with a
 *    catch-up sync after reconnecting so no event is ever lost.
 *  - Heartbeat to keep presence fresh, offline beacon when the tab closes.
 */
export class Realtime {
    constructor(chat) {
        this.chat = chat;
        this.config = chat.config.realtime;
        this.echo = null;
        this.state = 'connecting';
        this.pollTimer = null;
        this.fallbackTimer = null;
        this.lastSync = new Date().toISOString();
        this.everConnected = false;
        this.disconnectedAt = null;
        this.polling = false;
    }

    start() {
        this.setStatus('connecting');

        if (this.config.enabled && this.config.key) {
            this.connectEcho();
            // If the socket is not up quickly, keep the app live with polling.
            this.fallbackTimer = setTimeout(() => {
                if (this.state !== 'connected') this.startPolling();
            }, 4000);
        } else {
            this.startPolling();
        }

        this.startHeartbeat();
        this.bindLifecycle();

        // Safety net: a light catch-up sync even while the socket is connected
        // (covers events the server could not broadcast).
        this.safetyTimer = setInterval(() => {
            if (!this.polling && document.visibilityState === 'visible') this.syncNow();
        }, 60_000);
    }

    /* ------------------------------------------------------------------ */
    /* WebSockets                                                          */
    /* ------------------------------------------------------------------ */

    connectEcho() {
        window.Pusher = Pusher;

        const tls = this.config.scheme === 'https';
        this.echo = new Echo({
            broadcaster: 'reverb',
            key: this.config.key,
            wsHost: this.config.host || window.location.hostname,
            wsPort: this.config.port || 80,
            wssPort: this.config.port || 443,
            forceTLS: tls,
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
        });
        window.Echo = this.echo;

        const connection = this.echo.connector.pusher.connection;
        connection.bind('state_change', ({ current }) => this.onSocketState(current));

        const me = this.chat.me.id;

        this.echo
            .private(`App.Models.User.${me}`)
            .listen('.message.sent', (e) => this.chat.onIncomingMessage(e.message))
            .listen('.message.updated', (e) => this.chat.onMessageUpdated(e.message))
            .listen('.message.hidden', (e) => this.chat.onMessageHidden(e))
            .listen('.message.status', (e) => this.chat.onStatusUpdate(e))
            .listen('.user.typing', (e) => this.chat.onTyping(e))
            .listen('.block.changed', (e) => this.chat.onBlockChanged?.(e))
            .listen('.call.incoming', (e) => this.chat.calls?.onIncoming(e.call))
            .listen('.call.updated', (e) => this.chat.calls?.onCallUpdated(e.call))
            .listen('.call.signal', (e) => this.chat.calls?.onSignal(e.signal))
            .notification((notification) => this.chat.onNotification?.(notification));

        this.echo
            .join('online')
            .here((users) => this.chat.onPresenceHere(users))
            .joining((user) => this.chat.onPresenceJoin(user))
            .leaving((user) => this.chat.onPresenceLeave(user))
            .listen('.user.presence', (e) => this.chat.onPresenceUpdate(e.user));
    }

    onSocketState(state) {
        this.state = state;

        if (state === 'connected') {
            clearTimeout(this.fallbackTimer);
            const reconnected = this.everConnected;
            this.everConnected = true;
            this.stopPolling();
            this.setStatus('connected');

            // Catch up on anything that happened while the socket was down.
            if (reconnected || this.disconnectedAt) {
                this.syncNow(this.disconnectedAt ?? this.lastSync);
            }
            this.disconnectedAt = null;
            return;
        }

        if (['unavailable', 'failed', 'disconnected'].includes(state)) {
            this.disconnectedAt ??= new Date(Date.now() - 5000).toISOString();
            this.startPolling();
        } else if (state === 'connecting' && !this.polling) {
            this.setStatus('connecting');
        }
    }

    get socketId() {
        return this.echo?.socketId?.() ?? null;
    }

    /* ------------------------------------------------------------------ */
    /* Polling fallback                                                    */
    /* ------------------------------------------------------------------ */

    startPolling() {
        if (!this.chat.api.has('sync') || this.pollTimer) return;
        this.polling = true;
        this.setStatus(navigator.onLine ? 'polling' : 'offline');
        this.lastSync = this.disconnectedAt ?? this.lastSync;
        this.syncNow();
        this.pollTimer = setInterval(() => this.syncNow(), this.config.pollingIntervalMs || 4000);
    }

    stopPolling() {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
        this.polling = false;
    }

    async syncNow(since = null) {
        if (this.syncing) return;
        this.syncing = true;

        try {
            const data = await this.chat.api.sync({
                since: since ?? this.lastSync,
                conversation_id: this.chat.active?.id ?? undefined,
            });
            this.lastSync = data.server_time;
            this.chat.applySync(data);
            if (this.polling) this.setStatus('polling');
        } catch (error) {
            if (!error?.response) this.setStatus('offline');
        } finally {
            this.syncing = false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Presence heartbeat & lifecycle                                      */
    /* ------------------------------------------------------------------ */

    startHeartbeat() {
        if (!this.chat.api.has('heartbeat')) return;
        const beat = () => this.chat.api.heartbeat().catch(() => {});
        beat();
        const seconds = this.chat.config.presence?.heartbeatSeconds || 45;
        this.heartbeatTimer = setInterval(beat, seconds * 1000);
    }

    bindLifecycle() {
        window.addEventListener('online', () => {
            this.setStatus(this.state === 'connected' ? 'connected' : 'polling');
            this.syncNow();
            this.chat.api.heartbeat?.().catch(() => {});
        });

        window.addEventListener('offline', () => this.setStatus('offline'));

        // Tell the server we left (best effort) so "last seen" is accurate.
        window.addEventListener('pagehide', (event) => {
            if (event.persisted || !this.chat.api.has('offline')) return;
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const body = new FormData();
            body.append('_token', token ?? '');
            navigator.sendBeacon?.(this.chat.api.url('offline'), body);
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.chat.api.heartbeat?.().catch(() => {});
                if (this.polling) this.syncNow();
            }
        });
    }

    setStatus(state) {
        const { connectionStatus, connectionLabel } = this.chat.el;
        const labels = {
            connected: 'Online',
            connecting: 'Connecting…',
            polling: 'Online',
            offline: 'Offline',
        };
        connectionStatus.dataset.state = state;
        connectionLabel.textContent = labels[state] ?? 'Online';
        connectionStatus.title =
            state === 'polling' ? 'Live updates via AJAX (WebSocket unavailable)' : state === 'connected' ? 'Live updates via WebSocket' : '';
    }
}
